<?php
/**
 * Catálogos pequeños que no cambian. Los catálogos grandes (CIE-10, CLUES,
 * municipios INEGI) irán en la base de datos.
 */

// Entidades federativas con la clave de dos letras que usa la CURP (RENAPO).
const ENTIDADES = [
    'AS' => 'Aguascalientes',      'BC' => 'Baja California',   'BS' => 'Baja California Sur',
    'CC' => 'Campeche',            'CL' => 'Coahuila',          'CM' => 'Colima',
    'CS' => 'Chiapas',             'CH' => 'Chihuahua',         'DF' => 'Ciudad de México',
    'DG' => 'Durango',             'GT' => 'Guanajuato',        'GR' => 'Guerrero',
    'HG' => 'Hidalgo',             'JC' => 'Jalisco',           'MC' => 'Estado de México',
    'MN' => 'Michoacán',           'MS' => 'Morelos',           'NT' => 'Nayarit',
    'NL' => 'Nuevo León',          'OC' => 'Oaxaca',            'PL' => 'Puebla',
    'QT' => 'Querétaro',           'QR' => 'Quintana Roo',      'SP' => 'San Luis Potosí',
    'SL' => 'Sinaloa',             'SR' => 'Sonora',            'TC' => 'Tabasco',
    'TS' => 'Tamaulipas',          'TL' => 'Tlaxcala',          'VZ' => 'Veracruz',
    'YN' => 'Yucatán',             'ZS' => 'Zacatecas',         'NE' => 'Nacido en el extranjero',
];

// Sexo según la CURP: H, M y X (no binario, RENAPO).
const SEXOS = [
    'H' => 'Hombre',
    'M' => 'Mujer',
    'X' => 'No binario',
];

const GRUPOS_SANGUINEOS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Desconocido'];
