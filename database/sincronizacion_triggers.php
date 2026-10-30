<?php
/**
 * Genera el SQL de la cola de sincronización (triggers de MariaDB) a partir de
 * app/servicios/sincronizacion/tablas.php, para que la lista de columnas viva en un solo lugar.
 *
 * Solo desde la línea de comandos (la carpeta database/ está bloqueada al navegador):
 *   C:/xampp/php/php.exe database/sincronizacion_triggers.php           → triggers
 *   C:/xampp/php/php.exe database/sincronizacion_triggers.php --siembra → triggers + siembra de filas existentes
 * La salida se aplica como root:  ... | C:/xampp/mysql/bin/mysql -u root expediente_femeci
 * y se copia a database/esquema.sql cuando cambia la lista de columnas.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/servicios/sincronizacion/tablas.php';

/** institucion_id de la fila (aísla la cola por institución; pagina_filas la hereda de su página). */
function institucionFila(array $def, string $p): string
{
    return $def['institucion'] === 'pagina'
        ? "(SELECT pi.institucion_id FROM paginas pi WHERE pi.id = {$p}pagina_id)"
        : "{$p}institucion_id";
}

/** JSON_OBJECT con el estado de la fila ($p = 'NEW.' en triggers, 't.' en la siembra). */
function jsonFila(string $tabla, array $def, string $p): string
{
    $partes = [];
    foreach ($def['columnas'] as $c) {
        $partes[] = "'$c', $p$c";
    }
    foreach ($def['referencias'] as $col => [$referida, $clave]) {
        $partes[] = "'$clave', (SELECT r.uuid FROM $referida r WHERE r.id = $p$col)";
    }
    foreach ($def['personas'] as $col => $clave) {
        $partes[] = "'$clave', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol)"
                  . " FROM usuarios u WHERE u.id = $p$col)";
    }
    $partes[] = "'institucion_id', " . institucionFila($def, $p);
    if ($def['mutable']) {
        $partes[] = "'sinc_modificado_en', {$p}sinc_modificado_en";
    }
    return "JSON_OBJECT(\n      " . implode(",\n      ", $partes) . ")";
}

$siembra = in_array('--siembra', $argv, true);
$sql = "-- Generado por database/sincronizacion_triggers.php: no editar a mano.\nDELIMITER //\n";

