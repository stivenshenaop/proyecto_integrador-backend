<?php
header("Access-Control-Allow-Origin: http://localhost:5174");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Admin-Token");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();
$isAdmin = false;
if (isset($_SESSION['user'])) {
    $role = $_SESSION['user']['rol'] ?? $_SESSION['user']['role'] ?? null;
    if ($role === 'admin') {
        $isAdmin = true;
    }
}

$cfg = @include __DIR__ . '/upload-config.php';
$providedToken = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? null;
$tokenIsValid = isset($cfg['admin_token']) && $providedToken === $cfg['admin_token'];
$hasAdminAccess = $isAdmin || $tokenIsValid;

try {
    $pdo = new PDO("mysql:host=localhost;dbname=juegamas;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed', 'details' => $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Single place to normalise user rows before returning them to the client.
function formatUserRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'primer_nombre' => $row['primer_nombre'],
        'segundo_nombre' => $row['segundo_nombre'],
        'email' => $row['email'],
        'telefono' => $row['telefono'],
        'pais' => $row['pais'],
        'ciudad' => $row['ciudad'],
        'codigo_postal' => $row['codigo_postal'],
        'rol' => $row['rol'],
        'fecha_registro' => $row['fecha_registro'],
        'activo' => (bool) $row['activo'],
    ];
}

if (!$hasAdminAccess) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

