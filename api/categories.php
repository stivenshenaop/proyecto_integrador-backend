<?php
// Simplified CORS headers
// Allow only the frontend origin when requests include credentials (withCredentials)
header("Access-Control-Allow-Origin: http://localhost:5174");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
// allow common headers and the admin token header used by admin endpoints
header("Access-Control-Allow-Headers: Content-Type, Accept, X-Admin-Token");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

try {
    $pdo = new PDO("mysql:host=localhost;dbname=juegamas;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT id, nombre FROM categorias ORDER BY nombre");
    $stmt->execute();

    $categories = array();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $categories[] = array(
            'id' => (int)$row['id'],
            'name' => $row['nombre']
        );
    }

    echo json_encode($categories);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array("error" => $e->getMessage()));
}

?>
