<?php
/**
 * Nuevo registro del historial clínico a página completa, con el mismo estilo que el registro de pacientes:
 * elección del tipo y un formulario propio para cada nota o documento de la NOM-004-SSA3-2012.
 * Envía a pacientes/historial.php (origen=completo); si algo falla, regresa aquí con lo escrito y el
 * mensaje de cada campo. Las entradas son inmutables una vez guardadas (NOM-004 5.10).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/registros_clinicos.php';
exigirPermiso('historial');

$pacienteId = (int) ($_GET['paciente'] ?? 0);
$consulta = db()->prepare('SELECT id, folio, nombre, primer_apellido, segundo_apellido, fecha_nacimiento, sexo, alergias
                             FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
$consulta->execute([$pacienteId, institucionId()]);
$p = $consulta->fetch();
if (!$p) {
    mensajeFlash('error', 'El paciente no existe o fue dado de baja.');
    redirigir('/pacientes/');
}
$nombrePaciente = trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}");
$tipo = is_string($_GET['tipo'] ?? null) && isset(TIPOS_REGISTRO[$_GET['tipo']]) ? $_GET['tipo'] : '';
$info = $tipo ? TIPOS_REGISTRO[$tipo] : null;

// Lo escrito antes de un error de validación (lo deja pacientes/historial.php en la sesión)
$borrador = $_SESSION['borrador_registro'] ?? null;
unset($_SESSION['borrador_registro']);
$datos = $errores = [];
if ($borrador && $borrador['paciente'] === $pacienteId && $borrador['tipo'] === $tipo) {
    $datos   = (array) $borrador['datos'];
    $errores = (array) $borrador['errores'];
}

// Quien firma la entrada (NOM-004 5.10)
$autor = db()->prepare('SELECT nombre, cedula_profesional FROM usuarios WHERE id = ?');
$autor->execute([usuarioActual()['id']]);
$autor = $autor->fetch();

// Receta: institución y establecimiento de la última receta de quien prescribe, para no reescribirlos
$prescriptorPrevio = [];
if ($tipo === 'receta') {
    $ultima = db()->prepare("SELECT detalle FROM historial_clinico WHERE autor_id = ? AND tipo = 'receta' ORDER BY id DESC LIMIT 1");
    $ultima->execute([usuarioActual()['id']]);
    $prescriptorPrevio = (json_decode((string) $ultima->fetchColumn(), true) ?: [])['prescriptor'] ?? [];
}
$establecimientoConfig = (string) config('nombre_establecimiento');

/* ---------- Ayudas para pintar campos (mismo marcado que pacientes/registro.php) ---------- */
$valor = function (string $campo, string $porDefecto = '') use ($datos): string {
    $v = preg_match('/^(\w+)\[(\w+)\]$/', $campo, $m) ? ($datos[$m[1]][$m[2]] ?? null) : ($datos[$campo] ?? null);
    return is_string($v) ? $v : ($datos ? '' : $porDefecto);
};
$idDe  = fn (string $nombre): string => 'r-' . trim(preg_replace('/[^a-z0-9]+/i', '-', $nombre), '-');
$aviso = fn (string $clave): string => isset($errores[$clave]) ? '<span class="campo__ayuda">' . e($errores[$clave]) . '</span>' : '';
$marca = fn (bool $req): string => $req ? ' <span class="requerido">*</span>' : '';

/**
 * Campo de una línea. Opciones: col, max, req, tipo, icono, defecto, valor (renglones), clave (error),
 * fila (true en renglones repetibles: sin id, la etiqueta se asocia con aria-label), attrs, campoAttrs.
 */
