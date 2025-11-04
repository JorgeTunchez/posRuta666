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

// Obtener historial de salarios del usuario actual
$sql_salarios = "SELECT ps.*, 
                        e_pagador.nombre as pagador_nombre,
                        e_receptor.nombre as receptor_nombre
                 FROM pagos_salarios ps
                 JOIN empleados e_pagador ON ps.id_empleado_pago = e_pagador.id
                 JOIN empleados e_receptor ON ps.id_empleado = e_receptor.id
                 WHERE ps.id_empleado = ?
                 ORDER BY ps.fecha_pago DESC, ps.created_at DESC";
$stmt_salarios = $conn->prepare($sql_salarios);
$stmt_salarios->bind_param("i", $_SESSION['user_id']);
$stmt_salarios->execute();
$result_salarios = $stmt_salarios->get_result();

// Si es administrador o gerente, puede ver todos los salarios
if ($puede_editar) {
    $sql_todos_salarios = "SELECT ps.*, 
                                  e_pagador.nombre as pagador_nombre,
                                  e_receptor.nombre as receptor_nombre,
                                  e_receptor.id as receptor_id
                           FROM pagos_salarios ps
                           JOIN empleados e_pagador ON ps.id_empleado_pago = e_pagador.id
                           JOIN empleados e_receptor ON ps.id_empleado = e_receptor.id
                           ORDER BY ps.fecha_pago DESC, ps.created_at DESC";
    $result_todos_salarios = $conn->query($sql_todos_salarios);
}

// Procesar nuevo pago de salario
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['registrar_pago'])) {
    if ($puede_editar) {
        $id_empleado = intval($_POST['id_empleado']);
        $monto = floatval($_POST['monto']);
        $descripcion = trim($_POST['descripcion']);
        $fecha_pago = $_POST['fecha_pago'];
        
        // Insertar pago de salario
        $sql_insert = "INSERT INTO pagos_salarios (id_empleado, monto, descripcion, fecha_pago, id_empleado_pago) 
                       VALUES (?, ?, ?, ?, ?)";
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->bind_param("idssi", $id_empleado, $monto, $descripcion, $fecha_pago, $_SESSION['user_id']);
        
        if ($stmt_insert->execute()) {
            $mensaje_exito = "Pago de salario registrado exitosamente";
            // Recargar datos
            $result_todos_salarios = $conn->query($sql_todos_salarios);
        } else {
            $mensaje_error = "Error al registrar el pago: " . $conn->error;
        }
    }
}

