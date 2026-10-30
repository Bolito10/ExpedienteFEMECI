<?php
/**
 * Parte de la conversión que toca la base de datos: registro de conversiones (NOM-024 trazabilidad),
 * historial, archivos temporales de importación y datos de origen para exportar.
 */
require_once __DIR__ . '/comun.php';

const CARPETA_IMPORTACIONES = __DIR__ . '/../../../privado/importaciones';
const HORAS_IMPORTACION_TEMPORAL = 3;
const MAXIMO_FILAS_PACIENTES = 20000;
const MAXIMO_FILAS_PAGINA = 5000;      // mismo límite que api/paginas/base_datos.php
const MAXIMO_COLUMNAS_PAGINA = 30;

/** Campos del paciente que se pueden exportar (además de las propiedades agregadas). */
function camposExportacionPaciente(): array
{
    $campos = ['folio' => 'Folio', 'curp' => 'CURP'];
    foreach (CAMPOS_IMPORTACION as $clave => $c) {
        if ($clave !== 'curp') {
            $campos[$clave] = $c['texto'];
        }
    }
    $campos['confirmacion_datos'] = 'Confirmado por el paciente';
    $campos['creado_en'] = 'Fecha de registro';
    foreach (propiedadesExtraPaciente() as $prop) {
        $campos['extra_' . $prop['clave']] = $prop['nombre'];
    }
    return $campos;
}

/** Inserta la conversión en el registro inmutable y en la bitácora. Devuelve su id. */
function registrarConversion(array $c): int
{
    db()->prepare(
        'INSERT INTO conversiones (institucion_id, usuario_id, direccion, formato, archivo_nombre, archivo_sha256, archivo_bytes, origen, destino, filas_ok, filas_error, notas, ip)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        institucionId(), (int) usuarioActual()['id'], $c['direccion'], $c['formato'], mb_substr($c['archivo_nombre'], 0, 255), $c['archivo_sha256'], $c['archivo_bytes'],
        mb_substr($c['origen'], 0, 255), mb_substr($c['destino'], 0, 255), $c['filas_ok'], $c['filas_error'], $c['notas'] ?: null, ipCliente(),
    ]);
    $id = (int) db()->lastInsertId();
    registrarBitacora('conversiones', $id, $c['direccion'] === 'importar' ? 'crear' : 'acceso', $c['direccion'] === 'importar' ? 'importacion' : 'exportacion', null,
        mb_substr("{$c['formato']} · {$c['archivo_nombre']} · sha256 {$c['archivo_sha256']} · {$c['origen']} → {$c['destino']} · {$c['filas_ok']} ok, {$c['filas_error']} con error", 0, 2000));
    return $id;
}

/** Últimas conversiones: el administrador ve todas; los demás, las suyas. */
function historialConversiones(int $limite = 100): array
{
    $sql = 'SELECT c.*, u.nombre AS persona FROM conversiones c JOIN usuarios u ON u.id = c.usuario_id WHERE c.institucion_id = ?';
    $parametros = [institucionId()];
    if (!esAdministrador()) {
        $sql .= ' AND c.usuario_id = ?';
        $parametros[] = (int) usuarioActual()['id'];
    }
    $consulta = db()->prepare($sql . ' ORDER BY c.id DESC LIMIT ' . max(1, min(500, $limite)));
    $consulta->execute($parametros);
    return array_map('conversionPublica', $consulta->fetchAll());
}

function conversionPublica(array $c): array
{
    return [
        'id' => (int) $c['id'], 'persona' => $c['persona'] ?? '', 'direccion' => $c['direccion'], 'formato' => $c['formato'],
        'formato_texto' => FORMATOS_EXPORTACION[$c['formato']]['texto'] ?? (FORMATOS_IMPORTACION[$c['formato']] ?? strtoupper($c['formato'])),
        'archivo' => $c['archivo_nombre'], 'sha256' => $c['archivo_sha256'], 'bytes' => (int) $c['archivo_bytes'],
        'origen' => $c['origen'], 'destino' => $c['destino'], 'ok' => (int) $c['filas_ok'], 'error' => (int) $c['filas_error'],
        'notas' => (string) $c['notas'], 'fecha' => date('c', strtotime($c['creado_en'])), 'fecha_texto' => fechaCorta($c['creado_en']) . ' ' . date('H:i', strtotime($c['creado_en'])),
    ];
}

