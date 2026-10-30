    </main>
    </div><!-- /contenido__pagina -->

    <?php if (!empty($paginaActual)): ?>
      <!-- Actividad de la página: se abre con clic en "Última edición" (Notificaciones y Estadísticas reales de la bitácora) -->
      <aside class="panel-lateral" data-panel-actividad hidden aria-label="Actividad de la página">
        <header class="panel-lateral__cabecera">
          <button type="button" class="boton-icono" data-panel-cerrar title="Cerrar"><?= icono('doble_derecha') ?></button>
          <span class="panel-lateral__espacio"></span>
          <button type="button" class="barra-superior__texto" data-compartir-abrir aria-haspopup="dialog"><?= icono('candado') ?> Compartir</button>
          <button type="button" class="boton-icono boton-favorito<?= $paginaActual['favorita'] ? ' activo' : '' ?>" data-favorita aria-pressed="<?= $paginaActual['favorita'] ? 'true' : 'false' ?>" title="Favorito"><?= icono('estrella') ?></button>
        </header>
        <div class="panel-lateral__pestanas" role="tablist">
          <button type="button" role="tab" aria-selected="true" data-panel-pestana="notificaciones">Notificaciones</button>
          <button type="button" role="tab" aria-selected="false" data-panel-pestana="estadisticas">Estadísticas</button>
        </div>
        <div class="panel-lateral__cuerpo" data-panel-cuerpo></div>
      </aside>
    <?php endif; ?>

    <?php require __DIR__ . '/../componentes/asistente_panel.php'; ?>
    </div><!-- /contenido__cuerpo -->
  </div><!-- /contenido -->
</div>

<?php if (!empty($notificacion)): ?>
  <div class="notificaciones" aria-live="polite">
    <div class="notificacion notificacion--<?= e($notificacion['tipo']) ?>" role="<?= $notificacion['tipo'] === 'error' ? 'alert' : 'status' ?>">
      <?= icono($notificacion['tipo'] === 'error' ? 'info' : 'escudo') ?>
      <span><?= e($notificacion['texto']) ?></span>
    </div>
  </div>
<?php endif; ?>

<?php $iconosJs = ['imagen', 'grafica', 'carpeta', 'documento', 'tabla', 'chevron', 'mas', 'agregar', 'estrella', 'papelera', 'enlace', 'editar', 'reabrir', 'usuarios', 'candado', 'flecha_abajo', 'aa', 'numero', 'calendario', 'seleccion', 'casilla', 'buscar', 'agarre', 'titulo', 'vinetas', 'lista_numerada', 'comillas', 'separador', 'texto', 'cerrar', 'microfono', 'reloj', 'chispa', 'flecha_arriba', 'menos', 'clinico', 'info']; ?>
<?php $rellenosJs = ['documento', 'carpeta', 'tabla', 'reabrir']; ?>
<script type="application/json" id="iconos-js"><?= json_encode(array_combine($iconosJs, array_map('icono', $iconosJs)) + array_combine(array_map(fn ($n) => "r_$n", $rellenosJs), array_map('iconoRelleno', $rellenosJs)), JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= recurso("js/markdown.js") ?>"></script>
<script src="<?= recurso("js/app.js") ?>"></script>
<script src="<?= recurso("js/espacio.js") ?>"></script>
<script src="<?= recurso("js/graficas.js") ?>"></script>
<?php foreach (glob(__DIR__ . "/../../../assets/js/modulos/*.js") as $guion): ?>
<script src="<?= recurso("js/modulos/" . basename($guion)) ?>"></script>
<?php endforeach; ?>
<?php foreach ($guionesPagina ?? [] as $guion): // scripts propios de una página (p. ej. el visor de imágenes) ?>
<script src="<?= recurso($guion) ?>"></script>
<?php endforeach; ?>
</body>
</html>
