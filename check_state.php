<?php
require __DIR__.'/vendor/autoload.php';
$pdo = new PDO('sqlite:'.__DIR__.'/database/database.sqlite');
$r = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
$names = array_column($r->fetchAll(PDO::FETCH_ASSOC), 'name');
echo implode(',', $names) . "\n";
echo "Tables count: " . count($names) . "\n";
