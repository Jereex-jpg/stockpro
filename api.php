<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require 'config.php';

function out($a) { echo json_encode($a); exit; }
function err($m) { out(['ok' => false, 'error' => $m]); }
// Nivel de seguridad de la contraseña (0 a 100)
function fuerza($p) {
  $s = 0;
  if (strlen($p) >= 8) $s += 25;
  if (preg_match('/[a-z]/', $p) && preg_match('/[A-Z]/', $p)) $s += 25;
  if (preg_match('/\d/', $p)) $s += 25;
  if (preg_match('/[^A-Za-z0-9]/', $p)) $s += 25;
  return $s;
}

$d = json_decode(file_get_contents('php://input'), true) ?: [];
$a = $_GET['a'] ?? '';

if ($a === 'register') {
  $n = trim($d['nombre'] ?? '');
  $u = strtolower(trim($d['usuario'] ?? ''));
  $e = strtolower(trim($d['email'] ?? ''));
  $p = $d['password'] ?? '';
  if ($n === '' || $u === '' || $e === '' || $p === '') err('Completá todos los campos.');
  if (!preg_match('/^[a-z0-9_]{3,30}$/', $u)) err('Usuario: de 3 a 30 letras minúsculas, números o _.');
  if (!filter_var($e, FILTER_VALIDATE_EMAIL)) err('Ingresá un correo electrónico válido.');
  if (strlen($p) < 8) err('La contraseña debe tener al menos 8 caracteres.');
  if (fuerza($p) < 50) err('La contraseña es muy débil. Agregá mayúsculas, números o símbolos.');
  $q = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ?');
  $q->execute([$u]);
  if ($q->fetch()) err('Ese usuario ya existe.');
  $q = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
  $q->execute([$e]);
  if ($q->fetch()) err('Ese correo ya está registrado.');
  // El primer usuario registrado es administrador
  $rol = $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() == 0 ? 'admin' : 'empleado';
  $q = $pdo->prepare('INSERT INTO usuarios (nombre, usuario, email, password_hash, rol) VALUES (?, ?, ?, ?, ?)');
  $q->execute([$n, $u, $e, password_hash($p, PASSWORD_DEFAULT), $rol]);
  session_regenerate_id(true);
  $_SESSION['uid'] = $pdo->lastInsertId();
  out(['ok' => true, 'user' => ['usuario' => $u, 'rol' => $rol]]);
}

if ($a === 'login') {
  // Se puede entrar con el usuario o con el correo
  $u = strtolower(trim($d['usuario'] ?? ''));
  $q = $pdo->prepare('SELECT * FROM usuarios WHERE usuario = ? OR email = ?');
  $q->execute([$u, $u]);
  $r = $q->fetch(PDO::FETCH_ASSOC);
  if (!$r || !password_verify($d['password'] ?? '', $r['password_hash'])) err('Usuario o contraseña incorrectos.');
  session_regenerate_id(true);
  $_SESSION['uid'] = $r['id'];
  out(['ok' => true, 'user' => ['usuario' => $r['usuario'], 'rol' => $r['rol']]]);
}

if ($a === 'me') {
  if (empty($_SESSION['uid'])) out(['ok' => true, 'user' => null]);
  $q = $pdo->prepare('SELECT usuario, rol FROM usuarios WHERE id = ?');
  $q->execute([$_SESSION['uid']]);
  out(['ok' => true, 'user' => $q->fetch(PDO::FETCH_ASSOC) ?: null]);
}

if ($a === 'logout') {
  session_destroy();
  out(['ok' => true]);
}

err('Acción no válida.');
