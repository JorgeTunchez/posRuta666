<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

include 'conexion.php';
include 'config_hora.php';

// Verificar permisos según rol
$puede_editar = ($_SESSION['user_role'] == 'administrador' || $_SESSION['user_role'] == 'gerente');

// Inicializar variables de mensaje
// Inicializar variables de mensaje solo si no existen
if (!isset($mensaje_exito)) $mensaje_exito = '';
if (!isset($mensaje_error)) $mensaje_error = '';

// Calcular saldo actual de caja chica - FUNCIÓN MEJORADA
function obtenerSaldoCajaChica($conn) {
    $sql_totales = "SELECT 
        COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) as total_ingresos,
        COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN monto ELSE 0 END), 0) as total_egresos,
        COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) - 
        COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN monto ELSE 0 END), 0) as saldo_actual
        FROM caja_chica";
    $result_totales = $conn->query($sql_totales);
    $totales = $result_totales->fetch_assoc();
    return floatval($totales['saldo_actual'] ?? 0);
}

$saldo_actual = obtenerSaldoCajaChica($conn);

// Procesar operaciones
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Agregar movimiento de caja chica
    if (isset($_POST['agregar_movimiento'])) {
        $tipo = $_POST['tipo'];
        $monto = floatval($_POST['monto']);
        $descripcion = trim($_POST['descripcion']);
        
        $sql = "INSERT INTO caja_chica (tipo, monto, descripcion, id_empleado) 
                VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sdsi", $tipo, $monto, $descripcion, $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            $mensaje_exito = "Movimiento agregado correctamente";
            // Actualizar saldo actual
            $saldo_actual = obtenerSaldoCajaChica($conn);
        } else {
            $mensaje_error = "Error al agregar movimiento: " . $conn->error;
        }
    }
    
    // Editar movimiento (solo admin y gerente)
    if (isset($_POST['editar_movimiento']) && $puede_editar) {
        $id = intval($_POST['id']);
        $tipo = $_POST['tipo'];
        $monto = floatval($_POST['monto']);
        $descripcion = trim($_POST['descripcion']);
        
        $sql = "UPDATE caja_chica SET tipo=?, monto=?, descripcion=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sdsi", $tipo, $monto, $descripcion, $id);
        
        if ($stmt->execute()) {
            $mensaje_exito = "Movimiento actualizado correctamente";
            // Actualizar saldo actual
            $saldo_actual = obtenerSaldoCajaChica($conn);
        } else {
            $mensaje_error = "Error al actualizar movimiento: " . $conn->error;
        }
    }
    
    // Eliminar movimiento (solo admin y gerente)
    if (isset($_POST['eliminar_movimiento']) && $puede_editar) {
        $id = intval($_POST['id']);
        
        $sql = "DELETE FROM caja_chica WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $mensaje_exito = "Movimiento eliminado correctamente";
            // Actualizar saldo actual
            $saldo_actual = obtenerSaldoCajaChica($conn);
        } else {
            $mensaje_error = "Error al eliminar movimiento: " . $conn->error;
        }
    }
    
    // Cobrar salario (todos los empleados pueden cobrar SU salario) - VERSIÓN CORREGIDA
    if (isset($_POST['cobrar_salario'])) {
        $id_empleado = intval($_POST['id_empleado']);
        $monto = floatval($_POST['monto']);
        $descripcion = trim($_POST['descripcion']);
        $fecha_actual = date('Y-m-d');
        
        error_log("=== INICIANDO COBRO DE SALARIO ===");
        error_log("Empleado ID: " . $id_empleado);
        error_log("Monto: " . $monto);
        error_log("Descripción: " . $descripcion);
        error_log("Fecha: " . $fecha_actual);
        error_log("Saldo en caja: " . $saldo_actual);
        
        // Verificar saldo suficiente en caja chica
        if ($monto > $saldo_actual) {
            $mensaje_error = "Fondos insuficientes en caja chica. Saldo actual: Q" . number_format($saldo_actual, 2) . ". Monto solicitado: Q" . number_format($monto, 2);
            error_log("❌ " . $mensaje_error);
        } else {
            // Verificar que el empleado solo pueda cobrar su propio salario
            if ($_SESSION['user_role'] != 'administrador' && $_SESSION['user_role'] != 'gerente') {
                if ($id_empleado != $_SESSION['user_id']) {
                    $mensaje_error = "Solo puedes cobrar tu propio salario";
                    error_log("❌ " . $mensaje_error);
                } else {
                    procesarPagoSalario($id_empleado, $monto, $descripcion, $fecha_actual, $conn);
                }
            } else {
                // Admin/gerente puede pagar cualquier salario
                procesarPagoSalario($id_empleado, $monto, $descripcion, $fecha_actual, $conn);
            }
        }
    }
}