switch ($method) {
    case 'GET':
        $id = isset($_GET['id']) ? (int) $_GET['id'] : null;
        $includeInactive = isset($_GET['includeInactive']) ? filter_var($_GET['includeInactive'], FILTER_VALIDATE_BOOLEAN) : false;
        $search = isset($_GET['q']) ? trim($_GET['q']) : '';

        try {
            if ($id) {
                $sql = 'SELECT id, primer_nombre, segundo_nombre, email, telefono, pais, ciudad, codigo_postal, rol, fecha_registro, activo FROM usuarios WHERE id = :id';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    http_response_code(404);
                    echo json_encode(['error' => 'User not found']);
                    break;
                }
                echo json_encode(formatUserRow($user));
                break;
            }

            $conditions = [];
            $params = [];

            if (!$includeInactive) {
                $conditions[] = 'activo = 1';
            }

            if ($search !== '') {
                $conditions[] = '(primer_nombre LIKE :term OR segundo_nombre LIKE :term OR email LIKE :term)';
                $params[':term'] = '%' . $search . '%';
            }

            $sql = 'SELECT id, primer_nombre, segundo_nombre, email, telefono, pais, ciudad, codigo_postal, rol, fecha_registro, activo FROM usuarios';
            if ($conditions) {
                $sql .= ' WHERE ' . implode(' AND ', $conditions);
            }
            $sql .= ' ORDER BY fecha_registro DESC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $result = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $result[] = formatUserRow($row);
            }
            echo json_encode($result);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $primerNombre = trim($input['primer_nombre'] ?? $input['first_name'] ?? '');
        $segundoNombre = trim($input['segundo_nombre'] ?? $input['second_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $telefono = trim($input['telefono'] ?? '');
        $pais = trim($input['pais'] ?? '');
        $ciudad = trim($input['ciudad'] ?? '');
        $codigoPostal = trim($input['codigo_postal'] ?? '');
        $rol = trim($input['rol'] ?? 'user');
        $activo = isset($input['activo']) ? (int) !!$input['activo'] : 1;

        if ($primerNombre === '' || $email === '' || $password === '' || $pais === '' || $ciudad === '' || $codigoPostal === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            break;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid email address']);
            break;
        }

        if ($rol !== 'user' && $rol !== 'admin') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid role value']);
            break;
        }

        try {
            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(409);
                echo json_encode(['error' => 'Email already in use']);
                break;
            }

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $insert = $pdo->prepare('INSERT INTO usuarios (primer_nombre, segundo_nombre, email, password, telefono, pais, ciudad, codigo_postal, rol, activo) VALUES (:primer_nombre, :segundo_nombre, :email, :password, :telefono, :pais, :ciudad, :codigo_postal, :rol, :activo)');
            $insert->execute([
                ':primer_nombre' => $primerNombre,
                ':segundo_nombre' => $segundoNombre !== '' ? $segundoNombre : null,
                ':email' => $email,
                ':password' => $hashedPassword,
                ':telefono' => $telefono !== '' ? $telefono : null,
                ':pais' => $pais,
                ':ciudad' => $ciudad,
                ':codigo_postal' => $codigoPostal,
                ':rol' => $rol,
                ':activo' => $activo,
            ]);

            $newId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('SELECT id, primer_nombre, segundo_nombre, email, telefono, pais, ciudad, codigo_postal, rol, fecha_registro, activo FROM usuarios WHERE id = :id');
            $stmt->execute([':id' => $newId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            http_response_code(201);
            echo json_encode(formatUserRow($user));
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'PUT':
        $rawBody = file_get_contents('php://input');
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            parse_str($rawBody, $input);
        }

        $id = isset($input['id']) ? (int) $input['id'] : (isset($_GET['id']) ? (int) $_GET['id'] : 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing id for update']);
            break;
        }

        $fields = [];
        $params = [':id' => $id];

        if (array_key_exists('primer_nombre', $input)) {
            $fields[] = 'primer_nombre = :primer_nombre';
            $params[':primer_nombre'] = trim($input['primer_nombre']);
        }
        if (array_key_exists('segundo_nombre', $input)) {
            $second = trim((string) $input['segundo_nombre']);
            $fields[] = 'segundo_nombre = :segundo_nombre';
            $params[':segundo_nombre'] = $second !== '' ? $second : null;
        }
        if (array_key_exists('email', $input)) {
            $email = trim($input['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid email address']);
                break;
            }
            $check = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email AND id <> :id LIMIT 1');
            $check->execute([':email' => $email, ':id' => $id]);
            if ($check->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(409);
                echo json_encode(['error' => 'Email already in use']);
                break;
            }
            $fields[] = 'email = :email';
            $params[':email'] = $email;
        }
        if (array_key_exists('password', $input)) {
            $password = (string) $input['password'];
            if ($password !== '') {
                $fields[] = 'password = :password';
                $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
            }
        }
        if (array_key_exists('telefono', $input)) {
            $phone = trim((string) $input['telefono']);
            $fields[] = 'telefono = :telefono';
            $params[':telefono'] = $phone !== '' ? $phone : null;
        }
        if (array_key_exists('pais', $input)) {
            $fields[] = 'pais = :pais';
            $params[':pais'] = trim($input['pais']);
        }
        if (array_key_exists('ciudad', $input)) {
            $fields[] = 'ciudad = :ciudad';
            $params[':ciudad'] = trim($input['ciudad']);
        }
        if (array_key_exists('codigo_postal', $input)) {
            $fields[] = 'codigo_postal = :codigo_postal';
            $params[':codigo_postal'] = trim($input['codigo_postal']);
        }
        if (array_key_exists('rol', $input)) {
            $role = trim($input['rol']);
            if ($role !== 'user' && $role !== 'admin') {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid role value']);
                break;
            }
            $fields[] = 'rol = :rol';
            $params[':rol'] = $role;
        }
        if (array_key_exists('activo', $input)) {
            $fields[] = 'activo = :activo';
            $params[':activo'] = (int) !!$input['activo'];
        }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields provided for update']);
            break;
        }

        try {
            $updateSql = 'UPDATE usuarios SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute($params);

            if ($stmt->rowCount() === 0) {
                // Verify user still exists to differentiate between "no change" and "not found".
                $check = $pdo->prepare('SELECT id FROM usuarios WHERE id = :id');
                $check->execute([':id' => $id]);
                if (!$check->fetch(PDO::FETCH_ASSOC)) {
                    http_response_code(404);
                    echo json_encode(['error' => 'User not found']);
                    break;
                }
            }

            $stmt = $pdo->prepare('SELECT id, primer_nombre, segundo_nombre, email, telefono, pais, ciudad, codigo_postal, rol, fecha_registro, activo FROM usuarios WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(formatUserRow($user));
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'DELETE':
        $rawBody = file_get_contents('php://input');
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            parse_str($rawBody, $input);
        }

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0 && isset($input['id'])) {
            $id = (int) $input['id'];
        }

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing id for delete']);
            break;
        }

        $hardDelete = isset($_GET['hard']) ? filter_var($_GET['hard'], FILTER_VALIDATE_BOOLEAN) : false;

        try {
            if ($hardDelete) {
                $stmt = $pdo->prepare('DELETE FROM usuarios WHERE id = :id');
                $stmt->execute([':id' => $id]);
                if ($stmt->rowCount() === 0) {
                    http_response_code(404);
                    echo json_encode(['error' => 'User not found']);
                    break;
                }
                echo json_encode(['success' => true, 'deleted' => 'hard']);
                break;
            }

            $stmt = $pdo->prepare('UPDATE usuarios SET activo = 0 WHERE id = :id');
            $stmt->execute([':id' => $id]);
            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
                break;
            }
            echo json_encode(['success' => true, 'deleted' => 'soft']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

?>