$campo = function (string $nombre, string $etiqueta, array $o = []) use ($valor, $idDe, $aviso, $marca, $errores): string {
    $o += ['col' => 6, 'max' => 255, 'req' => false, 'tipo' => 'text', 'icono' => null, 'defecto' => '', 'valor' => null,
           'clave' => $nombre, 'fila' => false, 'attrs' => '', 'campoAttrs' => '', 'unidad' => null];
    $id  = $o['fila'] ? '' : $idDe($nombre);
    $val = $o['valor'] ?? $valor($nombre, $o['defecto']);
    $req = $o['req'] ? ($o['fila'] ? ' data-requerido-fila' : ' required') : '';
    $control = 'campo__control' . ($o['unidad'] !== null ? ' campo__control--unidad' : '');
    return '<div class="campo col-' . $o['col'] . (isset($errores[$o['clave']]) ? ' campo--error' : '') . '"' . $o['campoAttrs'] . '>'
         . ($o['fila'] ? '<span class="campo__etiqueta">' : '<label class="campo__etiqueta" for="' . $id . '">') . e($etiqueta) . $marca($o['req'])
         . ($o['fila'] ? '</span>' : '</label>')
         . '<div class="' . $control . '">' . ($o['icono'] ? icono($o['icono']) : '')
         . '<input type="' . $o['tipo'] . '"' . ($id ? ' id="' . $id . '"' : ' aria-label="' . e($etiqueta) . '"') . ' name="' . e($nombre) . '" value="' . e($val) . '"'
         . ($o['tipo'] === 'text' ? ' maxlength="' . $o['max'] . '"' : '') . $req . ' autocomplete="off"' . $o['attrs'] . '>'
         . ($o['unidad'] !== null ? '<span>' . e($o['unidad']) . '</span>' : '') . '</div>'
         . $aviso($o['clave']) . '</div>';
};

/** Área de texto (caja completa). Opciones: col, max, req, filas, campoAttrs. */
$area = function (string $nombre, string $etiqueta, array $o = []) use ($valor, $idDe, $aviso, $marca, $errores): string {
    $o += ['col' => 12, 'max' => 5000, 'req' => false, 'filas' => 3, 'campoAttrs' => ''];
    $id = $idDe($nombre);
    return '<div class="campo col-' . $o['col'] . (isset($errores[$nombre]) ? ' campo--error' : '') . '"' . $o['campoAttrs'] . '>'
         . '<label class="campo__etiqueta" for="' . $id . '">' . e($etiqueta) . $marca($o['req']) . '</label>'
         . '<div class="campo__control campo__control--area"><textarea id="' . $id . '" name="' . e($nombre) . '" rows="' . $o['filas'] . '" maxlength="' . $o['max'] . '"'
         . ($o['req'] ? ' required' : '') . '>' . e($valor($nombre)) . '</textarea></div>' . $aviso($nombre) . '</div>';
};

/** Lista de opciones (assets/js/espacio.js la convierte en el selector de la app). Opciones: col, req, valor, clave, fila, campoAttrs. */
$lista = function (string $nombre, string $etiqueta, array $opciones, array $o = []) use ($valor, $idDe, $aviso, $marca, $errores): string {
    $o += ['col' => 6, 'req' => false, 'valor' => null, 'clave' => $nombre, 'fila' => false, 'campoAttrs' => '', 'defecto' => ''];
    $id  = $o['fila'] ? '' : $idDe($nombre);
    $val = $o['valor'] ?? $valor($nombre, $o['defecto']);
    $html = '<div class="campo col-' . $o['col'] . (isset($errores[$o['clave']]) ? ' campo--error' : '') . '"' . $o['campoAttrs'] . '>'
          . ($o['fila'] ? '<span class="campo__etiqueta">' : '<label class="campo__etiqueta" for="' . $id . '">') . e($etiqueta) . $marca($o['req'])
          . ($o['fila'] ? '</span>' : '</label>')
          . '<div class="campo__control campo__control--select"><select' . ($id ? ' id="' . $id . '"' : ' aria-label="' . e($etiqueta) . '"') . ' name="' . e($nombre) . '"'
          . ($o['req'] ? ($o['fila'] ? ' data-requerido-fila' : ' required') : '') . '><option value="">Selecciona</option>';
    foreach ($opciones as $opcion) {
        $html .= '<option value="' . e($opcion) . '"' . ($val === $opcion ? ' selected' : '') . '>' . e($opcion) . '</option>';
    }
    return $html . '</select></div>' . $aviso($o['clave']) . '</div>';
};

/** Fecha y hora de la nota (NOM-004 5.10). */
$fechaHora = fn (string $etiqueta = 'Fecha'): string =>
    $campo('fecha', $etiqueta, ['col' => 3, 'req' => true, 'tipo' => 'date', 'icono' => 'calendario', 'defecto' => date('Y-m-d'), 'attrs' => ' max="' . date('Y-m-d') . '"'])
  . $campo('hora', 'Hora', ['col' => 3, 'tipo' => 'time', 'icono' => 'reloj', 'defecto' => date('H:i')]);

