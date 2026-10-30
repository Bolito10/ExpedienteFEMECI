<?php
/**
 * Sincronización local → servidor central (y central → local).
 *
 * Cada consultorio corre su propia instalación y trabaja sin internet. Lo que se captura queda en
 * la cola `sincronizacion_cola`, que llenan triggers de MariaDB (database/sincronizacion_triggers.php):
 * así ningún camino de escritura se escapa (formularios, API, asistente de IA, importaciones, root).
 * Cuando hay conexión, sincronizar():
 *   1. Sube: arma lotes con lo pendiente (el último estado de cada registro), los firma con
 *      HMAC-SHA256 y los manda a {central}/api/sincronizacion/recibir.php. Solo marca como enviado
 *      lo que el central confirma con un acuse firmado; lo demás se reintenta con espera creciente.
 *   2. Baja: pide a {central}/api/sincronizacion/cambios.php lo que otros consultorios (o el propio
 *      central) cambiaron desde el último cursor y lo aplica igual que el central aplica lo recibido.
 * El central es otra instalación del mismo código: recibe con sincAplicarCambios(), idempotente por
 * uuid, la última escritura gana por sinc_modificado_en y el historial clínico solo se agrega.
 *
 * Instituciones: la configuración (dirección y token) es de cada institución y cada token pertenece
 * a una sola. El emisor solo manda la cola de su institución; el central identifica el token por su
 * huella (cabecera X-Sinc-Clave), impone la institución de ese token a todo lo que recibe, rechaza
 * tocar registros de otra institución y solo entrega el diario de esa institución. Sin institución
 * (instalaciones anteriores) la clave es 0 en `configuracion` y NULL en los datos.
 *
 * Seguridad: los tokens viven en privado/sincronizacion.php (bloqueado a la web, nunca en la base).
 * Cada petición lleva nodo, huella del token, marca de tiempo (±5 min) y nonce de un solo uso; la
 * respuesta va firmada con el nonce de la petición, así un acuse no se puede falsificar ni reutilizar.
 */
require_once __DIR__ . '/tablas.php';

const SINC_ARCHIVO_TOKEN   = __DIR__ . '/../../../privado/sincronizacion.php';
const SINC_RUTA_RECIBIR    = '/api/sincronizacion/recibir.php';
const SINC_RUTA_CAMBIOS    = '/api/sincronizacion/cambios.php';
const SINC_MAX_BYTES       = 16 * 1024 * 1024;   // cuerpo máximo que acepta el receptor
const SINC_LOTE_BYTES      = 4 * 1024 * 1024;    // tamaño objetivo de cada lote que se envía
const SINC_LOTE_FILAS      = 300;                // filas de la cola que se leen por lote
const SINC_MAX_CAMBIOS     = 1000;               // cambios máximos por lote recibido
const SINC_VENTANA_FIRMA   = 300;                // tolerancia de la marca de tiempo (s)
const SINC_ESPERA_BASE     = 30;                 // primera espera tras un fallo (s); se duplica
const SINC_ESPERA_MAXIMA   = 3600;
const SINC_RETRASO_DIARIO  = 60;                 // el diario se entrega con 1 min de retraso (transacciones en curso)
const SINC_DIAS_CONSERVAR  = 30;
const SINC_SEGUNDOS_VUELTA = 40;                 // tiempo máximo de cada mitad de una sincronización
const SINC_UUID            = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
const SINC_TOKEN_VALIDO    = '/^[A-Za-z0-9_-]{32,128}$/';
const SINC_ERROR_GRANDE    = 'El registro supera max_allowed_packet de MySQL y no se pudo encolar completo; hay que aumentarlo en my.ini.';

/** Falta un registro referido (p. ej. el paciente de una nota): se reintenta después. */
class SincReferenciaPendiente extends RuntimeException {}
/** El registro o su contenedor fue eliminado: se confirma sin aplicar. */
class SincDescartado extends RuntimeException {}
/** Datos que no se pueden aplicar: se rechaza con el motivo. */
class SincRechazado extends RuntimeException {}

/* ---------- Configuración (por institución; 0 = sin institución o de toda la instalación) ---------- */

function sincClaveInstitucion(?int $institucion): int
{
    return $institucion !== null && $institucion > 0 ? $institucion : 0;
}

/** Institución de la sesión (null si no hay o si la instalación aún no maneja instituciones). */
function sincInstitucionActual(): ?int
{
    $id = function_exists('institucionId') ? institucionId() : (int) (usuarioActual()['institucion_id'] ?? 0);
    return $id ? (int) $id : null;
}

/** Valor de `configuracion` de una institución, sin caché (la sincronización lo cambia a media petición). */
function sincValor(string $clave, ?int $institucion): ?string
{
    $c = db()->prepare('SELECT valor FROM configuracion WHERE institucion_id = ? AND clave = ?');
    $c->execute([sincClaveInstitucion($institucion), $clave]);
    $v = $c->fetchColumn();
    return $v === false ? null : $v;
}

function sincGuardarValor(string $clave, ?string $valor, ?int $institucion): void
{
    db()->prepare(
        'INSERT INTO configuracion (institucion_id, clave, valor, actualizado_por) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado_por = VALUES(actualizado_por)'
    )->execute([sincClaveInstitucion($institucion), $clave, $valor, usuarioActual()['id'] ?? null]);
}

/** Tokens de todas las instituciones: [clave de institución => token]. Se lee como texto (sin caché de PHP). */
function sincTokens(): array
{
    if (!is_file(SINC_ARCHIVO_TOKEN)) {
        return [];
    }
    $lineas = explode("\n", (string) file_get_contents(SINC_ARCHIVO_TOKEN), 3);
    $datos = json_decode($lineas[1] ?? '', true);
    $tokens = [];
    foreach (is_array($datos['tokens'] ?? null) ? $datos['tokens'] : [] as $clave => $token) {
        if (is_string($token) && preg_match(SINC_TOKEN_VALIDO, $token)) {
            $tokens[(int) $clave] = $token;
        }
    }
    return $tokens;
}

