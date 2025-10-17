<?php
// Simple auth endpoint using PHP sessions. Development use only.
header("Access-Control-Allow-Origin: http://localhost:5174");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Accept");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

$action = $_GET['action'] ?? $_POST['action'] ?? null;

try {
    $pdo = new PDO("mysql:host=localhost;dbname=juegamas;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($action === 'login') {
        // Accept JSON POST or form fields
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';

        if (!$email || !$password) {
            echo json_encode(['success' => false, 'error' => 'Missing credentials']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT id, primer_nombre, segundo_nombre, email, password, rol FROM usuarios WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // Remove password before storing in session
            unset($user['password']);
            $_SESSION['user'] = $user;
            echo json_encode(['success' => true, 'user' => $user]);
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
        exit;
    }

    if ($action === 'register') {
        // Accept JSON POST or form fields
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $first = trim($input['primer_nombre'] ?? $input['firstName'] ?? '');
        $second = trim($input['segundo_nombre'] ?? $input['secondName'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $telefono = trim($input['telefono'] ?? $input['phone'] ?? '');
        $pais = trim($input['pais'] ?? $input['country'] ?? '');
        $ciudad = trim($input['ciudad'] ?? $input['city'] ?? '');
        $codigo_postal = trim($input['codigo_postal'] ?? $input['postal'] ?? '');

        if (!$first || !$email || !$password) {
            echo json_encode(['success' => false, 'error' => 'Missing required fields']);
            exit;
        }

        // Check email uniqueness
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode(['success' => false, 'error' => 'Email already registered']);
            exit;
        }

        // Hash password
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Insert user
        $insert = $pdo->prepare('INSERT INTO usuarios (primer_nombre, segundo_nombre, email, password, telefono, pais, ciudad, codigo_postal, rol) VALUES (:first, :second, :email, :password, :telefono, :pais, :ciudad, :codigo_postal, :rol)');
        $insert->execute([
            ':first' => $first,
            ':second' => $second,
            ':email' => $email,
            ':password' => $hash,
            ':telefono' => $telefono,
            ':pais' => $pais,
            ':ciudad' => $ciudad,
            ':codigo_postal' => $codigo_postal,
            ':rol' => 'cliente'
        ]);

        $id = $pdo->lastInsertId();
        $user = [
            'id' => $id,
            'primer_nombre' => $first,
            'segundo_nombre' => $second,
            'email' => $email,
            'rol' => 'cliente'
        ];

        // Start session for new user
        $_SESSION['user'] = $user;

        echo json_encode(['success' => true, 'user' => $user]);
        exit;
    }

    if ($action === 'updateProfile') {
        // Accept JSON body or multipart/form-data with file upload for avatar
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        if (!isset($_SESSION['user'])) {
            echo json_encode(['success' => false, 'error' => 'Not authenticated']);
            exit;
        }

        $uid = $_SESSION['user']['id'];

        $avatar = $input['avatar'] ?? null;
        $favorites = isset($input['favorites']) ? json_encode($input['favorites']) : null;
        $primer_nombre = $input['primer_nombre'] ?? null;

        // Handle file upload if provided
        if (isset($_FILES['avatar']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
            $uploadsDir = __DIR__ . '/../uploads/avatars';
            if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

            $origName = basename($_FILES['avatar']['name']);
            $ext = pathinfo($origName, PATHINFO_EXTENSION);
            $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
            $newName = 'avatar_' . $uid . '_' . time() . '_' . bin2hex(random_bytes(4)) . ($safeExt ? '.' . $safeExt : '');
            $dest = $uploadsDir . '/' . $newName;
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dest)) {
                // Set avatar URL relative to server root
                $avatar = '/Backend/uploads/avatars/' . $newName;
            }
        }

        $fields = [];
        $params = [':id' => $uid];
        if ($avatar !== null) { $fields[] = 'avatar = :avatar'; $params[':avatar'] = $avatar; }
        if ($favorites !== null) { $fields[] = 'favorites = :favorites'; $params[':favorites'] = $favorites; }
        if ($primer_nombre !== null) { $fields[] = 'primer_nombre = :primer_nombre'; $params[':primer_nombre'] = $primer_nombre; }

        if (count($fields) > 0) {
            $sql = 'UPDATE usuarios SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        // Fetch updated user
        $stmt = $pdo->prepare('SELECT id, primer_nombre, segundo_nombre, email, rol, avatar, favorites FROM usuarios WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $uid]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            // decode favorites to array if present
            if (!empty($user['favorites'])) {
                $user['favorites'] = json_decode($user['favorites'], true);
            }
            $_SESSION['user'] = $user;
            echo json_encode(['success' => true, 'user' => $user]);
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Could not update user']);
        exit;
    }

    if ($action === 'logout') {
        session_unset();
        session_destroy();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'me') {
        if (isset($_SESSION['user'])) {
            echo json_encode(['success' => true, 'user' => $_SESSION['user']]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
