<?php
/**
 * Dictado por voz: recibe el audio grabado en el navegador, lo envía al servicio local de IA
 * (127.0.0.1, ningún dato sale del equipo) y devuelve el texto y los campos ya en el formato
 * del formulario de registro.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';

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
if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
// El navegador envía un fragmento cada 5 s mientras se dicta
if (!dentroDelLimite('dictado:usuario:' . usuarioActual()['id'], 240, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiadas solicitudes de dictado. Espera unos minutos.']);
}
$audio = $_FILES['audio'] ?? null;
if (!$audio || $audio['error'] !== UPLOAD_ERR_OK || $audio['size'] === 0) {
    responder(400, ['ok' => false, 'error' => 'No llegó el audio.']);
}
if ($audio['size'] > 25 * 1024 * 1024) {
    responder(413, ['ok' => false, 'error' => 'La grabación es demasiado larga.']);
}
$tipo = (new finfo(FILEINFO_MIME_TYPE))->file($audio['tmp_name']);
if (!preg_match('#^(audio|video)/#', $tipo)) {
    responder(415, ['ok' => false, 'error' => 'El archivo no es audio.']);
}
if (!is_readable(IA_ARCHIVO_TOKEN)) {
    responder(503, ['ok' => false, 'error' => 'El servicio de dictado no está iniciado (ia/iniciar_servicio.bat).']);
}

// ---- Reenvío al servicio local ----
$curl = curl_init(IA_SERVICIO_URL . '/transcribir');
curl_setopt_array($curl, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => file_get_contents($audio['tmp_name']),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: ' . (str_starts_with($tipo, 'video/') ? str_replace('video/', 'audio/', $tipo) : $tipo),
        'X-Token: ' . trim(file_get_contents(IA_ARCHIVO_TOKEN)),
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT        => 120,
]);
$respuesta = curl_exec($curl);
$codigo    = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);

if ($respuesta === false) {
    responder(503, ['ok' => false, 'error' => 'El servicio de dictado no está iniciado (ia/iniciar_servicio.bat).']);
}
$datos = json_decode($respuesta, true);
if ($codigo !== 200 || !is_array($datos)) {
    responder(502, ['ok' => false, 'error' => $datos['error'] ?? 'El servicio de dictado no respondió correctamente.']);
}

responder(200, [
    'ok'       => true,
    'texto'    => $datos['texto'],
    'campos'   => normalizarCampos($datos['campos'] ?? []),
    'duracion' => $datos['duracion_audio'] ?? null,
]);

/* ---------- Del texto extraído al formato de cada campo del formulario ---------- */

function normalizarCampos(array $campos): array
{
    // Un "CURP" de 10 dígitos es en realidad un teléfono dictado sin pausas
    if (isset($campos['curp']) && preg_match('/^\d{10}$/', preg_replace('/\D/', '', $campos['curp']))
        && !preg_match('/[A-Za-z]/', $campos['curp'])) {
        $campos['telefono'] ??= $campos['curp'];
        unset($campos['curp']);
    }
    $salida = [];
    foreach ($campos as $campo => $valor) {
        $valor = trim((string) $valor, " \t\n\r\0\x0B.,;:");
        if ($valor === '') {
            continue;
        }
        $valor = match ($campo) {
            'nombre', 'primer_apellido', 'segundo_apellido', 'contacto_emergencia' => mb_convert_case($valor, MB_CASE_TITLE),
            'fecha_nacimiento' => fechaDictada($valor),
            'sexo'             => sexoDictado($valor),
            'grupo_sanguineo'  => grupoDictado($valor),
            'curp'             => curpValida(mb_strtoupper($valor)) ? mb_strtoupper($valor) : null,
            'correo'           => filter_var(mb_strtolower($valor), FILTER_VALIDATE_EMAIL) ?: null,
            'telefono', 'telefono_emergencia' => telefonoDictado($valor),
            default            => mb_strtoupper(mb_substr($valor, 0, 1)) . mb_substr($valor, 1),
        };
        if ($valor !== null) {
            $salida[$campo] = $valor;
        }
    }
    return $salida;
}

/** "23 de mayo de 1994", "23 mayo 1994", "23/05/1994" → "1994-05-23" (o null si no es una fecha válida). */
function fechaDictada(string $texto): ?string
{
    $meses = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7,
              'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];
    $t = mb_strtolower($texto);
    if (preg_match('/(\d{1,2})\s*(?:de\s+)?(' . implode('|', array_keys($meses)) . ')\s*(?:de\s+|del\s+)?(\d{4})/u', $t, $m)) {
        [$d, $mes, $a] = [(int) $m[1], $meses[$m[2]], (int) $m[3]];
    } elseif (preg_match('#(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})#', $t, $m)) {
        [$d, $mes, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    } else {
        return null;
    }
    if (!checkdate($mes, $d, $a) || sprintf('%04d-%02d-%02d', $a, $mes, $d) > date('Y-m-d')) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $a, $mes, $d);
}

/** Whisper agrupa los dígitos a su manera ("55481 99 330"); un número de 10 dígitos queda como "55 4821 9930". */
function telefonoDictado(string $texto): string
{
    $digitos = preg_replace('/\D/', '', $texto);
    return strlen($digitos) === 10
        ? substr($digitos, 0, 2) . ' ' . substr($digitos, 2, 4) . ' ' . substr($digitos, 6)
        : $texto;
}

function sexoDictado(string $texto): ?string
{
    $t = mb_strtolower($texto);
    return match (true) {
        (bool) preg_match('/^(femenin[oa]|mujer)$/u', $t)   => 'M',
        (bool) preg_match('/^(masculin[oa]|hombre)$/u', $t) => 'H',
        default                                             => null,
    };
}

function grupoDictado(string $texto): ?string
{
    $t = mb_strtolower(str_replace(' ', '', $texto));
    if (!preg_match('/^(ab|a|b|o|0)(positivo|negativo|\+|-)$/u', $t, $m)) {
        return null;
    }
    $grupo = strtoupper($m[1] === '0' ? 'O' : $m[1]) . (in_array($m[2], ['positivo', '+'], true) ? '+' : '-');
    return in_array($grupo, GRUPOS_SANGUINEOS, true) ? $grupo : null;
}
