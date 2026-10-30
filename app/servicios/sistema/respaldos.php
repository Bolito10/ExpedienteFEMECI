<?php
/**
 * Respaldos de la base de datos (NOM-024 6.6: disponibilidad e integridad).
 *
 *   - mysqldump de expediente_femeci (solo esta base, sin CREATE DATABASE) comprimido en
 *     privado/respaldos/respaldo-AAAAMMDD-HHMMSS-motivo.sql.gz, con su suma SHA-256 al lado (.sha256).
 *   - Se conservan los 14 más recientes.
 *   - Automático y perezoso: la primera petición del día lanza en segundo plano mantenimiento/respaldar.php;
 *     un candado (flock) evita que se repita. El Programador de tareas de Windows puede llamar al mismo
 *     script con mantenimiento/respaldo_diario.bat (docs/respaldos.md).
 *   - Restaurar es destructivo: solo administrador, con confirmación escrita y un respaldo previo automático.
 *     Necesita el usuario de MySQL femeci_respaldo (privado/respaldo_bd.php); la app normal no puede borrar tablas.
 */

const CARPETA_RESPALDOS   = __DIR__ . '/../../../privado/respaldos';
const RESPALDOS_CONSERVAR = 14;
const RESPALDO_PATRON     = '/^respaldo-(\d{8})-(\d{6})-(automatico|manual|programado|antes_de_restaurar)\.sql\.gz$/';
const MOTIVOS_RESPALDO    = [
    'automatico'         => 'Automático',
    'programado'         => 'Programado',
    'manual'             => 'Manual',
    'antes_de_restaurar' => 'Antes de restaurar',
];

/**
 * Quién administra los respaldos. Un respaldo contiene la base completa (todas las instituciones), así que
 * con varias instituciones solo lo ven las cuentas de ADMINISTRADORES_SERVIDOR (config/config.php);
 * con una sola institución (o ninguna), cualquier administrador.
 */
function puedeAdministrarRespaldos(): bool
{
    if (!function_exists('esAdministrador') || !esAdministrador()) {
        return false;
    }
    $lista = defined('ADMINISTRADORES_SERVIDOR') ? (array) ADMINISTRADORES_SERVIDOR : [];
    if (in_array((int) usuarioActual()['id'], array_map('intval', $lista), true)) {
        return true;
    }
    try {
        return (int) db()->query('SELECT COUNT(*) FROM instituciones')->fetchColumn() <= 1;
    } catch (PDOException $e) {
        return true;   // sin tabla de instituciones: una sola clínica
    }
}

function carpetaRespaldos(): string
{
    if (!is_dir(CARPETA_RESPALDOS)) {
        @mkdir(CARPETA_RESPALDOS, 0750, true);
    }
    return realpath(CARPETA_RESPALDOS) ?: CARPETA_RESPALDOS;
}

/** Credenciales para mysqldump (el usuario de respaldos o, si no existe, el de la app) o para restaurar (solo el de respaldos). */
function credencialesRespaldo(bool $restaurar = false): ?array
{
    $archivo = __DIR__ . '/../../../privado/respaldo_bd.php';
    if (is_file($archivo)) {
        $c = require $archivo;
        if (is_array($c) && !empty($c['usuario'])) {
            return ['usuario' => (string) $c['usuario'], 'clave' => (string) ($c['clave'] ?? '')];
        }
    }
    return $restaurar ? null : ['usuario' => DB_USUARIO, 'clave' => DB_CLAVE];
}

function restaurarDisponible(): bool
{
    return credencialesRespaldo(true) !== null && is_file(MYSQL_BIN . '/mysql.exe');
}

/** Archivo de opciones temporal: la contraseña no aparece en la línea de comandos (ni en la lista de procesos). */
function archivoOpcionesMysql(array $credenciales): string
{
    $ruta = carpetaRespaldos() . '/.opciones-' . bin2hex(random_bytes(6)) . '.cnf';
    $escapar = fn (string $v): string => '"' . addcslashes($v, "\\\"") . '"';
    $texto = "[client]\nuser=" . $escapar($credenciales['usuario']) . "\npassword=" . $escapar($credenciales['clave'])
           . "\nhost=" . DB_HOST . "\nport=" . DB_PUERTO . "\ndefault-character-set=utf8mb4\n";
    if (!escribirArchivoAtomico($ruta, $texto)) {
        throw new RuntimeException('No se pudo preparar el respaldo (sin permiso de escritura en privado/respaldos).');
    }
    return $ruta;
}

