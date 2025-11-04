<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

include 'conexion.php';
include 'config_hora.php';

// Todos los usuarios pueden editar compras
$puede_editar = true;

// Obtener saldo actual de caja chica
function obtenerSaldoCajaChica($conn) {
    $sql_totales = "SELECT 
        COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) as total_ingresos,
        COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN monto ELSE 0 END), 0) as total_egresos,
        COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) - 
        COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN monto ELSE 0 END), 0) as saldo_actual
        FROM caja_chica";
    $result_totales = $conn->query($sql_totales);
    if ($result_totales && $result_totales->num_rows > 0) {
        $totales = $result_totales->fetch_assoc();
        return floatval($totales['saldo_actual']);
    }
    return 0;
}

$saldo_actual = obtenerSaldoCajaChica($conn);

// Obtener ID de categoría para enseres
function obtenerCategoriaEnseres($conn) {
    // Primero, verificar si existe alguna categoría
    $sql_categorias = "SELECT id, nombre FROM categorias LIMIT 1";
    $result = $conn->query($sql_categorias);
    
    if ($result && $result->num_rows > 0) {
        $categoria = $result->fetch_assoc();
        return $categoria['id']; // Usar la primera categoría disponible
    }
    
    // Si no hay categorías, crear una por defecto
    $sql_insert = "INSERT INTO categorias (nombre, descripcion) VALUES ('General', 'Categoría por defecto')";
    if ($conn->query($sql_insert)) {
        return $conn->insert_id;
    }
    
    // Si todo falla, retornar 1 como último recurso
    return 1;
}

$categoria_default_id = obtenerCategoriaEnseres($conn);

// Procesar operaciones
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Registrar nueva compra
    if (isset($_POST['registrar_compra'])) {
        $id_proveedor = intval($_POST['id_proveedor']);
        $total = floatval($_POST['total']);
        $fecha_compra = $_POST['fecha_compra'];
        $productos = json_decode($_POST['productos_compra'], true);
        
        // Verificar saldo suficiente en caja chica
        if ($total > $saldo_actual) {
            $mensaje_error = "Fondos insuficientes en caja chica. Saldo actual: Q" . number_format($saldo_actual, 2) . ". Total compra: Q" . number_format($total, 2);
        } else {
            // Iniciar transacción
            $conn->begin_transaction();
            
            try {
                // Insertar compra con estado 'recibida'
                $sql_compra = "INSERT INTO compras (id_proveedor, total, fecha_compra, estado) 
                              VALUES (?, ?, ?, 'recibida')";
                $stmt_compra = $conn->prepare($sql_compra);
                $stmt_compra->bind_param("ids", $id_proveedor, $total, $fecha_compra);
                $stmt_compra->execute();
                $compra_id = $stmt_compra->insert_id;
                
                // Insertar detalles de compra y actualizar productos
                foreach ($productos as $producto) {
                    $id_producto = intval($producto['id']);
                    $cantidad = intval($producto['cantidad']);
                    $precio_unitario = floatval($producto['precio_unitario']);
                    $subtotal = floatval($producto['subtotal']);
                    $es_para_venta = $producto['es_para_venta'];
                    $nombre_producto = $producto['nombre'];
                    $tipo = $producto['tipo'];
                    
                    // Si es un producto nuevo (id = 0), crear un registro en productos
                    if ($id_producto == 0) {
                        if ($es_para_venta) {
                            // Es un producto para venta
                            $sql_nuevo_producto = "INSERT INTO productos (nombre, precio_compra, precio_venta, stock, stock_minimo, id_categoria) 
                                                  VALUES (?, ?, ?, ?, 5, ?)";
                            $precio_venta = $precio_unitario * 1.3; // 30% de margen
                            $stmt_producto = $conn->prepare($sql_nuevo_producto);
                            $stmt_producto->bind_param("sddii", $nombre_producto, $precio_unitario, $precio_venta, $cantidad, $categoria_default_id);
                        } else {
                            // Es un enser (no para venta)
                            $sql_nuevo_producto = "INSERT INTO productos (nombre, precio_compra, precio_venta, stock, stock_minimo, id_categoria) 
                                                  VALUES (?, ?, 0, 0, 0, ?)";
                            $stmt_producto = $conn->prepare($sql_nuevo_producto);
                            $stmt_producto->bind_param("sdi", $nombre_producto, $precio_unitario, $categoria_default_id);
                        }
                        
                        if ($stmt_producto->execute()) {
                            $id_producto = $stmt_producto->insert_id;
                        } else {
                            throw new Exception("Error al crear producto: " . $stmt_producto->error);
                        }
                    }
                    
                    // Insertar detalle de compra CON EL NOMBRE
                    $sql_detalle = "INSERT INTO compra_detalles (id_compra, id_producto, nombre_producto, cantidad, precio_unitario, subtotal) 
                                   VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt_detalle = $conn->prepare($sql_detalle);
                    $stmt_detalle->bind_param("iisidd", $compra_id, $id_producto, $nombre_producto, $cantidad, $precio_unitario, $subtotal);
                    
                    if (!$stmt_detalle->execute()) {
                        throw new Exception("Error al insertar detalle: " . $stmt_detalle->error);
                    }
                    
                    // Actualizar stock solo si es un producto para venta y ya existe en productos (no es nuevo)
                    if ($es_para_venta && $id_producto > 0 && $tipo === 'existente') {
                        $sql_update_stock = "UPDATE productos SET stock = stock + ?, precio_compra = ? WHERE id = ?";
                        $stmt_stock = $conn->prepare($sql_update_stock);
                        $stmt_stock->bind_param("idi", $cantidad, $precio_unitario, $id_producto);
                        
                        if (!$stmt_stock->execute()) {
                            throw new Exception("Error al actualizar stock: " . $stmt_stock->error);
                        }
                    }
                }
                
                // Registrar egreso en caja chica
                $sql_caja = "INSERT INTO caja_chica (tipo, monto, descripcion, id_empleado) 
                            VALUES ('egreso', ?, 'Compra #$compra_id', ?)";
                $stmt_caja = $conn->prepare($sql_caja);
                $stmt_caja->bind_param("di", $total, $_SESSION['user_id']);
                
                if (!$stmt_caja->execute()) {
                    throw new Exception("Error al registrar en caja chica: " . $stmt_caja->error);
                }
                
                // Confirmar transacción
                $conn->commit();
                
                $mensaje_exito = "Compra registrada exitosamente. N° de compra: $compra_id";
                $saldo_actual = obtenerSaldoCajaChica($conn);
                
            } catch (Exception $e) {
                // Revertir transacción en caso de error
                $conn->rollback();
                $mensaje_error = "Error al registrar la compra: " . $e->getMessage();
                error_log("Error en compras: " . $e->getMessage());
            }
        }
    }
}