/** Signos vitales (con el IMC calculado en el navegador a partir de peso y talla). */
$signos = function () use ($campo, $valor, $datos): string {
    $cortas = ['fc' => 'Frec. cardiaca', 'fr' => 'Frec. respiratoria', 'spo2' => 'Saturación O₂', 'glucosa' => 'Glucosa'];
    $html = '<p class="registro-clinico__subtitulo">Signos vitales</p><div class="rejilla">';
    foreach (SIGNOS_VITALES as $clave => [$etiqueta, , $unidad]) {
        $html .= $campo("signos[$clave]", $cortas[$clave] ?? $etiqueta,
            ['col' => 2, 'max' => 6, 'unidad' => $unidad, 'attrs' => ' inputmode="decimal" data-signo="' . $clave . '"']);
    }
    $imc = imcDesdeSignos(array_map('floatval', array_filter((array) ($datos['signos'] ?? []), 'is_numeric')));
    $html .= '<div class="campo col-2"><label class="campo__etiqueta" for="r-imc">IMC</label><div class="campo__control campo__control--unidad">'
           . '<input id="r-imc" value="' . ($imc !== null ? e(number_format($imc, 1)) : '') . '" readonly tabindex="-1" data-imc><span>kg/m²</span></div></div>';
    return $html . '</div>';
};

/** Renglones de diagnóstico con código CIE-10 opcional. */
$diagnosticos = function (string $etiqueta = 'Diagnóstico', bool $req = false, string $agregar = 'Otro diagnóstico') use ($campo, $datos): string {
    $filas = is_array($datos['diagnostico'] ?? null) ? array_values($datos['diagnostico']) : [''];
    $cies  = is_array($datos['cie10'] ?? null) ? array_values($datos['cie10']) : [];
    $fila = fn (int $i, string $d, string $c, bool $plantilla = false) =>
        '<div class="registro-clinico__fila registro-clinico__fila--linea" data-fila><div class="rejilla">'
      . $campo('diagnostico[]', $etiqueta, ['col' => 9, 'req' => $req, 'fila' => true, 'valor' => $d, 'clave' => $plantilla ? '' : "diagnostico[$i]"])
      . $campo('cie10[]', 'CIE-10', ['col' => 3, 'max' => 10, 'fila' => true, 'valor' => $c, 'clave' => $plantilla ? '' : "cie10[$i]", 'attrs' => ' data-cie spellcheck="false"'])
      . '</div><button type="button" class="registro-clinico__quitar" data-fila-quitar aria-label="Quitar diagnóstico" title="Quitar">' . icono('cerrar') . '</button></div>';
    $html = '<div class="registro-clinico__filas" data-filas="diagnosticos"' . ($req ? ' data-filas-requeridas' : '') . '>';
    foreach ($filas as $i => $d) {
        $html .= $fila($i, (string) $d, (string) ($cies[$i] ?? ''));
    }
    return $html . '</div><template data-plantilla="diagnosticos">' . $fila(0, '', '', true) . '</template>'
         . '<button type="button" class="historial__otro" data-fila-agregar="diagnosticos">' . icono('agregar') . ' ' . e($agregar) . '</button>';
};

