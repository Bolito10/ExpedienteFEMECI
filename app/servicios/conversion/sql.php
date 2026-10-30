<?php
/**
 * Volcados SQL de MySQL/MariaDB, PostgreSQL y SQLite. NUNCA se ejecuta el SQL: se analiza como texto.
 * Se reconocen CREATE TABLE (nombres y tipos de columna), INSERT/REPLACE INTO … VALUES (incluso con
 * varias filas por sentencia), y COPY … FROM stdin de pg_dump. Cadenas con '' y, en MySQL o E'…',
 * con escapes de barra invertida; identificadores con `…`, "…" o […]; comentarios -- # y /* *\/.
 */

/** Dialecto probable del volcado. */
function sqlDetectarDialecto(string $texto): string
{
    $muestra = substr($texto, 0, 300000);
    $puntos = ['mysql' => 0, 'postgresql' => 0, 'sqlite' => 0];
    $puntos['mysql']      += preg_match_all('/`|ENGINE\s*=|\/\*!\d{5}|AUTO_INCREMENT|LOCK TABLES|mysqldump|MariaDB/i', $muestra);
    $puntos['postgresql'] += 2 * preg_match_all('/pg_dump|PostgreSQL|SET search_path|::[a-z]|\bCOPY\s+\S+.*FROM\s+stdin|\bSERIAL\b|nextval\(|OWNER TO/i', $muestra);
    $puntos['sqlite']     += 2 * preg_match_all('/PRAGMA|sqlite_sequence|BEGIN TRANSACTION;|AUTOINCREMENT\b/i', $muestra);
    arsort($puntos);
    return reset($puntos) > 0 ? array_key_first($puntos) : 'sql';
}

final class LectorSql
{
    private int $i = 0;
    private int $n;

    public function __construct(private string $s, private string $dialecto)
    {
        $this->n = strlen($s);
    }

    public function posicion(): int
    {
        return $this->i;
    }

    public function moverA(int $posicion): void
    {
        $this->i = $posicion;
    }

    public function texto(): string
    {
        return $this->s;
    }

    /** Siguiente token o null al final: ['t' => tipo, 'v' => valor]. */
    public function token(): ?array
    {
        $s = $this->s;
        while ($this->i < $this->n) {
            $c = $s[$this->i];
            // Espacios
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f" || $c === "\v") {
                $this->i += strspn($s, " \t\n\r\f\v", $this->i);
                continue;
            }
            // Comentarios
            if (($c === '-' && ($s[$this->i + 1] ?? '') === '-') || ($c === '#' && $this->dialecto === 'mysql')) {
                $fin = strpos($s, "\n", $this->i);
                $this->i = $fin === false ? $this->n : $fin + 1;
                continue;
            }
            if ($c === '/' && ($s[$this->i + 1] ?? '') === '*') {
                $fin = strpos($s, '*/', $this->i + 2);
                $this->i = $fin === false ? $this->n : $fin + 2;
                continue;
            }
            break;
        }
        if ($this->i >= $this->n) {
            return null;
        }
        $c = $s[$this->i];