// FUNCIÓN COMPLETAMENTE CORREGIDA PARA PROCESAR PAGO DE SALARIO
function procesarPagoSalario($id_empleado, $monto, $descripcion, $fecha_actual, $conn) {
    global $mensaje_exito, $mensaje_error, $saldo_actual;
    
    error_log("=== PROCESANDO PAGO DE SALARIO ===");
    
    // Verificar si ya se pagó salario hoy a este empleado
    $sql_verificar = "SELECT id FROM pagos_salarios WHERE id_empleado = ? AND fecha_pago = ?";
    $stmt_verificar = $conn->prepare($sql_verificar);
    $stmt_verificar->bind_param("is", $id_empleado, $fecha_actual);
    $stmt_verificar->execute();
    $result_verificar = $stmt_verificar->get_result();
    
    if ($result_verificar->num_rows > 0) {
        $mensaje_error = "Este empleado ya recibió un pago de salario hoy";
        error_log("❌ " . $mensaje_error);
        return;
    }
    
    // Obtener nombre del empleado para el registro
    $sql_nombre_empleado = "SELECT nombre FROM empleados WHERE id = ?";
    $stmt_nombre = $conn->prepare($sql_nombre_empleado);
    $stmt_nombre->bind_param("i", $id_empleado);
    $stmt_nombre->execute();
    $result_nombre = $stmt_nombre->get_result();
    $empleado = $result_nombre->fetch_assoc();
    $nombre_empleado = $empleado ? $empleado['nombre'] : 'Empleado #' . $id_empleado;
    
    // Iniciar transacción
    $conn->begin_transaction();
    
    try {
        error_log("Iniciando transacción...");
        
        // Registrar el pago como egreso en caja chica
        $sql_caja = "INSERT INTO caja_chica (tipo, monto, descripcion, id_empleado) 
                    VALUES ('egreso', ?, ?, ?)";
        $stmt_caja = $conn->prepare($sql_caja);
        $descripcion_caja = $descripcion . " - " . $nombre_empleado;
        $stmt_caja->bind_param("dsi", $monto, $descripcion_caja, $_SESSION['user_id']);
        
        if (!$stmt_caja->execute()) {
            throw new Exception("Error al registrar en caja chica: " . $stmt_caja->error);
        }
        
        error_log("✅ Registrado en caja chica");
        
        // Verificar si la tabla pagos_salarios existe, si no, crearla
        $sql_check_table = "SHOW TABLES LIKE 'pagos_salarios'";
        $result_check = $conn->query($sql_check_table);
        
        if ($result_check->num_rows == 0) {
            // Crear la tabla si no existe
            $sql_create_table = "CREATE TABLE pagos_salarios (
                id INT PRIMARY KEY AUTO_INCREMENT,
                id_empleado INT NOT NULL,
                monto DECIMAL(10,2) NOT NULL,
                descripcion TEXT NOT NULL,
                fecha_pago DATE NOT NULL,
                id_empleado_pago INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (id_empleado) REFERENCES empleados(id),
                FOREIGN KEY (id_empleado_pago) REFERENCES empleados(id)
            )";
            $conn->query($sql_create_table);
            error_log("✅ Tabla pagos_salarios creada");
        }
        
        // Registrar en la tabla de control de pagos de salario
        $sql_pago_salario = "INSERT INTO pagos_salarios (id_empleado, monto, descripcion, fecha_pago, id_empleado_pago) 
                            VALUES (?, ?, ?, ?, ?)";
        $stmt_pago = $conn->prepare($sql_pago_salario);
        $stmt_pago->bind_param("idssi", $id_empleado, $monto, $descripcion, $fecha_actual, $_SESSION['user_id']);
        
        if (!$stmt_pago->execute()) {
            throw new Exception("Error al registrar en pagos_salarios: " . $stmt_pago->error);
        }
        
        error_log("✅ Registrado en pagos_salarios");
        
        // Confirmar transacción
        $conn->commit();
        
        $mensaje_exito = "Pago de salario registrado correctamente. Empleado: " . $nombre_empleado . ", Monto: Q" . number_format($monto, 2);
        error_log("✅ " . $mensaje_exito);
        
        // Actualizar saldo actual
        $saldo_actual = obtenerSaldoCajaChica($conn);
        
    } catch (Exception $e) {
        // Revertir transacción en caso de error
        $conn->rollback();
        $mensaje_error = "Error al procesar el pago de salario: " . $e->getMessage();
        error_log("❌ " . $mensaje_error);
    }
}

