<?php
/**
 * Asistente de IA. Dos formas con el mismo componente (assets/js/app.js → iniciarAsistente):
 *   - panel: botón circular abajo y panel a la derecha de la página, redimensionable.
 *   - pagina: asistente/index.php lo muestra a pantalla completa ($asistenteEnPagina = true).
 * Las respuestas van sobre el fondo, sin burbujas; el razonamiento va en la herramienta de
 * pensamiento (assets/js/razonamiento.js).
 */
if (!empty($asistenteYaMostrado) || !puede('asistente')) {
    return;
}
$modoAsistente = !empty($asistenteEnPagina) ? 'pagina' : 'panel';

// Sugerencias: las mismas en el panel y en la página completa; las de contexto dependen de lo que hay en pantalla
$sugerencias = [
    ['buscar', 'buscar', 'Busca lo que quieras'],
    ['documento', 'archivo', 'Analiza un archivo de texto'],
    ['escudo', 'normativa', 'Consulta la NOM-004 y la NOM-024'],
    ['plantilla', 'nota_clinica', 'Redacta una nota clínica'],
    ['tabla', 'base_datos_nueva', 'Crea una base de datos'],
];
if (!empty($pacienteActual)) {
    $sugerencias[] = ['usuario', 'paciente', 'Resume el expediente de este paciente'];
}
if (!empty($paginaActual) && $paginaActual['tipo'] === 'nota') {
    $sugerencias[] = ['lista', 'pagina', 'Resume esta página'];
} elseif (!empty($paginaActual) && $paginaActual['tipo'] === 'base_datos') {
    $sugerencias[] = ['tabla', 'base_datos', 'Ayúdame a configurar esta base de datos'];
}
$listaSugerencias = function (string $clase) use ($sugerencias): string {
    $html = '<ul class="' . $clase . '" data-sugerencias>';
    foreach ($sugerencias as [$icono, $clave, $texto]) {
        $html .= '<li><button type="button" data-sugerencia="' . $clave . '">' . iconoRelleno($icono) . '<span>' . e($texto) . '</span></button></li>';
    }
    return $html . '</ul>';
};
$asistenteYaMostrado = true;
?>
<?php if ($modoAsistente === 'panel'): ?>
  <button type="button" class="asistente-boton" data-asistente-abrir aria-label="Abrir el asistente de IA" title="Asistente de IA">
    <?= icono('chispa') ?>
  </button>
<?php endif; ?>

