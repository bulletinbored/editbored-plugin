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
            . 'frameborder="0" allowfullscreen loading="lazy"></iframe></div>';
    }

    if (in_array($bare, ['twitter.com', 'x.com'], true)) {
        return '<div class="embed embed-twitter">'
            . '<iframe src="https://platform.twitter.com/embed/Tweet.html?url='
            . urlencode($url) . '" title="Embedded post" '
            . 'frameborder="0" loading="lazy"></iframe></div>';
    }

    if (in_array($bare, ['facebook.com', 'instagram.com'], true)) {
        $src = 'https://www.' . $bare . '/plugins/embed?url=' . urlencode($url);
        return '<div class="embed embed-' . ($bare === 'instagram.com' ? 'instagram' : 'facebook') . '">'
            . '<iframe src="' . $esc($src) . '" title="Embedded post" '
            . 'frameborder="0" loading="lazy"></iframe></div>';
    }

    return null;
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
    return '<div class="embed embed-link">'
        . '<a href="' . $esc($url) . '" target="_blank" rel="noopener noreferrer">'
        . '<img src="' . $esc($favicon) . '" alt="" loading="lazy" class="embed-link-favicon">'
        . '<span class="embed-link-domain">' . $esc($host) . '</span>'
        . '<span class="embed-link-url">' . $esc($url) . '</span>'
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
    return preg_replace_callback(
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
}
