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

// allow dev token fallback if present (keeps parity with upload/assign)
$cfg = @include __DIR__ . '/upload-config.php';
$provided = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? $_POST['adminToken'] ?? null;
if (!$isAdmin && (!isset($cfg['admin_token']) || $provided !== $cfg['admin_token'])) {
    http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$name = trim($input['name'] ?? '');
$categoryId = isset($input['categoryId']) ? intval($input['categoryId']) : null;
$price = isset($input['price']) ? floatval($input['price']) : 0;
$stock = isset($input['stock']) ? intval($input['stock']) : 0;
$year = isset($input['releaseYear']) ? intval($input['releaseYear']) : null;
$description = $input['description'] ?? '';
$image = $input['image'] ?? null; // filename stored in images folder

if ($name === '') { http_response_code(400); echo json_encode(['error'=>'Missing name']); exit; }

try {
    $pdo = new PDO("mysql:host=localhost;dbname=juegamas;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("INSERT INTO videojuegos (nombre, categoria_id, precio, stock, ano_lanzamiento, descripcion, imagen_portada, activo) VALUES (:n, :cat, :price, :stock, :year, :desc, :img, 1)");
    $stmt->execute([
        ':n' => $name,
        ':cat' => $categoryId,
        ':price' => $price,
        ':stock' => $stock,
        ':year' => $year,
        ':desc' => $description,
        ':img' => $image
    ]);
    $id = (int)$pdo->lastInsertId();
    echo json_encode(['success'=>true, 'id'=>$id]);
} catch (Exception $e) {
    http_response_code(500); echo json_encode(['error'=>$e->getMessage()]);
}

?>
