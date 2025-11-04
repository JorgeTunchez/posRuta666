<?php
session_start();
include 'config_hora.php';
include 'conexion.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sesión expirada']);
    exit();
}

try {
    // Obtener cuentas activas - CORREGIDO para tu estructura
    $sql = "SELECT cp.id, cp.total, cp.created_at as fecha_creacion, 
                   c.nombre as cliente_nombre, c.telefono,
                   COUNT(cd.id) as total_productos
            FROM cuentas_pendientes cp
            LEFT JOIN clientes c ON cp.id_cliente = c.id
            LEFT JOIN cuenta_detalles cd ON cp.id = cd.id_cuenta
            WHERE cp.estado = 'activa'
            GROUP BY cp.id
            ORDER BY cp.created_at DESC";
    
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception('Error en consulta: ' . $conn->error);
    }
    
    $cuentas = [];
    
    while ($row = $result->fetch_assoc()) {
        $cuentas[] = [
            'id' => $row['id'],
            'cliente_nombre' => $row['cliente_nombre'] ?: 'Cliente General',
            'total' => floatval($row['total']),
            'total_productos' => intval($row['total_productos']),
            'fecha_creacion' => date('H:i', strtotime($row['fecha_creacion']))
        ];
    }
    
    echo json_encode(['success' => true, 'cuentas' => $cuentas]);
    
} catch (Exception $e) {
    error_log("Error en obtener_cuentas_activas.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?>