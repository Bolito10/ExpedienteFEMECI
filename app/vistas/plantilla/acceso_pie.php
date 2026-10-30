    </div>
  </section>
  <!-- Imágenes y video del panel de acceso (assets/img/acceso, mejorados): pasan con un fundido suave -->
  <div class="acceso__imagen acceso__carrusel" data-carrusel aria-hidden="true">
    <img class="acceso__diapositiva activa" src="<?= recurso('img/acceso/pintura.webp') ?>" alt="">
    <img class="acceso__diapositiva" data-src="<?= recurso('img/acceso/diapositiva-1.webp') ?>" alt="">
    <img class="acceso__diapositiva acceso__diapositiva--vertical" data-src="<?= recurso('img/acceso/diapositiva-2.webp') ?>" alt="">
    <img class="acceso__diapositiva" data-src="<?= recurso('img/acceso/diapositiva-4.webp') ?>" alt="">
    <video class="acceso__diapositiva" data-src="<?= recurso('img/acceso/video-acceso.mp4') ?>" muted playsinline loop preload="none"></video>
    <img class="acceso__diapositiva acceso__diapositiva--vertical" data-src="<?= recurso('img/acceso/diapositiva-3.webp') ?>" alt="">
    <img class="acceso__diapositiva" data-src="<?= recurso('img/acceso/diapositiva-5.webp') ?>" alt="">
    <img class="acceso__diapositiva acceso__diapositiva--contener" data-src="<?= recurso('img/acceso/diapositiva-6.webp') ?>" alt="">
  </div>
</main>

<?php if (!empty($notificacion)): ?>
  <div class="notificaciones" aria-live="polite">
    <div class="notificacion notificacion--<?= e($notificacion['tipo']) ?>" role="<?= $notificacion['tipo'] === 'error' ? 'alert' : 'status' ?>">
      <?= icono($notificacion['tipo'] === 'error' ? 'info' : 'escudo') ?>
      <span><?= e($notificacion['texto']) ?></span>
    </div>
  </div>
<?php endif; ?>

<script src="<?= recurso("js/app.js") ?>"></script>
<script src="<?= recurso("js/modulos/controles.js") ?>"></script>
<script src="<?= recurso("js/carrusel_acceso.js") ?>"></script>
<?php foreach ($guionesAcceso ?? [] as $guion): ?>
<script src="<?= recurso($guion) ?>"></script>
<?php endforeach; ?>
</body>
</html>
