<?php
// TEMPORARY SCRIPT - will set the admin password to 'admin1234' for development/testing.
// Remove this file after use.

header('Content-Type: application/json');

// DB config - reuse project's DB connection settings
$dbHost = '127.0.0.1';
$dbName = 'juegamas';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'DB connect failed', 'message' => $e->getMessage()]);
    exit;
}

$email = 'admin@juegamas.com';
$newPasswordPlain = 'admin1234';
$newHash = password_hash($newPasswordPlain, PASSWORD_BCRYPT);

// Update existing admin user
$stmt = $pdo->prepare('UPDATE usuarios SET password = :pw WHERE email = :email');
$stmt->execute([':pw' => $newHash, ':email' => $email]);
$rows = $stmt->rowCount();

// Verify by selecting and using password_verify
$stmt2 = $pdo->prepare('SELECT id, email, password FROM usuarios WHERE email = :email');
$stmt2->execute([':email' => $email]);
$user = $stmt2->fetch(PDO::FETCH_ASSOC);

$ok = false;
if ($user) {
    $ok = password_verify($newPasswordPlain, $user['password']);
}

echo json_encode([
    'success' => true,
    'updated_rows' => $rows,
    'email' => $email,
    'password_verified' => $ok,
    'note' => 'Remove this file after use!'
]);

// Do NOT exit without printing result
?>