function sincToken(?int $institucion): string
{
    return sincTokens()[sincClaveInstitucion($institucion)] ?? '';
}

/** Huella pública del token: identifica la institución sin revelar el token. */
function sincHuella(string $token): string
{
    return substr(hash('sha256', 'sinc-huella:' . $token), 0, 24);
}

function sincGuardarToken(?int $institucion, string $token): void
{
    $tokens = sincTokens();
    $clave = sincClaveInstitucion($institucion);
    if ($token === '') {
        unset($tokens[$clave]);
    } else {
        $tokens[$clave] = $token;
    }
    if (!$tokens) {
        if (is_file(SINC_ARCHIVO_TOKEN)) {
            unlink(SINC_ARCHIVO_TOKEN);
        }
        return;
    }
    // Primera línea: PHP que corta la ejecución si alguien llegara a pedir el archivo; segunda: los tokens
    $contenido = "<?php http_response_code(404); exit; ?>\n"
               . json_encode(['tokens' => (object) $tokens]) . "\n"
               . "Tokens de sincronización por institución (HMAC-SHA256). Se cambian desde Configuración; no se copian a la base.\n";
    $temporal = SINC_ARCHIVO_TOKEN . '.' . bin2hex(random_bytes(4));
    if (file_put_contents($temporal, $contenido, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo guardar el token en privado/.');
    }
    @chmod($temporal, 0600);
    rename($temporal, SINC_ARCHIVO_TOKEN);
}

function sincUuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Identificador de esta instalación (uno solo, se crea una vez). */
function sincNodo(): string
{
    $nodo = sincValor('sinc_nodo', null);
    if (!$nodo || !preg_match(SINC_UUID, $nodo)) {
        $nodo = sincUuid4();
        sincGuardarValor('sinc_nodo', $nodo, null);
    }
    return $nodo;
}

function sincNodoCorto(string $nodo): string
{
    return 'el nodo ' . substr($nodo, 0, 8);
}

function sincHostLocal(string $host): bool
{
    $host = strtolower(trim($host, '[]'));
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
        return true;
    }
    return filter_var($host, FILTER_VALIDATE_IP) !== false
        && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/** null si la dirección sirve; si no, el motivo. */
function sincErrorUrl(string $url): ?string
{
    if ($url === '') {
        return null;
    }
    $p = parse_url($url);
    $esquema = strtolower($p['scheme'] ?? '');
    if (!$p || !in_array($esquema, ['http', 'https'], true) || empty($p['host'])
        || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || strlen($url) > 300) {
        return 'Escribe la dirección completa del servidor central (http:// o https://), sin usuario ni parámetros.';
    }
    if ($esquema === 'http' && !sincHostLocal($p['host'])) {
        return 'Fuera de la red local usa https: los datos clínicos no deben viajar sin cifrar.';
    }
    return null;
}

/* ---------- Firma y transporte ---------- */

function sincFirma(string $token, string ...$partes): string
{
    return hash_hmac('sha256', implode("\n", $partes), $token);
}

/** Espera antes del siguiente intento: 30 s, 1, 2, 4… min, hasta 1 h. */
function sincEspera(int $intentos): int
{
    return min(SINC_ESPERA_MAXIMA, SINC_ESPERA_BASE * 2 ** min(12, max(0, $intentos - 1)));
}

/**
 * POST firmado a otra instalación. Devuelve el JSON de la respuesta solo si viene firmada con el
 * token y el nonce de esta petición; cualquier otra cosa es un fallo (nada se marca como enviado).
 */
function sincPost(string $url, string $cuerpo, string $token, string $nodo): array
{
    $fecha = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $huella = sincHuella($token);
    $cabeceras = [];
    $ch = curl_init($url);
    $opciones = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $cuerpo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Expect:',
            'X-Sinc-Nodo: ' . $nodo,
            'X-Sinc-Clave: ' . $huella,
            'X-Sinc-Fecha: ' . $fecha,
            'X-Sinc-Nonce: ' . $nonce,
            'X-Sinc-Firma: ' . sincFirma($token, $fecha, $nonce, $nodo, $huella, $cuerpo),
        ],
        CURLOPT_HEADERFUNCTION => function ($ch, string $linea) use (&$cabeceras): int {
            if (str_contains($linea, ':')) {
                [$nombre, $valor] = explode(':', $linea, 2);
                $cabeceras[strtolower(trim($nombre))] = trim($valor);
            }
            return strlen($linea);
        },
    ];
    // XAMPP trae su propio paquete de certificados raíz para https
    $certificados = 'C:/xampp/apache/bin/curl-ca-bundle.crt';
    if (!ini_get('curl.cainfo') && is_file($certificados)) {
        $opciones[CURLOPT_CAINFO] = $certificados;
    }
    curl_setopt_array($ch, $opciones);
    $respuesta = curl_exec($ch);
    if ($respuesta === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Sin conexión con el servidor central (' . $error . ').');
    }
    $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $datos = json_decode((string) $respuesta, true);
    if ($codigo !== 200) {
        $motivo = is_array($datos) && is_string($datos['error'] ?? null) ? mb_substr($datos['error'], 0, 200) : 'sin detalle';
        throw new RuntimeException("El servidor central respondió $codigo: $motivo");
    }
    $fechaR = $cabeceras['x-sinc-fecha'] ?? '';
    $firmaR = $cabeceras['x-sinc-firma'] ?? '';
    if ($firmaR === '' || !hash_equals(sincFirma($token, $fechaR, $nonce, 'respuesta', (string) $respuesta), $firmaR)) {
        throw new RuntimeException('La respuesta del servidor central no trae una firma válida (¿el token es el mismo en ambos?).');
    }
    if (abs(time() - (int) $fechaR) > SINC_VENTANA_FIRMA) {
        throw new RuntimeException('La hora del servidor central y la de este equipo difieren más de 5 minutos.');
    }
    if (!is_array($datos) || empty($datos['ok'])) {
        throw new RuntimeException('El servidor central devolvió una respuesta que no se entiende.');
    }
    return $datos;
}

