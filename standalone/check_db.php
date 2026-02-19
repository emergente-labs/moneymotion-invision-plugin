<?php
$db = new PDO('sqlite:' . __DIR__ . '/data/sessions.sqlite');
$rows = $db->query('SELECT * FROM checkout_sessions ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode($rows, JSON_PRETTY_PRINT);
