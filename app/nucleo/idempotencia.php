<?php
/**
 * Peticiones idempotentes (tabla `peticiones_idempotentes`).
 *
 * Cada POST JSON a /api/ que manda el navegador lleva la cabecera X-Idempotencia con una clave única
 * (assets/js/modulos/sin_conexion.js). Si la red se corta después de que el servidor guardó, la cola
 * sin conexión reintenta con la misma clave y aquí se devuelve la respuesta guardada sin repetir la
 * escritura. Si la primera vez quedó a medias (apagón a la mitad), se avisa en lugar de adivinar.
 *
 * Las respuestas con error del servidor (5xx) o por límite de intentos (429) no se guardan: se pueden reintentar.
 */

const IDEMPOTENCIA_DIAS = 7;
const IDEMPOTENCIA_MAX_RESPUESTA = 256 * 1024;

function atenderIdempotencia(): void
{
    $claveCliente = (string) ($_SERVER['HTTP_X_IDEMPOTENCIA'] ?? '');
    if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $claveCliente)) {
        return;
    }
    // Transmisión en vivo (el asistente), archivos grandes, respaldos y sincronización: se atienden sin búfer ni clave
    $ruta = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    foreach (['/api/asistente/conversar.php', '/api/presencia.php', '/api/datos/', '/api/configuracion/respaldos.php', '/api/sincronizacion/'] as $excluida) {
        if (str_starts_with($ruta, URL_BASE . $excluida)) {
            return;
        }
    }
    $usuarioId = (int) usuarioActual()['id'];
    $responder = function (int $codigo, array $datos): never {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        exit;
    };

    // El token se revisa aquí también para que la cola distinga "sesión nueva" de "sin permiso"
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        $responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.', 'motivo' => 'csrf']);
    }

    $pdo   = db();
    $clave = hash('sha256', $usuarioId . ':' . $claveCliente);
    $ruta  = mb_substr($ruta, 0, 190);

    if (random_int(1, 200) === 1) {
        $pdo->prepare('DELETE FROM peticiones_idempotentes WHERE creado_en < NOW() - INTERVAL ? DAY')->execute([IDEMPOTENCIA_DIAS]);
    }

    try {
        $pdo->prepare('INSERT INTO peticiones_idempotentes (clave, usuario_id, ruta) VALUES (?, ?, ?)')
            ->execute([$clave, $usuarioId, $ruta]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') {
            throw $e;
        }
        $previa = $pdo->prepare('SELECT ruta, estado, codigo, respuesta, TIMESTAMPDIFF(SECOND, creado_en, NOW()) AS segundos
                                   FROM peticiones_idempotentes WHERE clave = ?');
        $previa->execute([$clave]);
        $previa = $previa->fetch();
        if (!$previa || $previa['ruta'] !== $ruta) {
            $responder(422, ['ok' => false, 'error' => 'La clave de la petición ya se usó en otra acción.', 'motivo' => 'clave']);
        }
        if ($previa['estado'] === 'terminada') {
            // Ya se hizo: misma respuesta, sin repetir la escritura
            http_response_code((int) $previa['codigo']);
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Idempotencia-Repetida: 1');
            echo $previa['respuesta'] ?? json_encode(['ok' => true, 'repetida' => true]);
            exit;
        }
        // Sigue en curso (otra pestaña) o se interrumpió sin terminar (apagón): no se sabe si se guardó
        $responder(409, (int) $previa['segundos'] < 120
            ? ['ok' => false, 'error' => 'Este cambio todavía se está procesando.', 'motivo' => 'en_proceso']
            : ['ok' => false, 'error' => 'No se pudo confirmar si este cambio se guardó. Revisa que esté y, si falta, vuelve a hacerlo.', 'motivo' => 'desconocido']);
    }

    ob_start();
    register_shutdown_function(function () use ($clave): void {
        $cuerpo = (string) ob_get_contents();
        $codigo = http_response_code() ?: 200;
        try {
            $pdo = db();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();   // la acción terminó con error a la mitad: nada de ella se guardó
            }
            if ($codigo >= 500 || $codigo === 429) {   // error del servidor o límite de intentos: se puede reintentar
                $pdo->prepare('DELETE FROM peticiones_idempotentes WHERE clave = ?')->execute([$clave]);
            } else {
                $pdo->prepare("UPDATE peticiones_idempotentes SET estado = 'terminada', codigo = ?, respuesta = ? WHERE clave = ?")
                    ->execute([$codigo, strlen($cuerpo) <= IDEMPOTENCIA_MAX_RESPUESTA ? $cuerpo : null, $clave]);
            }
        } catch (Throwable $e) {
            error_log('[idempotencia] ' . $e->getMessage());
        }
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    });
}
