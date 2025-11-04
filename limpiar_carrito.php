<?php
session_start();
include 'config_hora.php';
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sesión expirada']);
    exit();
}

// Solo limpiar si no hay cuenta activa
if (!isset($_SESSION['cuenta_activa'])) {
    $_SESSION['carrito'] = array();
    echo json_encode(['success' => true, 'message' => 'Carrito limpiado']);
} else {
    echo json_encode(['success' => true, 'message' => 'Cuenta activa, carrito no limpiado']);
}
?>