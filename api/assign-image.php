<?php
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
// also accept token in POST body
if (!$provided && isset($_POST['adminToken'])) $provided = $_POST['adminToken'];
if (!$isAdmin && $provided !== $cfg['admin_token']) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: invalid admin token or not logged in as admin']);
    exit;
}

$gameId = isset($_POST['gameId']) ? intval($_POST['gameId']) : 0;
$filename = isset($_POST['filename']) ? trim($_POST['filename']) : '';

if ($gameId <= 0 || $filename === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing gameId or filename']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=localhost;dbname=juegamas;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("UPDATE videojuegos SET imagen_portada = :fn WHERE id = :id");
    $stmt->execute([':fn' => $filename, ':id' => $gameId]);

    echo json_encode(['message' => 'Image assigned', 'gameId' => $gameId, 'filename' => $filename]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

?>
