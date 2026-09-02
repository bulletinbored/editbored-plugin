<?php
/**
 * Plugin Name: editbored
 * Author: mlzog
 * Description: WYSIWYG Markdown editor with mentions and image upload.
 *              Saves Markdown to DB; renders server-side via bb_render_content().
 *              Markdown-only: user HTML is never stored or trusted, so the core
 *              can render without any HTML sanitizer.
 * License: BSD Zero Clause License
 */

require_once __DIR__ . '/LinkCardCache.php';

function editbored_init() {
    global $pluginManager;

    if (!isset($pluginManager)) {
        return;
    }

    $baseUrl = rtrim(base_url(), '/');
    $pluginUrl = $baseUrl . '/plugins/' . basename(__DIR__);
    $previewUrl = $baseUrl . '/index.php?action=preview';

    $users = [];
    if (isset($GLOBALS['pdo'])) {
        $stmt = $GLOBALS['pdo']->query("SELECT id, username FROM users ORDER BY username ASC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $usersJson = json_encode($users);
    $uploadUrl = $pluginUrl . '/upload.php';
    // Ensure a CSRF token exists in the session so the upload endpoint can
    // validate it (generate_csrf_token() was never called globally before).
    if (function_exists('generate_csrf_token')) {
        generate_csrf_token();
    }
    $csrfToken = $_SESSION['csrf_token'] ?? '';
    $nonce = $GLOBALS['CSP_NONCE'] ?? '';
    $ebVer = function ($rel) use ($pluginUrl) {
        $f = __DIR__ . '/' . $rel;
        // Use a content hash as the cache-busting version so any edit forces a
        // fresh download. filemtime alone can be identical across rapid writes
        // on some filesystems, leaving stale cached assets in the browser.
        $v = file_exists($f) ? substr(md5_file($f), 0, 10) : time();
        return $pluginUrl . '/' . $rel . '?v=' . $v;
    };
    $cssUrl = $ebVer('assets/css/editbored.css');
    $editorUrl = $ebVer('assets/js/editbored.js');
    $markedUrl = $ebVer('assets/js/marked.min.js');

    $head = '<link href="' . $cssUrl . '" rel="stylesheet">' . "\n";

    // Boot data passed via data-* attributes (no inline script) so it survives
    // strict CSP. editbored.js reads these in init().
    $head .= '<div id="editbored-boot" data-users="' . htmlspecialchars($usersJson, ENT_QUOTES, 'UTF-8') . '" '
        . 'data-upload-url="' . htmlspecialchars($uploadUrl, ENT_QUOTES, 'UTF-8') . '" '
        . 'data-preview-url="' . htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') . '" '
        . 'data-csrf-token="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '" style="display:none"></div>' . "\n";

    $footer = '<script src="' . $markedUrl . '" nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    $footer .= '<script async src="https://www.instagram.com/embed.js" nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    $footer .= '<div id="fb-root"></div><script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js#xfbml=1&version=v21.0" nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    $footer .= '<script async src="https://platform.twitter.com/widgets.js" nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    $footer .= '<script src="' . $editorUrl . '" nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";

    $pluginManager->addHook('frontend_before_render', function () use ($head) {
        echo $head;
    });
    $pluginManager->addHook('footer_before_render', function () use ($footer) {
        echo $footer;
    });

    // Take over content rendering to add auto-embeds and link cards on top of
    // the core Markdown output. The core renders Markdown only; embed/card
    // generation is a plugin concern.
    $pluginManager->addHook('render_content', function (string $text): ?string {
        return editbored_render_content($text);
    });

    $pluginManager->addHook('after_post', function ($threadId, $postId) {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo) {
            return;
        }
        $stmt = $pdo->prepare("
            SELECT p.content, p.user_id, t.title, u.username AS author
            FROM posts p
            JOIN threads t ON p.thread_id = t.id
            LEFT JOIN users u ON p.user_id = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([(int)$postId]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$post) {
            return;
        }
        $actorId = (int)($post['user_id'] ?? 0);
        $authorName = $post['author'] ?? 'Someone';
        $threadTitle = $post['title'] ?? '';
        $threadLink = url('thread', ['id' => (int)$threadId, 'slug' => slugify($threadTitle)], true);

        if (preg_match_all('/(?<!\w)@([A-Za-z0-9_]+)/u', $post['content'] ?? '', $matches)) {
            $usernames = array_unique($matches[1]);
            foreach ($usernames as $username) {
                $uStmt = $pdo->prepare("SELECT id, email, username FROM users WHERE username = ? AND email IS NOT NULL AND email <> ''");
                $uStmt->execute([$username]);
                $user = $uStmt->fetch(PDO::FETCH_ASSOC);
                if (!$user || (int)$user['id'] === $actorId) {
                    continue;
                }
                $notifMsg = t('mentioned_notification', [
                    'author' => escape($authorName),
                    'title' => escape($threadTitle),
                ]);
                create_notification($pdo, (int)$user['id'], 'mention', $notifMsg, $notifMsg, $threadLink);

                $subject = t('mentioned_subject', ['title' => $threadTitle]);
                $body = t('mentioned_body', [
                    'username' => escape($user['username'] ?? $username),
                    'author' => escape($authorName),
                    'title' => escape($threadTitle),
                    'link' => $threadLink,
                ]);
                send_email($user['email'], $subject, $body);
            }
        }
    });
}

// Hosts permitted for auto-embed iframes (server-validated, so a user cannot
// smuggle an arbitrary iframe).
function editbored_embed_allowed_hosts(): array {
    return [
        'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com',
        'platform.twitter.com', 'twitter.com', 'x.com',
        'www.facebook.com', 'facebook.com',
        'www.instagram.com', 'instagram.com',
    ];
}

// Turn a bare media URL into a safe embed iframe, or null if not allowed.
function editbored_build_embed(string $url): ?string {
    $url = trim($url);
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return null;
    }
    $bare = preg_replace('/^www\./', '', $host);
    $esc = function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    };

    if (in_array($bare, ['youtube.com', 'youtube-nocookie.com'], true)) {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $id = $q['v'] ?? '';
        if ($id === '' && preg_match('#/(?:embed|shorts)/([A-Za-z0-9_-]{6,})#', $url, $m)) {
            $id = $m[1];
        }
        if (!preg_match('/^[A-Za-z0-9_-]{6,}$/', $id)) {
            return null;
        }
        $domain = $bare === 'youtube-nocookie.com' ? 'www.youtube-nocookie.com' : 'www.youtube.com';
        $src = 'https://' . $domain . '/embed/' . $id;
        return '<div class="embed embed-youtube">'
            . '<iframe src="' . $esc($src) . '" title="Embedded video" '
            . 'allowfullscreen loading="lazy"></iframe></div>';
    }

    if (in_array($bare, ['twitter.com', 'x.com'], true)) {
        return '<div class="embed embed-twitter">'
            . '<blockquote class="twitter-tweet" data-dnt="true">'
            . '<a href="' . $esc($url) . '"></a></blockquote></div>';
    }

    if (in_array($bare, ['facebook.com', 'instagram.com'], true)) {
        if ($bare === 'instagram.com') {
            return '<div class="embed embed-instagram">'
                . '<iframe src="https://www.instagram.com/p/' . urlencode(basename(rtrim(parse_url($url, PHP_URL_PATH), '/'))) . '/embed" '
                . 'title="Embedded post" loading="lazy"></iframe></div>';
        }
        return '<div class="embed embed-facebook">'
            . '<iframe src="https://www.facebook.com/plugins/post.php?href='
            . urlencode($url) . '&show_text=true&width=500" '
            . 'title="Embedded post" loading="lazy"></iframe></div>';
    }

    return null;
}

