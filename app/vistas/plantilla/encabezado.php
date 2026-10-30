<?php
/**
 * Encabezado común: barra lateral, barra de pestañas, barra de ruta y banner.
 * Requiere haber incluido antes app/nucleo/inicio.php.
 *
 * Variables que puede definir la página:
 *   $tituloPagina     Texto de la pestaña.
 *   $seccionActiva    Clave del elemento de la barra lateral resaltado ('pacientes', 'asistente').
 *   $migas            [['texto' => ..., 'url' => ...], ...] ruta en la barra superior (la última sin url).
 *   $mostrarBanner    false para ocultar el banner (por defecto true).
 *   $bannerAlto       true para el banner alto de las páginas de base de datos.
 *   $bannerImagen     imagen del banner dentro de assets/img (por defecto la de histología).
 *   $claseContenido   Clase extra para <main> (p. ej. 'pagina--ancha').
 *   $notificacion     ['tipo' => 'exito'|'error', 'texto' => ...] aviso que se muestra en pie.php (opcional).
 *   $pacienteActual   id del paciente abierto; el asistente de IA puede usarlo como contexto (opcional).
 *   $paginaActual     página del espacio de trabajo abierta (paginas/ver.php): cambia la barra superior
 *                     por la de Notion (Última edición, Compartir, enlace, favorito).
 *   $clavePresencia   sección para las burbujas de quién está aquí (por defecto, la ruta del script).
 */
require_once __DIR__ . '/../../servicios/espacio/paginas.php';
require_once __DIR__ . '/../../servicios/clinica/agenda.php';

$tituloPagina   = $tituloPagina   ?? 'Expediente clínico';
$seccionActiva  = $seccionActiva  ?? '';
$migas          = $migas          ?? [['texto' => $tituloPagina]];
$mostrarBanner  = $mostrarBanner  ?? true;
$claseContenido = $claseContenido ?? '';
$paginaActual   = $paginaActual   ?? null;
$usuario        = usuarioActual();
$clavePresencia = $clavePresencia ?? ('ruta:' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(
    basename(dirname($_SERVER['SCRIPT_NAME'])) . '-' . basename($_SERVER['SCRIPT_NAME'], '.php')
)), '-'));

