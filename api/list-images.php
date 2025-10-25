<?php
header("Access-Control-Allow-Origin: http://localhost:5174");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

$imagesDir = __DIR__ . '/../images';
$result = array();
if (is_dir($imagesDir)) {
    $files = scandir($imagesDir);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        if (is_file($imagesDir . '/' . $f)) $result[] = $f;
    }
    echo json_encode($result);
} else {
    http_response_code(404);
    echo json_encode(array('error' => 'images directory not found', 'path' => $imagesDir));
}

?>
