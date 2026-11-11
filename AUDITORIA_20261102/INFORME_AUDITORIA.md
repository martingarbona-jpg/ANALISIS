# Auditoría local de ANÁLISIS — 02/11/2026

## Dictamen y alcance

Se revisó la copia existente en `C:\laragon\www\ANALISIS`. No se identificó malware, una web shell ni código ofuscado en los archivos locales examinados. Se corrigieron vulnerabilidades y defectos concretos con cambios acotados. **La detección de Imunify360 no está resuelta ni descartada: falta una verificación real en Donweb.**

La alerta, firma y horarios son información suministrada por el solicitante: `SMW-BLKH-SA-CLOUDAV-php.wshll.fm-NP2913-0`, el 02/11/2026 a las 08:14 y 08:15. No se accedió al hosting, sus logs ni su cuarentena; no se pudo comprobar si los bytes detectados eran iguales a los de esta copia local. La zona horaria de esos dos eventos debe confirmarla Donweb.

El inventario inicial contenía únicamente cuatro archivos de aplicación: `index.php` (86.906 bytes) y `phpmailer/src/{PHPMailer,SMTP,Exception}.php`. Las carpetas locales `adjuntos/` y `resultados/` estaban vacías. Existía el repositorio `.git/`, que no forma parte de un despliegue. No había archivos PHP adicionales, `.htaccess`, `.user.ini`, `php.ini`, `vendor/`, manifiesto Composer ni scripts ocultos de aplicación en este árbol local. No se auditó el historial completo de Git ni otros sitios de Laragon.

**Faltan localmente `smtp_config.php` y `registros.json`.** No se crearon esos archivos en el proyecto, no se inventó una contraseña SMTP ni se reemplazó la configuración del servidor. La aplicación requiere `smtp_config.php` después del login: sin ese archivo, esta copia local no puede funcionar completamente con su configuración real. Las pruebas se ejecutaron en copias temporales con una clave expresamente ficticia.

## 1. Qué contiene index.php

Una aplicación monolítica PHP + HTML + CSS + JavaScript para registrar análisis, protegida por una contraseña compartida y sesiones. Incluye pedidos y resultados PDF/JPEG/PNG, estados, pagos, historial, búsqueda, resumen, exportación mensual Excel y correo SMTP con adjunto y copia oculta. Los datos se guardan en `registros.json`; **no existe una conexión SQL en el código disponible**.

Los `require_once` apuntan a rutas locales fijas: `smtp_config.php`, el autoload Composer opcional y las tres clases PHPMailer. Ningún parámetro HTTP elige un archivo a incluir. No se encontraron inclusiones PHP remotas, `eval`, `assert`, `system`, `shell_exec`, ejecución mediante `exec`, descompresión de cargas ofuscadas ni deserialización de entrada externa en la aplicación. El texto `/exec` de la URL Google no es una llamada PHP a `exec()`.

Los `preg_replace` de index.php limpian espacios y nombres; no usan el modificador histórico `/e`. `base64_encode` enmascara credenciales en los diagnósticos. Las escrituras guardan JSON, archivos subidos y logs en rutas previstas. No hay un editor de código, un ejecutor de comandos ni un mecanismo de autopersistencia malicioso.

En PHPMailer, `base64_decode` procesa autenticación SMTP y datos MIME; `popen` implementa el transporte sendmail. La aplicación selecciona expresamente `isSMTP()`. No se eliminaron estas funciones de biblioteca. Los tres archivos coinciden íntegramente con la versión oficial **6.10.0**, normalizando únicamente CRLF/LF. Esto descarta modificaciones locales de esas tres copias respecto de esa versión; no constituye una garantía sobre cualquier versión futura o el entorno del hosting. Se consultaron también los avisos de seguridad oficiales y no se justificó una migración de biblioteca para esta corrección.

La consulta de afiliados usa JSONP desde un Google Apps Script fijo. Es una dependencia externa legítima aparente que ejecuta JavaScript en el navegador. No hay acceso a su código o cuenta administradora para certificar su integridad; se conservó la integración y no se enviaron consultas con DNI reales.

## 2. Qué podría activar la firma

