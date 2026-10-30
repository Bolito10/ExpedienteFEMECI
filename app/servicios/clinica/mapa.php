<?php
/**
 * Mapa de México por entidad federativa (identificación de localización).
 * Los trazos vienen de app/datos/mapa_mexico.php (Natural Earth, dominio público).
 */
require_once __DIR__ . '/../../datos/mapa_mexico.php';

/** Quita acentos y pasa a minúsculas para comparar texto libre. */
function textoPlano(string $texto): string
{
    $texto = mb_strtolower($texto);
    return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
}

/**
 * Identifica la entidad federativa escrita en un domicilio ("Cuernavaca, Morelos" → MS).
 * Devuelve la clave de entidad (la misma de la CURP) o null si no se reconoce.
 * Si aparecen varias, gana la última (el estado suele escribirse al final).
 */
function entidadDesdeTexto(?string $texto): ?string
{
    if (!$texto) {
        return null;
    }
    static $alias = null;
    if ($alias === null) {
        $alias = [];
        foreach (ENTIDADES as $clave => $nombre) {
            if ($clave !== 'NE') {
                $alias[textoPlano($nombre)] = $clave;
            }
        }
        $alias += [
            'cdmx' => 'DF', 'distrito federal' => 'DF', 'ciudad de mexico' => 'DF',
            'edomex' => 'MC', 'edo. de mexico' => 'MC', 'edo de mexico' => 'MC', 'estado de mexico' => 'MC',
            'coahuila de zaragoza' => 'CL', 'michoacan de ocampo' => 'MN', 'veracruz de ignacio de la llave' => 'VZ',
            'nl' => 'NL', 'bcs' => 'BS', 'qroo' => 'QR', 'q. roo' => 'QR', 'slp' => 'SP',
        ];
        // Los nombres largos primero, para que "baja california sur" no se lea como "baja california"
        uksort($alias, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    }

    $plano    = ' ' . preg_replace('/[^a-z0-9. ]+/', ' ', textoPlano($texto)) . ' ';
    $mejor    = null;
    $posicion = -1;
    foreach ($alias as $nombre => $clave) {
        $encontrado = strrpos($plano, ' ' . $nombre . ' ');
        if ($encontrado !== false && $encontrado > $posicion) {
            // Evita que un nombre corto contenido en otro más largo ya elegido gane por posición
            if ($mejor !== null && str_contains($mejor[0], $nombre)) {
                continue;
            }
            $mejor    = [$nombre, $clave];
            $posicion = $encontrado;
        }
    }
    return $mejor[1] ?? null;
}

/**
 * Dibuja el mapa.
 *   $niveles    [clave => 0..5] intensidad de color por entidad (0 = sin pacientes).
 *   $textos     [clave => texto del globo] (opcional).
 *   $resaltada  clave de una entidad a resaltar (las demás se atenúan) o null.
 *   $clase      clase extra del <svg>.
 */
function mapaMexico(array $niveles = [], array $textos = [], ?string $resaltada = null, string $clase = ''): string
{
    $html = '<svg class="mapa ' . e($clase) . '" viewBox="' . MAPA_MEXICO_VIEWBOX . '" role="img" '
          . 'aria-label="Mapa de México por entidad federativa">';
    foreach (MAPA_MEXICO as $clave => $trazo) {
        $nombre = ENTIDADES[$clave] ?? $clave;
        $clases = 'mapa__estado nivel-' . (int) ($niveles[$clave] ?? 0);
        if ($resaltada !== null) {
            $clases .= $clave === $resaltada ? ' resaltada' : ' atenuada';
        }
        $html .= '<path class="' . $clases . '" d="' . $trazo . '" data-entidad="' . $clave . '" '
               . 'data-texto="' . e($textos[$clave] ?? $nombre) . '"><title>' . e($textos[$clave] ?? $nombre) . '</title></path>';
    }
    return $html . '</svg>';
}
