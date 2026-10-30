<?php
/**
 * Tarea periódica de las notificaciones push (solo línea de comandos). Hace lo mismo que el sondeo de cada
 * minuto de las sesiones abiertas, pero sin depender de que haya alguien con la app abierta:
 *   recordatorios de cita con la anticipación de cada usuario, avisos por umbral y despacho de la cola
 *   (incluidos los reintentos de lo que no salió por falta de internet).
 *
 * Programarla cada minuto en el Programador de tareas de Windows:
 *   Programa: C:\xampp\php\php.exe
 *   Argumentos: C:\xampp\htdocs\ExpedienteFEMECI\app\tareas\push.php
 * O en Linux (cron):  * * * * * php /ruta/app/tareas/push.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../nucleo/seguridad.php';
configurarErrores();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../nucleo/funciones.php';
require_once __DIR__ . '/../nucleo/configuracion.php';
require_once __DIR__ . '/../servicios/cuenta/notificaciones.php';
require_once __DIR__ . '/../servicios/cuenta/push.php';

$candado = fopen(sys_get_temp_dir() . '/expediente_push_tarea.lock', 'c');
if (!$candado || !flock($candado, LOCK_EX | LOCK_NB)) {
    exit(0);   // la ejecución anterior sigue en curso
}
tareasPeriodicasPush();
$total = ['enviados' => 0, 'reintentos' => 0, 'descartados' => 0, 'bajas' => 0];
for ($vuelta = 0; $vuelta < 8; $vuelta++) {   // hasta 8 lotes por ejecución
    $r = procesarColaPush();
    foreach ($r as $k => $v) {
        $total[$k] += $v;
    }
    if (array_sum($r) === 0 || $r['enviados'] === 0) {
        break;
    }
}
if (array_sum($total) > 0) {
    echo date('Y-m-d H:i:s') . ' push: ' . json_encode($total) . "\n";
}
flock($candado, LOCK_UN);
fclose($candado);
