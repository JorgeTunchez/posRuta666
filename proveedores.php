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

// En la sección de procesamiento POST, verifica que esté correcto:
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Agregar proveedor
    if (isset($_POST['agregar_proveedor'])) {
        $nombre = trim($_POST['nombre']);
        $contacto = trim($_POST['contacto']);
        $telefono = trim($_POST['telefono']);
        $email = trim($_POST['email']);
        $direccion = trim($_POST['direccion']);
        
        $sql = "INSERT INTO proveedores (nombre, contacto, telefono, email, direccion) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssss", $nombre, $contacto, $telefono, $email, $direccion);
        
        if ($stmt->execute()) {
            $mensaje_exito = "Proveedor agregado correctamente";
        } else {
            $mensaje_error = "Error al agregar proveedor: " . $conn->error;
        }
    }
    
    // Editar proveedor
    if (isset($_POST['editar_proveedor'])) {
        $id = intval($_POST['id']);
        $nombre = trim($_POST['nombre']);
        $contacto = trim($_POST['contacto']);
        $telefono = trim($_POST['telefono']);
        $email = trim($_POST['email']);
        $direccion = trim($_POST['direccion']);
        
        $sql = "UPDATE proveedores SET nombre=?, contacto=?, telefono=?, email=?, direccion=? 
                WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssi", $nombre, $contacto, $telefono, $email, $direccion, $id);
        
        if ($stmt->execute()) {
            $mensaje_exito = "Proveedor actualizado correctamente";
        } else {
            $mensaje_error = "Error al actualizar proveedor: " . $conn->error;
        }
    }
    
    // Eliminar proveedor (solo admin y gerente)
    if (isset($_POST['eliminar_proveedor']) && $puede_eliminar) {
        $id = intval($_POST['id']);
        
        // Verificar si el proveedor tiene productos asociados
        $sql_verificar = "SELECT COUNT(*) as total FROM productos WHERE id_proveedor = ?";
        $stmt_verificar = $conn->prepare($sql_verificar);
        $stmt_verificar->bind_param("i", $id);
        $stmt_verificar->execute();
        $result_verificar = $stmt_verificar->get_result();
        $verificar = $result_verificar->fetch_assoc();
        
        if ($verificar['total'] > 0) {
            $mensaje_error = "No se puede eliminar el proveedor porque tiene productos asociados";
        } else {
            $sql = "DELETE FROM proveedores WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $mensaje_exito = "Proveedor eliminado correctamente";
            } else {
                $mensaje_error = "Error al eliminar proveedor: " . $conn->error;
            }
        }
    }
}

// Obtener total de proveedores para paginación
$sql_total = "SELECT COUNT(*) as total FROM proveedores";
$result_total = $conn->query($sql_total);
$total_proveedores = $result_total->fetch_assoc()['total'];
$total_paginas = ceil($total_proveedores / $registros_por_pagina);