        if ($c === "'") {
            return ['t' => 'cadena', 'v' => $this->cadena("'", $this->dialecto === 'mysql')];
        }
        if ($c === '"') {
            // En MySQL (modo normal) las comillas dobles son cadenas; en el estándar, identificadores
            return $this->dialecto === 'mysql' ? ['t' => 'cadena', 'v' => $this->cadena('"', true)] : ['t' => 'id', 'v' => $this->cadena('"', false)];
        }
        if ($c === '`') {
            return ['t' => 'id', 'v' => $this->cadena('`', false)];
        }
        if ($c === '[' && $this->dialecto !== 'mysql' && $this->dialecto !== 'postgresql') {
            $fin = strpos($s, ']', $this->i);
            $fin = $fin === false ? $this->n : $fin;
            $valor = substr($s, $this->i + 1, $fin - $this->i - 1);
            $this->i = $fin + 1;
            return ['t' => 'id', 'v' => $valor];
        }
        if ($c === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $s, $m, 0, $this->i)) {
            $etiqueta = $m[0];
            $inicio = $this->i + strlen($etiqueta);
            $fin = strpos($s, $etiqueta, $inicio);
            $fin = $fin === false ? $this->n : $fin;
            $this->i = $fin + strlen($etiqueta);
            return ['t' => 'cadena', 'v' => substr($s, $inicio, $fin - $inicio)];
        }
        if (ctype_digit($c) || ($c === '.' && ctype_digit($s[$this->i + 1] ?? ''))) {
            if ($c === '0' && ($s[$this->i + 1] ?? '') === 'x' && preg_match('/\G0x[0-9A-Fa-f]+/', $s, $m, 0, $this->i)) {
                $this->i += strlen($m[0]);
                return ['t' => 'hex', 'v' => $m[0]];
            }
            preg_match('/\G(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', $s, $m, 0, $this->i);
            $this->i += strlen($m[0]);
            return ['t' => 'numero', 'v' => $m[0]];
        }
        if (ctype_alpha($c) || $c === '_') {
            preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/', $s, $m, 0, $this->i);
            $palabra = $m[0];
            $this->i += strlen($palabra);
            if (($s[$this->i] ?? '') === "'") {
                $prefijo = strtoupper($palabra);
                if ($prefijo === 'E') {
                    return ['t' => 'cadena', 'v' => $this->cadena("'", true)];
                }
                if ($prefijo === 'X') {
                    return ['t' => 'hex', 'v' => '0x' . $this->cadena("'", false)];
                }
                if ($prefijo === 'N' || $prefijo === 'B' || $prefijo === 'U' || str_starts_with($palabra, '_')) {
                    return ['t' => 'cadena', 'v' => $this->cadena("'", $this->dialecto === 'mysql')];
                }
            }
            return ['t' => 'palabra', 'v' => $palabra];
        }
        if ($c === ':' && ($s[$this->i + 1] ?? '') === ':') {
            $this->i += 2;
            return ['t' => 'simbolo', 'v' => '::'];
        }
        $this->i++;
        return ['t' => 'simbolo', 'v' => $c];
    }

    /** Cadena entre comillas: la comilla se duplica para escaparla; opcionalmente, escapes con \. */
    private function cadena(string $comilla, bool $barra): string
    {
        $s = $this->s;
        $this->i++;
        $valor = '';
        $buscar = $barra ? $comilla . '\\' : $comilla;
        while ($this->i < $this->n) {
            $largo = strcspn($s, $buscar, $this->i);
            $valor .= substr($s, $this->i, $largo);
            $this->i += $largo;
            if ($this->i >= $this->n) {
                break;
            }
            $c = $s[$this->i];
            if ($c === '\\') {
                $siguiente = $s[$this->i + 1] ?? '';
                $valor .= match ($siguiente) {
                    '0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1A",
                    '%', '_' => '\\' . $siguiente,
                    default => $siguiente,
                };
                $this->i += 2;
                continue;
            }
            // comilla
            if (($s[$this->i + 1] ?? '') === $comilla) {
                $valor .= $comilla;
                $this->i += 2;
                continue;
            }
            $this->i++;
            return $valor;
        }
        return $valor;
    }

    /** Tokens de la siguiente sentencia (hasta ; fuera de cadenas), o null al final. */
    public function sentencia(): ?array
    {
        $tokens = [];
        while (($t = $this->token()) !== null) {
            if ($t['t'] === 'simbolo' && $t['v'] === ';') {
                if ($tokens) {
                    return $tokens;
                }
                continue;
            }
            $tokens[] = $t;
        }
        return $tokens ?: null;
    }

    /** Salta hasta después de la siguiente línea que sea exactamente $linea (o al final). */
    public function saltarHastaLinea(string $patron): void
    {
        if (preg_match($patron, $this->s, $m, PREG_OFFSET_CAPTURE, $this->i)) {
            $this->i = $m[0][1] + strlen($m[0][0]);
        } else {
            $this->i = $this->n;
        }
    }
}