/* ---------- Envío (local → central) y bajada (central → local) ---------- */

/**
 * Sincroniza ahora la institución: sube lo pendiente y baja lo nuevo del central. Un candado de
 * MySQL (GET_LOCK) impide que dos peticiones lo hagan a la vez. $manual ignora la espera entre reintentos.
 */
function sincronizar(?int $institucion, bool $manual): array
{
    $url   = rtrim((string) sincValor('sinc_url', $institucion), '/');
    $token = sincToken($institucion);
    if ($url === '' || $token === '') {
        return ['ok' => false, 'configurada' => false, 'error' => 'Falta la dirección del servidor central o el token.'];
    }
    $proximo = sincValor('sinc_proximo_intento', $institucion);
    if (!$manual && $proximo && strtotime($proximo) > time()) {
        return ['ok' => true, 'configurada' => true, 'en_espera' => true, 'enviados' => 0, 'rechazados' => 0, 'recibidos' => 0];
    }
    $pdo = db();
    $candado = DB_NOMBRE . '.sincronizacion.' . sincClaveInstitucion($institucion);
    $tomado = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $tomado->execute([$candado]);
    if ((int) $tomado->fetchColumn() !== 1) {
        return ['ok' => true, 'configurada' => true, 'ocupada' => true, 'enviados' => 0, 'rechazados' => 0, 'recibidos' => 0];
    }

    $resultado = ['ok' => true, 'configurada' => true, 'enviados' => 0, 'rechazados' => 0, 'recibidos' => 0, 'error' => null];
    try {
        sincGuardarValor('sinc_ultimo_intento', date('Y-m-d H:i:s'), $institucion);
        try {
            $subida = sincSubir($institucion, $url, $token, $manual, $resultado);
            $bajada = sincBajar($institucion, $url, $token);
            $resultado['recibidos'] = $bajada['recibidos'];
            $resultado['error'] = $subida['error'] ?? $bajada['error'];

            sincGuardarValor('sinc_fallos', '0', $institucion);
            sincGuardarValor('sinc_proximo_intento', null, $institucion);
            sincGuardarValor('sinc_ultimo_envio', date('Y-m-d H:i:s'), $institucion);
            sincGuardarValor('sinc_ultimo_error', $resultado['error'], $institucion);
        } catch (Throwable $e) {
            $fallos = (int) sincValor('sinc_fallos', $institucion) + 1;
            sincGuardarValor('sinc_fallos', (string) $fallos, $institucion);
            sincGuardarValor('sinc_proximo_intento', date('Y-m-d H:i:s', time() + sincEspera($fallos)), $institucion);
            sincGuardarValor('sinc_ultimo_error', mb_substr($e->getMessage(), 0, 400), $institucion);
            $resultado['ok'] = false;
            $resultado['error'] = $e->getMessage();
        }
        if ($resultado['enviados'] || $resultado['recibidos']) {
            registrarBitacora('sincronizacion', sincClaveInstitucion($institucion), 'actualizar', 'sincronizar', null, sprintf(
                '%s: %d enviados, %d rechazados, %d recibidos', $manual ? 'Manual' : 'Automática',
                $resultado['enviados'], $resultado['rechazados'], $resultado['recibidos']
            ));
        }
        sincPodar($institucion);
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$candado]);
    }
    return $resultado;
}

