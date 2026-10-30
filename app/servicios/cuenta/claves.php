<?php
/**
 * Contraseñas: Argon2id (bcrypt si el PHP no lo trae) y reglas de contraseña robusta.
 */

/** Algoritmo para password_hash(): Argon2id si está disponible. */
function algoritmoClave(): string|int
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
}

function hashClave(string $clave): string
{
    return password_hash($clave, algoritmoClave());
}

/** Contraseñas muy usadas que no se aceptan aunque cumplan las reglas. */
const CLAVES_COMUNES = [
    'password', 'contraseña', 'contrasena', '123456789012', 'qwertyuiop12', 'administrador', 'bienvenido',
    'expediente', 'hospital', 'clinica', 'medico', 'doctor', 'mexico', 'femeci', 'abc12345', 'iloveyou',
];

/**
 * Motivo por el que la contraseña no es robusta, o null si lo es:
 * 12 a 128 caracteres, al menos tres de cuatro tipos (minúsculas, mayúsculas, números, símbolos),
 * sin contener el correo ni el nombre, sin repetir un mismo carácter y fuera de la lista de comunes.
 */
function motivoClaveDebil(string $clave, string $correo = '', string $nombre = ''): ?string
{
    $largo = mb_strlen($clave);
    if ($largo < 12) {
        return 'La contraseña debe tener al menos 12 caracteres.';
    }
    if ($largo > 128) {
        return 'La contraseña puede tener hasta 128 caracteres.';
    }
    $tipos = (int) preg_match('/\p{Ll}/u', $clave) + (int) preg_match('/\p{Lu}/u', $clave)
           + (int) preg_match('/\d/', $clave) + (int) preg_match('/[^\p{L}\d]/u', $clave);
    if ($tipos < 3) {
        return 'Combina al menos tres tipos de caracteres: minúsculas, mayúsculas, números o símbolos.';
    }
    if (preg_match('/(.)\1{3,}/u', $clave)) {
        return 'La contraseña no debe repetir el mismo carácter cuatro veces seguidas.';
    }
    $minuscula = mb_strtolower($clave);
    foreach (CLAVES_COMUNES as $comun) {
        if (str_contains($minuscula, $comun)) {
            return 'La contraseña contiene una palabra muy común. Elige otra.';
        }
    }
    $local = mb_strtolower((string) strstr($correo, '@', true));
    if (mb_strlen($local) >= 4 && str_contains($minuscula, $local)) {
        return 'La contraseña no debe contener tu correo.';
    }
    foreach (preg_split('/\s+/u', mb_strtolower($nombre)) ?: [] as $parte) {
        if (mb_strlen($parte) >= 4 && str_contains($minuscula, $parte)) {
            return 'La contraseña no debe contener tu nombre.';
        }
    }
    return null;
}