// Obtener movimientos de caja chica
$sql_movimientos = "SELECT cc.*, e.nombre as empleado_nombre 
                   FROM caja_chica cc 
                   LEFT JOIN empleados e ON cc.id_empleado = e.id 
                   ORDER BY cc.created_at DESC";
$result_movimientos = $conn->query($sql_movimientos);

// Obtener empleados para cobro de salario
$sql_empleados = "SELECT * FROM empleados ORDER BY nombre";
$result_empleados = $conn->query($sql_empleados);

// Si no es admin/gerente, solo mostrar el empleado actual
if ($_SESSION['user_role'] != 'administrador' && $_SESSION['user_role'] != 'gerente') {
    $sql_empleado_actual = "SELECT * FROM empleados WHERE id = ?";
    $stmt_empleado = $conn->prepare($sql_empleado_actual);
    $stmt_empleado->bind_param("i", $_SESSION['user_id']);
    $stmt_empleado->execute();
    $result_empleados = $stmt_empleado->get_result();
}

// Obtener historial de pagos de salario (solo para admin/gerente)
if ($puede_editar) {
    // Verificar si la tabla existe primero
    $sql_check_historial = "SHOW TABLES LIKE 'pagos_salarios'";
    $result_check_historial = $conn->query($sql_check_historial);
    
    if ($result_check_historial->num_rows > 0) {
        $sql_historial_salarios = "SELECT ps.*, e.nombre as empleado_nombre, ep.nombre as empleado_pago_nombre
                                  FROM pagos_salarios ps
                                  LEFT JOIN empleados e ON ps.id_empleado = e.id
                                  LEFT JOIN empleados ep ON ps.id_empleado_pago = ep.id
                                  ORDER BY ps.fecha_pago DESC, ps.created_at DESC
                                  LIMIT 50";
        $result_historial_salarios = $conn->query($sql_historial_salarios);
    }
}

// Calcular totales - VERSIÓN CORREGIDA
$sql_totales = "SELECT 
    COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) as total_ingresos,
    COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN monto ELSE 0 END), 0) as total_egresos,
    COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) - 
    COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN monto ELSE 0 END), 0) as saldo_actual
    FROM caja_chica";
$result_totales = $conn->query($sql_totales);
$totales = $result_totales->fetch_assoc();

// Debug: Verificar los totales calculados
error_log("=== TOTALES CAJA CHICA ===");
error_log("Ingresos: " . ($totales['total_ingresos'] ?? 0));
error_log("Egresos: " . ($totales['total_egresos'] ?? 0));
error_log("Saldo: " . ($totales['saldo_actual'] ?? 0));

