<?php
/**
 * Marco de las pantallas de acceso (iniciar sesión, crear cuenta):
 * ventana con el formulario a la izquierda y la pintura a la derecha, sobre fondo liso.
 * Variables: $tituloPagina; opcionales $claseContenido (clase extra del contenido),
 * $hojasAcceso y $guionesAcceso (rutas dentro de assets/ que la pantalla necesita además).
 */
$tituloPagina = $tituloPagina ?? 'Acceso';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($tituloPagina) ?> · Expedientes Clínicos</title>
  <link rel="stylesheet" href="<?= recurso("css/estilos.css") ?>">
  <link rel="stylesheet" href="<?= recurso("css/modulos/movil.css") ?>">
  <link rel="stylesheet" href="<?= recurso("css/modulos/controles.css") ?>">
<?php foreach ($hojasAcceso ?? [] as $hoja): ?>
  <link rel="stylesheet" href="<?= recurso($hoja) ?>">
<?php endforeach; ?>
</head>
<body class="acceso" data-base="<?= URL_BASE ?>">

<main class="acceso__ventana">
  <section class="acceso__panel">
    <span class="acceso__marca">Expedientes Clínicos</span>
    <div class="acceso__contenido<?= !empty($claseContenido) ? ' ' . e($claseContenido) : '' ?>">
