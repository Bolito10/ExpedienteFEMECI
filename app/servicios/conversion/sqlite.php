<?php
/**
 * Archivo de base de datos SQLite (.sqlite, .db): se abre en modo de solo lectura y se leen sus tablas
 * con SELECT. No se ejecuta ningún SQL que venga en el archivo (vistas y disparadores se ignoran).
 */

function leerSqlite(string $datos): array
{
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('Este servidor no puede leer archivos SQLite. Expórtalo como volcado .sql.');
    }
    $carpeta = __DIR__ . '/../../../privado/importaciones';
    if (!is_dir($carpeta)) {
        @mkdir($carpeta, 0700, true);
    }
    $ruta = $carpeta . '/sqlite-' . bin2hex(random_bytes(8)) . '.db';
    file_put_contents($ruta, $datos, LOCK_EX);
    $pdo = null;
    try {
        $pdo = new PDO('sqlite:' . $ruta, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);
        $nombres = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY rowid LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
        $tablas = [];
        foreach ($nombres as $nombre) {
            $citado = '"' . str_replace('"', '""', $nombre) . '"';
            $columnas = [];
            $tipos = [];
            foreach ($pdo->query("PRAGMA table_info($citado)") as $c) {
                $columnas[] = $c['name'];
                $tipos[] = strtoupper((string) $c['type']);
            }
            $consulta = $pdo->query("SELECT * FROM $citado LIMIT " . LIMITE_FILAS_LECTURA);
            $filas = [];
            while ($fila = $consulta->fetch(PDO::FETCH_NUM)) {
                $filas[] = array_map(fn ($v) => is_string($v) && !mb_check_encoding($v, 'UTF-8') ? '0x' . bin2hex($v) : $v, $fila);
            }
            $consulta = null;
            $tabla = convTabla($nombre, $columnas, $filas);
            $tabla['tipos'] = $tipos;
            $tablas[] = $tabla;
        }
        usort($tablas, fn ($a, $b) => (count($b['filas']) > 0) <=> (count($a['filas']) > 0));
        return ['formato' => 'sqlite', 'tablas' => $tablas, 'avisos' => [count($tablas) . ' tablas en la base SQLite (abierta en solo lectura)']];
    } catch (PDOException) {
        throw new RuntimeException('No se pudo abrir la base SQLite; el archivo puede estar dañado o cifrado.');
    } finally {
        $pdo = null;
        gc_collect_cycles();
        @unlink($ruta);
    }
}
