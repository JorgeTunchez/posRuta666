<?php
// Limpiar cualquier output anterior
while (ob_get_level()) ob_end_clean();

// Configurar headers primero
header('Content-Type: application/json; charset=utf-8');

// Desactivar visualización de errores para el cliente
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Log de errores en archivo
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

session_start();

// Verificar sesión primero
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'La sesión ha expirado. Por favor, recarga la página.'
    ]);
    exit();
}

// Incluir archivos necesarios
try {
    include 'conexion.php';
    include 'funciones.php';
    include 'config_hora.php'; // Incluir configuración de hora
} catch (Exception $e) {
    error_log("Error al incluir archivos: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al cargar componentes del sistema'
    ]);
    exit();
}

// Verificar que es una solicitud POST válida
if ($_SERVER['REQUEST_METHOD'] != 'POST' || !isset($_POST['agregar_producto'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'La solicitud no es válida'
    ]);
    exit();
}

// Validar datos requeridos
if (!isset($_POST['producto_id']) || !isset($_POST['cantidad'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Faltan datos requeridos: producto_id o cantidad'
    ]);
    exit();
}

try {
    $producto_id = intval($_POST['producto_id']);
    $cantidad = intval($_POST['cantidad']);
    $cuenta_id = isset($_POST['cuenta_id']) ? intval($_POST['cuenta_id']) : null;
    
    error_log("=== AGREGAR PRODUCTO ===");
    error_log("Producto ID: $producto_id, Cantidad: $cantidad, Cuenta ID: " . ($cuenta_id ?: 'null'));
    
    // Validar valores
    if ($producto_id <= 0 || $cantidad <= 0) {
        throw new Exception('Datos inválidos: ID o cantidad incorrectos');
    }
    
    // Determinar si usar precio nocturno (misma lógica que en ventas.php)
    $hora_actual = date('H');
    $minuto_actual = date('i');
    $usar_precio_after = false;
    
    if (($hora_actual == 0 && $minuto_actual >= 30) || 
        ($hora_actual >= 1 && $hora_actual <= 5) || 
        ($hora_actual == 6 && $minuto_actual == 0)) {
        $usar_precio_after = true;
    }
    
    error_log("Hora actual: $hora_actual:$minuto_actual, Usar precio after: " . ($usar_precio_after ? 'SÍ' : 'NO'));
    
    // Verificar stock y obtener información del producto CON EL PRECIO CORRECTO
    $sql_producto = "SELECT *, 
                    CASE 
                        WHEN ? = 1 AND precio_after > 0 THEN precio_after 
                        ELSE precio_venta 
                    END as precio_correcto
                    FROM productos 
                    WHERE id = ? AND stock >= ?";
    
    $stmt = $conn->prepare($sql_producto);
    $usar_precio_int = $usar_precio_after ? 1 : 0;
    $stmt->bind_param("iii", $usar_precio_int, $producto_id, $cantidad);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $producto = $result->fetch_assoc();
        
        // USAR EL PRECIO CALCULADO POR LA BASE DE DATOS
        $precio_unitario = floatval($producto['precio_correcto']);
        $subtotal = $precio_unitario * $cantidad;
        
        error_log("Producto: " . $producto['nombre']);
        error_log("Precio usado: $precio_unitario (Precio venta: " . $producto['precio_venta'] . ", Precio after: " . $producto['precio_after'] . ")");
        error_log("Subtotal: $subtotal");
        
        if ($cuenta_id) {
            error_log("Agregando a cuenta ID: $cuenta_id");
            
            // Verificar que la cuenta existe y está activa
            $sql_verificar_cuenta = "SELECT id FROM cuentas_pendientes WHERE id = ? AND estado = 'activa'";
            $stmt_verificar = $conn->prepare($sql_verificar_cuenta);
            $stmt_verificar->bind_param("i", $cuenta_id);
            $stmt_verificar->execute();
            $result_verificar = $stmt_verificar->get_result();
            
            if ($result_verificar->num_rows === 0) {
                throw new Exception('La cuenta no existe o no está activa');
            }
            
            // Agregar a cuenta en base de datos
            $sql_agregar = "INSERT INTO cuenta_detalles (id_cuenta, id_producto, cantidad, precio_unitario, subtotal) 
                        VALUES (?, ?, ?, ?, ?)";
            $stmt_agregar = $conn->prepare($sql_agregar);
            $stmt_agregar->bind_param("iiidd", $cuenta_id, $producto_id, $cantidad, $precio_unitario, $subtotal);
            
            if ($stmt_agregar->execute()) {
                // Actualizar total de la cuenta
                $sql_actualizar_total = "UPDATE cuentas_pendientes SET total = total + ? WHERE id = ?";
                $stmt_actualizar = $conn->prepare($sql_actualizar_total);
                $stmt_actualizar->bind_param("di", $subtotal, $cuenta_id);
                $stmt_actualizar->execute();
                
                error_log("✓ Producto agregado a cuenta exitosamente");
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Producto agregado a la cuenta correctamente',
                    'tipo' => 'cuenta',
                    'cuenta_id' => $cuenta_id,
                    'producto_nombre' => $producto['nombre'],
                    'precio' => $precio_unitario,
                    'cantidad' => $cantidad,
                    'subtotal' => $subtotal
                ]);
                exit();
            } else {
                throw new Exception('Error al agregar producto a la cuenta: ' . $conn->error);
            }
        } else {
            error_log("Agregando al carrito de sesión");
            
            // Agregar al carrito normal (sesión)
            if (!isset($_SESSION['carrito'])) {
                $_SESSION['carrito'] = array();
            }
            
            $encontrado = false;
            foreach ($_SESSION['carrito'] as &$item) {
                if ($item['id'] == $producto_id) {
                    $item['cantidad'] += $cantidad;
                    $item['subtotal'] = $item['cantidad'] * $precio_unitario;
                    $encontrado = true;
                    break;
                }
            }
            
            if (!$encontrado) {
                $_SESSION['carrito'][] = array(
                    'id' => $producto_id,
                    'nombre' => $producto['nombre'],
                    'precio' => $precio_unitario,
                    'cantidad' => $cantidad,
                    'subtotal' => $subtotal
                );
            }
            
            error_log("✓ Producto agregado al carrito exitosamente");
            error_log("Carrito actual: " . count($_SESSION['carrito']) . " productos");
            
            echo json_encode([
                'success' => true,
                'message' => 'Producto agregado al carrito correctamente',
                'tipo' => 'carrito',
                'producto_nombre' => $producto['nombre'],
                'precio' => $precio_unitario,
                'cantidad' => $cantidad,
                'subtotal' => $subtotal,
                'total_carrito' => count($_SESSION['carrito'])
            ]);
            exit();
        }
    } else {
        error_log("✗ Stock insuficiente o producto no encontrado - ID: $producto_id");
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No hay suficiente stock del producto seleccionado o el producto no existe'
        ]);
        exit();
    }
    
} catch (Exception $e) {
    error_log("✗ Error en agregar_producto.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ocurrió un error inesperado: ' . $e->getMessage()
    ]);
    exit();
}

// Cerrar conexión
if (isset($conn)) {
    $conn->close();
}
?>