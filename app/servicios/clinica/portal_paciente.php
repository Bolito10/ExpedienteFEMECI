<?php
/**
 * Portal del paciente: lo que cambió en su expediente, dicho en lenguaje claro.
 *
 * Fuentes (solo lectura): historial_clinico (cada nota, receta o estudio que agrega el personal) y la
 * bitácora del expediente (ficha de identificación, foto y citas). Nunca se muestran las notas y bases
 * de datos internas del equipo (páginas del espacio de trabajo) ni las notas internas de las citas.
 */
require_once __DIR__ . '/../../datos/registros_clinicos.php';
require_once __DIR__ . '/agenda.php';

/** Nombre claro de cada dato de la ficha (columna de pacientes => etiqueta). */
const ETIQUETAS_FICHA = [
    'curp' => 'CURP', 'nombre' => 'Nombre', 'primer_apellido' => 'Primer apellido', 'segundo_apellido' => 'Segundo apellido',
    'fecha_nacimiento' => 'Fecha de nacimiento', 'sexo' => 'Sexo', 'entidad_nacimiento' => 'Entidad de nacimiento',
    'nacionalidad' => 'Nacionalidad', 'telefono' => 'Teléfono', 'correo' => 'Correo electrónico', 'domicilio' => 'Domicilio',
    'municipio_estado' => 'Municipio y estado', 'contacto_emergencia' => 'Contacto de emergencia',
    'telefono_emergencia' => 'Teléfono de emergencia', 'alergias' => 'Alergias', 'grupo_sanguineo' => 'Grupo sanguíneo',
    'padecimientos_cronicos' => 'Padecimientos crónicos', 'medicamentos_actuales' => 'Medicamentos actuales',
    'motivo_consulta' => 'Motivo de consulta', 'confirmacion_datos' => 'Confirmación de datos',
];

/** Icono de cada dato de la ficha (los mismos del perfil). */
const ICONOS_FICHA = [
    'curp' => 'huella', 'fecha_nacimiento' => 'calendario', 'sexo' => 'usuario', 'entidad_nacimiento' => 'ubicacion',
    'nacionalidad' => 'bandera', 'telefono' => 'telefono', 'correo' => 'correo', 'domicilio' => 'casa',
    'municipio_estado' => 'ubicacion', 'contacto_emergencia' => 'usuarios', 'telefono_emergencia' => 'telefono',
    'alergias' => 'alerta', 'grupo_sanguineo' => 'gota', 'padecimientos_cronicos' => 'corazon',
    'medicamentos_actuales' => 'pildora', 'motivo_consulta' => 'clinico',
];

/** Valor de la ficha tal como se lee en el perfil (códigos de sexo y entidad, fechas). */
function valorFichaLegible(string $campo, ?string $valor): string
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return '';
    }
    return match ($campo) {
        'sexo'               => SEXOS[$valor] ?? $valor,
        'entidad_nacimiento' => ENTIDADES[$valor] ?? $valor,
        'fecha_nacimiento'   => preg_match('/^\d{4}-\d{2}-\d{2}/', $valor) ? fechaCorta($valor) : $valor,
        'confirmacion_datos' => $valor === '1' ? 'Sí' : 'No',
        default              => mb_strimwidth($valor, 0, 160, '…'),
    };
}

/**
 * Cambios recientes del expediente, del más reciente al más antiguo.
 * Cada cambio: ['fecha', 'icono', 'texto', 'detalle', 'autor', 'url'].
 */