// In-memory cache instance for the current request.
function editbored_get_cache(): ?LinkCardCache {
    static $cache = null;
    if ($cache === null && class_exists('LinkCardCache')) {
        $cache = new LinkCardCache();
    }
    return $cache;
}

// Helper: fetch a remote page for link-card metadata (with caching).
function editbored_fetch_page(string $url): ?string {
    $url = trim($url);
    $cache = editbored_get_cache();
    if ($cache !== null) {
        $cached = $cache->get($url);
        if ($cached !== null) {
            return $cached['body'] ?? null;
        }
    }
    $body = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ForumBot/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXREDIRS => 2,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if ($body === false || !is_string($body)) {
            $body = null;
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0\r\n",
            'timeout' => 3,
            'follow_location' => 1,
            'max_redirects' => 2,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            $body = null;
        }
    }
    if ($body !== null && $cache !== null) {
        $cache->set($url, ['body' => $body]);
    }
    return $body;
}

// Safe generic link card for any http(s) URL.
function editbored_build_link_card(string $url): string {
    $url = trim($url);
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '' || !preg_match('#^https?://#i', $url)) {
        return '';
    }
    $esc = function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    };
    $favicon = 'https://www.google.com/s2/favicons?domain=' . rawurlencode($host) . '&sz=64';
    $title = $host;
    $preview = '';
    $body = editbored_fetch_page($url);
    if ($body !== null) {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m)) {
            $title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($title === '') {
                $title = $host;
            }
        }
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']+)["\']/i', $body, $m)) {
            $preview = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    $preview = mb_substr($preview, 0, 120);
    return '<div class="embed embed-link">'
        . '<a href="' . $esc($url) . '" target="_blank" rel="noopener noreferrer">'
        . '<img src="' . $esc($favicon) . '" alt="" loading="lazy" class="embed-link-favicon">'
        . '<span class="embed-link-content">'
        . '<span class="embed-link-title">' . $esc($title) . '</span>'
        . ($preview !== '' ? '<span class="embed-link-preview">' . $esc($preview) . '</span>' : '')
        . '<span class="embed-link-domain">' . $esc($host) . '</span>'
        . '</span>'
        . '</a></div>';
}

