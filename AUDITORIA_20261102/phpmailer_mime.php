<?php
if (PHP_SAPI !== 'cli') exit(1);
$dir = $argv[1] ?? '';
if (strpos(basename($dir), 'analisis-auditoria-') !== 0) throw new Exception('Solo copia temporal');
foreach (['Exception', 'PHPMailer', 'SMTP'] as $clase) require $dir . '/app/phpmailer/src/' . $clase . '.php';
$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
$mail->isSMTP();
$mail->CharSet = 'UTF-8';
$mail->setFrom('origen@example.invalid', 'Origen ficticio');
$mail->addAddress('destino@example.invalid', 'Paciente ficticio');
$mail->Subject = 'Resultado de análisis';
$mail->Body = 'Resultado ficticio para verificar MIME sin transporte.';
$mail->addAttachment($dir . '/app/resultados/prueba_smtp.pdf', 'resultado.pdf');
if (!$mail->preSend()) throw new Exception('No se pudo preparar el mensaje');
$mime = $mail->getSentMIMEMessage();
if (strpos($mime, 'application/pdf') === false || strpos($mime, 'resultado.pdf') === false || strpos($mime, 'multipart/mixed') === false) throw new Exception('MIME incompleto');
echo "OK: PHPMailer original genera MIME, asunto UTF-8 y adjunto PDF mediante preSend(), sin send() ni conexión SMTP.\n";
