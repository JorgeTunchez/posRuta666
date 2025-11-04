<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

include 'conexion.php';
include 'config_hora.php';

// Obtener datos del usuario actual
$sql_usuario = "SELECT u.*, e.nombre as empleado_nombre, e.email as empleado_email, 
                       e.telefono, e.direccion 
                FROM usuarios u 
                JOIN empleados e ON u.id_empleado = e.id 
                WHERE u.id = ?";
$stmt = $conn->prepare($sql_usuario);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$usuario = $result->fetch_assoc();

// Procesar actualización
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['actualizar_perfil'])) {
    $nombre = trim($_POST['nombre']);
    $email = trim($_POST['email']);
    $telefono = trim($_POST['telefono']);
    $direccion = trim($_POST['direccion']);
    $password_actual = $_POST['password_actual'];
    $nueva_password = $_POST['nueva_password'];
    $confirmar_password = $_POST['confirmar_password'];
    
    // Verificar password actual si se quiere cambiar
    if (!empty($nueva_password)) {
        if (empty($password_actual)) {
            $mensaje_error = "Debe ingresar la contraseña actual";
        } elseif (hash('sha256', $password_actual) !== $usuario['password']) {
            $mensaje_error = "La contraseña actual es incorrecta";
        } elseif ($nueva_password !== $confirmar_password) {
            $mensaje_error = "Las nuevas contraseñas no coinciden";
        } else {
            // Actualizar con nueva contraseña
            $nueva_password_hash = hash('sha256', $nueva_password);
            $sql_update = "UPDATE usuarios SET password = ? WHERE id = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("si", $nueva_password_hash, $_SESSION['user_id']);
            $stmt_update->execute();
        }
    }
    
    // Actualizar datos del empleado
    if (!isset($mensaje_error)) {
        $sql_empleado = "UPDATE empleados SET nombre = ?, email = ?, telefono = ?, direccion = ? WHERE id = ?";
        $stmt_empleado = $conn->prepare($sql_empleado);
        $stmt_empleado->bind_param("ssssi", $nombre, $email, $telefono, $direccion, $usuario['id_empleado']);
        
        if ($stmt_empleado->execute()) {
            $_SESSION['user_name'] = $nombre;
            $mensaje_exito = "Perfil actualizado correctamente";
            // Recargar datos
            $stmt->execute();
            $result = $stmt->get_result();
            $usuario = $result->fetch_assoc();
        } else {
            $mensaje_error = "Error al actualizar el perfil: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ruta 666 - Mi Perfil</title>
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
        
        /* Paneles */
        .panel {
            background-color: var(--dark-bg);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .panel-header {
            color: var(--accent);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #333;
        }
        
        .info-text {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 15px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        /* Acción única centrada */
        .single-action {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }

        .action-card {
            background: var(--dark-bg);
            border-radius: 10px;
            padding: 25px;
            text-align: center;
            border: 1px solid #333;
            transition: all 0.3s;
            max-width: 300px;
            width: 100%;
        }

        .action-card:hover {
            border-color: var(--accent);
            transform: translateY(-5px);
        }

        .action-icon {
            font-size: 32px;
            margin-bottom: 15px;
        }

        .action-title {
            color: var(--accent);
            margin-bottom: 10px;
            font-weight: bold;
            font-size: 18px;
        }

        .action-description {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.4;
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
            <a href="editar_perfil.php" class="menu-item active">Mi Perfil</a>
        </div>
        
        <div class="user-info">
            <strong><?php echo $_SESSION['user_name']; ?></strong>
            <small><?php echo ucfirst($_SESSION['user_role']); ?></small>
            <a href="logout.php" class="btn btn-sm">Cerrar Sesión</a>
        </div>
    </div>

    <div class="content">
        <div class="header">
            <h1>Mi Perfil</h1>
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

        <!-- Acción única centrada -->
        <div class="single-action">
            <div class="action-card">
                <div class="action-icon">💰</div>
                <div class="action-title">Mis Salarios</div>
                <div class="action-description">Consulta tu historial completo de pagos, salarios y recibos</div>
                <a href="salarios.php" class="btn btn-info" style="width: 100%; padding: 12px; font-size: 16px;">
                    Ver Historial de Salarios
                </a>
            </div>
        </div>

        <div style="max-width: 600px; margin: 0 auto;">
            <form method="POST">
                <input type="hidden" name="actualizar_perfil" value="true">
                
                <!-- Información Personal -->
                <div class="panel">
                    <h3 class="panel-header">Información Personal</h3>
                    
                    <div class="form-group">
                        <label>Nombre Completo *</label>
                        <input type="text" name="nombre" value="<?php echo htmlspecialchars($usuario['empleado_nombre']); ?>" required class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($usuario['empleado_email'] ?? ''); ?>" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="text" name="telefono" value="<?php echo htmlspecialchars($usuario['telefono'] ?? ''); ?>" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label>Dirección</label>
                        <textarea name="direccion" class="form-control"><?php echo htmlspecialchars($usuario['direccion'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Cambiar Contraseña -->
                <div class="panel">
                    <h3 class="panel-header">Cambiar Contraseña</h3>
                    <div class="info-text">
                        Solo complete estos campos si desea cambiar su contraseña
                    </div>
                    
                    <div class="form-group">
                        <label>Contraseña Actual</label>
                        <input type="password" name="password_actual" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label>Nueva Contraseña</label>
                        <input type="password" name="nueva_password" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label>Confirmar Nueva Contraseña</label>
                        <input type="password" name="confirmar_password" class="form-control">
                    </div>
                </div>

                <!-- Botones de acción -->
                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="window.history.back()" style="flex: 1;">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-success" style="flex: 1;">
                        Actualizar Perfil
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Validación del formulario
        document.querySelector('form').addEventListener('submit', function(e) {
            const nuevaPassword = document.querySelector('input[name="nueva_password"]').value;
            const confirmarPassword = document.querySelector('input[name="confirmar_password"]').value;
            const passwordActual = document.querySelector('input[name="password_actual"]').value;
            
            // Si se llena alguna contraseña, validar que se llenen todas
            if (nuevaPassword || confirmarPassword || passwordActual) {
                if (!passwordActual) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Debe ingresar la contraseña actual para cambiar la contraseña'
                    });
                    return;
                }
                
                if (nuevaPassword !== confirmarPassword) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Las nuevas contraseñas no coinciden'
                    });
                    return;
                }
            }
            
            // Confirmación antes de actualizar
            e.preventDefault();
            Swal.fire({
                title: '¿Actualizar perfil?',
                text: 'Se guardarán los cambios realizados',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, actualizar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    this.submit();
                }
            });
        });

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

        // Efectos para la tarjeta de acción
        document.querySelector('.action-card').addEventListener('mouseenter', function() {
            this.style.boxShadow = '0 8px 25px rgba(255, 0, 0, 0.3)';
        });
        
        document.querySelector('.action-card').addEventListener('mouseleave', function() {
            this.style.boxShadow = 'none';
        });
    </script>
</body>
</html>