/** Analiza el volcado y devuelve una tabla por cada CREATE TABLE / INSERT INTO / COPY. */
function leerSql(string $datos): array
{
    [$texto] = convTextoUtf8($datos);
    $dialecto = sqlDetectarDialecto($texto);
    $lector = new LectorSql($texto, $dialecto);
    $tablas = [];   // nombre en minúsculas → ['nombre', 'columnas' => [], 'tipos' => [], 'filas' => []]
    $sentencias = 0;
    $ignoradas = 0;

    $mayus = fn (?array $t): string => $t && $t['t'] === 'palabra' ? strtoupper($t['v']) : '';
    $tabla = function (string $nombre) use (&$tablas): string {
        $clave = mb_strtolower($nombre);
        $tablas[$clave] ??= ['nombre' => $nombre, 'columnas' => [], 'tipos' => [], 'filas' => []];
        return $clave;
    };

    while (($sql = $lector->sentencia()) !== null) {
        $sentencias++;
        $primera = $mayus($sql[0]);

        // DELIMITER de mysqldump (rutinas y disparadores): se omite el bloque completo
        if ($primera === 'DELIMITER') {
            $lector->saltarHastaLinea('/^\s*DELIMITER\s*;\s*$/mi');
            $ignoradas++;
            continue;
        }
        // CREATE TRIGGER de SQLite: el cuerpo BEGIN … END trae ; internos
        if ($primera === 'CREATE' && in_array('TRIGGER', array_map($mayus, array_slice($sql, 1, 3)), true)) {
            while ($mayus(end($sql)) !== 'END' && ($sql = $lector->sentencia()) !== null) {
            }
            $ignoradas++;
            continue;
        }

        if ($primera === 'CREATE') {
            $k = 1;
            while (in_array($mayus($sql[$k] ?? null), ['TEMPORARY', 'TEMP', 'UNLOGGED', 'GLOBAL', 'LOCAL', 'OR', 'REPLACE'], true)) {
                $k++;
            }
            if ($mayus($sql[$k] ?? null) !== 'TABLE') {
                $ignoradas++;
                continue;
            }
            $k++;
            if ($mayus($sql[$k] ?? null) === 'IF') {
                $k += 3;   // IF NOT EXISTS
            }
            [$nombre, $k] = sqlNombreCompuesto($sql, $k);
            if ($nombre === null || ($sql[$k]['v'] ?? '') !== '(') {
                $ignoradas++;
                continue;
            }
            $clave = $tabla($nombre);
            foreach (sqlPartesEntreParentesis($sql, $k) as $parte) {
                $palabra = $mayus($parte[0] ?? null);
                if (!$parte || in_array($palabra, ['PRIMARY', 'KEY', 'UNIQUE', 'INDEX', 'CONSTRAINT', 'FOREIGN', 'CHECK', 'FULLTEXT', 'SPATIAL', 'EXCLUDE', 'LIKE', 'PERIOD'], true)) {
                    continue;
                }
                $columna = (string) $parte[0]['v'];
                $tipo = [];
                foreach (array_slice($parte, 1) as $t) {
                    if ($t['t'] !== 'palabra' || in_array(strtoupper($t['v']), ['NOT', 'NULL', 'DEFAULT', 'PRIMARY', 'UNIQUE', 'REFERENCES', 'CHECK', 'COLLATE', 'CHARACTER', 'AUTO_INCREMENT', 'AUTOINCREMENT', 'COMMENT', 'GENERATED', 'CONSTRAINT', 'ON'], true)) {
                        break;
                    }
                    $tipo[] = strtoupper($t['v']);
                }
                if (!in_array($columna, $tablas[$clave]['columnas'], true)) {
                    $tablas[$clave]['columnas'][] = $columna;
                    $tablas[$clave]['tipos'][] = implode(' ', $tipo);
                }
            }
            continue;
        }

        if ($primera === 'INSERT' || $primera === 'REPLACE') {
            $k = 1;
            while ($k < count($sql) && $mayus($sql[$k]) !== 'INTO') {
                $k++;   // OR REPLACE / IGNORE / LOW_PRIORITY…
            }
            [$nombre, $k] = sqlNombreCompuesto($sql, $k + 1);
            if ($nombre === null) {
                $ignoradas++;
                continue;
            }
            $clave = $tabla($nombre);
            $columnasInsert = null;
            if (($sql[$k]['v'] ?? '') === '(' && $sql[$k]['t'] === 'simbolo') {
                $columnasInsert = array_map(fn ($p) => (string) ($p[0]['v'] ?? ''), sqlPartesEntreParentesis($sql, $k));
                $k = sqlDespuesDeParentesis($sql, $k);
            }
            if (!in_array($mayus($sql[$k] ?? null), ['VALUES', 'VALUE'], true)) {
                $ignoradas++;   // INSERT … SELECT
                continue;
            }
            $k++;
            $destino = &$tablas[$clave];
            $indices = null;
            if ($columnasInsert !== null) {
                $indices = [];
                foreach ($columnasInsert as $col) {
                    $pos = array_search($col, $destino['columnas'], true);
                    if ($pos === false) {
                        $destino['columnas'][] = $col;
                        $destino['tipos'][] = '';
                        $pos = count($destino['columnas']) - 1;
                    }
                    $indices[] = $pos;
                }
            }
            $total = count($sql);
            while ($k < $total && ($sql[$k]['v'] ?? '') === '(' && $sql[$k]['t'] === 'simbolo') {
                $valores = array_map('sqlValor', sqlPartesEntreParentesis($sql, $k));
                $k = sqlDespuesDeParentesis($sql, $k);
                if ($indices === null) {
                    for ($c = count($destino['columnas']); $c < count($valores); $c++) {
                        $destino['columnas'][] = 'Columna ' . ($c + 1);
                        $destino['tipos'][] = '';
                    }
                    $destino['filas'][] = $valores;
                } else {
                    $fila = [];
                    foreach ($indices as $j => $pos) {
                        $fila[$pos] = $valores[$j] ?? null;
                    }
                    $destino['filas'][] = $fila;
                }
                if (count($destino['filas']) > LIMITE_FILAS_LECTURA) {
                    break;
                }
                if (($sql[$k]['v'] ?? '') === ',') {
                    $k++;
                }
            }
            unset($destino);
            continue;
        }

        // COPY tabla (columnas) FROM stdin;  … filas separadas por tabulador … \.
        if ($primera === 'COPY' && in_array('STDIN', array_map($mayus, $sql), true)) {
            [$nombre, $k] = sqlNombreCompuesto($sql, 1);
            $clave = $tabla($nombre ?? 'datos');
            $columnasCopy = ($sql[$k]['v'] ?? '') === '(' ? array_map(fn ($p) => (string) ($p[0]['v'] ?? ''), sqlPartesEntreParentesis($sql, $k)) : $tablas[$clave]['columnas'];
            foreach ($columnasCopy as $col) {
                if (!in_array($col, $tablas[$clave]['columnas'], true)) {
                    $tablas[$clave]['columnas'][] = $col;
                    $tablas[$clave]['tipos'][] = '';
                }
            }
            $indices = array_map(fn ($c) => array_search($c, $tablas[$clave]['columnas'], true), $columnasCopy);
            $texto = $lector->texto();
            $inicio = strpos($texto, "\n", $lector->posicion());
            $inicio = $inicio === false ? strlen($texto) : $inicio + 1;
            $fin = preg_match('/^\\\\\.\r?$/m', $texto, $m, PREG_OFFSET_CAPTURE, $inicio) ? $m[0][1] : strlen($texto);
            foreach (preg_split('/\r?\n/', substr($texto, $inicio, $fin - $inicio)) as $linea) {
                if ($linea === '') {
                    continue;
                }
                $fila = [];
                foreach (explode("\t", $linea) as $j => $campo) {
                    if (isset($indices[$j]) && $indices[$j] !== false) {
                        $fila[$indices[$j]] = $campo === '\N' ? null : sqlEscapesCopy($campo);
                    }
                }
                $tablas[$clave]['filas'][] = $fila;
                if (count($tablas[$clave]['filas']) > LIMITE_FILAS_LECTURA) {
                    break;
                }
            }
            $lector->moverA($fin + 2);
            continue;
        }
        $ignoradas++;
    }

    $salida = [];
    foreach ($tablas as $t) {
        $ancho = count($t['columnas']);
        $filas = array_map(function ($f) use ($ancho) {
            $completa = [];
            for ($c = 0; $c < $ancho; $c++) {
                $completa[] = $f[$c] ?? null;
            }
            return $completa;
        }, $t['filas']);
        $tabla = convTabla($t['nombre'], $t['columnas'], $filas);
        $tabla['tipos'] = $t['tipos'];
        $salida[] = $tabla;
    }
    // Las tablas con datos primero
    usort($salida, fn ($a, $b) => (count($b['filas']) > 0) <=> (count($a['filas']) > 0));
    $nombresDialecto = ['mysql' => 'MySQL / MariaDB', 'postgresql' => 'PostgreSQL', 'sqlite' => 'SQLite', 'sql' => 'SQL estándar'];
    return [
        'formato' => 'sql',
        'dialecto' => $dialecto,
        'tablas' => $salida,
        'avisos' => ['Dialecto: ' . $nombresDialecto[$dialecto] . " · $sentencias sentencias analizadas (no se ejecutó ninguna)"
                     . ($ignoradas ? " · $ignoradas sin datos de tabla omitidas" : '')],
    ];
}

