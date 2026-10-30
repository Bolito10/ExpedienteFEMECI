<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
exigirPermiso('editar_paciente');

$campos = [
    'nombre', 'primer_apellido', 'segundo_apellido', 'fecha_nacimiento', 'sexo', 'curp',
    'entidad_nacimiento', 'nacionalidad', 'telefono', 'correo', 'domicilio', 'municipio_estado',
    'contacto_emergencia', 'telefono_emergencia', 'alergias', 'grupo_sanguineo',
    'padecimientos_cronicos', 'medicamentos_actuales', 'motivo_consulta',
];

$datos   = array_fill_keys($campos, '');
$errores = [];

// Propiedades que el administrador agregó a la base de pacientes: forman parte del formato de registro
$propiedadesExtra = json_decode((string) config('pacientes_propiedades'), true) ?: [];
$extras = [];   // clave => valor ya validado (texto, número, fecha AAAA-MM-DD, opción o bool)

// Modo edición ("Editar datos" desde el perfil): mismo formulario, precargado
$idEdicion = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$original  = null;
if ($idEdicion) {
    $consulta = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
    $consulta->execute([$idEdicion, institucionId()]);
    $original = $consulta->fetch();
    if (!$original) {
        mensajeFlash('error', 'El paciente no existe o fue dado de baja.');
        redirigir('/pacientes/');
    }
    foreach ($campos as $campo) {
        $datos[$campo] = (string) ($original[$campo] ?? '');
    }
    $extras = json_decode((string) $original['extras'], true) ?: [];
}
$extrasOriginales = $extras;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($campos as $campo) {
        $datos[$campo] = trim((string) ($_POST[$campo] ?? ''));
    }
    $datos['curp'] = mb_strtoupper($datos['curp']);
    $confirmado    = isset($_POST['confirmacion']);

    // ---- Validación ----
    if (!csrfValido()) {
        $errores['general'] = 'La sesión expiró. Vuelve a enviar el formulario.';
    } elseif ($yaGuardado = envioYaProcesado()) {
        // El mismo envío llegó dos veces (doble clic o reenvío tras un corte): no se registra otra vez
        redirigir($yaGuardado);
    }
    foreach (['nombre' => 80, 'primer_apellido' => 60] as $campo => $max) {
        if ($datos[$campo] === '') {
            $errores[$campo] = 'Campo obligatorio';
        } elseif (mb_strlen($datos[$campo]) > $max) {
            $errores[$campo] = "Máximo $max caracteres";
        }
    }

    $fecha = DateTime::createFromFormat('!Y-m-d', $datos['fecha_nacimiento']);
    if ($datos['fecha_nacimiento'] === '') {
        $errores['fecha_nacimiento'] = 'Campo obligatorio';
    } elseif (!$fecha || $fecha->format('Y-m-d') !== $datos['fecha_nacimiento'] || $fecha > new DateTime('today')) {
        $errores['fecha_nacimiento'] = 'Fecha no válida';
    }

    if (!isset(SEXOS[$datos['sexo']])) {
        $errores['sexo'] = 'Campo obligatorio';
    }

    if ($datos['curp'] !== '') {
        if (!curpValida($datos['curp'])) {
            $errores['curp'] = 'La CURP no es válida. Revisa el formato y el dígito verificador.';
        } else {
            $existe = db()->prepare('SELECT folio FROM pacientes WHERE curp = ? AND institucion_id = ? AND id <> ?');
            $existe->execute([$datos['curp'], institucionId(), $idEdicion]);
            if ($folio = $existe->fetchColumn()) {
                $errores['curp'] = "Esta CURP ya está registrada (expediente $folio).";
            }
        }
    }

    if ($datos['entidad_nacimiento'] !== '' && !isset(ENTIDADES[$datos['entidad_nacimiento']])) {
        $errores['entidad_nacimiento'] = 'Selecciona una entidad de la lista';
    }
    if ($datos['correo'] !== '' && !filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) {
        $errores['correo'] = 'Correo electrónico no válido';
    }
    if ($datos['grupo_sanguineo'] !== '' && !in_array($datos['grupo_sanguineo'], GRUPOS_SANGUINEOS, true)) {
        $errores['grupo_sanguineo'] = 'Selecciona un grupo de la lista';
    }
    if (!$confirmado) {
        $errores['confirmacion'] = 'Confirma el origen de los datos';
    }

    // Propiedades agregadas: mismas reglas que la edición en la tabla (api/pacientes/actualizar.php)
    foreach ($propiedadesExtra as $prop) {
        $clave = $prop['clave'];
        $valor = is_scalar($_POST['extra'][$clave] ?? null) ? trim((string) $_POST['extra'][$clave]) : '';
        if ($prop['tipo'] === 'casilla') {
            $valor = isset($_POST['extra'][$clave]) ? '1' : '';
        }
        unset($extras[$clave]);
        if ($valor === '') {
            continue;
        }
        switch ($prop['tipo']) {
            case 'numero':
                $numero = str_replace(',', '.', $valor);
                is_numeric($numero) ? $extras[$clave] = $numero + 0 : $errores["extra_$clave"] = 'Escribe un número';
                break;
            case 'fecha':
                $f = DateTime::createFromFormat('!Y-m-d', $valor);
                $f && $f->format('Y-m-d') === $valor ? $extras[$clave] = $valor : $errores["extra_$clave"] = 'Fecha no válida';
                break;
            case 'seleccion':
                in_array($valor, $prop['opciones'] ?? [], true) ? $extras[$clave] = $valor : $errores["extra_$clave"] = 'Selecciona una opción de la lista';
                break;
            case 'casilla':
                $extras[$clave] = true;
                break;
            default:
                $extras[$clave] = mb_substr($valor, 0, 500);
        }
    }

    // ---- Guardado de cambios (edición): solo los campos modificados, cada uno a la bitácora ----
    if (!$errores && $original) {
        $pdo = db();
        $pdo->beginTransaction();
        $cambios = 0;
        foreach ($campos as $campo) {
            $nuevo    = $datos[$campo] === '' ? null : $datos[$campo];
            $anterior = $original[$campo];
            if ($nuevo !== $anterior) {
                $pdo->prepare("UPDATE pacientes SET `$campo` = ? WHERE id = ? AND institucion_id = ?")->execute([$nuevo, $idEdicion, institucionId()]);
                registrarBitacora('pacientes', $idEdicion, 'actualizar', $campo, $anterior, $nuevo);
                $cambios++;
            }
        }
        $textoExtra = fn ($v) => $v === null ? null : (is_bool($v) ? ($v ? 'Sí' : 'No') : (string) $v);
        foreach ($propiedadesExtra as $prop) {
            $antes   = $extrasOriginales[$prop['clave']] ?? null;
            $despues = $extras[$prop['clave']] ?? null;
            if ($textoExtra($antes) !== $textoExtra($despues)) {
                registrarBitacora('pacientes', $idEdicion, 'actualizar', mb_substr($prop['nombre'], 0, 60), $textoExtra($antes), $textoExtra($despues));
                $cambios++;
            }
        }
        if ($extras != $extrasOriginales) {
            $pdo->prepare('UPDATE pacientes SET extras = ? WHERE id = ? AND institucion_id = ?')
                ->execute([$extras ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null, $idEdicion, institucionId()]);
        }
        $pdo->commit();

        mensajeFlash('exito', $cambios ? 'Datos del paciente actualizados.' : 'No hubo cambios que guardar.');
        recordarEnvio('/pacientes/ver.php?id=' . $idEdicion);
        redirigir('/pacientes/ver.php?id=' . $idEdicion);
    }

    // ---- Guardado (alta) ----
    if (!$errores) {
        $valores = array_map(fn ($v) => $v === '' ? null : $v, $datos);

        $pdo = db();
        $pdo->beginTransaction();

        // El folio depende del id, así que se inserta uno temporal y se actualiza enseguida.
        $columnas = implode(', ', $campos);
        $marcas   = implode(', ', array_map(fn ($c) => ":$c", $campos));
        $insertar = $pdo->prepare(
            "INSERT INTO pacientes (institucion_id, folio, $columnas, extras, confirmacion_datos)
             VALUES (:institucion_id, :folio_temporal, $marcas, :extras, 1)"
        );
        $insertar->execute(['institucion_id' => institucionId(), 'folio_temporal' => 'TMP-' . bin2hex(random_bytes(6)), 'extras' => $extras ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null] + $valores);

        $id    = (int) $pdo->lastInsertId();
        $folio = sprintf('EXP-%06d', $id);
        $pdo->prepare('UPDATE pacientes SET folio = ? WHERE id = ?')->execute([$folio, $id]);
        registrarBitacora('pacientes', $id, 'crear', null, null, $folio);
        $pdo->commit();
        require_once __DIR__ . '/../app/servicios/cuenta/push.php';
        evaluarUmbralesPush('pacientes');   // aviso por umbral "N pacientes nuevos" (solo encola)

        $nombreCompleto = trim("{$datos['nombre']} {$datos['primer_apellido']} {$datos['segundo_apellido']}");
        mensajeFlash('exito', "Paciente registrado: $nombreCompleto (expediente $folio).");
        recordarEnvio('/pacientes/?nuevo=' . $id);
        redirigir('/pacientes/?nuevo=' . $id);
    }
}

