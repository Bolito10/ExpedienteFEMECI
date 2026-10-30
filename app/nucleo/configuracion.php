<?php
/**
 * Valores de configuración del sistema guardados en la tabla `configuracion`.
 * Los secretos se cifran con AES-256-GCM; la llave vive en privado/llave.bin (bloqueado a la web).
 * Cada institución tiene su propia configuración (permisos, textos, portadas, asistente de IA…):
 * la clave primaria es (institucion_id, clave); 0 son los valores de antes de las instituciones.
 */

const ARCHIVO_LLAVE = __DIR__ . '/../../privado/llave.bin';

function config(string $clave, ?string $porDefecto = null): ?string
{
    // Caché por petición; guardarConfig() la actualiza para que lo guardado se vea en la misma petición
    $cache = &$GLOBALS['__cache_config'];
    $cache ??= [];
    if (!array_key_exists($clave, $cache)) {
        // El valor de la institución; si no tiene uno propio, el que existía antes de las instituciones (0),
        // así lo configurado entonces (clave de la IA, permisos, textos) sigue funcionando
        $consulta = db()->prepare('SELECT valor FROM configuracion WHERE institucion_id IN (?, 0) AND clave = ? ORDER BY institucion_id DESC LIMIT 1');
        $consulta->execute([institucionId() ?? 0, $clave]);
        $valor = $consulta->fetchColumn();
        $cache[$clave] = $valor === false ? null : $valor;
    }
    return $cache[$clave] ?? $porDefecto;
}

function guardarConfig(string $clave, ?string $valor): void
{
    db()->prepare(
        'INSERT INTO configuracion (institucion_id, clave, valor, actualizado_por) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado_por = VALUES(actualizado_por)'
    )->execute([institucionId() ?? 0, $clave, $valor, usuarioActual()['id'] ?? null]);
    $GLOBALS['__cache_config'][$clave] = $valor;
}

function llaveCifrado(): string
{
    // Atómica y sin pisar otra: una llave a medias dejaría los secretos cifrados sin forma de leerse
    if (!is_file(ARCHIVO_LLAVE) && !escribirArchivoAtomico(ARCHIVO_LLAVE, random_bytes(32), true)) {
        throw new RuntimeException('No se pudo crear la llave de cifrado en privado/.');
    }
    $llave = file_get_contents(ARCHIVO_LLAVE);
    if ($llave === false || strlen($llave) !== 32) {
        throw new RuntimeException('La llave de cifrado de privado/llave.bin está dañada.');
    }
    return $llave;
}

function cifrar(string $texto): string
{
    $iv = random_bytes(12);
    $cifrado = openssl_encrypt($texto, 'aes-256-gcm', llaveCifrado(), OPENSSL_RAW_DATA, $iv, $etiqueta);
    return base64_encode($iv . $etiqueta . $cifrado);
}

function descifrar(?string $guardado): ?string
{
    if (!$guardado) {
        return null;
    }
    $datos = base64_decode($guardado, true);
    if ($datos === false || strlen($datos) < 29) {
        return null;
    }
    $texto = openssl_decrypt(substr($datos, 28), 'aes-256-gcm', llaveCifrado(), OPENSSL_RAW_DATA,
                             substr($datos, 0, 12), substr($datos, 12, 16));
    return $texto === false ? null : $texto;
}

function esAdministrador(): bool
{
    return (usuarioActual()['rol'] ?? '') === 'administrador';
}

/** Texto de la interfaz que el administrador puede editar (títulos y subtítulos de las secciones). */
function textoEditable(string $clave, string $porDefecto): string
{
    $guardado = config('texto_' . $clave);
    return $guardado !== null && $guardado !== '' ? $guardado : $porDefecto;
}

/** Atributos para que un título sea editable en su lugar (solo el administrador). */
function atributosEditable(string $clave, string $porDefecto): string
{
    return puede('configurar')
        ? ' data-texto-editable="' . e($clave) . '" data-placeholder="' . e($porDefecto) . '" contenteditable="plaintext-only" spellcheck="true"'
        : '';
}