**No se conoce el fragmento que coincide con NP2913-0.** No se dispone de la regla interna, de un resultado por línea ni de Imunify360 en este equipo. Atribuir el disparo a una función específica sería especulativo.

Como candidatos para que el fabricante investigue, index.php agrupa operaciones legítimas propias de un gestor de documentos: `move_uploaded_file`, `rename`, `unlink`, `rmdir`, `file_put_contents` y resolución de rutas. Esa combinación puede parecerse superficialmente a un gestor de archivos malicioso; **no está comprobado que cause la firma**. Los cambios no consisten en renombrar funciones, ofuscar, fragmentar código para eludir firmas ni desactivar el antivirus.

La documentación oficial explica las categorías `SA` y `wshll`, y el estado “Content removed”. Esto es compatible con el vaciado informado por Donweb, pero no demuestra que esta muestra sea malware. No se halló una explicación pública verificable del patrón exacto NP2913-0.

Hipótesis pendientes: falso positivo de la muestra legítima; diferencias o una inyección presentes únicamente en el archivo alojado; o un hallazgo asociado a otra versión. Hay que comparar hashes con la muestra de cuarentena anterior al vaciado. El archivo ya vacío no permite esa comparación.

## 3. Hallazgos y correcciones

| Hallazgo comprobado en código | Alcance y corrección |
| --- | --- |
| XSS almacenado en botones JavaScript | `pagado` de la creación se guardaba desde POST y se interpolaba sin escape en `onclick`. También había escapes incompletos en atributos con rutas y motivo. Se aplica escape de cadena JavaScript y del atributo HTML, y `textContent` para mensajes de error. Un valor malicioso podía ejecutar JavaScript al accionar el botón; no se observó un ataque real. Se verificaron cargas inertes en un contexto JavaScript aislado. |
| Falta de validación CSRF explícita | Login y operaciones de escritura carecían de tokens. La explotación efectiva dependía de cookies y navegador. Se agregó un token aleatorio por sesión, comprobación en servidor y envío automático desde todos los formularios AJAX. El login lleva un campo oculto. |
| Sesiones sin endurecimiento explícito | Se agregaron modo estricto, cookies exclusivas, HttpOnly, SameSite=Lax, Secure bajo HTTPS y regeneración de ID al ingresar. El logout limpia sesión y cookie. La contraseña y el método de acceso siguen siendo los existentes. |
| Archivos sensibles dentro de la raíz pública sin reglas locales de protección | No había `.htaccess` local que protegiera JSON, logs o documentos. La exposición real previa en Donweb depende de reglas del servidor no disponibles. El nuevo archivo bloquea acceso HTTP a datos, configuración, repositorio, respaldo, informes y bibliotecas, desactiva listados y dirige documentos al control PHP autenticado. Conserva las URLs existentes. |
| Subidas validadas solo por extensión | Se verifica `is_uploaded_file`, MIME con Fileinfo e integridad básica de imágenes con `getimagesize`, además de la lista original de extensiones. Se sigue usando `move_uploaded_file`. Un PHP renombrado a PDF es rechazado. No se afirma que cualquier PDF aceptado sea inocuo: no se implementó un antivirus documental. |
| Rutas sin confinamiento robusto | Reemplazar `../` no prueba que un archivo pertenezca al área autorizada. Se usa `realpath`, se rechazan traversal, esquemas y bytes nulos y se comprueba el prefijo del directorio autorizado con su separador. Aplica a mover, eliminar, visualizar y adjuntar por correo. Era un defecto latente si se manipulaban las rutas guardadas; no se encontró un parámetro HTTP que permitiera reemplazar directamente una ruta del registro. |
| Riesgo de pérdida de registros | Un JSON inválido se convertía silenciosamente en `[]`, y LOCK_EX al escribir no protegía toda la operación leer/modificar/guardar. Se rechaza JSON corrupto, se serializa la operación con un archivo de bloqueo y se guarda mediante temporal y reemplazo. Se comprobaron diez altas simultáneas de dos sesiones. |
| Diagnósticos SMTP potencialmente sensibles | Antes solo se enmascaraba la sección SMTPDebug. Ahora se enmascaran también ErrorInfo y la excepción tanto en logs como en el error devuelto y guardado. No se cambian host, puerto, seguridad, usuario, remitente, destinatarios, copia oculta, asunto, cuerpo ni la fuente de la contraseña. |
| Defecto de rutas en Windows | `rutaWeb()` mezclaba `__DIR__` con `DIRECTORY_SEPARATOR`, aunque los destinos usaban `/`. En Laragon se reproducía una ruta absoluta en el JSON. Se normalizan separadores antes de obtener el enlace relativo. El comportamiento de rutas Linux se conserva. |
| Bloque de eliminación inalcanzable | Se quitó una segunda eliminación condicionada a un valor que acababa de vaciarse. La eliminación legítima de todos los pedidos y el resultado permanece. |

