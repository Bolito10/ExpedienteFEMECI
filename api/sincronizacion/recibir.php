<?php
/**
 * Receptor del servidor central: otra instalación (un consultorio) manda un lote de cambios.
 *
 * Sin sesión: exige firma HMAC-SHA256 con el token compartido, marca de tiempo reciente, nonce de un
 * solo uso, límite de tamaño (16 MB) y de frecuencia. Aplica el lote en una transacción, idempotente
 * por uuid (la última escritura gana por sinc_modificado_en; el historial clínico solo se agrega) y
 * responde, firmado, el acuse de cada cambio. Todo se aplica en la institución dueña del token
 * (la huella X-Sinc-Clave la identifica); un registro de otra institución se rechaza.
 *   POST {nodo, cambios: [{ref, tabla, uuid, operacion, datos, creado_en}]}
 *   → {ok, nodo, acuses: {ref: aplicado|omitido|descartado}, errores: {ref: motivo}}
 */
require_once __DIR__ . '/../../app/servicios/sincronizacion/arranque.php';

set_time_limit(120);
[$nodo, $nonce, $datos, $token, $institucion] = sincVerificarPeticion();

$cambios = $datos['cambios'] ?? null;
if (!is_array($cambios) || !array_is_list($cambios) || count($cambios) > SINC_MAX_CAMBIOS) {
    sincFallar(400, 'El lote debe traer una lista de hasta ' . SINC_MAX_CAMBIOS . ' cambios.');
}

$resultado = sincAplicarCambios($cambios, $nodo, $institucion);
sincResponder(200, [
    'ok'      => true,
    'nodo'    => sincNodo(),
    'acuses'  => (object) $resultado['acuses'],
    'errores' => (object) $resultado['errores'],
], $nonce, $token);
