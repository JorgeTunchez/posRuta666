<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

include 'conexion.php';
include 'config_hora.php';

// Verificar permisos según rol
$puede_eliminar = ($_SESSION['user_role'] == 'administrador' || $_SESSION['user_role'] == 'gerente');

// Configuración de paginación
$registros_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Procesar operaciones
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Agregar o editar cliente
    if (isset($_POST['accion'])) {
        $nombre = trim($_POST['nombre']);
        $telefono = trim($_POST['telefono']);
        $email = trim($_POST['email']);
        
        if ($_POST['accion'] == 'agregar') {
            // AGREGAR NUEVO CLIENTE
            $sql = "INSERT INTO clientes (nombre, telefono, email, visitas) 
                    VALUES (?, ?, ?, 0)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sss", $nombre, $telefono, $email);
            
            if ($stmt->execute()) {
                $mensaje_exito = "Cliente agregado correctamente";
            } else {
                $mensaje_error = "Error al agregar cliente: " . $conn->error;
            }
        } 
        elseif ($_POST['accion'] == 'editar') {
            // EDITAR CLIENTE EXISTENTE
            $id = intval($_POST['id']);
            
            $sql = "UPDATE clientes SET nombre = ?, telefono = ?, email = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssi", $nombre, $telefono, $email, $id);
            
            if ($stmt->execute()) {
                $mensaje_exito = "Cliente actualizado correctamente";
            } else {
                $mensaje_error = "Error al actualizar cliente: " . $conn->error;
            }
        }
    }
    
    // Eliminar cliente (solo admin y gerente)
    if (isset($_POST['eliminar_cliente']) && $puede_eliminar) {
        $id = intval($_POST['id']);
        
        // Verificar si el cliente tiene ventas asociadas
        $sql_verificar = "SELECT COUNT(*) as total FROM ventas WHERE id_cliente = ?";
        $stmt_verificar = $conn->prepare($sql_verificar);
        $stmt_verificar->bind_param("i", $id);
        $stmt_verificar->execute();
        $result_verificar = $stmt_verificar->get_result();
        $verificar = $result_verificar->fetch_assoc();
        
        if ($verificar['total'] > 0) {
            $mensaje_error = "No se puede eliminar el cliente porque tiene ventas asociadas";
        } else {
            $sql = "DELETE FROM clientes WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $mensaje_exito = "Cliente eliminado correctamente";
            } else {
                $mensaje_error = "Error al eliminar cliente: " . $conn->error;
            }
        }
    }

    // ... (el resto del código de procesamiento se mantiene igual)
}

// Obtener total de clientes para paginación
$sql_total = "SELECT COUNT(*) as total FROM clientes";
$result_total = $conn->query($sql_total);
$total_clientes = $result_total->fetch_assoc()['total'];
$total_paginas = ceil($total_clientes / $registros_por_pagina);

// Obtener clientes con información de crédito y saldo pendiente - CON PAGINACIÓN
$sql_clientes = "SELECT 
    c.*,
    COALESCE(cc.credito_disponible, 0) as credito_disponible,
    COALESCE(cc.credito_limite, 0) as credito_limite,
    (SELECT COUNT(*) FROM ventas v WHERE v.id_cliente = c.id) as total_ventas,
    (SELECT COALESCE(SUM(v.total), 0) FROM ventas v WHERE v.id_cliente = c.id) as total_compras,
    (SELECT COALESCE(SUM(v.total), 0) FROM ventas v WHERE v.id_cliente = c.id AND v.metodo_pago = 'credito') as total_credito,
    (SELECT COALESCE(SUM(vp.monto), 0) FROM venta_pagos vp 
    JOIN ventas v ON vp.id_venta = v.id 
    WHERE v.id_cliente = c.id AND vp.metodo_pago = 'credito') as total_pagado_credito,
    CASE 
        WHEN cc.credito_disponible IS NULL THEN 'sin_credito'
        WHEN cc.credito_disponible > 0 THEN 'con_credito'
        ELSE 'credito_agotado'
    END as estado_credito
