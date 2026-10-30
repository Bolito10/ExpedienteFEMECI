<?php
/**
 * Encabezado del portal del paciente: la misma ventana que el resto del expediente (marco, barra lateral
 * y tarjeta de contenido), pero con una barra lateral mínima (su expediente, sus citas y cerrar sesión)
 * y sin pestañas, buscador, asistente ni accesos al resto del sistema.
 * Requiere haber incluido antes app/nucleo/inicio.php.
 *
 * Variables que puede definir la página:
 *   $tituloPagina   Texto de la pestaña.
 *   $seccionPortal  'expediente' | 'citas' | 'cuenta' (enlace resaltado de la barra lateral).
 *   $migas          [['texto' => ..., 'url' => ...], ...] ruta en la barra superior (la última sin url).
 *   $mostrarBanner  false para ocultar la portada (por defecto true).
 *   $claseContenido Clase extra para <main>.
 */
$tituloPagina   = $tituloPagina   ?? 'Mi expediente';
$seccionPortal  = $seccionPortal  ?? 'expediente';
$migas          = $migas          ?? [['texto' => $tituloPagina]];
$mostrarBanner  = $mostrarBanner  ?? true;
$claseContenido = $claseContenido ?? '';
$usuario        = usuarioActual();
// La misma portada que ve el personal en el perfil del paciente
$portadaPerfil  = config('portada_pacientes-ver');
$altoPortada    = (int) config('portada_alto_pacientes-ver');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= tokenCsrf() ?>">
  <title><?= e($tituloPagina) ?> · Expedientes Clínicos</title>
  <link rel="stylesheet" href="<?= recurso("css/estilos.css") ?>">
  <?php foreach (glob(__DIR__ . "/../../../assets/css/modulos/*.css") as $hoja): ?>
    <link rel="stylesheet" href="<?= recurso("css/modulos/" . basename($hoja)) ?>">
  <?php endforeach; ?>
  <script nonce="<?= nonceCsp() ?>">
    // Aplica el estado guardado de la barra lateral antes de pintar, para evitar parpadeo.
    try { if (localStorage.getItem('lateral-oculta') === '1') document.documentElement.classList.add('lateral-oculta'); } catch (e) {}
  </script>
</head>
<body class="portal" data-base="<?= URL_BASE ?>">

<div class="app">

  <aside class="barra-lateral" id="barra-lateral" aria-label="Portal del paciente">
    <div class="barra-lateral__cabecera">
      <span class="barra-lateral__nombre">Expedientes Clínicos</span>
      <button type="button" class="boton-icono" data-accion="alternar-lateral" title="Ocultar la barra lateral"><?= icono('panel') ?></button>
    </div>

    <nav class="barra-lateral__desplazable portal-lateral" aria-label="Mi portal">
      <a class="lateral-enlace<?= $seccionPortal === 'expediente' ? ' activo' : '' ?>" href="<?= URL_BASE ?>/portal/"<?= $seccionPortal === 'expediente' ? ' aria-current="page"' : '' ?>><?= icono('usuario') ?><span>Mi expediente</span></a>
      <a class="lateral-enlace<?= $seccionPortal === 'citas' ? ' activo' : '' ?>" href="<?= URL_BASE ?>/portal/citas.php"<?= $seccionPortal === 'citas' ? ' aria-current="page"' : '' ?>><?= icono('calendario') ?><span>Mis citas</span></a>
      <a class="lateral-enlace<?= $seccionPortal === 'cuenta' ? ' activo' : '' ?>" href="<?= URL_BASE ?>/cuenta/"<?= $seccionPortal === 'cuenta' ? ' aria-current="page"' : '' ?>><?= icono('candado') ?><span>Mi cuenta</span></a>
    </nav>

    <div class="barra-lateral__pie">
      <form class="barra-lateral__usuario" method="post" action="<?= URL_BASE ?>/acceso/salir.php">
        <?= campoCsrf() ?>
        <?= avatar((int) $usuario['id'], $usuario['nombre'], $usuario['foto'] ?? null) ?>
        <span class="barra-lateral__persona"><?= e($usuario['nombre']) ?></span>
        <button type="submit" class="boton-icono" title="Cerrar sesión" aria-label="Cerrar sesión"><?= icono('salir') ?></button>
      </form>
    </div>
  </aside>

  <div class="velo" data-accion="cerrar-lateral" aria-hidden="true"></div>

  <div class="contenido">
    <div class="contenido__cuerpo">
    <div class="contenido__pagina">

    <header class="barra-superior">
      <button type="button" class="boton-icono barra-superior__menu" data-accion="alternar-lateral" title="Mostrar la barra lateral" aria-controls="barra-lateral"><?= icono('panel') ?></button>
      <nav class="migas" aria-label="Ruta">
        <?php foreach ($migas as $i => $miga): ?>
          <?php if ($i > 0): ?><span class="migas__separador">/</span><?php endif; ?>
          <?php if (!empty($miga['url'])): ?>
            <a class="migas__enlace" href="<?= URL_BASE . $miga['url'] ?>"><?= e($miga['texto']) ?></a>
          <?php else: ?>
            <span class="migas__actual"><?= e($miga['texto']) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
        <span class="migas__lectura">Solo lectura</span>
      </nav>
      <div class="barra-superior__derecha">
        <button type="button" class="boton-icono" data-imprimir title="Imprimir" aria-label="Imprimir"><?= icono('documento') ?></button>
      </div>
    </header>

    <?php if ($mostrarBanner): ?>
      <div class="banner" role="img" aria-label="Portada" style="<?= $portadaPerfil ? '--banner-imagen: url(' . e(URL_BASE . '/' . $portadaPerfil) . ');' : '' ?><?= $altoPortada ? ' --alto: ' . $altoPortada . 'px;' : '' ?>"></div>
    <?php endif; ?>

    <main class="pagina portal__pagina <?= e($claseContenido) ?>">