No se borraron registros o documentos reales ni se identificaron otros archivos locales infectados. El respaldo contiene el archivo original completo; no se limpió ni alteró esa evidencia.

## 4. Archivos modificados, respaldo y entrega

- **Modificado:** `index.php`, para las correcciones anteriores. Se mantuvieron estilos, formularios visibles, acciones, informes y correo.
- **Nuevo para hosting:** `.htaccess`, necesario para la privacidad y para impedir que los documentos se ejecuten directamente como scripts. Los dos archivos se despliegan juntos.
- **Nuevos solo locales:** `AUDITORIA_20261102/`, con informe, solicitud a Donweb, pruebas, resultados, manifiesto y paquete de entrega.
- **Respaldo previo:** `BACKUP_ANTES_AUDITORIA_20261102/index.php`. Su SHA-256 coincide con el inventario inicial. `.htaccess` no existía, por lo que no había una versión anterior que respaldar.
- **Sin cambios:** los tres archivos PHPMailer. No se crearon ni editaron credenciales SMTP, registros ni archivos adjuntos reales.

Los hashes exactos y tamaños se encuentran en `MANIFIESTO_SHA256.json`. No se hicieron commits ni se publicaron cambios.

## 5. Verificaciones realizadas y límites

Entorno aislado: Apache **2.4.54** y PHP **8.1.10** de Laragon, únicamente en `127.0.0.1:18763`, con raíz y sesiones en una carpeta temporal independiente. El proceso de pruebas se detuvo al finalizar. Estos binarios son los disponibles para verificar compatibilidad local, no una recomendación de versión para producción.

- **49 comprobaciones de aplicación y seguridad**: login existente y regeneración de sesión, cookies, bloqueo de acceso anónimo, CSRF, JavaScript generado, escape de cargas XSS, formularios, email inválido, archivos prohibidos/falsificados, PDF/PNG legítimos, documentos históricos con espacios/acentos/`&`/`#`/comilla, bloqueo de archivos sensibles por Apache, traversal, documento de doble extensión sin interpretación PHP, edición, pago, realizado con traslado, resultado y reemplazo, reenvío sin destinatario, resumen, Excel, eliminación, concurrencia entre sesiones, JSON corrupto y logout.
- **8 comprobaciones SMTP simuladas**: éxito, fallo, estado y fecha, constantes, contraseña ficticia persistente entre intentos, destinatario/copia oculta/adjunto y redacción de secretos. El simulador no implementa transporte de red.
- **1 prueba con PHPMailer original**: `preSend()` genera MIME con asunto UTF-8 y PDF; no se llama `send()`.
- Sintaxis correcta mediante `php -l` de todos los PHP de aplicación y de los dos scripts PHP de prueba. Configuración Apache validada y `.htaccess` ejercitado en solicitudes reales locales.
- Comparación de todos los bloques CSS con el respaldo: idénticos. No se realizó comparación visual por capturas de pantalla ni pruebas manuales en todos los navegadores.
- En la copia de pruebas se deshabilitaron `mail`, funciones de sockets y funciones de ejecución de procesos. Las llamadas HTTP del cliente de pruebas se limitaron al Apache local. No hubo envíos reales.

Resultados guardados: `RESULTADOS_HTTP.json`, `RESULTADOS_SMTP.txt`, `RESULTADOS_MIME.txt`, `RESULTADOS_SINTAXIS.txt`. Para reproducir en este Laragon: `node AUDITORIA_20261102/ejecutar_pruebas.cjs`. El script usa rutas de binarios locales explícitas y deja la copia ficticia en `%TEMP%`; nunca debe apuntarse a producción.

