<?php
try {
    $pdo = new PDO("mysql:host=localhost;dbname=juegamas;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query("SELECT id, primer_nombre, segundo_nombre, email, password, rol FROM usuarios WHERE rol = 'admin' LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        // base64 the hash to avoid terminal truncation issues
        $row['password_b64'] = base64_encode($row['password']);
        echo json_encode(['success'=>true, 'user'=>$row]);
    } else {
        echo json_encode(['success'=>false, 'error'=>'No admin user found']);
    }
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
?>