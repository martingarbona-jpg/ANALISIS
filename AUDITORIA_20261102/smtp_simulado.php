<?php
// Solo CLI sobre una copia temporal. Sustituye el transporte para impedir correos reales.
namespace PHPMailer\PHPMailer {
  class PHPMailer {
    public $ErrorInfo = '';
    public $SMTPDebug, $Debugoutput, $Host, $SMTPAuth, $Username, $Password;
    public $SMTPSecure, $Port, $CharSet, $Subject, $Body;
    public $from, $to, $bcc, $attachment;
    public static $instancias = [];
    public static $fallar = false;
    public function __construct($exceptions = true) { self::$instancias[] = $this; }
    public function isSMTP() {}
    public function setFrom($mail, $name) { $this->from = [$mail, $name]; }
    public function addAddress($mail, $name) { $this->to = [$mail, $name]; }
    public function addBCC($mail) { $this->bcc = $mail; }
    public function addAttachment($file, $name) { $this->attachment = [$file, $name]; }
    public function send() {
      if (self::$fallar) {
        $this->ErrorInfo = 'Error simulado ' . $this->Password;
        throw new \Exception($this->ErrorInfo);
      }
      return true;
    }
  }
}
namespace {
  if (PHP_SAPI !== 'cli') exit(1);
  $dir = $argv[1] ?? '';
  if (strpos(basename($dir), 'analisis-auditoria-') !== 0) throw new \Exception('Solo copia temporal');
  $_SERVER['REQUEST_METHOD'] = 'GET';
  $_SERVER['SCRIPT_NAME'] = '/app/index.php';
  session_save_path($dir . '/sessions');
  session_start();
  $_SESSION['analisis_ok'] = true;
  session_write_close();
  ob_start();
  require $dir . '/app/index.php';
  ob_end_clean();
  function verificar($condicion, $mensaje) {
    if (!$condicion) throw new \Exception($mensaje);
    echo "OK: " . $mensaje . PHP_EOL;
  }
  $configAntes = hash_file('sha256', $dir . '/app/smtp_config.php');
  $ruta = 'resultados/prueba_smtp.pdf';
  file_put_contents($dir . '/app/' . $ruta, "%PDF-1.4\n%%EOF");
  $registro = ['id' => 'prueba', 'nombre' => 'Persona ficticia', 'email' => 'prueba@example.invalid', 'resultado' => $ruta];
  $estado = actualizarEstadoEmailResultado($registro);
  $mail = \PHPMailer\PHPMailer\PHPMailer::$instancias[0];
  verificar($estado['enviado'] && $registro['emailEnviado'] && $registro['fechaEmail'] !== '', 'Envío simulado actualiza estado y fecha');
  verificar($mail->Password === SMTP_PASS && $mail->Host === SMTP_HOST && $mail->Port === SMTP_PORT && $mail->SMTPSecure === SMTP_SECURE, 'Configuración SMTP se carga de las mismas constantes');
  verificar($mail->bcc === SMTP_USER && $mail->to[0] === 'prueba@example.invalid' && is_file($mail->attachment[0]), 'Destinatario, copia oculta y adjunto conservados');
  \PHPMailer\PHPMailer\PHPMailer::$fallar = true;
  $estado = actualizarEstadoEmailResultado($registro);
  verificar(!$estado['enviado'] && !$registro['emailEnviado'] && $registro['fechaEmail'] === '', 'Fallo SMTP simulado conserva resultado y marca error');
  verificar(strpos($estado['error'], SMTP_PASS) === false && strpos($registro['errorEmail'], SMTP_PASS) === false, 'Error devuelto al formulario y guardado en registros no expone clave ficticia');
  verificar(strpos(file_get_contents($dir . '/app/logs/mail_error.log'), SMTP_PASS) === false, 'Log no expone la clave ficticia ni en ErrorInfo ni en excepción');
  \PHPMailer\PHPMailer\PHPMailer::$fallar = false;
  actualizarEstadoEmailResultado($registro);
  verificar(\PHPMailer\PHPMailer\PHPMailer::$instancias[2]->Password === SMTP_PASS && hash_file('sha256', $dir . '/app/smtp_config.php') === $configAntes, 'Reenvío conserva contraseña y no modifica configuración SMTP');
  try { rutaResultadoAbsoluta('smtp_config.php'); verificar(false, 'No adjuntar configuración'); }
  catch (\Exception $e) { verificar($e->getMessage() === 'El archivo de resultado no existe.', 'Correo rechaza adjuntos fuera de resultados'); }
}
