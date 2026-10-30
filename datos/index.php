<?php
/**
 * Importar y exportar: conversión de bases de datos médicas entre formatos comunes
 * (CSV, TSV, XLSX, JSON, XML, SQL de MySQL/PostgreSQL/SQLite, SQLite, HL7 v2 y HL7 FHIR).
 * Cada conversión queda registrada con la huella SHA-256 del archivo (NOM-024-SSA3-2012).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/conversion/registro.php';
require_once __DIR__ . '/../app/servicios/espacio/paginas.php';
exigirPermiso('importar_exportar');

$usuarioId = (int) usuarioActual()['id'];

// Bases de datos del espacio de trabajo que esta persona puede ver (para exportarlas)
$espacio = array_filter(paginasDelEspacio($usuarioId), fn ($p) => $p['accesible'] && !$p['en_papelera'] && $p['tipo'] === 'base_datos');
$paginas = [];
if ($espacio) {
    $marcas = implode(',', array_fill(0, count($espacio), '?'));
    $consulta = db()->prepare("SELECT p.id, p.titulo, p.contenido, (SELECT COUNT(*) FROM pagina_filas f WHERE f.pagina_id = p.id) AS filas
                                 FROM paginas p WHERE p.id IN ($marcas) ORDER BY p.titulo, p.id");
    $consulta->execute(array_keys($espacio));
    foreach ($consulta as $p) {
        $paginas[] = [
            'id' => (int) $p['id'],
            'titulo' => trim((string) $p['titulo']) ?: 'Sin título',
            'filas' => (int) $p['filas'],
            'columnas' => array_map(fn ($c) => ['id' => $c['id'], 'nombre' => $c['nombre'], 'tipo' => $c['tipo']], columnasDe($p)),
        ];
    }
}

$totalPacientes = 0;
if (puede('ver_expediente')) {
    $c = db()->prepare('SELECT COUNT(*) FROM pacientes WHERE institucion_id = ? AND eliminado_en IS NULL');
    $c->execute([institucionId()]);
    $totalPacientes = (int) $c->fetchColumn();
}
$camposDestino = array_map(fn ($c) => ['texto' => $c['texto'], 'req' => $c['req']], camposDestinoPaciente());
$camposExportar = camposExportacionPaciente();
$formatos = array_map(fn ($f) => ['texto' => $f['texto'], 'grupo' => $f['grupo'], 'solo_pacientes' => !empty($f['solo_pacientes'])], FORMATOS_EXPORTACION);
$historial = historialConversiones();

$tituloPagina   = textoEditable('datos_titulo', 'Importar y exportar');
$seccionActiva  = 'datos';
$clavePresencia = 'datos:conversion';
$migas          = [['texto' => $tituloPagina]];
$claseContenido = 'pagina--ancha';
$bannerAlto     = true;
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina__cabecera">
  <h1 class="pagina__titulo"<?= atributosEditable('datos_titulo', 'Importar y exportar') ?>><?= e($tituloPagina) ?></h1>
  <p class="pagina__subtitulo"<?= atributosEditable('datos_subtitulo', 'Conversión de bases de datos · NOM-024-SSA3-2012') ?>><?= e(textoEditable('datos_subtitulo', 'Conversión de bases de datos · NOM-024-SSA3-2012')) ?></p>
</div>

<script type="application/json" id="datos-config"><?= json_encode([
    'camposDestino'  => $camposDestino,
    'tiposColumna'   => TIPOS_COLUMNA,
    'formatos'       => $formatos,
    'camposExportar' => $camposExportar,
    'paginas'        => $paginas,
    'totalPacientes' => $totalPacientes,
    'historial'      => $historial,
    'puede'          => ['importarPacientes' => puede('editar_paciente'), 'exportarPacientes' => puede('ver_expediente')],
    'todoElHistorial' => esAdministrador(),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<section class="bd datos" data-datos>
  <div class="bd__cabecera">
    <div class="personas__vistas" role="tablist" aria-label="Vistas">
      <button type="button" role="tab" class="personas__vista activa" aria-selected="true" data-datos-vista="importar"><?= iconoRelleno('editar') ?> Importar</button>
      <button type="button" role="tab" class="personas__vista" aria-selected="false" data-datos-vista="exportar"><?= iconoRelleno('documento') ?> Exportar</button>
      <button type="button" role="tab" class="personas__vista" aria-selected="false" data-datos-vista="historial"><?= iconoRelleno('reloj') ?> Historial <span data-datos-contador><?= count($historial) ?></span></button>
      <button type="button" role="tab" class="personas__vista" aria-selected="false" data-datos-vista="formatos"><?= iconoRelleno('plantilla') ?> Formatos</button>
    </div>
  </div>

  <!-- Importar -->
  <div class="datos__panel" data-datos-panel="importar">
    <label class="datos-zona" data-datos-zona>
      <input type="file" data-datos-archivo hidden
             accept=".csv,.tsv,.txt,.tab,.xlsx,.json,.ndjson,.xml,.sql,.dump,.sqlite,.sqlite3,.db,.hl7,.fhir">
      <span class="datos-zona__titulo">Arrastra un archivo aquí o <span class="datos-zona__enlace">elige uno</span></span>
      <span class="datos-zona__formatos">CSV y TSV · Excel (XLSX) · JSON · XML · volcados SQL de MySQL, MariaDB, PostgreSQL y SQLite · archivos SQLite · HL7 v2 · HL7 FHIR</span>
      <span class="datos-zona__limite">Hasta 20 MB. El archivo solo se lee: ningún SQL se ejecuta.</span>
    </label>
    <p class="datos-estado" data-datos-cargando hidden><span class="datos-estado__punto" aria-hidden="true"></span><span>Leyendo el archivo…</span></p>

    <div class="datos-analisis datos-ancho" data-datos-analisis hidden>
      <div class="datos-archivo">
        <div class="datos-archivo__texto">
          <span class="datos-archivo__nombre" data-datos-nombre></span>
          <span class="datos-archivo__meta" data-datos-meta></span>
        </div>
        <button type="button" class="boton boton--secundario" data-datos-otro>Cambiar archivo</button>
      </div>

      <dl class="datos-propiedades" data-datos-propiedades></dl>

      <h2 class="datos-subtitulo">Columnas</h2>
      <p class="datos-ayuda" data-datos-ayuda-mapeo></p>
      <div class="bd__marco">
        <table class="bd-tabla datos-mapeo" data-datos-mapeo></table>
      </div>

      <h2 class="datos-subtitulo">Vista previa</h2>
      <div class="bd__marco datos-vista datos-ancho">
        <table class="bd-tabla" data-datos-previa></table>
      </div>
      <p class="datos-ayuda" data-datos-previa-nota></p>

      <h2 class="datos-subtitulo">Validación</h2>
      <div class="datos-informe" data-datos-informe aria-live="polite"></div>

      <div class="datos-acciones">
        <button type="button" class="boton boton--fantasma" data-datos-descartar>Descartar</button>
        <button type="button" class="boton boton--primario" data-datos-importar disabled>Importar</button>
      </div>
    </div>

    <div class="datos-resultado" data-datos-resultado hidden></div>
  </div>

  <!-- Exportar -->
  <div class="datos__panel" data-datos-panel="exportar" hidden>
    <dl class="datos-propiedades" data-datos-exportar-propiedades></dl>
    <div class="datos-campos-cabecera">
      <h2 class="datos-subtitulo">Campos</h2>
      <span class="datos-campos-cabecera__acciones">
        <button type="button" class="datos-enlace" data-datos-campos-todos>Todos</button>
        <button type="button" class="datos-enlace" data-datos-campos-ninguno>Ninguno</button>
      </span>
    </div>
    <div class="datos-campos" data-datos-campos role="group" aria-label="Campos a exportar"></div>
    <p class="datos-ayuda" data-datos-exportar-nota></p>
    <div class="datos-acciones">
      <button type="button" class="boton boton--primario" data-datos-exportar>Exportar</button>
    </div>
  </div>

  <!-- Historial de conversiones -->
  <div class="datos__panel" data-datos-panel="historial" hidden>
    <p class="datos-ayuda"><?= esAdministrador() ? 'Todas las conversiones del establecimiento.' : 'Tus conversiones.' ?> Cada archivo queda identificado por su huella SHA-256; el registro no se puede modificar ni borrar.</p>
    <div class="bd__marco datos-ancho">
      <table class="bd-tabla datos-historial" data-datos-historial></table>
    </div>
    <p class="bd__vacio" data-datos-historial-vacio hidden>Aún no hay conversiones registradas.</p>
  </div>
  <!-- Perfiles de formato: cómo se lee y se escribe cada formato institucional (IMSS, DGIS…), sin tocar el código -->
  <div class="datos__panel" data-datos-panel="formatos" hidden>
    <div data-formatos data-editable="<?= puede('configurar') ? '1' : '0' ?>">
      <p class="datos-ayuda">Cada perfil describe cómo leer y escribir el formato de una institución. Al importar se elige solo el que corresponde al archivo. Si la institución cambia su formato, se duplica el perfil y se edita, sin tocar el código.</p>
      <div class="bd__marco datos-ancho">
        <table class="bd-tabla formatos-tabla" data-formatos-tabla></table>
      </div>
      <?php if (puede('configurar')): ?>
        <div class="datos-acciones">
          <label class="boton boton--secundario">Importar perfil (.json)<input type="file" accept=".json,application/json" data-formatos-importar hidden></label>
        </div>
      <?php endif; ?>
      <div class="formatos-editor" data-formatos-editor hidden>
        <div class="datos-campos-cabecera">
          <h2 class="datos-subtitulo" data-formatos-editor-titulo>Perfil</h2>
          <span class="datos-campos-cabecera__acciones"><button type="button" class="datos-enlace" data-formatos-descargar>Descargar .json</button></span>
        </div>
        <p class="datos-ayuda" data-formatos-aviso></p>
        <textarea class="area-ia formatos-editor__json" spellcheck="false" data-formatos-json aria-label="Perfil en JSON"></textarea>
        <ul class="formatos-editor__errores" data-formatos-errores hidden></ul>
        <div class="datos-acciones">
          <button type="button" class="boton boton--fantasma" data-formatos-cerrar>Cerrar</button>
          <button type="button" class="boton boton--primario" data-formatos-guardar>Guardar perfil</button>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
