<?php
/**
 * Arranque de los endpoints entre instalaciones (api/sincronizacion/recibir.php y cambios.php).
 * Igual que app/nucleo/inicio.php pero sin sesión ni cookie: quien llama es otro servidor y se
 * autentica con la firma HMAC del lote (sincVerificarPeticion).
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../nucleo/seguridad.php';

configurarErrores();
enviarCabecerasSeguridad();

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../nucleo/funciones.php';
require_once __DIR__ . '/../../nucleo/configuracion.php';
require_once __DIR__ . '/sincronizacion.php';
