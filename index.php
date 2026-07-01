<?php
session_start();

define('PASSWORD_SISTEMA', 'Pasteleros2026');

if (isset($_POST['login_password'])) {
  if ($_POST['login_password'] === PASSWORD_SISTEMA) {
    $_SESSION['analisis_ok'] = true;
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
  } else {
    $login_error = "ContraseÃ±a incorrecta";
  }
}

if (isset($_GET['logout'])) {
  session_destroy();
  header("Location: " . $_SERVER['PHP_SELF']);
  exit;
}

if (empty($_SESSION['analisis_ok'])) {
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Acceso restringido</title>

<style>
body{
  margin:0;
  background:#f4f7f6;
  font-family:Arial;
  display:flex;
  align-items:center;
  justify-content:center;
  height:100vh;
}

.card{
  background:white;
  padding:35px;
  border-radius:18px;
  width:340px;
  box-shadow:0 10px 30px rgba(0,0,0,.15);
}

h1{
  margin-top:0;
  color:#0f7a43;
}

input{
  width:100%;
  padding:14px;
  border-radius:10px;
  border:1px solid #d1d5db;
  margin-top:10px;
  margin-bottom:15px;
  box-sizing:border-box;
}

button{
  width:100%;
  padding:14px;
  border:none;
  border-radius:10px;
  background:#0f7a43;
  color:white;
  font-weight:bold;
  cursor:pointer;
}

.error{
  color:#b91c1c;
  margin-bottom:10px;
}
</style>
</head>

<body>

<div class="card">

  <h1>REGISTRO_ANALISIS</h1>

  <?php if (!empty($login_error)) { ?>
    <div class="error"><?= $login_error ?></div>
  <?php } ?>

  <form method="POST">

    <input
      type="password"
      name="login_password"
      placeholder="Contraseña"
      required
    >

    <button type="submit">
      Ingresar
    </button>

  </form>

</div>

</body>
</html>
<?php
exit;
}

/* ============================================================
   REGISTRO_ANALISIS - SISTEMA SIMPLE EN PHP + JSON
   ------------------------------------------------------------
   Subir este archivo como:
   /analisis/index.php

   El sistema crea solo:
   /analisis/registros.json
   /analisis/adjuntos/pendientes/
   /analisis/adjuntos/realizados/

   Requisitos:
   - Hosting con PHP habilitado
   - Permisos de escritura en la carpeta /analisis/
   ============================================================ */

date_default_timezone_set('America/Argentina/Buenos_Aires');

define('DATA_FILE', __DIR__ . '/registros.json');
define('ADJUNTOS_DIR', __DIR__ . '/adjuntos');
define('PENDIENTES_DIR', ADJUNTOS_DIR . '/pendientes');
define('REALIZADOS_DIR', ADJUNTOS_DIR . '/realizados');
define('RESULTADOS_DIR', __DIR__ . '/resultados');
// Requiere PHPMailer en vendor/autoload.php o en phpmailer/src/.
define('SMTP_HOST', 'pastagl.ferozo.com');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');
define('SMTP_USER', 'consultorios@pastelerosmendoza.org');
define('SMTP_PASS', 'PEGAR_CONTRASENA_SOLO_EN_DONWEB');
define('SMTP_FROM', 'consultorios@pastelerosmendoza.org');
define('SMTP_FROM_NAME', 'Consultorios OSP Pasteleros Mendoza');

