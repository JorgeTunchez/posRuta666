<?php
session_start();
include 'config_hora.php';
include 'conexion.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

$cuenta_id = isset($_GET['cuenta_id']) ? intval($_GET['cuenta_id']) : 0;

if ($cuenta_id > 0) {
    $sql = "SELECT cp.id_cliente, c.nombre as cliente_nombre 
            FROM cuentas_pendientes cp 
            LEFT JOIN clientes c ON cp.id_cliente = c.id 
            WHERE cp.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $cuenta_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $cuenta = $result->fetch_assoc();
        echo json_encode([
            'success' => true,
            'cliente_id' => $cuenta['id_cliente'],
            'cliente_nombre' => $cuenta['cliente_nombre'] ?: 'Cliente General'
        ]);
    } else {
        echo json_encode(['success' => false]);
    }
} else {
    echo json_encode(['success' => false]);
}

$conn->close();
?>