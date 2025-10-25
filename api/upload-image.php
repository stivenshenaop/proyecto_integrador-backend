<?php
// Simple image upload endpoint for admin (development use).
// Note: For production, add authentication and stricter validation.

// CORS: allow the frontend origin and include credentials so frontend can send cookies
header("Access-Control-Allow-Origin: http://localhost:5174");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Admin-Token");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Allow session-based admin (from auth.php) or fallback to admin token
session_start();
$isAdmin = false;
if (isset($_SESSION['user']) && ($_SESSION['user']['rol'] ?? $_SESSION['user']['role'] ?? '') === 'admin') {
    $isAdmin = true;
}

$cfg = include __DIR__ . '/upload-config.php';
$provided = null;
if (isset($_SERVER['HTTP_X_ADMIN_TOKEN'])) $provided = $_SERVER['HTTP_X_ADMIN_TOKEN'];
if (!$provided && isset($_POST['adminToken'])) $provided = $_POST['adminToken'];
if (!$isAdmin && $provided !== $cfg['admin_token']) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: invalid admin token or not logged in as admin']);
    exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['image'];
$allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
$maxSize = 4 * 1024 * 1024; // 4MB


// Validate by extension as finfo may return unexpected MIME on some systems
$origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowedExt = ['jpg','jpeg','png','webp'];
if (!in_array($origExt, $allowedExt)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file extension']);
    exit;
}

if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large']);
    exit;
}
// use the original extension from filename
$ext = '.' . ($origExt === 'jpeg' ? 'jpg' : $origExt);

$name = pathinfo($file['name'], PATHINFO_FILENAME);
// sanitize name
$name = preg_replace('/[^A-Za-z0-9-_]/', '-', $name);
$targetDir = __DIR__ . '/../images';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

$targetFile = $targetDir . '/' . $name . $ext;
$counter = 1;
while (file_exists($targetFile)) {
    $targetFile = $targetDir . '/' . $name . '-' . $counter . $ext;
    $counter++;
}

if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
    $err = error_get_last();
    http_response_code(500);
    echo json_encode(['error' => 'Could not save file', 'detail' => $err]);
    exit;
}

$urlBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http';
$urlBase .= '://' . $_SERVER['HTTP_HOST'] . '/Backend/images/';
$savedName = basename($targetFile);

echo json_encode(['message' => 'File uploaded', 'filename' => $savedName, 'url' => $urlBase . $savedName]);

?>
