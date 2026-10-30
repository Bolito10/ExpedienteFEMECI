<?php
/**
 * Guarda un campo editado en línea desde la tabla de pacientes.
 * Recibe JSON {id, campo, valor} con el token CSRF en la cabecera X-CSRF-Token.
 * Responde JSON {ok, valor, texto} o {ok: false, error}.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
exigirPermiso('editar_paciente');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ['ok' => false, 'error' => 'Método no permitido.']);
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}

$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
$id      = (int) ($entrada['id'] ?? 0);
$campo   = (string) ($entrada['campo'] ?? '');
$valor   = trim((string) ($entrada['valor'] ?? ''));

// Campos editables en la tabla y su validación
$editables = [
    'curp'                   => ['max' => 18],
    'sexo'                   => ['opciones' => array_keys(SEXOS), 'obligatorio' => true],
    'telefono'               => ['max' => 20],
    'alergias'               => ['max' => 255],
    'contacto_emergencia'    => ['max' => 120],
    'telefono_emergencia'    => ['max' => 20],
    'padecimientos_cronicos' => ['max' => 255],
    'grupo_sanguineo'        => ['opciones' => GRUPOS_SANGUINEOS],
    'entidad_nacimiento'     => ['opciones' => array_keys(ENTIDADES)],
    'nacionalidad'           => ['max' => 40],
    'correo'                 => ['max' => 120, 'correo' => true],
    'domicilio'              => ['max' => 200],
    'municipio_estado'       => ['max' => 120],
    'medicamentos_actuales'  => ['max' => 255],
    'motivo_consulta'        => ['max' => 2000],
];

if (str_starts_with($campo, 'extra_')) {
    $definicion = null;
    foreach (json_decode((string) config('pacientes_propiedades'), true) ?: [] as $prop) {
        if ('extra_' . $prop['clave'] === $campo) {
            $definicion = $prop;
        }
    }
    if (!$definicion) {
        responder(400, ['ok' => false, 'error' => 'La propiedad ya no existe.']);
    }
    $consulta = db()->prepare('SELECT extras FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
    $consulta->execute([$id, institucionId()]);
    $fila = $consulta->fetch() ?: responder(404, ['ok' => false, 'error' => 'El paciente no existe o fue dado de baja.']);
    $extras = json_decode((string) $fila['extras'], true) ?: [];
    $nuevo = match ($definicion['tipo']) {
        'numero'    => $valor === '' ? null : (is_numeric($valor) ? $valor + 0 : responder(422, ['ok' => false, 'error' => 'Escribe un número.'])),
        'fecha'     => $valor === '' ? null : (DateTime::createFromFormat('!Y-m-d', $valor) ? $valor : responder(422, ['ok' => false, 'error' => 'Usa el formato AAAA-MM-DD.'])),
        'casilla'   => $valor === '' ? null : $valor === '1',
        'seleccion' => $valor === '' ? null : (in_array($valor, $definicion['opciones'] ?? [], true) ? $valor : responder(422, ['ok' => false, 'error' => 'Selecciona una opción de la lista.'])),
        default     => $valor === '' ? null : mb_substr($valor, 0, 500),
    };
    $anterior = $extras[$definicion['clave']] ?? null;
    if ($nuevo === null) {
        unset($extras[$definicion['clave']]);
    } else {
        $extras[$definicion['clave']] = $nuevo;
    }
    db()->prepare('UPDATE pacientes SET extras = ? WHERE id = ? AND institucion_id = ?')->execute([$extras ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null, $id, institucionId()]);
    registrarBitacora('pacientes', $id, 'actualizar', mb_substr($definicion['nombre'], 0, 60), is_scalar($anterior) ? (string) $anterior : null, is_bool($nuevo) ? ($nuevo ? 'Sí' : 'No') : (string) $nuevo);
    responder(200, ['ok' => true, 'valor' => is_bool($nuevo) ? ($nuevo ? '1' : '0') : (string) $nuevo, 'texto' => is_bool($nuevo) ? ($nuevo ? 'Sí' : 'No') : (string) $nuevo]);
}

if (!isset($editables[$campo])) {
    responder(400, ['ok' => false, 'error' => 'Este campo no se puede editar desde la tabla.']);
}
$regla = $editables[$campo];

$consulta = db()->prepare("SELECT `$campo` FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL");
$consulta->execute([$id, institucionId()]);
$fila = $consulta->fetch();
if (!$fila) {
    responder(404, ['ok' => false, 'error' => 'El paciente no existe o fue dado de baja.']);
}
$anterior = $fila[$campo];

if ($campo === 'curp') {
    $valor = mb_strtoupper($valor);
}

// ---- Validación ----
if ($valor === '' && !empty($regla['obligatorio'])) {
    responder(422, ['ok' => false, 'error' => 'Este campo es obligatorio.']);
}
if ($valor !== '' && isset($regla['max']) && mb_strlen($valor) > $regla['max']) {
    responder(422, ['ok' => false, 'error' => "Máximo {$regla['max']} caracteres."]);
}
if ($valor !== '' && isset($regla['opciones']) && !in_array($valor, $regla['opciones'], true)) {
    responder(422, ['ok' => false, 'error' => 'Selecciona una opción de la lista.']);
}
if (!empty($regla['correo']) && $valor !== '' && !filter_var($valor, FILTER_VALIDATE_EMAIL)) {
    responder(422, ['ok' => false, 'error' => 'Correo electrónico no válido.']);
}
if ($campo === 'curp' && $valor !== '') {
    if (!curpValida($valor)) {
        responder(422, ['ok' => false, 'error' => 'La CURP no es válida. Revisa el formato y el dígito verificador.']);
    }
    $duplicada = db()->prepare('SELECT folio FROM pacientes WHERE curp = ? AND institucion_id = ? AND id <> ?');
    $duplicada->execute([$valor, institucionId(), $id]);
    if ($folio = $duplicada->fetchColumn()) {
        responder(422, ['ok' => false, 'error' => "Esta CURP ya está registrada (expediente $folio)."]);
    }
}

$nuevo = $valor === '' ? null : $valor;

// ---- Guardado + bitácora ----
if ($nuevo !== $anterior) {
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE pacientes SET `$campo` = ? WHERE id = ? AND institucion_id = ?")->execute([$nuevo, $id, institucionId()]);
    registrarBitacora('pacientes', $id, 'actualizar', $campo, $anterior, $nuevo);
    $pdo->commit();
}

$texto = match ($campo) {
    'sexo'               => SEXOS[$nuevo] ?? '',
    'entidad_nacimiento' => ENTIDADES[$nuevo] ?? '',
    default              => (string) $nuevo,
};
responder(200, ['ok' => true, 'valor' => (string) $nuevo, 'texto' => $texto]);
