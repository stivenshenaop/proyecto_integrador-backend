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

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$id = isset($input['id']) ? intval($input['id']) : 0;
if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }

$fields = [];
$params = [':id' => $id];
if (isset($input['name'])) { $fields[] = 'nombre = :name'; $params[':name'] = $input['name']; }
// Only include numeric categoryId if it's a positive integer
if (isset($input['categoryId'])) {
    if (is_numeric($input['categoryId']) && intval($input['categoryId']) > 0) {
        $fields[] = 'categoria_id = :cat';
        $params[':cat'] = intval($input['categoryId']);
    } else {
        // skip invalid categoryId for updates to avoid FK violation
    }
}
if (isset($input['price'])) {
    if (is_numeric($input['price'])) { $fields[] = 'precio = :price'; $params[':price'] = floatval($input['price']); }
}
if (isset($input['stock'])) {
    if (is_numeric($input['stock'])) { $fields[] = 'stock = :stock'; $params[':stock'] = intval($input['stock']); }
}
if (isset($input['releaseYear'])) {
    if (is_numeric($input['releaseYear'])) { $fields[] = 'ano_lanzamiento = :year'; $params[':year'] = intval($input['releaseYear']); }
}
if (isset($input['description'])) { $fields[] = 'descripcion = :desc'; $params[':desc'] = $input['description']; }
if (isset($input['image'])) { $fields[] = 'imagen_portada = :img'; $params[':img'] = $input['image']; }

// support explicit remove flag to clear portada
if (isset($input['removeImage']) && ($input['removeImage'] == '1' || $input['removeImage'] === 1 || $input['removeImage'] === true)) {
    $fields[] = 'imagen_portada = NULL';
}

if (empty($fields)) { http_response_code(400); echo json_encode(['error'=>'No fields to update']); exit; }

try {
    $pdo = new PDO("mysql:host=localhost;dbname=juegamas;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sql = "UPDATE videojuegos SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success'=>true, 'id'=>$id]);
} catch (Exception $e) {
    http_response_code(500); echo json_encode(['error'=>$e->getMessage()]);
}

?>
