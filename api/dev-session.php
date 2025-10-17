<?php
// Development helper: create a session as admin for testing endpoints.
header("Access-Control-Allow-Origin: http://localhost:5174");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$secret = $_POST['secret'] ?? null;
if ($secret !== 'dev-set-session-123') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

session_start();
// Fetch admin user from DB and set into session
try {
    $pdo = new PDO("mysql:host=localhost;dbname=juegamas;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare('SELECT id, primer_nombre, segundo_nombre, email, rol FROM usuarios WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => 'admin@juegamas.com']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $_SESSION['user'] = $user;
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Admin user not found']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

?>
