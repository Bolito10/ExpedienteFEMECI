<?php
/**
 * Respaldo de la base de datos desde la línea de comandos (nunca desde el navegador).
 *
 *   php respaldar.php            respalda solo si todavía no hay respaldo de hoy (lo lanza la app y el .bat)
 *   php respaldar.php --forzar   respalda aunque ya haya uno de hoy
 *
 * Código de salida: 0 bien (o ya había respaldo de hoy), 1 falló. Detalle en privado/registros/php-errores.log.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/nucleo/seguridad.php';
configurarErrores();
ini_set('display_errors', 'stderr');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/nucleo/funciones.php';
require_once __DIR__ . '/../app/servicios/sistema/respaldos.php';

$forzar = in_array('--forzar', $argv, true);
$r = $forzar ? crearRespaldo('programado') : respaldoDiarioSiToca(getenv('RESPALDO_MOTIVO') === 'programado' ? 'programado' : 'automatico');

if (!$r['ok']) {
    fwrite(STDERR, 'No se pudo respaldar: ' . $r['error'] . PHP_EOL);
    exit(1);
}
echo isset($r['omitido']) ? $r['omitido'] . PHP_EOL : 'Respaldo creado: ' . $r['archivo'] . ' (' . tamanoLegible($r['bytes']) . ', SHA-256 ' . $r['sha256'] . ')' . PHP_EOL;
exit(0);