/** Nombre posiblemente calificado (esquema.tabla): devuelve [ultimaParte, índiceSiguiente]. */
function sqlNombreCompuesto(array $sql, int $k): array
{
    $nombre = null;
    while (isset($sql[$k]) && in_array($sql[$k]['t'], ['palabra', 'id'], true)) {
        $nombre = (string) $sql[$k]['v'];
        $k++;
        if (($sql[$k]['v'] ?? '') === '.' && $sql[$k]['t'] === 'simbolo') {
            $k++;
            continue;
        }
        break;
    }
    return [$nombre, $k];
}

/** Partes separadas por coma dentro del paréntesis que abre en $k (respeta anidación). */
function sqlPartesEntreParentesis(array $sql, int $k): array
{
    $partes = [];
    $actual = [];
    $nivel = 0;
    for ($i = $k, $total = count($sql); $i < $total; $i++) {
        $t = $sql[$i];
        $simbolo = $t['t'] === 'simbolo' ? $t['v'] : null;
        if ($simbolo === '(') {
            $nivel++;
            if ($nivel === 1) {
                continue;
            }
        } elseif ($simbolo === ')') {
            $nivel--;
            if ($nivel === 0) {
                $partes[] = $actual;
                return $partes;
            }
        } elseif ($simbolo === ',' && $nivel === 1) {
            $partes[] = $actual;
            $actual = [];
            continue;
        }
        $actual[] = $t;
    }
    $partes[] = $actual;
    return $partes;
}