// Obtener proveedores
$sql_proveedores = "SELECT * FROM proveedores ORDER BY nombre";
$result_proveedores = $conn->query($sql_proveedores);

// Obtener productos para compra (incluyendo enseres)
$sql_productos = "SELECT * FROM productos ORDER BY nombre";
$result_productos = $conn->query($sql_productos);

// Obtener historial de compras con detalles
$sql_compras = "SELECT 
    c.*, 
    p.nombre as proveedor_nombre,
    (SELECT COUNT(*) FROM compra_detalles cd WHERE cd.id_compra = c.id) as cantidad_productos,
    (SELECT GROUP_CONCAT(cd2.nombre_producto SEPARATOR ', ') 
     FROM compra_detalles cd2 
     WHERE cd2.id_compra = c.id 
     LIMIT 3) as productos_nombres
FROM compras c 
LEFT JOIN proveedores p ON c.id_proveedor = p.id 
ORDER BY c.created_at DESC";
$result_compras = $conn->query($sql_compras);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ruta 666 - Gestión de Compras</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Metal+Mania&display=swap" rel="stylesheet">
    <style>
        :root {
            --dark-bg: #1a1a1a;
            --darker-bg: #121212;
            --accent: #ff0000;
            --text: #ffffff;
            --text-secondary: #cccccc;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }
        
        body {
            background-color: var(--darker-bg);
            color: var(--text);
            height: 100vh;
            overflow: hidden;
        }
        
        .sidebar {
            position: fixed;
            width: 250px;
            height: 100%;
            background-color: var(--dark-bg);
            padding: 20px 0;
            border-right: 1px solid #333;
            display: flex;
            flex-direction: column;
        }
        
        .logo {
            text-align: center;
            padding: 20px 0;
            font-family: 'Metal Mania', cursive;
            font-size: 28px;
            color: var(--accent);
            text-shadow: 0 0 10px rgba(255, 0, 0, 0.7);
            border-bottom: 1px solid #333;
            margin-bottom: 20px;
            flex-shrink: 0;
        }
        
        .menu-container {
            flex: 1;
            overflow-y: auto;
        }
        
        .menu-item {
            padding: 15px 20px;
            display: block;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 4px solid transparent;
        }
        
        .menu-item:hover, .menu-item.active {
            background-color: #333;
            color: var(--accent);
            border-left: 4px solid var(--accent);
        }
        
        .user-info {
            padding: 15px 20px;
            border-top: 1px solid #333;
            background-color: var(--dark-bg);
            flex-shrink: 0;
        }
        
        .user-info strong {
            display: block;
            margin-bottom: 5px;
        }
        
        .user-info small {
            color: var(--text-secondary);
            display: block;
            margin-bottom: 10px;
        }
        
        .content {
            margin-left: 250px;
            padding: 20px;
            height: 100vh;
            overflow-y: auto;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 20px;
            background-color: var(--dark-bg);
            border-bottom: 1px solid #333;
            margin-bottom: 20px;
        }
        
        .btn {
            padding: 8px 15px;
            background-color: var(--accent);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        
        .btn:hover {
            background-color: #cc0000;
            transform: translateY(-2px);
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }
        
        .btn-danger {
            background-color: #dc3545;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .btn-success {
            background-color: #28a745;
        }
        
        .btn-success:hover {
            background-color: #218838;
        }
        
        .btn-warning {
            background-color: #ffc107;
            color: #000;
        }
        
        .btn-warning:hover {
            background-color: #e0a800;
        }
        
        .btn-info {
            background-color: #17a2b8;
        }
        
        .btn-info:hover {
            background-color: #138496;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.8);
        }
        
        .modal-content {
            background-color: var(--dark-bg);
            margin: 5% auto;
            padding: 20px;
            border-radius: 10px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #333;
            padding-bottom: 15px;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: var(--accent);
        }
        
        /* Layout principal */
        .compras-container {
            display: flex;
            flex-direction: column;
            gap: 20px;
            height: auto;
        }
        
        /* Panel de historial */
        .historial-panel {
            background-color: var(--dark-bg);
            border-radius: 10px;
            padding: 20px;
        }
        
        /* Formularios */
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: var(--text-secondary);
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            background: #333;
            border: 1px solid #444;
            border-radius: 5px;
            color: white;
        }
        
        .form-control:focus {
            border-color: var(--accent);
            outline: none;
        }
        
        /* Tabla */
        .table-container {
            background: var(--dark-bg);
            border-radius: 8px;
            overflow: hidden;
            margin-top: 15px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #333;
        }
        
        th {
            background-color: #2a2a2a;
            color: var(--accent);
            font-weight: bold;
        }
        
        tr:hover {
            background-color: #2a2a2a;
        }
        
        .info-box {
            background: var(--dark-bg);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #17a2b8;
        }
        
        .saldo-insuficiente {
            color: #dc3545;
            font-weight: bold;
        }
        
        .saldo-suficiente {
            color: #28a745;
            font-weight: bold;
        }
        
        .producto-enseres {
            background: rgba(255, 193, 7, 0.1);
            border-left: 3px solid #ffc107;
        }
        
        /* Estilos para la tabla de productos en compras */
        #lista-productos-compra {
            margin-top: 15px;
            max-height: 300px;
            overflow-y: auto;
        }

        #lista-productos-compra table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        #lista-productos-compra th,
        #lista-productos-compra td {
            padding: 10px 8px;
            text-align: left;
            border-bottom: 1px solid #333;
            vertical-align: middle;
        }

        #lista-productos-compra th {
            background-color: #2a2a2a;
            color: var(--accent);
            font-weight: bold;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        #lista-productos-compra tr:hover {
            background-color: #2a2a2a;
        }

        /* ESTILOS ESPECÍFICOS PARA BOTONES DE COMPRAS */
        .acciones-compra {
            display: flex !important;
            gap: 8px !important;
            justify-content: center !important;
            align-items: center !important;
            flex-wrap: nowrap !important;
        }

        .btn-accion {
            padding: 8px 12px !important;
            border: none !important;
            border-radius: 5px !important;
            cursor: pointer !important;
            font-size: 14px !important;
            font-weight: bold !important;
            min-width: 50px !important;
            height: 35px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            transition: all 0.3s ease !important;
        }

        .btn-enseres {
            background-color: #ffc107 !important;
            color: #000 !important;
        }

        .btn-enseres:hover {
            background-color: #e0a800 !important;
        }

        .btn-eliminar {
            background-color: #dc3545 !important;
            color: white !important;
        }

        .btn-eliminar:hover {
            background-color: #c82333 !important;
        }

        /* Inputs en la tabla */
        #lista-productos-compra input {
            width: 100%;
            padding: 6px 8px;
            background: #333;
            border: 1px solid #555;
            border-radius: 4px;
            color: white;
            font-size: 14px;
        }

        #lista-productos-compra input:focus {
            border-color: var(--accent);
            outline: none;
        }

        /* Estilos para detalles de compra */
        .detalles-compra {
            background: var(--dark-bg);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .detalles-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #333;
        }

        .detalles-productos {
            margin-top: 15px;
        }

        .producto-detalle {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #333;
        }

        .producto-detalle:last-child {
            border-bottom: none;
        }

        .enseres-badge {
            background: #ffc107;
            color: #000;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }

        .acciones-tabla {
            display: flex;
            gap: 5px;
            justify-content: center;
        }

        /* Nuevos estilos para agregar enseres */
        .agregar-enseres {
            background: rgba(255, 193, 7, 0.1);
            border: 2px dashed #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .agregar-enseres h4 {
            color: #ffc107;
            margin-bottom: 10px;
        }

        .selector-productos {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            align-items: end;
        }

        .btn-enseres-agregar {
            background-color: #ffc107;
            color: #000;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        .btn-enseres-agregar:hover {
            background-color: #e0a800;
        }
        /* Estilos para la tabla de detalles */
.detalles-compra table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}