// Obtener empleados para el formulario
if ($puede_editar) {
    $sql_empleados = "SELECT e.id, e.nombre, u.role 
                      FROM empleados e 
                      JOIN usuarios u ON e.id = u.id_empleado 
                      WHERE u.estado = 'activo' 
                      ORDER BY e.nombre";
    $result_empleados = $conn->query($sql_empleados);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ruta 666 - Historial de Salarios</title>
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
            max-width: 500px;
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
        .salarios-container {
            display: flex;
            flex-direction: column;
            gap: 20px;
            height: auto;
        }
        
        /* Paneles */
        .panel {
            background-color: var(--dark-bg);
            border-radius: 10px;
            padding: 20px;
        }
        
        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #333;
        }
        
        .panel-title {
            color: var(--accent);
            margin: 0;
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
        
        textarea.form-control {
            resize: vertical;
            min-height: 80px;
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
        
        .monto-salario {
            font-weight: bold;
            color: #28a745;
        }
        
        .info-box {
            background: var(--dark-bg);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #17a2b8;
        }
        
        .resumen-salarios {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .resumen-item {
            background: #2a2a2a;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .resumen-valor {
            font-size: 24px;
            font-weight: bold;
            color: var(--accent);
            margin: 10px 0;
        }
        
        .resumen-label {
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .badge-role {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .badge-admin {
            background: #dc3545;
            color: white;
        }
        
        .badge-gerente {
            background: #ffc107;
            color: #000;
        }
        
        .badge-bartender {
            background: #17a2b8;
            color: white;
        }
        
        .badge-cajero {
            background: #28a745;
            color: white;
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
            <h1>Historial de Salarios</h1>
            <div style="display: flex; gap: 10px;">
                <?php if ($puede_editar): ?>
                    <button class="btn btn-success" onclick="abrirModalNuevoPago()">Nuevo Pago</button>
                <?php endif; ?>
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

        <div class="salarios-container">
            <!-- Resumen de salarios -->
            <div class="panel">
                <h3 class="panel-title">Resumen de Salarios</h3>
                <div class="resumen-salarios">
                    <div class="resumen-item">
                        <div class="resumen-label">Total Pagado (Este Mes)</div>
                        <div class="resumen-valor">
                            Q<?php
                            $mes_actual = date('Y-m');
                            $sql_total_mes = "SELECT COALESCE(SUM(monto), 0) as total_mes 
                                            FROM pagos_salarios 
                                            WHERE id_empleado = ? 
                                            AND DATE_FORMAT(fecha_pago, '%Y-%m') = ?";
                            $stmt_total_mes = $conn->prepare($sql_total_mes);
                            $stmt_total_mes->bind_param("is", $_SESSION['user_id'], $mes_actual);
                            $stmt_total_mes->execute();
                            $result_total_mes = $stmt_total_mes->get_result();
                            $total_mes = $result_total_mes->fetch_assoc()['total_mes'];
                            echo number_format($total_mes, 2);
                            ?>
                        </div>
                    </div>
                    
                    <div class="resumen-item">
                        <div class="resumen-label">Total Pagado (General)</div>
                        <div class="resumen-valor">
                            Q<?php
                            $sql_total_general = "SELECT COALESCE(SUM(monto), 0) as total_general 
                                                FROM pagos_salarios 
                                                WHERE id_empleado = ?";
                            $stmt_total_general = $conn->prepare($sql_total_general);
                            $stmt_total_general->bind_param("i", $_SESSION['user_id']);
                            $stmt_total_general->execute();
                            $result_total_general = $stmt_total_general->get_result();
                            $total_general = $result_total_general->fetch_assoc()['total_general'];
                            echo number_format($total_general, 2);
                            ?>
                        </div>
                    </div>
                    
                    <div class="resumen-item">
                        <div class="resumen-label">Total de Pagos</div>
                        <div class="resumen-valor">
                            <?php echo $result_salarios->num_rows; ?>
                        </div>
                    </div>
                    
                    <div class="resumen-item">
                        <div class="resumen-label">Último Pago</div>
                        <div class="resumen-valor">
                            <?php
                            if ($result_salarios->num_rows > 0) {
                                $result_salarios->data_seek(0);
                                $ultimo_pago = $result_salarios->fetch_assoc();
                                echo date('d/m/Y', strtotime($ultimo_pago['fecha_pago']));
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Historial personal -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">Mi Historial de Salarios</h3>
                </div>
                
                <div class="table-container">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Fecha de Pago</th>
                                    <th>Monto</th>
                                    <th>Descripción</th>
                                    <th>Pagado por</th>
                                    <th>Fecha de Registro</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result_salarios->num_rows > 0): ?>
                                    <?php $result_salarios->data_seek(0); ?>
                                    <?php while ($salario = $result_salarios->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y', strtotime($salario['fecha_pago'])); ?></td>
                                            <td class="monto-salario">Q<?php echo number_format($salario['monto'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($salario['descripcion']); ?></td>
                                            <td><?php echo htmlspecialchars($salario['pagador_nombre']); ?></td>
                                            <td><?php echo date('d/m/Y H:i', strtotime($salario['created_at'])); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 20px;">
                                            No hay registros de salarios
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Historial completo (solo para administradores/gerentes) -->
            <?php if ($puede_editar && isset($result_todos_salarios)): ?>
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">Historial Completo de Salarios</h3>
                    <small style="color: var(--text-secondary);">Todos los pagos de salarios</small>
                </div>
                
                <div class="table-container">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Empleado</th>
                                    <th>Fecha de Pago</th>
                                    <th>Monto</th>
                                    <th>Descripción</th>
                                    <th>Pagado por</th>
                                    <th>Fecha de Registro</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result_todos_salarios->num_rows > 0): ?>
                                    <?php while ($salario = $result_todos_salarios->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 8px;">
                                                    <?php echo htmlspecialchars($salario['receptor_nombre']); ?>
                                                    <?php if ($salario['receptor_id'] == $_SESSION['user_id']): ?>
                                                        <span class="badge-role badge-info">Tú</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><?php echo date('d/m/Y', strtotime($salario['fecha_pago'])); ?></td>
                                            <td class="monto-salario">Q<?php echo number_format($salario['monto'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($salario['descripcion']); ?></td>
                                            <td><?php echo htmlspecialchars($salario['pagador_nombre']); ?></td>
                                            <td><?php echo date('d/m/Y H:i', strtotime($salario['created_at'])); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 20px;">
                                            No hay registros de salarios
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal para nuevo pago (solo para administradores/gerentes) -->
    <?php if ($puede_editar): ?>
    <div id="modalNuevoPago" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Registrar Nuevo Pago de Salario</h2>
                <span class="close" onclick="cerrarModalNuevoPago()">&times;</span>
            </div>
            
            <form method="POST" id="formNuevoPago">
                <input type="hidden" name="registrar_pago" value="true">
                
                <div class="form-group">
                    <label>Empleado *</label>
                    <select name="id_empleado" required class="form-control">
                        <option value="">Seleccionar empleado</option>
                        <?php while ($empleado = $result_empleados->fetch_assoc()): ?>
                            <option value="<?php echo $empleado['id']; ?>">
                                <?php echo htmlspecialchars($empleado['nombre']); ?> 
                                (<?php echo ucfirst($empleado['role']); ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Monto *</label>
                    <input type="number" name="monto" step="0.01" min="0" required 
                           class="form-control" placeholder="0.00">
                </div>
                
                <div class="form-group">
                    <label>Fecha de Pago *</label>
                    <input type="date" name="fecha_pago" required 
                           class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="form-group">
                    <label>Descripción *</label>
                    <textarea name="descripcion" required class="form-control" 
                              placeholder="Ej: Pago de salario quincenal, bono, etc."></textarea>
                </div>
                
                <div class="form-actions" style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="button" class="btn btn-danger" onclick="cerrarModalNuevoPago()" style="flex: 1;">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-success" style="flex: 1;">
                        Registrar Pago
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function abrirModalNuevoPago() {
            document.getElementById('modalNuevoPago').style.display = 'block';
        }

        function cerrarModalNuevoPago() {
            document.getElementById('modalNuevoPago').style.display = 'none';
            document.getElementById('formNuevoPago').reset();
        }

        // Validar formulario antes de enviar
        document.getElementById('formNuevoPago')?.addEventListener('submit', function(e) {
            const monto = parseFloat(document.querySelector('input[name="monto"]').value);
            
            if (monto <= 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'El monto debe ser mayor a 0'
                });
                return;
            }
            
            // Confirmación antes de registrar
            e.preventDefault();
            const empleadoSelect = document.querySelector('select[name="id_empleado"]');
            const empleadoNombre = empleadoSelect.options[empleadoSelect.selectedIndex].text;
            
            Swal.fire({
                title: '¿Registrar pago de salario?',
                html: `Empleado: <strong>${empleadoNombre}</strong><br>
                       Monto: <strong>Q${monto.toFixed(2)}</strong>`,
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

        // Cerrar modal al hacer clic fuera
        window.onclick = function(event) {
            const modal = document.getElementById('modalNuevoPago');
            if (event.target == modal) {
                cerrarModalNuevoPago();
            }
        }

        // Efectos visuales para los inputs
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', function() {
                this.style.transform = 'scale(1.02)';
                this.style.transition = 'transform 0.2s ease';
            });
            
            input.addEventListener('blur', function() {
                this.style.transform = 'scale(1)';
            });
        });
    </script>
</body>
</html>