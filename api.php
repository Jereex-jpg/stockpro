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

// Devuelve el usuario logueado (leído de la base) o corta con error :)
function usuario_actual($pdo) {
  if (!empty($_SESSION['uid'])) {
    $q = $pdo->prepare('SELECT id, usuario, rol FROM usuarios WHERE id = ?');
    $q->execute([$_SESSION['uid']]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    if ($u) return $u;
  }
  http_response_code(401);
  err('Tenés que iniciar sesión.');
}
function exigir_admin($u) {
  if ($u['rol'] !== 'admin') {
    http_response_code(403);
    err('Solo el administrador puede hacer esto.');
  }
}
function nombre_valido($s, $max) {
  $s = trim($s);
  if ($s === '' || mb_strlen($s) > $max) err("El nombre es obligatorio (máximo $max caracteres).");
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

//  CATEGORÍAS (cualquier usuario logueado) 
if ($a === 'cat_list') {
  usuario_actual($pdo);
  $r = $pdo->query('SELECT c.id, c.nombre, COUNT(p.id) AS productos FROM categorias c LEFT JOIN productos p ON p.categoria_id = c.id AND p.activo = 1 GROUP BY c.id, c.nombre ORDER BY c.nombre')->fetchAll(PDO::FETCH_ASSOC);
  out(['ok' => true, 'categorias' => $r]);
}
if ($a === 'cat_add') {
  usuario_actual($pdo);
  $n = nombre_valido($d['nombre'] ?? '', 60);
  try {
    $pdo->prepare('INSERT INTO categorias (nombre) VALUES (?)')->execute([$n]);
  } catch (PDOException $e) {
    err($e->getCode() === '23000' ? 'Ya existe esa categoría.' : 'Error en la base de datos.');
  }
  out(['ok' => true, 'id' => $pdo->lastInsertId()]);
}
if ($a === 'cat_edit') {
  usuario_actual($pdo);
  $n = nombre_valido($d['nombre'] ?? '', 60);
  try {
    $pdo->prepare('UPDATE categorias SET nombre = ? WHERE id = ?')->execute([$n, (int)($d['id'] ?? 0)]);
  } catch (PDOException $e) {
    err($e->getCode() === '23000' ? 'Ya existe esa categoría.' : 'Error en la base de datos.');
  }
  out(['ok' => true]);
}
if ($a === 'cat_del') {
  usuario_actual($pdo);
  try {
    $pdo->prepare('DELETE FROM categorias WHERE id = ?')->execute([(int)($d['id'] ?? 0)]);
  } catch (PDOException $e) {
    err($e->getCode() === '23000' ? 'No se puede eliminar: la categoría tiene productos.' : 'Error en la base de datos.');
  }
  out(['ok' => true]);
}

//  PROVEEDORES (agregar y editar: todos; eliminar: solo admin) 
if ($a === 'prov_list') {
  usuario_actual($pdo);
  $r = $pdo->query("SELECT pr.id, pr.nombre, pr.telefono, COALESCE(GROUP_CONCAT(p.nombre ORDER BY p.nombre SEPARATOR ', '), '') AS productos FROM proveedores pr LEFT JOIN productos p ON p.proveedor_id = pr.id AND p.activo = 1 GROUP BY pr.id, pr.nombre, pr.telefono ORDER BY pr.nombre")->fetchAll(PDO::FETCH_ASSOC);
  out(['ok' => true, 'proveedores' => $r]);
}
if ($a === 'prov_add') {
  usuario_actual($pdo);
  $n = nombre_valido($d['nombre'] ?? '', 100);
  $t = trim($d['telefono'] ?? '');
  $t = $t === '' ? '-' : mb_substr($t, 0, 40);
  $pdo->prepare('INSERT INTO proveedores (nombre, telefono) VALUES (?, ?)')->execute([$n, $t]);
  out(['ok' => true, 'id' => $pdo->lastInsertId()]);
}
if ($a === 'prov_edit') {
  usuario_actual($pdo);
  $n = nombre_valido($d['nombre'] ?? '', 100);
  $t = trim($d['telefono'] ?? '');
  $t = $t === '' ? '-' : mb_substr($t, 0, 40);
  $pdo->prepare('UPDATE proveedores SET nombre = ?, telefono = ? WHERE id = ?')->execute([$n, $t, (int)($d['id'] ?? 0)]);
  out(['ok' => true]);
}
if ($a === 'prov_del') {
  exigir_admin(usuario_actual($pdo));
  $pdo->prepare('DELETE FROM proveedores WHERE id = ?')->execute([(int)($d['id'] ?? 0)]);
  out(['ok' => true]);
}

//  PRODUCTOS
if ($a === 'prod_add') {
  $u = usuario_actual($pdo);
  $n = nombre_valido($d['nombre'] ?? '', 120);
  $cat = (int)($d['categoria_id'] ?? 0);
  $prov = (int)($d['proveedor_id'] ?? 0) ?: null;
  $precio = max(0, (float)($d['precio'] ?? 0));
  // El costo solo lo define el admin; el del empleado se ignora
  $costo = $u['rol'] === 'admin' ? max(0, (float)($d['costo'] ?? 0)) : 0;
  $min = max(0, (int)($d['minimo'] ?? 5));
  // Stock inicial: una fila por talle (sin talles: una sola fila con talle vacío)
  $filas = [];
  if (!empty($d['talles']) && is_array($d['talles'])) {
    foreach ($d['talles'] as $t => $c) {
      $t = mb_substr(trim((string)$t), 0, 20);
      if ($t === '') continue;
      $filas[$t] = max(0, (int)$c);
    }
  }
  if (!$filas) $filas[''] = max(0, (int)($d['stock'] ?? 0));
  $q = $pdo->prepare('SELECT id FROM categorias WHERE id = ?');
  $q->execute([$cat]);
  if (!$q->fetch()) err('Elegí una categoría válida.');
  if ($prov) {
    $q = $pdo->prepare('SELECT id FROM proveedores WHERE id = ?');
    $q->execute([$prov]);
    if (!$q->fetch()) err('Proveedor no válido.');
  }
  $pdo->beginTransaction();
  try {
    $pdo->prepare('INSERT INTO productos (nombre, categoria_id, proveedor_id, precio, costo, minimo) VALUES (?,?,?,?,?,?)')->execute([$n, $cat, $prov, $precio, $costo, $min]);
    $pid = $pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO producto_talles (producto_id, talle, stock) VALUES (?,?,?)');
    $mv = $pdo->prepare("INSERT INTO movimientos (usuario_id, producto_id, talle, tipo, cantidad, motivo) VALUES (?,?,?, 'Alta', ?, 'Producto nuevo')");
    $hubo = false;
    foreach ($filas as $t => $c) {
      $ins->execute([$pid, (string)$t, $c]);
      if ($c > 0) { $mv->execute([$u['id'], $pid, (string)$t, $c]); $hubo = true; }
    }
    if (!$hubo) $mv->execute([$u['id'], $pid, (string)array_key_first($filas), 0]);
    $pdo->commit();
  } catch (Exception $e) {
    $pdo->rollBack();
    err('No se pudo guardar el producto.');
  }
  out(['ok' => true, 'id' => $pid]);
}

if ($a === 'prod_list') {
  $u = usuario_actual($pdo);
  $es_admin = $u['rol'] === 'admin';
  $prods = $pdo->query('SELECT p.id, p.nombre, p.categoria_id, c.nombre AS categoria, p.proveedor_id, pr.nombre AS proveedor, p.precio, p.costo, p.minimo FROM productos p JOIN categorias c ON c.id = p.categoria_id LEFT JOIN proveedores pr ON pr.id = p.proveedor_id WHERE p.activo = 1 ORDER BY p.nombre')->fetchAll(PDO::FETCH_ASSOC);
  $tal = [];
  foreach ($pdo->query('SELECT t.producto_id, t.talle, t.stock FROM producto_talles t JOIN productos p ON p.id = t.producto_id WHERE p.activo = 1 ORDER BY t.talle') as $r) {
    $tal[$r['producto_id']][$r['talle']] = (int)$r['stock'];
  }
  foreach ($prods as &$p) {
    $filas = $tal[$p['id']] ?? [];
    $p['stock'] = array_sum($filas);
    // Sin talles: null. Con talles: {"M":6,"L":0,...}
    $p['talles'] = (count($filas) === 1 && isset($filas[''])) ? null : $filas;
    $p['precio'] = (float)$p['precio'];
    $p['minimo'] = (int)$p['minimo'];
    if ($es_admin) $p['costo'] = (float)$p['costo'];
    else unset($p['costo']);
  }
  unset($p);
  out(['ok' => true, 'productos' => $prods]);
}

if ($a === 'prod_edit') {
  $u = usuario_actual($pdo);
  $id = (int)($d['id'] ?? 0);
  $n = nombre_valido($d['nombre'] ?? '', 120);
  $cat = (int)($d['categoria_id'] ?? 0);
  $prov = (int)($d['proveedor_id'] ?? 0) ?: null;
  $min = max(0, (int)($d['minimo'] ?? 5));
  $q = $pdo->prepare('SELECT id FROM productos WHERE id = ? AND activo = 1');
  $q->execute([$id]);
  if (!$q->fetch()) err('El producto no existe.');
  $q = $pdo->prepare('SELECT id FROM categorias WHERE id = ?');
  $q->execute([$cat]);
  if (!$q->fetch()) err('Elegí una categoría válida.');
  if ($prov) {
    $q = $pdo->prepare('SELECT id FROM proveedores WHERE id = ?');
    $q->execute([$prov]);
    if (!$q->fetch()) err('Proveedor no válido.');
  }
  $pdo->prepare('UPDATE productos SET nombre = ?, categoria_id = ?, proveedor_id = ?, minimo = ? WHERE id = ?')->execute([$n, $cat, $prov, $min, $id]);
  // Precio y costo: solo el admin (si el empleado los manda, se ignoran)
  if ($u['rol'] === 'admin') {
    if (isset($d['precio'])) $pdo->prepare('UPDATE productos SET precio = ? WHERE id = ?')->execute([max(0, (float)$d['precio']), $id]);
    if (isset($d['costo'])) $pdo->prepare('UPDATE productos SET costo = ? WHERE id = ?')->execute([max(0, (float)$d['costo']), $id]);
  }
  out(['ok' => true]);
}

if ($a === 'prod_del') {
  exigir_admin(usuario_actual($pdo));
  $q = $pdo->prepare('UPDATE productos SET activo = 0 WHERE id = ? AND activo = 1');
  $q->execute([(int)($d['id'] ?? 0)]);
  if ($q->rowCount() === 0) err('El producto no existe.');
  out(['ok' => true]);
}

if ($a === 'mov_add') {
  $u = usuario_actual($pdo);
  $tipo = $d['tipo'] ?? '';
  if (!in_array($tipo, ['Entrada', 'Venta', 'Ajuste'], true)) err('Tipo de movimiento no válido.');
  $n = (int)($d['cantidad'] ?? 0);
  if ($n < 1) err('Ingresá una cantidad mayor a 0.');
  $motivo = mb_substr(trim((string)($d['motivo'] ?? '')), 0, 200);
  if ($tipo === 'Ajuste' && $motivo === '') err('En un ajuste el motivo es obligatorio.');
  if ($motivo === '') $motivo = '-';
  $talle = mb_substr(trim((string)($d['talle'] ?? '')), 0, 20);
  $delta = $tipo === 'Entrada' ? $n : ($tipo === 'Venta' ? -$n : ((int)($d['signo'] ?? 1) < 0 ? -$n : $n));
  $pid = (int)($d['producto_id'] ?? 0);
  $q = $pdo->prepare('SELECT precio, costo FROM productos WHERE id = ? AND activo = 1');
  $q->execute([$pid]);
  $p = $q->fetch(PDO::FETCH_ASSOC);
  if (!$p) err('El producto no existe.');
  $q = $pdo->prepare('SELECT stock FROM producto_talles WHERE producto_id = ? AND talle = ?');
  $q->execute([$pid, $talle]);
  if ($q->fetch() === false) err($talle === '' ? 'Elegí un talle.' : 'Talle no válido.');
  $pdo->beginTransaction();
  try {
    // El stock nunca queda en negativo: la condición va dentro del propio UPDATE
    $s = $pdo->prepare('UPDATE producto_talles SET stock = stock + ? WHERE producto_id = ? AND talle = ? AND stock + ? >= 0');
    $s->execute([$delta, $pid, $talle, $delta]);
    if ($s->rowCount() === 0) { $pdo->rollBack(); err('No hay stock suficiente.'); }
    $venta = $tipo === 'Venta';
    $pdo->prepare('INSERT INTO movimientos (usuario_id, producto_id, talle, tipo, cantidad, motivo, precio_unit, costo_unit) VALUES (?,?,?,?,?,?,?,?)')->execute([$u['id'], $pid, $talle, $tipo, $delta, $motivo, $venta ? $p['precio'] : null, $venta ? $p['costo'] : null]);
    $pdo->commit();
  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    err('No se pudo registrar el movimiento.');
  }
  $q = $pdo->prepare('SELECT SUM(stock) FROM producto_talles WHERE producto_id = ?');
  $q->execute([$pid]);
  out(['ok' => true, 'stock_total' => (int)$q->fetchColumn()]);
}

if ($a === 'mov_list') {
  usuario_actual($pdo);
  $r = $pdo->query('SELECT m.id, m.fecha, u.usuario, p.nombre AS producto, m.talle, m.tipo, m.cantidad, m.motivo FROM movimientos m JOIN usuarios u ON u.id = m.usuario_id JOIN productos p ON p.id = m.producto_id ORDER BY m.id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
  out(['ok' => true, 'movimientos' => $r]);
}

err('Acción no válida.');