$seccionActiva  = 'pacientes';
$clavePresencia = $original ? 'paciente:' . $idEdicion : 'pacientes:registro';
if ($original) {
    $nombreOriginal = trim("{$original['nombre']} {$original['primer_apellido']} {$original['segundo_apellido']}");
    $tituloPagina   = 'Editar datos';
    $migas          = [
        ['texto' => 'Pacientes', 'url' => '/pacientes/'],
        ['texto' => $nombreOriginal, 'url' => '/pacientes/ver.php?id=' . $idEdicion],
        ['texto' => 'Editar datos'],
    ];
} else {
    $tituloPagina = 'Registrar paciente';
    $migas        = [['texto' => 'Pacientes', 'url' => '/pacientes/'], ['texto' => 'Registrar paciente']];
}
$notificacion  = $errores
    ? ['tipo' => 'error', 'texto' => $errores['general'] ?? 'Revisa los campos marcados en rojo.']
    : null;

// Ayudas para pintar valores y errores
$v     = fn (string $c): string => e($datos[$c]);
$error = fn (string $c): string => isset($errores[$c]) ? ' campo--error' : '';
$aviso = fn (string $c): string => isset($errores[$c]) ? '<span class="campo__ayuda" data-original="">' . e($errores[$c]) . '</span>' : '';
$sel   = fn (string $c, string $valor): string => $datos[$c] === $valor ? ' selected' : '';

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina__cabecera">
  <span class="pagina__meta"><?= iconoRelleno('documento') ?> <?= $original ? e($original['folio']) : 'Nuevo expediente' ?></span>
  <h1 class="pagina__titulo"><?= $original ? 'Editar datos del paciente' : 'Registro de pacientes' ?></h1>
  <button type="button" class="dictado-mostrar" data-dictado-mostrar title="Llenar el formulario por voz (Alt+Shift+D)"><?= icono('microfono') ?> Dictar <kbd class="tecla">Alt ⇧ D</kbd></button>