.detalles-compra th,
.detalles-compra td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #333;
}

.detalles-compra th {
    background-color: #2a2a2a;
    color: var(--accent);
}

.enseres-badge {
    background: #ffc107;
    color: #000;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: bold;
    margin-left: 8px;
}
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="logo">RUTA 666</div>
        
        <div class="menu-container">
            <a href="dashboard_<?php echo $_SESSION['user_role']; ?>.php" class="menu-item">Dashboard</a>
            <a href="ventas.php" class="menu-item">Punto de Venta</a>
            <a href="inventario.php" class="menu-item">Inventario</a>
            <a href="clientes.php" class="menu-item">CRM</a>
            <a href="proveedores.php" class="menu-item">Proveedores</a>
            <a href="caja_chica.php" class="menu-item">Caja Chica</a>
            <a href="compras.php" class="menu-item active">Compras</a>
            <?php if ($_SESSION['user_role'] != 'bar_tender'): ?>
            <a href="reportes.php" class="menu-item">Reportes</a>
            <?php endif; ?>
            <?php if ($_SESSION['user_role'] == 'administrador'): ?>
            <a href="empleados.php" class="menu-item">Empleados</a>
            <a href="configuracion.php" class="menu-item">Configuración</a>
            <?php endif; ?>
            <a href="editar_perfil.php" class="menu-item">Mi Perfil</a>
        </div>
        
        <div class="user-info">
            <strong><?php echo $_SESSION['user_name']; ?></strong>
            <small><?php echo ucfirst($_SESSION['user_role']); ?></small>
            <a href="logout.php" class="btn btn-sm">Cerrar Sesión</a>
        </div>
    </div>
        <div class="content">
        <div class="header">
            <h1>Gestión de Compras</h1>
            <div style="display: flex; gap: 10px;">
                <!-- BOTÓN NUEVA COMPRA - VISIBLE PARA TODOS -->
                <button class="btn btn-success" onclick="abrirModalNuevaCompra()">Nueva Compra</button>
            </div>
        </div>

        <?php if (!empty($mensaje_exito)): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        icon: 'success',
                        title: '¡Éxito!',
                        text: '<?php echo addslashes($mensaje_exito); ?>',
                        timer: 4000,
                        showConfirmButton: true
                    });
                });
            </script>
        <?php endif; ?>
        
        <?php if (!empty($mensaje_error)): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: '<?php echo addslashes($mensaje_error); ?>',
                        confirmButtonText: 'Entendido'
                    });
                });
            </script>
        <?php endif; ?>

        <!-- Información de saldo -->
        <div class="info-box">
            <p><strong>Saldo en caja chica:</strong> 
                <span class="<?php echo ($saldo_actual > 0) ? 'saldo-suficiente' : 'saldo-insuficiente'; ?>">
                    Q<?php echo number_format($saldo_actual, 2); ?>
                </span>
            </p>
            <p><strong>Nota:</strong> Las compras se descuentan automáticamente de la caja chica.</p>
        </div>

        <div class="compras-container">
            <!-- Panel de historial -->
            <div class="historial-panel">
                <h3>Historial de Compras</h3>
                
                <div class="table-container">
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>N° Compra</th>
                    <th>Proveedor</th>
                    <th>Productos</th>
                    <th>Total</th>
                    <th>Fecha</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result_compras && $result_compras->num_rows > 0): ?>
                    <?php while ($compra = $result_compras->fetch_assoc()): ?>
                        <tr>
                            <td>#<?php echo $compra['id']; ?></td>
                            <td><?php echo htmlspecialchars($compra['proveedor_nombre']); ?></td>
                            <td>
                                <div style="max-width: 200px;">
                                    <strong><?php echo $compra['cantidad_productos']; ?> producto(s)</strong>
                                    <?php if (!empty($compra['productos_nombres'])): ?>
                                        <br>
                                        <small style="color: var(--text-secondary);">
                                            <?php 
                                            $nombres = explode(', ', $compra['productos_nombres']);
                                            $mostrar_nombres = array_slice($nombres, 0, 3);
                                            echo htmlspecialchars(implode(', ', $mostrar_nombres));
                                            if (count($nombres) > 3) {
                                                echo '...';
                                            }
                                            ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><strong>Q<?php echo number_format($compra['total'], 2); ?></strong></td>
                            <td><?php echo date('d/m/Y', strtotime($compra['fecha_compra'])); ?></td>
                            <td>
                                <span style="color: 
                                    <?php echo $compra['estado'] == 'recibida' ? '#28a745' : 
                                          ($compra['estado'] == 'cancelada' ? '#dc3545' : '#ffc107'); ?>">
                                    <?php echo ucfirst($compra['estado']); ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-info btn-sm" 
                                        onclick="verDetallesCompra(<?php echo $compra['id']; ?>)">
                                    Ver Detalles
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 20px;">
                            No hay compras registradas
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
            </div>
        </div>
    </div>

    <!-- Modal para nueva compra -->
    <div id="modalNuevaCompra" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Registrar Nueva Compra</h2>
                <span class="close" onclick="cerrarModalNuevaCompra()">&times;</span>
            </div>
            
            <form id="formNuevaCompra" method="POST">
                <input type="hidden" name="registrar_compra" value="true">
                <input type="hidden" name="productos_compra" id="productos_compra" value="[]">
                <input type="hidden" name="total" id="total_compra_hidden" value="0">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Proveedor *</label>
                        <select name="id_proveedor" id="id_proveedor" required class="form-control">
                            <option value="">Seleccionar proveedor</option>
                            <?php 
                            $result_proveedores->data_seek(0);
                            while ($proveedor = $result_proveedores->fetch_assoc()): ?>
                                <option value="<?php echo $proveedor['id']; ?>">
                                    <?php echo htmlspecialchars($proveedor['nombre']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Fecha de Compra *</label>
                        <input type="date" name="fecha_compra" id="fecha_compra" required 
                               class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                
                <!-- Sección para agregar productos existentes -->
                <div class="form-group">
                    <label>Agregar Productos Existentes</label>
                    <div class="selector-productos">
                        <select id="selectProducto" class="form-control">
                            <option value="">Seleccionar producto existente</option>
                            <?php 
                            $result_productos->data_seek(0);
                            while ($producto = $result_productos->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $producto['id']; ?>" 
                                        data-nombre="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                        data-precio-compra="<?php echo $producto['precio_compra'] ?? 0; ?>">
                                    <?php echo htmlspecialchars($producto['nombre']); ?> 
                                    (Compra: Q<?php echo number_format($producto['precio_compra'] ?? 0, 2); ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <button type="button" class="btn btn-info" onclick="agregarProductoExistente()">
                            Agregar Producto
                        </button>
                    </div>
                </div>
                
                <!-- Sección para agregar enseres nuevos -->
                <div class="agregar-enseres">
                    <h4>🔧 Agregar Enseres o Productos Nuevos</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Nombre del Enser/Producto *</label>
                            <input type="text" id="nombre_enseres" class="form-control" 
                                   placeholder="Ej: Martillo, Destornillador, etc.">
                        </div>
                        <div class="form-group">
                            <label>Precio Unitario *</label>
                            <input type="number" id="precio_enseres" class="form-control" 
                                   step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Cantidad *</label>
                            <input type="number" id="cantidad_enseres" class="form-control" 
                                   min="1" value="1">
                        </div>
                        <div class="form-group" style="display: flex; align-items: end;">
                            <button type="button" class="btn-enseres-agregar" onclick="agregarEnser()" style="width: 100%;">
                                + Agregar Enser
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Lista de productos agregados -->
                <div id="lista-productos-compra" class="table-container">
                    <div class="table-responsive">
                        <table style="width: 100%; min-width: 600px;">
                            <thead>
                                <tr>
                                    <th width="30%">Producto</th>
                                    <th width="15%">Cantidad</th>
                                    <th width="20%">Precio Unitario</th>
                                    <th width="20%">Subtotal</th>
                                    <th width="15%">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-productos-compra">
                                <!-- Los productos se agregarán aquí dinámicamente -->
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" style="text-align: right; font-weight: bold;">Total:</td>
                                    <td id="total-compra" style="font-weight: bold; color: var(--accent);">Q0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                
                <div class="form-actions" style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="button" class="btn btn-danger" onclick="cancelarCompra()" style="flex: 1;">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-success" id="btnRegistrarCompra" style="flex: 1;" disabled>
                        Registrar Compra
                    </button>
                </div>
            </form>
        </div>
    </div>
        <!-- Modal para detalles de compra -->
    <div id="modalDetallesCompra" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Detalles de Compra</h2>
                <span class="close" onclick="cerrarModalDetallesCompra()">&times;</span>
            </div>
            
            <div id="contenido-detalles-compra">
                <!-- Los detalles se cargarán aquí dinámicamente -->
            </div>
        </div>
    </div>

        <script>
        let productosCompra = [];
        let saldoActual = <?php echo $saldo_actual; ?>;

        function abrirModalNuevaCompra() {
            document.getElementById('modalNuevaCompra').style.display = 'block';
        }

        function cerrarModalNuevaCompra() {
            document.getElementById('modalNuevaCompra').style.display = 'none';
            productosCompra = [];
            actualizarListaProductosCompra();
            document.getElementById('formNuevaCompra').reset();
        }

        function abrirModalDetallesCompra() {
            document.getElementById('modalDetallesCompra').style.display = 'block';
        }

        function cerrarModalDetallesCompra() {
            document.getElementById('modalDetallesCompra').style.display = 'none';
        }

        // Función para agregar productos existentes
        function agregarProductoExistente() {
            const select = document.getElementById('selectProducto');
            const selectedOption = select.options[select.selectedIndex];
            
            if (!selectedOption.value) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Selecciona un producto',
                    text: 'Debes seleccionar un producto de la lista',
                    timer: 2000
                });
                return;
            }
            
            const productoId = selectedOption.value;
            const productoNombre = selectedOption.getAttribute('data-nombre');
            const precioCompra = parseFloat(selectedOption.getAttribute('data-precio-compra')) || 0;
            
            // Verificar si el producto ya está en la lista
            const productoExistente = productosCompra.find(p => p.id == productoId);
            if (productoExistente) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Producto duplicado',
                    text: 'Este producto ya está en la lista de compra',
                    timer: 2000
                });
                return;
            }
            
            // Agregar producto a la lista
            const producto = {
                id: productoId,
                nombre: productoNombre,
                cantidad: 1,
                precio_unitario: precioCompra,
                subtotal: precioCompra,
                es_para_venta: true,
                tipo: 'existente'
            };
            
            productosCompra.push(producto);
            actualizarListaProductosCompra();
            select.value = '';
            
            Swal.fire({
                icon: 'success',
                title: 'Producto agregado',
                text: `${productoNombre} agregado a la compra`,
                timer: 1500
            });
        }

        // Función para agregar enseres nuevos - CORREGIDA COMPLETAMENTE
        // Función para agregar enseres nuevos - VERSIÓN SIMPLIFICADA
