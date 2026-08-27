<?php
// Load the same bootstrap the app uses so the session (BBSESSID, save_path,
// cookie params) is resumed identically to the page that rendered the editor.
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Login required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!validate_csrf_token($csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF token invalid']);
    exit;
}

if (empty($_FILES['editbored_image']['tmp_name']) || $_FILES['editbored_image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No image uploaded']);
    exit;
}

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];
$maxSize = 5 * 1024 * 1024;
$info = validate_upload(
    $_FILES['editbored_image']['tmp_name'],
    $_FILES['editbored_image']['name'] ?? '',
    $allowed,
    $maxSize
);
if ($info === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid image']);
    exit;
}

$uploadDir = __DIR__ . '/../../uploads/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}
$safeName = $info['safe_name'];

if (move_uploaded_file($_FILES['editbored_image']['tmp_name'], $uploadDir . $safeName)) {
    $base = rtrim(!empty($GLOBALS['config']['base_url']) ? $GLOBALS['config']['base_url'] : preg_replace('#/plugins/[^/]+/[^/]+$#', '', $_SERVER['SCRIPT_NAME'] ?? ''), '/');
    $url = $base . '/uploads/' . rawurlencode($safeName);
    echo json_encode(['url' => $url, 'filename' => $safeName]);
    exit;
}

http_response_code(500);
echo json_encode(['error' => 'Failed to move uploaded file']);
exit;