// Full content render for editbored: core Markdown plus auto-embeds and cards.
function editbored_render_content(string $text): string {
    if ($text === '' || $text === null) {
        return '';
    }
    // Parse Markdown directly (NOT via bb_render_content, which would re-enter
    // this hook and cause infinite recursion). Then upgrade bare URLs into
    // embeds/cards — but only in text segments, never inside HTML tags or
    // attributes (which would corrupt href/src values).
    $html = '<div class="markdown-content">' . bb_parse_markdown($text) . '</div>';
    $html = preg_replace_callback(
        '#([^<]*)(<[^>]*>)?#',
        function ($m) {
            if (!isset($m[1]) || $m[1] === '') {
                return $m[0];
            }
            return preg_replace_callback(
                '#https?://[^\s<>"\'\)]+#i',
                function ($urlMatch) {
                    $url = rtrim($urlMatch[0], '.');
                    // Bare image URL -> render as <img> (skip SVG, which can carry script).
                    if (preg_match('#\.(png|jpe?g|gif|webp|avif|bmp|tiff?)(\?.*)?$#i', $url) && !preg_match('#\.svg(\?.*)?$#i', $url)) {
                        $esc = function (string $s): string {
                            return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        };
                        return '<div class="embed embed-image"><img src="' . $esc($url) . '" alt="" loading="lazy"></div>';
                    }
                    $embed = editbored_build_embed($url);
                    if ($embed === null) {
                        $embed = editbored_build_link_card($url);
                    }
                    return ($embed !== '' && $embed !== null) ? $embed : htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                },
                $m[1]
            ) . ($m[2] ?? '');
        },
        $html
    );
    // Mentions last, so @ inside URLs or embed markup is never touched.
    return editbored_render_mentions($html);
}

/**
 * Render mentions (@username) on top of the already-rendered HTML. Only touches
 * text segments, never HTML tags/attributes, so it cannot corrupt href/src.
 */
function editbored_render_mentions(string $html): string {
    return preg_replace_callback(
        '#([^<]*)(<[^>]*>)?#',
        function ($m) {
            if (!isset($m[1]) || $m[1] === '') {
                return $m[0];
            }
            return preg_replace_callback(
                '#(?<!\w)@([a-zA-Z0-9_]{2,})#',
                function ($mentionMatch) {
                    $username = $mentionMatch[1];
                    $esc = function (string $s): string {
                        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    };
                    return '<a href="' . $esc(url('profile', ['username' => $username])) . '" class="mention">@' . $esc($username) . '</a>';
                },
                $m[1]
            ) . ($m[2] ?? '');
        },
        $html
    );
}