function agregarEnser() {
    const nombre = document.getElementById('nombre_enseres').value.trim();
    const precio = parseFloat(document.getElementById('precio_enseres').value) || 0;
    const cantidad = parseInt(document.getElementById('cantidad_enseres').value) || 1;
    
    console.log('Datos del enser:', { nombre, precio, cantidad });
    
    // Validaciones básicas
    if (!nombre) {
        alert('Debes ingresar un nombre para el enser');
        return;
    }
    
    if (precio <= 0) {
        alert('El precio debe ser mayor a 0');
        return;
    }
    
    if (cantidad <= 0) {
        alert('La cantidad debe ser mayor a 0');
        return;
    }
    
    // Crear nuevo enser
    const nuevoEnser = {
        id: 0,
        nombre: nombre,
        cantidad: cantidad,
        precio_unitario: precio,
        subtotal: cantidad * precio,
        es_para_venta: false,
        tipo: 'nuevo'
    };
    
    // Agregar a la lista
    productosCompra.push(nuevoEnser);
    actualizarListaProductosCompra();
    
    // Limpiar formulario
    document.getElementById('nombre_enseres').value = '';
    document.getElementById('precio_enseres').value = '';
    document.getElementById('cantidad_enseres').value = '1';
    
    console.log('Enser agregado:', nuevoEnser);
    console.log('Lista actual de productos:', productosCompra);
}

        function actualizarListaProductosCompra() {
            const tbody = document.getElementById('tbody-productos-compra');
            const totalCompraElement = document.getElementById('total-compra');
            const totalCompraHidden = document.getElementById('total_compra_hidden');
            const btnRegistrar = document.getElementById('btnRegistrarCompra');
            
            tbody.innerHTML = '';
            let totalCompra = 0;
            
            productosCompra.forEach((producto, index) => {
                const subtotal = producto.cantidad * producto.precio_unitario;
                totalCompra += subtotal;
                
                const tr = document.createElement('tr');
                tr.className = producto.es_para_venta ? '' : 'producto-enseres';
                tr.innerHTML = `
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <strong>${producto.nombre}</strong>
                            ${!producto.es_para_venta ? '<span style="color: #ffc107; font-weight: bold;">🔧 ENSER</span>' : ''}
                            ${producto.tipo === 'nuevo' ? '<span style="color: #17a2b8; font-weight: bold;">🆕 NUEVO</span>' : ''}
                        </div>
                    </td>
                    <td>
                        <input type="number" value="${producto.cantidad}" min="1" 
                               onchange="actualizarCantidad(${index}, this.value)" 
                               style="width: 70px; padding: 8px; background: #333; border: 1px solid #555; color: white; border-radius: 4px; text-align: center;">
                    </td>
                    <td>
                        <input type="number" value="${producto.precio_unitario.toFixed(2)}" step="0.01" min="0"
                               onchange="actualizarPrecio(${index}, this.value)"
                               style="width: 100px; padding: 8px; background: #333; border: 1px solid #555; color: white; border-radius: 4px; text-align: center;">
                    </td>
                    <td style="font-weight: bold; color: var(--accent);">
                        Q${subtotal.toFixed(2)}
                    </td>
                    <td>
                        <div class="acciones-compra">
                            ${producto.tipo === 'existente' ? `
                                <button type="button" class="btn-accion btn-enseres" onclick="toggleParaVenta(${index})" title="${producto.es_para_venta ? 'Marcar como enser' : 'Marcar para venta'}">
                                    ${producto.es_para_venta ? '💰' : '🔧'}
                                </button>
                            ` : ''}
                            <button type="button" class="btn-accion btn-eliminar" onclick="eliminarProductoCompra(${index})" title="Eliminar producto">
                                ✕
                            </button>
                        </div>
                    </td>
                `;
                tbody.appendChild(tr);
            });
            
            totalCompraElement.innerHTML = `<strong style="font-size: 1.2em;">Q${totalCompra.toFixed(2)}</strong>`;
            totalCompraHidden.value = totalCompra;
            
            // Actualizar campo oculto
            document.getElementById('productos_compra').value = JSON.stringify(productosCompra);
            
            // Habilitar/deshabilitar botón de registrar
            const tieneProductos = productosCompra.length > 0;
            const saldoSuficiente = totalCompra <= saldoActual;
            
            btnRegistrar.disabled = !tieneProductos || !saldoSuficiente;
            
            if (tieneProductos && !saldoSuficiente) {
                totalCompraElement.innerHTML = `
                    <strong style="font-size: 1.2em; color: #dc3545;">Q${totalCompra.toFixed(2)}</strong> 
                    <br><small style="color: #dc3545;">❌ Fondos insuficientes</small>
                `;
            }
            
            // Mostrar mensaje si no hay productos
            if (productosCompra.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: #666; font-style: italic;">
                            📝 Agregue productos usando las opciones superiores
                        </td>
                    </tr>
                `;
            }
        }

        function actualizarCantidad(index, nuevaCantidad) {
            productosCompra[index].cantidad = parseInt(nuevaCantidad) || 1;
            productosCompra[index].subtotal = productosCompra[index].cantidad * productosCompra[index].precio_unitario;
            actualizarListaProductosCompra();
        }

        function actualizarPrecio(index, nuevoPrecio) {
            productosCompra[index].precio_unitario = parseFloat(nuevoPrecio) || 0;
            productosCompra[index].subtotal = productosCompra[index].cantidad * productosCompra[index].precio_unitario;
            actualizarListaProductosCompra();
        }

        function toggleParaVenta(index) {
            productosCompra[index].es_para_venta = !productosCompra[index].es_para_venta;
            actualizarListaProductosCompra();
        }

        function eliminarProductoCompra(index) {
            productosCompra.splice(index, 1);
            actualizarListaProductosCompra();
        }

        function cancelarCompra() {
            Swal.fire({
                title: '¿Cancelar compra?',
                text: 'Se perderán todos los productos agregados',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sí, cancelar',
                cancelButtonText: 'Continuar editando'
            }).then((result) => {
                if (result.isConfirmed) {
                    cerrarModalNuevaCompra();
                }
            });
        }

        // ... resto de funciones igual ...

        // Validar formulario antes de enviar
        document.getElementById('formNuevaCompra').addEventListener('submit', function(e) {
            if (productosCompra.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Debe agregar al menos un producto a la compra'
                });
                return;
            }
            
            const totalCompra = productosCompra.reduce((sum, producto) => sum + producto.subtotal, 0);
            if (totalCompra > saldoActual) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Fondos insuficientes',
                    html: `Saldo disponible: <strong>Q${saldoActual.toFixed(2)}</strong><br>
                           Total compra: <strong>Q${totalCompra.toFixed(2)}</strong><br>
                           Faltante: <strong style="color: #dc3545;">Q${(totalCompra - saldoActual).toFixed(2)}</strong>`
                });
                return;
            }
            
            // Mostrar confirmación final
            e.preventDefault();
            
            const productosCount = productosCompra.length;
            const enseresCount = productosCompra.filter(p => !p.es_para_venta).length;
            const productosNormalesCount = productosCompra.filter(p => p.es_para_venta).length;
            
            Swal.fire({
                title: '¿Registrar compra?',
                html: `Proveedor: <strong>${document.getElementById('id_proveedor').options[document.getElementById('id_proveedor').selectedIndex].text}</strong><br>
                       Total: <strong>Q${totalCompra.toFixed(2)}</strong><br>
                       Productos: <strong>${productosCount}</strong> (${productosNormalesCount} para venta, ${enseresCount} enseres)`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, registrar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    this.submit();
                }
            });
        });

        // Cerrar modales al hacer clic fuera
        window.onclick = function(event) {
            const modalNueva = document.getElementById('modalNuevaCompra');
            const modalDetalles = document.getElementById('modalDetallesCompra');
            
            if (event.target == modalNueva) {
                cerrarModalNuevaCompra();
            }
            if (event.target == modalDetalles) {
                cerrarModalDetallesCompra();
            }
        }

        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            actualizarListaProductosCompra();
        });
        function verDetallesCompra(compraId) {
    // Hacer una petición AJAX para obtener los detalles
    fetch('obtener_detalles_compra.php?id=' + compraId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                mostrarModalDetalles(data.compra, data.detalles);
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'No se pudieron cargar los detalles de la compra'
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Error al cargar los detalles de la compra'
            });
        });
}

function mostrarModalDetalles(compra, detalles) {
    const contenido = document.getElementById('contenido-detalles-compra');
    
    let html = `
        <div class="detalles-compra">
            <div class="detalles-header">
                <div>
                    <h3>Compra #${compra.id}</h3>
                    <p><strong>Proveedor:</strong> ${compra.proveedor_nombre}</p>
                    <p><strong>Fecha:</strong> ${compra.fecha_compra}</p>
                    <p><strong>Total:</strong> Q${parseFloat(compra.total).toFixed(2)}</p>
                    <p><strong>Estado:</strong> ${compra.estado}</p>
                </div>
            </div>
            
            <div class="detalles-productos">
                <h4>Productos de la Compra</h4>
                <div class="table-container">
                    <table style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Cantidad</th>
                                <th>Precio Unitario</th>
                                <th>Subtotal</th>
                                <th>Tipo</th>
                            </tr>
                        </thead>
                        <tbody>
    `;
    
    detalles.forEach(detalle => {
        const tipo = detalle.es_para_venta ? 'Producto Venta' : 'Enser';
        const tipoClass = detalle.es_para_venta ? '' : 'enseres-badge';
        
        html += `
            <tr>
                <td>
                    <strong>${detalle.nombre_producto}</strong>
                    ${!detalle.es_para_venta ? '<span class="enseres-badge">ENSER</span>' : ''}
                </td>
                <td>${detalle.cantidad}</td>
                <td>Q${parseFloat(detalle.precio_unitario).toFixed(2)}</td>
                <td><strong>Q${parseFloat(detalle.subtotal).toFixed(2)}</strong></td>
                <td>
                    <span class="${tipoClass}">${tipo}</span>
                </td>
            </tr>
        `;
    });
    
    html += `
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" style="text-align: right; font-weight: bold;">Total:</td>
                                <td colspan="2" style="font-weight: bold; color: var(--accent);">
                                    Q${parseFloat(compra.total).toFixed(2)}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    `;
    
    contenido.innerHTML = html;
    document.getElementById('modalDetallesCompra').style.display = 'block';
}
    </script>
        
</body>
</html>