function asegurarSistema(){
  foreach ([ADJUNTOS_DIR, PENDIENTES_DIR, REALIZADOS_DIR, RESULTADOS_DIR] as $dir) {
    if (!is_dir($dir)) {
      mkdir($dir, 0755, true);
    }
  }

  if (!file_exists(DATA_FILE)) {
    file_put_contents(DATA_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
  }
}

function leerRegistros(){
  asegurarSistema();
  $json = file_get_contents(DATA_FILE);
  $data = json_decode($json, true);
  return is_array($data) ? $data : [];
}

function guardarRegistros($registros){
  asegurarSistema();
  file_put_contents(DATA_FILE, json_encode(array_values($registros), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function responderJson($ok, $extra = []){
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

function limpiarTexto($txt){
  $txt = trim((string)$txt);
  $txt = preg_replace('/\s+/', ' ', $txt);
  return $txt;
}

function esPagadoSi($valor){
  $valor = trim((string)$valor);
  $valor = function_exists('mb_strtolower')
    ? mb_strtolower($valor, 'UTF-8')
    : strtolower($valor);
  $valor = str_replace(['í', 'Ã­', 'ã­'], 'i', $valor);
  return $valor === 'si';
}

function limpiarNombreArchivo($txt){
  $txt = limpiarTexto($txt);
  $txt = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
  $txt = preg_replace('/[^A-Za-z0-9._ -]/', '', $txt);
  $txt = str_replace(['/', '\\'], '-', $txt);
  $txt = preg_replace('/\s+/', ' ', $txt);
  return trim($txt) ?: 'archivo';
}

function fechaArchivo(){
  return date('Y-m-d_H-i-s');
}

function rutaWeb($rutaAbsoluta){
  $rel = str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $rutaAbsoluta);
  $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
  return $rel;
}

function guardarAdjunto($campo, $estado, $dni, $nombre){
  if (!isset($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
    return '';
  }

  if ($_FILES[$campo]['error'] !== UPLOAD_ERR_OK) {
    throw new Exception('No se pudo subir el archivo.');
  }

  $permitidos = ['pdf', 'jpg', 'jpeg', 'png'];
  $original = $_FILES[$campo]['name'];
  $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

  if (!in_array($ext, $permitidos, true)) {
    throw new Exception('Archivo no permitido. Solo PDF, JPG, JPEG o PNG.');
  }

  $baseDir = strtolower($estado) === 'realizado' ? REALIZADOS_DIR : PENDIENTES_DIR;

  if (strtolower($estado) === 'realizado') {
    $baseDir .= '/' . date('Y-m');
  }

  $persona = limpiarNombreArchivo($nombre . ' - DNI ' . $dni);
  $destDir = $baseDir . '/' . $persona;

  if (!is_dir($destDir)) {
    mkdir($destDir, 0755, true);
  }

  $nombreFinal = fechaArchivo() . ' - ' . limpiarNombreArchivo($original);
  $destino = $destDir . '/' . $nombreFinal;

  if (!move_uploaded_file($_FILES[$campo]['tmp_name'], $destino)) {
    throw new Exception('No se pudo guardar el adjunto en el hosting.');
  }

  return rutaWeb($destino);
}

function moverAdjuntoARealizados($rutaRelativa, $dni, $nombre){
  if (!$rutaRelativa) return '';

  $origen = __DIR__ . '/' . str_replace(['../', '..\\'], '', $rutaRelativa);
  if (!file_exists($origen)) return $rutaRelativa;

  $persona = limpiarNombreArchivo($nombre . ' - DNI ' . $dni);
  $destDir = REALIZADOS_DIR . '/' . date('Y-m') . '/' . $persona;

  if (!is_dir($destDir)) {
    mkdir($destDir, 0755, true);
  }

  $destino = $destDir . '/' . basename($origen);

  if ($origen !== $destino) {
    @rename($origen, $destino);
  }

  return rutaWeb($destino);
}

function guardarResultado($campo, $dni, $nombre){
  if (!isset($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
    throw new Exception('Debe seleccionar un resultado.');
  }

  if ($_FILES[$campo]['error'] !== UPLOAD_ERR_OK) {
    throw new Exception('No se pudo subir el resultado.');
  }

  $permitidos = ['pdf', 'jpg', 'jpeg', 'png'];
  $original = $_FILES[$campo]['name'];
  $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

  if (!in_array($ext, $permitidos, true)) {
    throw new Exception('Resultado no permitido. Solo PDF, JPG, JPEG o PNG.');
  }

  $persona = limpiarNombreArchivo($nombre . ' - DNI ' . $dni);
  $destDir = RESULTADOS_DIR . '/' . date('Y-m') . '/' . $persona;

  if (!is_dir($destDir)) {
    mkdir($destDir, 0755, true);
  }

  $destino = $destDir . '/resultado.' . $ext;

  if (!move_uploaded_file($_FILES[$campo]['tmp_name'], $destino)) {
    throw new Exception('No se pudo guardar el resultado en el hosting.');
  }

  return rutaWeb($destino);
}

function eliminarArchivoYCarpetasVacias($rutaRelativa, $limiteDir){
  if (!$rutaRelativa) return;

  $rutaRelativa = str_replace(['../', '..\\'], '', $rutaRelativa);
  $archivo = __DIR__ . '/' . $rutaRelativa;

  if (file_exists($archivo) && is_file($archivo)) {
    @unlink($archivo);
  }

  $dir = dirname($archivo);
  $limite = realpath($limiteDir);

  while ($dir && is_dir($dir) && realpath($dir) !== $limite) {
    $restantes = array_diff(scandir($dir), ['.', '..']);

    if (count($restantes) === 0) {
      @rmdir($dir);
      $dir = dirname($dir);
    } else {
      break;
    }
  }
}

function cargarPHPMailer(){
  if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
    return true;
  }

  $autoload = __DIR__ . '/vendor/autoload.php';
  if (file_exists($autoload)) {
    require_once $autoload;
  }

  if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
    $base = __DIR__ . '/phpmailer/src';
    $archivos = [
      $base . '/Exception.php',
      $base . '/PHPMailer.php',
      $base . '/SMTP.php'
    ];

    if (file_exists($archivos[0]) && file_exists($archivos[1]) && file_exists($archivos[2])) {
      require_once $archivos[0];
      require_once $archivos[1];
      require_once $archivos[2];
    }
  }

  return class_exists('\\PHPMailer\\PHPMailer\\PHPMailer');
}

function rutaResultadoAbsoluta($rutaRelativa){
  $rutaRelativa = str_replace(['../', '..\\'], '', $rutaRelativa);
  $archivo = __DIR__ . '/' . $rutaRelativa;

  if (!file_exists($archivo) || !is_file($archivo)) {
    throw new Exception('El archivo de resultado no existe.');
  }

  return $archivo;
}

function enmascararDebugSMTP($debug){
  $debug = (string)$debug;
  $sensibles = [
    SMTP_PASS,
    base64_encode(SMTP_PASS),
    base64_encode("\0" . SMTP_USER . "\0" . SMTP_PASS)
  ];

  foreach ($sensibles as $sensible) {
    if ($sensible !== '') {
      $debug = str_replace($sensible, '[oculto]', $debug);
    }
  }

  return $debug;
}

function registrarErrorEnvioMail($registro, $archivo, $mail, $e, $smtpDebug){
  $logsDir = __DIR__ . '/logs';
  if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0755, true);
  }

  $tamanoArchivo = (is_string($archivo) && file_exists($archivo)) ? filesize($archivo) . ' bytes' : 'No disponible';
  $errorInfo = $mail ? $mail->ErrorInfo : '';
  $contenido =
    "----------------------------------\n" .
    "Fecha: " . date('d/m/Y H:i:s') . "\n" .
    "Registro ID: " . ($registro['id'] ?? '') . "\n" .
    "Destinatario: " . limpiarTexto($registro['email'] ?? '') . "\n" .
    "Nombre: " . limpiarTexto($registro['nombre'] ?? '') . "\n" .
    "Resultado: " . ($registro['resultado'] ?? '') . "\n" .
    "Archivo absoluto: " . (is_string($archivo) ? $archivo : '') . "\n" .
    "Tamaño archivo: " . $tamanoArchivo . "\n" .
    "SMTP_HOST: " . SMTP_HOST . "\n" .
    "SMTP_PORT: " . SMTP_PORT . "\n" .
    "SMTP_SECURE: " . SMTP_SECURE . "\n" .
    "SMTP_USER: " . SMTP_USER . "\n" .
    "PHPMailer ErrorInfo: " . $errorInfo . "\n" .
    "Exception message: " . $e->getMessage() . "\n" .
    "SMTPDebug:\n" . enmascararDebugSMTP($smtpDebug) .
    "----------------------------------\n";

  @file_put_contents($logsDir . '/mail_error.log', $contenido, FILE_APPEND | LOCK_EX);
}

class ErrorEnvioMailException extends Exception {
  private $detalleEmail;

  public function __construct($mensaje, $detalleEmail, Throwable $anterior = null){
    parent::__construct($mensaje, 0, $anterior);
    $this->detalleEmail = $detalleEmail;
  }

  public function getDetalleEmail(){
    return $this->detalleEmail;
  }
}

function enviarResultadoPorEmail($registro){
  $email = limpiarTexto($registro['email'] ?? '');
  $nombre = limpiarTexto($registro['nombre'] ?? '');
  $resultado = $registro['resultado'] ?? '';

  if ($email === '') {
    throw new Exception('Sin email cargado');
  }

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new Exception('Email invÃ¡lido.');
  }

  if ($resultado === '') {
    throw new Exception('El registro no tiene resultado cargado.');
  }

  $archivo = rutaResultadoAbsoluta($resultado);

  if (!cargarPHPMailer()) {
    throw new Exception('PHPMailer no disponible. Instalar con Composer en vendor/ o subir la carpeta phpmailer/src/.');
  }

  $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
  $smtpDebug = '';

  try {
    $mail->SMTPDebug = 2;
    $mail->Debugoutput = function($str, $level) use (&$smtpDebug) {
      $smtpDebug .= "[" . $level . "] " . $str . "\n";
    };

    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = SMTP_SECURE;
    $mail->Port = SMTP_PORT;
    $mail->CharSet = 'UTF-8';

    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $mail->addAddress($email, $nombre);
    $mail->addBCC('consultorios@pastelerosmendoza.org');
    $mail->Subject = html_entity_decode('Resultado de an&aacute;lisis - Obra Social Pasteleros', ENT_QUOTES, 'UTF-8');
    $mail->Body = html_entity_decode(
      "Estimado/a " . ($nombre ?: 'paciente') . ":\n\n" .
      "Adjuntamos el resultado de los an&aacute;lisis realizados en nuestros consultorios.\n\n" .
      "Saludos.\n" .
      "Obra Social Pasteleros Mendoza",
      ENT_QUOTES,
      'UTF-8'
    );
    $mail->addAttachment($archivo, basename($archivo));
    $mail->send();
  } catch (Throwable $e) {
    registrarErrorEnvioMail($registro, $archivo, $mail, $e, $smtpDebug);
    throw new ErrorEnvioMailException($e->getMessage(), $mail->ErrorInfo . ' | ' . $e->getMessage(), $e);
  }
}

function actualizarEstadoEmailResultado(&$registro){
  $email = limpiarTexto($registro['email'] ?? '');

  if ($email === '') {
    $registro['emailEnviado'] = false;
    $registro['fechaEmail'] = '';
    $registro['errorEmail'] = 'Sin email cargado';
    return [
      'enviado' => false,
      'email' => '',
      'error' => 'Sin email cargado',
      'mensajeEmail' => 'No se enviÃ³ email porque el paciente no tiene email cargado.'
    ];
  }

  try {
    enviarResultadoPorEmail($registro);
    $registro['emailEnviado'] = true;
    $registro['fechaEmail'] = date('d/m/Y H:i');
    $registro['errorEmail'] = '';
    return [
      'enviado' => true,
      'email' => $email,
      'error' => '',
      'mensajeEmail' => 'Email enviado a ' . $email . '.'
    ];
  } catch (Throwable $e) {
    $registro['emailEnviado'] = false;
    $registro['fechaEmail'] = '';
    $registro['errorEmail'] = ($e instanceof ErrorEnvioMailException) ? $e->getDetalleEmail() : $e->getMessage();
    return [
      'enviado' => false,
      'email' => $email,
      'error' => $e->getMessage(),
      'mensajeEmail' => 'No se pudo enviar el email. Motivo: ' . $e->getMessage()
    ];
  }
}

function normalizar($txt){
  $txt = mb_strtolower((string)$txt, 'UTF-8');
  $txt = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
  return $txt ?: '';
}

function filtrarRegistros($registros){
  $buscar = normalizar($_GET['buscar'] ?? '');
  $estado = $_GET['estado'] ?? 'Pendiente';
  $pago = $_GET['pago'] ?? 'Todos';

  $filtrados = array_filter($registros, function($r) use ($buscar, $estado, $pago){
    if ($estado !== 'Todos' && ($r['estado'] ?? '') !== $estado) return false;

    if ($pago === 'Pagado' && !esPagadoSi($r['pagado'] ?? '')) return false;
    if ($pago === 'No pagado' && esPagadoSi($r['pagado'] ?? '')) return false;

    if ($buscar !== '') {
      $texto = normalizar(
        ($r['nombre'] ?? '') . ' ' .
        ($r['dni'] ?? '') . ' ' .
        ($r['fechaCarga'] ?? '') . ' ' .
        ($r['estado'] ?? '') . ' ' .
        ($r['pagado'] ?? '')
      );

      if (strpos($texto, $buscar) === false) return false;
    }

    return true;
  });

  usort($filtrados, function($a, $b){
    return strcmp($b['fechaISO'] ?? '', $a['fechaISO'] ?? '');
  });

  return array_values($filtrados);
}

function resumenRegistros($registros){
  $totalRealizados = 0;
  $totalPendientes = 0;
  $totalMonto = 0;
  $totalResultados = 0;
  $totalEmailsEnviados = 0;
  $mesActualRealizados = 0;
  $mesActualMonto = 0;
  $mesActual = date('Y-m');
  $meses = [];

  foreach ($registros as $r) {
    $estado = $r['estado'] ?? 'Pendiente';
    $monto = (float)($r['monto'] ?? 0);
    $fechaISO = $r['fechaISO'] ?? date('Y-m-d');
    $mes = substr($fechaISO, 0, 7);

    if (!empty($r['resultado'])) {
      $totalResultados++;
    }

    if (($r['emailEnviado'] ?? false) === true) {
      $totalEmailsEnviados++;
    }

    if (!isset($meses[$mes])) {
      $meses[$mes] = [
        'mes' => $mes,
        'realizados' => 0,
        'pendientes' => 0,
        'monto' => 0
      ];
    }

    if ($estado === 'Realizado') {
      $totalRealizados++;
      $meses[$mes]['realizados']++;

      if ($mes === $mesActual) {
        $mesActualRealizados++;
      }
    } else {
      $totalPendientes++;
      $meses[$mes]['pendientes']++;
    }

    if (esPagadoSi($r['pagado'] ?? '')) {
      $totalMonto += $monto;
      $meses[$mes]['monto'] += $monto;

      if ($mes === $mesActual) {
        $mesActualMonto += $monto;
      }
    }
  }

  krsort($meses);

  return [
    'totalRealizados' => $totalRealizados,
    'totalPendientes' => $totalPendientes,
    'totalMonto' => $totalMonto,
    'totalResultados' => $totalResultados,
    'totalEmailsEnviados' => $totalEmailsEnviados,
    'mesActualRealizados' => $mesActualRealizados,
    'mesActualMonto' => $mesActualMonto,
    'meses' => array_values($meses)
  ];
}

function hExcel($valor){
  return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function formatoMontoArg($monto){
  return '$ ' . number_format((float)$monto, 2, ',', '.');
}

function nombreMesInforme($mes){
  $partes = explode('-', (string)$mes);
  $anio = $partes[0] ?? '';
  $numeroMes = (int)($partes[1] ?? 0);
  $nombres = [
    1 => 'Enero',
    2 => 'Febrero',
    3 => 'Marzo',
    4 => 'Abril',
    5 => 'Mayo',
    6 => 'Junio',
    7 => 'Julio',
    8 => 'Agosto',
    9 => 'Septiembre',
    10 => 'Octubre',
    11 => 'Noviembre',
    12 => 'Diciembre'
  ];

  return ($nombres[$numeroMes] ?? $mes) . ' ' . $anio;
}

function normalizarMotivoInforme($motivo){
  $motivo = trim((string)$motivo);
  $reemplazos = [
    'ONCOLÃ“GICO' => 'ONCOLOGICO',
    'ONCOLÓGICO' => 'ONCOLOGICO',
    'ONCOLOGICO' => 'ONCOLOGICO',
    'EXCEPCIÃ“N' => 'EXCEPCION',
    'EXCEPCIÓN' => 'EXCEPCION',
    'EXCEPCION' => 'EXCEPCION',
    'DISCAPACIDAD' => 'DISCAPACIDAD',
    'PMI' => 'PMI'
  ];
  $upper = function_exists('mb_strtoupper')
    ? mb_strtoupper($motivo, 'UTF-8')
    : strtoupper($motivo);

  foreach ($reemplazos as $buscar => $normalizado) {
    if (strpos($upper, $buscar) !== false) {
      return $normalizado;
    }
  }

  return $upper;
}

function exportarInformeMensual($registros, $mes){
  if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
    $mes = date('Y-m');
  }

  $delMes = array_values(array_filter($registros, function($r) use ($mes) {
    return substr((string)($r['fechaISO'] ?? ''), 0, 7) === $mes;
  }));

  $resumen = [
    'total' => count($delMes),
    'realizados' => 0,
    'pendientes' => 0,
    'pagados' => 0,
    'noPagados' => 0,
    'recaudado' => 0,
    'resultados' => 0,
    'emails' => 0,
    'montoCero' => 0,
    'pmi' => 0,
    'oncologico' => 0,
    'discapacidad' => 0,
    'excepcion' => 0
  ];
  $diario = [];

  foreach ($delMes as $r) {
    $estado = $r['estado'] ?? 'Pendiente';
    $fechaISO = (string)($r['fechaISO'] ?? '');
    $fechaDia = substr($fechaISO, 0, 10);
    $monto = (float)($r['monto'] ?? 0);
    $pagado = esPagadoSi($r['pagado'] ?? '');
    $motivo = normalizarMotivoInforme($r['motivo'] ?? '');

    if ($estado === 'Realizado') {
      $resumen['realizados']++;
    } else {
      $resumen['pendientes']++;
    }

    if ($pagado) {
      $resumen['pagados']++;
      $resumen['recaudado'] += $monto;
    } else {
      $resumen['noPagados']++;
    }

    if ($monto === 0.0) $resumen['montoCero']++;
    if (!empty($r['resultado'])) $resumen['resultados']++;
    if (($r['emailEnviado'] ?? false) === true) $resumen['emails']++;
    if ($motivo === 'PMI') $resumen['pmi']++;
    if ($motivo === 'ONCOLOGICO') $resumen['oncologico']++;
    if ($motivo === 'DISCAPACIDAD') $resumen['discapacidad']++;
    if ($motivo === 'EXCEPCION') $resumen['excepcion']++;

    if ($fechaDia === '') {
      $fechaDia = 'Sin fecha';
    }

    if (!isset($diario[$fechaDia])) {
      $diario[$fechaDia] = [
        'fecha' => $fechaDia,
        'cantidad' => 0,
        'pagados' => 0,
        'recaudado' => 0
      ];
    }

    $diario[$fechaDia]['cantidad']++;
    if ($pagado) {
      $diario[$fechaDia]['pagados']++;
      $diario[$fechaDia]['recaudado'] += $monto;
    }
  }

  ksort($diario);

  $filename = 'informe_analisis_' . $mes . '.xls';
  header('Content-Type: application/vnd.ms-excel; charset=utf-8');
  header('Content-Disposition: attachment; filename=' . $filename);
  header('Pragma: no-cache');
  header('Expires: 0');

  echo "\xEF\xBB\xBF";
  ?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    body{font-family:Arial,Helvetica,sans-serif;color:#1f2933;}
    h1{color:#0f7a43;font-size:22px;margin:0 0 8px;}
    h2{color:#0f7a43;font-size:16px;margin:24px 0 8px;}
    table{border-collapse:collapse;width:100%;margin-bottom:12px;}
    th{background:#0f7a43;color:#fff;font-weight:bold;}
    th,td{border:1px solid #cbd5e1;padding:7px;vertical-align:top;}
    .label{background:#eef7f2;font-weight:bold;width:260px;}
    .money{text-align:right;}
    .muted{color:#64748b;}
  </style>
</head>
<body>
  <h1>INFORME MENSUAL DE ANÁLISIS</h1>
  <table>
    <tr><td class="label">Mes informado</td><td><?= hExcel(nombreMesInforme($mes)) ?></td></tr>
    <tr><td class="label">Fecha de generación</td><td><?= hExcel(date('d/m/Y H:i')) ?></td></tr>
    <tr><td class="label">Institución</td><td>Obra Social Pasteleros Mendoza</td></tr>
  </table>

  <h2>Resumen general</h2>
  <table>
    <tr><th>Indicador</th><th>Total</th></tr>
    <tr><td>Total de registros del mes</td><td><?= (int)$resumen['total'] ?></td></tr>
    <tr><td>Total realizados</td><td><?= (int)$resumen['realizados'] ?></td></tr>
    <tr><td>Total pendientes</td><td><?= (int)$resumen['pendientes'] ?></td></tr>
    <tr><td>Total pagados</td><td><?= (int)$resumen['pagados'] ?></td></tr>
    <tr><td>Total no pagados</td><td><?= (int)$resumen['noPagados'] ?></td></tr>
    <tr><td>Total recaudado</td><td class="money"><?= hExcel(formatoMontoArg($resumen['recaudado'])) ?></td></tr>
    <tr><td>Resultados cargados</td><td><?= (int)$resumen['resultados'] ?></td></tr>
    <tr><td>Emails enviados</td><td><?= (int)$resumen['emails'] ?></td></tr>
    <tr><td>Cantidad con monto $0</td><td><?= (int)$resumen['montoCero'] ?></td></tr>
    <tr><td>Gratuitos PMI</td><td><?= (int)$resumen['pmi'] ?></td></tr>
    <tr><td>Gratuitos ONCOLÓGICO</td><td><?= (int)$resumen['oncologico'] ?></td></tr>
    <tr><td>Gratuitos DISCAPACIDAD</td><td><?= (int)$resumen['discapacidad'] ?></td></tr>
    <tr><td>Gratuitos EXCEPCIÓN</td><td><?= (int)$resumen['excepcion'] ?></td></tr>
  </table>

  <h2>Recaudación diaria</h2>
  <table>
    <tr><th>Fecha</th><th>Cantidad de análisis</th><th>Cantidad pagados</th><th>Recaudado</th></tr>
    <?php if (empty($diario)): ?>
      <tr><td colspan="4" class="muted">No hay registros para el mes seleccionado.</td></tr>
    <?php else: ?>
      <?php foreach ($diario as $dia): ?>
        <tr>
          <td><?= hExcel($dia['fecha']) ?></td>
          <td><?= (int)$dia['cantidad'] ?></td>
          <td><?= (int)$dia['pagados'] ?></td>
          <td class="money"><?= hExcel(formatoMontoArg($dia['recaudado'])) ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </table>

  <h2>Detalle completo</h2>
  <table>
    <tr>
      <th>Fecha carga</th>
      <th>Nombre</th>
      <th>DNI</th>
      <th>Email</th>
      <th>Estado</th>
      <th>Pagado</th>
      <th>Monto</th>
      <th>Motivo</th>
      <th>Fecha realizado</th>
      <th>Resultado cargado</th>
      <th>Email enviado</th>
      <th>Fecha email</th>
      <th>Error email</th>
    </tr>
    <?php if (empty($delMes)): ?>
      <tr><td colspan="13" class="muted">No hay registros para el mes seleccionado.</td></tr>
    <?php else: ?>
      <?php foreach ($delMes as $r): ?>
        <tr>
          <td><?= hExcel($r['fechaCarga'] ?? '') ?></td>
          <td><?= hExcel($r['nombre'] ?? '') ?></td>
          <td><?= hExcel($r['dni'] ?? '') ?></td>
          <td><?= hExcel($r['email'] ?? '') ?></td>
          <td><?= hExcel($r['estado'] ?? '') ?></td>
          <td><?= esPagadoSi($r['pagado'] ?? '') ? 'Sí' : 'No' ?></td>
          <td class="money"><?= hExcel(formatoMontoArg((float)($r['monto'] ?? 0))) ?></td>
          <td><?= hExcel($r['motivo'] ?? '') ?></td>
          <td><?= hExcel($r['fechaRealizado'] ?? '') ?></td>
          <td><?= !empty($r['resultado']) ? 'Sí' : 'No' ?></td>
          <td><?= (($r['emailEnviado'] ?? false) === true) ? 'Sí' : 'No' ?></td>
          <td><?= hExcel($r['fechaEmail'] ?? '') ?></td>
          <td><?= hExcel($r['errorEmail'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </table>
</body>
</html>
  <?php
  exit;
}

/* =========================
   API INTERNA
   ========================= */

asegurarSistema();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['export'] ?? '') === 'informe_mensual') {
  exportarInformeMensual(leerRegistros(), $_GET['mes'] ?? date('Y-m'));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['api'])) {
  $api = $_GET['api'];
  $registros = leerRegistros();

  if ($api === 'listar') {
    responderJson(true, ['registros' => filtrarRegistros($registros)]);
  }

  if ($api === 'resumen') {
    responderJson(true, resumenRegistros($registros));
  }

  responderJson(false, ['error' => 'API no vÃ¡lida']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $accion = $_POST['accion'] ?? '';
    $registros = leerRegistros();

    if ($accion === 'crear') {
      $nombre = limpiarTexto($_POST['nombre'] ?? '');
      $dni = limpiarTexto($_POST['dni'] ?? '');
      $email = limpiarTexto($_POST['email'] ?? '');
      $pagado = $_POST['pagado'] ?? 'No';
      $estado = $_POST['estado'] ?? 'Pendiente';
      $monto = (float)($_POST['monto'] ?? 0);

      if ($nombre === '') throw new Exception('Debe cargar nombre y apellido.');
      if ($dni === '') throw new Exception('Debe cargar DNI.');
      if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Email invÃ¡lido.');
      if (esPagadoSi($pagado) && $monto < 0) throw new Exception('Monto invÃ¡lido.');
      if (!in_array($estado, ['Pendiente', 'Realizado'], true)) $estado = 'Pendiente';

      $adjunto = guardarAdjunto('archivo', $estado, $dni, $nombre);

      if ($estado === 'Realizado' && $adjunto === '') {
        throw new Exception('Para cargar como realizado debe adjuntar el pedido.');
      }

      $fechaISO = date('Y-m-d H:i:s');

      $registros[] = [
        'id' => uniqid('reg_', true),
        'fechaISO' => $fechaISO,
        'fechaCarga' => date('d/m/Y H:i'),
        'nombre' => $nombre,
        'dni' => $dni,
        'email' => $email,
        'estado' => $estado,
        'motivo' => $_POST['motivo'] ?? '',
        'pagado' => $pagado,
        'monto' => esPagadoSi($pagado) ? $monto : 0,
        'adjunto' => $adjunto,
        'resultado' => '',
        'fechaResultado' => '',
        'emailEnviado' => false,
        'fechaEmail' => '',
        'errorEmail' => '',
        'historial' => [[
          'fecha' => date('d/m/Y H:i'),
          'accion' => 'Registro creado'
        ]]
      ];

      guardarRegistros($registros);
      responderJson(true, ['mensaje' => 'Registro guardado correctamente.']);
    }

    if ($accion === 'realizar') {
      $id = $_POST['id'] ?? '';
      $monto = (float)($_POST['monto'] ?? 0);
      $encontrado = false;

      foreach ($registros as &$r) {
        if (($r['id'] ?? '') === $id) {
          $encontrado = true;

          if ($monto < 0 && (($r['monto'] ?? 0) < 0)) {
  throw new Exception('Debe ingresar un monto vÃ¡lido.');
}

          if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $r['adjunto'] = guardarAdjunto('archivo', 'Realizado', $r['dni'] ?? '', $r['nombre'] ?? '');
          } elseif (!empty($r['adjunto'])) {
            $r['adjunto'] = moverAdjuntoARealizados($r['adjunto'], $r['dni'] ?? '', $r['nombre'] ?? '');
          } else {
            throw new Exception('Debe adjuntar el pedido para marcar como realizado.');
          }

          $r['estado'] = 'Realizado';
          $r['pagado'] = 'SÃ­';
          $r['monto'] = $monto > 0 ? $monto : (float)($r['monto'] ?? 0);
          $r['fechaRealizado'] = date('d/m/Y H:i');
          $r['historial'][] = [
            'fecha' => date('d/m/Y H:i'),
            'accion' => 'Marcado como realizado'
          ];
          break;
        }
      }
      unset($r);

      if (!$encontrado) throw new Exception('Registro no encontrado.');

      guardarRegistros($registros);
      responderJson(true, ['mensaje' => 'Registro marcado como realizado.']);
    }

    if ($accion === 'actualizarPago') {
      $id = $_POST['id'] ?? '';
      $pagado = $_POST['pagado'] ?? 'No';
      $monto = (float)($_POST['monto'] ?? 0);
      $encontrado = false;

      foreach ($registros as &$r) {
        if (($r['id'] ?? '') === $id) {
          $encontrado = true;
          $r['pagado'] = esPagadoSi($pagado) ? 'SÃ­' : 'No';
          $r['monto'] = esPagadoSi($r['pagado']) ? $monto : 0;
          $r['historial'][] = [
            'fecha' => date('d/m/Y H:i'),
            'accion' => 'Pago actualizado'
          ];
          break;
        }
      }
      unset($r);

      if (!$encontrado) throw new Exception('Registro no encontrado.');

      guardarRegistros($registros);
      responderJson(true, ['mensaje' => 'Pago actualizado.']);
    }

    if ($accion === 'editarRegistro') {
      $id = $_POST['id'] ?? '';
      $nombre = limpiarTexto($_POST['nombre'] ?? '');
      $email = limpiarTexto($_POST['email'] ?? '');
      $pagado = $_POST['pagado'] ?? 'No';
      $monto = (float)($_POST['monto'] ?? 0);
      $motivo = limpiarTexto($_POST['motivo'] ?? '');
      $motivo = str_replace(['ONCOLÃ“GICO', 'EXCEPCIÃ“N'], ['ONCOLOGICO', 'EXCEPCION'], $motivo);
      $motivosPermitidos = ['PMI', 'ONCOLOGICO', 'DISCAPACIDAD', 'EXCEPCION'];
      $encontrado = false;

      if ($nombre === '') throw new Exception('Debe cargar nombre y apellido.');
      if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Email invÃ¡lido.');

      $pagadoFinal = esPagadoSi($pagado) ? 'SÃ­' : 'No';

      if ($pagadoFinal === 'No') {
        $monto = 0;
        $motivo = '';
      } else {
        if ($monto < 0) throw new Exception('Monto invÃ¡lido.');

        if ($monto == 0) {
          if ($motivo === '') throw new Exception('Debe seleccionar el motivo.');
          if (!in_array($motivo, $motivosPermitidos, true)) throw new Exception('Motivo invÃ¡lido.');
        } else {
          $motivo = '';
        }
      }

      foreach ($registros as &$r) {
        if (($r['id'] ?? '') === $id) {
          $encontrado = true;
          $r['nombre'] = $nombre;
          $r['email'] = $email;
          $r['pagado'] = $pagadoFinal;
          $r['monto'] = $monto;
          $r['motivo'] = $motivo;
          $r['historial'][] = [
            'fecha' => date('d/m/Y H:i'),
            'accion' => 'Registro editado'
          ];
          break;
        }
      }
      unset($r);

      if (!$encontrado) throw new Exception('Registro no encontrado.');

      guardarRegistros($registros);
      responderJson(true, ['mensaje' => 'Registro actualizado correctamente']);
    }

    if ($accion === 'subirResultado') {
      $id = $_POST['id'] ?? '';
      $encontrado = false;
      $estadoEmail = null;

      foreach ($registros as &$r) {
        if (($r['id'] ?? '') === $id) {
          $encontrado = true;

          $resultadoAnterior = $r['resultado'] ?? '';
          $resultadoNuevo = guardarResultado('resultado', $r['dni'] ?? '', $r['nombre'] ?? '');

          if ($resultadoAnterior !== '' && $resultadoAnterior !== $resultadoNuevo) {
            eliminarArchivoYCarpetasVacias($resultadoAnterior, RESULTADOS_DIR);
          }

          $r['resultado'] = $resultadoNuevo;
          $r['fechaResultado'] = date('d/m/Y H:i');
          $estadoEmail = actualizarEstadoEmailResultado($r);
          $r['historial'][] = [
            'fecha' => date('d/m/Y H:i'),
            'accion' => 'Resultado cargado'
          ];
          break;
        }
      }
      unset($r);

      if (!$encontrado) throw new Exception('Registro no encontrado.');

      guardarRegistros($registros);
      responderJson(true, array_merge([
        'mensaje' => 'Resultado cargado correctamente.'
      ], $estadoEmail ?? []));
    }

    if ($accion === 'reenviarEmail') {
      $id = $_POST['id'] ?? '';
      $encontrado = false;
      $estadoEmail = null;

      foreach ($registros as &$r) {
        if (($r['id'] ?? '') === $id) {
          $encontrado = true;

          if (empty($r['resultado'])) {
            throw new Exception('El registro no tiene resultado cargado.');
          }

          if (empty($r['email'])) {
            $r['emailEnviado'] = false;
            $r['fechaEmail'] = '';
            $r['errorEmail'] = 'Sin email cargado';
            $estadoEmail = [
              'enviado' => false,
              'email' => '',
              'error' => 'Sin email cargado',
              'mensajeEmail' => 'No se enviÃ³ email porque el paciente no tiene email cargado.'
            ];
          } else {
            $estadoEmail = actualizarEstadoEmailResultado($r);
          }

          $r['historial'][] = [
            'fecha' => date('d/m/Y H:i'),
            'accion' => 'ReenvÃ­o de email de resultado'
          ];
          break;
        }
      }
      unset($r);

      if (!$encontrado) throw new Exception('Registro no encontrado.');

      guardarRegistros($registros);
      responderJson(true, array_merge([
        'mensaje' => 'ReenvÃ­o procesado.'
      ], $estadoEmail ?? []));
    }

if ($accion === 'eliminar') {

  $id = $_POST['id'] ?? '';
  $nuevo = [];
  $encontrado = false;

  foreach ($registros as $r) {

    if (($r['id'] ?? '') === $id) {

      $encontrado = true;
      eliminarArchivoYCarpetasVacias($r['resultado'] ?? '', RESULTADOS_DIR);

      if (!empty($r['adjunto'])) {

        $rutaRelativa = str_replace(['../', '..\\'], '', $r['adjunto']);
        $archivo = __DIR__ . '/' . $rutaRelativa;

        if (file_exists($archivo) && is_file($archivo)) {
          @unlink($archivo);
        }

        // borrar carpetas vacÃ­as
        $dir = dirname($archivo);
        $limite = realpath(ADJUNTOS_DIR);

        while ($dir && is_dir($dir) && realpath($dir) !== $limite) {

          $restantes = array_diff(scandir($dir), ['.', '..']);

          if (count($restantes) === 0) {
            @rmdir($dir);
            $dir = dirname($dir);
          } else {
            break;
          }
        }
      }

      continue;
    }

    $nuevo[] = $r;
  }

  if (!$encontrado) {
    throw new Exception('Registro no encontrado.');
  }

  guardarRegistros($nuevo);

  responderJson(true, [
    'mensaje' => 'Registro, adjunto y resultado eliminados.'
  ]);
}

    responderJson(false, ['error' => 'AcciÃ³n no vÃ¡lida.']);

  } catch (Throwable $e) {
    responderJson(false, ['error' => $e->getMessage()]);
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="icon" type="image/png" href="favicon.png?v=3">

  <title>REGISTRO_ANALISIS</title>

  <style>
    :root{
      --verde:#0f7a43;
      --verde2:#149e5f;
      --fondo:#f4f7f6;
      --texto:#1f2933;
      --gris:#6b7280;
      --borde:#e5e7eb;
      --rojo:#b91c1c;
      --amarillo:#92400e;
    }

    *{
      margin:0;
      padding:0;
      box-sizing:border-box;
      font-family:Arial, Helvetica, sans-serif;
    }

    body{
      background:var(--fondo);
      color:var(--texto);
    }

    header{
      background:linear-gradient(135deg,var(--verde),var(--verde2));
      color:white;
      padding:22px 20px;
      box-shadow:0 2px 10px rgba(0,0,0,.15);
    }

    header h1{
      font-size:30px;
      margin-bottom:4px;
      letter-spacing:.5px;
    }

    header p{
      opacity:.95;
    }

    .container{
      width:95%;
      max-width:1450px;
      margin:25px auto;
    }

    .tabs{
      display:flex;
      gap:10px;
      margin-bottom:20px;
      flex-wrap:wrap;
    }

    .tab-btn,
    .small-btn{
      border:none;
      background:white;
      color:var(--verde);
      padding:12px 18px;
      border-radius:10px;
      cursor:pointer;
      font-weight:bold;
      transition:.2s;
      box-shadow:0 2px 8px rgba(0,0,0,.08);
    }

    .tab-btn:hover,
    .small-btn:hover{
      background:#eef7f2;
    }

    .tab-btn.active,
    .small-btn.active{
      background:var(--verde);
      color:white;
    }

    .tab{
      display:none;
    }

    .tab.active{
      display:block;
    }

    .card{
      background:white;
      border-radius:14px;
      padding:20px;
      box-shadow:0 2px 12px rgba(0,0,0,.08);
      margin-bottom:20px;
    }

    .card h2{
      color:var(--verde);
      margin-bottom:18px;
    }

    .grid{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(230px,1fr));
      gap:18px;
    }

    .campo{
      display:flex;
      flex-direction:column;
      gap:6px;
    }

    label{
      font-weight:bold;
      font-size:14px;
    }

    input,
    select,
    button{
      padding:12px;
      border-radius:10px;
      border:1px solid #d1d5db;
      font-size:14px;
    }

    button{
      background:var(--verde);
      color:white;
      border:none;
      cursor:pointer;
      font-weight:bold;
      transition:.2s;
    }

    button:hover{
      background:var(--verde2);
    }

    button.danger{
      background:var(--rojo);
    }

    button.light{
      background:#eef7f2;
      color:var(--verde);
      border:1px solid #cfe8db;
    }

    .acciones{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin-top:20px;
      align-items:center;
    }

    .tabla-wrap{
      overflow-x:auto;
      overflow-y:auto;
      max-height:70vh;
      border:1px solid var(--borde);
      border-radius:10px;
    }

    .scroll-top{
      overflow-x:auto;
      overflow-y:hidden;
      height:16px;
      margin-bottom:8px;
      border:1px solid var(--borde);
      border-radius:8px;
      background:#f9fafb;
    }

    .scroll-top-inner{
      height:1px;
    }

    table{
      width:100%;
      border-collapse:collapse;
      min-width:920px;
    }

    th{
      background:var(--verde);
      color:white;
      padding:12px;
      text-align:left;
      position:sticky;
      top:0;
      z-index:5;
    }

    td{
      padding:12px;
      border-bottom:1px solid var(--borde);
      background:white;
      vertical-align:middle;
    }

    tr:hover td{
      background:#f9fafb;
    }

    .estado-pill,
    .pago-pill{
      display:inline-flex;
      padding:6px 10px;
      border-radius:999px;
      font-size:12px;
      font-weight:bold;
      white-space:nowrap;
    }

    .pendiente{
      background:#fef3c7;
      color:#92400e;
    }

    .realizado{
      background:#d1fae5;
      color:#065f46;
    }

    .pagado{
      background:#dbeafe;
      color:#1e40af;
    }

    .nopagado{
      background:#fee2e2;
      color:#991b1b;
    }

    .cards-resumen{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
      gap:18px;
    }

    .mini-card{
      background:white;
      border-radius:14px;
      padding:20px;
      box-shadow:0 2px 12px rgba(0,0,0,.08);
    }

    .mini-card h3{
      color:var(--verde);
      margin-bottom:10px;
      font-size:15px;
    }

    .mini-card p{
      font-size:28px;
      font-weight:bold;
    }

    .informe-mensual{
      display:flex;
      gap:14px;
      align-items:flex-end;
      flex-wrap:wrap;
    }

    .informe-mensual .campo{
      min-width:240px;
      flex:1;
    }

    .informe-mensual button{
      min-width:260px;
    }

    .mensaje{
      margin-top:15px;
      font-weight:bold;
      color:var(--verde);
    }

    .error{
      color:var(--rojo);
    }

    .toolbar{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin-bottom:15px;
    }

    .muted{
      color:var(--gris);
      font-size:13px;
    }

    .link-btn{
      display:inline-block;
      text-decoration:none;
      background:#eef7f2;
      color:var(--verde);
      font-weight:bold;
      padding:8px 10px;
      border-radius:8px;
      margin:2px;
      border:1px solid #cfe8db;
      white-space:nowrap;
    }

    .icon-btn{
      width:36px;
      height:36px;
      padding:0;
      border-radius:9px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-size:18px;
      margin:2px;
    }

    .nombre-link{
      color:var(--verde);
      cursor:pointer;
      font-weight:bold;
      text-decoration:underline;
      background:none;
      border:none;
      padding:0;
      font-size:inherit;
      text-align:left;
    }

    .nombre-link:hover{
      color:var(--verde2);
      background:none;
    }

    .detalle-grid{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
      gap:14px;
    }

    .detalle-box{
      background:#f9fafb;
      border:1px solid var(--borde);
      border-radius:12px;
      padding:14px;
    }

    .detalle-box h4{
      color:var(--verde);
      margin-bottom:10px;
      font-size:14px;
      text-transform:uppercase;
    }

    .detalle-box p{
      margin:7px 0;
      line-height:1.35;
    }

    .detalle-actions{
      display:flex;
      flex-wrap:wrap;
      gap:4px;
      margin-top:8px;
      align-items:center;
    }

    .historial-list{
      margin:0;
      padding-left:18px;
    }

    .btn-mini,
    .archivos-cell .link-btn,
    .archivos-cell button,
    .acciones-cell button{
      padding:6px 8px;
      border-radius:7px;
      font-size:12px;
      line-height:1.1;
      margin:2px;
    }

    .archivos-cell,
    .acciones-cell{
      display:flex;
      flex-wrap:wrap;
      gap:4px;
      align-items:center;
      min-width:130px;
    }

    .monto-cell{
      white-space:nowrap;
      min-width:95px;
      text-align:right;
      font-weight:bold;
    }

    .motivo-pill{
      display:inline-flex;
      white-space:nowrap;
      padding:5px 8px;
      border-radius:999px;
      font-size:12px;
      font-weight:bold;
      background:#fef3c7;
      color:#92400e;
    }

    .modal{
      display:none;
      position:fixed;
      inset:0;
      background:rgba(0,0,0,.65);
      z-index:9999;
      padding:22px;
    }

    .modal.active{
      display:flex;
      align-items:center;
      justify-content:center;
    }

    .modal-content{
      width:min(1100px,96vw);
      height:min(820px,92vh);
      background:white;
      border-radius:16px;
      overflow:hidden;
      display:flex;
      flex-direction:column;
      box-shadow:0 15px 45px rgba(0,0,0,.35);
    }

    .modal-head{
      padding:12px 16px;
      background:var(--verde);
      color:white;
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:12px;
    }

    .modal-body{
      flex:1;
      background:#111827;
      display:flex;
      align-items:center;
      justify-content:center;
      overflow:auto;
    }

    .modal-body iframe{
      width:100%;
      height:100%;
      border:none;
      background:white;
    }

    .modal-body img{
      max-width:100%;
      max-height:100%;
      object-fit:contain;
      background:white;
    }

    .edit-content{
      height:auto;
      max-height:92vh;
    }

    .edit-body{
      background:white;
      display:block;
      padding:18px;
    }

    .detalle-content{
      height:auto;
      max-height:92vh;
    }

    .detalle-body{
      background:white;
      display:block;
      align-items:stretch;
      justify-content:flex-start;
      padding:18px;
    }

    .x{
      background:white;
      color:var(--verde);
      padding:8px 12px;
      border-radius:8px;
    }

    @media(max-width:700px){
      header h1{font-size:24px;}
      .container{width:96%; margin:15px auto;}
      .card{padding:15px;}
      .tabs{gap:8px;}
      .tab-btn{width:100%;}
      .mini-card p{font-size:23px;}
    }
  </style>
</head>

<body>

<header>
  <h1>REGISTRO_ANALISIS</h1>
  <p>Gestión de análisis, pendientes, adjuntos y resumen</p>
</header>

<div class="container">

  <div class="tabs">
    <button class="tab-btn active" onclick="abrirTab('buscar', this)">Tabla / Pendientes</button>
    <button class="tab-btn" onclick="abrirTab('carga', this)">Cargar análisis</button>
    <button class="tab-btn" onclick="abrirTab('resumen', this)">Resumen</button>
  </div>

  <div id="buscar" class="tab active">
    <div class="card">
      <h2>Tabla general</h2>

      <div class="toolbar">
        <button class="small-btn active" data-estado="Pendiente" onclick="setEstadoRapido('Pendiente', this)">Pendientes</button>
        <button class="small-btn" data-estado="Realizado" onclick="setEstadoRapido('Realizado', this)">Realizados</button>
        <button class="small-btn" data-estado="Todos" onclick="setEstadoRapido('Todos', this)">Todos</button>
      </div>

      <div class="grid">
        <div class="campo">
          <label>Buscar nombre, DNI o fecha</label>
          <input type="text" id="buscarTexto" oninput="cargarRegistros()" placeholder="Ej: Pérez, 33444843, 27/05">
        </div>

        <div class="campo">
          <label>Estado</label>
          <select id="buscarEstado" onchange="sincronizarBotonesEstado(); cargarRegistros();">
            <option>Pendiente</option>
            <option>Realizado</option>
            <option>Todos</option>
          </select>
        </div>

        <div class="campo">
          <label>Pago</label>
          <select id="buscarPago" onchange="cargarRegistros()">
            <option>Todos</option>
            <option>Pagado</option>
            <option>No pagado</option>
          </select>
        </div>
      </div>

      <p class="muted" style="margin-top:12px;">
        La tabla se carga automáticamente. No hace falta buscar por DNI para ver registros.
      </p>
    </div>

    <div class="scroll-top" id="scrollTop">
      <div class="scroll-top-inner" id="scrollTopInner"></div>
    </div>

    <div class="card tabla-wrap" id="tablaWrap">
      <table>
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Nombre</th>
            <th>DNI</th>
            <th>Pago</th>
            <th>Pedido</th>
            <th>Resultado</th>
            <th>Acción</th>
          </tr>
        </thead>

        <tbody id="tablaRegistros">
          <tr><td colspan="7">Cargando...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div id="carga" class="tab">
    <div class="card">
      <h2>Nueva carga</h2>

      <form id="formCarga" enctype="multipart/form-data">
        <input type="hidden" name="accion" value="crear">

        <div class="grid">
          <div class="campo">
            <label>Nombre y apellido</label>
            <input type="text" name="nombre" id="nombre" autocomplete="off">
          </div>

          <div class="campo">
            <label>DNI</label>
            <input type="text" name="dni" id="dni" autocomplete="off" onblur="buscarAfiliadoPorDni()" oninput="limpiarEstadoAfiliado()">
<small id="estadoAfiliado" class="muted"></small>
          </div>

          <div class="campo">
            <label>Email del paciente</label>
            <input type="email" name="email" id="email" autocomplete="off">
          </div>

          <div class="campo">
            <label>Pagado</label>
            <select name="pagado" id="pagado" onchange="toggleMonto()">
              <option>No</option>
              <option value="SÃ­">Sí</option>
            </select>
          </div>

          <div class="campo">
  <label>Monto</label>
  <input type="number" name="monto" id="monto" value="0" disabled>
</div>

<div class="campo" id="campoMotivo" style="display:none;">
  <label>Motivo</label>
  <select name="motivo" id="motivo">
    <option value="">Seleccionar</option>
    <option value="PMI">PMI</option>
    <option value="ONCOLOGICO">ONCOLÓGICO</option>
    <option value="DISCAPACIDAD">DISCAPACIDAD</option>
    <option value="EXCEPCION">EXCEPCIÓN</option>
  </select>
</div>

          <div class="campo">
            <label>Estado</label>
            <select name="estado" id="estado">
              <option>Pendiente</option>
              <option>Realizado</option>
            </select>
          </div>

          <div class="campo">
            <label>Adjuntar pedido</label>
            <input type="file" name="archivo" id="archivo" accept=".jpg,.jpeg,.png,.pdf">
          </div>
        </div>

        <div class="acciones">
          <button type="submit">Guardar registro</button>
          <span class="muted">Si lo cargás como realizado, el pedido es obligatorio.</span>
        </div>

        <div class="mensaje" id="mensaje"></div>
      </form>
    </div>
  </div>

  <div id="resumen" class="tab">
    <div class="cards-resumen">
      <div class="mini-card">
        <h3>Total realizados</h3>
        <p id="rRealizados">0</p>
      </div>

      <div class="mini-card">
        <h3>Total pendientes</h3>
        <p id="rPendientes">0</p>
      </div>

      <div class="mini-card">
        <h3>Total recaudado</h3>
        <p id="rMonto">$ 0</p>
      </div>

      <div class="mini-card">
        <h3>Mes actual</h3>
        <p id="rMes">0</p>
      </div>

      <div class="mini-card">
        <h3>Resultados cargados</h3>
        <p id="rResultados">0</p>
      </div>

      <div class="mini-card">
        <h3>Emails enviados</h3>
        <p id="rEmails">0</p>
      </div>
    </div>

    <div class="card" style="margin-top:20px;">
      <h2>Informe mensual</h2>
      <div class="informe-mensual">
        <div class="campo">
          <label for="mesInforme">Mes</label>
          <select id="mesInforme">
            <option value="">Cargando meses...</option>
          </select>
        </div>
        <button type="button" onclick="descargarInformeMensual()">Descargar informe mensual Excel</button>
      </div>
      <div class="muted" id="mensajeInformeMensual" style="margin-top:10px;"></div>
    </div>

    <div class="card" style="margin-top:20px;">
      <h2>Detalle por mes</h2>
      <div id="detalleMeses"></div>
    </div>
  </div>

</div>

<div class="modal" id="visorModal">
  <div class="modal-content">
    <div class="modal-head">
      <strong id="visorTitulo">Pedido</strong>
      <div>
        <a id="visorDescargar" class="link-btn" target="_blank" download>Descargar</a>
        <button class="x" onclick="cerrarVisor()">Cerrar</button>
      </div>
    </div>
    <div class="modal-body" id="visorBody"></div>
  </div>
</div>

<div class="modal" id="editarModal">
  <div class="modal-content edit-content">
    <div class="modal-head">
      <strong>Editar registro</strong>
      <button class="x" onclick="cerrarEditar()">Cerrar</button>
    </div>
    <div class="modal-body edit-body">
      <form id="formEditar">
        <input type="hidden" name="accion" value="editarRegistro">
        <input type="hidden" name="id" id="editarId">

        <div class="grid">
          <div class="campo">
            <label>Nombre</label>
            <input type="text" name="nombre" id="editarNombre" autocomplete="off">
          </div>

          <div class="campo">
            <label>Email</label>
            <input type="email" name="email" id="editarEmail" autocomplete="off">
          </div>

          <div class="campo">
            <label>Pagado</label>
            <select name="pagado" id="editarPagado" onchange="toggleEditarMonto()">
              <option>No</option>
              <option value="SÃ­">Sí</option>
            </select>
          </div>

          <div class="campo">
            <label>Monto</label>
            <input type="number" name="monto" id="editarMonto" min="0" step="0.01">
          </div>

          <div class="campo" id="editarCampoMotivo">
            <label>Motivo</label>
            <select name="motivo" id="editarMotivo">
              <option value="">Seleccionar</option>
              <option value="PMI">PMI</option>
              <option value="ONCOLOGICO">ONCOLÓGICO</option>
              <option value="DISCAPACIDAD">DISCAPACIDAD</option>
              <option value="EXCEPCION">EXCEPCIÓN</option>
            </select>
          </div>
        </div>

        <div class="acciones">
          <button type="submit">Guardar cambios</button>
          <button type="button" class="light" onclick="cerrarEditar()">Cancelar</button>
        </div>

        <div class="mensaje" id="editarMensaje"></div>
      </form>
    </div>
  </div>
</div>

<div class="modal" id="detalleModal">
  <div class="modal-content detalle-content">
    <div class="modal-head">
      <strong>Detalle del registro</strong>
      <button class="x" onclick="cerrarDetalle()">Cerrar</button>
    </div>
    <div class="modal-body detalle-body" id="detalleBody"></div>
  </div>
</div>

<script>
let filtroEstadoActual = 'Pendiente';
let registrosCache = {};

const CARNET_URL = "https://script.google.com/macros/s/AKfycby86fvv4wOHh3eth7mLTmvSwXiD6INr7syd0tJ7DPQ0gPeH7SvoCfdk6wb5pCRSDh81/exec";

function abrirTab(id, btn){
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));

  document.getElementById(id).classList.add('active');
  btn.classList.add('active');

  if(id === 'buscar') cargarRegistros();
  if(id === 'resumen') cargarResumen();
}

function limpiarEstadoAfiliado(){
  const estado = document.getElementById('estadoAfiliado');
  if(estado) estado.innerText = '';
}

function jsonpCarnet(url){
  return new Promise((resolve, reject) => {
    const callback = 'callbackCarnet_' + Date.now();

    window[callback] = function(data){
      delete window[callback];
      script.remove();
      resolve(data);
    };

    const script = document.createElement('script');
    script.src = url + '&callback=' + callback;
    script.onerror = () => {
      delete window[callback];
      script.remove();
      reject();
    };

    document.body.appendChild(script);
  });
}

async function buscarAfiliadoPorDni(){
  const dniInput = document.getElementById('dni');
  const nombreInput = document.getElementById('nombre');
  const estado = document.getElementById('estadoAfiliado');

  const dni = dniInput.value.trim();

  if(!dni){
    if(estado) estado.innerText = '';
    return;
  }

  if(estado) estado.innerText = 'Buscando afiliado...';

  try{
    const data = await jsonpCarnet(
  CARNET_URL +
  '?accion=buscarPorDni' +
  '&dni=' + encodeURIComponent(dni)
);

    const encontrado =
      data.ok === true ||
      data.valido === true ||
      data.encontrado === true ||
      data.estado === 'ACTIVO' ||
      data.estado === 'OK';

    const nombreDetectado =
      data.nombre ||
      data.nombreCompleto ||
      data.afiliado ||
      data.apellidoNombre ||
      data.titular ||
      '';

    if(encontrado && nombreDetectado){
      nombreInput.value = nombreDetectado;
      if(estado) estado.innerText = 'Afiliado encontrado';
    }else{
      if(estado) estado.innerText = 'DNI no encontrado. Cargar nombre manualmente.';
    }

  }catch(e){
    if(estado) estado.innerText = 'No se pudo consultar la base. Cargar nombre manualmente.';
  }
}


function toggleMonto(){
  const pagado = document.getElementById('pagado').value;
  const monto = document.getElementById('monto');
  const campoMotivo = document.getElementById('campoMotivo');

  if(esPagadoSiJs(pagado)){
    monto.disabled = false;

    if(Number(monto.value) === 0){
      campoMotivo.style.display = 'flex';
    }

    monto.addEventListener('input', function(){
      if(Number(this.value) === 0){
        campoMotivo.style.display = 'flex';
      }else{
        campoMotivo.style.display = 'none';
        document.getElementById('motivo').value = '';
      }
    });

  }else{
    monto.disabled = true;
    monto.value = 0;
    campoMotivo.style.display = 'none';
    document.getElementById('motivo').value = '';
  }
}

function setEstadoRapido(estado, btn){
  filtroEstadoActual = estado;
  document.getElementById('buscarEstado').value = estado;

  document.querySelectorAll('.small-btn[data-estado]').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  cargarRegistros();
}

function sincronizarBotonesEstado(){
  const estado = document.getElementById('buscarEstado').value;
  document.querySelectorAll('.small-btn[data-estado]').forEach(b => {
    b.classList.toggle('active', b.dataset.estado === estado);
  });
}

function money(n){
  return '$ ' + Number(n || 0).toLocaleString('es-AR');
}

function nombreMesVisible(mes){
  const partes = String(mes || '').split('-');
  if(partes.length !== 2) return mes || '';

  const fecha = new Date(Number(partes[0]), Number(partes[1]) - 1, 1);
  const nombre = fecha.toLocaleDateString('es-AR', {month:'long', year:'numeric'});
  return nombre.charAt(0).toUpperCase() + nombre.slice(1);
}

function ajustarScrollTabla(){
  const tablaWrap = document.getElementById('tablaWrap');
  const scrollTop = document.getElementById('scrollTop');
  const scrollTopInner = document.getElementById('scrollTopInner');
  const tabla = tablaWrap?.querySelector('table');

  if(!tablaWrap || !scrollTop || !scrollTopInner || !tabla) return;

  scrollTopInner.style.width = tabla.scrollWidth + 'px';
  scrollTop.scrollLeft = tablaWrap.scrollLeft;
}

function iniciarScrollTabla(){
  const tablaWrap = document.getElementById('tablaWrap');
  const scrollTop = document.getElementById('scrollTop');

  if(!tablaWrap || !scrollTop || tablaWrap.dataset.scrollSync === 'ok') return;

  tablaWrap.dataset.scrollSync = 'ok';

  scrollTop.addEventListener('scroll', () => {
    tablaWrap.scrollLeft = scrollTop.scrollLeft;
  });

  tablaWrap.addEventListener('scroll', () => {
    scrollTop.scrollLeft = tablaWrap.scrollLeft;
  });

  window.addEventListener('resize', ajustarScrollTabla);
}

function esPagadoSiJs(valor){
  return ['Sí', 'SÃ­', 'S\u00c3\u00ad'].includes(String(valor || ''));
}

function normalizarMotivo(valor){
  return String(valor || '')
    .replace('ONCOLÓGICO', 'ONCOLOGICO')
    .replace('ONCOLÃ“GICO', 'ONCOLOGICO')
    .replace('EXCEPCIÓN', 'EXCEPCION')
    .replace('EXCEPCIÃ“N', 'EXCEPCION');
}

function textoVisible(txt){
  return String(txt || '')
    .replace(/ContraseÃ±a/g, 'Contraseña')
    .replace(/GestiÃ³n/g, 'Gestión')
    .replace(/anÃ¡lisis/g, 'análisis')
    .replace(/automÃ¡ticamente/g, 'automáticamente')
    .replace(/PÃ©rez/g, 'Pérez')
    .replace(/AcciÃ³n/g, 'Acción')
    .replace(/invÃ¡lido/g, 'inválido')
    .replace(/vÃ¡lido/g, 'válido')
    .replace(/enviÃ³/g, 'envió')
    .replace(/ReenvÃ­o/g, 'Reenvío')
    .replace(/vacÃ­as/g, 'vacías')
    .replace(/todavÃ­a/g, 'todavía')
    .replace(/SÃ­/g, 'Sí')
    .replace(/ONCOLÃ“GICO/g, 'ONCOLÓGICO')
    .replace(/EXCEPCIÃ“N/g, 'EXCEPCIÓN');
}

function htmlAttr(txt){
  return String(txt || '')
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

function htmlText(txt){
  return htmlAttr(textoVisible(txt)).replace(/'/g, '&#039;');
}

function mensajeResultadoEmail(data){
  if(data.enviado === true){
    return 'Resultado cargado correctamente.\nEmail enviado a ' + data.email + '.';
  }

  if(data.error === 'Sin email cargado'){
    return 'Resultado cargado correctamente.\nNo se envió email porque el paciente no tiene email cargado.';
  }

  return 'Resultado cargado correctamente.\nNo se pudo enviar el email.\nMotivo: ' + (textoVisible(data.error) || 'Error desconocido');
}

async function apiGet(params){
  const res = await fetch('index.php?' + params + '&_=' + Date.now(), { cache:'no-store' });
  return await res.json();
}

async function apiPost(formData){
  const res = await fetch('index.php', {
    method:'POST',
    body:formData,
    cache:'no-store'
  });

  return await res.json();
}

document.getElementById('formCarga').addEventListener('submit', async function(e){
  e.preventDefault();

  const nombre = document.getElementById('nombre').value.trim();
  const dni = document.getElementById('dni').value.trim();
  const email = document.getElementById('email').value.trim();
  const pagado = document.getElementById('pagado').value;
  const monto = document.getElementById('monto').value || 0;
  const estado = document.getElementById('estado').value;
  const archivo = document.getElementById('archivo').files[0];
  const mensaje = document.getElementById('mensaje');

  if(!nombre){
    alert('Debe cargar nombre');
    return;
  }

  if(!dni){
    alert('Debe cargar DNI');
    return;
  }

  if(email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){
    alert('Email inválido');
    return;
  }

  if(esPagadoSiJs(pagado) && Number(monto) < 0){
  alert('Monto inválido');
  return;
}

  if(esPagadoSiJs(pagado) && Number(monto) === 0 && !document.getElementById('motivo').value){
  alert('Debe seleccionar el motivo');
  return;
}

  if(estado === 'Realizado' && !archivo){
    alert('Debe adjuntar el pedido');
    return;
  }

  const fd = new FormData(this);

  if(pagado === 'No'){
    fd.set('monto', '0');
  }

  mensaje.className = 'mensaje';
  mensaje.innerHTML = 'Guardando registro...';

  const data = await apiPost(fd);

  if(data.ok){
    mensaje.innerHTML = 'Registro guardado correctamente';

    this.reset();
    document.getElementById('pagado').value = 'No';
    document.getElementById('monto').value = 0;
    document.getElementById('monto').disabled = true;
    document.getElementById('estado').value = 'Pendiente';

    document.getElementById('buscarEstado').value = 'Pendiente';
    sincronizarBotonesEstado();

    await cargarRegistros();
    await cargarResumen();
    abrirTab('buscar', document.querySelector('.tab-btn'));
  }else{
    mensaje.className = 'mensaje error';
    mensaje.innerHTML = textoVisible(data.error) || 'Error al guardar';
  }
});

async function cargarRegistros(){
  const buscar = document.getElementById('buscarTexto')?.value || '';
  const estado = document.getElementById('buscarEstado')?.value || 'Pendiente';
  const pago = document.getElementById('buscarPago')?.value || 'Todos';

  const tbody = document.getElementById('tablaRegistros');
  tbody.innerHTML = '<tr><td colspan="7">Cargando...</td></tr>';

  const data = await apiGet(
    'api=listar' +
    '&buscar=' + encodeURIComponent(buscar) +
    '&estado=' + encodeURIComponent(estado) +
    '&pago=' + encodeURIComponent(pago)
  );

  tbody.innerHTML = '';

  if(!data.ok){
    tbody.innerHTML = `<tr><td colspan="7">${htmlText(data.error || 'Error al cargar registros')}</td></tr>`;
    return;
  }

  if(!data.registros.length){
    tbody.innerHTML = `<tr><td colspan="7">No hay registros para este filtro.</td></tr>`;
    return;
  }

  registrosCache = {};

  data.registros.forEach(r=>{
    registrosCache[r.id] = r;
    const adj = r.adjunto || '';
    const ext = adj.split('.').pop().toLowerCase();
    const resultado = r.resultado || '';
    const extResultado = resultado.split('.').pop().toLowerCase();

    const pedidoHtml = adj
      ? `
        <button class="light icon-btn" title="Ver archivo" onclick="verArchivo('${adj.replace(/'/g, "\\'")}', '${ext}', 'Pedido')">&#128065;</button>
        <a class="link-btn icon-btn" title="Descargar archivo" href="${adj}" download>&#11015;</a>
      `
      : '';


    const resultadoHtml = resultado
      ? `
        <button class="light icon-btn" title="Ver archivo" onclick="verArchivo('${resultado.replace(/'/g, "\\'")}', '${extResultado}', 'Resultado')">&#128065;</button>
        <a class="link-btn icon-btn" title="Descargar archivo" href="${resultado}" download>&#11015;</a>
        <button class="btn-mini" onclick="subirResultado('${r.id}')">Reemplazar</button>
      `
      : `<button class="btn-mini" onclick="subirResultado('${r.id}')">Cargar resultado</button>`;

    const tr = document.createElement('tr');

    tr.innerHTML = `
      <td>${htmlText(r.fechaCarga || '-')}</td>
      <td><button type="button" class="nombre-link" onclick="abrirDetalle('${r.id}')">${htmlText(r.nombre || '-')}</button></td>
      <td>${htmlText(r.dni || '-')}</td>

      <td>
        <span class="pago-pill ${esPagadoSiJs(r.pagado) ? 'pagado' : 'nopagado'}">
          ${esPagadoSiJs(r.pagado) ? 'Pagado' : 'No pagado'}
        </span>
      </td>

      <td><div class="archivos-cell">${pedidoHtml}</div></td>

      <td><div class="archivos-cell">${resultadoHtml}</div></td>

      <td><div class="acciones-cell">
  ${
    r.estado === 'Pendiente'
      ? `
        <button class="btn-mini" onclick="marcarRealizado('${r.id}', '${r.pagado}', ${Number(r.monto || 0)}, ${adj ? 'true' : 'false'}, '${(r.motivo || '').replace(/'/g, "\\'")}')">
          Realizar
        </button>

        <button class="light icon-btn" title="Editar registro" onclick="abrirEditar('${r.id}')">
          &#9999;
        </button>

        <button class="danger icon-btn" title="Eliminar registro" onclick="eliminarRegistro('${r.id}')">
          &#128465;
        </button>
      `
      : `

        <button class="light icon-btn" title="Editar registro" onclick="abrirEditar('${r.id}')">
          &#9999;
        </button>

        <button class="danger icon-btn" title="Eliminar registro" onclick="eliminarRegistro('${r.id}')">
          &#128465;
        </button>
      `
  }
</div></td>
    `;

    tbody.appendChild(tr);
  });

  iniciarScrollTabla();
  ajustarScrollTabla();
}

function archivoBotones(url, ext, titulo){
  if(!url) return '';

  const safeUrl = String(url).replace(/'/g, "\\'");
  const safeTitulo = String(titulo || 'Archivo').replace(/'/g, "\\'");

  return `
    <button class="light icon-btn" title="Ver archivo" onclick="verArchivo('${safeUrl}', '${ext}', '${safeTitulo}')">&#128065;</button>
    <a class="link-btn icon-btn" title="Descargar archivo" href="${htmlAttr(url)}" download>&#11015;</a>
  `;
}

function estadoEmailDetalle(r){
  if(!r.email) return 'Sin email';
  if(r.emailEnviado === true) return 'Enviado';
  return 'Error';
}

function renderHistorial(historial){
  if(!Array.isArray(historial) || !historial.length){
    return '<p>Sin historial</p>';
  }

  return `
    <ul class="historial-list">
      ${historial.map(h => `
        <li>
          <strong>${htmlText(h.fecha || '-')}</strong>
          ${htmlText(h.accion || '')}
        </li>
      `).join('')}
    </ul>
  `;
}

function abrirDetalle(id){
  const r = registrosCache[id];
  if(!r){
    alert('Registro no encontrado');
    return;
  }

  const adj = r.adjunto || '';
  const resultado = r.resultado || '';
  const extPedido = adj.split('.').pop().toLowerCase();
  const extResultado = resultado.split('.').pop().toLowerCase();
  const pagado = esPagadoSiJs(r.pagado);
  const montoDetalle = Number(r.monto) === 0 && r.motivo
    ? `${money(r.monto)} - ${htmlText(r.motivo)}`
    : money(r.monto);

  document.getElementById('detalleBody').innerHTML = `
    <div class="detalle-grid">
      <div class="detalle-box">
        <h4>Datos del paciente</h4>
        <p><strong>Nombre:</strong> ${htmlText(r.nombre || '-')}</p>
        <p><strong>DNI:</strong> ${htmlText(r.dni || '-')}</p>
        <p><strong>Email:</strong> ${htmlText(r.email || '-')}</p>
      </div>

      <div class="detalle-box">
        <h4>Estado</h4>
        <p><strong>Estado:</strong> ${htmlText(r.estado || '-')}</p>
        <p><strong>Fecha carga:</strong> ${htmlText(r.fechaCarga || '-')}</p>
        <p><strong>Fecha realizado:</strong> ${htmlText(r.fechaRealizado || '-')}</p>
      </div>

      <div class="detalle-box">
        <h4>Pago</h4>
        <p><strong>Pagado:</strong> ${pagado ? 'Pagado' : 'No pagado'}</p>
        <p><strong>Monto:</strong> ${montoDetalle}</p>
        <p><strong>Motivo:</strong> ${htmlText(r.motivo || '-')}</p>
      </div>

      <div class="detalle-box">
        <h4>Pedido</h4>
        <p><strong>Estado:</strong> ${adj ? 'Tiene pedido' : 'Sin pedido'}</p>
        <div class="detalle-actions">${archivoBotones(adj, extPedido, 'Pedido')}</div>
      </div>

      <div class="detalle-box">
        <h4>Resultado</h4>
        <p><strong>Estado:</strong> ${resultado ? 'Disponible' : 'Pendiente'}</p>
        <p><strong>Fecha resultado:</strong> ${htmlText(r.fechaResultado || '-')}</p>
        <div class="detalle-actions">
          ${archivoBotones(resultado, extResultado, 'Resultado')}
          <button class="btn-mini" onclick="subirResultado('${r.id}')">${resultado ? 'Reemplazar resultado' : 'Cargar resultado'}</button>
        </div>
      </div>

      <div class="detalle-box">
        <h4>Email</h4>
        <p><strong>Estado email:</strong> ${estadoEmailDetalle(r)}</p>
        <p><strong>Fecha email:</strong> ${htmlText(r.fechaEmail || '-')}</p>
        <p><strong>Error email:</strong> ${htmlText(r.errorEmail || '-')}</p>
        <div class="detalle-actions">
          ${r.email && resultado ? `<button class="btn-mini" onclick="reenviarEmail('${r.id}')">Reenviar email</button>` : ''}
        </div>
      </div>

      <div class="detalle-box">
        <h4>Historial</h4>
        ${renderHistorial(r.historial)}
      </div>
    </div>
  `;

  document.getElementById('detalleModal').classList.add('active');
}

function cerrarDetalle(){
  document.getElementById('detalleModal').classList.remove('active');
  document.getElementById('detalleBody').innerHTML = '';
}

document.getElementById('detalleModal').addEventListener('click', function(e){
  if(e.target === this) cerrarDetalle();
});

async function marcarRealizado(id, pagado, montoActual, tieneAdjunto, motivo){
  let monto = montoActual;

  if(!esPagadoSiJs(pagado) || (Number(montoActual) === 0 && !motivo)){
    monto = prompt('Ingrese el monto pagado');

    if(monto === null || monto === '' || Number(monto) < 0){
  alert('Monto inválido');
  return;
}
  }

  const fd = new FormData();
  fd.append('accion', 'realizar');
  fd.append('id', id);
  fd.append('monto', monto);

  if(!tieneAdjunto){
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.jpg,.jpeg,.png,.pdf';

    input.onchange = async () => {
      const archivo = input.files[0];

      if(!archivo){
        alert('Debe adjuntar el pedido');
        return;
      }

      fd.append('archivo', archivo);

      const data = await apiPost(fd);

      if(data.ok){
        await cargarRegistros();
        await cargarResumen();
        alert('Registro marcado como realizado');
      }else{
        alert(textoVisible(data.error) || 'Error');
      }
    };

    input.click();
    return;
  }

  const data = await apiPost(fd);

  if(data.ok){
    await cargarRegistros();
    await cargarResumen();
    alert('Registro marcado como realizado');
  }else{
    alert(textoVisible(data.error) || 'Error');
  }
}

function toggleEditarMonto(){
  const pagado = document.getElementById('editarPagado').value;
  const monto = document.getElementById('editarMonto');
  const campoMotivo = document.getElementById('editarCampoMotivo');
  const motivo = document.getElementById('editarMotivo');

  if(esPagadoSiJs(pagado)){
    monto.disabled = false;
    campoMotivo.style.display = Number(monto.value || 0) === 0 ? 'flex' : 'none';
  }else{
    monto.value = 0;
    monto.disabled = true;
    motivo.value = '';
    campoMotivo.style.display = 'none';
  }
}

document.getElementById('editarMonto').addEventListener('input', function(){
  const campoMotivo = document.getElementById('editarCampoMotivo');
  const motivo = document.getElementById('editarMotivo');

  if(Number(this.value || 0) === 0){
    campoMotivo.style.display = 'flex';
  }else{
    motivo.value = '';
    campoMotivo.style.display = 'none';
  }
});

function abrirEditar(id){
  const r = registrosCache[id];
  if(!r){
    alert('Registro no encontrado');
    return;
  }

  document.getElementById('editarId').value = r.id || '';
  document.getElementById('editarNombre').value = r.nombre || '';
  document.getElementById('editarEmail').value = r.email || '';
  document.getElementById('editarPagado').value = esPagadoSiJs(r.pagado || 'No') ? 'SÃ­' : 'No';
  document.getElementById('editarMonto').value = Number(r.monto || 0);
  document.getElementById('editarMotivo').value = normalizarMotivo(r.motivo);
  document.getElementById('editarMensaje').innerHTML = '';

  toggleEditarMonto();
  document.getElementById('editarModal').classList.add('active');
}

function cerrarEditar(){
  document.getElementById('editarModal').classList.remove('active');
}

document.getElementById('editarModal').addEventListener('click', function(e){
  if(e.target === this) cerrarEditar();
});

document.getElementById('formEditar').addEventListener('submit', async function(e){
  e.preventDefault();

  const nombre = document.getElementById('editarNombre').value.trim();
  const email = document.getElementById('editarEmail').value.trim();
  const pagado = document.getElementById('editarPagado').value;
  const monto = document.getElementById('editarMonto').value || 0;
  const motivo = document.getElementById('editarMotivo').value;
  const mensaje = document.getElementById('editarMensaje');

  if(!nombre){
    alert('Debe cargar nombre');
    return;
  }

  if(email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){
    alert('Email inválido');
    return;
  }

  if(esPagadoSiJs(pagado) && Number(monto) < 0){
    alert('Monto inválido');
    return;
  }

  if(esPagadoSiJs(pagado) && Number(monto) === 0 && !motivo){
    alert('Debe seleccionar el motivo');
    return;
  }

  const fd = new FormData(this);

  if(!esPagadoSiJs(pagado)){
    fd.set('monto', '0');
    fd.set('motivo', '');
  }

  if(esPagadoSiJs(pagado) && Number(monto) > 0){
    fd.set('motivo', '');
  }

  mensaje.className = 'mensaje';
  mensaje.innerHTML = 'Guardando cambios...';

  const data = await apiPost(fd);

  if(data.ok){
    cerrarEditar();
    await cargarRegistros();
    await cargarResumen();
    alert('Registro actualizado correctamente');
  }else{
    mensaje.className = 'mensaje error';
    mensaje.innerHTML = textoVisible(data.error) || 'Error al guardar';
  }
});

async function subirResultado(id){
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = '.jpg,.jpeg,.png,.pdf';

  input.onchange = async () => {
    const archivo = input.files[0];

    if(!archivo){
      alert('Debe seleccionar un resultado');
      return;
    }

    const fd = new FormData();
    fd.append('accion', 'subirResultado');
    fd.append('id', id);
    fd.append('resultado', archivo);

    const data = await apiPost(fd);

    if(data.ok){
      await cargarRegistros();
      alert(mensajeResultadoEmail(data));
    }else{
      alert(textoVisible(data.error) || 'Error');
    }
  };

  input.click();
}

async function reenviarEmail(id){
  const fd = new FormData();
  fd.append('accion', 'reenviarEmail');
  fd.append('id', id);

  const data = await apiPost(fd);

  await cargarRegistros();

  if(data.ok){
    if(data.enviado === true){
      alert('Email enviado a ' + data.email + '.');
    }else if(data.error === 'Sin email cargado'){
      alert('No se envió email porque el paciente no tiene email cargado.');
    }else{
      alert('No se pudo enviar el email.\nMotivo: ' + (textoVisible(data.error) || 'Error desconocido'));
    }
  }else{
    alert(textoVisible(data.error) || 'Error');
  }
}

async function eliminarRegistro(id){

  if(!confirm('¿Eliminar registro, adjunto y resultado?')){
    return;
  }

  const fd = new FormData();
  fd.append('accion', 'eliminar');
  fd.append('id', id);

  const data = await apiPost(fd);

  if(data.ok){
    await cargarRegistros();
    await cargarResumen();
    alert('Registro eliminado');
  }else{
    alert(textoVisible(data.error) || 'Error');
  }
}


async function cargarResumen(){
  const data = await apiGet('api=resumen');

  if(!data.ok) return;

  document.getElementById('rRealizados').innerText = data.totalRealizados;
  document.getElementById('rPendientes').innerText = data.totalPendientes;
  document.getElementById('rMonto').innerText = money(data.totalMonto);
  document.getElementById('rResultados').innerText = data.totalResultados || 0;
  document.getElementById('rEmails').innerText = data.totalEmailsEnviados || 0;
  document.getElementById('rMes').innerText =
    data.mesActualRealizados +
    ' / ' +
    money(data.mesActualMonto);

  cargarSelectorInformeMensual(data.meses || []);

  const detalle = document.getElementById('detalleMeses');

  if(!data.meses.length){
    detalle.innerHTML = '<p>No hay registros todavía.</p>';
    return;
  }

  detalle.innerHTML = data.meses.map(m=>`
    <div style="padding:12px;border-bottom:1px solid #e5e7eb;">
      <strong>${htmlText(m.mes)}</strong><br>
      Realizados: ${m.realizados} |
      Pendientes: ${m.pendientes} |
      Recaudado: ${money(m.monto)}
    </div>
  `).join('');
}

function cargarSelectorInformeMensual(meses){
  const select = document.getElementById('mesInforme');
  const mensaje = document.getElementById('mensajeInformeMensual');

  if(!select) return;

  const valorActual = select.value;

  if(!meses.length){
    select.innerHTML = '<option value="">Sin meses disponibles</option>';
    if(mensaje) mensaje.innerText = 'Cuando haya registros cargados, aparecerán los meses disponibles.';
    return;
  }

  select.innerHTML = meses.map(m => {
    const mes = m.mes || '';
    return `<option value="${htmlAttr(mes)}">${htmlText(nombreMesVisible(mes))}</option>`;
  }).join('');

  if(valorActual && meses.some(m => m.mes === valorActual)){
    select.value = valorActual;
  }

  if(mensaje) mensaje.innerText = '';
}

function descargarInformeMensual(){
  const mes = document.getElementById('mesInforme').value;

  if(!mes){
    alert('Seleccioná un mes para generar el informe.');
    return;
  }

  window.open('index.php?export=informe_mensual&mes=' + encodeURIComponent(mes), '_blank');
}

function verArchivo(url, ext, titulo){
  const modal = document.getElementById('visorModal');
  const body = document.getElementById('visorBody');
  const descargar = document.getElementById('visorDescargar');
  const visorTitulo = document.getElementById('visorTitulo');

  visorTitulo.innerText = titulo || 'Archivo';
  descargar.href = url;
  body.innerHTML = '';

  if(['jpg','jpeg','png','webp'].includes(ext)){
    const img = document.createElement('img');
    img.src = url;
    body.appendChild(img);
  }else{
    const iframe = document.createElement('iframe');
    iframe.src = url;
    body.appendChild(iframe);
  }

  modal.classList.add('active');
}

function verPedido(url, ext){
  verArchivo(url, ext, 'Pedido');
}

function cerrarVisor(){
  document.getElementById('visorModal').classList.remove('active');
  document.getElementById('visorBody').innerHTML = '';
}

document.getElementById('visorModal').addEventListener('click', function(e){
  if(e.target === this) cerrarVisor();
});

cargarRegistros();
cargarResumen();
</script>

</body>
</html>