/** Marca un grupo de filas de la cola con un fallo y su próximo intento. */
function sincMarcarFallo(array $ids, string $error): void
{
    if (!$ids) {
        return;
    }
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $intentos = db()->prepare("SELECT MAX(intentos) FROM sincronizacion_cola WHERE id IN ($marcas)");
    $intentos->execute($ids);
    $espera = sincEspera((int) $intentos->fetchColumn() + 1);
    db()->prepare("UPDATE sincronizacion_cola SET intentos = intentos + 1, error = ?,
                   proximo_intento = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id IN ($marcas)")
        ->execute([mb_substr($error, 0, 500), $espera, ...$ids]);
}

/**
 * Sube la cola de la institución por lotes. Lanza una excepción si el central no responde (el lote
 * se reintenta con espera creciente); $cuenta lleva lo enviado hasta ese momento.
 */
function sincSubir(?int $institucion, string $url, string $token, bool $manual, array &$cuenta): array
{
    $pdo = db();
    $nodo = sincNodo();
    $enviados = &$cuenta['enviados'];
    $rechazados = &$cuenta['rechazados'];
    $primerError = null;
    $cursor = 0;
    $intentados = [];
    $limite = time() + SINC_SEGUNDOS_VUELTA;

    while (time() < $limite) {
        $consulta = $pdo->prepare(
            'SELECT id, tabla, uuid, operacion, datos, creado_en FROM sincronizacion_cola
              WHERE institucion_id <=> ? AND origen IS NULL AND enviado_en IS NULL AND id > ?'
            . ($manual ? '' : ' AND (proximo_intento IS NULL OR proximo_intento <= NOW())')
            . ' ORDER BY id LIMIT ' . SINC_LOTE_FILAS
        );
        $consulta->execute([$institucion, $cursor]);
        $filas = $consulta->fetchAll();
        if (!$filas) {
            break;
        }

        // Un registro cambiado varias veces viaja una sola vez, con su último estado
        // (en la posición de su primer cambio, para conservar el orden de creación).
        $grupos = [];
        foreach ($filas as $f) {
            $clave = $f['tabla'] . '|' . $f['uuid'];
            if (isset($intentados[$clave])) {
                continue;   // ya se intentó en esta vuelta; si falló, espera su turno
            }
            if ($f['datos'] === null) {
                // MariaDB no pudo armar el JSON (fila mayor que max_allowed_packet): se avisa y no se manda
                sincMarcarFallo([(int) $f['id']], SINC_ERROR_GRANDE);
                $primerError ??= "{$f['tabla']}: " . SINC_ERROR_GRANDE;
                continue;
            }
            $grupos[$clave]['ids'][] = (int) $f['id'];
            $grupos[$clave]['fila'] = $f;
        }
        $grupos = array_values($grupos);
        $lote = [];
        $bytes = 0;
        foreach ($grupos as $grupo) {
            $largo = strlen($grupo['fila']['datos']);
            if ($lote && $bytes + $largo > SINC_LOTE_BYTES) {
                break;
            }
            $bytes += $largo;
            $lote[] = $grupo;
            $intentados[$grupo['fila']['tabla'] . '|' . $grupo['fila']['uuid']] = true;
        }
        // Lo que no cupo se vuelve a leer en la siguiente vuelta
        $cursor = count($lote) < count($grupos) ? $grupos[count($lote)]['ids'][0] - 1 : (int) end($filas)['id'];
        if (!$lote) {
            continue;
        }

        $cambios = array_map(fn ($g) => [
            'ref'       => (string) max($g['ids']),
            'tabla'     => $g['fila']['tabla'],
            'uuid'      => $g['fila']['uuid'],
            'operacion' => $g['fila']['operacion'],
            'datos'     => json_decode($g['fila']['datos'], true),
            'creado_en' => $g['fila']['creado_en'],
        ], $lote);
        $cuerpo = json_encode(['nodo' => $nodo, 'cambios' => $cambios], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        try {
            $respuesta = sincPost($url . SINC_RUTA_RECIBIR, $cuerpo, $token, $nodo);
        } catch (Throwable $e) {
            sincMarcarFallo(array_merge(...array_column($lote, 'ids')), $e->getMessage());
            throw $e;
        }

        $acuses  = is_array($respuesta['acuses'] ?? null) ? $respuesta['acuses'] : [];
        $errores = is_array($respuesta['errores'] ?? null) ? $respuesta['errores'] : [];
        $confirmados = [];
        foreach ($lote as $g) {
            $ref = (string) max($g['ids']);
            if (isset($acuses[$ref])) {
                array_push($confirmados, ...$g['ids']);
                $enviados++;
            } else {
                $error = is_string($errores[$ref] ?? null) ? $errores[$ref] : 'El servidor central no confirmó este registro.';
                sincMarcarFallo($g['ids'], $error);
                $rechazados++;
                $primerError ??= "{$g['fila']['tabla']}: $error";
            }
        }
        if ($confirmados) {
            $marcas = implode(',', array_fill(0, count($confirmados), '?'));
            $pdo->prepare("UPDATE sincronizacion_cola SET enviado_en = NOW(), error = NULL WHERE id IN ($marcas)")->execute($confirmados);
        }
    }
    return ['error' => $primerError];
}

/** Baja los cambios del central (de esta institución) desde el último cursor y los aplica aquí. */
function sincBajar(?int $institucion, string $url, string $token): array
{
    $nodo = sincNodo();
    $recibidos = 0;
    $limite = time() + SINC_SEGUNDOS_VUELTA;
    while (time() < $limite) {
        $desde = (int) sincValor('sinc_cursor_bajada', $institucion);
        $cuerpo = json_encode(['nodo' => $nodo, 'desde' => $desde, 'limite' => SINC_LOTE_FILAS]);
        $respuesta = sincPost($url . SINC_RUTA_CAMBIOS, $cuerpo, $token, $nodo);
        $central = (string) ($respuesta['nodo'] ?? '');
        $cambios = is_array($respuesta['cambios'] ?? null) ? $respuesta['cambios'] : [];
        $hasta = (int) ($respuesta['hasta'] ?? $desde);
        if (!preg_match(SINC_UUID, $central)) {
            throw new RuntimeException('El servidor central no se identificó.');
        }
        if (!$cambios) {
            if ($hasta > $desde) {
                sincGuardarValor('sinc_cursor_bajada', (string) $hasta, $institucion);
            }
            if (empty($respuesta['mas'])) {
                break;
            }
            continue;
        }

        $r = sincAplicarCambios($cambios, $central, $institucion);
        $cursor = $desde;
        $error = null;
        foreach ($cambios as $c) {
            $ref = (string) ($c['ref'] ?? '');
            if (isset($r['errores'][$ref])) {
                $error = ($c['tabla'] ?? '') . ': ' . $r['errores'][$ref];
                // Un cambio que falla 5 veces seguidas se salta (queda en la bitácora) para no detener el resto
                [$refAtorada, $veces] = array_pad(explode(':', (string) sincValor('sinc_bajada_atorada', $institucion)), 2, 0);
                $veces = $refAtorada === $ref ? (int) $veces + 1 : 1;
                if ($veces >= 5) {
                    registrarBitacora('sincronizacion', sincClaveInstitucion($institucion), 'actualizar', 'bajada_omitida', null,
                        mb_substr("{$c['tabla']} {$c['uuid']}: {$r['errores'][$ref]}", 0, 500));
                    sincGuardarValor('sinc_bajada_atorada', null, $institucion);
                    $cursor = (int) $ref;
                    $error = null;
                    continue;
                }
                sincGuardarValor('sinc_bajada_atorada', "$ref:$veces", $institucion);
                break;
            }
            $cursor = (int) $ref;
            if (($r['acuses'][$ref] ?? '') === 'aplicado') {
                $recibidos++;
            }
        }
        if ($error === null) {
            $cursor = max($cursor, $hasta);
        }
        sincGuardarValor('sinc_cursor_bajada', (string) $cursor, $institucion);
        if ($error !== null) {
            return ['recibidos' => $recibidos, 'error' => $error];
        }
        if (empty($respuesta['mas'])) {
            break;
        }
    }
    return ['recibidos' => $recibidos, 'error' => null];
}

/**
 * Borra de la cola de la institución lo enviado hace más de 30 días y lo que llegó del central
 * (un consultorio no sirve su diario a nadie). Las bajas se conservan: evitan revivir lo eliminado.
 */
function sincPodar(?int $institucion): void
{
    db()->prepare("DELETE FROM sincronizacion_cola
                    WHERE institucion_id <=> ? AND operacion <> 'baja' AND creado_en < DATE_SUB(NOW(), INTERVAL ? DAY)
                      AND ((origen IS NULL AND enviado_en IS NOT NULL) OR origen IS NOT NULL) LIMIT 5000")
        ->execute([$institucion, SINC_DIAS_CONSERVAR]);
}

/** Estado para la configuración de la institución: pendientes, último envío, último error. */
function sincEstado(?int $institucion): array
{
    $url = (string) sincValor('sinc_url', $institucion);
    $token = sincToken($institucion);
    $pendientes = db()->prepare('SELECT COUNT(DISTINCT tabla, uuid) FROM sincronizacion_cola
                                  WHERE institucion_id <=> ? AND origen IS NULL AND enviado_en IS NULL');
    $pendientes->execute([$institucion]);
    return [
        'ok'              => true,
        'configurada'     => $url !== '' && $token !== '',
        'url'             => $url,
        'token_final'     => $token !== '' ? '••••' . substr($token, -4) : '',
        'pendientes'      => (int) $pendientes->fetchColumn(),
        'ultimo_envio'    => sincValor('sinc_ultimo_envio', $institucion),
        'ultimo_intento'  => sincValor('sinc_ultimo_intento', $institucion),
        'proximo_intento' => sincValor('sinc_proximo_intento', $institucion),
        'ultimo_error'    => (string) sincValor('sinc_ultimo_error', $institucion),
    ];
}

/* ---------- Aplicar cambios recibidos (central, y local en la bajada) ---------- */

/**
 * Aplica un lote en la institución $institucion, en una sola transacción. Cada cambio va en su propio
 * SAVEPOINT: si uno falla por sus datos se deshace solo ese y se informa; si falla la base (o
 * cualquier otra cosa) se deshace el lote entero y no se confirma nada.
 * Devuelve ['acuses' => [ref => aplicado|omitido|descartado], 'errores' => [ref => motivo]].
 */
function sincAplicarCambios(array $cambios, string $origen, ?int $institucion): array
{
    $pdo = db();
    $acuses = [];
    $errores = [];
    $pendientes = [];
    foreach (array_values($cambios) as $i => $c) {
        $ref = is_array($c) ? (string) ($c['ref'] ?? '') : '';
        if ($ref === '' || strlen($ref) > 40) {
            continue;
        }
        if (!isset(SINC_TABLAS[$c['tabla'] ?? '']) || !is_string($c['uuid'] ?? null) || !preg_match(SINC_UUID, $c['uuid'])
            || !in_array($c['operacion'] ?? '', ['alta', 'cambio', 'baja'], true) || !is_array($c['datos'] ?? null)) {
            $errores[$ref] = 'Cambio mal formado.';
            continue;
        }
        $c['_pos'] = $i;
        $pendientes[] = $c;
    }
    // Las tablas referidas primero; dentro de cada tabla, el orden en que se capturó
    usort($pendientes, fn ($a, $b) => [SINC_TABLAS[$a['tabla']]['orden'], $a['_pos']] <=> [SINC_TABLAS[$b['tabla']]['orden'], $b['_pos']]);

    // Un lote a la vez en esta instalación: dos consultorios que mandan el mismo registro no se pisan
    $candado = DB_NOMBRE . '.sincronizacion.aplicar';
    $tomado = $pdo->prepare('SELECT GET_LOCK(?, 30)');
    $tomado->execute([$candado]);
    if ((int) $tomado->fetchColumn() !== 1) {
        throw new RuntimeException('Otro lote se está aplicando; se reintentará.');
    }
    $modoAnterior = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $pdo->prepare('SET @sinc_origen = ?')->execute([$origen]);
    $pdo->beginTransaction();
    try {
        // Varias pasadas: un cambio cuya referencia llega más adelante en el mismo lote se reintenta
        while ($pendientes) {
            $diferidos = [];
            $avance = false;
            foreach ($pendientes as $c) {
                $ref = (string) $c['ref'];
                $pdo->exec('SAVEPOINT sinc_cambio');
                try {
                    $acuses[$ref] = sincAplicarCambio($c, $origen, $institucion);
                    $pdo->exec('RELEASE SAVEPOINT sinc_cambio');
                    $avance = true;
                } catch (SincReferenciaPendiente $e) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT sinc_cambio');
                    $c['_error'] = $e->getMessage();
                    $diferidos[] = $c;
                } catch (SincDescartado $e) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT sinc_cambio');
                    $acuses[$ref] = 'descartado';
                    $avance = true;
                } catch (SincRechazado $e) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT sinc_cambio');
                    $errores[$ref] = $e->getMessage();
                } catch (PDOException $e) {
                    // Datos (22xxx; MariaDB da 01000 a "Data truncated" en modo estricto) o integridad (23xxx):
                    // se rechaza ese cambio; cualquier otro error (conexión, permisos…) tumba el lote
                    if (!in_array(substr((string) $e->getCode(), 0, 2), ['01', '22', '23'], true)) {
                        throw $e;
                    }
                    $pdo->exec('ROLLBACK TO SAVEPOINT sinc_cambio');
                    $errores[$ref] = sincMensajeBase($e);
                }
            }
            if (!$diferidos || !$avance) {
                foreach ($diferidos as $c) {
                    $errores[(string) $c['ref']] = $c['_error'];
                }
                break;
            }
            $pendientes = $diferidos;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->exec('SET @sinc_origen = NULL, @sinc_fecha_baja = NULL');
        $pdo->prepare('SET SESSION sql_mode = ?')->execute([$modoAnterior]);
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$candado]);
    }
    return ['acuses' => $acuses, 'errores' => $errores];
}