FROM clientes c 
LEFT JOIN creditos_clientes cc ON c.id = cc.id_cliente 
ORDER BY c.nombre
LIMIT ? OFFSET ?";

$stmt_clientes = $conn->prepare($sql_clientes);
$stmt_clientes->bind_param("ii", $registros_por_pagina, $offset);
$stmt_clientes->execute();
$result_clientes = $stmt_clientes->get_result();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ruta 666 - CRM Clientes</title>
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
            }
            
            .btn:hover {
                background-color: #cc0000;
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
                color: var(--accent);
                margin: 10px 0;
            }
            
            .stat-label {
                color: var(--text-secondary);
                font-size: 0.9em;
            }
            
            .credito-disponible {
                color: #28a745;
                font-weight: bold;
            }
            
            .credito-agotado {
                color: #dc3545;
                font-weight: bold;
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

        /* Estilos para paginación */
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 5px;
        }
        
        .pagination a, .pagination span {
            padding: 8px 12px;
            background: var(--dark-bg);
            border: 1px solid #333;
            border-radius: 5px;
            color: var(--text);
            text-decoration: none;
        }
        
        .pagination a:hover {
            background: #333;
            border-color: var(--accent);
        }
        
        .pagination .current {
            background: var(--accent);
            border-color: var(--accent);
        }
        
        .pagination-info {
            text-align: center;
            margin-top: 10px;
            color: var(--text-secondary);
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
            <a href="clientes.php" class="menu-item active">CRM</a>
            <!-- AGREGAR ENLACES FALTANTES -->
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
            <h1>CRM - Gestión de Clientes</h1>
            <button class="btn btn-success" onclick="abrirModalAgregar()">Agregar Cliente</button>
        </div>

        <?php if (isset($mensaje_exito)): ?>
            <script>Swal.fire('Éxito', '<?php echo $mensaje_exito; ?>', 'success');</script>
        <?php endif; ?>
        
        <?php if (isset($mensaje_error)): ?>
            <script>Swal.fire('Error', '<?php echo $mensaje_error; ?>', 'error');</script>
        <?php endif; ?>

        <!-- Estadísticas -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_clientes; ?></div>
                <div class="stat-label">Total Clientes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">
                    <?php 
                    $sql_nuevos = "SELECT COUNT(*) as total FROM clientes WHERE DATE(created_at) = CURDATE()";
                    $result_nuevos = $conn->query($sql_nuevos);
                    echo $result_nuevos->fetch_assoc()['total'];
                    ?>
                </div>
                <div class="stat-label">Clientes Nuevos Hoy</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">
                    <?php 
                    $sql_credito = "SELECT COUNT(*) as total FROM creditos_clientes WHERE credito_disponible > 0";
                    $result_credito = $conn->query($sql_credito);
                    echo $result_credito->fetch_assoc()['total'];
                    ?>
                </div>
                <div class="stat-label">Con Crédito Activo</div>
            </div>
        </div>

        <!-- Búsqueda -->
        <div class="search-box">
            <input type="text" id="searchCliente" placeholder="🔍 Buscar cliente por nombre, teléfono o email..." onkeyup="filtrarClientes()">
        </div>

        <div class="table-container">
            <div class="table-responsive">
                <table id="tablaClientes">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Contacto</th>
                            <th>Visitas</th>
                            <th>Crédito</th>
                            <th>Total Compras</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_clientes && $result_clientes->num_rows > 0): ?>
                            <?php while ($cliente = $result_clientes->fetch_assoc()): ?>
                                <?php 
                                // Calcular saldo pendiente
                                $total_credito = floatval($cliente['total_credito']);
                                $total_pagado_credito = floatval($cliente['total_pagado_credito']);
                                $saldo_pendiente = $total_credito - $total_pagado_credito;
                                
                                // Verificar si el cliente tiene registro en creditos_clientes
                                $tiene_registro_credito = ($cliente['estado_credito'] != 'sin_credito');
                                $credito_disponible = floatval($cliente['credito_disponible']);
                                $credito_limite = floatval($cliente['credito_limite']);
                                
                                // Preparar teléfono para WhatsApp
                                $telefono_whatsapp = '';
                                if (!empty($cliente['telefono'])) {
                                    $telefono_limpio = preg_replace('/[^0-9]/', '', $cliente['telefono']);
                                    $telefono_whatsapp = 'https://wa.me/+502' . $telefono_limpio;
                                }
                                ?>
                                <tr data-id="<?php echo $cliente['id']; ?>" 
                                    data-nombre="<?php echo htmlspecialchars(strtolower($cliente['nombre'])); ?>" 
                                    data-telefono="<?php echo htmlspecialchars(strtolower($cliente['telefono'] ?? '')); ?>"
                                    data-email="<?php echo htmlspecialchars(strtolower($cliente['email'] ?? '')); ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($cliente['nombre']); ?></strong>
                                        <?php if ($cliente['ultima_visita']): ?>
                                            <br><small>Última visita: <?php echo date('d/m/Y', strtotime($cliente['ultima_visita'])); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($cliente['telefono']): ?>
                                            📞 <?php echo htmlspecialchars($cliente['telefono']); ?><br>
                                        <?php endif; ?>
                                        <?php if ($cliente['email']): ?>
                                            ✉️ <?php echo htmlspecialchars($cliente['email']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php echo $cliente['visitas']; ?>
                                    </td>
                                    <td>
                                        <?php if ($tiene_registro_credito): ?>
                                            <span class="<?php echo $credito_disponible > 0 ? 'credito-disponible' : 'credito-agotado'; ?>">
                                                Q<?php echo number_format($credito_disponible, 2); ?> / 
                                                Q<?php echo number_format($credito_limite, 2); ?>
                                            </span>
                                            <?php if ($credito_disponible <= 0): ?>
                                                <br><small style="color: #ff6b6b;">Crédito agotado</small>
                                            <?php endif; ?>
                                            
                                            <!-- Mostrar saldo pendiente si existe -->
                                            <?php if ($saldo_pendiente > 0): ?>
                                                <br>
                                                <small style="color: #ff9900; font-weight: bold;">
                                                    ⚠️ Saldo pendiente: Q<?php echo number_format($saldo_pendiente, 2); ?>
                                                </small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <small style="color: #666;">Sin crédito</small>
                                            
                                            <!-- Mostrar saldo pendiente incluso si no tiene crédito activo -->
                                            <?php if ($saldo_pendiente > 0): ?>
                                                <br>
                                                <small style="color: #ff9900; font-weight: bold;">
                                                    ⚠️ Saldo pendiente: Q<?php echo number_format($saldo_pendiente, 2); ?>
                                                </small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        Q<?php echo number_format($cliente['total_compras'], 2); ?>
                                        <br>
                                        <small><?php echo $cliente['total_ventas']; ?> ventas</small>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                            <button class="btn btn-primary btn-sm" onclick="editarCliente(<?php echo $cliente['id']; ?>)">
                                                Editar
                                            </button>
                                            
                                            <!-- Botón para recordatorio de pago por WhatsApp -->
                                            <?php if ($saldo_pendiente > 0 && !empty($cliente['telefono'])): ?>
                                                <button class="btn btn-warning btn-sm" 
                                                        onclick="enviarRecordatorioWhatsApp('<?php echo $telefono_whatsapp; ?>', '<?php echo htmlspecialchars($cliente['nombre']); ?>', <?php echo $saldo_pendiente; ?>)">
                                                    Cobrar
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($_SESSION['user_role'] == 'administrador' || $_SESSION['user_role'] == 'gerente'): ?>
                                                <button class="btn btn-info btn-sm" onclick="asignarCredito(<?php echo $cliente['id']; ?>, <?php echo $cliente['credito_disponible']; ?>, <?php echo $cliente['credito_limite']; ?>)">
                                                    Crédito
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($puede_eliminar): ?>
                                                <button class="btn btn-danger btn-sm" onclick="eliminarCliente(<?php echo $cliente['id']; ?>, '<?php echo htmlspecialchars($cliente['nombre']); ?>')">
                                                    Eliminar
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 20px;">
                                    No hay clientes registrados
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Paginación -->
            <?php if ($total_paginas > 1): ?>
                <div class="pagination-info">
                    Mostrando <?php echo min($registros_por_pagina, $result_clientes->num_rows); ?> de <?php echo $total_clientes; ?> clientes
                </div>
                <div class="pagination">
                    <?php if ($pagina_actual > 1): ?>
                        <a href="?pagina=1">&laquo; Primera</a>
                        <a href="?pagina=<?php echo $pagina_actual - 1; ?>">&lsaquo; Anterior</a>
                    <?php endif; ?>
                    
                    <?php
                    // Mostrar números de páginas
                    $inicio = max(1, $pagina_actual - 2);
                    $fin = min($total_paginas, $pagina_actual + 2);
                    
                    for ($i = $inicio; $i <= $fin; $i++):
                    ?>
                        <?php if ($i == $pagina_actual): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?pagina=<?php echo $i; ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($pagina_actual < $total_paginas): ?>
                        <a href="?pagina=<?php echo $pagina_actual + 1; ?>">Siguiente &rsaquo;</a>
                        <a href="?pagina=<?php echo $total_paginas; ?>">Última &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Los modales se mantienen igual -->
    <!-- Modal para agregar/editar cliente -->
    <div class="modal" id="modalCliente">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitulo">Agregar Cliente</h3>
                <button class="modal-close" onclick="cerrarModalCliente()">&times;</button>
            </div>
            <form id="formCliente" method="POST">
                <input type="hidden" name="id" id="clienteId">
                <input type="hidden" name="accion" id="accionCliente" value="agregar">
                
                <div class="form-group">
                    <label>Nombre Completo *</label>
                    <input type="text" name="nombre" id="clienteNombre" required class="form-control">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="text" name="telefono" id="clienteTelefono" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" id="clienteEmail" class="form-control">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="cerrarModalCliente()">Cancelar</button>
                    <button type="submit" class="btn btn-success" id="btnGuardarCliente">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para asignar crédito -->
    <div class="modal" id="modalCredito">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Asignar Crédito</h3>
                <button class="modal-close" onclick="cerrarModalCredito()">&times;</button>
            </div>
            <form id="formCredito" method="POST">
                <input type="hidden" name="asignar_credito" value="true">
                <input type="hidden" name="id_cliente" id="creditoClienteId">
                
                <div class="form-group">
                    <label>Cliente</label>
                    <input type="text" id="creditoClienteNombre" class="form-control" readonly style="background: #444;">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Crédito Disponible (Q) *</label>
                        <input type="number" name="credito_disponible" id="creditoDisponible" step="0.01" min="0" required class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label>Límite de Crédito (Q) *</label>
                        <input type="number" name="credito_limite" id="creditoLimite" step="0.01" min="0" required class="form-control">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="cerrarModalCredito()">Cancelar</button>
                    <button type="submit" class="btn btn-success">Asignar Crédito</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalAgregar() {
            document.getElementById('modalTitulo').textContent = 'Agregar Cliente';
            document.getElementById('accionCliente').value = 'agregar';
            document.getElementById('btnGuardarCliente').textContent = 'Guardar';
            document.getElementById('formCliente').reset();
            document.getElementById('clienteId').value = '';
            document.getElementById('modalCliente').style.display = 'flex';
        }

        function editarCliente(id) {
            console.log('Editando cliente ID:', id);
            
            const fila = document.querySelector(`tr[data-id="${id}"]`);
            
            if (fila) {
                console.log('Fila encontrada:', fila);
                
                const tds = fila.querySelectorAll('td');
                
                const nombre = tds[0].querySelector('strong').textContent.trim();
                
                const contactoText = tds[1].textContent;
                let telefono = '';
                let email = '';
                
                if (contactoText.includes('📞')) {
                    const telefonoPart = contactoText.split('📞')[1];
                    if (telefonoPart) {
                        telefono = telefonoPart.split('✉️')[0]?.trim() || telefonoPart.trim();
                    }
                }
                
                if (contactoText.includes('✉️')) {
                    const emailPart = contactoText.split('✉️')[1];
                    if (emailPart) {
                        email = emailPart.trim();
                    }
                }
                
                console.log('Datos extraídos:', { nombre, telefono, email });
                
                document.getElementById('modalTitulo').textContent = 'Editar Cliente';
                document.getElementById('accionCliente').value = 'editar';
                document.getElementById('btnGuardarCliente').textContent = 'Actualizar';
                document.getElementById('clienteId').value = id;
                document.getElementById('clienteNombre').value = nombre;
                document.getElementById('clienteTelefono').value = telefono;
                document.getElementById('clienteEmail').value = email;
                document.getElementById('modalCliente').style.display = 'flex';
            } else {
                console.error('No se encontró la fila para el ID:', id);
                Swal.fire('Error', 'No se encontró el cliente en la tabla', 'error');
            }
        }
        
        function asignarCredito(clienteId, creditoDisponible, creditoLimite) {
            const fila = document.querySelector(`tr[data-id="${clienteId}"]`);
            const nombre = fila ? fila.querySelector('strong').textContent : 'Cliente';
            
            document.getElementById('creditoClienteId').value = clienteId;
            document.getElementById('creditoClienteNombre').value = nombre;
            document.getElementById('creditoDisponible').value = creditoDisponible;
            document.getElementById('creditoLimite').value = creditoLimite;
            document.getElementById('modalCredito').style.display = 'flex';
        }
        
        function eliminarCliente(id, nombre) {
            Swal.fire({
                title: '¿Eliminar cliente?',
                text: `¿Estás seguro de eliminar a ${nombre}?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('eliminar_cliente', 'true');
                    formData.append('id', id);
                    
                    fetch('clientes.php', {
                        method: 'POST',
                        body: formData
                    }).then(() => {
                        window.location.reload();
                    });
                }
            });
        }
        
        function enviarRecordatorioWhatsApp(whatsappUrl, nombreCliente, saldoPendiente) {
            const mensaje = `Hola ${nombreCliente}, este es un recordatorio amable de que tienes un saldo pendiente de Q${saldoPendiente.toFixed(2)}. Por favor, realiza el pago lo antes posible. ¡Gracias!`;
            const urlCompleta = `${whatsappUrl}?text=${encodeURIComponent(mensaje)}`;
            
            Swal.fire({
                title: 'Enviar recordatorio',
                text: `¿Deseas enviar un recordatorio de pago a ${nombreCliente} por WhatsApp?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#25D366',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Sí, enviar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.open(urlCompleta, '_blank');
                }
            });
        }
        
        function cerrarModalCliente() {
            document.getElementById('modalCliente').style.display = 'none';
        }
        
        function cerrarModalCredito() {
            document.getElementById('modalCredito').style.display = 'none';
        }
        
        function filtrarClientes() {
            const searchTerm = document.getElementById('searchCliente').value.toLowerCase();
            const filas = document.querySelectorAll('#tablaClientes tbody tr');
            
            filas.forEach(fila => {
                const nombre = fila.getAttribute('data-nombre');
                const telefono = fila.getAttribute('data-telefono');
                const email = fila.getAttribute('data-email');
                
                const coincide = nombre.includes(searchTerm) || 
                            telefono.includes(searchTerm) || 
                            email.includes(searchTerm);
                
                fila.style.display = coincide ? '' : 'none';
            });
        }
        
        // Cerrar modales al hacer clic fuera
        document.getElementById('modalCliente').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarModalCliente();
            }
        });
        
        document.getElementById('modalCredito').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarModalCredito();
            }
        });
    </script>
</body>
</html>