// Página activa dentro del grupo Pacientes (para resaltar el enlace de la barra lateral)
$script     = basename($_SERVER['SCRIPT_NAME']);
$enListado  = $seccionActiva === 'pacientes' && $script === 'index.php';
$enRegistro = $seccionActiva === 'pacientes' && $script === 'registro.php' && empty($_GET['id']);
$arbol      = arbolPaginas((int) $usuario['id']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
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
<body data-base="<?= URL_BASE ?>" data-usuario="<?= (int) $usuario['id'] ?>" data-usuario-nombre="<?= e($usuario['nombre']) ?>" data-usuario-foto="<?= !empty($usuario['foto']) ? e(URL_BASE . '/' . $usuario['foto']) : '' ?>" data-titulo="<?= e($tituloPagina) ?>" data-presencia="<?= e($clavePresencia) ?>"<?= !empty($pacienteActual) ? ' data-paciente="' . (int) $pacienteActual . '" data-paciente-nombre="' . e($tituloPagina) . '"' : '' ?><?= $paginaActual ? ' data-pagina="' . (int) $paginaActual['id'] . '" data-pagina-tipo="' . e($paginaActual['tipo']) . '"' : '' ?>>

<div class="app">

  <!-- Barra lateral: plana sobre el marco de la ventana; el contenido va en la tarjeta de la derecha -->
  <aside class="barra-lateral" id="barra-lateral" aria-label="Navegación principal">
    <div class="barra-lateral__cabecera">
      <span class="barra-lateral__nombre">Expedientes Clínicos</span>
      <button type="button" class="boton-icono" data-accion="alternar-lateral" title="Ocultar la barra lateral"><?= icono('panel') ?></button>
      <button type="button" class="boton-icono" data-pagina-nueva="nota" data-seccion="privado" title="Nueva página"><?= iconoRelleno('editar') ?></button>
    </div>

    <!-- Foto de la clínica: arriba, antes del buscador y de Inicio (la cambia el administrador) -->
    <div class="lateral-clinica">
      <?php $fotoClinica = config('foto_clinica'); ?>
      <?php if ($fotoClinica || puede('configurar')): ?>
        <figure class="clinica<?= $fotoClinica ? '' : ' clinica--vacia' ?>" data-clinica>
          <?php if ($fotoClinica): ?>
            <img src="<?= URL_BASE . '/' . e($fotoClinica) ?>" alt="Foto de la clínica" data-clinica-imagen>
          <?php endif; ?>
          <?php if (puede('configurar')): ?>
            <label class="clinica__editar" title="<?= $fotoClinica ? 'Cambiar la foto' : 'Agregar la foto de la clínica' ?>">
              <?= icono('imagen') ?><span><?= $fotoClinica ? 'Cambiar foto' : 'Agregar foto de la clínica' ?></span>
              <input type="file" accept="image/jpeg,image/png,image/webp" data-clinica-archivo hidden>
            </label>
          <?php endif; ?>
        </figure>
      <?php endif; ?>
    </div>

    <button type="button" class="lateral-buscar" data-abrir-busqueda>
      <span>Buscar o preguntar</span><kbd>Ctrl+K</kbd>
    </button>

    <div class="lateral-inicio">
      <a class="lateral-inicio__pastilla<?= $seccionActiva === 'pacientes' ? ' activo' : '' ?>" href="<?= URL_BASE ?>/pacientes/"><?= iconoRelleno('casa') ?><span>Inicio</span></a>
      <a class="boton-icono<?= $seccionActiva === 'asistente' ? ' activo' : '' ?>" href="<?= URL_BASE ?>/asistente/" title="Asistente de IA"><?= iconoRelleno('mensaje') ?></a>
      <?php if (puede('agenda')): ?>
        <a class="boton-icono<?= $seccionActiva === 'agenda' ? ' activo' : '' ?>" href="<?= URL_BASE ?>/agenda/" title="Agenda"><?= iconoRelleno('calendario') ?></a>
      <?php endif; ?>
      <!-- Notificaciones: assets/js/modulos/notificaciones.js abre la bandeja y mantiene el punto de "sin leer" -->
      <button type="button" class="boton-icono campana" data-notificaciones-abrir aria-haspopup="dialog" aria-expanded="false" title="Notificaciones"><svg class="ir ir--campana" viewBox="0 0 24 24" aria-hidden="true"><g class="a-campana"><rect class="m" x="10.9" y="1.6" width="2.2" height="2.6" rx="1.1"/><path class="b" d="M12 3.2a6 6 0 0 0-6 6v3.4l-1.5 2.5a1.2 1.2 0 0 0 1 1.9h13a1.2 1.2 0 0 0 1-1.9L18 12.6V9.2a6 6 0 0 0-6-6z"/></g><path class="d a-badajo" d="M9.5 18.2h5a2.5 2.5 0 0 1-5 0z"/></svg><span class="campana__punto" data-notificaciones-punto hidden></span></button>
    </div>

    <!-- Cada sección se pliega con su título (se recuerda en este navegador) -->
    <div class="barra-lateral__desplazable">
      <script type="application/json" id="arbol-paginas"><?= json_encode($arbol, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

      <?php if (puede('agenda')): $proximas = proximasCitas((int) $usuario['id'], 4); ?>
        <div class="lateral-seccion" data-plegable="eventos">
          <div class="lateral-seccion__titulo">
            <button type="button" class="lateral-seccion__alternar" aria-expanded="true">Próximos eventos<?= icono('flecha_abajo', 'lateral-seccion__chevron') ?></button>
            <a class="boton-icono" href="<?= URL_BASE ?>/agenda/?nueva=1" title="Nueva cita"><?= icono('agregar') ?></a>
          </div>
          <div class="lateral-seccion__contenido">
            <div class="lateral-eventos">
              <?php if ($proximas):
                $diaAnterior = null;
                foreach ($proximas as $c):
                  $inicioCita = str_replace('T', ' ', $c['inicio']);
                  $dia = etiquetaDiaCita($inicioCita);
              ?>
                <?php if ($dia !== $diaAnterior): $diaAnterior = $dia; ?><span class="lateral-eventos__dia"><?= e($dia) ?></span><?php endif; ?>
                <a class="lateral-evento" href="<?= URL_BASE ?>/agenda/?fecha=<?= substr($c['inicio'], 0, 10) ?>&amp;cita=<?= $c['id'] ?>">
                  <span class="lateral-evento__hora"><?= e(substr($inicioCita, 11, 5)) ?></span>
                  <span class="lateral-evento__titulo"><?= e($c['paciente'] ?: ($c['motivo'] ?: 'Cita')) ?></span>
                </a>
              <?php endforeach; else: ?>
                <a class="lateral-enlace lateral-enlace--tenue" href="<?= URL_BASE ?>/agenda/"><?= icono('calendario') ?><span>No hay eventos próximos</span></a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="lateral-seccion" data-seccion-arbol="favoritos" data-plegable="favoritos"<?= $arbol['favoritos'] ? '' : ' hidden' ?>>
        <div class="lateral-seccion__titulo">
          <button type="button" class="lateral-seccion__alternar" aria-expanded="true">Favoritos<?= icono('flecha_abajo', 'lateral-seccion__chevron') ?></button>
        </div>
        <div class="lateral-seccion__contenido">
          <div class="arbol" data-arbol="favoritos"></div>
        </div>
      </div>

      <div class="lateral-seccion" data-plegable="expediente">
        <div class="lateral-seccion__titulo">
          <button type="button" class="lateral-seccion__alternar" aria-expanded="true">Expediente<?= icono('flecha_abajo', 'lateral-seccion__chevron') ?></button>
          <button type="button" class="boton-icono" data-pagina-paciente-menu data-importar="<?= puede('importar_exportar') ? '1' : '0' ?>" data-registrar="<?= puede('editar_paciente') ? '1' : '0' ?>" aria-haspopup="menu" title="Registrar paciente, crear o importar"><?= icono('agregar') ?></button>
        </div>
        <div class="lateral-seccion__contenido">
          <a class="lateral-enlace<?= $seccionActiva === 'pacientes' ? ' activo' : '' ?>" href="<?= URL_BASE ?>/pacientes/"<?= $enListado ? ' aria-current="page"' : '' ?>><?= iconoRelleno('usuarios') ?><span>Pacientes</span></a>
        </div>
      </div>

      <div class="lateral-seccion" data-seccion-arbol="privado" data-plegable="privado">
        <div class="lateral-seccion__titulo">
          <button type="button" class="lateral-seccion__alternar" aria-expanded="true">Privado<?= icono('flecha_abajo', 'lateral-seccion__chevron') ?></button>
          <button type="button" class="boton-icono" data-pagina-menu-nueva data-seccion="privado" title="Agregar una página"><?= icono('agregar') ?></button>
        </div>
        <div class="lateral-seccion__contenido">
          <div class="arbol" data-arbol="privado"></div>
          <button type="button" class="lateral-enlace lateral-enlace--tenue" data-pagina-menu-nueva data-seccion="privado"><?= icono('agregar') ?><span>Agregar página nueva</span></button>
        </div>
      </div>

      <div class="lateral-seccion" data-seccion-arbol="compartido" data-plegable="compartido">
        <div class="lateral-seccion__titulo">
          <button type="button" class="lateral-seccion__alternar" aria-expanded="true">Compartido<?= icono('flecha_abajo', 'lateral-seccion__chevron') ?></button>
          <button type="button" class="boton-icono" data-pagina-menu-nueva data-seccion="compartido" title="Agregar una página para el equipo"><?= icono('agregar') ?></button>
        </div>
        <div class="lateral-seccion__contenido">
          <div class="arbol" data-arbol="compartido"></div>
          <button type="button" class="lateral-enlace lateral-enlace--tenue" data-pagina-menu-nueva data-seccion="compartido"><?= icono('agregar') ?><span>Empieza a colaborar</span></button>
        </div>
      </div>

    </div>

    <div class="barra-lateral__pie">

      <!-- Recientes y Papelera se ocultan con la misma flecha que las demás secciones; el panel emergente queda
           fuera del contenido plegable para que no lo recorte -->
      <div class="lateral-desplegable">
        <div class="lateral-seccion lateral-seccion--pie" data-plegable="pie">
          <div class="lateral-seccion__titulo">
            <button type="button" class="lateral-seccion__alternar" aria-expanded="true">Más<?= icono('flecha_abajo', 'lateral-seccion__chevron') ?></button>
          </div>
          <div class="lateral-seccion__contenido"><div>
            <button type="button" class="lateral-enlace" data-lateral-lista="recientes" aria-haspopup="menu" aria-expanded="false"><?= iconoRelleno('reloj') ?><span>Recientes</span></button>
            <button type="button" class="lateral-enlace" data-lateral-lista="papelera" aria-haspopup="menu" aria-expanded="false"><?= iconoRelleno('papelera') ?><span>Papelera</span></button>
            <a class="lateral-enlace<?= $seccionActiva === 'configuracion' || $seccionActiva === 'personas' ? ' activo' : '' ?>" href="<?= URL_BASE ?>/configuracion/"><?= iconoRelleno('engrane') ?><span>Configuración</span></a>
          </div></div>
        </div>
        <div class="lateral-popover" role="menu" data-lateral-popover hidden></div>
      </div>

      <form class="barra-lateral__usuario" method="post" action="<?= URL_BASE ?>/acceso/salir.php">
        <?= campoCsrf() ?>
        <label class="barra-lateral__foto" title="Cambiar foto de perfil">
          <?= avatar((int) $usuario['id'], $usuario['nombre'], $usuario['foto'] ?? null) ?>
          <input type="file" accept="image/jpeg,image/png,image/webp" data-foto-perfil hidden>
        </label>
        <a class="barra-lateral__persona" href="<?= URL_BASE ?>/configuracion/" title="Configuración: tu cuenta, notificaciones y la clínica"><?= e($usuario['nombre']) ?></a>
        <button type="submit" class="boton-icono" title="Cerrar sesión"><?= icono('salir') ?></button>
      </form>
    </div>
  </aside>

  <div class="velo" data-accion="cerrar-lateral" aria-hidden="true"></div>

  <!-- Contenido: tarjeta redondeada; debajo de las pestañas, la página y (si está abierto) el asistente -->
  <div class="contenido">

    <!-- Barra de pestañas (funcional: app.js mantiene las pestañas abiertas en la sesión del navegador) -->
    <div class="pestanas">
      <button type="button" class="boton-icono pestanas__lateral" data-accion="alternar-lateral" title="Mostrar la barra lateral"><?= icono('panel') ?></button>
      <button type="button" class="boton-icono" data-pestanas-atras title="Atrás"><?= icono('atras') ?></button>
      <button type="button" class="boton-icono" data-pestanas-adelante title="Adelante"><?= icono('adelante') ?></button>
      <div class="pestanas__lista" role="tablist" aria-label="Pestañas abiertas" data-pestanas-lista>
        <span class="pestana activa" role="tab" aria-selected="true">
          <?= icono('documento') ?><span class="pestana__titulo"><?= e($tituloPagina) ?></span>
        </span>
      </div>
      <button type="button" class="boton-icono" data-pestanas-nueva title="Nueva pestaña"><?= icono('agregar') ?></button>
    </div>

    <div class="contenido__cuerpo">
    <div class="contenido__pagina" data-desplazable>

    <header class="barra-superior">
      <?php if (!$paginaActual): ?>
        <span class="barra-superior__icono" aria-hidden="true"><?= icono('documento') ?></span>
      <?php endif; ?>

      <nav class="migas" aria-label="Ruta">
        <?php foreach ($migas as $i => $miga): ?>
          <?php if ($i > 0): ?><span class="migas__separador">/</span><?php endif; ?>
          <?php if (!empty($miga['url'])): ?>
            <a class="migas__enlace" href="<?= URL_BASE . $miga['url'] ?>"><?= e($miga['texto']) ?></a>
          <?php else: ?>
            <span class="migas__actual"<?= $paginaActual ? ' data-miga-titulo' : '' ?>><?= e($miga['texto']) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($paginaActual): ?>
          <button type="button" class="migas__privacidad" data-compartir-abrir title="Quién tiene acceso">
            <?= icono($paginaActual['compartida_efectiva'] ? 'usuarios' : 'candado') ?>
            <span data-privacidad-texto><?= $paginaActual['compartida_efectiva'] ? 'Compartida' : 'Privada' ?></span>
            <?= icono('flecha_abajo', 'migas__chevron') ?>
          </button>
          <?php if (!nivelAlcanza($paginaActual, 'editar')): ?><span class="migas__lectura">Solo lectura</span><?php endif; ?>
        <?php endif; ?>
      </nav>

      <div class="barra-superior__derecha">
        <!-- Quién está en esta sección y quién edita (presencia real, se renueva cada 15 s) -->
        <div class="presencia" data-presencia-burbujas aria-label="Personas en esta página"></div>

        <?php if ($paginaActual): ?>
          <div class="ancla-popover">
            <button type="button" class="barra-superior__edicion" data-actividad-abrir aria-haspopup="dialog" aria-expanded="false">
              Última edición <span data-ultima-edicion data-fecha="<?= e(date('c', strtotime($paginaActual['actualizado_en']))) ?>"><?= e(str_replace('hace un momento', 'ahora mismo', hace($paginaActual['actualizado_en']))) ?></span>
            </button>
          </div>
          <div class="ancla-popover">
            <button type="button" class="barra-superior__texto" data-compartir-abrir aria-haspopup="dialog" aria-expanded="false"><?= icono('candado') ?> Compartir</button>
          </div>
          <button type="button" class="boton-icono" data-copiar-enlace title="Copiar enlace"><?= icono('enlace') ?></button>
          <button type="button" class="boton-icono boton-favorito<?= $paginaActual['favorita'] ? ' activo' : '' ?>" data-favorita aria-pressed="<?= $paginaActual['favorita'] ? 'true' : 'false' ?>" title="<?= $paginaActual['favorita'] ? 'Quitar de favoritos' : 'Agregar a favoritos' ?>"><?= icono('estrella') ?></button>
        <?php elseif (empty($ocultarCompartir)): ?>
          <button type="button" class="boton boton--compartir" data-compartir title="Copiar el enlace de esta página">
            <?= icono('candado') ?> Compartir
          </button>
        <?php endif; ?>
        <div class="menu-mas">
          <button type="button" class="boton-icono" data-menu-mas aria-haspopup="menu" aria-expanded="false" title="Más opciones"><?= icono('mas') ?></button>
          <div class="menu-mas__lista" role="menu" hidden>
            <?php if ($paginaActual && nivelAlcanza($paginaActual, 'completo') && !$paginaActual['en_papelera']): ?>
              <button type="button" role="menuitem" data-pagina-papelera><?= icono('papelera') ?> Mover a la papelera</button>
            <?php endif; ?>
            <button type="button" role="menuitem" data-pestanas-nueva><?= icono('agregar') ?> Nueva pestaña</button>
            <button type="button" role="menuitem" data-imprimir><?= icono('documento') ?> Imprimir</button>
          </div>
        </div>
      </div>
    </header>

    <?php if ($paginaActual && $paginaActual['en_papelera']): ?>
      <!-- Página en la papelera: se puede restaurar (o eliminar definitivamente si eres quien la creó) -->
      <div class="aviso-papelera" role="status">
        <span><?= $paginaActual['eliminado_en'] !== null
            ? e(($paginaActual['eliminado_por_nombre'] ?? 'Alguien') . ' movió esta página a la papelera ' . hace($paginaActual['eliminado_en']) . '.')
            : 'Esta página está dentro de una página que se movió a la papelera.' ?></span>
        <?php if ($paginaActual['eliminado_en'] !== null && nivelAlcanza($paginaActual, 'completo')): ?>
          <span class="aviso-papelera__acciones">
            <button type="button" data-pagina-restaurar><?= icono('reabrir') ?> Restaurar página</button>
            <?php if ((int) $paginaActual['propietario_id'] === (int) $usuario['id']): ?>
              <button type="button" data-pagina-eliminar><?= icono('papelera') ?> Eliminar de forma permanente</button>
            <?php endif; ?>
          </span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($mostrarBanner):
      // Clave de la portada de esta sección: la ruta del script (p. ej. "pacientes-index")
      $clavePortada  = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(basename(dirname($_SERVER['SCRIPT_NAME'])) . '-' . basename($_SERVER['SCRIPT_NAME'], '.php'))), '-');
      $portadaPropia = config('portada_' . $clavePortada) ?: ($clavePortada === 'pacientes-index' ? config('portada_pacientes') : null);
      $urlBanner = $portadaPropia ? URL_BASE . '/' . $portadaPropia : (!empty($bannerImagen) ? URL_BASE . '/assets/img/' . $bannerImagen : null);
    ?>
      <?php $altoPortada = (int) config('portada_alto_' . $clavePortada); ?>
      <div class="banner<?= !empty($bannerAlto) ? ' banner--alto' : '' ?>" role="img" aria-label="Portada" style="<?= $urlBanner ? '--banner-imagen: url(' . e($urlBanner) . ');' : '' ?><?= $altoPortada ? ' --alto: ' . $altoPortada . 'px;' : '' ?>">
        <?php if (puede('configurar')): ?>
          <div class="portada__acciones">
            <button type="button" data-portada-cambiar data-destino="seccion" data-clave="<?= e($clavePortada) ?>"<?= $portadaPropia ? ' data-tiene="1"' : '' ?>><?= icono('imagen') ?> Cambiar portada</button>
            <?php if ($altoPortada): ?>
              <button type="button" data-portada-alto-original data-destino="seccion" data-clave="<?= e($clavePortada) ?>">Tamaño predeterminado</button>
            <?php endif; ?>
            <?php if ($portadaPropia): ?>
              <button type="button" data-portada-quitar data-destino="seccion" data-clave="<?= e($clavePortada) ?>">Quitar</button>
            <?php endif; ?>
          </div>
          <div class="portada__tirador" data-portada-tirador data-destino="seccion" data-clave="<?= e($clavePortada) ?>" role="separator" aria-orientation="horizontal" aria-label="Arrastra para cambiar el alto de la portada" tabindex="0"></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <main class="pagina <?= e($claseContenido) ?>">