/** Renglones de medicamentos de la receta. */
$medicamentos = function () use ($campo, $lista, $datos): string {
    $col = fn (string $c) => is_array($datos[$c] ?? null) ? array_values($datos[$c]) : [];
    $nombres = $col('medicamento') ?: [''];
    $fila = function (int $i, bool $plantilla = false) use ($campo, $lista, $col): string {
        $v = fn (string $c) => $plantilla ? '' : (string) ($col($c)[$i] ?? '');
        $k = fn (string $c) => $plantilla ? '' : $c . "[$i]";
        return '<div class="registro-clinico__fila" data-fila>'
             . '<div class="registro-clinico__fila-cabecera"><span>Medicamento <span data-fila-numero>' . ($i + 1) . '</span></span>'
             . '<button type="button" class="registro-clinico__quitar" data-fila-quitar aria-label="Quitar medicamento" title="Quitar">' . icono('cerrar') . '</button></div>'
             . '<div class="rejilla">'
             . $campo('medicamento[]', 'Nombre genérico', ['col' => 5, 'max' => 120, 'req' => true, 'fila' => true, 'valor' => $v('medicamento'), 'clave' => $k('medicamento')])
             . $campo('presentacion[]', 'Presentación', ['col' => 4, 'max' => 120, 'req' => true, 'fila' => true, 'valor' => $v('presentacion'), 'clave' => $k('presentacion')])
             . $campo('cantidad[]', 'Cantidad a surtir', ['col' => 3, 'max' => 60, 'fila' => true, 'valor' => $v('cantidad'), 'clave' => $k('cantidad')])
             . $campo('dosis[]', 'Dosis', ['col' => 3, 'max' => 60, 'req' => true, 'fila' => true, 'valor' => $v('dosis'), 'clave' => $k('dosis')])
             . $lista('via[]', 'Vía', VIAS_ADMINISTRACION, ['col' => 3, 'req' => true, 'fila' => true, 'valor' => $v('via'), 'clave' => $k('via')])
             . $campo('periodicidad[]', 'Frecuencia', ['col' => 3, 'max' => 60, 'req' => true, 'fila' => true, 'valor' => $v('periodicidad'), 'clave' => $k('periodicidad')])
             . $campo('duracion[]', 'Duración', ['col' => 3, 'max' => 60, 'req' => true, 'fila' => true, 'valor' => $v('duracion'), 'clave' => $k('duracion')])
             . '</div></div>';
    };
    $html = '<div class="registro-clinico__filas" data-filas="medicamentos" data-filas-requeridas>';
    foreach (array_keys($nombres) as $i) {
        $html .= $fila($i);
    }
    return $html . '</div><template data-plantilla="medicamentos">' . $fila(0, true) . '</template>'
         . '<button type="button" class="historial__otro" data-fila-agregar="medicamentos">' . icono('agregar') . ' Otro medicamento</button>';
};

/** Casilla de verificación con el estilo del registro. */
$casilla = fn (string $nombre, string $texto, bool $req = false): string =>
    '<label class="casilla' . (isset($errores[$nombre]) ? ' casilla--error' : '') . '"><input type="checkbox" name="' . $nombre . '" value="1"'
  . (!empty($datos[$nombre]) ? ' checked' : '') . ($req ? ' required' : '') . '> ' . e($texto) . '</label>' . $aviso($nombre);

$seccion = fn (string $icono, string $titulo): string =>
    '<h2 class="seccion__titulo">' . iconoRelleno($icono) . ' ' . e($titulo) . '</h2>';

$seccionActiva  = 'pacientes';
$pacienteActual = $pacienteId;
$tituloPagina   = ($info ? $info['documento'] : 'Nuevo registro clínico') . ' · ' . $nombrePaciente;
$migas = [
    ['texto' => 'Pacientes', 'url' => '/pacientes/'],
    ['texto' => $nombrePaciente, 'url' => '/pacientes/ver.php?id=' . $pacienteId],
    ['texto' => $info ? $info['documento'] : 'Nuevo registro'],
];
$notificacion = tomarFlash();
$urlTipo = fn (string $t): string => URL_BASE . '/pacientes/nuevo_registro.php?paciente=' . $pacienteId . '&amp;tipo=' . $t;

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina__cabecera registro-clinico__cabecera">
  <span class="pagina__meta"><?= iconoRelleno($info['relleno'] ?? 'documento') ?> <?= e($p['folio']) ?> · <?= e($nombrePaciente) ?></span>
  <h1 class="pagina__titulo"><?= e($info ? $info['documento'] : 'Nuevo registro clínico') ?></h1>
  <p class="registro-clinico__paciente">
    <span><?= edad($p['fecha_nacimiento']) ?> años · <?= e(SEXOS[$p['sexo']] ?? '') ?></span>
    <span class="<?= $p['alergias'] ? 'registro-clinico__alergias' : '' ?>">Alergias: <?= e($p['alergias'] ?: 'no registradas') ?></span>
    <?php if ($info): ?><span><?= e($info['norma']) ?></span><?php endif; ?>
  </p>
</div>

