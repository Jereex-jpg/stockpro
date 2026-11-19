<?php
// COPIA DE EJEMPLO. Copiá este archivo como config.php y completá tus datos.
// No subas config.php con tu contraseña real a GitHub.
$host = 'TU_HOST_MYSQL';        // ej: sql123.infinityfree.com
$db   = 'TU_BASE_DE_DATOS';     // ej: if0_12345678_golstock
$user = 'TU_USUARIO';           // ej: if0_12345678
$pass = 'TU_CONTRASEÑA';
try {
  $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  ]);
} catch (Exception $e) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'No se pudo conectar a la base de datos. Revisá config.php.']);
  exit;
}
