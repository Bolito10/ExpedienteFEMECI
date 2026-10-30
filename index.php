<?php
require_once __DIR__ . '/app/nucleo/inicio.php';

// Todo el expediente parte de la sección de Pacientes (o del portal, si la cuenta es de un paciente).
header('Location: ' . URL_BASE . rutaInicio());
exit;