<?php if (!$info): ?>
  <!-- Elección del tipo: lista agrupada, como las propiedades del expediente -->
  <?php foreach (array_unique(array_column(TIPOS_REGISTRO, 'grupo')) as $grupo): ?>
    <section class="seccion registro-clinico__grupo">
      <h2 class="registro-clinico__grupo-titulo"><?= e($grupo) ?></h2>
      <ul class="registro-clinico__tipos-lista">
        <?php foreach (TIPOS_REGISTRO as $clave => $t): if ($t['grupo'] !== $grupo) continue; ?>
          <li>
            <a class="registro-clinico__opcion" href="<?= $urlTipo($clave) ?>">
              <?= iconoRelleno($t['relleno']) ?>
              <span class="registro-clinico__opcion-texto"><b><?= e($t['documento']) ?></b><small><?= e($t['descripcion']) ?></small></span>
              <span class="registro-clinico__norma"><?= e($t['norma']) ?></span>
              <?= icono('chevron') ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>
  <div class="pie-formulario">
    <span class="nota-privacidad"><?= icono('candado') ?> Cada registro queda firmado con tu nombre y cédula, y no se puede modificar (NOM-004 5.10).</span>
    <div class="pie-formulario__acciones">
      <a class="boton boton--fantasma" href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= $pacienteId ?>#historial">Volver al expediente</a>
    </div>
  </div>