/* ---------------------------------------------------------------------- Archivos temporales */

function carpetaImportaciones(): string
{
    if (!is_dir(CARPETA_IMPORTACIONES)) {
        mkdir(CARPETA_IMPORTACIONES, 0700, true);
    }
    // Limpieza: archivos de importaciones que se abandonaron
    foreach (glob(CARPETA_IMPORTACIONES . '/*') ?: [] as $archivo) {
        if (is_file($archivo) && filemtime($archivo) < time() - HORAS_IMPORTACION_TEMPORAL * 3600) {
            @unlink($archivo);
        }
    }
    return CARPETA_IMPORTACIONES;
}

/** Importación en curso de esta sesión (o null si venció). */
function importacionEnCurso(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token) || !isset($_SESSION['importaciones'][$token])) {
        return null;
    }
    $info = $_SESSION['importaciones'][$token];
    $cache = carpetaImportaciones() . "/$token.json";
    if (!is_file($cache)) {
        unset($_SESSION['importaciones'][$token]);
        return null;
    }
    $info['lectura'] = json_decode((string) file_get_contents($cache), true);
    return is_array($info['lectura']) ? $info : null;
}

function descartarImportacion(string $token): void
{
    if (preg_match('/^[0-9a-f]{32}$/', $token)) {
        @unlink(carpetaImportaciones() . "/$token.json");
    }
    unset($_SESSION['importaciones'][$token]);
}

/* ---------------------------------------------------------------------- Pacientes existentes */

/** Clave de duplicado por nombre, primer apellido y fecha de nacimiento (sin acentos ni mayúsculas). */
function claveNombreFecha(string $nombre, string $primerApellido, string $fecha): string
{
    $normal = fn ($t) => preg_replace('/\s+/', ' ', mb_strtolower(trim(convSinAcentos($t))));
    return $normal($nombre) . '|' . $normal($primerApellido) . '|' . $fecha;
}

/** Índices de pacientes existentes por CURP y por nombre+fecha. */
function indicePacientesExistentes(): array
{
    $porCurp = [];
    $porNombre = [];
    $todos = db()->prepare('SELECT id, folio, curp, nombre, primer_apellido, fecha_nacimiento, eliminado_en FROM pacientes WHERE institucion_id = ?');
    $todos->execute([institucionId()]);
    foreach ($todos as $p) {
        $p['id'] = (int) $p['id'];
        if ($p['curp']) {
            $porCurp[$p['curp']] = $p;
        }
        if ($p['eliminado_en'] === null) {
            $porNombre[claveNombreFecha($p['nombre'], $p['primer_apellido'], $p['fecha_nacimiento'])] ??= $p;
        }
    }
    return ['curp' => $porCurp, 'nombre' => $porNombre];
}

/* ---------------------------------------------------------------------- Propiedades nuevas del paciente */

const MAXIMO_PROPIEDADES_PACIENTE = 30;   // mismo límite que api/pacientes/propiedades.php