// Mejorar descripciones de movimientos existentes
function mejorarDescripcion($descripcion, $conn) {
    // Buscar patrones como "Cliente ID: X" y reemplazar por nombre
    if (preg_match('/Cliente ID: (\d+)/', $descripcion, $matches)) {
        $cliente_id = $matches[1];
        $sql_cliente = "SELECT nombre FROM clientes WHERE id = ?";
        $stmt = $conn->prepare($sql_cliente);
        $stmt->bind_param("i", $cliente_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($cliente = $result->fetch_assoc()) {
            $descripcion = str_replace("Cliente ID: $cliente_id", "Cliente: " . $cliente['nombre'], $descripcion);
        }
    }
    return $descripcion;
}

// Obtener fecha actual en formato legible
$fecha_actual_legible = date('d/m/Y');
?>  

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ruta 666 - Caja Chica</title>
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
        
        .btn-primary {
            background-color: #007bff;
        }
        
        .btn-primary:hover {
            background-color: #0056b3;
        }
        
        /* Estadísticas */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: var(--dark-bg);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid var(--accent);
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .ingreso {
            color: #28a745;
        }
        
        .egreso {
            color: #dc3545;
        }
        
        .saldo {
            color: var(--accent);
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9em;
        }
        
        /* Búsqueda */
        .search-box {
            margin-bottom: 20px;
        }
        
        .search-box input {
            width: 100%;
            padding: 12px;
            background: #333;
            border: 1px solid #444;
            border-radius: 25px;
            color: white;
            font-size: 16px;
        }
        
        /* Tabla */
        .table-container {
            background: var(--dark-bg);
            border-radius: 8px;
            overflow: hidden;
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
        
        .tipo-ingreso {
            color: #28a745;
            font-weight: bold;
        }
        
        .tipo-egreso {
            color: #dc3545;
            font-weight: bold;
        }
        
        /* Modales */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background-color: var(--dark-bg);
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #333;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.5em;
            cursor: pointer;
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
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            
            .content {
                margin-left: 0;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
    }
     .btn-salario {
            background-color: #6f42c1;
        }
        
        .btn-salario:hover {
            background-color: #5a2d91;
        }
        
        .btn-historial {
            background-color: #20c997;
        }
        
        .btn-historial:hover {
            background-color: #199d76;
        }
        
        .section-title {
            margin: 30px 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--accent);
            color: var(--accent);
        }
        
        .info-box {
            background: var(--dark-bg);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #17a2b8;
        }
        
        .info-box p {
            margin: 5px 0;
            color: var(--text-secondary);
        }
        
        .saldo-insuficiente {
            color: #dc3545;
            font-weight: bold;
        }
        
        .saldo-suficiente {
            color: #28a745;
            font-weight: bold;
        }
        
        .advertencia {
            background: #fff3cd;
            color: #856404;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            border-left: 4px solid #ffc107;
        }
        
        .advertencia p {
            margin: 5px 0;
            color: #856404;
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
            <a href="caja_chica.php" class="menu-item active">Caja Chica</a>
            <a href="compras.php" class="menu-item">Compras</a>
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
    <h1>Gestión de Caja Chica</h1>
    <div style="display: flex; gap: 10px;">
        <?php if ($_SESSION['user_role'] != 'bar_tender'): ?>
            <button class="btn btn-success" onclick="abrirModalMovimiento()">Agregar Movimiento</button>
        <?php endif; ?>
        <button class="btn btn-salario" onclick="abrirModalSalario()">Cobrar Salario</button>
        <?php if ($puede_editar): ?>
            <button class="btn btn-historial" onclick="abrirModalHistorial()">Historial Salarios</button>
        <?php endif; ?>
    </div>
</div>  

       <?php if (!empty($mensaje_exito)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'success',
                title: 'Éxito',
                text: '<?php echo addslashes($mensaje_exito); ?>',
                timer: 3000,
                showConfirmButton: false
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

        

        <!-- Estadísticas -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-number ingreso">Q<?php echo number_format($totales['total_ingresos'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Ingresos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number egreso">Q<?php echo number_format($totales['total_egresos'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Egresos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number <?php echo ($saldo_actual >= 0) ? 'saldo' : 'egreso'; ?>">
                    Q<?php echo number_format($saldo_actual, 2); ?>
                </div>
                <div class="stat-label">Saldo Actual</div>
            </div>
        </div>

        <!-- Búsqueda -->
        <div class="search-box">
            <input type="text" id="searchMovimiento" placeholder="🔍 Buscar movimiento por descripción..." onkeyup="filtrarMovimientos()">
        </div>

        <div class="table-container">
            <div class="table-responsive">
                <table id="tablaMovimientos">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Monto</th>
                            <th>Descripción</th>
                            <th>Registrado por</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_movimientos && $result_movimientos->num_rows > 0): ?>
                            <?php while ($movimiento = $result_movimientos->fetch_assoc()): ?>
                                <tr data-descripcion="<?php echo htmlspecialchars(strtolower($movimiento['descripcion'])); ?>">
                                    <td><?php echo date('d/m/Y H:i', strtotime($movimiento['created_at'])); ?></td>
                                    <td>
                                        <span class="tipo-<?php echo $movimiento['tipo']; ?>">
                                            <?php echo ucfirst($movimiento['tipo']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong>Q<?php echo number_format($movimiento['monto'], 2); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars(mejorarDescripcion($movimiento['descripcion'], $conn)); ?></td>
                                    <td><?php echo htmlspecialchars($movimiento['empleado_nombre']); ?></td>
                                    <td>
                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                            <?php if ($puede_editar): ?>
                                                <button class="btn btn-primary btn-sm" onclick="editarMovimiento(<?php echo $movimiento['id']; ?>)">
                                                    Editar
                                                </button>
                                                <button class="btn btn-danger btn-sm" onclick="eliminarMovimiento(<?php echo $movimiento['id']; ?>, '<?php echo htmlspecialchars($movimiento['descripcion']); ?>')">
                                                    Eliminar
                                                </button>
                                            <?php else: ?>
                                                <small>Solo lectura</small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 20px;">
                                    No hay movimientos registrados
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sección de historial de salarios (solo para admin/gerente) -->
        <?php if ($puede_editar): ?>
            <h3 class="section-title">Historial de Pagos de Salario</h3>
            <div class="table-container">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha Pago</th>
                                <th>Empleado</th>
                                <th>Monto</th>
                                <th>Descripción</th>
                                <th>Pagado por</th>
                                <th>Fecha Registro</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($result_historial_salarios) && $result_historial_salarios->num_rows > 0): ?>
                                <?php while ($pago = $result_historial_salarios->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($pago['fecha_pago'])); ?></td>
                                        <td><?php echo htmlspecialchars($pago['empleado_nombre']); ?></td>
                                        <td><strong>Q<?php echo number_format($pago['monto'], 2); ?></strong></td>
                                        <td><?php echo htmlspecialchars($pago['descripcion']); ?></td>
                                        <td><?php echo htmlspecialchars($pago['empleado_pago_nombre']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($pago['created_at'])); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 20px;">
                                        No hay pagos de salario registrados
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal para agregar/editar movimiento -->
    <div class="modal" id="modalMovimiento">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalMovimientoTitulo">Agregar Movimiento</h3>
                <button class="modal-close" onclick="cerrarModalMovimiento()">&times;</button>
            </div>
            <form id="formMovimiento" method="POST">
                <input type="hidden" name="id" id="movimientoId">
                
                <div class="form-group">
                    <label>Tipo *</label>
                    <select name="tipo" id="movimientoTipo" required class="form-control">
                        <option value="ingreso">Ingreso</option>
                        <option value="egreso">Egreso</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Monto *</label>
                    <input type="number" name="monto" id="movimientoMonto" step="0.01" min="0.01" required class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Descripción *</label>
                    <textarea name="descripcion" id="movimientoDescripcion" required class="form-control" style="height: 80px;"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="cerrarModalMovimiento()">Cancelar</button>
                    <button type="submit" class="btn btn-success" id="btnGuardarMovimiento">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para cobrar salario -->
    <div class="modal" id="modalSalario">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Cobrar Salario</h3>
                <button class="modal-close" onclick="cerrarModalSalario()">&times;</button>
            </div>
            <form id="formSalario" method="POST">
                <input type="hidden" name="cobrar_salario" value="true">
                
                <div class="form-group">
                    <label>Empleado *</label>
                    <select name="id_empleado" id="empleadoSalario" required class="form-control" onchange="actualizarSalario()" <?php echo ($_SESSION['user_role'] != 'administrador' && $_SESSION['user_role'] != 'gerente') ? 'disabled' : ''; ?>>
                        <option value="">Seleccionar empleado</option>
                        <?php 
                        $result_empleados->data_seek(0); // Reset pointer
                        while ($empleado = $result_empleados->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $empleado['id']; ?>" 
                                    data-salario="<?php echo $empleado['salario_base']; ?>"
                                    <?php echo ($_SESSION['user_role'] != 'administrador' && $_SESSION['user_role'] != 'gerente' && $empleado['id'] == $_SESSION['user_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($empleado['nombre']); ?> - Q<?php echo number_format($empleado['salario_base'], 2); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <?php if ($_SESSION['user_role'] != 'administrador' && $_SESSION['user_role'] != 'gerente'): ?>
                        <input type="hidden" name="id_empleado" value="<?php echo $_SESSION['user_id']; ?>">
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label>Monto a Cobrar *</label>
                    <input type="number" name="monto" id="montoSalario" step="0.01" min="0.01" required class="form-control" onchange="verificarSaldo()">
                    <small id="mensajeSaldo" style="margin-top: 5px; display: block;"></small>
                </div>
                
                <div class="form-group">
                    <label>Descripción *</label>
                    <textarea name="descripcion" id="descripcionSalario" required class="form-control" style="height: 80px;"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="cerrarModalSalario()">Cancelar</button>
                    <button type="submit" class="btn btn-success" id="btnCobrarSalario">Cobrar Salario</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para historial de salarios (solo admin/gerente) -->
    <?php if ($puede_editar): ?>
    <div class="modal" id="modalHistorial">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3>Historial Completo de Pagos de Salario</h3>
                <button class="modal-close" onclick="cerrarModalHistorial()">&times;</button>
            </div>
            <div class="table-responsive">
                <table style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Fecha Pago</th>
                            <th>Empleado</th>
                            <th>Monto</th>
                            <th>Descripción</th>
                            <th>Pagado por</th>
                            <th>Fecha Registro</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sql_historial_completo = "SELECT ps.*, e.nombre as empleado_nombre, ep.nombre as empleado_pago_nombre
                                                  FROM pagos_salarios ps
                                                  LEFT JOIN empleados e ON ps.id_empleado = e.id
                                                  LEFT JOIN empleados ep ON ps.id_empleado_pago = ep.id
                                                  ORDER BY ps.fecha_pago DESC, ps.created_at DESC";
                        $result_historial_completo = $conn->query($sql_historial_completo);
                        ?>
                        <?php if ($result_historial_completo && $result_historial_completo->num_rows > 0): ?>
                            <?php while ($pago = $result_historial_completo->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($pago['fecha_pago'])); ?></td>
                                    <td><?php echo htmlspecialchars($pago['empleado_nombre']); ?></td>
                                    <td><strong>Q<?php echo number_format($pago['monto'], 2); ?></strong></td>
                                    <td><?php echo htmlspecialchars($pago['descripcion']); ?></td>
                                    <td><?php echo htmlspecialchars($pago['empleado_pago_nombre']); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($pago['created_at'])); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 20px;">
                                    No hay pagos de salario registrados
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-danger" onclick="cerrarModalHistorial()">Cerrar</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Variable global para el saldo actual
        const saldoActual = <?php echo $saldo_actual; ?>;

        function abrirModalMovimiento() {
            document.getElementById('modalMovimientoTitulo').textContent = 'Agregar Movimiento';
            document.getElementById('btnGuardarMovimiento').textContent = 'Guardar';
            document.getElementById('formMovimiento').reset();
            document.getElementById('movimientoId').value = '';
            
            // Remover campos existentes
            const existingAddField = document.querySelector('input[name="agregar_movimiento"]');
            const existingEditField = document.querySelector('input[name="editar_movimiento"]');
            if (existingAddField) existingAddField.remove();
            if (existingEditField) existingEditField.remove();
            
            // Agregar campo para agregar
            const addField = document.createElement('input');
            addField.type = 'hidden';
            addField.name = 'agregar_movimiento';
            addField.value = 'true';
            document.getElementById('formMovimiento').appendChild(addField);
            
            document.getElementById('modalMovimiento').style.display = 'flex';
        }
        
        function editarMovimiento(id) {
            // Buscar la fila del movimiento
            const filas = document.querySelectorAll('#tablaMovimientos tbody tr');
            let movimientoData = null;
            
            filas.forEach(fila => {
                const tds = fila.querySelectorAll('td');
                if (tds.length > 0) {
                    const tipo = tds[1].querySelector('span').textContent.toLowerCase();
                    const monto = parseFloat(tds[2].querySelector('strong').textContent.replace('Q', '').replace(',', ''));
                    const descripcion = tds[3].textContent;
                    
                    movimientoData = {
                        id: id,
                        tipo: tipo,
                        monto: monto,
                        descripcion: descripcion
                    };
                }
            });
            
            if (movimientoData) {
                document.getElementById('modalMovimientoTitulo').textContent = 'Editar Movimiento';
                document.getElementById('btnGuardarMovimiento').textContent = 'Actualizar';
                document.getElementById('movimientoId').value = movimientoData.id;
                document.getElementById('movimientoTipo').value = movimientoData.tipo;
                document.getElementById('movimientoMonto').value = movimientoData.monto;
                document.getElementById('movimientoDescripcion').value = movimientoData.descripcion;
                
                // Remover campos existentes
                const existingAddField = document.querySelector('input[name="agregar_movimiento"]');
                const existingEditField = document.querySelector('input[name="editar_movimiento"]');
                if (existingAddField) existingAddField.remove();
                if (existingEditField) existingEditField.remove();
                
                // Agregar campo para editar
                const editField = document.createElement('input');
                editField.type = 'hidden';
                editField.name = 'editar_movimiento';
                editField.value = 'true';
                document.getElementById('formMovimiento').appendChild(editField);
                
                document.getElementById('modalMovimiento').style.display = 'flex';
            } else {
                Swal.fire('Error', 'No se pudieron cargar los datos del movimiento', 'error');
            }
        }
        
        function eliminarMovimiento(id, descripcion) {
            Swal.fire({
                title: '¿Eliminar movimiento?',
                text: `¿Estás seguro de eliminar: "${descripcion}"?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('eliminar_movimiento', 'true');
                    formData.append('id', id);
                    
                    fetch('caja_chica.php', {
                        method: 'POST',
                        body: formData
                    }).then(() => {
                        window.location.reload();
                    });
                }
            });
        }
        
        function abrirModalSalario() {
            document.getElementById('formSalario').reset();
            actualizarSalario(); // Actualizar con valores por defecto
            verificarSaldo(); // Verificar saldo inicial
            document.getElementById('modalSalario').style.display = 'flex';
        }
        
        function actualizarSalario() {
            const select = document.getElementById('empleadoSalario');
            const selectedOption = select.options[select.selectedIndex];
            const salarioBase = selectedOption.getAttribute('data-salario');
            const fechaActual = new Date().toLocaleDateString('es-ES');
            
            if (salarioBase) {
                document.getElementById('montoSalario').value = salarioBase;
                const nombreEmpleado = selectedOption.text.split(' - ')[0];
                document.getElementById('descripcionSalario').value = `Pago de salario ${fechaActual} - ${nombreEmpleado}`;
                verificarSaldo(); // Verificar saldo después de actualizar
            }
        }
        
        function verificarSaldo() {
            const montoSalario = parseFloat(document.getElementById('montoSalario').value) || 0;
            const mensajeSaldo = document.getElementById('mensajeSaldo');
            const btnCobrarSalario = document.getElementById('btnCobrarSalario');
            
            if (montoSalario > saldoActual) {
                mensajeSaldo.innerHTML = `<span style="color: #dc3545;">❌ Fondos insuficientes. Saldo disponible: Q${saldoActual.toFixed(2)}</span>`;
                btnCobrarSalario.disabled = true;
                btnCobrarSalario.style.backgroundColor = '#6c757d';
            } else if (montoSalario > 0) {
                mensajeSaldo.innerHTML = `<span style="color: #28a745;">✅ Fondos suficientes. Saldo disponible: Q${saldoActual.toFixed(2)}</span>`;
                btnCobrarSalario.disabled = false;
                btnCobrarSalario.style.backgroundColor = '';
            } else {
                mensajeSaldo.innerHTML = '';
                btnCobrarSalario.disabled = false;
                btnCobrarSalario.style.backgroundColor = '';
            }
        }
        
        function abrirModalHistorial() {
            document.getElementById('modalHistorial').style.display = 'flex';
        }
        
        function cerrarModalMovimiento() {
            document.getElementById('modalMovimiento').style.display = 'none';
        }
        
        function cerrarModalSalario() {
            document.getElementById('modalSalario').style.display = 'none';
        }
        
        function cerrarModalHistorial() {
            document.getElementById('modalHistorial').style.display = 'none';
        }
        
        function filtrarMovimientos() {
            const searchTerm = document.getElementById('searchMovimiento').value.toLowerCase();
            const filas = document.querySelectorAll('#tablaMovimientos tbody tr');
            
            filas.forEach(fila => {
                const descripcion = fila.getAttribute('data-descripcion');
                const coincide = descripcion.includes(searchTerm);
                fila.style.display = coincide ? '' : 'none';
            });
        }
        
        // Cerrar modales al hacer clic fuera
        document.getElementById('modalMovimiento').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarModalMovimiento();
            }
        });
        
        document.getElementById('modalSalario').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarModalSalario();
            }
        });
        
        <?php if ($puede_editar): ?>
        document.getElementById('modalHistorial').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarModalHistorial();
            }
        });
        <?php endif; ?>

        // Inicializar salario al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            actualizarSalario();
        });
    </script>
</body>
</html>