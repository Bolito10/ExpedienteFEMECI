<?php
/**
 * Camino inverso (central → local): un consultorio pide los cambios del diario desde su cursor.
 *
 * Misma verificación que recibir.php (firma, marca de tiempo, nonce, tamaño y frecuencia).
 * Solo entrega el diario de la institución dueña del token. Entrega lo que capturó el central y lo que llegó de otros consultorios, nunca lo que vino del
 * propio solicitante. Se detiene en el primer cambio de hace menos de un minuto, para no saltarse
 * uno de una transacción que todavía no termina.
 *   POST {nodo, desde, limite} → {ok, nodo, cambios: [{ref, tabla, uuid, operacion, datos, creado_en}], hasta, mas}
 */
require_once __DIR__ . '/../../app/servicios/sincronizacion/arranque.php';

[$nodo, $nonce, $datos, $token, $institucion] = sincVerificarPeticion();

$desde  = max(0, (int) ($datos['desde'] ?? 0));
$limite = min(SINC_LOTE_FILAS, max(1, (int) ($datos['limite'] ?? SINC_LOTE_FILAS)));

$consulta = db()->prepare(
    'SELECT id, tabla, uuid, operacion, datos, creado_en, origen,
            creado_en < DATE_SUB(NOW(6), INTERVAL ? SECOND) AS asentado
       FROM sincronizacion_cola WHERE id > ? AND institucion_id <=> ? ORDER BY id LIMIT ' . $limite
);
$consulta->execute([SINC_RETRASO_DIARIO, $desde, $institucion]);
$filas = $consulta->fetchAll();

$cambios = [];
$hasta = $desde;
$bytes = 0;
$completo = true;
$porTamano = false;
foreach ($filas as $f) {
    if (!$f['asentado']) {
        $completo = false;
        break;
    }
    if ($cambios && $bytes + strlen((string) $f['datos']) > SINC_LOTE_BYTES) {
        $porTamano = true;
        break;
    }
    $hasta = (int) $f['id'];
    if ($f['origen'] === $nodo || $f['datos'] === null) {
        continue;   // lo que mandó el propio solicitante, o una fila que no cupo en max_allowed_packet
    }
    $bytes += strlen($f['datos']);
    $cambios[] = [
        'ref'       => (string) $f['id'],
        'tabla'     => $f['tabla'],
        'uuid'      => $f['uuid'],
        'operacion' => $f['operacion'],
        'datos'     => json_decode($f['datos'], true),
        'creado_en' => $f['creado_en'],
    ];
}

if ($cambios) {
    registrarBitacora('sincronizacion', 0, 'acceso', 'cambios', null,
        count($cambios) . ' cambios entregados a ' . sincNodoCorto($nodo));
}
sincResponder(200, [
    'ok'      => true,
    'nodo'    => sincNodo(),
    'cambios' => $cambios,
    'hasta'   => $hasta,
    'mas'     => $porTamano || ($completo && count($filas) === $limite),
], $nonce, $token);
