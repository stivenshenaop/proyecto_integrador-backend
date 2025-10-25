<?php
// Headers CORS simplificados
// Permit the frontend origin and allow credentials (cookies) to be sent
header("Access-Control-Allow-Origin: http://localhost:5174");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept, X-Admin-Token");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

// Manejar preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

try {
    // Conexión directa sin clase
    $pdo = new PDO("mysql:host=localhost;dbname=juegamas;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Query con categorías
    $query = "SELECT v.*, c.nombre as categoria_nombre 
              FROM videojuegos v 
              LEFT JOIN categorias c ON v.categoria_id = c.id 
              WHERE v.activo = 1 
              ORDER BY v.nombre";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    
    $games = array();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Build absolute base URL from request so frontend loads images from the correct host
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];

        $imageUrl = '/placeholder-game.jpg';
        if (!empty($row['imagen_portada'])) {
            $imageFile = __DIR__ . '/../images/' . $row['imagen_portada'];
            if (file_exists($imageFile)) {
                $imageUrl = $baseUrl . '/Backend/images/' . $row['imagen_portada'];
            } else {
                // fallback to frontend placeholder if the backend image file is missing
                $imageUrl = '/placeholder-game.jpg';
            }
        }

        $games[] = array(
            "id" => (int)$row['id'],
            "name" => $row['nombre'],
            "category" => $row['categoria_nombre'] ?? 'Sin categoría',
            "releaseYear" => (int)$row['ano_lanzamiento'],
            "price" => (float)$row['precio'],
            "description" => $row['descripcion'],
            "imageUrl" => $imageUrl,
            "stock" => (int)$row['stock']
        );
    }
    
    echo json_encode($games);
    
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(array("error" => $e->getMessage(), "line" => __LINE__));
}
?>