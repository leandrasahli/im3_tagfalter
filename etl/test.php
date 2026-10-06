<?php
/**
 * Test – prüft Extract und Transform ohne Datenbank.
 * Aufruf im Browser (PhpStorm-Server) oder im Terminal: php etl/test.php
 * Nach dem Test kann die Datei gelöscht werden.
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

$result = include __DIR__ . '/transform.php';

echo "=== AUDIT ===\n";
print_r($result['audit']);

echo "\n=== ERSTE 3 JAHRE ===\n";
print_r(array_slice($result['data'], 0, 3));

echo "\n=== ANZAHL JAHRE: " . count($result['data']) . " ===\n";
 