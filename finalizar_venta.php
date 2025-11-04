<?php
session_start();
include 'config_hora.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sesión expirada']);
    exit();
}

include 'conexion.php';
include 'funciones.php';

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}
// Procesar cliente temporal si existe
if (isset($_POST['cliente_temporal'])) {
    $clienteTemp = json_decode($_POST['cliente_temporal'], true);
    
    // Insertar nuevo cliente
    $sql_cliente = "INSERT INTO clientes (nombre, telefono, email, puntos, visitas, created_at) 
                   VALUES (?, ?, ?, 0, 0, NOW())";
    $stmt_cliente = $conn->prepare($sql_cliente);
    $stmt_cliente->bind_param("sss", $clienteTemp['nombre'], $clienteTemp['telefono'], $clienteTemp['email']);
    
    if ($stmt_cliente->execute()) {
        $cliente_id = $stmt_cliente->insert_id;
        $_POST['cliente_id'] = $cliente_id;
    }
}
try {
    // Obtener datos del formulario
    $cliente_id = isset($_POST['cliente_id']) && !empty($_POST['cliente_id']) ? intval($_POST['cliente_id']) : NULL;
    $metodo_pago = $_POST['metodo_pago'] ?? 'efectivo';
    $descuento = isset($_POST['descuento']) ? floatval($_POST['descuento']) : 0;
    $cuenta_id = isset($_POST['cuenta_id']) && !empty($_POST['cuenta_id']) ? intval($_POST['cuenta_id']) : null;
    
    error_log("=== INICIANDO VENTA ===");
    error_log("Cliente ID: " . ($cliente_id ?: 'Ninguno'));
    error_log("Método pago: $metodo_pago");
    error_log("Descuento: $descuento");
    error_log("Cuenta ID: " . ($cuenta_id ?: 'Ninguna'));
    
    // Obtener productos del carrito o de la cuenta
    $carrito = array();
    
    if ($cuenta_id) {
        // Obtener productos de la cuenta desde BD
        error_log("Obteniendo productos de cuenta ID: $cuenta_id");
        $sql_cuenta = "SELECT cd.*, p.nombre as producto_nombre, p.stock 
                      FROM cuenta_detalles cd 
                      JOIN productos p ON cd.id_producto = p.id 
                      WHERE cd.id_cuenta = ?";
        $stmt_cuenta = $conn->prepare($sql_cuenta);
        $stmt_cuenta->bind_param("i", $cuenta_id);
        $stmt_cuenta->execute();
        $result_cuenta = $stmt_cuenta->get_result();
        
        while ($item = $result_cuenta->fetch_assoc()) {
            $carrito[] = array(
                'id' => $item['id_producto'],
                'nombre' => $item['producto_nombre'],
                'precio' => $item['precio_unitario'],
                'cantidad' => $item['cantidad'],
                'subtotal' => $item['subtotal'],
                'stock' => $item['stock']
            );
            error_log("Producto cuenta: {$item['producto_nombre']} - Cantidad: {$item['cantidad']}");
        }
    } else {
        // Obtener productos del carrito de sesión
        error_log("Obteniendo productos del carrito de sesión");
        if (isset($_SESSION['carrito']) && !empty($_SESSION['carrito'])) {
            foreach ($_SESSION['carrito'] as $item) {
                // Verificar stock actual para cada producto
                $sql_stock = "SELECT nombre, stock FROM productos WHERE id = ?";
                $stmt_stock = $conn->prepare($sql_stock);
                $stmt_stock->bind_param("i", $item['id']);
                $stmt_stock->execute();
                $result_stock = $stmt_stock->get_result();
                
                if ($result_stock->num_rows === 0) {
                    throw new Exception("Producto ID {$item['id']} no encontrado en la base de datos");
                }
                
                $producto_stock = $result_stock->fetch_assoc();
                
                $carrito[] = array(
                    'id' => $item['id'],
                    'nombre' => $item['nombre'],
                    'precio' => $item['precio'],
                    'cantidad' => $item['cantidad'],
                    'subtotal' => $item['subtotal'],
                    'stock' => $producto_stock['stock']
                );
                error_log("Producto carrito: {$item['nombre']} - Cantidad: {$item['cantidad']} - Stock: {$producto_stock['stock']}");
            }
        }
    }
    
    // Verificar que hay productos
    if (empty($carrito)) {
        error_log("ERROR: Carrito vacío");
        echo json_encode(['success' => false, 'message' => 'No hay productos en el carrito']);
        exit();
    }
    
    error_log("Total productos en carrito: " . count($carrito));
    
    // Verificar stock antes de procesar
    foreach ($carrito as $item) {
        if ($item['cantidad'] > $item['stock']) {
            $error_msg = "Stock insuficiente para: {$item['nombre']} (Stock: {$item['stock']}, Solicitado: {$item['cantidad']})";
            error_log("ERROR: $error_msg");
            echo json_encode(['success' => false, 'message' => $error_msg]);
            exit();
        }
    }
    
    // Calcular subtotal
    $subtotal = 0;
    foreach ($carrito as $item) {
        $subtotal += $item['subtotal'];
    }
    
    // Validar descuento
    if ($descuento > $subtotal) {
        $descuento = $subtotal;
    }
    
    // Calcular impuestos
    $impuestos = 0;
    $base_imponible = $subtotal - $descuento;
    if ($metodo_pago == 'tarjeta' && $base_imponible < 100 && $base_imponible > 0) {
        $impuestos = $base_imponible * 0.10;
    }
    
    $total = $base_imponible + $impuestos;
    
    error_log("Cálculos - Subtotal: $subtotal, Descuento: $descuento, Impuestos: $impuestos, Total: $total");
    
    // Iniciar transacción para asegurar consistencia
    $conn->begin_transaction();
    error_log("Transacción iniciada");
    
    try {
        // Insertar venta
        $sql_venta = "INSERT INTO ventas (id_cliente, id_empleado, total, descuento, impuestos, metodo_pago, estado) 
                     VALUES (?, ?, ?, ?, ?, ?, 'completada')";
        $stmt = $conn->prepare($sql_venta);
        $stmt->bind_param("iiddds", $cliente_id, $_SESSION['user_id'], $total, $descuento, $impuestos, $metodo_pago);
        
        if (!$stmt->execute()) {
            throw new Exception('Error al registrar la venta: ' . $conn->error);
        }
        
        $venta_id = $stmt->insert_id;
        error_log("✓ Venta insertada con ID: $venta_id");
        
        // Insertar detalles de venta y actualizar stock
        foreach ($carrito as $item) {
            $producto_id = $item['id'];
            $cantidad = $item['cantidad'];
            $precio = $item['precio'];
            $subtotal_item = $item['subtotal'];
            
            error_log("Procesando producto ID: $producto_id, Nombre: {$item['nombre']}, Cantidad: $cantidad");
            
            // Insertar detalle de venta
            $sql_detalle = "INSERT INTO venta_detalles (id_venta, id_producto, cantidad, precio_unitario, subtotal) 
                           VALUES (?, ?, ?, ?, ?)";
            $stmt_detalle = $conn->prepare($sql_detalle);
            $stmt_detalle->bind_param("iiidd", $venta_id, $producto_id, $cantidad, $precio, $subtotal_item);
            
            if (!$stmt_detalle->execute()) {
                throw new Exception('Error al insertar detalle de venta para producto ID ' . $producto_id . ': ' . $conn->error);
            }
            
            error_log("✓ Detalle de venta insertado para producto ID: $producto_id");
            
            // ACTUALIZAR STOCK - Versión robusta
            $sql_update_stock = "UPDATE productos SET stock = stock - ? WHERE id = ?";
            $stmt_stock = $conn->prepare($sql_update_stock);
            
            if (!$stmt_stock) {
                throw new Exception('Error preparando consulta de stock: ' . $conn->error);
            }
            
            $stmt_stock->bind_param("ii", $cantidad, $producto_id);
            
            if (!$stmt_stock->execute()) {
                throw new Exception('Error al actualizar stock del producto ID ' . $producto_id . ': ' . $conn->error);
            }
            
            // Verificar cuántas filas fueron afectadas
            $filas_afectadas = $stmt_stock->affected_rows;
            error_log("Filas afectadas en actualización de stock: $filas_afectadas");
            
            if ($filas_afectadas === 0) {
                // Verificar si el producto existe
                $sql_verificar = "SELECT nombre FROM productos WHERE id = ?";
                $stmt_verificar = $conn->prepare($sql_verificar);
                $stmt_verificar->bind_param("i", $producto_id);
                $stmt_verificar->execute();
                $result_verificar = $stmt_verificar->get_result();
                
                if ($result_verificar->num_rows > 0) {
                    $producto = $result_verificar->fetch_assoc();
                    throw new Exception('No se pudo actualizar el stock del producto: ' . $producto['nombre']);
                } else {
                    throw new Exception('Producto ID ' . $producto_id . ' no encontrado para actualizar stock');
                }
            }
            
            error_log("✓ Stock actualizado para producto ID: $producto_id - Se restaron $cantidad unidades");
            
            // Cerrar statements
            $stmt_stock->close();
            $stmt_detalle->close();
        }
        
        // Si era una cuenta, marcarla como pagada
        if ($cuenta_id) {
            $sql_actualizar_cuenta = "UPDATE cuentas_pendientes SET estado = 'pagada' WHERE id = ?";
            $stmt_cuenta = $conn->prepare($sql_actualizar_cuenta);
            $stmt_cuenta->bind_param("i", $cuenta_id);
            
            if (!$stmt_cuenta->execute()) {
                throw new Exception('Error al actualizar cuenta: ' . $conn->error);
            }
            
            error_log("✓ Cuenta ID $cuenta_id marcada como pagada");
        } else {
            // Limpiar carrito de sesión solo si no es cuenta
            $_SESSION['carrito'] = array();
            error_log("✓ Carrito de sesión limpiado");
        }
        
        // Actualizar puntos del cliente si existe
        if ($cliente_id) {
            $puntos = floor($total / 10); // 1 punto por cada Q10
            $sql_puntos = "UPDATE clientes SET puntos = puntos + ?, visitas = visitas + 1, ultima_visita = CURDATE() WHERE id = ?";
            $stmt_puntos = $conn->prepare($sql_puntos);
            $stmt_puntos->bind_param("ii", $puntos, $cliente_id);
            $stmt_puntos->execute(); // No crítico si falla
            error_log("✓ Puntos actualizados para cliente ID: $cliente_id - Puntos agregados: $puntos");
        }
        
        // Confirmar transacción
        $conn->commit();
        error_log("✓ Transacción confirmada");
        
        // Verificación final de stock
        error_log("=== VERIFICACIÓN FINAL DE STOCK ===");
        foreach ($carrito as $item) {
            $sql_verificar_final = "SELECT nombre, stock FROM productos WHERE id = ?";
            $stmt_verificar_final = $conn->prepare($sql_verificar_final);
            $stmt_verificar_final->bind_param("i", $item['id']);
            $stmt_verificar_final->execute();
            $result_verificar_final = $stmt_verificar_final->get_result();
            $producto_final = $result_verificar_final->fetch_assoc();
            
            error_log("Producto: {$producto_final['nombre']} - Stock final: {$producto_final['stock']}");
            $stmt_verificar_final->close();
        }
        
        error_log("✓ Venta #$venta_id procesada exitosamente. Productos vendidos: " . count($carrito));
        
        // Éxito
        echo json_encode([
            'success' => true,
            'message' => 'Venta registrada exitosamente',
            'venta_id' => $venta_id,
            'total' => $total,
            'subtotal' => $subtotal,
            'descuento' => $descuento,
            'impuestos' => $impuestos,
            'productos' => count($carrito)
        ]);
        
    } catch (Exception $e) {
        // Revertir transacción en caso de error
        $conn->rollback();
        error_log("✗ ERROR - Transacción revertida: " . $e->getMessage());
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("✗ ERROR FINAL: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

if (isset($conn)) {
    $conn->close();
    error_log("=== FIN DE PROCESO ===");
}
?>