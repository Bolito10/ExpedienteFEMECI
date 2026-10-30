<?php
/**
 * Perfiles de formato (app/servicios/conversion/perfiles.php): consultarlos y administrar los propios.
 *   GET                                       lista (incluidos y propios)
 *   GET  ?id=…                                el perfil completo en JSON (para verlo o descargarlo)
 *   POST JSON {accion: 'duplicar', id}        copia editable de un perfil (queda como propio)
 *   POST JSON {accion: 'guardar', json}       guarda un perfil propio (validación estricta; la versión
 *                                             anterior se conserva en el historial)
 *   POST JSON {accion: 'eliminar', id}        quita un perfil propio (se archiva en el historial)
 * Los incluidos no se modifican: para cambiar uno se duplica. Leer pide importar_exportar; cambiar, además, configurar.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/conversion/registro.php';

function responder(int $codigo, array $datos): never
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

exigirPermiso('importar_exportar');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        $perfil = cargarPerfil((string) $_GET['id']);
        $perfil ? responder(200, ['ok' => true, 'perfil' => perfilParaArchivo($perfil), 'origen' => $perfil['origen']])
                : responder(404, ['ok' => false, 'error' => 'El perfil no existe.']);
    }
    responder(200, ['ok' => true, 'perfiles' => array_values(array_map('perfilResumen', perfilesDisponibles())), 'editable' => puede('configurar')]);
}

if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!puede('configurar')) {
    responder(403, ['ok' => false, 'error' => 'Solo un administrador puede cambiar los perfiles de formato.']);
}
if (!dentroDelLimite('perfiles:usuario:' . (int) usuarioActual()['id'], 60, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiados cambios seguidos. Espera un momento.']);
}

$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
$accion = (string) ($entrada['accion'] ?? '');

try {
    switch ($accion) {
        case 'duplicar':
            $origen = cargarPerfil((string) ($entrada['id'] ?? ''));
            if (!$origen) {
                responder(404, ['ok' => false, 'error' => 'El perfil no existe.']);
            }
            $copia = perfilParaArchivo($origen);
            $copia['id'] = idPerfilLibre($origen['id'] . '-propio');
            $copia['nombre'] = mb_substr($origen['nombre'] . ' (copia)', 0, 120);
            $copia['version'] = '1.0.0';
            [$valido, $errores] = validarPerfil($copia);
            if (!$valido || $errores) {
                responder(422, ['ok' => false, 'error' => 'No se pudo duplicar: ' . implode(' ', array_slice($errores, 0, 3))]);
            }
            $guardado = guardarPerfilPropio($valido);
            registrarBitacora('perfiles_formato', 0, 'crear', 'duplicado', $origen['id'], $guardado['id']);
            responder(200, ['ok' => true, 'id' => $guardado['id']]);

        case 'guardar':
            $json = $entrada['json'] ?? null;
            [$perfil, $errores] = validarPerfil(is_array($json) ? $json : (string) $json);
            if (!$perfil || $errores) {
                responder(422, ['ok' => false, 'error' => 'El perfil no es válido.', 'errores' => array_slice($errores, 0, 20)]);
            }
            $existia = cargarPerfil($perfil['id']);
            $guardado = guardarPerfilPropio($perfil);
            registrarBitacora('perfiles_formato', 0, $existia ? 'actualizar' : 'crear', 'perfil', $existia['version'] ?? null, $guardado['id'] . ' ' . $guardado['version']);
            responder(200, ['ok' => true, 'id' => $guardado['id'], 'version' => $guardado['version']]);

        case 'eliminar':
            $id = (string) ($entrada['id'] ?? '');
            if (!eliminarPerfilPropio($id)) {
                responder(422, ['ok' => false, 'error' => 'Solo se pueden quitar perfiles propios.']);
            }
            registrarBitacora('perfiles_formato', 0, 'eliminar', 'perfil', $id, null);
            responder(200, ['ok' => true]);
    }
} catch (RuntimeException $e) {
    responder(422, ['ok' => false, 'error' => $e->getMessage()]);
}
responder(400, ['ok' => false, 'error' => 'Acción no válida.']);