function cambiosRecientesPaciente(int $pacienteId, int $limite = 15): array
{
    $cambios = [];

    // Lo que el personal agregó al historial clínico (entradas inmutables, con autor y cédula)
    $h = db()->prepare('SELECT id, tipo, titulo, autor_nombre, autor_cedula, creado_en FROM historial_clinico
                         WHERE paciente_id = ? ORDER BY creado_en DESC, id DESC LIMIT ' . (int) $limite);
    $h->execute([$pacienteId]);
    foreach ($h->fetchAll() as $r) {
        $cambios[] = [
            'fecha'   => $r['creado_en'],
            'icono'   => TIPOS_REGISTRO[$r['tipo']]['icono'] ?? 'documento',
            'texto'   => 'Se agregó ' . mb_strtolower(nombreTipoRegistro($r['tipo'], 'documento')),
            'detalle' => preg_replace('/^(Receta|Consentimiento):\s*/u', '', $r['titulo']),
            'autor'   => $r['autor_nombre'] . ($r['autor_cedula'] ? ' · cédula ' . $r['autor_cedula'] : ''),
            'url'     => URL_BASE . '/pacientes/evento.php?id=' . (int) $r['id'],
        ];
    }

    // Ficha de identificación y foto (bitácora). Se omiten las páginas internas del equipo ("pagina"),
    // los avisos de cita (se toman de la bitácora de citas) y los registros del historial (arriba).
    $b = db()->prepare(
        "SELECT b.accion, b.campo, b.valor_anterior, b.valor_nuevo, b.creado_en, u.nombre AS autor
           FROM bitacora b LEFT JOIN usuarios u ON u.id = b.usuario_id
          WHERE b.tabla = 'pacientes' AND b.registro_id = ? AND b.accion IN ('crear', 'actualizar')
            AND (b.campo IS NULL OR (b.campo NOT LIKE 'historial\\_%' AND b.campo NOT IN ('pagina', 'cita')))
          ORDER BY b.creado_en DESC, b.id DESC LIMIT " . (int) $limite
    );
    $b->execute([$pacienteId]);
    foreach ($b->fetchAll() as $r) {
        $campo = (string) $r['campo'];
        if ($r['accion'] === 'crear') {
            $texto = 'Se abrió tu expediente';
            $detalle = $r['valor_nuevo'] ? 'Folio ' . $r['valor_nuevo'] : '';
            $icono = 'documento';
        } elseif ($campo === 'foto') {
            $texto = $r['valor_nuevo'] ? 'Se actualizó tu foto' : 'Se quitó tu foto';
            $detalle = '';
            $icono = 'camara';
        } else {
            // Columna de la ficha o propiedad agregada por el establecimiento (se guarda con su nombre)
            $etiqueta = ETIQUETAS_FICHA[$campo] ?? $campo;
            $antes = valorFichaLegible($campo, $r['valor_anterior']);
            $ahora = valorFichaLegible($campo, $r['valor_nuevo']);
            $texto = match (true) {
                $ahora === '' => 'Se quitó: ' . $etiqueta,
                $antes === '' => 'Se agregó: ' . $etiqueta,
                default       => 'Se actualizó: ' . $etiqueta,
            };
            $detalle = $ahora === '' ? 'Antes: ' . $antes : ($antes === '' ? $ahora : $ahora . ' · antes: ' . $antes);
            $icono = ICONOS_FICHA[$campo] ?? 'editar';
        }
        $cambios[] = ['fecha' => $r['creado_en'], 'icono' => $icono, 'texto' => $texto, 'detalle' => $detalle,
                      'autor' => $r['autor'] ?? '', 'url' => null];
    }

    // Citas del paciente: agendadas, movidas, confirmadas o canceladas (sin las notas internas de la cita)
    $c = db()->prepare(
        "SELECT b.accion, b.campo, b.valor_anterior, b.valor_nuevo, b.creado_en, u.nombre AS autor, c.inicio
           FROM bitacora b JOIN citas c ON c.id = b.registro_id LEFT JOIN usuarios u ON u.id = b.usuario_id
          WHERE b.tabla = 'citas' AND c.paciente_id = ? AND b.campo IN ('inicio', 'estado')
            AND b.accion IN ('crear', 'actualizar', 'eliminar')
          ORDER BY b.creado_en DESC, b.id DESC LIMIT " . (int) $limite
    );
    $c->execute([$pacienteId]);
    $fecha = function (?string $valor): string {
        $valor = substr(trim((string) $valor), 0, 19);   // el valor puede llevar una nota después de la fecha
        return strtotime($valor) ? cuandoCita($valor) : '';
    };
    foreach ($c->fetchAll() as $r) {
        if ($r['accion'] === 'crear') {
            $texto = 'Se agendó una cita';
            $detalle = $fecha($r['valor_nuevo']);
        } elseif ($r['campo'] === 'inicio') {
            $texto = 'Se cambió el horario de una cita';
            $detalle = trim($fecha($r['valor_nuevo']) . ($fecha($r['valor_anterior']) ? ' · antes: ' . $fecha($r['valor_anterior']) : ''));
        } else {
            $texto = 'Cita ' . mb_strtolower(ESTADOS_CITA[$r['valor_nuevo']] ?? (string) $r['valor_nuevo']);
            $detalle = cuandoCita($r['inicio']);
        }
        $cambios[] = ['fecha' => $r['creado_en'], 'icono' => 'calendario', 'texto' => $texto, 'detalle' => $detalle,
                      'autor' => $r['autor'] ?? '', 'url' => URL_BASE . '/portal/citas.php'];
    }

    usort($cambios, fn ($a, $b) => strcmp($b['fecha'], $a['fecha']));
    return array_slice($cambios, 0, $limite);
}

/** Citas del paciente sin las notas internas: próximas (o pasadas con $pasadas = true). */
function citasDelPaciente(int $pacienteId, bool $pasadas = false, int $limite = 50): array
{
    $sql = $pasadas
        ? "SELECT c.id, c.inicio, c.fin, c.motivo, c.estado, u.nombre AS profesional FROM citas c JOIN usuarios u ON u.id = c.profesional_id
            WHERE c.paciente_id = ? AND (c.fin < NOW() OR c.estado IN ('cancelada', 'no_asistio')) ORDER BY c.inicio DESC LIMIT " . (int) $limite
        : "SELECT c.id, c.inicio, c.fin, c.motivo, c.estado, u.nombre AS profesional FROM citas c JOIN usuarios u ON u.id = c.profesional_id
            WHERE c.paciente_id = ? AND c.fin >= NOW() AND c.estado NOT IN ('cancelada', 'no_asistio') ORDER BY c.inicio LIMIT " . (int) $limite;
    $consulta = db()->prepare($sql);
    $consulta->execute([$pacienteId]);
    return $consulta->fetchAll();
}

/** Expediente activo ligado a la cuenta de paciente con sesión, o null. */
function expedienteDelPortal(): ?array
{
    $id = pacienteDeSesion();
    if ($id === null) {
        return null;
    }
    // Acotado también a la institución de la cuenta (institucionId(), app/nucleo/institucion.php).
    // <=> compara también NULL: una cuenta sin institución solo ve un expediente sin institución.
    $consulta = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND institucion_id <=> ? AND eliminado_en IS NULL');
    $consulta->execute([$id, institucionId()]);
    return $consulta->fetch() ?: null;
}