<<?= $modoAsistente === 'panel' ? 'aside' : 'section' ?> class="asistente asistente--<?= $modoAsistente ?>" data-asistente data-modo="<?= $modoAsistente ?>"<?= $modoAsistente === 'panel' ? ' hidden' : '' ?> aria-label="Asistente de IA">
  <?php if ($modoAsistente === 'panel'): ?>
    <div class="asistente__tirador" data-asistente-tirador role="separator" aria-orientation="vertical"
         aria-label="Cambiar el ancho del asistente" tabindex="0"></div>
  <?php endif; ?>

  <header class="asistente__cabecera">
    <div class="ancla-popover">
      <button type="button" class="asistente__chats" data-asistente-chats aria-haspopup="menu" aria-expanded="false">
        <span class="asistente__avatar asistente__avatar--mini" aria-hidden="true"><?= icono('chispa') ?></span>
        <span data-chat-titulo>Nuevo chat con la IA</span><?= icono('flecha_abajo', 'asistente__chevron') ?>
      </button>
    </div>
    <span class="asistente__info" data-asistente-info></span>
    <span class="asistente__acciones">
      <button type="button" class="boton-icono" data-asistente-nueva title="Nuevo chat"><?= icono('chat_nuevo') ?></button>
      <?php if ($modoAsistente === 'panel'): ?>
        <a class="boton-icono" href="<?= URL_BASE ?>/asistente/" title="Abrir a pantalla completa"><?= icono('expandir') ?></a>
      <?php endif; ?>
      <button type="button" class="boton-icono" data-asistente-ajustes title="Configuración del asistente" hidden><?= icono('engrane') ?></button>
      <?php if ($modoAsistente === 'panel'): ?>
        <button type="button" class="boton-icono" data-asistente-cerrar title="Cerrar"><?= icono('doble_derecha') ?></button>
      <?php endif; ?>
    </span>
  </header>

  <div class="asistente__marco" data-asistente-marco>
    <div class="asistente__conversacion" data-asistente-conversacion>
      <div class="asistente__vacio" data-asistente-vacio>
        <span class="asistente__avatar" aria-hidden="true"><?= iconoRelleno('chispa') ?></span>
        <p class="asistente__vacio-titulo">¿En qué puedo ayudarte hoy?</p>
        <?php if ($modoAsistente === 'panel'): ?><?= $listaSugerencias('asistente__sugerencias') ?><?php endif; ?>
      </div>
    </div>

    <!-- Configuración (solo administrador): proveedor detectado por la clave, modelos, comportamiento, privacidad y uso -->
    <section class="asistente__ajustes" data-asistente-panel-ajustes hidden>
      <form class="ajustes-ia" data-ajustes-form autocomplete="off">
        <header class="ajustes-ia__cabecera">
          <h2 class="ajustes-ia__titulo">Configuración del asistente</h2>
          <span class="ajustes-ia__estado" data-ajustes-estado></span>
        </header>

        <h3 class="ajustes-ia__seccion">Conexión</h3>
        <div class="ajustes-ia__fila ajustes-ia__fila--apilada">
          <div class="ajustes-ia__etiqueta"><b>Clave de API</b><small data-ajuste-clave-ayuda>Al pegarla se reconoce el proveedor y se cargan sus modelos.</small></div>
          <div class="campo-ia">
            <?= icono('candado') ?>
            <input name="clave" type="password" spellcheck="false" data-ajuste-clave aria-label="Clave de API">
            <span class="campo-ia__marca" data-ajuste-detectado></span>
            <button type="button" class="campo-ia__boton" data-ajuste-ver title="Mostrar u ocultar la clave"><?= icono('ojo') ?></button>
          </div>
        </div>
        <div class="ajustes-ia__fila">
          <div class="ajustes-ia__etiqueta"><b>Proveedor</b></div>
          <div class="desplegable-ia">
            <input type="hidden" name="servicio" data-ajuste-servicio>
            <button type="button" class="desplegable-ia__boton" data-ajuste-servicio-boton aria-haspopup="listbox" aria-expanded="false">
              <span class="desplegable-ia__monograma" data-ajuste-servicio-monograma></span>
              <span class="desplegable-ia__texto" data-ajuste-servicio-nombre></span>
              <?= icono('flecha_abajo', 'desplegable-ia__chevron') ?>
            </button>
            <div class="desplegable-ia__lista" data-ajuste-servicios role="listbox" aria-label="Proveedores" hidden></div>
          </div>
        </div>
        <div class="ajustes-ia__fila ajustes-ia__fila--apilada" data-ajuste-url-caja>
          <div class="ajustes-ia__etiqueta"><b>Dirección de la API</b><small>Para modelos locales o servicios compatibles.</small></div>
          <div class="campo-ia">
            <?= icono('enlace') ?>
            <input name="url" spellcheck="false" aria-label="Dirección de la API">
          </div>
        </div>

        <h3 class="ajustes-ia__seccion">Modelo</h3>
        <div class="ajustes-ia__modelos">
          <div class="campo-ia">
            <?= icono('buscar') ?>
            <input name="modelo" spellcheck="false" data-ajuste-modelo aria-label="Modelo">
            <span class="campo-ia__marca campo-ia__marca--tenue" data-ajuste-cuenta></span>
            <button type="button" class="campo-ia__boton" data-ajustes-modelos title="Volver a cargar los modelos"><?= icono('reabrir') ?></button>
          </div>
          <ul class="ajustes-ia__lista" data-lista-modelos role="listbox" aria-label="Modelos disponibles" hidden></ul>
        </div>

        <h3 class="ajustes-ia__seccion">Comportamiento</h3>
        <div class="ajustes-ia__fila">
          <div class="ajustes-ia__etiqueta"><b>Profundidad</b><small>Cuánto razona antes de responder.</small></div>
          <div class="segmentado" role="radiogroup" data-ajuste-esfuerzo>
            <button type="button" data-valor="low" aria-pressed="false">Rápido</button>
            <button type="button" data-valor="medium" aria-pressed="true">Auto</button>
            <button type="button" data-valor="high" aria-pressed="false">Profundo</button>
          </div>
        </div>
        <label class="ajustes-ia__fila ajustes-ia__fila--interruptor">
          <div class="ajustes-ia__etiqueta"><b>Acciones</b><small>Crear y editar páginas, bases de datos, expedientes y citas; investigar en internet.</small></div>
          <input type="checkbox" name="herramientas" checked><i class="interruptor" aria-hidden="true"></i>
        </label>
        <div class="ajustes-ia__fila">
          <div class="ajustes-ia__etiqueta"><b>Límite de preguntas</b><small>Por persona cada 10 minutos.</small></div>
          <div class="contador-ia" data-ajuste-limite>
            <button type="button" data-paso="-5" aria-label="Menos"><?= icono('menos') ?></button>
            <input name="limite" inputmode="numeric" aria-label="Preguntas cada 10 minutos">
            <button type="button" data-paso="5" aria-label="Más"><?= icono('agregar') ?></button>
          </div>
        </div>
        <div class="ajustes-ia__fila ajustes-ia__fila--apilada">
          <div class="ajustes-ia__etiqueta"><b>Instrucciones del establecimiento</b><small>Se agregan a cada conversación.</small></div>
          <textarea class="area-ia" name="instrucciones" rows="3" maxlength="2000" aria-label="Instrucciones del establecimiento"></textarea>
        </div>

        <h3 class="ajustes-ia__seccion">Privacidad</h3>
        <p class="ajustes-ia__destino" data-ajuste-destino></p>

        <h3 class="ajustes-ia__seccion">Uso · últimos 30 días</h3>
        <dl class="ajustes-ia__uso" data-ajuste-uso></dl>

        <?php require __DIR__ . '/sincronizacion_ajustes.php'; ?>

        <div class="ajustes-ia__pie">
          <span class="ajustes-ia__prueba" data-ajustes-resultado></span>
          <button type="button" class="boton boton--sutil" data-ajustes-cerrar>Cancelar</button>
          <button type="button" class="boton boton--sutil" data-ajustes-probar>Probar</button>
          <button type="submit" class="boton boton--primario">Guardar</button>
        </div>
      </form>
    </section>
  </div>

  <!-- Barra de entrada -->
  <form class="entrada" data-asistente-form>
    <div class="entrada__adjuntos" data-entrada-adjuntos hidden></div>
    <textarea class="entrada__texto" data-entrada-texto rows="<?= $modoAsistente === 'pagina' ? 2 : 1 ?>" placeholder="Pregúntale a la IA" aria-label="Pregunta"></textarea>
    <div class="entrada__barra">
      <div class="entrada__menu">
        <button type="button" class="entrada__icono" data-menu-boton="mas" aria-haspopup="menu" aria-expanded="false" title="Adjuntar, herramientas y dictado"><?= icono('agregar') ?></button>
        <ul class="entrada__opciones" role="menu" data-menu="mas" hidden>
          <li role="menuitem" data-accion-entrada="adjuntar"><?= icono('pdf') ?><b>Adjuntar archivo de texto</b><small>.txt, .md, .csv o .json, hasta 200 KB.</small></li>
          <li role="menuitem" data-accion-entrada="dictar"><?= icono('microfono') ?><b>Dictar la pregunta</b><small>Transcripción local; el audio no sale del equipo.</small></li>
          <li class="entrada__opciones-titulo" aria-hidden="true">Herramienta</li>
          <li role="menuitemradio" data-herramienta="ninguna" aria-checked="true"><?= icono('chispa') ?><b>Sin herramienta</b><small>Solo la conversación.</small></li>
          <li role="menuitemradio" data-herramienta="normativa" aria-checked="false"><?= icono('escudo') ?><b>Normativa</b><small>Resumen de NOM-004, NOM-024 y LFPDPPP.</small></li>
          <li role="menuitemradio" data-herramienta="pacientes" data-requiere-expediente aria-checked="false"><?= icono('usuarios') ?><b>Lista de pacientes</b><small>Hasta 100 pacientes registrados.</small></li>
          <li role="menuitemradio" data-herramienta="paciente" data-requiere-expediente data-requiere-paciente aria-checked="false"><?= icono('usuario') ?><b>Paciente abierto</b><small>El expediente que tienes en pantalla.</small></li>
          <li role="menuitemradio" data-herramienta="pagina" data-requiere-expediente data-requiere-pagina aria-checked="false"><?= icono('documento') ?><b>Página abierta</b><small>La nota o base de datos que tienes en pantalla.</small></li>
        </ul>
      </div>
      <input type="file" accept=".txt,.md,.csv,.json,text/plain" data-entrada-archivo hidden>

      <div class="entrada__menu">
        <button type="button" class="entrada__icono entrada__icono--acceso" data-menu-boton="acceso" aria-haspopup="menu" aria-expanded="false" title="Acceso restringido: no se envía ningún dato de pacientes"><?= icono('escudo_alerta') ?></button>
        <ul class="entrada__opciones" role="menu" data-menu="acceso" hidden>
          <li role="menuitemradio" data-valor="restringido" aria-checked="true"><?= icono('escudo_alerta') ?><b>Acceso restringido</b><small>No se envía ningún dato de pacientes.</small></li>
          <li role="menuitemradio" data-valor="expediente" aria-checked="false"><?= icono('escudo_ok') ?><b>Acceso al expediente</b><small>Permite compartir datos de pacientes y páginas con el modelo.</small></li>
        </ul>
      </div>

      <button type="button" class="entrada__herramienta" data-herramienta-actual hidden></button>
      <button type="button" class="entrada__icono entrada__icono--mic" data-entrada-mic hidden title="Detener el dictado"><?= icono('microfono') ?></button>

      <span class="entrada__espacio"></span>

      <div class="entrada__menu">
        <button type="button" class="entrada__modelo" data-menu-boton="esfuerzo" aria-haspopup="menu" aria-expanded="false" title="Profundidad del razonamiento">
          <span data-menu-texto="esfuerzo">Auto</span>
        </button>
        <ul class="entrada__opciones entrada__opciones--derecha" role="menu" data-menu="esfuerzo" hidden>
          <li class="entrada__opciones-titulo" aria-hidden="true" data-entrada-modelo>Sin modelo configurado</li>
          <li role="menuitemradio" data-valor="medium" aria-checked="true"><b>Auto</b><small>Equilibrado; recomendado para el día a día.</small></li>
          <li role="menuitemradio" data-valor="low" aria-checked="false"><b>Rápido</b><small>Razona poco; respuestas breves.</small></li>
          <li role="menuitemradio" data-valor="high" aria-checked="false"><b>Profundo</b><small>Razona más; tarda más.</small></li>
        </ul>
      </div>

      <button type="submit" class="entrada__enviar" data-entrada-enviar aria-label="Enviar" disabled><?= icono('flecha_arriba') ?></button>
    </div>
  </form>

  <?php if ($modoAsistente === 'pagina'): ?>
    <?= $listaSugerencias('asistente__atajos') ?>
  <?php endif; ?>
</<?= $modoAsistente === 'panel' ? 'aside' : 'section' ?>>