function sqlDespuesDeParentesis(array $sql, int $k): int
{
    $nivel = 0;
    for ($i = $k, $total = count($sql); $i < $total; $i++) {
        if ($sql[$i]['t'] !== 'simbolo') {
            continue;
        }
        if ($sql[$i]['v'] === '(') {
            $nivel++;
        } elseif ($sql[$i]['v'] === ')' && --$nivel === 0) {
            return $i + 1;
        }
    }
    return count($sql);
}

/** Valor literal de una lista de tokens: cadena, número, NULL, TRUE/FALSE; lo demás se conserva como texto. */
function sqlValor(array $tokens): string|int|float|bool|null
{
    if (!$tokens) {
        return null;
    }
    $primero = $tokens[0];
    if ($primero['t'] === 'cadena') {
        return $primero['v'];
    }
    if ($primero['t'] === 'palabra' && str_starts_with($primero['v'], '_') && ($tokens[1]['t'] ?? '') === 'cadena') {
        return $tokens[1]['v'];   // _utf8mb4 'texto' / _binary '…'
    }
    if ($primero['t'] === 'hex') {
        return $primero['v'];
    }
    $signo = '';
    if ($primero['t'] === 'simbolo' && ($primero['v'] === '-' || $primero['v'] === '+') && ($tokens[1]['t'] ?? '') === 'numero') {
        $signo = $primero['v'] === '-' ? '-' : '';
        $primero = $tokens[1];
    }
    if ($primero['t'] === 'numero') {
        $numero = $signo . $primero['v'];
        return preg_match('/^-?\d+$/', $numero) && strlen(ltrim($numero, '-')) < 16 ? (int) $numero : (is_numeric($numero) ? (float) $numero : $numero);
    }
    if ($primero['t'] === 'palabra') {
        $palabra = strtoupper($primero['v']);
        if ($palabra === 'NULL' && count($tokens) === 1) {
            return null;
        }
        if (($palabra === 'TRUE' || $palabra === 'FALSE') && count($tokens) === 1) {
            return $palabra === 'TRUE';
        }
    }
    // Expresiones (funciones, CURRENT_TIMESTAMP…): se guardan como texto
    return implode('', array_map(fn ($t) => $t['t'] === 'cadena' ? "'" . $t['v'] . "'" : $t['v'], $tokens));
}

