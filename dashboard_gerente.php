<?php
session_start();
include 'config_hora.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'gerente') {
    header("Location: index.php");
    exit();
}

include 'conexion.php';
include 'funciones.php';

// Obtener estadísticas para el dashboard
$ventas_hoy = 0;
$productos_stock_bajo = 0;
$clientes_nuevos = 0;
$compras_pendientes = 0;

$sql_ventas = "SELECT SUM(total) as total FROM ventas WHERE DATE(created_at) = CURDATE()";
$result = $conn->query($sql_ventas);
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $ventas_hoy = $row['total'] ? $row['total'] : 0;
}

$sql_stock = "SELECT COUNT(*) as count FROM productos WHERE stock <= stock_minimo";
$result = $conn->query($sql_stock);
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $productos_stock_bajo = $row['count'];
}

$sql_clientes = "SELECT COUNT(*) as count FROM clientes WHERE DATE(created_at) = CURDATE()";
$result = $conn->query($sql_clientes);
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $clientes_nuevos = $row['count'];
}

$sql_compras = "SELECT COUNT(*) as count FROM compras WHERE estado = 'pendiente'";
$result = $conn->query($sql_compras);
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $compras_pendientes = $row['count'];
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ruta 666 - Dashboard Gerente</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    .stats-container {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .stat-card {
        background-color: var(--dark-bg);
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        text-align: center;
        border-left: 4px solid var(--accent);
    }
    .stat-number {
        font-size: 2.5rem;
        font-weight: bold;
        color: var(--accent);
        margin: 10px 0;
    }
    .chart-container {
        background-color: var(--dark-bg);
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 20px;
    }
    .btn {
        padding: 8px 12px;
        background-color: var(--accent);
        color: white;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        font-size: 12px;
        text-align: center;
    }
    .btn:hover {
        background-color: #cc0000;
    }
    .text-center {
        text-align: center;
    }
    
    /* Scrollbar personalizado para el menú */
    .menu-container::-webkit-scrollbar {
        width: 5px;
    }
    .menu-container::-webkit-scrollbar-track {
        background: var(--dark-bg);
    }
    .menu-container::-webkit-scrollbar-thumb {
        background: #444;
        border-radius: 10px;
    }
    .menu-container::-webkit-scrollbar-thumb:hover {
        background: #555;
    }
</style>
</head>
<body>
    <div class="sidebar">
    <div class="logo">RUTA 666</div>
    
    <div class="menu-container">
        <a href="dashboard_admin.php" class="menu-item active">Dashboard</a>
        <a href="ventas.php" class="menu-item">Punto de Venta</a>
        <a href="inventario.php" class="menu-item">Inventario</a>
        <a href="reportes.php" class="menu-item">Reportes</a>
        <a href="empleados.php" class="menu-item">Empleados</a>
        <a href="clientes.php" class="menu-item">CRM</a>
        <a href="proveedores.php" class="menu-item">Proveedores</a>
        <a href="caja_chica.php" class="menu-item">Caja Chica</a>
        <a href="compras.php" class="menu-item">Compras</a>
        <!-- Solo en admin -->
        <a href="configuracion.php" class="menu-item">Configuración</a>
    </div>
    
    <div class="user-info">
        <strong><?php echo $_SESSION['user_name']; ?></strong>
        <small><?php echo ucfirst($_SESSION['user_role']); ?></small>
        <a href="logout.php" class="btn">Cerrar Sesión</a>
    </div>
</div>

    <div class="content">
        <div class="header">
            <h1>Dashboard Gerente</h1>
            <span><?php echo obtenerFechaFormateada(); ?></span>
        </div>

        <div class="stats-container">
            <div class="stat-card">
                <h3>Ventas Hoy</h3>
                <div class="stat-number"><?php echo formato_moneda($ventas_hoy); ?></div>
                <p>Total de ventas del día</p>
            </div>
            <div class="stat-card">
                <h3>Stock Bajo</h3>
                <div class="stat-number"><?php echo $productos_stock_bajo; ?></div>
                <p>Productos que necesitan reposición</p>
            </div>
            <div class="stat-card">
                <h3>Clientes Nuevos</h3>
                <div class="stat-number"><?php echo $clientes_nuevos; ?></div>
                <p>Clientes registrados hoy</p>
            </div>
            <div class="stat-card">
                <h3>Compras Pendientes</h3>
                <div class="stat-number"><?php echo $compras_pendientes; ?></div>
                <p>Órdenes por recibir</p>
            </div>
        </div>

        <div class="chart-container">
            <h3>Ventas de la Semana</h3>
            <canvas id="ventasChart" height="100"></canvas>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
            <div class="chart-container">
                <h3>Productos Más Vendidos</h3>
                <canvas id="productosChart" height="200"></canvas>
            </div>
            <div class="chart-container">
                <h3>Métodos de Pago</h3>
                <canvas id="pagosChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <script>
        // Gráfico de ventas de la semana
        const ventasCtx = document.getElementById('ventasChart').getContext('2d');
        const ventasChart = new Chart(ventasCtx, {
            type: 'line',
            data: {
                labels: ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
                datasets: [{
                    label: 'Ventas Q',
                    data: [12000, 19000, 15000, 22000, 18000, 25000, 21000],
                    borderColor: '#ff0000',
                    tension: 0.1,
                    backgroundColor: 'rgba(255, 0, 0, 0.1)'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        labels: {
                            color: '#fff'
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#ccc'
                        },
                        grid: {
                            color: '#333'
                        }
                    },
                    x: {
                        ticks: {
                            color: '#ccc'
                        },
                        grid: {
                            color: '#333'
                        }
                    }
                }
            }
        });

        // Gráfico de productos más vendidos
        const productosCtx = document.getElementById('productosChart').getContext('2d');
        const productosChart = new Chart(productosCtx, {
            type: 'bar',
            data: {
                labels: ['Cerveza', 'Whisky', 'Hamburguesa', 'Cigarros', 'Playeras'],
                datasets: [{
                    label: 'Unidades Vendidas',
                    data: [120, 85, 75, 60, 40],
                    backgroundColor: '#ff0000'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        labels: {
                            color: '#fff'
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#ccc'
                        },
                        grid: {
                            color: '#333'
                        }
                    },
                    x: {
                        ticks: {
                            color: '#ccc'
                        },
                        grid: {
                            color: '#333'
                        }
                    }
                }
            }
        });

        // Gráfico de métodos de pago
        const pagosCtx = document.getElementById('pagosChart').getContext('2d');
        const pagosChart = new Chart(pagosCtx, {
            type: 'doughnut',
            data: {
                labels: ['Efectivo', 'Tarjeta', 'Transferencia', 'Billetera Móvil', 'Crédito'],
                datasets: [{
                    data: [45, 25, 15, 10, 5],
                    backgroundColor: [
                        '#ff0000',
                        '#cc0000',
                        '#990000',
                        '#660000',
                        '#330000'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#fff'
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>