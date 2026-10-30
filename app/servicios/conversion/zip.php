<?php
/**
 * ZIP mínimo en PHP puro (el PHP de XAMPP no trae la extensión zip): lo justo para leer y
 * escribir archivos XLSX. Usa zlib (gzinflate/gzdeflate) para el método "deflate".
 * Protección contra bombas de compresión: límite de tamaño descomprimido por archivo y total.
 */

const ZIP_MAXIMO_DESCOMPRIMIDO = 200 * 1024 * 1024;   // 200 MB en total

/**
 * Lee las entradas de un ZIP. Devuelve [nombre => contenido] de las entradas que acepte $filtro
 * (o de todas si es null). Lanza RuntimeException si el archivo no es un ZIP válido.
 */
function zipLeer(string $datos, ?callable $filtro = null): array
{
    $largo = strlen($datos);
    // Fin del directorio central: firma 0x06054b50 en los últimos 64 KB
    $inicioBusqueda = max(0, $largo - 65557);
    $pos = strrpos(substr($datos, $inicioBusqueda), "PK\x05\x06");
    if ($pos === false) {
        throw new RuntimeException('El archivo no es un ZIP/XLSX válido.');
    }
    $pos += $inicioBusqueda;
    $fin = unpack('vdisco/vdiscoCd/ventradasDisco/ventradas/Vtamano/Voffset', substr($datos, $pos + 4, 16));
    $offset = $fin['offset'];
    $entradas = [];
    $total = 0;
    for ($i = 0; $i < $fin['entradas']; $i++) {
        if (substr($datos, $offset, 4) !== "PK\x01\x02") {
            throw new RuntimeException('El directorio del ZIP está dañado.');
        }
        $c = unpack('vversion/vnecesaria/vbanderas/vmetodo/vhora/vfecha/Vcrc/Vcomprimido/Vdescomprimido/vlargoNombre/vlargoExtra/vlargoComentario/vdisco/vinternos/Vexternos/Vlocal',
                    substr($datos, $offset + 4, 42));
        $nombre = substr($datos, $offset + 46, $c['largoNombre']);
        $offset += 46 + $c['largoNombre'] + $c['largoExtra'] + $c['largoComentario'];
        if (str_ends_with($nombre, '/') || ($filtro && !$filtro($nombre))) {
            continue;
        }
        if ($c['banderas'] & 1) {
            throw new RuntimeException('El archivo está protegido con contraseña.');
        }
        if ($c['descomprimido'] > ZIP_MAXIMO_DESCOMPRIMIDO || ($total += $c['descomprimido']) > ZIP_MAXIMO_DESCOMPRIMIDO) {
            throw new RuntimeException('El contenido descomprimido es demasiado grande.');
        }
        $local = unpack('vlargoNombre/vlargoExtra', substr($datos, $c['local'] + 26, 4));
        $inicio = $c['local'] + 30 + $local['largoNombre'] + $local['largoExtra'];
        $crudo = substr($datos, $inicio, $c['comprimido']);
        $contenido = match ($c['metodo']) {
            0 => $crudo,
            8 => @gzinflate($crudo, max(1, $c['descomprimido'])),
            default => throw new RuntimeException('El ZIP usa un método de compresión no compatible.'),
        };
        if ($contenido === false) {
            throw new RuntimeException('No se pudo descomprimir «' . $nombre . '».');
        }
        $entradas[$nombre] = $contenido;
    }
    return $entradas;
}

/** Crea un ZIP (deflate) con [nombre => contenido]. */
function zipCrear(array $archivos): string
{
    $salida = '';
    $directorio = '';
    $hora = (int) date('G') << 11 | (int) date('i') << 5 | intdiv((int) date('s'), 2);
    $fecha = ((int) date('Y') - 1980) << 9 | (int) date('n') << 5 | (int) date('j');
    foreach ($archivos as $nombre => $contenido) {
        $comprimido = gzdeflate($contenido, 6);
        $crc = crc32($contenido);
        $cabecera = pack('vvvvvVVVvv', 20, 0x0800, 8, $hora, $fecha, $crc, strlen($comprimido), strlen($contenido), strlen($nombre), 0);
        $offset = strlen($salida);
        $salida .= "PK\x03\x04" . $cabecera . $nombre . $comprimido;
        $directorio .= "PK\x01\x02" . pack('v', 20) . $cabecera . pack('vvvVV', 0, 0, 0, 0, $offset) . $nombre;
    }
    return $salida . $directorio
         . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($archivos), count($archivos), strlen($directorio), strlen($salida), 0);
}