**No fue posible comprobar:** datos reales (no hay `registros.json` local), credencial/configuración SMTP real (falta `smtp_config.php`), conexión o entrega SMTP, permisos/propietarios Linux, reglas heredadas del hosting, Google Apps Script, integridad del árbol remoto, logs de acceso, cuarentena ni veredicto del antivirus. No hay conexión SQL que probar en esta implementación. La persistencia comprobada de contraseña corresponde a una configuración ficticia; no se presenta como verificación de la contraseña de producción.

Límites residuales: contraseña compartida conservada por pedido expreso, sin limitador persistente de intentos propio; debe confirmarse la protección contra fuerza bruta en el WAF de Donweb. JSON no es una base transaccional: el bloqueo y reemplazo reducen pérdidas, pero no convierten documentos y registros en una transacción conjunta. Por ejemplo, un fallo de disco después de mover un archivo puede requerir recuperación desde respaldo. La validación MIME no reemplaza el análisis antivirus de documentos. Los límites de tamaño/cantidad siguen dependiendo de PHP/hosting. No se certifica ausencia absoluta de vulnerabilidades o de compromiso fuera de esta copia.

## 6. Qué subir a Donweb y cómo verificar

El ZIP `PARA_SUBIR_DONWEB_20261102.zip` contiene exclusivamente:

| Archivo local | Destino |
| --- | --- |
| `index.php` | `public_html/analisis/index.php` |
| `.htaccess` | `public_html/analisis/.htaccess` |

No se subieron. **No subir** `BACKUP_ANTES_AUDITORIA_20261102/`, `AUDITORIA_20261102/`, pruebas, resultados, `.git/` ni archivos temporales. No reemplazar `registros.json`, `smtp_config.php`, `adjuntos/` o `resultados/`. El ZIP no contiene estos archivos ni PHPMailer.

Antes de aplicar, conservar copia de los dos destinos remotos y verificar que la configuración SMTP existente permanezca disponible. Si en el hosting ya existe `.htaccess`, hay que integrar estas reglas preservando las de Donweb; el archivo remoto no estuvo disponible en esta auditoría. No sobrescribirlo a ciegas. Las reglas necesitan Apache 2.4 con mod_rewrite/AllowOverride o una implementación compatible; en otros servidores requieren adaptación. Deben colocarse antes de una regla general que capture todas las rutas. No es necesario hacer públicos los logs ni ampliar permisos a 0777.

Con Donweb: analizar primero la muestra exacta y el archivo corregido en un área de revisión controlada. Tras autorización de despliegue, verificar login, listados, documentos existentes, bloqueo anónimo de JSON/documentos y respuesta sin error 500. La comprobación inicial del correo debe ser de configuración/lógica, sin enviar mensajes. Cualquier prueba de entrega real requiere autorización separada.

## 7. Estado de Imunify360

**Corregidos y probados localmente:** los problemas descritos. **Pendiente:** confirmar la identidad de la muestra detectada, obtener una revisión del fabricante y un nuevo escaneo de los archivos que realmente quedarán en Donweb. No se garantiza que la firma deje de activarse. La solicitud lista para copiar está en `SOLICITUD_DONWEB.md`; no fue enviada. No se solicitó una exclusión del antivirus.

## Fuentes primarias consultadas

- [Interfaz y significado de firmas/estados de Imunify360](https://docs.imunify360.com/user_interface/).
- [Envío oficial de falsos positivos](https://docs.imunify360.com/command_line_interface/#submit-false-positive-false-negative).
- [PHPMailer 6.10.0 — PHPMailer.php](https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.10.0/src/PHPMailer.php), [SMTP.php](https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.10.0/src/SMTP.php) y [Exception.php](https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.10.0/src/Exception.php).
- [Política de seguridad de PHPMailer](https://github.com/PHPMailer/PHPMailer/security/policy) y [avisos oficiales](https://github.com/PHPMailer/PHPMailer/security/advisories).
