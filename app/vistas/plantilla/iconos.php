<?php
/**
 * Iconos SVG de línea (estilo Lucide) para usar en todo el expediente.
 * Uso: <?= icono('usuario') ?>
 */
function icono(string $nombre, string $clase = ''): string
{
    static $trazos = [
        'documento'   => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        'flujo'       => '<circle cx="6" cy="6" r="2"/><circle cx="18" cy="18" r="2"/><path d="M6 8v4a4 4 0 0 0 4 4h6"/><path d="M14 6h4v4"/>',
        'estrella'    => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
        'info'        => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'mas'         => '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
        'candado'     => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'ojo'         => '<path d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7z"/><circle cx="12" cy="12" r="3"/>',
        'menos'       => '<path d="M5 12h14"/>',
        'correo'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6.5 8.5 6.5 8.5-6.5"/>',
        'ventana'     => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
        'mano'        => '<path d="M8 13V6.5a1.5 1.5 0 0 1 3 0V12M11 11V5a1.5 1.5 0 0 1 3 0v6M14 11V6.5a1.5 1.5 0 0 1 3 0V14a6 6 0 0 1-6 6h-.6a5 5 0 0 1-3.9-1.9L4.4 15a1.5 1.5 0 0 1 2.3-1.9L8 14.5"/>',
        'regla'       => '<path d="M4 16 16 4l4 4L8 20z"/><path d="M8 12l2 2M11 9l2 2M14 6l2 2"/>',
        'contraste'   => '<circle cx="12" cy="12" r="8.5"/><path d="M12 3.5v17a8.5 8.5 0 0 0 0-17z" fill="currentColor" stroke="none"/>',
        'campana'     => '<path d="M6 9.5a6 6 0 0 1 12 0V13l1.6 2.6c.3.5 0 1.1-.6 1.1H5c-.6 0-.9-.6-.6-1.1L6 13z"/><path d="M10 19.5a2 2 0 0 0 4 0"/>',
        'corazon'     => '<path d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7.3a4.3 4.3 0 0 1 7.5 2.5C19.5 15.4 12 20 12 20z"/><path d="M7.5 12h2.3l1.3-2.3 1.9 4 1.2-1.7h2.3"/>',
        'alerta'      => '<path d="M10.3 4.2 2.8 17.5A2 2 0 0 0 4.5 20.5h15a2 2 0 0 0 1.7-3L13.7 4.2a2 2 0 0 0-3.4 0z"/><path d="M12 9.5v4M12 17h.01"/>',
        'camara'      => '<path d="M4 8.5A1.5 1.5 0 0 1 5.5 7h2l1.5-2h6l1.5 2h2A1.5 1.5 0 0 1 20 8.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 17.5z"/><circle cx="12" cy="12.5" r="3.2"/>',
        'huella'      => '<path d="M7 11a5 5 0 0 1 10 0v2a9 9 0 0 1-1.2 4.5"/><path d="M12 11v2.5a12 12 0 0 1-2 6.5M9.5 11a2.5 2.5 0 0 1 5 0v2M4.5 14a14 14 0 0 0 .5-3 7 7 0 0 1 12.5-4.3"/>',
        'bandera'     => '<path d="M5 21V4"/><path d="M5 4h11l-2 4 2 4H5"/>',
        'termometro'  => '<path d="M10 13.5V5a2 2 0 0 1 4 0v8.5a4 4 0 1 1-4 0z"/><path d="M12 9v6"/>',
        'balanza'     => '<rect x="3.5" y="4" width="17" height="16" rx="4"/><path d="M9 9a4 4 0 0 1 6 0l-2 2.5"/>',
        'usuario'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'casa'        => '<path d="M3 11 12 4l9 7"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
        'clinico'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/>',
        'mensaje'     => '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 9h8M8 12h5"/>',
        'calendario'  => '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/>',
        'grafica'     => '<path d="M4 4v16h16"/><path d="M8 16v-4M12 16V8M16 16v-6"/>',
        'carita'      => '<circle cx="12" cy="12" r="9"/><path d="M9 14a4 4 0 0 0 6 0M9 9.5h.01M15 9.5h.01"/>',
        'escudo'      => '<path d="M12 3 5 6v6c0 4.5 3 7.5 7 9 4-1.5 7-4.5 7-9V6z"/>',
        'lista'       => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
        'agregar'     => '<path d="M12 5v14M5 12h14"/>',
        'buscar'      => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'usuarios'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6"/>',
        'usuario_mas' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M19 8v6M16 11h6"/>',
        'menu'        => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'colapsar'    => '<path d="m11 17-5-5 5-5M18 17l-5-5 5-5"/>',
        'pagina'      => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
        'texto'       => '<path d="M4 7h16M4 12h16M4 17h10"/>',
        'aa'          => '<path d="m3 18 4.5-12L12 18M4.7 13.5h5.6"/><path d="M15 12.5a3 3 0 0 1 6 0V18m0-3.5c-1-1-6-1.5-6 1.5 0 2.5 4.5 2.5 6 0"/>',
        'numero'      => '<path d="M5 9h14M5 15h14M10 4 8 20M16 4l-2 16"/>',
        'seleccion'   => '<circle cx="12" cy="12" r="9"/><path d="m8.5 10.5 3.5 3.5 3.5-3.5"/>',
        'ordenar'     => '<path d="M7 4v16M3.5 16.5 7 20l3.5-3.5M17 20V4M13.5 7.5 17 4l3.5 3.5"/>',
        'gota'        => '<path d="M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11z"/>',
        'telefono'    => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
        'flecha_abajo'=> '<path d="m6 9 6 6 6-6"/>',
        'chispa'      => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 15l.7 2.3L22 18l-2.3.7L19 21l-.7-2.3L16 18l2.3-.7z"/>',
        'reloj'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'reabrir'     => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
        'escudo_alerta' => '<path d="M12 3 5 6v6c0 4.5 3 7.5 7 9 4-1.5 7-4.5 7-9V6z"/><path d="M12 8v4M12 15.5h.01"/>',
        'escudo_ok'   => '<path d="M12 3 5 6v6c0 4.5 3 7.5 7 9 4-1.5 7-4.5 7-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'flecha_arriba' => '<path d="M12 19V5M5 12l7-7 7 7"/>',
        'microfono'   => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/>',
        'onda'        => '<path d="M4 10v4M8 7v10M12 4v16M16 7v10M20 10v4"/>',
        'expandir'    => '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/><path d="m4 4 5 5M20 4l-5 5M4 20l5-5M20 20l-5-5"/>',
        'contraer'    => '<path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/>',
        'pulgar_arriba' => '<path d="M7 10v11H4V10zM7 10l4-7a2 2 0 0 1 3 2l-1 5h6a2 2 0 0 1 2 2.3l-1.4 7A2 2 0 0 1 17.6 21H7"/>',
        'pulgar_abajo'  => '<path d="M17 14V3h3v11zM17 14l-4 7a2 2 0 0 1-3-2l1-5H5a2 2 0 0 1-2-2.3l1.4-7A2 2 0 0 1 6.4 3H17"/>',
        'ubicacion'   => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'barras'      => '<path d="M6 20v-5M12 20V9M18 20V4"/>',
        'panel'       => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>',
        'atras'       => '<path d="m15 18-6-6 6-6"/>',
        'adelante'    => '<path d="m9 18 6-6-6-6"/>',
        'cerrar'      => '<path d="M6 6l12 12M18 6 6 18"/>',
        'salir'       => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l-5-5 5-5M5 12h11"/>',
        'tendencia'   => '<path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'pildora'     => '<path d="M10.5 20.5a5 5 0 0 1-7-7l7-7a5 5 0 0 1 7 7z"/><path d="m8.5 8.5 7 7"/>',
        'carpeta'     => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'imagen'      => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
        'tarjeta'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/>',
        'engrane'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'flecha_derecha' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        // Espacio de trabajo (notas, carpetas y bases de datos)
        'tabla'          => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 10v10"/>',
        'papelera'       => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'enlace'         => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'editar'         => '<path d="M12 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-6"/><path d="M17.5 3.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/>',
        'chevron'        => '<path d="m9 6 6 6-6 6"/>',
        'agarre'         => '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
        'casilla'        => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="m8 12 3 3 5-6"/>',
        'comillas'       => '<path d="M6 7v10M10 9h8M10 13h8M10 17h5"/>',
        'separador'      => '<path d="M4 12h16"/>',
        'plantilla'      => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M4 9h16M10 9v12"/>',
        'lista_numerada' => '<path d="M10 6h10M10 12h10M10 18h10M4 5h2v4M4 9h3M4 15h3l-3 4h3"/>',
        'titulo'         => '<path d="M6 4v16M18 4v16M6 12h12"/>',
        'vinetas'        => '<path d="M10 6h10M10 12h10M10 18h10"/><circle cx="5" cy="6" r="1"/><circle cx="5" cy="12" r="1"/><circle cx="5" cy="18" r="1"/>',
        'chat_nuevo'     => '<path d="M20 12a8 8 0 0 1-11.6 7.1L4 20l1-4.2A8 8 0 1 1 20 12z"/><path d="M12 9v6M9 12h6"/>',
        'doble_derecha'  => '<path d="m6 6 6 6-6 6M13 6l6 6-6 6"/>',
        'pdf'            => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M9 13h6M9 17h4"/>',
    ];

    $trazo = $trazos[$nombre] ?? $trazos['info'];
    $clases = trim('icono ' . $clase);

    return '<svg class="' . $clases . '" viewBox="0 0 24 24" aria-hidden="true">' . $trazo . '</svg>';
}

