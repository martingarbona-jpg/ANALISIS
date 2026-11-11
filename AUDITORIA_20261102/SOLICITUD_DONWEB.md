# Solicitud de revisión de Imunify360 — texto preparado, no enviado

Dominio: ospastelerosmza.com.ar

Archivo: public_html/analisis/index.php (solicitamos confirmar la ruta absoluta de la cuenta).

Firma informada: SMW-BLKH-SA-CLOUDAV-php.wshll.fm-NP2913-0.

Detecciones informadas: 02/11/2026, 08:14 y 08:15. Por favor, confirmar la zona horaria del registro.

El archivo queda vacío después de la actuación del antivirus. Se auditó la copia local disponible y no se identificaron web shells, código ofuscado, ejecución de entrada HTTP o inclusiones remotas. Se encontraron y corrigieron problemas de XSS, CSRF, validación de documentos, confinamiento de rutas y protección HTTP de archivos. No afirmamos que la muestra remota coincida con la local ni que la detección sea ya un falso positivo confirmado.

La aplicación legítima administra análisis y documentos, guarda registros JSON y envía resultados con PHPMailer por SMTP. Usa move_uploaded_file, rename, unlink y file_put_contents para esas funciones. Los tres archivos PHPMailer locales coinciden con la versión oficial 6.10.0, normalizando solo saltos de línea. No se retiraron funciones de biblioteca ni se ofuscó código para evitar el antivirus.

Solicitamos:

1. Conservar y facilitar por el canal privado de soporte la muestra anterior al vaciado, su SHA-256, tamaño, ruta, propietario y evento completo de Imunify360, incluida versión de motor y base de firmas.
2. Comparar su hash con el original local y el corregido indicados en MANIFIESTO_SHA256.json. El hash del archivo ya vacío no sirve para identificar la muestra previa.
3. Identificar el fragmento o criterio que activa NP2913-0 y enviar la muestra a Imunify para revisar un posible falso positivo.
4. Escanear también el árbol remoto de analisis, reglas .htaccess/.user.ini, tareas programadas y archivos modificados recientemente para descartar una inyección que solo exista en el hosting. Confirmar otros archivos afectados y revisar accesos cercanos a los eventos.
5. Evaluar el archivo corregido en un área controlada antes del reemplazo público; devolver veredicto, hash exacto analizado y versión de firmas. Tras su aplicación autorizada, confirmar un nuevo escaneo y que el contenido no vuelve a ser vaciado.
6. Confirmar compatibilidad de las reglas Apache 2.4 de privacidad y conservar cualquier configuración existente del hosting. No reemplazar registros, documentos ni smtp_config.php.

Como referencia, la documentación oficial ofrece el envío de falsos positivos por el administrador del servidor:

```sh
imunify360-agent submit false-positive --reason 'Aplicacion legitima de analisis; se solicita revision de NP2913-0 y comparacion de hashes' /RUTA_ABSOLUTA_CONFIRMADA/public_html/analisis/index.php
```

Referencia: [documentación oficial de envío de falsos positivos](https://docs.imunify360.com/command_line_interface/#submit-false-positive-false-negative). El administrador debe usar la ruta de la muestra que desea analizar y la sintaxis admitida por su versión. No se ejecutó este comando durante la auditoría.

Adjuntos para soporte privado: informe, manifiesto y, cuando lo soliciten, muestra original/corregida identificadas por hash. El código conserva la contraseña de acceso del sistema; no publicarlo en foros ni servicios de análisis públicos. No incluir registros de pacientes, documentos ni la contraseña/configuración SMTP.

No solicitamos desactivar Imunify360 ni agregar exclusiones como solución. Necesitamos un análisis de la muestra y verificación posterior.
