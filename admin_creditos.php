<?php
session_start();
include 'config_hora.php';
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'administrador') {
    header("Location: index.php");
    exit();
}

include 'conexion.php';

// Procesar asignación de crédito
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['asignar_credito'])) {
    $cliente_id = intval($_POST['cliente_id']);
    $monto_credito = floatval($_POST['monto_credito']);
    $limite_credito = floatval($_POST['limite_credito']);
    
    // Verificar si ya existe registro
    $sql_check = "SELECT id FROM creditos_clientes WHERE id_cliente = ?";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("i", $cliente_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows > 0) {
        // Actualizar crédito existente
        $sql_update = "UPDATE creditos_clientes SET credito_disponible = ?, credito_limite = ? WHERE id_cliente = ?";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param("ddi", $monto_credito, $limite_credito, $cliente_id);
    } else {
        // Insertar nuevo crédito
        $sql_insert = "INSERT INTO creditos_clientes (id_cliente, credito_disponible, credito_limite) VALUES (?, ?, ?)";
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->bind_param("idd", $cliente_id, $monto_credito, $limite_credito);
    }
    
    if (isset($stmt_update) && $stmt_update->execute()) {
        $mensaje = "Crédito actualizado exitosamente";
    } elseif (isset($stmt_insert) && $stmt_insert->execute()) {
        $mensaje = "Crédito asignado exitosamente";
    } else {
        $error = "Error al asignar crédito";
    }
}

// Obtener lista de clientes con crédito
$sql_clientes_credito = "SELECT c.id, c.nombre, c.telefono, 
                         COALESCE(cc.credito_disponible, 0) as credito_disponible,
                         COALESCE(cc.credito_limite, 0) as credito_limite
                         FROM clientes c 
                         LEFT JOIN creditos_clientes cc ON c.id = cc.id_cliente
                         ORDER BY c.nombre";
$result_clientes = $conn->query($sql_clientes_credito);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrar Créditos - Ruta 666</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .table th, .table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        .table th { background-color: #333; color: white; }
        .btn { padding: 8px 15px; margin: 5px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-primary { background-color: #007bff; color: white; }
        .btn-success { background-color: #28a745; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Administrar Créditos de Clientes</h1>
        
        <?php if (isset($mensaje)): ?>
            <script>Swal.fire('Éxito', '<?php echo $mensaje; ?>', 'success');</script>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <script>Swal.fire('Error', '<?php echo $error; ?>', 'error');</script>
        <?php endif; ?>

        <h2>Asignar/Actualizar Crédito</h2>
        <form method="POST" style="background: #f5f5f5; padding: 20px; border-radius: 5px;">
            <input type="hidden" name="asignar_credito" value="1">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                <select name="cliente_id" required style="padding: 10px;">
                    <option value="">Seleccionar Cliente</option>
                    <?php
                    $sql_todos_clientes = "SELECT id, nombre, telefono FROM clientes ORDER BY nombre";
                    $result_todos = $conn->query($sql_todos_clientes);
                    while ($cliente = $result_todos->fetch_assoc()): ?>
                        <option value="<?php echo $cliente['id']; ?>">
                            <?php echo htmlspecialchars($cliente['nombre']); ?> 
                            (<?php echo $cliente['telefono'] ?: 'Sin teléfono'; ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
                <input type="number" name="monto_credito" step="0.01" min="0" placeholder="Crédito Disponible" required style="padding: 10px;">
                <input type="number" name="limite_credito" step="0.01" min="0" placeholder="Límite de Crédito" required style="padding: 10px;">
                <button type="submit" class="btn btn-success">Asignar Crédito</button>
            </div>
        </form>

        <h2>Clientes con Crédito</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Teléfono</th>
                    <th>Crédito Disponible</th>
                    <th>Límite de Crédito</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($cliente = $result_clientes->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($cliente['nombre']); ?></td>
                        <td><?php echo $cliente['telefono'] ?: 'N/A'; ?></td>
                        <td>Q<?php echo number_format($cliente['credito_disponible'], 2); ?></td>
                        <td>Q<?php echo number_format($cliente['credito_limite'], 2); ?></td>
                        <td>
                            <button class="btn btn-primary" onclick="editarCredito(<?php echo $cliente['id']; ?>, <?php echo $cliente['credito_disponible']; ?>, <?php echo $cliente['credito_limite']; ?>)">
                                Editar
                            </button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <script>
    function editarCredito(clienteId, creditoActual, limiteActual) {
        Swal.fire({
            title: 'Editar Crédito',
            html: `
                <input type="number" id="nuevoCredito" value="${creditoActual}" step="0.01" min="0" class="swal2-input" placeholder="Nuevo Crédito">
                <input type="number" id="nuevoLimite" value="${limiteActual}" step="0.01" min="0" class="swal2-input" placeholder="Nuevo Límite">
            `,
            showCancelButton: true,
            confirmButtonText: 'Actualizar',
            preConfirm: () => {
                const nuevoCredito = parseFloat(document.getElementById('nuevoCredito').value);
                const nuevoLimite = parseFloat(document.getElementById('nuevoLimite').value);
                
                if (isNaN(nuevoCredito) || isNaN(nuevoLimite) || nuevoCredito < 0 || nuevoLimite < 0) {
                    Swal.showValidationMessage('Ingrese valores válidos');
                    return false;
                }
                
                return { credito: nuevoCredito, limite: nuevoLimite };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // Aquí puedes agregar la lógica para actualizar via AJAX
                Swal.fire('Éxito', 'Crédito actualizado', 'success').then(() => {
                    location.reload();
                });
            }
        });
    }
    </script>
</body>
</html>