/** Candado exclusivo de respaldos/restauración. $esperar=false regresa null si otro proceso lo tiene. */
function candadoRespaldos(bool $esperar = true)
{
    $f = fopen(carpetaRespaldos() . '/.candado', 'c');
    if (!$f) {
        return null;
    }
    if (!flock($f, LOCK_EX | ($esperar ? 0 : LOCK_NB))) {
        fclose($f);
        return null;
    }
    return $f;
}

function soltarCandado($candado): void
{
    if ($candado) {
        flock($candado, LOCK_UN);
        fclose($candado);
    }
}

/**
 * Crea un respaldo. Devuelve ['ok' => true, 'archivo', 'bytes', 'sha256'] o ['ok' => false, 'error'].
 * $candado: el que ya tiene quien llama (restaurar), para no esperarse a sí mismo.
 */
function crearRespaldo(string $motivo, $candado = null): array
{
    if (!isset(MOTIVOS_RESPALDO[$motivo])) {
        return ['ok' => false, 'error' => 'Motivo de respaldo no válido.'];
    }
    $propio = $candado === null;
    $candado ??= candadoRespaldos();
    if (!$candado) {
        return ['ok' => false, 'error' => 'No se pudo tomar el candado de respaldos.'];
    }
    @set_time_limit(600);
    $carpeta  = carpetaRespaldos();
    $nombre   = 'respaldo-' . date('Ymd-His') . '-' . $motivo . '.sql.gz';
    // Dos respaldos en el mismo segundo (manual justo después de uno automático): se espera al siguiente
    while (is_file("$carpeta/$nombre")) {
        sleep(1);
        $nombre = 'respaldo-' . date('Ymd-His') . '-' . $motivo . '.sql.gz';
    }
    $parcial  = "$carpeta/.$nombre.parcial";
    $errores  = "$carpeta/.$nombre.err";
    $opciones = null;
    try {
        $mysqldump = MYSQL_BIN . '/mysqldump.exe';
        if (!is_file($mysqldump)) {
            throw new RuntimeException('No se encontró mysqldump en ' . MYSQL_BIN . '.');
        }
        $credenciales = credencialesRespaldo();
        $opciones = archivoOpcionesMysql($credenciales);
        // Los triggers (cola de sincronización) solo se pueden leer con el usuario de respaldos; con el de la app
        // se omiten y, al restaurar, se vuelven a generar (regenerarTriggers)
        $conTriggers = $credenciales['usuario'] !== DB_USUARIO;
        $proceso = proc_open([
            $mysqldump, '--defaults-extra-file=' . $opciones,
            '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--hex-blob',
            '--default-character-set=utf8mb4', '--add-drop-table', $conTriggers ? '--triggers' : '--skip-triggers',
            DB_NOMBRE,
        ], [1 => ['pipe', 'w'], 2 => ['file', $errores, 'w']], $tuberias, null, null, ['bypass_shell' => true]);
        if (!is_resource($proceso)) {
            throw new RuntimeException('No se pudo ejecutar mysqldump.');
        }
        $gz = gzopen($parcial, 'wb6');
        $final = '';
        while (!feof($tuberias[1])) {
            $trozo = fread($tuberias[1], 1 << 16);
            if ($trozo === false || $trozo === '') {
                continue;
            }
            gzwrite($gz, $trozo);
            $final = substr($final . $trozo, -400);
        }
        fclose($tuberias[1]);
        gzclose($gz);
        $codigo = proc_close($proceso);
        $detalle = trim((string) @file_get_contents($errores));
        // mysqldump escribe "-- Dump completed" al final solo si terminó bien
        if ($codigo !== 0 || !str_contains($final, 'Dump completed')) {
            throw new RuntimeException('mysqldump no terminó bien' . ($detalle ? ': ' . mb_strimwidth($detalle, 0, 300, '…') : '.'));
        }
        // Al disco antes de darle su nombre definitivo
        $f = fopen($parcial, 'ab');
        fsync($f);
        fclose($f);
        if (!@rename($parcial, "$carpeta/$nombre")) {
            throw new RuntimeException('No se pudo guardar el respaldo.');
        }
        $sha = hash_file('sha256', "$carpeta/$nombre");
        escribirArchivoAtomico("$carpeta/$nombre.sha256", "$sha  $nombre\n");
        rotarRespaldos();
        escribirArchivoAtomico("$carpeta/ultimo.json", json_encode(['fecha' => date('Y-m-d'), 'momento' => date('c'), 'archivo' => $nombre]));
        if (function_exists('registrarBitacora')) {
            try {
                registrarBitacora('respaldos', 0, 'crear', $motivo, null, $nombre);
            } catch (Throwable $e) {
                error_log('[respaldos] bitácora: ' . $e->getMessage());
            }
        }
        return ['ok' => true, 'archivo' => $nombre, 'bytes' => filesize("$carpeta/$nombre"), 'sha256' => $sha];
    } catch (Throwable $e) {
        error_log('[respaldos] ' . $e->getMessage());
        @unlink($parcial);
        return ['ok' => false, 'error' => $e->getMessage()];
    } finally {
        if ($opciones) {
            @unlink($opciones);
        }
        @unlink($errores);
        if ($propio) {
            soltarCandado($candado);
        }
    }
}

