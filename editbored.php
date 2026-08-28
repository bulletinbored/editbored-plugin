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
