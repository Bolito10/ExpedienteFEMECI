<?php
/**
 * Asistente de IA a pantalla completa (se abre desde el icono de conversación de la barra lateral).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';

if (!puede('asistente')) {
    mensajeFlash('error', 'Tu rol no tiene acceso al asistente.');
    redirigir('/pacientes/');
}

$tituloPagina      = 'Asistente de IA';
$seccionActiva     = 'asistente';
$clavePresencia    = 'asistente:pagina';
$mostrarBanner     = false;
$claseContenido    = 'pagina--asistente';
$asistenteEnPagina = true;
$ocultarCompartir  = true;
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
require __DIR__ . '/../app/vistas/componentes/asistente_panel.php';
require __DIR__ . '/../app/vistas/plantilla/pie.php';
