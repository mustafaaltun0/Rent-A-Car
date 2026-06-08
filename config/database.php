<?php
$host = 'localhost';
$db   = 'rentacar_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    require_once __DIR__ . '/../includes/auth.php';
    auth_bootstrap($pdo);
} catch (PDOException $e) {
    exit('Veritabani baglanti hatasi: ' . $e->getMessage());
}