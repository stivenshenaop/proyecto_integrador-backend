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
$userId = null;
if (isset($_SESSION['user'])) {
    $sessionUser = $_SESSION['user'];
    $role = $sessionUser['rol'] ?? $sessionUser['role'] ?? null;
    if ($role === 'admin') {
        $isAdmin = true;
    }
    $userId = $sessionUser['id'] ?? null;
}

$cfg = @include __DIR__ . '/upload-config.php';
$providedToken = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? null;
$tokenIsValid = isset($cfg['admin_token']) && $providedToken === $cfg['admin_token'];

try {
    $pdo = new PDO("mysql:host=localhost;dbname=juegamas;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed', 'details' => $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

function formatOrderRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'usuario_id' => (int) $row['usuario_id'],
        'fecha_pedido' => $row['fecha_pedido'],
        'total' => (float) $row['total'],
        'estado' => $row['estado'],
        'metodo_pago' => $row['metodo_pago'],
        'direccion_envio' => $row['direccion_envio'],
        'usuario' => isset($row['usuario_email']) ? [
            'email' => $row['usuario_email'],
            'nombre' => $row['usuario_nombre'],
        ] : null,
    ];
}

switch ($method) {
    case 'GET':
        $id = isset($_GET['id']) ? (int) $_GET['id'] : null;
        $queryUserId = isset($_GET['usuario_id']) ? (int) $_GET['usuario_id'] : null;
        $status = isset($_GET['estado']) ? trim($_GET['estado']) : '';

        if (!$isAdmin && !$tokenIsValid) {
            if (!$userId) {
                http_response_code(403);
                echo json_encode(['error' => 'Not authenticated']);
                break;
            }
            $queryUserId = $userId;
        }

        try {
            if ($id) {
                $sql = 'SELECT p.*, u.email AS usuario_email, CONCAT(u.primer_nombre, " ", COALESCE(u.segundo_nombre, "")) AS usuario_nombre
                        FROM pedidos p
                        LEFT JOIN usuarios u ON p.usuario_id = u.id
                        WHERE p.id = :id';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Order not found']);
                    break;
                }
                if (!$isAdmin && !$tokenIsValid && $order['usuario_id'] != $queryUserId) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Forbidden']);
                    break;
                }
                echo json_encode(formatOrderRow($order));
                break;
            }

            $conditions = [];
            $params = [];
            if ($queryUserId) {
                $conditions[] = 'p.usuario_id = :usuario_id';
                $params[':usuario_id'] = $queryUserId;
            }
            if ($status !== '') {
                $conditions[] = 'p.estado = :estado';
                $params[':estado'] = $status;
            }

            $sql = 'SELECT p.*, u.email AS usuario_email, CONCAT(u.primer_nombre, " ", COALESCE(u.segundo_nombre, "")) AS usuario_nombre
                    FROM pedidos p
                    LEFT JOIN usuarios u ON p.usuario_id = u.id';
            if ($conditions) {
                $sql .= ' WHERE ' . implode(' AND ', $conditions);
            }
            $sql .= ' ORDER BY p.fecha_pedido DESC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $orders = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $orders[] = formatOrderRow($row);
            }
            echo json_encode($orders);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'POST':
        if (!$isAdmin && !$tokenIsValid && !$userId) {
            http_response_code(403);
            echo json_encode(['error' => 'Not authenticated']);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $usuarioId = isset($input['usuario_id']) ? (int) $input['usuario_id'] : $userId;
        $total = isset($input['total']) ? (float) $input['total'] : null;
        $estado = isset($input['estado']) ? trim($input['estado']) : 'pendiente';
        $metodoPago = isset($input['metodo_pago']) ? trim($input['metodo_pago']) : null;
        $direccionEnvio = isset($input['direccion_envio']) ? trim($input['direccion_envio']) : null;

        if ($usuarioId <= 0 || $total === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields (usuario_id, total)']);
            break;
        }

        $allowedStates = ['pendiente', 'procesando', 'completado', 'cancelado'];
        if (!in_array($estado, $allowedStates, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid order status']);
            break;
        }

        try {
            $checkUser = $pdo->prepare('SELECT id FROM usuarios WHERE id = :id LIMIT 1');
            $checkUser->execute([':id' => $usuarioId]);
            if (!$checkUser->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid usuario_id']);
                break;
            }

            $insert = $pdo->prepare('INSERT INTO pedidos (usuario_id, total, estado, metodo_pago, direccion_envio) VALUES (:usuario_id, :total, :estado, :metodo_pago, :direccion_envio)');
            $insert->execute([
                ':usuario_id' => $usuarioId,
                ':total' => $total,
                ':estado' => $estado,
                ':metodo_pago' => $metodoPago,
                ':direccion_envio' => $direccionEnvio,
            ]);

            $newId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('SELECT p.*, u.email AS usuario_email, CONCAT(u.primer_nombre, " ", COALESCE(u.segundo_nombre, "")) AS usuario_nombre
                                   FROM pedidos p
                                   LEFT JOIN usuarios u ON p.usuario_id = u.id
                                   WHERE p.id = :id');
            $stmt->execute([':id' => $newId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            http_response_code(201);
            echo json_encode(formatOrderRow($order));
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'PUT':
        if (!$isAdmin && !$tokenIsValid) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            break;
        }

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

        if (array_key_exists('usuario_id', $input)) {
            $usuarioId = (int) $input['usuario_id'];
            if ($usuarioId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid usuario_id']);
                break;
            }
            $fields[] = 'usuario_id = :usuario_id';
            $params[':usuario_id'] = $usuarioId;
        }
        if (array_key_exists('total', $input)) {
            $fields[] = 'total = :total';
            $params[':total'] = (float) $input['total'];
        }
        if (array_key_exists('estado', $input)) {
            $allowedStates = ['pendiente', 'procesando', 'completado', 'cancelado'];
            if (!in_array($input['estado'], $allowedStates, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid order status']);
                break;
            }
            $fields[] = 'estado = :estado';
            $params[':estado'] = $input['estado'];
        }
        if (array_key_exists('metodo_pago', $input)) {
            $fields[] = 'metodo_pago = :metodo_pago';
            $params[':metodo_pago'] = trim($input['metodo_pago']) !== '' ? trim($input['metodo_pago']) : null;
        }
        if (array_key_exists('direccion_envio', $input)) {
            $fields[] = 'direccion_envio = :direccion_envio';
            $params[':direccion_envio'] = trim($input['direccion_envio']) !== '' ? trim($input['direccion_envio']) : null;
        }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields provided for update']);
            break;
        }

        try {
            $pdo->beginTransaction();

            if (isset($params[':usuario_id'])) {
                $checkUser = $pdo->prepare('SELECT id FROM usuarios WHERE id = :id LIMIT 1');
                $checkUser->execute([':id' => $params[':usuario_id']]);
                if (!$checkUser->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid usuario_id']);
                    break;
                }
            }

            $sql = 'UPDATE pedidos SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare('SELECT id FROM pedidos WHERE id = :id');
                $check->execute([':id' => $id]);
                if (!$check->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->rollBack();
                    http_response_code(404);
                    echo json_encode(['error' => 'Order not found']);
                    break;
                }
            }

            $pdo->commit();

            $stmt = $pdo->prepare('SELECT p.*, u.email AS usuario_email, CONCAT(u.primer_nombre, " ", COALESCE(u.segundo_nombre, "")) AS usuario_nombre
                                   FROM pedidos p
                                   LEFT JOIN usuarios u ON p.usuario_id = u.id
                                   WHERE p.id = :id');
            $stmt->execute([':id' => $id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(formatOrderRow($order));
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'DELETE':
        if (!$isAdmin && !$tokenIsValid) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            break;
        }

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

        try {
            $stmt = $pdo->prepare('DELETE FROM pedidos WHERE id = :id');
            $stmt->execute([':id' => $id]);
            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Order not found']);
                break;
            }
            echo json_encode(['success' => true]);
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