/** Deja solo los RESPALDOS_CONSERVAR más recientes (con su .sha256). */
function rotarRespaldos(): void
{
    $carpeta = carpetaRespaldos();
    $archivos = array_values(array_filter(scandir($carpeta) ?: [], fn ($a) => preg_match(RESPALDO_PATRON, $a)));
    rsort($archivos);   // el nombre lleva la fecha: orden alfabético = cronológico
    foreach (array_slice($archivos, RESPALDOS_CONSERVAR) as $viejo) {
        @unlink("$carpeta/$viejo");
        @unlink("$carpeta/$viejo.sha256");
    }
}

/** Respaldos guardados, del más reciente al más antiguo. */
function listarRespaldos(): array
{
    $carpeta = carpetaRespaldos();
    $lista = [];
    foreach (scandir($carpeta) ?: [] as $a) {
        if (!preg_match(RESPALDO_PATRON, $a, $m)) {
            continue;
        }
        $momento = DateTime::createFromFormat('YmdHis', $m[1] . $m[2]);
        $sha = is_file("$carpeta/$a.sha256") ? strtok((string) file_get_contents("$carpeta/$a.sha256"), " \n") : null;
        $lista[] = [
            'archivo' => $a,
            'fecha'   => $momento ? $momento->format('Y-m-d H:i:s') : '',
            'motivo'  => $m[3],
            'bytes'   => (int) filesize("$carpeta/$a"),
            'sha256'  => $sha ?: null,
        ];
    }
    usort($lista, fn ($x, $y) => strcmp($y['archivo'], $x['archivo']));
    return $lista;
}

/** Ruta de un respaldo por su nombre (solo nombres con el patrón; nada de rutas). */
function rutaRespaldo(string $nombre): ?string
{
    if (!preg_match(RESPALDO_PATRON, $nombre)) {
        return null;
    }
    $ruta = carpetaRespaldos() . '/' . $nombre;
    return is_file($ruta) ? $ruta : null;
}

/** Revisa un respaldo: suma SHA-256 contra su .sha256, gzip legible hasta el final y volcado completo. */
function verificarRespaldo(string $nombre): array
{
    $ruta = rutaRespaldo($nombre);
    if (!$ruta) {
        return ['ok' => false, 'error' => 'El respaldo no existe.'];
    }
    $esperada = is_file("$ruta.sha256") ? strtok((string) file_get_contents("$ruta.sha256"), " \n") : '';
    $real = hash_file('sha256', $ruta);
    if (!$esperada || !hash_equals($esperada, $real)) {
        return ['ok' => false, 'error' => $esperada ? 'La suma SHA-256 no coincide: el archivo cambió o está dañado.' : 'Falta la suma SHA-256 del respaldo.'];
    }
    $gz = gzopen($ruta, 'rb');
    $final = '';
    while ($gz && !gzeof($gz)) {
        $trozo = gzread($gz, 1 << 16);
        if ($trozo === false) {
            break;
        }
        $final = substr($final . $trozo, -400);
    }
    $gz && gzclose($gz);
    if (!str_contains($final, 'Dump completed')) {
        return ['ok' => false, 'error' => 'El respaldo está incompleto o no se puede descomprimir.'];
    }
    return ['ok' => true, 'sha256' => $real];
}