foreach (SINC_TABLAS as $tabla => $def) {
    $sql .= "\n-- $tabla\n";
    // Antes de insertar: uuid (si alguien lo manda vacío) y marca de última escritura
    $sql .= "DROP TRIGGER IF EXISTS sinc_{$tabla}_ai_antes //\n"
          . "CREATE TRIGGER sinc_{$tabla}_ai_antes BEFORE INSERT ON $tabla FOR EACH ROW\nBEGIN\n"
          . "  IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = UUID(); END IF;\n"
          . ($def['mutable'] ? "  IF @sinc_origen IS NULL OR NEW.sinc_modificado_en IS NULL THEN SET NEW.sinc_modificado_en = NOW(6); END IF;\n" : '')
          . "END //\n";
    $sql .= "DROP TRIGGER IF EXISTS sinc_{$tabla}_ai //\n"
          . "CREATE TRIGGER sinc_{$tabla}_ai AFTER INSERT ON $tabla FOR EACH ROW\n"
          . "  INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)\n"
          . "  VALUES ('$tabla', NEW.uuid, " . institucionFila($def, 'NEW.') . ", 'alta', " . jsonFila($tabla, $def, 'NEW.') . ", @sinc_origen) //\n";

    // Si la fila cambia de institución (p. ej. las filas anteriores pasan a la primera institución que se
    // registra), lo pendiente de enviar se mueve con ella; en páginas, también lo de sus filas.
    $mover = $def['institucion'] !== 'columna' ? ''
        : "  IF NOT (NEW.institucion_id <=> OLD.institucion_id) THEN
"
        . "    UPDATE sincronizacion_cola SET institucion_id = NEW.institucion_id
"
        . "     WHERE origen IS NULL AND enviado_en IS NULL AND ((tabla = '$tabla' AND uuid = NEW.uuid)"
        . ($tabla === 'paginas' ? "
        OR (tabla = 'pagina_filas' AND uuid IN (SELECT f.uuid FROM pagina_filas f WHERE f.pagina_id = NEW.id))" : '')
        . ");
  END IF;
";

    if (!$def['mutable']) {
        if ($mover !== '') {
            $sql .= "DROP TRIGGER IF EXISTS sinc_{$tabla}_au //
"
                  . "CREATE TRIGGER sinc_{$tabla}_au AFTER UPDATE ON $tabla FOR EACH ROW
BEGIN
$mover" . "END //
";
        }
        continue;
    }
    // Antes de actualizar: el uuid no cambia; la marca solo avanza si cambió algo que se sincroniza
    $comparadas = array_merge($def['columnas'], array_keys($def['referencias']), array_keys($def['personas']),
                              $def['institucion'] === 'columna' ? ['institucion_id'] : []);
    $iguales = implode(' AND ', array_map(fn ($c) => "NEW.$c <=> OLD.$c", $comparadas));
    $sql .= "DROP TRIGGER IF EXISTS sinc_{$tabla}_au_antes //\n"
          . "CREATE TRIGGER sinc_{$tabla}_au_antes BEFORE UPDATE ON $tabla FOR EACH ROW\nBEGIN\n"
          . "  IF OLD.uuid IS NOT NULL AND OLD.uuid <> '' THEN SET NEW.uuid = OLD.uuid; END IF;\n"
          . "  IF @sinc_origen IS NULL AND NOT ($iguales) THEN SET NEW.sinc_modificado_en = NOW(6); END IF;\n"
          . "END //\n";
    $sql .= "DROP TRIGGER IF EXISTS sinc_{$tabla}_au //\n"
          . "CREATE TRIGGER sinc_{$tabla}_au AFTER UPDATE ON $tabla FOR EACH ROW\nBEGIN\n"
          . "  IF NOT (NEW.sinc_modificado_en <=> OLD.sinc_modificado_en) THEN\n"
          . "    INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)\n"
          . "    VALUES ('$tabla', NEW.uuid, " . institucionFila($def, 'NEW.') . ", 'cambio', " . jsonFila($tabla, $def, 'NEW.') . ", @sinc_origen);\n"
          . "  END IF;\n$mover" . "END //\n";

    if ($def['bajas']) {
        $sql .= "DROP TRIGGER IF EXISTS sinc_{$tabla}_ad //\n"
              . "CREATE TRIGGER sinc_{$tabla}_ad AFTER DELETE ON $tabla FOR EACH ROW\n"
              . "  INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)\n"
              . "  VALUES ('$tabla', OLD.uuid, " . institucionFila($def, 'OLD.') . ", 'baja', JSON_OBJECT('sinc_modificado_en', COALESCE(@sinc_fecha_baja, NOW(6))), @sinc_origen) //\n";
    }
}
$sql .= "\nDELIMITER ;\n";

if ($siembra) {
    // Filas que ya existían antes de la cola: se encolan una vez como alta (repetirlo no duplica)
    $porOrden = SINC_TABLAS;
    uasort($porOrden, fn ($a, $b) => $a['orden'] <=> $b['orden']);
    $sql .= "\n-- Siembra: filas existentes a la cola (una sola vez; repetirlo no duplica)\n";
    foreach ($porOrden as $tabla => $def) {
        $sql .= "INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)\n"
              . "SELECT '$tabla', t.uuid, " . institucionFila($def, 't.') . ", 'alta', " . jsonFila($tabla, $def, 't.') . ", NULL\n"
              . "  FROM $tabla t\n"
              . " WHERE NOT EXISTS (SELECT 1 FROM sincronizacion_cola c WHERE c.tabla = '$tabla' AND c.uuid = t.uuid)\n"
              . " ORDER BY t.id;\n";
    }
}
echo $sql;