/** Escapes del formato de texto de COPY de PostgreSQL. */
function sqlEscapesCopy(string $campo): string
{
    if (!str_contains($campo, '\\')) {
        return $campo;
    }
    return preg_replace_callback('/\\\\(.)/', fn ($m) => match ($m[1]) {
        'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", 'v' => "\v",
        default => $m[1],
    }, $campo);
}

/* ---------------------------------------------------------------------- Escritura */

/**
 * CREATE TABLE + INSERT (de 200 en 200 filas) para MySQL/MariaDB, PostgreSQL o SQLite.
 * $tipos (opcional) por columna: texto, numero, fecha, casilla, seleccion.
 */
function escribirSql(string $dialecto, string $nombreTabla, array $columnas, array $filas, array $tipos = []): string
{
    $citar = fn (string $id): string => $dialecto === 'mysql' ? '`' . $id . '`' : '"' . $id . '"';
    $tabla = convIdentificador($nombreTabla, 'datos');
    $ids = convIdentificadoresUnicos($columnas);

    // Tipo de cada columna: el indicado o el que se deduce de los valores
    $definiciones = [];
    $clases = [];
    foreach ($ids as $c => $id) {
        $valores = array_filter(array_column($filas, $c), fn ($v) => $v !== null && $v !== '');
        $clase = sqlClaseColumna($tipos[$c] ?? null, $valores);
        $clases[$c] = $clase;
        $largo = $valores ? max(array_map(fn ($v) => mb_strlen(convValorTexto($v)), $valores)) : 0;
        $tipoSql = match ($clase) {
            'entero'   => $dialecto === 'mysql' ? 'BIGINT' : 'INTEGER',
            'decimal'  => $dialecto === 'mysql' ? 'DOUBLE' : ($dialecto === 'postgresql' ? 'DOUBLE PRECISION' : 'REAL'),
            'fecha'    => $dialecto === 'sqlite' ? 'TEXT' : 'DATE',
            'booleano' => $dialecto === 'postgresql' ? 'BOOLEAN' : ($dialecto === 'mysql' ? 'TINYINT(1)' : 'INTEGER'),
            default    => $dialecto === 'sqlite' ? 'TEXT' : ($largo <= 255 ? 'VARCHAR(' . max(16, (int) (ceil(max(1, $largo) / 16) * 16)) . ')' : 'TEXT'),
        };
        $definiciones[] = '  ' . $citar($id) . ' ' . $tipoSql;
    }

    $literal = function (mixed $v, string $clase) use ($dialecto): string {
        if ($v === null || ($v === '' && $clase !== 'texto')) {
            return 'NULL';
        }
        if ($clase === 'booleano' || is_bool($v)) {
            $verdadero = is_bool($v) ? $v : in_array(mb_strtolower((string) $v), ['1', 'sí', 'si', 'true', 'verdadero', 'x', 'yes'], true);
            return $dialecto === 'postgresql' ? ($verdadero ? 'TRUE' : 'FALSE') : ($verdadero ? '1' : '0');
        }
        if (($clase === 'entero' || $clase === 'decimal') && is_numeric($v)) {
            return convValorTexto(is_string($v) ? $v + 0 : $v);
        }
        $texto = convValorTexto($v);
        $texto = str_replace("'", "''", $texto);
        if ($dialecto === 'mysql') {
            $texto = str_replace(['\\', "\0"], ['\\\\', '\\0'], $texto);
        } else {
            $texto = str_replace("\0", '', $texto);
        }
        return "'" . $texto . "'";
    };

    $nombres = ['mysql' => 'MySQL / MariaDB', 'postgresql' => 'PostgreSQL', 'sqlite' => 'SQLite'];
    $sql = "-- Exportado desde Expedientes Clínicos · " . date('Y-m-d H:i:s') . "\n"
         . "-- Dialecto: {$nombres[$dialecto]} · tabla «{$nombreTabla}» · " . count($filas) . " filas\n\n";
    $sql .= match ($dialecto) {
        'mysql'      => "SET NAMES utf8mb4;\nSTART TRANSACTION;\n\n",
        'postgresql' => "SET client_encoding = 'UTF8';\nSET standard_conforming_strings = on;\nBEGIN;\n\n",
        default      => "BEGIN TRANSACTION;\n\n",
    };
    $sql .= 'CREATE TABLE IF NOT EXISTS ' . $citar($tabla) . " (\n" . implode(",\n", $definiciones) . "\n)"
          . ($dialecto === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '') . ";\n\n";
    $listaColumnas = implode(', ', array_map($citar, $ids));
    foreach (array_chunk($filas, 200) as $bloque) {
        $tuplas = array_map(function ($fila) use ($literal, $clases) {
            $fila = array_values($fila);
            return '(' . implode(', ', array_map(fn ($c) => $literal($fila[$c] ?? null, $clases[$c]), array_keys($clases))) . ')';
        }, $bloque);
        $sql .= 'INSERT INTO ' . $citar($tabla) . " ($listaColumnas) VALUES\n" . implode(",\n", $tuplas) . ";\n";
    }
    return $sql . "\nCOMMIT;\n";
}

/** Clase de dato de una columna: entero, decimal, fecha, booleano o texto. */
function sqlClaseColumna(?string $tipo, array $valores): string
{
    $porTipo = ['numero' => null, 'fecha' => 'fecha', 'casilla' => 'booleano', 'seleccion' => 'texto', 'texto' => 'texto'];
    if ($tipo !== null && isset($porTipo[$tipo]) && $porTipo[$tipo] !== null) {
        return $porTipo[$tipo];
    }
    if (!$valores) {
        return 'texto';
    }
    $todos = fn (callable $f): bool => count(array_filter($valores, $f)) === count($valores);
    if ($todos(fn ($v) => is_bool($v))) {
        return 'booleano';
    }
    if ($todos(fn ($v) => is_int($v) || (is_string($v) && preg_match('/^-?(0|[1-9]\d{0,14})$/', $v)))) {
        return 'entero';
    }
    if ($todos(fn ($v) => is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/', $v)))) {
        return 'decimal';
    }
    if ($tipo === null && $todos(fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && DateTime::createFromFormat('!Y-m-d', $v))) {
        return 'fecha';
    }
    return 'texto';
}