/** CHECK TABLE de todas las tablas de la base. Devuelve [['tabla', 'estado', 'mensaje'], …]. */
function verificarIntegridadBd(): array
{
    $tablas = db()->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
    $resultado = [];
    foreach ($tablas as $tabla) {
        if (!preg_match('/^[a-z0-9_]+$/', $tabla)) {
            continue;
        }
        $filas = db()->query("CHECK TABLE `$tabla`")->fetchAll();
        $estado = 'OK';
        $mensajes = [];
        foreach ($filas as $f) {
            if (strcasecmp($f['Msg_type'], 'status') === 0) {
                $estado = $f['Msg_text'];
            } elseif (in_array(strtolower($f['Msg_type']), ['error', 'warning'], true)) {
                $mensajes[] = $f['Msg_text'];
                if (strtolower($f['Msg_type']) === 'error') {
                    $estado = 'Error';
                }
            }
        }
        $resultado[] = ['tabla' => $tabla, 'ok' => strcasecmp($estado, 'OK') === 0, 'estado' => $estado, 'mensaje' => implode(' · ', $mensajes)];
    }
    return $resultado;
}

/**
 * Restaura un respaldo sobre expediente_femeci. Antes hace un respaldo automático del estado actual.
 * Devuelve ['ok' => true, 'previo' => archivo] o ['ok' => false, 'error'].
 */
