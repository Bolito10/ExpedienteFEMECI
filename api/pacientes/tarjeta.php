<?php
/**
 * Fragmento HTML de la tarjeta del paciente para el menú de clic izquierdo.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
exigirPermiso('ver_expediente');
require_once __DIR__ . '/../../app/vistas/componentes/tarjeta_paciente.php';

$paciente = buscarPaciente((int) ($_GET['id'] ?? 0));

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

if (!$paciente) {
    http_response_code(404);
    exit;
}

echo tarjetaPaciente($paciente, 'perfil--flotante');