function sincMensajeBase(PDOException $e): string
{
    $codigo = (int) ($e->errorInfo[1] ?? 0);
    return match (true) {
        $codigo === 1062 => 'Dato duplicado en esta instalación (' . (preg_match("/for key '([^']+)'/", $e->getMessage(), $m) ? $m[1] : 'clave única') . ').',
        $codigo === 1452 => 'Falta un registro relacionado.',
        $codigo === 1048 => 'Falta un dato obligatorio' . (preg_match("/Column '([^']+)'/", $e->getMessage(), $m) ? " ($m[1])." : '.'),
        default          => 'Datos no válidos: ' . mb_substr(preg_replace('/^SQLSTATE\[\w+\]: [^:]*: \d+ /', '', $e->getMessage()), 0, 160),
    };
}

/** Normaliza la marca de modificación a 'Y-m-d H:i:s.uuuuuu' (o null si no es válida). */
function sincMarca(mixed $valor): ?string
{
    if (!is_string($valor) || !preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/', $valor, $m)) {
        return null;
    }
    return $m[1] . '.' . str_pad($m[2] ?? '', 6, '0');
}

/** ¿Hay una baja registrada de ese registro? Los uuid no se reutilizan: una baja es definitiva. */
function sincTieneBaja(string $tabla, string $uuid): bool
{
    $c = db()->prepare("SELECT 1 FROM sincronizacion_cola WHERE tabla = ? AND uuid = ? AND operacion = 'baja' LIMIT 1");
    $c->execute([$tabla, $uuid]);
    return (bool) $c->fetchColumn();
}