// Obtener proveedores con paginación
$sql_proveedores = "SELECT * FROM proveedores ORDER BY nombre LIMIT ? OFFSET ?";
$stmt_proveedores = $conn->prepare($sql_proveedores);
$stmt_proveedores->bind_param("ii", $registros_por_pagina, $offset);
$stmt_proveedores->execute();
$result_proveedores = $stmt_proveedores->get_result();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ruta 666 - Proveedores</title>
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
        
        .btn-whatsapp {
            background-color: #25D366;
            color: white;
        }
        
        .btn-whatsapp:hover {
            background-color: #128C7E;
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
        
        /* Paginación */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 20px;
            gap: 10px;
        }
        
        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid #444;
            border-radius: 5px;
            text-decoration: none;
            color: var(--text);
            transition: all 0.3s;
        }
        
        .pagination a:hover {
            background-color: var(--accent);
            border-color: var(--accent);
        }
        
        .pagination .current {
            background-color: var(--accent);
            border-color: var(--accent);
        }
        
        .pagination .disabled {
            color: #666;
            cursor: not-allowed;
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
            
            .pagination {
                flex-wrap: wrap;
            }
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
            <a href="proveedores.php" class="menu-item active">Proveedores</a>
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
            <h1>Gestión de Proveedores</h1>
            <button class="btn btn-success" onclick="abrirModalAgregar()">Agregar Proveedor</button>
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
                <div class="stat-number"><?php echo $total_proveedores; ?></div>
                <div class="stat-label">Total Proveedores</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">
                    <?php 
                    $sql_activos = "SELECT COUNT(DISTINCT id_proveedor) as total FROM productos WHERE id_proveedor IS NOT NULL";
                    $result_activos = $conn->query($sql_activos);
                    echo $result_activos->fetch_assoc()['total'];
                    ?>
                </div>
                <div class="stat-label">Proveedores Activos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">
                    <?php 
                    $sql_nuevos = "SELECT COUNT(*) as total FROM proveedores WHERE DATE(created_at) = CURDATE()";
                    $result_nuevos = $conn->query($sql_nuevos);
                    echo $result_nuevos->fetch_assoc()['total'];
                    ?>
                </div>
                <div class="stat-label">Nuevos Hoy</div>
            </div>
        </div>

        <!-- Búsqueda -->
        <div class="search-box">
            <input type="text" id="searchProveedor" placeholder="🔍 Buscar proveedor por nombre, contacto o teléfono..." onkeyup="filtrarProveedores()">
        </div>

        <div class="table-container">
            <div class="table-responsive">
                <table id="tablaProveedores">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Contacto</th>
                            <th>Teléfono</th>
                            <th>Email</th>
                            <th>Dirección</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
    <?php if ($result_proveedores && $result_proveedores->num_rows > 0): ?>
        <?php while ($proveedor = $result_proveedores->fetch_assoc()): ?>
            <tr data-id="<?php echo $proveedor['id']; ?>" 
                data-nombre="<?php echo htmlspecialchars(strtolower($proveedor['nombre'])); ?>" 
                data-contacto="<?php echo htmlspecialchars(strtolower($proveedor['contacto'] ?? '')); ?>"
                data-telefono="<?php echo htmlspecialchars(strtolower($proveedor['telefono'] ?? '')); ?>">
                <td>
                    <strong><?php echo htmlspecialchars($proveedor['nombre']); ?></strong>
                </td>
                <td>
                    <?php echo htmlspecialchars($proveedor['contacto'] ?? 'N/A'); ?>
                </td>
                <td>
                    <?php if ($proveedor['telefono']): ?>
                        📞 <?php echo htmlspecialchars($proveedor['telefono']); ?>
                    <?php else: ?>
                        N/A
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($proveedor['email']): ?>
                        ✉️ <?php echo htmlspecialchars($proveedor['email']); ?>
                    <?php else: ?>
                        N/A
                    <?php endif; ?>
                </td>
                <td>
                    <?php echo htmlspecialchars($proveedor['direccion'] ?? 'N/A'); ?>
                </td>
                <td>
                    <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                        <?php if ($proveedor['telefono']): ?>
                            <button class="btn btn-whatsapp btn-sm" onclick="hacerPedidoWhatsApp('<?php echo htmlspecialchars($proveedor['telefono']); ?>', '<?php echo htmlspecialchars($proveedor['nombre']); ?>')">
                                📱 Pedido
                            </button>
                        <?php endif; ?>
                        <button class="btn btn-primary btn-sm" onclick="editarProveedor(<?php echo $proveedor['id']; ?>)">
                            Editar
                        </button>
                        <?php if ($puede_eliminar): ?>
                            <button class="btn btn-danger btn-sm" onclick="eliminarProveedor(<?php echo $proveedor['id']; ?>, '<?php echo htmlspecialchars($proveedor['nombre']); ?>')">
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
                No hay proveedores registrados
            </td>
        </tr>
    <?php endif; ?>
</tbody>
                </table>
            </div>
        </div>

        <!-- Paginación -->
        <?php if ($total_paginas > 1): ?>
        <div class="pagination">
            <?php if ($pagina_actual > 1): ?>
                <a href="?pagina=1">« Primera</a>
                <a href="?pagina=<?php echo $pagina_actual - 1; ?>">‹ Anterior</a>
            <?php else: ?>
                <span class="disabled">« Primera</span>
                <span class="disabled">‹ Anterior</span>
            <?php endif; ?>

            <?php
            // Mostrar números de página
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
                <a href="?pagina=<?php echo $pagina_actual + 1; ?>">Siguiente ›</a>
                <a href="?pagina=<?php echo $total_paginas; ?>">Última »</a>
            <?php else: ?>
                <span class="disabled">Siguiente ›</span>
                <span class="disabled">Última »</span>
            <?php endif; ?>
        </div>
        
        <div style="text-align: center; margin-top: 10px; color: var(--text-secondary);">
            Mostrando <?php echo min($registros_por_pagina, $result_proveedores->num_rows); ?> de <?php echo $total_proveedores; ?> proveedores
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal para agregar/editar proveedor -->
<div class="modal" id="modalProveedor">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitulo">Agregar Proveedor</h3>
            <button class="modal-close" onclick="cerrarModal()">&times;</button>
        </div>
        <form id="formProveedor" method="POST">
            <input type="hidden" name="id" id="proveedorId">
            
            <div class="form-group">
                <label>Nombre *</label>
                <input type="text" name="nombre" id="proveedorNombre" required class="form-control">
            </div>
            
            <div class="form-group">
                <label>Persona de Contacto</label>
                <input type="text" name="contacto" id="proveedorContacto" class="form-control">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Teléfono</label>
                    <input type="text" name="telefono" id="proveedorTelefono" class="form-control" placeholder="Ej: 50212345678">
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="proveedorEmail" class="form-control">
                </div>
            </div>
            
            <div class="form-group">
                <label>Dirección</label>
                <textarea name="direccion" id="proveedorDireccion" class="form-control" style="height: 80px;"></textarea>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-danger" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" class="btn btn-success" id="btnGuardarProveedor">Guardar</button>
            </div>
        </form>
    </div>
</div>

    <script>
        function abrirModalAgregar() {
    document.getElementById('modalTitulo').textContent = 'Agregar Proveedor';
    document.getElementById('btnGuardarProveedor').textContent = 'Guardar';
    document.getElementById('formProveedor').reset();
    document.getElementById('proveedorId').value = '';
    
    // Remover cualquier campo oculto de edición
    const existingEditField = document.querySelector('input[name="editar_proveedor"]');
    if (existingEditField) {
        existingEditField.remove();
    }
    
    // Asegurar que existe el campo para agregar
    if (!document.querySelector('input[name="agregar_proveedor"]')) {
        const addField = document.createElement('input');
        addField.type = 'hidden';
        addField.name = 'agregar_proveedor';
        addField.value = 'true';
        document.getElementById('formProveedor').appendChild(addField);
    }
    
    document.getElementById('modalProveedor').style.display = 'flex';
}

function editarProveedor(id) {
    console.log('Editando proveedor ID:', id);
    
    // Buscar la fila específica del proveedor usando data-id
    const fila = document.querySelector(`tr[data-id="${id}"]`);
    
    if (fila) {
        const tds = fila.querySelectorAll('td');
        
        // Extraer datos de cada columna
        const nombre = tds[0].querySelector('strong').textContent.trim();
        const contacto = tds[1].textContent.trim();
        let telefono = tds[2].textContent.trim();
        let email = tds[3].textContent.trim();
        const direccion = tds[4].textContent.trim();
        
        // Limpiar los datos (remover emojis y "N/A")
        telefono = telefono.replace('📞', '').trim();
        if (telefono === 'N/A') telefono = '';
        
        email = email.replace('✉️', '').trim();
        if (email === 'N/A') email = '';
        
        console.log('Datos extraídos:', { nombre, contacto, telefono, email, direccion });
        
        // Configurar el formulario para edición
        document.getElementById('modalTitulo').textContent = 'Editar Proveedor';
        document.getElementById('btnGuardarProveedor').textContent = 'Actualizar';
        document.getElementById('proveedorId').value = id;
        document.getElementById('proveedorNombre').value = nombre;
        document.getElementById('proveedorContacto').value = contacto !== 'N/A' ? contacto : '';
        document.getElementById('proveedorTelefono').value = telefono;
        document.getElementById('proveedorEmail').value = email;
        document.getElementById('proveedorDireccion').value = direccion !== 'N/A' ? direccion : '';
        
        // Remover cualquier campo oculto de agregar
        const existingAddField = document.querySelector('input[name="agregar_proveedor"]');
        if (existingAddField) {
            existingAddField.remove();
        }
        
        // Asegurar que existe el campo para editar
        if (!document.querySelector('input[name="editar_proveedor"]')) {
            const editField = document.createElement('input');
            editField.type = 'hidden';
            editField.name = 'editar_proveedor';
            editField.value = 'true';
            document.getElementById('formProveedor').appendChild(editField);
        }
        
        document.getElementById('modalProveedor').style.display = 'flex';
    } else {
        console.error('No se encontró la fila para el ID:', id);
        Swal.fire('Error', 'No se pudo cargar la información del proveedor', 'error');
    }
}

function eliminarProveedor(id, nombre) {
    Swal.fire({
        title: '¿Eliminar proveedor?',
        text: `¿Estás seguro de eliminar a "${nombre}"? Esta acción no se puede deshacer.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('eliminar_proveedor', 'true');
            formData.append('id', id);
            
            fetch('proveedores.php', {
                method: 'POST',
                body: formData
            }).then(() => {
                window.location.reload();
            });
        }
    });
}

function hacerPedidoWhatsApp(telefono, nombreProveedor) {
    // Limpiar el teléfono (remover espacios, guiones, etc.)
    const telefonoLimpio = telefono.replace(/\s+/g, '').replace(/[-\/]/g, '');
    
    // Verificar si el teléfono ya tiene el código de país
    let numeroWhatsApp;
    if (telefonoLimpio.startsWith('502')) {
        numeroWhatsApp = telefonoLimpio;
    } else if (telefonoLimpio.startsWith('+502')) {
        numeroWhatsApp = telefonoLimpio.substring(1); // Remover el +
    } else {
        numeroWhatsApp = '502' + telefonoLimpio;
    }
    
    // Crear mensaje predeterminado
    const mensaje = encodeURIComponent(`Hola, me gustaría hacer un pedido para el negocio Ruta 666, Ubicado en 12 Calle 8-61 zona 1`);
    
    // Abrir WhatsApp en nueva ventana
    const url = `https://wa.me/${numeroWhatsApp}?text=${mensaje}`;
    window.open(url, '_blank');
}

function cerrarModal() {
    document.getElementById('modalProveedor').style.display = 'none';
    // Limpiar campos ocultos al cerrar
    const addField = document.querySelector('input[name="agregar_proveedor"]');
    const editField = document.querySelector('input[name="editar_proveedor"]');
    if (addField) addField.remove();
    if (editField) editField.remove();
}

function filtrarProveedores() {
    const searchTerm = document.getElementById('searchProveedor').value.toLowerCase();
    const filas = document.querySelectorAll('#tablaProveedores tbody tr');
    
    filas.forEach(fila => {
        const nombre = fila.getAttribute('data-nombre');
        const contacto = fila.getAttribute('data-contacto');
        const telefono = fila.getAttribute('data-telefono');
        
        const coincide = nombre.includes(searchTerm) || 
                       contacto.includes(searchTerm) || 
                       telefono.includes(searchTerm);
        
        fila.style.display = coincide ? '' : 'none';
    });
}

// Cerrar modal al hacer clic fuera
document.getElementById('modalProveedor').addEventListener('click', function(e) {
    if (e.target === this) {
        cerrarModal();
    }
});
    </script>
</body>
</html>