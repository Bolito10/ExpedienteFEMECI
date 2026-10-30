<?php
/**
 * Tarjeta "Perfil del paciente" con su menú de acciones.
 * Se usa como menú flotante (clic izquierdo en la tabla) y en pacientes/ver.php.
 */

/** Obtiene un paciente activo por id, o null si no existe. */
function buscarPaciente(int $id): ?array
{
    $consulta = db()->prepare(
        'SELECT id, folio, nombre, primer_apellido, segundo_apellido, fecha_nacimiento, creado_en
           FROM pacientes
          WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL'
    );
    $consulta->execute([$id, institucionId()]);
    return $consulta->fetch() ?: null;
}

function tarjetaPaciente(array $paciente, string $claseExtra = ''): string
{
    $nombreCompleto   = trim("{$paciente['nombre']} {$paciente['primer_apellido']} {$paciente['segundo_apellido']}");
    $iniciales        = mb_strtoupper(mb_substr($paciente['nombre'], 0, 1) . mb_substr($paciente['primer_apellido'], 0, 1));
    $numeroExpediente = preg_replace('/\D/', '', $paciente['folio']);

    // Cada opción lleva a algo que existe; solo se muestran las que el rol puede usar
    $id = (int) $paciente['id'];
    $nuevo = fn (string $tipo) => '/pacientes/nuevo_registro.php?paciente=' . $id . '&tipo=' . $tipo;
    $opciones = array_values(array_filter([
        puede('historial') ? ['texto' => 'Nueva consulta', 'icono' => 'documento', 'url' => $nuevo('consulta'), 'principal' => true] : null,
        ['texto' => 'Resumen clínico', 'icono' => 'tendencia', 'url' => '/pacientes/ver.php?id=' . $id],
        ['texto' => 'Historial clínico', 'icono' => 'lista', 'url' => '/pacientes/ver.php?id=' . $id . '#historial'],
        puede('historial') ? ['texto' => 'Nueva receta', 'icono' => 'pildora', 'url' => $nuevo('receta')] : null,
        puede('historial') ? ['texto' => 'Resultado de estudio', 'icono' => 'barras', 'url' => $nuevo('estudio')] : null,
        puede('historial') ? ['texto' => 'Otro registro clínico', 'icono' => 'clinico', 'url' => '/pacientes/nuevo_registro.php?paciente=' . $id] : null,
        puede('agenda') ? ['texto' => 'Agendar cita', 'icono' => 'calendario', 'url' => '/agenda/?nueva=1&paciente_id=' . $id] : null,
        puede('asistente') ? ['texto' => 'Resumir con IA', 'icono' => 'chispa', 'url' => '/pacientes/ver.php?id=' . $id . '&resumir=1'] : null,
        puede('editar_paciente') ? ['texto' => 'Editar ficha', 'icono' => 'editar', 'url' => '/pacientes/registro.php?id=' . $id] : null,
    ]));

    ob_start();
    ?>
    <article class="perfil <?= e($claseExtra) ?>" aria-label="Perfil de <?= e($nombreCompleto) ?>">
      <header class="perfil__cabecera">
        <span class="avatar" aria-hidden="true"><?= e($iniciales) ?></span>
        <div class="perfil__identidad">
          <h2 class="perfil__nombre"><?= e($nombreCompleto) ?></h2>
          <p class="perfil__datos">Expediente <?= e($numeroExpediente) ?> · <?= edad($paciente['fecha_nacimiento']) ?> años</p>
        </div>
      </header>

      <div class="perfil__imagen" role="img" aria-label="Imagen decorativa"></div>

      <h3 class="perfil__titulo">Perfil del paciente</h3>

      <ul class="menu">
        <?php foreach ($opciones as $i => $op):
          $clases    = 'menu__opcion' . (!empty($op['principal']) ? ' menu__opcion--principal' : '');
          $atributos = 'href="' . URL_BASE . e($op['url']) . '"';
        ?>
          <li>
            <a class="<?= $clases ?>" <?= $atributos ?> style="--i: <?= $i ?>">
              <?= icono($op['icono']) ?>
              <span><?= e($op['texto']) ?></span>
              <?php if (!empty($op['principal'])): ?><?= icono('agregar', 'menu__mas') ?><?php endif; ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>

      <footer class="perfil__pie">
        <span>Registrado el <?= fechaCorta($paciente['creado_en']) ?></span>
      </footer>
    </article>
    <?php
    return ob_get_clean();
}
