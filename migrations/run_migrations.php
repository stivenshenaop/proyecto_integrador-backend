<?php
// Simple migration runner for local development
// Usage: open in browser http://localhost/Backend/migrations/run_migrations.php?run=1

if (!isset($_GET['run'])) {
    echo "This script will apply migrations. Append ?run=1 to execute.\n";
    echo "Available migration: 001_add_avatar_favorites.sql\n";
    exit;
}

try {
    $pdo = new PDO("mysql:host=localhost;dbname=juegamas;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = file_get_contents(__DIR__ . '/001_add_avatar_favorites.sql');
    $pdo->exec($sql);
    echo "Migration applied successfully\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage();
}