/** SELECT de una fila por uuid con su institución (pagina_filas la toma de su página). */
function sincBuscar(string $tabla, string $uuid, bool $bloquear = false): array|false
{
    $def = SINC_TABLAS[$tabla];
    $sql = $def['institucion'] === 'pagina'
        ? 'SELECT t.id' . ($def['mutable'] ? ', t.sinc_modificado_en' : '') . ', p.institucion_id FROM pagina_filas t JOIN paginas p ON p.id = t.pagina_id WHERE t.uuid = ?'
        : "SELECT id" . ($def['mutable'] ? ', sinc_modificado_en' : '') . ", institucion_id FROM `$tabla` WHERE uuid = ?";
    $c = db()->prepare($sql . ($bloquear ? ' FOR UPDATE' : ''));
    $c->execute([$uuid]);
    return $c->fetch();
}

/** Corta si el registro existente es de otra institución (aislamiento). */
function sincMismaInstitucion(array $fila, ?int $institucion, string $que): void
{
    $propia = $fila['institucion_id'] !== null ? (int) $fila['institucion_id'] : null;
    if ($propia !== $institucion) {
        throw new SincRechazado("$que pertenece a otra institución de esta instalación.");
    }
}

/** id local de la persona por su correo; si no existe se crea inactiva en la institución (un administrador la aprueba). */
function sincPersona(mixed $persona, string $origen, ?int $institucion): ?int
{
    if ($persona === null) {
        return null;
    }
    if (is_string($persona)) {
        $persona = json_decode($persona, true);
    }
    $correo = is_array($persona) ? mb_strtolower(trim((string) ($persona['correo'] ?? ''))) : '';
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || mb_strlen($correo) > 160) {
        throw new SincRechazado('Una persona del registro no trae un correo válido.');
    }
    $c = db()->prepare('SELECT id FROM usuarios WHERE correo = ?');
    $c->execute([$correo]);
    if ($id = $c->fetchColumn()) {
        return (int) $id;
    }
    $rol = ($persona['rol'] ?? '') !== 'paciente' && isset(ROLES[$persona['rol'] ?? '']) ? $persona['rol'] : 'medico';
    $nombre = mb_substr(trim((string) ($persona['nombre'] ?? '')), 0, 120) ?: $correo;
    $cedula = mb_substr(trim((string) ($persona['cedula'] ?? '')), 0, 20) ?: null;
    db()->prepare('INSERT INTO usuarios (nombre, correo, cedula_profesional, clave_hash, rol, activo, institucion_id) VALUES (?, ?, ?, ?, ?, 0, ?)')
        ->execute([$nombre, $correo, $cedula, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $rol, $institucion]);
    $id = (int) db()->lastInsertId();
    registrarBitacora('usuarios', $id, 'crear', 'sincronizacion', null,
        'Cuenta inactiva creada al recibir registros de ' . sincNodoCorto($origen) . '; espera la aprobación de un administrador');
    return $id;
}

/**
 * Valores de columna a partir del JSON recibido: referencias por uuid → id local (de la misma
 * institución), personas por correo → id. El institucion_id del JSON no se usa: manda el token.
 */
