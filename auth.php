<?php
// Archivo de acceso. Todas las operaciones continúan usando la sesión y CSRF del portal.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
  'httponly' => true,
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
  'samesite' => 'Lax'
]);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function validarCsrf(){
  $token = $_POST['csrf_token'] ?? '';
  if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Sesión vencida. Recargue la página.'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

$config = require __DIR__ . '/auth_config.php';
if (!is_array($config) || empty($config['password_hash'])) {
  http_response_code(503);
  exit('Configuración de acceso incompleta.');
}

if (isset($_POST['login_password'])) {
  validarCsrf();
  $pass = $_POST['login_password'];
  if (is_string($pass) && password_verify($pass, $config['password_hash'])) {
    session_regenerate_id(true);
    $_SESSION['analisis_ok'] = true;
    header('Location: index.php');
    exit;
  }
  $login_error = 'Contraseña incorrecta';
}
unset($config, $pass);

if (isset($_GET['logout'])) {
  $_SESSION = [];
  setcookie(session_name(), '', array_merge(session_get_cookie_params(), ['expires' => time() - 3600]));
  session_destroy();
  header('Location: index.php');
  exit;
}