<?php else: ?>
  <!-- Cambiar de tipo: texto simple, la opción activa subrayada (como los filtros del historial) -->
  <nav class="registro-clinico__tipos" aria-label="Tipo de registro">
    <?php foreach (TIPOS_REGISTRO as $clave => $t): ?>
      <a href="<?= $urlTipo($clave) ?>"<?= $clave === $tipo ? ' class="activo" aria-current="page"' : '' ?>><?= e($t['corto']) ?></a>
    <?php endforeach; ?>
  </nav>

  <form method="post" action="<?= URL_BASE ?>/pacientes/historial.php" id="registro-clinico" class="historial__form registro-clinico" data-registro-clinico data-paciente-nombre="<?= e($nombrePaciente) ?>" data-borrador="registro-<?= $pacienteId ?>-<?= e($tipo) ?>" novalidate>
    <?= campoCsrf() ?>
    <?= campoEnvio() ?>
    <input type="hidden" name="paciente_id" value="<?= $pacienteId ?>">
    <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
    <input type="hidden" name="origen" value="completo">

    <?php switch ($tipo):
      case 'consulta': ?>
      <section class="seccion">
        <?= $seccion('mensaje', 'Motivo de consulta') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('motivo', 'Motivo de consulta', ['req' => true, 'max' => 200]) ?>
          <?= $fechaHora() ?>
          <?= $area('padecimiento', 'Padecimiento actual') ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('usuario', 'Exploración física') ?>
        <div class="seccion__cuerpo">
          <?= $signos() ?>
          <div class="rejilla"><?= $area('exploracion', 'Exploración física') ?></div>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('buscar', 'Resultados y diagnóstico') ?>
        <div class="seccion__cuerpo">
          <div class="rejilla"><?= $area('resultados', 'Resultados relevantes de estudios', ['max' => 3000, 'filas' => 2]) ?></div>
          <?= $diagnosticos() ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('lista', 'Evolución y plan') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $area('nota', 'Nota de evolución', ['req' => true]) ?>
          <?= $area('plan', 'Plan y tratamiento', ['col' => 8, 'max' => 3000]) ?>
          <?= $campo('pronostico', 'Pronóstico', ['col' => 4, 'max' => 200]) ?>
        </div>
      </section>
    <?php break;

      case 'urgencias': ?>
      <section class="seccion">
        <?= $seccion('reloj', 'Atención') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('motivo', 'Motivo de la atención', ['req' => true, 'max' => 200]) ?>
          <?= $fechaHora('Fecha de atención') ?>
          <?= $area('interrogatorio', 'Resumen del interrogatorio', ['req' => true]) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('usuario', 'Exploración física') ?>
        <div class="seccion__cuerpo">
          <?= $signos() ?>
          <div class="rejilla">
            <?= $area('exploracion', 'Exploración física', ['req' => true]) ?>
            <?= $area('estado_mental', 'Estado mental', ['max' => 1000, 'filas' => 2]) ?>
          </div>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('buscar', 'Resultados y diagnóstico') ?>
        <div class="seccion__cuerpo">
          <div class="rejilla"><?= $area('resultados', 'Resultados relevantes de estudios', ['max' => 3000, 'filas' => 2]) ?></div>
          <?= $diagnosticos('Diagnóstico o problema clínico', true) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('lista', 'Tratamiento y destino') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $area('plan', 'Tratamiento', ['req' => true, 'max' => 3000]) ?>
          <?= $campo('pronostico', 'Pronóstico', ['max' => 200]) ?>
          <?= $lista('destino', 'Destino del paciente', DESTINOS_URGENCIAS, ['req' => true]) ?>
        </div>
      </section>
    <?php break;

      case 'interconsulta': ?>
      <section class="seccion">
        <?= $seccion('usuarios', 'Solicitud') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('servicio_consultado', 'Servicio consultado', ['req' => true, 'max' => 120]) ?>
          <?= $fechaHora() ?>
          <?= $campo('servicio_solicitante', 'Servicio que solicita', ['req' => true, 'max' => 120]) ?>
          <?= $area('motivo', 'Motivo de la interconsulta', ['req' => true, 'max' => 2000, 'filas' => 2]) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('mensaje', 'Resumen clínico') ?>
        <div class="seccion__cuerpo">
          <div class="rejilla"><?= $area('resumen', 'Resumen clínico') ?></div>
          <?= $signos() ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('buscar', 'Valoración') ?>
        <div class="seccion__cuerpo">
          <div class="rejilla">
            <?= $area('criterios', 'Criterios diagnósticos', ['req' => true, 'max' => 3000]) ?>
            <?= $area('plan_estudios', 'Plan de estudios', ['max' => 3000, 'filas' => 2]) ?>
            <?= $area('sugerencias', 'Sugerencias diagnósticas y de tratamiento', ['req' => true, 'max' => 3000]) ?>
          </div>
          <?= $diagnosticos() ?>
          <div class="rejilla"><?= $campo('pronostico', 'Pronóstico', ['max' => 200]) ?></div>
        </div>
      </section>
    <?php break;

      case 'referencia': ?>
      <section class="seccion">
        <?= $seccion('casa', 'Envío') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $lista('tipo_envio', 'Tipo de envío', TIPOS_ENVIO, ['req' => true, 'defecto' => 'Referencia']) ?>
          <?= $fechaHora('Fecha de envío') ?>
          <?= $campo('establecimiento', 'Establecimiento que envía', ['max' => 200, 'defecto' => $establecimientoConfig]) ?>
          <?= $campo('destino', 'Establecimiento que recibe', ['req' => true, 'max' => 200]) ?>
          <?= $campo('servicio_destino', 'Servicio que recibe', ['max' => 120]) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('mensaje', 'Motivo y resumen clínico') ?>
        <div class="seccion__cuerpo">
          <div class="rejilla">
            <?= $area('motivo', 'Motivo de envío', ['req' => true, 'max' => 2000, 'filas' => 2]) ?>
            <?= $area('resumen', 'Resumen clínico', ['req' => true]) ?>
          </div>
          <?= $signos() ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('buscar', 'Impresión diagnóstica y terapéutica') ?>
        <div class="seccion__cuerpo">
          <?= $diagnosticos('Impresión diagnóstica', true) ?>
          <div class="rejilla"><?= $area('terapeutica', 'Terapéutica empleada', ['max' => 3000]) ?></div>
        </div>
      </section>
    <?php break;

      case 'egreso': ?>
      <section class="seccion">
        <?= $seccion('reabrir', 'Egreso') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $lista('motivo_egreso', 'Motivo del egreso', MOTIVOS_EGRESO, ['req' => true]) ?>
          <?= $fechaHora('Fecha de egreso') ?>
          <?= $campo('ingreso', 'Fecha de ingreso', ['col' => 6, 'req' => true, 'tipo' => 'date', 'icono' => 'calendario', 'attrs' => ' max="' . date('Y-m-d') . '"']) ?>
          <?= $campo('servicio', 'Servicio', ['max' => 120]) ?>
          <?= $area('causas_defuncion', 'Causas de la defunción', ['req' => true, 'max' => 2000, 'filas' => 2, 'campoAttrs' => ' data-mostrar-si="motivo_egreso:Defunción"']) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('buscar', 'Diagnósticos y evolución') ?>
        <div class="seccion__cuerpo">
          <?= $diagnosticos('Diagnóstico final', true) ?>
          <div class="rejilla">
            <?= $area('resumen', 'Resumen de la evolución y estado actual', ['req' => true]) ?>
            <?= $area('manejo', 'Manejo durante la estancia', ['max' => 3000, 'filas' => 2]) ?>
          </div>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('lista', 'Plan al egreso') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $area('pendientes', 'Problemas clínicos pendientes', ['max' => 3000, 'filas' => 2]) ?>
          <?= $area('plan', 'Plan de manejo y tratamiento', ['max' => 3000, 'filas' => 2]) ?>
          <?= $area('recomendaciones', 'Recomendaciones para la vigilancia ambulatoria', ['max' => 3000, 'filas' => 2]) ?>
          <?= $area('factores_riesgo', 'Atención de factores de riesgo', ['max' => 2000, 'filas' => 2, 'col' => 8]) ?>
          <?= $campo('pronostico', 'Pronóstico', ['col' => 4, 'max' => 200]) ?>
        </div>
      </section>
    <?php break;

      case 'receta': ?>
      <section class="seccion">
        <?= $seccion('editar', 'Medicamentos') ?>
        <div class="seccion__cuerpo"><?= $medicamentos() ?></div>
      </section>
      <section class="seccion">
        <?= $seccion('mensaje', 'Indicaciones') ?>
        <div class="seccion__cuerpo rejilla"><?= $area('indicaciones', 'Indicaciones para el paciente', ['max' => 2000]) ?></div>
      </section>
      <section class="seccion">
        <?= $seccion('escudo', 'Quien prescribe') ?>
        <div class="seccion__cuerpo rejilla">
          <div class="campo col-6"><label class="campo__etiqueta" for="r-medico">Médico</label>
            <div class="campo__control"><input id="r-medico" value="<?= e($autor['nombre']) ?>" readonly tabindex="-1"></div></div>
          <?php if ($autor['cedula_profesional']): ?>
            <div class="campo col-6"><label class="campo__etiqueta" for="r-cedula">Cédula profesional</label>
              <div class="campo__control"><input id="r-cedula" value="<?= e($autor['cedula_profesional']) ?>" readonly tabindex="-1"></div></div>
          <?php else: ?>
            <?= $campo('cedula_prescriptor', 'Cédula profesional', ['req' => true, 'max' => 10, 'attrs' => ' inputmode="numeric" pattern="\d{6,10}" data-solo-digitos']) ?>
          <?php endif; ?>
          <?= $campo('institucion', 'Institución que expidió el título', ['req' => true, 'max' => 200, 'defecto' => $prescriptorPrevio['institucion'] ?? '']) ?>
          <?= $campo('establecimiento', 'Establecimiento', ['req' => true, 'max' => 200, 'defecto' => $prescriptorPrevio['establecimiento'] ?? $establecimientoConfig]) ?>
          <?= $campo('domicilio', 'Domicilio del establecimiento', ['max' => 255, 'defecto' => $prescriptorPrevio['domicilio'] ?? '']) ?>
          <?= $fechaHora('Fecha de expedición') ?>
        </div>
      </section>
    <?php break;

      case 'hospitalizacion': ?>
      <section class="seccion">
        <?= $seccion('casa', 'Ingreso') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('motivo_ingreso', 'Motivo de ingreso', ['req' => true, 'max' => 200]) ?>
          <?= $fechaHora('Fecha de ingreso') ?>
          <?= $campo('servicio', 'Servicio', ['req' => true, 'max' => 120]) ?>
          <?= $campo('cama', 'Cama', ['col' => 3, 'max' => 20]) ?>
          <?= $campo('diagnostico_ingreso', 'Diagnóstico de ingreso', ['col' => 12]) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('editar', 'Evolución') ?>
        <div class="seccion__cuerpo rejilla"><?= $area('evolucion', 'Evolución durante la estancia') ?></div>
      </section>
      <section class="seccion">
        <?= $seccion('reabrir', 'Egreso') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('egreso', 'Fecha de egreso', ['col' => 3, 'tipo' => 'date', 'icono' => 'calendario', 'attrs' => ' max="' . date('Y-m-d') . '"']) ?>
          <?= $lista('estado_egreso', 'Estado al egreso', ESTADOS_EGRESO, ['col' => 3]) ?>
          <?= $campo('diagnostico_egreso', 'Diagnóstico de egreso') ?>
          <?= $area('resumen', 'Resumen de la estancia') ?>
        </div>
      </section>
    <?php break;

      case 'estudio': ?>
      <section class="seccion">
        <?= $seccion('tabla', 'Estudio') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('estudio', 'Estudio', ['req' => true, 'max' => 200]) ?>
          <?= $fechaHora('Fecha del resultado') ?>
          <?= $lista('categoria', 'Tipo de estudio', CATEGORIAS_ESTUDIO, ['req' => true]) ?>
          <?= $campo('realizado_en', 'Laboratorio o unidad que lo realizó', ['max' => 200]) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('buscar', 'Resultado') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $area('resultado', 'Resultado', ['req' => true, 'filas' => 4]) ?>
          <?= $area('fuera_rango', 'Valores fuera de rango', ['max' => 2000, 'filas' => 2]) ?>
          <?= $area('interpretacion', 'Interpretación', ['max' => 3000]) ?>
        </div>
      </section>
    <?php break;

      case 'consentimiento': ?>
      <section class="seccion">
        <?= $seccion('escudo', 'Acto autorizado') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('procedimiento', 'Procedimiento', ['req' => true, 'max' => 200]) ?>
          <?= $fechaHora() ?>
          <?= $area('descripcion', 'En qué consiste', ['max' => 3000, 'filas' => 2]) ?>
          <?= $campo('lugar', 'Lugar', ['max' => 120]) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('lista', 'Riesgos, beneficios y alternativas') ?>
        <div class="seccion__cuerpo">
          <div class="rejilla">
            <?= $area('riesgos', 'Riesgos', ['req' => true, 'max' => 3000]) ?>
            <?= $area('beneficios', 'Beneficios esperados', ['req' => true, 'max' => 3000]) ?>
            <?= $area('alternativas', 'Alternativas', ['max' => 3000, 'filas' => 2]) ?>
          </div>
          <div class="registro-clinico__casillas"><?= $casilla('contingencias', 'Autoriza atender las contingencias y urgencias derivadas del acto autorizado') ?></div>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('usuario', 'Quien otorga el consentimiento') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $lista('otorga', 'Otorga', OTORGANTES_CONSENTIMIENTO, ['req' => true, 'col' => 4, 'defecto' => 'Paciente']) ?>
          <?= $campo('otorgante', 'Nombre completo', ['col' => 8, 'max' => 160, 'req' => true, 'defecto' => $nombrePaciente]) ?>
          <?= $campo('parentesco', 'Parentesco o relación con el paciente', ['max' => 60, 'req' => true, 'campoAttrs' => ' data-mostrar-si="otorga:!Paciente"']) ?>
          <?= $lista('decision', 'Decisión', ['Acepta', 'No acepta'], ['req' => true]) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('usuarios', 'Testigos') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('testigo1', 'Testigo 1 · nombre completo', ['req' => true, 'max' => 160]) ?>
          <?= $campo('testigo2', 'Testigo 2 · nombre completo', ['req' => true, 'max' => 160]) ?>
        </div>
      </section>
    <?php break;
    endswitch; ?>

    <div class="pie-formulario">
      <div class="pie-formulario__legal">
        <?php if ($tipo === 'consentimiento'): ?>
          <?= $casilla('explicado', 'Confirmo que expliqué el procedimiento en lenguaje comprensible y resolví las dudas', true) ?>
        <?php endif; ?>
        <span class="nota-privacidad"><?= icono('candado') ?> Firmará <?= e($autor['nombre']) ?><?= $autor['cedula_profesional'] ? ' · cédula ' . e($autor['cedula_profesional']) : '' ?>. Una vez guardado no se puede modificar (NOM-004 5.10).</span>
      </div>
      <div class="pie-formulario__acciones">
        <a class="boton boton--fantasma" href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= $pacienteId ?>#historial">Cancelar</a>
        <button type="submit" class="boton boton--secundario">Guardar en el historial</button>
      </div>
    </div>
  </form>
<?php endif; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
