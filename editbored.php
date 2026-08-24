<?php
/**
 * Plugin Name: editbored
 * Author: mlzog
 * Description: WYSIWYG Markdown editor with mentions and image upload
 * License: BSD Zero Clause License
 */

function editbored_init() {
    global $pluginManager, $config;

    if (!isset($pluginManager)) {
        return;
    }

    $baseUrl = rtrim(base_url(), '/');
    $pluginUrl = $baseUrl . '/plugins/editbored';

    $users = [];
    if (isset($GLOBALS['pdo'])) {
        $stmt = $GLOBALS['pdo']->query("SELECT id, username FROM users ORDER BY username ASC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $usersJson = json_encode($users);
    $uploadUrl = $pluginUrl . '/upload.php';
    $csrfToken = $_SESSION['csrf_token'] ?? '';
    $nonce = $GLOBALS['CSP_NONCE'] ?? '';
    $ebVer = function($rel) use ($pluginUrl) {
        $f = __DIR__ . '/' . $rel;
        return $pluginUrl . '/' . $rel . '?v=' . (file_exists($f) ? filemtime($f) : time());
    };
    $cssUrl = $ebVer('assets/css/editbored.css');
    $mentionsUrl = $ebVer('assets/js/mentions.js');
    $editorUrl = $ebVer('assets/js/editbored.js');

    $head = '<link href="' . $cssUrl . '" rel="stylesheet">' . "\n";
    $head .= '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '">window.editbored = window.editbored || {};window.editbored.users = ' . $usersJson . ';window.editbored.uploadUrl = ' . json_encode($uploadUrl) . ';window.editbored.csrfToken = ' . json_encode($csrfToken) . ';</script>' . "\n";

    $footer = '<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js" nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    $footer .= '<script async src="https://www.instagram.com/embed.js" nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    $footer .= '<div id="fb-root"></div><script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js#xfbml=1&version=v21.0" nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    $footer .= '<script src="' . $mentionsUrl . '" nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    $footer .= '<script src="' . $editorUrl . '" nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    $footer .= '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '">window.editbored = window.editbored || {};window.editbored.init && window.editbored.init();</script>' . "\n";

    $pluginManager->addHook('frontend_before_render', function() use ($head) {
        echo $head;
    });
    $pluginManager->addHook('footer_before_render', function() use ($footer) {
        echo $footer;
    });

    // Mention notifications (case 7): editbored owns these. The core fires
    // after_post on every reply; we send the email (notify_mentioned_users)
    // and write the in-app notification row for each mentioned user.
    // Hook signature: after_post($threadId, $postId)
    $pluginManager->addHook('after_post', function($threadId, $postId) {
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

        // Detect @mentions (editbored owns mention notifications: both the
        // in-app row and the email). The regex mirrors the syntax produced by
        // the editbored mention autocomplete and also catches mentions that
        // appear inside a quoted block.
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

                // Email delivery for the mention (owned by editbored).
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
