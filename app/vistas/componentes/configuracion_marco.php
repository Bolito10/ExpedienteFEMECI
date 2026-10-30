<?php
/**
 * Marco de Configuración: navegación por grupos a la izquierda y el contenido a la derecha.
 * Lo usan configuracion/index.php y las páginas grandes que viven aparte (Personas y permisos, Respaldos,
 * Correo), para que todo se sienta como una sola pantalla de ajustes.
 *   seccionesConfiguracion()           secciones visibles para el rol (grupo, clave, texto, icono, url)
 *   abrirMarcoConfiguracion($activa)   imprime la navegación y abre el contenido
 *   cerrarMarcoConfiguracion()         cierra el contenido
 */
require_once __DIR__ . '/../../servicios/sistema/respaldos.php';

function seccionesConfiguracion(): array
{
    $u = usuarioActual();
    $s = [
        ['Cuenta', 'cuenta', $u['nombre'], 'usuario', '/configuracion/'],
        ['Cuenta', 'notificaciones', 'Notificaciones', 'campana', '/configuracion/?s=notificaciones'],
        ['Cuenta', 'memoria', 'Memoria de la IA', 'chispa', '/configuracion/?s=memoria'],
    ];
    if (puede('configurar')) {
        array_push($s,
            ['Clínica', 'clinica', 'General', 'casa', '/configuracion/?s=clinica'],
            ['Clínica', 'personas', 'Personas y permisos', 'usuarios', '/configuracion/personas.php'],
            ['Clínica', 'formatos', 'Importar y formatos', 'tabla', '/configuracion/?s=formatos'],
        );
        if (puedeAdministrarRespaldos()) {
            array_push($s, ['Clínica', 'respaldos', 'Respaldos', 'carpeta', '/configuracion/respaldos.php'], ['Clínica', 'correo', 'Correo', 'correo', '/configuracion/correo.php']);
        }
        $s[] = ['Funciones', 'asistente', 'Asistente de IA y sincronización', 'chispa', '/configuracion/?s=asistente'];
    }
    return $s;
}

function abrirMarcoConfiguracion(string $activa, bool $ancho = false): void
{
    $u = usuarioActual();
    echo '<div class="configuracion"><nav class="configuracion__nav" aria-label="Secciones de configuración">';
    echo '<label class="configuracion__buscar">' . icono('buscar') . '<input type="search" placeholder="Buscar configuración" data-configuracion-buscar aria-label="Buscar configuración"></label>';
    $grupo = null;
    foreach (seccionesConfiguracion() as [$g, $clave, $texto, $ico, $url]) {
        if ($g !== $grupo) {
            $grupo = $g;
            echo '<div class="configuracion__grupo">' . e($g) . '</div>';
        }
        $esta = $clave === $activa;
        echo '<a class="configuracion__enlace' . ($esta ? ' activo' : '') . '" href="' . URL_BASE . e($url) . '"' . ($esta ? ' aria-current="page"' : '') . '>'
            . ($clave === 'cuenta' ? avatar((int) $u['id'], $u['nombre'], $u['foto'] ?? null) : icono($ico)) . '<span>' . e($texto) . '</span></a>';
    }
    echo '</nav><div class="configuracion__contenido' . ($ancho ? ' configuracion__contenido--ancho' : '') . '" data-configuracion-contenido>';
}

function cerrarMarcoConfiguracion(): void
{
    echo '</div></div>';
}