function sincValores(array $def, array $d, string $origen, ?int $institucion): array
{
    $valores = [];
    foreach ($def['columnas'] as $col) {
        if (array_key_exists($col, $d)) {
            $v = $d[$col];
            $valores[$col] = match (true) {
                is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE),
                is_bool($v)  => (int) $v,
                default      => $v,
            };
        }
    }
    foreach ($def['referencias'] as $col => [$referida, $clave]) {
        if (!array_key_exists($clave, $d)) {
            continue;
        }
        $uuid = $d[$clave];
        if ($uuid === null) {
            $valores[$col] = null;
            continue;
        }
        if (!is_string($uuid) || !preg_match(SINC_UUID, $uuid)) {
            throw new SincRechazado("Referencia no válida ($clave).");
        }
        $fila = sincBuscar($referida, $uuid);
        if ($fila === false) {
            if (sincTieneBaja($referida, $uuid)) {
                throw new SincDescartado("El registro de $referida al que pertenece fue eliminado.");
            }
            throw new SincReferenciaPendiente("Todavía no llega el registro de $referida al que pertenece.");
        }
        sincMismaInstitucion($fila, $institucion, "El registro de $referida al que pertenece");
        $valores[$col] = (int) $fila['id'];
    }
    foreach ($def['personas'] as $col => $clave) {
        if (array_key_exists($clave, $d)) {
            $valores[$col] = sincPersona($d[$clave], $origen, $institucion);
        }
    }
    return $valores;
}

/** El folio es del establecimiento: si ya lo usa otro paciente aquí, se agrega el nodo de origen. */
function sincFolioLibre(string $folio, string $origen): string
{
    $existe = db()->prepare('SELECT 1 FROM pacientes WHERE folio = ?');
    $base = mb_substr($folio, 0, 13);
    $sufijo = substr(str_replace('-', '', $origen), 0, 6);
    foreach (['', "-$sufijo", "-{$sufijo}b", "-{$sufijo}c"] as $i => $extra) {
        $candidato = $i === 0 ? $folio : $base . $extra;
        $existe->execute([$candidato]);
        if (!$existe->fetchColumn()) {
            return $candidato;
        }
    }
    throw new SincRechazado("El folio $folio ya existe en esta instalación.");
}

/** INSERT con uuid, institución (si la tabla la lleva) y marca. */
function sincInsertar(string $tabla, string $uuid, array $valores, ?int $institucion, ?string $marca): int
{
    if (SINC_TABLAS[$tabla]['institucion'] === 'columna') {
        $valores['institucion_id'] = $institucion;
    }
    if ($marca !== null) {
        $valores['sinc_modificado_en'] = $marca;
    }
    $columnas = array_keys($valores);
    db()->prepare("INSERT INTO `$tabla` (uuid, `" . implode('`, `', $columnas) . "`) VALUES (?" . str_repeat(', ?', count($columnas)) . ')')
        ->execute([$uuid, ...array_values($valores)]);
    return (int) db()->lastInsertId();
}

/** Aplica un cambio. Devuelve 'aplicado' u 'omitido' (ya estaba esta versión o una más nueva). */
function sincAplicarCambio(array $c, string $origen, ?int $institucion): string
{
    $pdo   = db();
    $tabla = $c['tabla'];
    $def   = SINC_TABLAS[$tabla];
    $uuid  = $c['uuid'];
    $d     = $c['datos'];
    $de    = sincNodoCorto($origen);

    $fila = sincBuscar($tabla, $uuid, true);
    if ($fila !== false) {
        sincMismaInstitucion($fila, $institucion, 'El registro');
    }

    // Historial clínico: solo se agrega (NOM-004 5.10); un uuid que ya existe no se vuelve a insertar
    if (!$def['mutable']) {
        if ($c['operacion'] !== 'alta' || $fila) {
            return 'omitido';
        }
        $id = sincInsertar($tabla, $uuid, sincValores($def, $d, $origen, $institucion), $institucion, null);
        registrarBitacora($tabla, $id, 'crear', 'sincronizacion', null, "Recibido de $de");
        return 'aplicado';
    }

    $marca = sincMarca($d['sinc_modificado_en'] ?? null);
    if ($marca === null) {
        throw new SincRechazado('El cambio no trae su fecha de modificación.');
    }
    if (strtotime(substr($marca, 0, 19)) > time() + 600) {
        throw new SincRechazado('La fecha de modificación está en el futuro: revisa la hora del equipo que lo capturó.');
    }

    if ($c['operacion'] === 'baja') {
        if (!$def['bajas'] || !$fila) {
            return 'omitido';
        }
        if (strcmp((string) $fila['sinc_modificado_en'], $marca) > 0) {
            return 'omitido';   // aquí hubo un cambio posterior a la baja
        }
        $pdo->prepare('SET @sinc_fecha_baja = ?')->execute([$marca]);
        $pdo->prepare("DELETE FROM `$tabla` WHERE id = ?")->execute([$fila['id']]);
        registrarBitacora($tabla, (int) $fila['id'], 'eliminar', 'sincronizacion', null, "Baja recibida de $de");
        return 'aplicado';
    }

    if ($fila) {
        // La última escritura gana; con la misma marca ya es esta versión (reenvío): no se repite
        if (strcmp((string) $fila['sinc_modificado_en'], $marca) >= 0) {
            return 'omitido';
        }
        $valores = sincValores($def, $d, $origen, $institucion);
        foreach ($def['solo_alta'] as $col) {
            unset($valores[$col]);
        }
        // En páginas, la versión es el contador local de ediciones (evita que dos sesiones se sobrescriban)
        $contarVersion = $tabla === 'paginas';
        unset($valores['version']);
        $asignaciones = array_map(fn ($col) => "`$col` = ?", array_keys($valores));
        if ($contarVersion) {
            $asignaciones[] = 'version = version + 1';
        }
        $asignaciones[] = 'sinc_modificado_en = ?';
        $pdo->prepare("UPDATE `$tabla` SET " . implode(', ', $asignaciones) . ' WHERE id = ?')
            ->execute([...array_values($valores), $marca, $fila['id']]);
        registrarBitacora($tabla, (int) $fila['id'], 'actualizar', 'sincronizacion', null, "Cambio recibido de $de");
        return 'aplicado';
    }

    // Alta (o cambio de un registro que aquí todavía no existe)
    if (sincTieneBaja($tabla, $uuid)) {
        throw new SincDescartado('El registro fue eliminado.');
    }
    $valores = sincValores($def, $d, $origen, $institucion);
    if ($tabla === 'pacientes') {
        if (!empty($valores['curp'])) {
            $curp = $pdo->prepare('SELECT folio FROM pacientes WHERE curp = ? AND institucion_id <=> ?');
            $curp->execute([$valores['curp'], $institucion]);
            if ($folio = $curp->fetchColumn()) {
                throw new SincRechazado("La CURP {$valores['curp']} ya pertenece al expediente $folio con otro identificador; hay que conciliarlos.");
            }
        }
        $valores['folio'] = sincFolioLibre((string) ($valores['folio'] ?? ''), $origen);
    }
    $id = sincInsertar($tabla, $uuid, $valores, $institucion, $marca);
    registrarBitacora($tabla, $id, 'crear', 'sincronizacion', null, "Recibido de $de");
    return 'aplicado';
}

