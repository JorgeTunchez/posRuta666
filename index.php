<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include 'config_hora.php';
include 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $usuario = $_POST['usuario'];
    $password = hash('sha256', $_POST['password']);
    
    $sql = "SELECT u.*, e.nombre as empleado_nombre 
            FROM usuarios u 
            INNER JOIN empleados e ON u.id_empleado = e.id 
            WHERE u.usuario = ? AND u.password = ? AND u.activo = 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $usuario, $password);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['empleado_nombre'];
        $_SESSION['user_role'] = $user['rol'];
        
        // Redireccionar según el rol
        switch ($user['rol']) {
            case 'administrador':
                header("Location: dashboard_admin.php");
                break;
            case 'gerente':
                header("Location: dashboard_gerente.php");
                break;
            case 'bar_tender':
                header("Location: dashboard_bar_tender.php");
                break;
            default:
                header("Location: dashboard_admin.php");
        }
        exit();
    } else {
        echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Usuario o contraseña incorrectos'
                });
              </script>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ruta 666 - Login</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Metal+Mania&display=swap" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.7)), url('https://images.unsplash.com/photo-1516450360452-9312f5e86fc7?ixlib=rb-1.2.1&auto=format&fit=crop&w=1350&q=80');
            background-size: cover;
            font-family: 'Arial', sans-serif;
            color: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-container {
            background: rgba(20, 20, 20, 0.9);
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(255, 0, 0, 0.5);
            width: 350px;
            text-align: center;
            border: 1px solid #ff0000;
        }
        .logo {
            font-family: 'Metal Mania', cursive;
            font-size: 36px;
            color: #ff0000;
            margin-bottom: 20px;
            text-shadow: 0 0 10px rgba(255, 0, 0, 0.7);
        }
        input {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: none;
            border-radius: 5px;
            background: #333;
            color: #fff;
        }
        button {
            width: 100%;
            padding: 10px;
            background: #ff0000;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            margin-top: 10px;
        }
        button:hover {
            background: #cc0000;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">RUTA 666</div>
        <form method="POST" action="">
            <input type="text" name="usuario" placeholder="Usuario" required>
            <input type="password" name="password" placeholder="Contraseña" required>
            <button type="submit">Entrar</button>
        </form>
    </div>
</body>
</html>