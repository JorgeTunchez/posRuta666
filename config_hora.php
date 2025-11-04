<?php
// Configurar zona horaria de Guatemala
date_default_timezone_set('America/Guatemala');

// Función para obtener fecha y hora actual de Guatemala
function obtenerHoraGuatemala($formato = 'Y-m-d H:i:s') {
    return date($formato);
}

// Función para obtener solo la fecha
function obtenerFechaGuatemala() {
    return date('Y-m-d');
}

// Función para obtener fecha formateada
function obtenerFechaFormateada() {
    return date('d/m/Y');
}
?>