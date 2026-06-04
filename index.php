<?php
session_start();

define('PASSWORD_SISTEMA', 'Pasteleros2026');
define('PASSWORD_LABORATORIO', 'Laboratorio2026');

if (isset($_POST['login_password'])) {
  $rolLogin = $_POST['login_rol'] ?? 'obra_social';
  $passwordEsperada = $rolLogin === 'laboratorio' ? PASSWORD_LABORATORIO : PASSWORD_SISTEMA;

  if ($_POST['login_password'] === $passwordEsperada) {
    $_SESSION['analisis_ok'] = true;
    $_SESSION['rol'] = $rolLogin === 'laboratorio' ? 'laboratorio' : 'obra_social';
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
  } else {
    $login_error = "Contraseña incorrecta";
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

input,
select{
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

    <select name="login_rol" required>
      <option value="obra_social">Obra Social</option>
      <option value="laboratorio">Laboratorio</option>
    </select>

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

if (empty($_SESSION['rol'])) {
  $_SESSION['rol'] = 'obra_social';
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

function usuarioEsObraSocial(){
  return ($_SESSION['rol'] ?? '') === 'obra_social';
}

function usuarioEsLaboratorio(){
  return ($_SESSION['rol'] ?? '') === 'laboratorio';
}

function requerirRol($rol){
  if (($_SESSION['rol'] ?? '') !== $rol) {
    throw new Exception('No tiene permisos para realizar esta accion.');
  }
}

function limpiarTexto($txt){
  $txt = trim((string)$txt);
  $txt = preg_replace('/\s+/', ' ', $txt);
  return $txt;
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

function borrarArchivoSiExiste($rutaRelativa){
  if (!$rutaRelativa) return;

  $rutaRelativa = str_replace(['../', '..\\'], '', $rutaRelativa);
  $archivo = __DIR__ . '/' . $rutaRelativa;

  if (file_exists($archivo) && is_file($archivo)) {
    @unlink($archivo);
  }

  $bases = [realpath(ADJUNTOS_DIR), realpath(RESULTADOS_DIR)];
  $dir = dirname($archivo);

  while ($dir && is_dir($dir)) {
    $realDir = realpath($dir);
    if (!$realDir || in_array($realDir, $bases, true)) {
      break;
    }

    $restantes = array_diff(scandir($dir), ['.', '..']);

    if (count($restantes) === 0) {
      @rmdir($dir);
      $dir = dirname($dir);
    } else {
      break;
    }
  }
}

function guardarResultado($campo, $dni, $nombre){
  if (!isset($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
    throw new Exception('Debe adjuntar el resultado.');
  }

  if ($_FILES[$campo]['error'] !== UPLOAD_ERR_OK) {
    throw new Exception('No se pudo subir el resultado.');
  }

  $permitidos = ['pdf', 'jpg', 'jpeg', 'png'];
  $original = $_FILES[$campo]['name'];
  $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

  if (!in_array($ext, $permitidos, true)) {
    throw new Exception('Archivo no permitido. Solo PDF, JPG, JPEG o PNG.');
  }

  $persona = limpiarNombreArchivo($nombre . ' - DNI ' . $dni);
  $destDir = RESULTADOS_DIR . '/' . date('Y-m') . '/' . $persona;

  if (!is_dir($destDir)) {
    mkdir($destDir, 0755, true);
  }

  $nombreFinal = fechaArchivo() . ' - ' . limpiarNombreArchivo($original);
  $destino = $destDir . '/' . $nombreFinal;

  if (!move_uploaded_file($_FILES[$campo]['tmp_name'], $destino)) {
    throw new Exception('No se pudo guardar el resultado en el hosting.');
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

    if ($pago === 'Pagado' && ($r['pagado'] ?? '') !== 'Sí') return false;
    if ($pago === 'No pagado' && ($r['pagado'] ?? '') === 'Sí') return false;

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

function fechaRegistroDia($r){
  $fecha = $r['fechaRealizado'] ?? $r['fechaCarga'] ?? '';

  if ($fecha) {
    $dt = DateTime::createFromFormat('d/m/Y H:i', $fecha);
    if ($dt) return $dt->format('Y-m-d');
  }

  if (!empty($r['fechaISO'])) {
    $ts = strtotime($r['fechaISO']);
    if ($ts) return date('Y-m-d', $ts);
  }

  return '';
}

function filtrarRegistrosLaboratorio($registros){
  $fecha = $_GET['fecha'] ?? date('Y-m-d');
  $buscar = normalizar($_GET['buscar'] ?? '');

  $filtrados = array_filter($registros, function($r) use ($fecha, $buscar){
    if (($r['estado'] ?? '') !== 'Realizado') return false;
    if (fechaRegistroDia($r) !== $fecha) return false;

    if ($buscar !== '') {
      $texto = normalizar(($r['nombre'] ?? '') . ' ' . ($r['dni'] ?? ''));
      if (strpos($texto, $buscar) === false) return false;
    }

    return true;
  });

  usort($filtrados, function($a, $b){
    $fechaA = $a['fechaRealizado'] ?? $a['fechaCarga'] ?? '';
    $fechaB = $b['fechaRealizado'] ?? $b['fechaCarga'] ?? '';
    return strcmp($fechaB, $fechaA);
  });

  return array_values($filtrados);
}

function resumenRegistros($registros){
  $totalRealizados = 0;
  $totalPendientes = 0;
  $totalMonto = 0;
  $mesActualRealizados = 0;
  $mesActualMonto = 0;
  $mesActual = date('Y-m');
  $meses = [];

  foreach ($registros as $r) {
    $estado = $r['estado'] ?? 'Pendiente';
    $monto = (float)($r['monto'] ?? 0);
    $fechaISO = $r['fechaISO'] ?? date('Y-m-d');
    $mes = substr($fechaISO, 0, 7);

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

    if (($r['pagado'] ?? '') === 'Sí') {
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
    'mesActualRealizados' => $mesActualRealizados,
    'mesActualMonto' => $mesActualMonto,
    'meses' => array_values($meses)
  ];
}

/* =========================
   API INTERNA
   ========================= */

asegurarSistema();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['api'])) {
  $api = $_GET['api'];
  $registros = leerRegistros();

  if ($api === 'listar') {
    if (!usuarioEsObraSocial()) responderJson(false, ['error' => 'No tiene permisos para listar esta vista.']);
    responderJson(true, ['registros' => filtrarRegistros($registros)]);
  }

  if ($api === 'resumen') {
    if (!usuarioEsObraSocial()) responderJson(false, ['error' => 'No tiene permisos para ver el resumen.']);
    responderJson(true, resumenRegistros($registros));
  }

  if ($api === 'laboratorio') {
    if (!usuarioEsLaboratorio()) responderJson(false, ['error' => 'No tiene permisos para ver laboratorio.']);
    responderJson(true, ['registros' => filtrarRegistrosLaboratorio($registros)]);
  }

  responderJson(false, ['error' => 'API no válida']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $accion = $_POST['accion'] ?? '';
    $registros = leerRegistros();

    if ($accion === 'crear') {
      requerirRol('obra_social');
      $nombre = limpiarTexto($_POST['nombre'] ?? '');
      $dni = limpiarTexto($_POST['dni'] ?? '');
      $pagado = $_POST['pagado'] ?? 'No';
      $estado = $_POST['estado'] ?? 'Pendiente';
      $monto = (float)($_POST['monto'] ?? 0);

      if ($nombre === '') throw new Exception('Debe cargar nombre y apellido.');
      if ($dni === '') throw new Exception('Debe cargar DNI.');
      if ($pagado === 'Sí' && $monto < 0) throw new Exception('Monto inválido.');
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
        'estado' => $estado,
        'fechaRealizado' => $estado === 'Realizado' ? date('d/m/Y H:i') : '',
        'motivo' => $_POST['motivo'] ?? '',
        'pagado' => $pagado,
        'monto' => $pagado === 'Sí' ? $monto : 0,
        'adjunto' => $adjunto,
        'historial' => [[
          'fecha' => date('d/m/Y H:i'),
          'accion' => 'Registro creado'
        ]]
      ];

      guardarRegistros($registros);
      responderJson(true, ['mensaje' => 'Registro guardado correctamente.']);
    }

    if ($accion === 'realizar') {
      requerirRol('obra_social');
      $id = $_POST['id'] ?? '';
      $monto = (float)($_POST['monto'] ?? 0);
      $encontrado = false;

      foreach ($registros as &$r) {
        if (($r['id'] ?? '') === $id) {
          $encontrado = true;

          if ($monto < 0 && (($r['monto'] ?? 0) < 0)) {
  throw new Exception('Debe ingresar un monto válido.');
}

          if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $r['adjunto'] = guardarAdjunto('archivo', 'Realizado', $r['dni'] ?? '', $r['nombre'] ?? '');
          } elseif (!empty($r['adjunto'])) {
            $r['adjunto'] = moverAdjuntoARealizados($r['adjunto'], $r['dni'] ?? '', $r['nombre'] ?? '');
          } else {
            throw new Exception('Debe adjuntar el pedido para marcar como realizado.');
          }

          $r['estado'] = 'Realizado';
          $r['pagado'] = 'Sí';
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
      requerirRol('obra_social');
      $id = $_POST['id'] ?? '';
      $pagado = $_POST['pagado'] ?? 'No';
      $monto = (float)($_POST['monto'] ?? 0);
      $encontrado = false;

      foreach ($registros as &$r) {
        if (($r['id'] ?? '') === $id) {
          $encontrado = true;
          $r['pagado'] = $pagado === 'Sí' ? 'Sí' : 'No';
          $r['monto'] = $r['pagado'] === 'Sí' ? $monto : 0;
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

if ($accion === 'eliminar') {
  requerirRol('obra_social');

  $id = $_POST['id'] ?? '';
  $nuevo = [];
  $encontrado = false;

  foreach ($registros as $r) {

    if (($r['id'] ?? '') === $id) {

      $encontrado = true;

      if (!empty($r['adjunto'])) {

        $rutaRelativa = str_replace(['../', '..\\'], '', $r['adjunto']);
        $archivo = __DIR__ . '/' . $rutaRelativa;

        if (file_exists($archivo) && is_file($archivo)) {
          @unlink($archivo);
        }

        // borrar carpetas vacías
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
      borrarArchivoSiExiste($r['resultado'] ?? '');

      continue;
    }

    $nuevo[] = $r;
  }

  if (!$encontrado) {
    throw new Exception('Registro no encontrado.');
  }

  guardarRegistros($nuevo);

  responderJson(true, [
    'mensaje' => 'Registro y adjunto eliminados.'
  ]);
}

    if ($accion === 'subirResultado') {
      requerirRol('laboratorio');
      $id = $_POST['id'] ?? '';
      $encontrado = false;

      foreach ($registros as &$r) {
        if (($r['id'] ?? '') === $id) {
          $encontrado = true;

          if (($r['estado'] ?? '') !== 'Realizado') {
            throw new Exception('Solo se pueden cargar resultados de registros realizados.');
          }

          $resultadoAnterior = $r['resultado'] ?? '';
          $resultadoNuevo = guardarResultado('archivoResultado', $r['dni'] ?? '', $r['nombre'] ?? '');
          borrarArchivoSiExiste($resultadoAnterior);

          $r['resultado'] = $resultadoNuevo;
          $r['fechaResultado'] = date('d/m/Y H:i');
          $r['historial'][] = [
            'fecha' => date('d/m/Y H:i'),
            'accion' => 'Resultado cargado por laboratorio'
          ];
          break;
        }
      }
      unset($r);

      if (!$encontrado) throw new Exception('Registro no encontrado.');

      guardarRegistros($registros);
      responderJson(true, ['mensaje' => 'Resultado cargado correctamente.']);
    }

    responderJson(false, ['error' => 'Acción no válida.']);

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

<link rel="icon" type="image/png" href="favicon.png?v=2">

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

    .header-top{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:16px;
      flex-wrap:wrap;
    }

    .logout{
      color:white;
      border:1px solid rgba(255,255,255,.7);
      border-radius:8px;
      padding:8px 10px;
      text-decoration:none;
      font-weight:bold;
      background:rgba(255,255,255,.12);
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
    }

    table{
      width:100%;
      border-collapse:collapse;
      min-width:1120px;
    }

    th{
      background:var(--verde);
      color:white;
      padding:12px;
      text-align:left;
      position:sticky;
      top:0;
      z-index:1;
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
  <div class="header-top">
    <div>
      <h1><?= usuarioEsLaboratorio() ? 'Laboratorio - Resultados de analisis' : 'REGISTRO_ANALISIS' ?></h1>
      <p><?= usuarioEsLaboratorio() ? 'Carga y consulta de resultados' : 'Gestion de analisis, pendientes, adjuntos y resumen' ?></p>
    </div>
    <a class="logout" href="?logout=1">Cerrar sesion</a>
  </div>
</header>

<div class="container">
<?php if (usuarioEsObraSocial()) { ?>

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

    <div class="card tabla-wrap">
      <table>
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Nombre</th>
            <th>DNI</th>
            <th>Estado</th>
            <th>Pago</th>
            <th>Monto</th>
            <th>Pedido</th>
            <th>Resultado</th>
            <th>Acción</th>
          </tr>
        </thead>

        <tbody id="tablaRegistros">
          <tr><td colspan="9">Cargando...</td></tr>
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
            <label>Pagado</label>
            <select name="pagado" id="pagado" onchange="toggleMonto()">
              <option>No</option>
              <option>Sí</option>
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
    </div>

    <div class="card" style="margin-top:20px;">
      <h2>Detalle por mes</h2>
      <div id="detalleMeses"></div>
    </div>
  </div>

<?php } else { ?>

  <div class="card">
    <h2>Laboratorio - Resultados de analisis</h2>

    <div class="grid">
      <div class="campo">
        <label>Fecha realizado/envio</label>
        <input type="date" id="labFecha" value="<?= date('Y-m-d') ?>">
      </div>

      <div class="campo">
        <label>Buscar nombre o DNI</label>
        <input type="text" id="labBuscar" placeholder="Nombre o DNI" oninput="cargarLaboratorio()">
      </div>

      <div class="campo">
        <label>&nbsp;</label>
        <button type="button" onclick="cargarLaboratorio()">Actualizar</button>
      </div>
    </div>
  </div>

  <div class="card tabla-wrap">
    <table>
      <thead>
        <tr>
          <th>Fecha realizado/envio</th>
          <th>Nombre</th>
          <th>DNI</th>
          <th>Pedido</th>
          <th>Resultado</th>
        </tr>
      </thead>

      <tbody id="tablaLaboratorio">
        <tr><td colspan="5">Cargando...</td></tr>
      </tbody>
    </table>
  </div>

<?php } ?>

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

<script>
let filtroEstadoActual = 'Pendiente';

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

  if(pagado === 'Sí'){
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

const formCarga = document.getElementById('formCarga');
if(formCarga){
formCarga.addEventListener('submit', async function(e){
  e.preventDefault();

  const nombre = document.getElementById('nombre').value.trim();
  const dni = document.getElementById('dni').value.trim();
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

  if(pagado === 'Sí' && Number(monto) < 0){
  alert('Monto inválido');
  return;
}

  if(pagado === 'Sí' && Number(monto) === 0 && !document.getElementById('motivo').value){
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
  mensaje.innerHTML = '⏳ Guardando registro...';

  const data = await apiPost(fd);

  if(data.ok){
    mensaje.innerHTML = '✅ Registro guardado correctamente';

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
    mensaje.innerHTML = '❌ ' + (data.error || 'Error al guardar');
  }
});
}

async function cargarRegistros(){
  const buscar = document.getElementById('buscarTexto')?.value || '';
  const estado = document.getElementById('buscarEstado')?.value || 'Pendiente';
  const pago = document.getElementById('buscarPago')?.value || 'Todos';

  const tbody = document.getElementById('tablaRegistros');
  tbody.innerHTML = '<tr><td colspan="9">Cargando...</td></tr>';

  const data = await apiGet(
    'api=listar' +
    '&buscar=' + encodeURIComponent(buscar) +
    '&estado=' + encodeURIComponent(estado) +
    '&pago=' + encodeURIComponent(pago)
  );

  tbody.innerHTML = '';

  if(!data.ok){
    tbody.innerHTML = `<tr><td colspan="9">${data.error || 'Error al cargar registros'}</td></tr>`;
    return;
  }

  if(!data.registros.length){
    tbody.innerHTML = `<tr><td colspan="9">No hay registros para este filtro.</td></tr>`;
    return;
  }

  data.registros.forEach(r=>{
    const adj = r.adjunto || '';
    const ext = adj.split('.').pop().toLowerCase();

    const pedidoHtml = adj
      ? `
        <a class="link-btn" href="${adj}" target="_blank">Abrir</a>
        <button class="light" onclick="verPedido('${adj.replace(/'/g, "\\'")}', '${ext}')">Ver</button>
        <a class="link-btn" href="${adj}" download>Descargar</a>
      `
      : '-';

    const res = r.resultado || '';
    const resExt = res.split('.').pop().toLowerCase();
    const resultadoHtml = res
      ? `
        <button class="light" onclick="verPedido('${res.replace(/'/g, "\\'")}', '${resExt}')">Ver</button>
        <a class="link-btn" href="${res}" download>Descargar</a>
      `
      : 'Pendiente';

    const tr = document.createElement('tr');

    tr.innerHTML = `
      <td>${r.fechaCarga || ''}</td>
      <td><strong>${r.nombre || ''}</strong></td>
      <td>${r.dni || ''}</td>

      <td>
        <span class="estado-pill ${r.estado === 'Pendiente' ? 'pendiente' : 'realizado'}">
          ${r.estado || ''}
        </span>
      </td>

      <td>
        <span class="pago-pill ${r.pagado === 'Sí' ? 'pagado' : 'nopagado'}">
          ${r.pagado === 'Sí' ? 'Pagado' : 'No pagado'}
        </span>
      </td>

      <td>${Number(r.monto) === 0 && r.motivo ? r.motivo : money(r.monto)}</td>

      <td>${pedidoHtml}</td>

      <td>${resultadoHtml}</td>

      <td>
  ${
    r.estado === 'Pendiente'
      ? `
        <button onclick="marcarRealizado('${r.id}', '${r.pagado}', ${Number(r.monto || 0)}, ${adj ? 'true' : 'false'})">
          Realizar
        </button>

        <button class="danger" onclick="eliminarRegistro('${r.id}')">
          Eliminar
        </button>
      `
      : `
        ✔

        <button class="danger" onclick="eliminarRegistro('${r.id}')">
          Eliminar
        </button>
      `
  }
</td>
    `;

    tbody.appendChild(tr);
  });
}

async function marcarRealizado(id, pagado, montoActual, tieneAdjunto){
  let monto = montoActual;

  if(pagado !== 'Sí' || Number(montoActual) <= 0){
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
        alert(data.error || 'Error');
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
    alert(data.error || 'Error');
  }
}
async function eliminarRegistro(id){

  if(!confirm('¿Eliminar registro y adjunto?')){
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
    alert(data.error || 'Error');
  }
}


async function cargarResumen(){
  const data = await apiGet('api=resumen');

  if(!data.ok) return;

  document.getElementById('rRealizados').innerText = data.totalRealizados;
  document.getElementById('rPendientes').innerText = data.totalPendientes;
  document.getElementById('rMonto').innerText = money(data.totalMonto);
  document.getElementById('rMes').innerText =
    data.mesActualRealizados +
    ' / ' +
    money(data.mesActualMonto);

  const detalle = document.getElementById('detalleMeses');

  if(!data.meses.length){
    detalle.innerHTML = '<p>No hay registros todavía.</p>';
    return;
  }

  detalle.innerHTML = data.meses.map(m=>`
    <div style="padding:12px;border-bottom:1px solid #e5e7eb;">
      <strong>${m.mes}</strong><br>
      Realizados: ${m.realizados} |
      Pendientes: ${m.pendientes} |
      Recaudado: ${money(m.monto)}
    </div>
  `).join('');
}

async function cargarLaboratorio(){
  const tbody = document.getElementById('tablaLaboratorio');
  if(!tbody) return;

  const fecha = document.getElementById('labFecha')?.value || '';
  const buscar = document.getElementById('labBuscar')?.value || '';

  tbody.innerHTML = '<tr><td colspan="5">Cargando...</td></tr>';

  const data = await apiGet(
    'api=laboratorio' +
    '&fecha=' + encodeURIComponent(fecha) +
    '&buscar=' + encodeURIComponent(buscar)
  );

  tbody.innerHTML = '';

  if(!data.ok){
    tbody.innerHTML = `<tr><td colspan="5">${data.error || 'Error al cargar registros'}</td></tr>`;
    return;
  }

  if(!data.registros.length){
    tbody.innerHTML = '<tr><td colspan="5">No hay registros realizados para este filtro.</td></tr>';
    return;
  }

  data.registros.forEach(r => {
    const adj = r.adjunto || '';
    const adjExt = adj.split('.').pop().toLowerCase();
    const res = r.resultado || '';
    const resExt = res.split('.').pop().toLowerCase();

    const pedidoHtml = adj
      ? `
        <button class="light" onclick="verPedido('${adj.replace(/'/g, "\\'")}', '${adjExt}')">Ver</button>
        <a class="link-btn" href="${adj}" download>Descargar</a>
      `
      : '-';

    const resultadoHtml = res
      ? `
        <button class="light" onclick="verPedido('${res.replace(/'/g, "\\'")}', '${resExt}')">Ver</button>
        <a class="link-btn" href="${res}" download>Descargar</a>
        <button onclick="subirResultado('${r.id}')">Reemplazar</button>
      `
      : `<button onclick="subirResultado('${r.id}')">Subir resultado</button>`;

    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${r.fechaRealizado || r.fechaCarga || ''}</td>
      <td><strong>${r.nombre || ''}</strong></td>
      <td>${r.dni || ''}</td>
      <td>${pedidoHtml}</td>
      <td>${resultadoHtml}</td>
    `;
    tbody.appendChild(tr);
  });
}

async function subirResultado(id){
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = '.jpg,.jpeg,.png,.pdf';

  input.onchange = async () => {
    const archivo = input.files[0];
    if(!archivo) return;

    const fd = new FormData();
    fd.append('accion', 'subirResultado');
    fd.append('id', id);
    fd.append('archivoResultado', archivo);

    const data = await apiPost(fd);

    if(data.ok){
      await cargarLaboratorio();
      alert('Resultado cargado correctamente');
    }else{
      alert(data.error || 'Error al subir resultado');
    }
  };

  input.click();
}

function verPedido(url, ext){
  const modal = document.getElementById('visorModal');
  const body = document.getElementById('visorBody');
  const descargar = document.getElementById('visorDescargar');

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

function cerrarVisor(){
  document.getElementById('visorModal').classList.remove('active');
  document.getElementById('visorBody').innerHTML = '';
}

document.getElementById('visorModal').addEventListener('click', function(e){
  if(e.target === this) cerrarVisor();
});

if(document.getElementById('tablaRegistros')){
  cargarRegistros();
  cargarResumen();
}

if(document.getElementById('tablaLaboratorio')){
  document.getElementById('labFecha')?.addEventListener('change', cargarLaboratorio);
  cargarLaboratorio();
}
</script>

</body>
</html>
