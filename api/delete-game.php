<?php
header("Access-Control-Allow-Origin: http://localhost:5174");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Admin-Token");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

session_start();
$isAdmin = false;
if (isset($_SESSION['user']) && (($_SESSION['user']['rol'] ?? '') === 'admin' || ($_SESSION['user']['role'] ?? '') === 'admin')) $isAdmin = true;

$cfg = @include __DIR__ . '/upload-config.php';
$provided = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? $_POST['adminToken'] ?? null;
if (!$isAdmin && (!isset($cfg['admin_token']) || $provided !== $cfg['admin_token'])) {
    http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit;
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }

try {
    $pdo = new PDO("mysql:host=localhost;dbname=juegamas;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare("DELETE FROM videojuegos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    echo json_encode(['success'=>true, 'id'=>$id]);
} catch (Exception $e) {
    http_response_code(500); echo json_encode(['error'=>$e->getMessage()]);
}

?>