/* ---------- Peticiones entre instalaciones (sin sesión) ---------- */

function sincResponder(int $codigo, array $datos, ?string $nonce = null, ?string $token = null): never
{
    $cuerpo = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    http_response_code($codigo);
    header('Content-Type: application/json; charset=UTF-8');
    if ($nonce !== null && $token !== null) {
        $fecha = (string) time();
        header('X-Sinc-Fecha: ' . $fecha);
        header('X-Sinc-Firma: ' . sincFirma($token, $fecha, $nonce, 'respuesta', $cuerpo));
    }
    echo $cuerpo;
    exit;
}

function sincFallar(int $codigo, string $error): never
{
    sincResponder($codigo, ['ok' => false, 'error' => $error]);
}

/**
 * Verifica una petición de otra instalación: tamaño, frecuencia, huella del token (que fija la
 * institución), firma HMAC, marca de tiempo reciente y nonce de un solo uso (anti-replay).
 * Devuelve [nodo, nonce, datos, token, institución]; si algo falla, responde y termina.
 */
function sincVerificarPeticion(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sincFallar(405, 'Solo POST.');
    }
    $ip = ipCliente();
    if (!dentroDelLimite('sinc:peticion:ip:' . $ip, 120, 60)) {
        sincFallar(429, 'Demasiadas peticiones. Espera un momento.');
    }
    if (limiteSuperado('sinc:firma:ip:' . $ip, 10)) {
        sincFallar(429, 'Demasiadas firmas no válidas. Espera unos minutos.');
    }
    $tokens = sincTokens();
    if (!$tokens) {
        sincFallar(503, 'Esta instalación no tiene configurada la sincronización.');
    }
    $largo = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($largo <= 0 || $largo > SINC_MAX_BYTES) {
        sincFallar(413, 'El lote está vacío o es demasiado grande.');
    }
    $cuerpo = (string) file_get_contents('php://input', false, null, 0, SINC_MAX_BYTES + 1);
    if (strlen($cuerpo) !== $largo) {
        sincFallar(400, 'El lote llegó incompleto.');
    }

    $nodo   = (string) ($_SERVER['HTTP_X_SINC_NODO'] ?? '');
    $huella = (string) ($_SERVER['HTTP_X_SINC_CLAVE'] ?? '');
    $fecha  = (string) ($_SERVER['HTTP_X_SINC_FECHA'] ?? '');
    $nonce  = (string) ($_SERVER['HTTP_X_SINC_NONCE'] ?? '');
    $firma  = (string) ($_SERVER['HTTP_X_SINC_FIRMA'] ?? '');
    if (!preg_match(SINC_UUID, $nodo) || !preg_match('/^[0-9a-f]{24}$/', $huella) || !ctype_digit($fecha)
        || !preg_match('/^[0-9a-f]{32}$/', $nonce) || !preg_match('/^[0-9a-f]{64}$/', $firma)) {
        contarIntento('sinc:firma:ip:' . $ip, 600);
        sincFallar(401, 'Faltan los datos de la firma.');
    }
    // La huella elige el token y, con él, la institución; sin coincidencia, la firma no puede ser válida
    $institucion = null;
    $token = null;
    foreach ($tokens as $clave => $candidato) {
        if (hash_equals(sincHuella($candidato), $huella)) {
            $token = $candidato;
            $institucion = $clave > 0 ? $clave : null;
        }
    }
    if ($token === null || !hash_equals(sincFirma($token, $fecha, $nonce, $nodo, $huella, $cuerpo), $firma)) {
        contarIntento('sinc:firma:ip:' . $ip, 600);
        sincFallar(401, 'Firma no válida.');
    }
    if (abs(time() - (int) $fecha) > SINC_VENTANA_FIRMA) {
        sincFallar(401, 'La marca de tiempo no es reciente: revisa la hora de los equipos.');
    }
    // El nonce solo vale una vez dentro de la ventana (la marca de tiempo descarta lo más viejo)
    $nuevo = db()->prepare('INSERT IGNORE INTO limites (clave, intentos, ventana_fin) VALUES (?, 1, DATE_ADD(NOW(), INTERVAL ? SECOND))');
    $nuevo->execute([hash('sha256', 'sinc:nonce:' . $nonce), 2 * SINC_VENTANA_FIRMA]);
    if ($nuevo->rowCount() === 0) {
        sincFallar(409, 'Petición repetida.');
    }
    if (!dentroDelLimite('sinc:peticion:nodo:' . $nodo, 60, 60)) {
        sincFallar(429, 'Demasiados lotes de este consultorio. Espera un momento.');
    }
    $datos = json_decode($cuerpo, true);
    if (!is_array($datos) || ($datos['nodo'] ?? null) !== $nodo) {
        sincFallar(400, 'Lote mal formado.');
    }
    return [$nodo, $nonce, $datos, $token, $institucion];
}