/**
 * Iconos rellenos en dos tonos de gris (referencia: glifos sólidos con detalles más claros
 * y un movimiento sutil al pasar el ratón). Clases: b = base, m = tono medio, d = detalle.
 * Las piezas con clase "a-…" se animan desde estilos.css (sección "Iconos rellenos").
 */
function iconoRelleno(string $nombre, string $clase = ''): string
{
    static $formas = [
        'documento' => '<path class="b" d="M7.5 2.5h6.7L19 7.3v11.2a3 3 0 0 1-3 3H8a3 3 0 0 1-3-3v-13a3 3 0 0 1 2.5-3z"/><path class="m" d="M14 2.6V6a1.6 1.6 0 0 0 1.6 1.6H19z"/><g class="a-lineas"><rect class="d" x="8" y="11" width="8" height="1.7" rx=".85"/><rect class="d" x="8" y="14.2" width="8" height="1.7" rx=".85"/><rect class="d" x="8" y="17.4" width="4.6" height="1.7" rx=".85"/></g>',
        'carpeta'   => '<path class="b" d="M2.5 6.5A2.5 2.5 0 0 1 5 4h3.7c.6 0 1.1.2 1.5.6L11.6 6H19a2.5 2.5 0 0 1 2.5 2.5V17A2.5 2.5 0 0 1 19 19.5H5A2.5 2.5 0 0 1 2.5 17z"/><path class="m a-tapa" d="M2.5 10.2A2.2 2.2 0 0 1 4.7 8h14.6a2.2 2.2 0 0 1 2.2 2.2V17a2.5 2.5 0 0 1-2.5 2.5H5A2.5 2.5 0 0 1 2.5 17z"/><rect class="d" x="5.5" y="11" width="5" height="1.6" rx=".8"/>',
        'tabla'     => '<rect class="b" x="2.5" y="4" width="19" height="16" rx="3"/><path class="m" d="M5.5 4h13a3 3 0 0 1 3 3v2.2h-19V7a3 3 0 0 1 3-3z"/><g class="a-lineas"><rect class="d" x="5" y="11.6" width="4.5" height="1.6" rx=".8"/><rect class="d" x="11" y="11.6" width="8" height="1.6" rx=".8"/><rect class="d" x="5" y="15.4" width="4.5" height="1.6" rx=".8"/><rect class="d" x="11" y="15.4" width="5.5" height="1.6" rx=".8"/></g>',
        'casa'      => '<path class="b" d="M10.4 3.2a2.5 2.5 0 0 1 3.2 0l6.5 5.5c.6.5.9 1.2.9 1.9V18a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 18v-7.4c0-.7.3-1.4.9-1.9z"/><path class="d a-puerta" d="M9.5 20.5V15a2.5 2.5 0 0 1 5 0v5.5z"/>',
        'mensaje'   => '<path class="b" d="M12 3.5c5 0 9 3.4 9 7.7s-4 7.7-9 7.7c-1 0-2-.1-2.9-.4L5 20.2l1.1-3.4C4.2 15.4 3 13.4 3 11.2 3 6.9 7 3.5 12 3.5z"/><g class="a-puntos"><circle class="d" cx="8.2" cy="11.2" r="1.3"/><circle class="d" cx="12" cy="11.2" r="1.3"/><circle class="d" cx="15.8" cy="11.2" r="1.3"/></g>',
        'usuarios'  => '<circle class="m" cx="16.5" cy="8" r="3"/><path class="m" d="M12.6 19.5a4.6 4.6 0 0 1 8.9-1.6c.3.8-.3 1.6-1.2 1.6z"/><g class="a-frente"><circle class="d" cx="9" cy="8" r="3.6"/><path class="b" d="M2.6 19.4a6.4 6.4 0 0 1 12.8 0c0 .6-.5 1.1-1.1 1.1H3.7c-.6 0-1.1-.5-1.1-1.1z"/></g>',
        // Esfera sólida con las manecillas recortadas (se ve el fondo): detalles semitransparentes encima de la base no se distinguían a 16 px
        'reloj'     => '<path class="b" fill-rule="evenodd" d="M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18zM11.1 6.8v6.1h1.8V6.8zM12.9 11.1v1.8h3.3v-1.8z"/>',
        'reabrir'   => '<circle class="b" cx="12" cy="12" r="9"/><g class="a-giro"><path class="d" d="M8.2 9.3a4.6 4.6 0 1 1-.6 4.4.85.85 0 0 1 1.6-.6 2.9 2.9 0 1 0 .5-2.6l1 .9c.5.5.2 1.4-.6 1.4H7.6a.9.9 0 0 1-.9-.9V9.4c0-.8.9-1.1 1.4-.6z"/></g>',
        'papelera'  => '<path class="b" d="M5.6 8h12.8l-.9 11a2.5 2.5 0 0 1-2.5 2.3H9a2.5 2.5 0 0 1-2.5-2.3z"/><g class="a-tapa-arriba"><rect class="d" x="3.5" y="5" width="17" height="2.2" rx="1.1"/><path class="m" d="M9 5V4a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 4v1z"/></g><rect class="m" x="9.2" y="11" width="1.6" height="7" rx=".8"/><rect class="m" x="13.2" y="11" width="1.6" height="7" rx=".8"/>',
        'buscar'    => '<circle class="b" cx="10.5" cy="10.5" r="7"/><circle class="m" cx="10.5" cy="10.5" r="4.2"/><g class="a-mango"><rect class="d" x="16" y="14.6" width="2.4" height="7" rx="1.2" transform="rotate(-45 17.2 18.1)"/></g>',
        'chispa'    => '<path class="d a-chispa" d="M11 3.5c.4 0 .7.3.8.7l.8 3.2a5 5 0 0 0 3.7 3.7l3.2.8a.8.8 0 0 1 0 1.6l-3.2.8a5 5 0 0 0-3.7 3.7l-.8 3.2a.8.8 0 0 1-1.6 0l-.8-3.2a5 5 0 0 0-3.7-3.7l-3.2-.8a.8.8 0 0 1 0-1.6l3.2-.8a5 5 0 0 0 3.7-3.7l.8-3.2c.1-.4.4-.7.8-.7z"/><path class="m a-chispa-chica" d="M18.5 2.5c.2 0 .4.1.4.3l.3 1.1c.2.6.6 1 1.2 1.2l1.1.3a.4.4 0 0 1 0 .8l-1.1.3c-.6.2-1 .6-1.2 1.2l-.3 1.1a.4.4 0 0 1-.8 0l-.3-1.1a1.7 1.7 0 0 0-1.2-1.2l-1.1-.3a.4.4 0 0 1 0-.8l1.1-.3c.6-.2 1-.6 1.2-1.2l.3-1.1c0-.2.2-.3.4-.3z"/>',
        'microfono' => '<rect class="d a-capsula" x="8.5" y="2.5" width="7" height="12" rx="3.5"/><path class="b" d="M5.2 11a.9.9 0 0 1 1.8 0 5 5 0 0 0 10 0 .9.9 0 0 1 1.8 0 6.8 6.8 0 0 1-5.9 6.7v2.1h2.4a.9.9 0 0 1 0 1.8H8.7a.9.9 0 0 1 0-1.8h2.4v-2.1A6.8 6.8 0 0 1 5.2 11z"/>',
        'plantilla' => '<rect class="b" x="3" y="4" width="18" height="16" rx="3"/><g class="a-lineas"><rect class="d" x="6" y="7.5" width="9" height="2" rx="1"/><rect class="m" x="6" y="11.2" width="6" height="2" rx="1"/><rect class="m" x="11" y="14.8" width="7" height="2" rx="1"/></g>',
        'editar'    => '<rect class="b" x="3" y="4" width="16" height="16" rx="3.5"/><path class="d a-lapiz" d="M18.4 2.9a2 2 0 0 1 2.8 2.8l-7.6 7.6c-.3.3-.6.5-1 .6l-2.6.7c-.5.1-.9-.3-.8-.8l.7-2.6c.1-.4.3-.7.6-1z"/>',
        'escudo'    => '<path class="b" d="M11.3 2.7a2 2 0 0 1 1.4 0l6 2.3c.8.3 1.3 1 1.3 1.9v4.6c0 4.6-3.3 8.4-7.6 9.7a1.4 1.4 0 0 1-.8 0C7.3 19.9 4 16.1 4 11.5V6.9c0-.9.5-1.6 1.3-1.9z"/><path class="d a-palomita" d="M10.9 14.6a1 1 0 0 1-1.4 0l-2-2a1 1 0 1 1 1.4-1.4l1.3 1.3 3.9-3.9a1 1 0 1 1 1.4 1.4z"/>',
        // Engrane sólido con el centro recortado, como el reloj
        'engrane'   => '<path class="b" fill-rule="evenodd" d="M10.6 2.5h2.8l.5 2.4c.6.2 1.2.5 1.7.9l2.3-.8 1.4 2.4-1.8 1.6c.1.7.1 1.3 0 2l1.8 1.6-1.4 2.4-2.3-.8c-.5.4-1.1.7-1.7.9l-.5 2.4h-2.8l-.5-2.4c-.6-.2-1.2-.5-1.7-.9l-2.3.8-1.4-2.4 1.8-1.6a6 6 0 0 1 0-2L4.7 7.4l1.4-2.4 2.3.8c.5-.4 1.1-.7 1.7-.9zM12 8.6a2.4 2.4 0 1 0 0 4.8 2.4 2.4 0 0 0 0-4.8z" transform="translate(0 1)"/>',
        'usuario'   => '<circle class="d a-frente" cx="12" cy="8" r="4.2"/><path class="b" d="M4 19.6a8 8 0 0 1 16 0c0 .5-.4.9-.9.9H4.9a.9.9 0 0 1-.9-.9z"/>',
        'lista'     => '<rect class="b" x="3" y="3.5" width="18" height="17" rx="3"/><g class="a-lineas"><circle class="d" cx="7.4" cy="8.5" r="1.2"/><rect class="d" x="10" y="7.7" width="7.5" height="1.6" rx=".8"/><circle class="d" cx="7.4" cy="12" r="1.2"/><rect class="m" x="10" y="11.2" width="7.5" height="1.6" rx=".8"/><circle class="d" cx="7.4" cy="15.5" r="1.2"/><rect class="m" x="10" y="14.7" width="5" height="1.6" rx=".8"/></g>',
    ];

    $forma = $formas[$nombre] ?? $formas['documento'];
    return '<svg class="' . trim('ir ir--' . $nombre . ' ' . $clase) . '" viewBox="0 0 24 24" aria-hidden="true">' . $forma . '</svg>';
}