/** Tipo (y opciones) para una propiedad nueva creada desde una columna del archivo. */
function tipoPropiedadNueva(array $valores, ?string $contenido): array
{
    // Identificadores con ceros a la izquierda o muchos dígitos se guardan como texto
    if (in_array($contenido, ['nss', 'telefono', 'codigo_postal', 'curp', 'clues', 'agregado_medico', 'cie10'], true)) {
        return ['texto', null];
    }
    $tipo = tipoColumnaSugerido($valores);
    if ($tipo === 'numero') {
        foreach (array_slice($valores, 0, 500) as $v) {
            if (is_string($v) && preg_match('/^0\d|^\d{10,}$/', trim($v))) {
                return ['texto', null];
            }
        }
    }
    if ($tipo === 'seleccion') {
        $opciones = [];
        foreach ($valores as $v) {
            $t = mb_substr(trim(convValorTexto($v)), 0, 60);
            if ($t !== '' && !in_array($t, $opciones, true)) {
                $opciones[] = $t;
            }
            if (count($opciones) > 60) {
                return ['texto', null];
            }
        }
        return ['seleccion', $opciones];
    }
    return [$tipo, null];
}

/**
 * Crea propiedades de la tabla de pacientes con el mismo mecanismo que api/pacientes/propiedades.php
 * (configuración pacientes_propiedades y bitácora). $definiciones = [claveProvisional => ['clave', 'nombre',
 * 'tipo', 'opciones'?, 'provisional'?]]: la clave de una propiedad de perfil (imss_nss…) se respeta; la de
 * una columna nueva ('provisional') se reemplaza por una aleatoria como en propiedades.php. Devuelve
 * [claveProvisional => claveReal]. Debe llamarse dentro de la transacción de la importación.
 */
function crearPropiedadesPaciente(array $definiciones, int $conversionId): array
{
    $consulta = db()->prepare('SELECT valor FROM configuracion WHERE institucion_id = ? AND clave = ? FOR UPDATE');
    $consulta->execute([institucionId() ?? 0, 'pacientes_propiedades']);
    $extras = json_decode((string) $consulta->fetchColumn(), true) ?: [];
    $existentes = array_column($extras, null, 'clave');
    $claves = [];
    foreach ($definiciones as $provisional => $def) {
        $fija = empty($def['provisional']) ? ($def['clave'] ?? null) : null;
        if ($fija !== null && isset($existentes[$fija])) {
            $claves[$provisional] = $fija;
            continue;
        }
        if (count($extras) >= MAXIMO_PROPIEDADES_PACIENTE) {
            continue;
        }
        $nueva = ['clave' => $fija ?? ('p' . bin2hex(random_bytes(3))), 'nombre' => mb_substr($def['nombre'], 0, 60), 'tipo' => $def['tipo']];
        if ($def['tipo'] === 'seleccion') {
            $nueva['opciones'] = array_values($def['opciones'] ?? []);
        }
        $extras[] = $nueva;
        $existentes[$nueva['clave']] = $nueva;
        $claves[$provisional] = $nueva['clave'];
        registrarBitacora('configuracion', 0, 'crear', 'pacientes_propiedad', null, "{$nueva['nombre']} · {$nueva['tipo']} · importación #$conversionId");
    }
    guardarConfig('pacientes_propiedades', json_encode($extras, JSON_UNESCAPED_UNICODE));
    return $claves;
}

/** Índice de pacientes activos por un identificador compuesto de propiedades (p. ej. NSS + agregado médico). */
function indicePorPropiedades(array $destinos): array
{
    $claves = array_map(fn ($d) => substr($d, 6), array_filter($destinos, fn ($d) => str_starts_with($d, 'extra_')));
    if (!$claves || count($claves) !== count($destinos)) {
        return [];
    }
    $indice = [];
    $conExtras = db()->prepare('SELECT id, folio, extras FROM pacientes WHERE institucion_id = ? AND eliminado_en IS NULL AND extras IS NOT NULL');
    $conExtras->execute([institucionId()]);
    foreach ($conExtras as $p) {
        $extras = json_decode((string) $p['extras'], true) ?: [];
        $partes = array_map(fn ($c) => mb_strtoupper(trim(convValorTexto($extras[$c] ?? ''))), $claves);
        if (!in_array('', $partes, true)) {
            $indice[implode('|', $partes)] ??= ['id' => (int) $p['id'], 'folio' => $p['folio'], 'curp' => null, 'eliminado_en' => null];
        }
    }
    return $indice;
}
