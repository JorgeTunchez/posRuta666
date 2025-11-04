<?php
// Función para formatear moneda (PHP)
function formato_moneda($monto) {
    return 'Q' . number_format($monto, 2);
}

// Otras funciones que puedas necesitar
function obtener_ventas_hoy($conn) {
    $sql = "SELECT SUM(total) as total FROM ventas WHERE DATE(created_at) = CURDATE()";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['total'] ? $row['total'] : 0;
    }
    return 0;
}

function obtener_stock_bajo($conn) {
    $sql = "SELECT COUNT(*) as count FROM productos WHERE stock <= stock_minimo";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['count'];
    }
    return 0;
}

function obtener_clientes_nuevos($conn) {
    $sql = "SELECT COUNT(*) as count FROM clientes WHERE DATE(created_at) = CURDATE()";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['count'];
    }
    return 0;
}
/**
 * Verificar si un combo tiene stock suficiente
 */
function verificarStockCombo($conn, $combo_id, $cantidad = 1) {
    $sql = "SELECT cp.id_producto, cp.cantidad as cantidad_combo, p.nombre, p.stock 
           FROM combo_productos cp 
           JOIN productos p ON cp.id_producto = p.id 
           WHERE cp.id_combo = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $combo_id);
    $stmt->execute();
    $productos = $stmt->get_result();
    
    $stock_suficiente = true;
    $productos_sin_stock = [];
    
    while ($producto = $productos->fetch_assoc()) {
        $cantidad_necesaria = $producto['cantidad_combo'] * $cantidad;
        if ($producto['stock'] < $cantidad_necesaria) {
            $stock_suficiente = false;
            $productos_sin_stock[] = [
                'nombre' => $producto['nombre'],
                'necesario' => $cantidad_necesaria,
                'disponible' => $producto['stock']
            ];
        }
    }
    
    return [
        'suficiente' => $stock_suficiente,
        'productos_sin_stock' => $productos_sin_stock
    ];
}

/**
 * Obtener combos disponibles para un día específico
 */
function getCombosDelDia($conn, $dia_semana) {
    $sql = "SELECT c.*, 
           GROUP_CONCAT(CONCAT(cp.cantidad, 'x', p.nombre)) as productos_info
           FROM combos c
           JOIN combo_dias cd ON c.id = cd.id_combo
           JOIN combo_productos cp ON c.id = cp.id_combo
           JOIN productos p ON cp.id_producto = p.id
           WHERE c.activo = 1 
           AND cd.dia_semana = ?
           AND cd.activo = 1
           GROUP BY c.id
           ORDER BY c.nombre";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $dia_semana);
    $stmt->execute();
    return $stmt->get_result();
}
?>