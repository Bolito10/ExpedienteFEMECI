<?php
/**
 * Columnas y filas de una página de tipo base de datos. POST JSON {id, accion, ...}:
 *   columna_agregar   {nombre, tipo, posicion?}
 *   columna_cambiar   {columna, nombre?, tipo?}
 *   columna_mover     {columna, posicion}     la primera ("Nombre") no se mueve
 *   columna_eliminar  {columna}               la primera ("Nombre") no se elimina
 *   fila_agregar      {antes_de? | despues_de? | duplicar?}   fila id de referencia
 *   celda             {fila, columna, valor}
 *   fila_eliminar     {fila}
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/espacio/paginas.php';

header('Content-Type: application/json; charset=UTF-8');

function responder(int $codigo, array $datos): never
{
    // Las escrituras de cada acción (con su bitácora) se confirman juntas solo si salió bien; un error las deshace
    if (db()->inTransaction()) {
        $codigo < 400 ? db()->commit() : db()->rollBack();
    }
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$usuarioId = (int) usuarioActual()['id'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!dentroDelLimite('paginas:usuario:' . $usuarioId, 600, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiados cambios seguidos. Espera un momento.']);
}
$entrada = json_decode(file_get_contents('php://input'), true);
$pagina  = is_array($entrada) ? cargarPagina((int) ($entrada['id'] ?? 0), $usuarioId) : null;
if (!$pagina || $pagina['en_papelera'] || $pagina['tipo'] !== 'base_datos') {
    responder(404, ['ok' => false, 'error' => 'La base de datos no existe o no tienes acceso.']);
}
if (!nivelAlcanza($pagina, 'editar')) {
    responder(403, ['ok' => false, 'error' => 'Solo tienes permiso para ver esta base de datos.']);
}

$pdo       = db();
$datos     = json_decode((string) $pagina['contenido'], true) ?: [];
$columnas  = columnasDe($pagina);
$siguiente = (int) ($datos['siguiente'] ?? count($columnas) + 1);
$indice    = array_search((string) ($entrada['columna'] ?? ''), array_column($columnas, 'id'), true);

$guardarColumnas = function (array $columnas, int $siguiente, ?string $campo = null, ?string $anterior = null, ?string $nuevo = null) use ($pdo, $pagina, $usuarioId): void {
    $pdo->prepare('UPDATE paginas SET contenido = ?, version = version + 1, actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')
        ->execute([json_encode(['columnas' => array_values($columnas), 'siguiente' => $siguiente], JSON_UNESCAPED_UNICODE), $usuarioId, $pagina['id']]);
    if ($campo) {
        registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', $campo, $anterior, $nuevo);
    }
};
$filaOError = function () use ($pdo, $pagina, $entrada): array {
    $c = $pdo->prepare('SELECT * FROM pagina_filas WHERE id = ? AND pagina_id = ?');
    $c->execute([(int) ($entrada['fila'] ?? 0), $pagina['id']]);
    $fila = $c->fetch();
    if (!$fila) {
        responder(404, ['ok' => false, 'error' => 'La fila no existe.']);
    }
    return $fila;
};
$nombreColumna = fn (): string => mb_substr(trim((string) ($entrada['nombre'] ?? '')), 0, 60);

$pdo->beginTransaction();
switch ($entrada['accion'] ?? '') {
    case 'columna_agregar':
        $tipo = (string) ($entrada['tipo'] ?? 'texto');
        if (!isset(TIPOS_COLUMNA[$tipo]) || count($columnas) >= 30) {
            responder(400, ['ok' => false, 'error' => count($columnas) >= 30 ? 'Máximo 30 columnas.' : 'Tipo de columna no válido.']);
        }
        $columna = ['id' => 'c' . $siguiente, 'nombre' => $nombreColumna() ?: TIPOS_COLUMNA[$tipo], 'tipo' => $tipo];
        // Posición opcional (insertar a la izquierda o a la derecha de otra); la primera columna no se desplaza
        $posicion = isset($entrada['posicion']) ? max(1, min(count($columnas), (int) $entrada['posicion'])) : count($columnas);
        array_splice($columnas, $posicion, 0, [$columna]);
        $guardarColumnas($columnas, $siguiente + 1, 'columna_nueva', null, $columna['nombre']);
        responder(200, ['ok' => true, 'columna' => $columna, 'posicion' => $posicion]);

    case 'columna_mover':
        if ($indice === false || $indice === 0) {
            responder(400, ['ok' => false, 'error' => 'Esta columna no se puede mover.']);
        }
        $posicion = max(1, min(count($columnas) - 1, (int) ($entrada['posicion'] ?? $indice)));
        $movida = array_splice($columnas, $indice, 1);
        array_splice($columnas, $posicion, 0, $movida);
        $guardarColumnas($columnas, $siguiente, 'columna', null, $movida[0]['nombre']);
        responder(200, ['ok' => true, 'columnas' => array_values($columnas)]);

    case 'columna_cambiar':
        if ($indice === false) {
            responder(404, ['ok' => false, 'error' => 'La columna no existe.']);
        }
        if (isset($entrada['nombre']) && $nombreColumna() !== '') {
            $columnas[$indice]['nombre'] = $nombreColumna();
        }
        if (isset($entrada['tipo']) && $indice > 0) {
            if (!isset(TIPOS_COLUMNA[$entrada['tipo']])) {
                responder(400, ['ok' => false, 'error' => 'Tipo de columna no válido.']);
            }
            $columnas[$indice]['tipo'] = $entrada['tipo'];
        }
        $guardarColumnas($columnas, $siguiente, 'columna', null, $columnas[$indice]['nombre']);
        responder(200, ['ok' => true, 'columna' => $columnas[$indice]]);

    case 'columna_eliminar':
        if ($indice === false || $indice === 0) {
            responder(400, ['ok' => false, 'error' => 'Esta columna no se puede eliminar.']);
        }
        $eliminada = $columnas[$indice]['nombre'];
        array_splice($columnas, $indice, 1);
        $guardarColumnas($columnas, $siguiente, 'columna_eliminada', $eliminada);
        responder(200, ['ok' => true]);

    case 'fila_agregar':
        $total = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(orden), 0) + 1 FROM pagina_filas WHERE pagina_id = ?');
        $total->execute([$pagina['id']]);
        [$cuantas, $orden] = $total->fetch(PDO::FETCH_NUM);
        if ($cuantas >= 5000) {
            responder(400, ['ok' => false, 'error' => 'Máximo 5000 filas por base de datos.']);
        }
        // Opcional: insertar arriba o abajo de otra fila (o duplicarla) en lugar de al final
        $valoresNuevos = '{}';
        $referencia = (int) ($entrada['antes_de'] ?? $entrada['despues_de'] ?? $entrada['duplicar'] ?? 0);
        if ($referencia) {
            $r = $pdo->prepare('SELECT orden, valores FROM pagina_filas WHERE id = ? AND pagina_id = ?');
            $r->execute([$referencia, $pagina['id']]);
            $ref = $r->fetch();
            if (!$ref) {
                responder(404, ['ok' => false, 'error' => 'La fila no existe.']);
            }
            $orden = (int) $ref['orden'] + (isset($entrada['antes_de']) ? 0 : 1);
            $pdo->prepare('UPDATE pagina_filas SET orden = orden + 1 WHERE pagina_id = ? AND orden >= ?')->execute([$pagina['id'], $orden]);
            if (isset($entrada['duplicar'])) {
                $valoresNuevos = $ref['valores'];
            }
        }
        $pdo->prepare('INSERT INTO pagina_filas (pagina_id, valores, orden) VALUES (?, ?, ?)')->execute([$pagina['id'], $valoresNuevos, $orden]);
        $filaId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE paginas SET actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')->execute([$usuarioId, $pagina['id']]);
        registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', 'fila_nueva', null, (string) $filaId);
        responder(200, ['ok' => true, 'fila' => $filaId, 'valores' => json_decode($valoresNuevos, true) ?: new stdClass()]);

    case 'celda':
        $fila = $filaOError();
        if ($indice === false) {
            responder(404, ['ok' => false, 'error' => 'La columna no existe.']);
        }
        $valores = json_decode($fila['valores'], true) ?: [];
        $valor   = valorDeCelda($columnas[$indice]['tipo'], $entrada['valor'] ?? null);
        $valores[$columnas[$indice]['id']] = $valor;
        // Selección: un valor nuevo se agrega como opción con el siguiente color
        $columna = $columnas[$indice];
        if ($columna['tipo'] === 'seleccion' && $valor !== null
            && !in_array($valor, array_column($columna['opciones'] ?? [], 'nombre'), true)) {
            $columnas[$indice]['opciones'][] = ['nombre' => $valor, 'color' => COLORES_OPCION[count($columna['opciones'] ?? []) % count(COLORES_OPCION)]];
            $guardarColumnas($columnas, $siguiente);
        }
        $pdo->prepare('UPDATE pagina_filas SET valores = ?, actualizado_en = NOW() WHERE id = ?')
            ->execute([json_encode($valores, JSON_UNESCAPED_UNICODE), $fila['id']]);
        $pdo->prepare('UPDATE paginas SET actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')->execute([$usuarioId, $pagina['id']]);
        // Bitácora: una entrada por persona, página y columna cada 5 minutos de edición
        $reciente = $pdo->prepare(
            "SELECT 1 FROM bitacora WHERE tabla = 'paginas' AND registro_id = ? AND usuario_id = ? AND campo = 'celda'
                AND valor_nuevo = ? AND creado_en > NOW() - INTERVAL 5 MINUTE LIMIT 1"
        );
        $reciente->execute([$pagina['id'], $usuarioId, $columna['nombre']]);
        if (!$reciente->fetchColumn()) {
            registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', 'celda', null, $columna['nombre']);
        }
        responder(200, ['ok' => true, 'valor' => $valor, 'columna' => $columnas[$indice]]);

    case 'fila_eliminar':
        $fila = $filaOError();
        $pdo->prepare('DELETE FROM pagina_filas WHERE id = ?')->execute([$fila['id']]);
        registrarBitacora('paginas', (int) $pagina['id'], 'eliminar', 'fila', $fila['valores']);
        responder(200, ['ok' => true]);
}

responder(400, ['ok' => false, 'error' => 'Acción no válida.']);
