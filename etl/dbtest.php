<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../config.php';
echo "config.php geladen.\n";

try {
    $pdo = new PDO($dsn, $username, $password, $options);
    echo "Verbindung zur Datenbank steht.\n";
} catch (Throwable $e) {
    echo 'Fehler: ' . $e->getMessage() . "\n";
}