<?php
/**
 * Configuración general del expediente.
 */

// Ruta pública del proyecto dentro de htdocs (sin diagonal final).
define('URL_BASE', '/ExpedienteFEMECI');
// Origen público para los enlaces de los correos (no se toma de la cabecera Host, que se puede falsificar)
define('URL_ORIGEN', 'http://localhost');

// Base de datos MySQL: una sola base (expediente_femeci). La app entra con un usuario de privilegios
// mínimos (database/usuario_aplicacion.sql) cuyas credenciales viven en privado/bd.php, nunca root.
$credencialesBd = is_file(__DIR__ . '/../privado/bd.php') ? require __DIR__ . '/../privado/bd.php' : ['usuario' => 'root', 'clave' => ''];
define('DB_HOST',    '127.0.0.1');
define('DB_PUERTO',  3306);
define('DB_NOMBRE',  'expediente_femeci');
define('DB_USUARIO', $credencialesBd['usuario']);
define('DB_CLAVE',   $credencialesBd['clave']);
unset($credencialesBd);
// Programas de MySQL para los respaldos (mysqldump) y la restauración (mysql)
define('MYSQL_BIN', 'C:/xampp/mysql/bin');
define('PHP_CLI',   'C:/xampp/php/php.exe');   // lanza el respaldo diario en segundo plano
// Cuentas (id de usuarios) que administran respaldos cuando hay varias instituciones: un respaldo trae la
// base completa. Vacío = cualquier administrador mientras haya una sola institución.
define('ADMINISTRADORES_SERVIDOR', []);

// Envío de correo (código de verificación al iniciar sesión).
//   'archivo': desarrollo; guarda cada correo como .eml en MAIL_CARPETA (fuera de htdocs).
//   'mail':    producción; usa mail() de PHP con el SMTP configurado en php.ini / sendmail.
define('MAIL_MODO',      'archivo');
define('MAIL_CARPETA',   'C:/xampp/mailoutput');
define('MAIL_REMITENTE', 'no-responder@femeci.local');

// Servicio local de IA (Whisper + extractor de campos). Escucha solo en este equipo.
define('IA_SERVICIO_URL', 'http://127.0.0.1:8765');
define('IA_ARCHIVO_TOKEN', __DIR__ . '/../ia/token.txt');

// Inicio de sesión con Google: el botón aparece solo cuando hay credenciales OAuth configuradas.
define('GOOGLE_CLIENT_ID', '');

// Verificación de cédulas en el Registro Nacional de Profesionistas (SEP). El portal oficial exige
// reCAPTCHA y no hay un servicio público: queda vacío hasta contar con un servicio autorizado (HTTPS)
// que responda en el formato del RNP. Vacío = las cédulas quedan "pendientes de verificación".
define('CEDULA_SERVICIO_URL', '');

date_default_timezone_set('America/Mexico_City');
mb_internal_encoding('UTF-8');
