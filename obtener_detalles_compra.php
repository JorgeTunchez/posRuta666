<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

if (isset($_GET['id'])) {
    $compra_id = intval($_GET['id']);
    
    // Obtener información de la compra
    $sql_compra = "SELECT c.*, p.nombre as proveedor_nombre 
                   FROM compras c 
                   LEFT JOIN proveedores p ON c.id_proveedor = p.id 
                   WHERE c.id = ?";
    $stmt_compra = $conn->prepare($sql_compra);
    $stmt_compra->bind_param("i", $compra_id);
    $stmt_compra->execute();
    $result_compra = $stmt_compra->get_result();
    $compra = $result_compra->fetch_assoc();
    
    // Obtener detalles de la compra
    $sql_detalles = "SELECT cd.*, 
                            pr.nombre as nombre_actual,
                            CASE 
                                WHEN pr.precio_venta > 0 THEN 1 
                                ELSE 0 
                            END as es_para_venta
                     FROM compra_detalles cd 
                     LEFT JOIN productos pr ON cd.id_producto = pr.id 
                     WHERE cd.id_compra = ?";
    $stmt_detalles = $conn->prepare($sql_detalles);
    $stmt_detalles->bind_param("i", $compra_id);
    $stmt_detalles->execute();
    $result_detalles = $stmt_detalles->get_result();
    $detalles = [];
    
    while ($detalle = $result_detalles->fetch_assoc()) {
        // Usar el nombre guardado en compra_detalles, o el nombre actual si no existe
        $detalle['nombre_producto'] = !empty($detalle['nombre_producto']) ? $detalle['nombre_producto'] : $detalle['nombre_actual'];
        $detalles[] = $detalle;
    }
    
    if ($compra) {
        echo json_encode([
            'success' => true,
            'compra' => $compra,
            'detalles' => $detalles
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Compra no encontrada']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'ID no proporcionado']);
}
?>