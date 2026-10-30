<?php
/**
 * Cerrar sesión (solo por POST con token CSRF).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValido()) {
    redirigir(rutaInicio());
}

registrarBitacora('usuarios', usuarioActual()['id'], 'salida');

// Notificaciones push: se cancela la suscripción que este dispositivo registró durante la sesión
require_once __DIR__ . '/../app/servicios/cuenta/push.php';
quitarSuscripcionDeSesion();

$_SESSION = [];
session_regenerate_id(true);
session_destroy();

redirigir('/acceso/');
