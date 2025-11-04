<?php
session_start();
include 'conexion.php';

// En tu código principal, después de la sección de procesar venta de combo individual
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['agregar_combo_carrito'])) {
    $combo_id = intval($_POST['combo_id']);
    $cantidad = intval($_POST['cantidad']);
    $cuenta_id = isset($_POST['cuenta_id']) ? intval($_POST['cuenta_id']) : null;
    
    // Obtener información del combo
    $sql_combo = "SELECT c.* FROM combos c 
                 JOIN combo_dias cd ON c.id = cd.id_combo 
                 WHERE c.id = ? AND cd.dia_semana = ? AND c.activo = 1";
    $stmt_combo = $conn->prepare($sql_combo);
    $stmt_combo->bind_param("is", $combo_id, $dia_actual);
    $stmt_combo->execute();
    $combo = $stmt_combo->get_result()->fetch_assoc();
    
    if (!$combo) {
        echo json_encode(['success' => false, 'message' => 'El combo no está disponible hoy']);
        exit();
    }
    
    // Verificar stock de productos del combo
    $sql_productos_combo = "SELECT cp.id_producto, cp.cantidad as cantidad_combo, p.nombre, p.stock 
                           FROM combo_productos cp 
                           JOIN productos p ON cp.id_producto = p.id 
                           WHERE cp.id_combo = ?";
    $stmt_productos = $conn->prepare($sql_productos_combo);
    $stmt_productos->bind_param("i", $combo_id);
    $stmt_productos->execute();
    $productos_combo = $stmt_productos->get_result();
    
    $stock_suficiente = true;
    $productos_sin_stock = [];
    
    while ($producto = $productos_combo->fetch_assoc()) {
        $cantidad_necesaria = $producto['cantidad_combo'] * $cantidad;
        if ($producto['stock'] < $cantidad_necesaria) {
            $stock_suficiente = false;
            $productos_sin_stock[] = $producto['nombre'] . " (necesario: $cantidad_necesaria, disponible: {$producto['stock']})";
        }
    }
    
    if (!$stock_suficiente) {
        echo json_encode(['success' => false, 'message' => "Stock insuficiente: " . implode(", ", $productos_sin_stock)]);
        exit();
    }
    
    // Calcular precio según hora
    $precio_final = $usar_precio_after ? 
        (($combo['precio_after'] && $combo['precio_after'] > 0) ? $combo['precio_after'] : $combo['precio_venta']) : 
        $combo['precio_venta'];
    
    $subtotal = $precio_final * $cantidad;
    
    if ($cuenta_id) {
        // Agregar a cuenta en base de datos
        $sql_agregar = "INSERT INTO cuenta_detalles (id_cuenta, id_producto, cantidad, precio_unitario, subtotal, tipo_item, combo_id) 
                       VALUES (?, NULL, ?, ?, ?, 'combo', ?)";
        $stmt_agregar = $conn->prepare($sql_agregar);
        $stmt_agregar->bind_param("iiddi", $cuenta_id, $cantidad, $precio_final, $subtotal, $combo_id);
        
        if ($stmt_agregar->execute()) {
            // Actualizar total de la cuenta
            $sql_actualizar_total = "UPDATE cuentas_pendientes SET total = total + ? WHERE id = ?";
            $stmt_actualizar = $conn->prepare($sql_actualizar_total);
            $stmt_actualizar->bind_param("di", $subtotal, $cuenta_id);
            $stmt_actualizar->execute();
            
            echo json_encode(['success' => true, 'message' => 'Combo agregado a la cuenta correctamente']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al agregar combo a la cuenta']);
        }
    } else {
        // Agregar al carrito de sesión
        $item_combo = [
            'tipo' => 'combo',
            'combo_id' => $combo_id,
            'nombre' => $combo['nombre'] . ' (Combo)',
            'precio' => $precio_final,
            'cantidad' => $cantidad,
            'subtotal' => $subtotal
        ];
        
        $_SESSION['carrito'][] = $item_combo;
        echo json_encode(['success' => true, 'message' => 'Combo agregado al carrito correctamente']);
    }
    exit();
}
?>