function restaurarRespaldo(string $nombre): array
{
    $credenciales = credencialesRespaldo(true);
    if (!$credenciales) {
        return ['ok' => false, 'error' => 'Para restaurar falta configurar el usuario de respaldos de MySQL (docs/respaldos.md).'];
    }
    $verificacion = verificarRespaldo($nombre);
    if (!$verificacion['ok']) {
        return $verificacion;
    }
    $candado = candadoRespaldos();
    if (!$candado) {
        return ['ok' => false, 'error' => 'No se pudo tomar el candado de respaldos.'];
    }
    $opciones = null;
    $errores = carpetaRespaldos() . '/.restaurar-' . bin2hex(random_bytes(4)) . '.err';
    try {
        @set_time_limit(1200);
        $previo = crearRespaldo('antes_de_restaurar', $candado);
        if (!$previo['ok']) {
            return ['ok' => false, 'error' => 'No se restauró: falló el respaldo previo (' . $previo['error'] . ').'];
        }
        $opciones = archivoOpcionesMysql($credenciales);
        $proceso = proc_open([
            MYSQL_BIN . '/mysql.exe', '--defaults-extra-file=' . $opciones, '--default-character-set=utf8mb4', '--binary-mode', DB_NOMBRE,
        ], [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', $errores, 'w']], $tuberias, null, null, ['bypass_shell' => true]);
        if (!is_resource($proceso)) {
            throw new RuntimeException('No se pudo ejecutar mysql.');
        }
        $gz = gzopen(rutaRespaldo($nombre), 'rb');
        while (!gzeof($gz)) {
            $trozo = gzread($gz, 1 << 16);
            if ($trozo === false || fwrite($tuberias[0], $trozo) === false) {
                break;
            }
        }
        gzclose($gz);
        fclose($tuberias[0]);
        $codigo = proc_close($proceso);
        if ($codigo !== 0) {
            $detalle = trim((string) @file_get_contents($errores));
            throw new RuntimeException('MySQL no terminó la restauración' . ($detalle ? ': ' . mb_strimwidth($detalle, 0, 300, '…') : '.')
                . ' El estado anterior quedó en ' . $previo['archivo'] . '.');
        }
        $avisoTriggers = regenerarTriggers($opciones);
        return ['ok' => true, 'previo' => $previo['archivo'], 'aviso' => $avisoTriggers];
    } catch (Throwable $e) {
        error_log('[respaldos] restaurar: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    } finally {
        if ($opciones) {
            @unlink($opciones);
        }
        @unlink($errores);
        soltarCandado($candado);
    }
}

/**
 * Vuelve a crear los triggers de la cola de sincronización (database/sincronizacion_triggers.php) después de
 * restaurar: DROP TABLE se los lleva y un respaldo hecho con el usuario de la app no los trae.
 * Devuelve null si todo bien (o no hay sincronización) o un aviso para mostrar.
 */
function regenerarTriggers(string $opciones): ?string
{
    $generador = realpath(__DIR__ . '/../../../database/sincronizacion_triggers.php');
    if (!$generador || !is_file(PHP_CLI)) {
        return null;
    }
    $sql = shell_exec('"' . str_replace('/', DIRECTORY_SEPARATOR, PHP_CLI) . '" -f "' . $generador . '"');
    if (!is_string($sql) || !str_contains($sql, 'TRIGGER')) {
        return 'No se pudieron regenerar los triggers de sincronización: ejecuta database/sincronizacion_triggers.php como root.';
    }
    $proceso = proc_open([MYSQL_BIN . '/mysql.exe', '--defaults-extra-file=' . $opciones, DB_NOMBRE],
        [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $tuberias, null, null, ['bypass_shell' => true]);
    if (!is_resource($proceso)) {
        return 'No se pudieron regenerar los triggers de sincronización.';
    }
    fwrite($tuberias[0], $sql);
    fclose($tuberias[0]);
    return proc_close($proceso) === 0 ? null : 'No se pudieron regenerar los triggers de sincronización: ejecuta database/sincronizacion_triggers.php como root.';
}

/* ---------- Respaldo diario perezoso ---------- */

/** Fecha (Y-m-d) del último respaldo completo, o null. */
function fechaUltimoRespaldo(): ?string
{
    $marca = json_decode((string) @file_get_contents(carpetaRespaldos() . '/ultimo.json'), true);
    return is_array($marca) && isset($marca['fecha']) ? (string) $marca['fecha'] : null;
}

/** Hace el respaldo del día si todavía no hay uno (lo usan el script de mantenimiento y el .bat). */
function respaldoDiarioSiToca(string $motivo = 'automatico'): array
{
    $candado = candadoRespaldos(false);
    if (!$candado) {
        return ['ok' => true, 'omitido' => 'Otro proceso está respaldando.'];
    }
    try {
        // Se revisa otra vez con el candado puesto: otro proceso pudo terminarlo mientras tanto
        if (fechaUltimoRespaldo() === date('Y-m-d')) {
            return ['ok' => true, 'omitido' => 'Ya hay un respaldo de hoy.'];
        }
        return crearRespaldo($motivo, $candado);
    } finally {
        soltarCandado($candado);
    }
}

/**
 * Llamada en cada petición (barata: lee un archivo pequeño). Si hoy no hay respaldo, lanza el script de
 * mantenimiento en segundo plano para no hacer esperar a quien navega. A lo mucho un intento por hora.
 */
function programarRespaldoPerezoso(): void
{
    try {
        $carpeta = carpetaRespaldos();
        if (fechaUltimoRespaldo() === date('Y-m-d')) {
            return;
        }
        $intento = "$carpeta/ultimo_intento.txt";
        if (is_file($intento) && filemtime($intento) > time() - 3600) {
            return;
        }
        $candado = candadoRespaldos(false);
        if (!$candado) {
            return;   // ya hay uno en curso
        }
        escribirArchivoAtomico($intento, date('c'));
        soltarCandado($candado);
        $php = str_replace('/', DIRECTORY_SEPARATOR, PHP_CLI);
        $script = realpath(__DIR__ . '/../../../mantenimiento/respaldar.php');
        if (PHP_OS_FAMILY !== 'Windows' || !is_file($php) || !$script) {
            return;
        }
        // start /B: el proceso sigue solo y esta petición responde de inmediato
        pclose(popen('start "" /B "' . $php . '" -f "' . $script . '" > NUL 2>&1', 'r'));
    } catch (Throwable $e) {
        error_log('[respaldos] programar: ' . $e->getMessage());
    }
}

/** Tamaño legible: "3.4 MB". */
function tamanoLegible(int $bytes): string
{
    foreach (['B', 'KB', 'MB', 'GB'] as $i => $unidad) {
        if ($bytes < 1024 ** ($i + 1) || $unidad === 'GB') {
            return ($i ? number_format($bytes / 1024 ** $i, 1) : $bytes) . ' ' . $unidad;
        }
    }
    return $bytes . ' B';
}