</div>

<form method="post" id="form-paciente" novalidate data-borrador="<?= $original ? 'paciente-' . $idEdicion : 'paciente-nuevo' ?>">
  <?= campoCsrf() ?>
  <?= campoEnvio() ?>
  <?php if ($original): ?><input type="hidden" name="id" value="<?= $idEdicion ?>"><?php endif; ?>

  <!-- Datos de identificación (NOM-004 6.1.1 / NOM-024 6.5) -->
  <section class="seccion">
    <h2 class="seccion__titulo"><?= iconoRelleno('usuario') ?> Datos de identificación</h2>
    <div class="seccion__cuerpo">
    <div class="rejilla rejilla--5">

      <div class="campo<?= $error('nombre') ?>">
        <label class="campo__etiqueta" for="nombre">Nombre(s) <span class="requerido">*</span></label>
        <div class="campo__control"><input id="nombre" name="nombre" value="<?= $v('nombre') ?>" maxlength="80" required autocomplete="off"></div>
        <?= $aviso('nombre') ?>
      </div>

      <div class="campo<?= $error('primer_apellido') ?>">
        <label class="campo__etiqueta" for="primer_apellido">Apellido paterno <span class="requerido">*</span></label>
        <div class="campo__control"><input id="primer_apellido" name="primer_apellido" value="<?= $v('primer_apellido') ?>" maxlength="60" required autocomplete="off"></div>
        <?= $aviso('primer_apellido') ?>
      </div>

      <div class="campo">
        <label class="campo__etiqueta" for="segundo_apellido">Apellido materno</label>
        <div class="campo__control"><input id="segundo_apellido" name="segundo_apellido" value="<?= $v('segundo_apellido') ?>" maxlength="60" autocomplete="off"></div>
      </div>

      <div class="campo<?= $error('fecha_nacimiento') ?>">
        <label class="campo__etiqueta" for="fecha_nacimiento">Fecha de nacimiento <span class="requerido">*</span></label>
        <div class="campo__control">
          <?= icono('calendario') ?>
          <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" value="<?= $v('fecha_nacimiento') ?>" required max="<?= date('Y-m-d') ?>">
        </div>
        <?= $aviso('fecha_nacimiento') ?>
      </div>

      <div class="campo<?= $error('sexo') ?>">
        <label class="campo__etiqueta" for="sexo">Sexo <span class="requerido">*</span></label>
        <div class="campo__control campo__control--select">
          <select id="sexo" name="sexo" required>
            <option value="">Selecciona</option>
            <?php foreach (SEXOS as $clave => $texto): ?>
              <option value="<?= $clave ?>"<?= $sel('sexo', $clave) ?>><?= e($texto) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?= $aviso('sexo') ?>
      </div>

    </div>
    <div class="rejilla">

      <div class="campo col-6<?= $error('curp') ?>">
        <label class="campo__etiqueta" for="curp">CURP</label>
        <div class="campo__control">
          <input id="curp" name="curp" value="<?= $v('curp') ?>" maxlength="18" autocomplete="off" spellcheck="false">
        </div>
        <?= $aviso('curp') ?>
      </div>

      <div class="campo col-3<?= $error('entidad_nacimiento') ?>">
        <label class="campo__etiqueta" for="entidad_nacimiento">Entidad de nacimiento</label>
        <div class="campo__control campo__control--select">
          <select id="entidad_nacimiento" name="entidad_nacimiento">
            <option value="">Selecciona</option>
            <?php foreach (ENTIDADES as $clave => $texto): ?>
              <option value="<?= $clave ?>"<?= $sel('entidad_nacimiento', $clave) ?>><?= e($texto) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?= $aviso('entidad_nacimiento') ?>
      </div>

      <div class="campo col-3">
        <label class="campo__etiqueta" for="nacionalidad">Nacionalidad</label>
        <div class="campo__control"><input id="nacionalidad" name="nacionalidad" value="<?= $v('nacionalidad') ?>" maxlength="40" autocomplete="off"></div>
      </div>

    </div>
    </div>
  </section>

  <!-- Contacto y domicilio -->
  <section class="seccion">
    <h2 class="seccion__titulo"><?= iconoRelleno('casa') ?> Contacto y domicilio</h2>
    <div class="seccion__cuerpo rejilla">

      <div class="campo col-6">
        <label class="campo__etiqueta" for="telefono">Teléfono</label>
        <div class="campo__control"><input type="tel" id="telefono" name="telefono" value="<?= $v('telefono') ?>" maxlength="20" autocomplete="off"></div>
      </div>

      <div class="campo col-6<?= $error('correo') ?>">
        <label class="campo__etiqueta" for="correo">Correo electrónico</label>
        <div class="campo__control"><input type="email" id="correo" name="correo" value="<?= $v('correo') ?>" maxlength="120" autocomplete="off"></div>
        <?= $aviso('correo') ?>
      </div>

      <div class="campo col-6">
        <label class="campo__etiqueta" for="domicilio">Domicilio</label>
        <div class="campo__control"><input id="domicilio" name="domicilio" value="<?= $v('domicilio') ?>" maxlength="200" autocomplete="off"></div>
      </div>

      <div class="campo col-6">
        <label class="campo__etiqueta" for="municipio_estado">Municipio / Estado</label>
        <div class="campo__control"><input id="municipio_estado" name="municipio_estado" value="<?= $v('municipio_estado') ?>" maxlength="120" autocomplete="off"></div>
      </div>

      <div class="campo col-6">
        <label class="campo__etiqueta" for="contacto_emergencia">Contacto de emergencia</label>
        <div class="campo__control"><input id="contacto_emergencia" name="contacto_emergencia" value="<?= $v('contacto_emergencia') ?>" maxlength="120" autocomplete="off"></div>
      </div>

      <div class="campo col-6">
        <label class="campo__etiqueta" for="telefono_emergencia">Teléfono de emergencia</label>
        <div class="campo__control"><input type="tel" id="telefono_emergencia" name="telefono_emergencia" value="<?= $v('telefono_emergencia') ?>" maxlength="20" autocomplete="off"></div>
      </div>

    </div>
  </section>

  <!-- Información clínica inicial -->
  <section class="seccion">
    <h2 class="seccion__titulo"><?= iconoRelleno('escudo') ?> Información clínica inicial</h2>
    <div class="seccion__cuerpo rejilla">

      <div class="campo col-3">
        <label class="campo__etiqueta" for="alergias">Alergias</label>
        <div class="campo__control"><input id="alergias" name="alergias" value="<?= $v('alergias') ?>" maxlength="255" autocomplete="off"></div>
      </div>

      <div class="campo col-3<?= $error('grupo_sanguineo') ?>">
        <label class="campo__etiqueta" for="grupo_sanguineo">Grupo sanguíneo</label>
        <div class="campo__control campo__control--select">
          <select id="grupo_sanguineo" name="grupo_sanguineo">
            <option value="">Selecciona</option>
            <?php foreach (GRUPOS_SANGUINEOS as $grupo): ?>
              <option value="<?= e($grupo) ?>"<?= $sel('grupo_sanguineo', $grupo) ?>><?= e($grupo) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?= $aviso('grupo_sanguineo') ?>
      </div>

      <div class="campo col-3">
        <label class="campo__etiqueta" for="padecimientos_cronicos">Padecimientos crónicos</label>
        <div class="campo__control"><input id="padecimientos_cronicos" name="padecimientos_cronicos" value="<?= $v('padecimientos_cronicos') ?>" maxlength="255" autocomplete="off"></div>
      </div>

      <div class="campo col-3">
        <label class="campo__etiqueta" for="medicamentos_actuales">Medicamentos actuales</label>
        <div class="campo__control"><input id="medicamentos_actuales" name="medicamentos_actuales" value="<?= $v('medicamentos_actuales') ?>" maxlength="255" autocomplete="off"></div>
      </div>

    </div>
  </section>

  <?php if ($propiedadesExtra || puede('configurar')): ?>
  <!-- Propiedades agregadas a la base de pacientes -->
  <section class="seccion">
    <h2 class="seccion__titulo"><?= iconoRelleno('lista') ?> Evaluación
      <?php if (puede('configurar')): ?><a class="seccion__accion" href="<?= URL_BASE ?>/pacientes/#propiedades" title="Agregar, renombrar o quitar propiedades de la base de pacientes">Editar formato</a><?php endif; ?>
    </h2>
    <div class="seccion__cuerpo rejilla">
      <?php if (!$propiedadesExtra): ?>
        <p class="seccion__vacio col-12">Las propiedades que agregues a la base de pacientes aparecen aquí y en cada expediente.</p>
      <?php endif; ?>
      <?php foreach ($propiedadesExtra as $prop):
          $clave = $prop['clave'];
          $idCampo = 'extra_' . $clave;
          $valor = $extras[$clave] ?? '';
          $claseError = isset($errores[$idCampo]) ? ' campo--error' : ''; ?>
        <?php if ($prop['tipo'] === 'casilla'): ?>
          <div class="campo col-6<?= $claseError ?>">
            <label class="casilla"><input type="checkbox" name="extra[<?= e($clave) ?>]" value="1"<?= $valor === true ? ' checked' : '' ?>> <?= e($prop['nombre']) ?></label>
          </div>
        <?php else: ?>
          <div class="campo col-6<?= $claseError ?>">
            <label class="campo__etiqueta" for="<?= e($idCampo) ?>"><?= e($prop['nombre']) ?></label>
            <?php if ($prop['tipo'] === 'seleccion'): ?>
              <div class="campo__control campo__control--select">
                <select id="<?= e($idCampo) ?>" name="extra[<?= e($clave) ?>]">
                  <option value="">Selecciona</option>
                  <?php foreach ($prop['opciones'] ?? [] as $opcion): ?>
                    <option value="<?= e($opcion) ?>"<?= (string) $valor === $opcion ? ' selected' : '' ?>><?= e($opcion) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <div class="campo__control"><input id="<?= e($idCampo) ?>" name="extra[<?= e($clave) ?>]" value="<?= e((string) $valor) ?>"
                <?= $prop['tipo'] === 'fecha' ? 'type="date"' : ($prop['tipo'] === 'numero' ? 'inputmode="decimal"' : 'maxlength="500"') ?> autocomplete="off"></div>
            <?php endif; ?>
            <?= isset($errores[$idCampo]) ? '<span class="campo__ayuda">' . e($errores[$idCampo]) . '</span>' : '' ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Motivo de consulta -->
  <section class="seccion">
    <h2 class="seccion__titulo"><?= iconoRelleno('mensaje') ?> Motivo de consulta</h2>
    <div class="seccion__cuerpo rejilla">
      <div class="campo col-12">
        <label class="campo__etiqueta" for="motivo_consulta">Motivo de consulta / observaciones iniciales</label>
        <div class="campo__control campo__control--area">
          <textarea id="motivo_consulta" name="motivo_consulta" rows="2"><?= $v('motivo_consulta') ?></textarea>
        </div>
      </div>
    </div>
  </section>

  <div class="pie-formulario">
    <div class="pie-formulario__legal">
      <label class="casilla"<?= isset($errores['confirmacion']) ? ' style="color: var(--peligro)"' : '' ?>>
        <input type="checkbox" name="confirmacion" id="confirmacion" required>
        Confirmo que los datos fueron proporcionados por el paciente o su representante
      </label>
      <span class="nota-privacidad"><?= icono('candado') ?> Aviso de privacidad y control de acceso</span>
    </div>
    <div class="pie-formulario__acciones">
      <a class="boton boton--fantasma" href="<?= URL_BASE ?><?= $original ? '/pacientes/ver.php?id=' . $idEdicion : '/pacientes/' ?>">Cancelar</a>
      <button type="submit" class="boton boton--secundario"><?= $original ? 'Guardar cambios' : 'Guardar paciente' ?></button>
    </div>
  </div>

</form>

<?php require __DIR__ . '/../app/vistas/componentes/dictado.php'; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
