<?php
session_start();
include 'config_hora.php';
if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.1 401 Unauthorized");
    echo json_encode(['error' => 'No autorizado', 'credito_disponible' => 0]);
    exit();
}

include 'conexion.php';

header('Content-Type: application/json');

try {
    $cliente_id = isset($_GET['cliente_id']) ? intval($_GET['cliente_id']) : 0;

    if ($cliente_id <= 0) {
        echo json_encode(['success' => false, 'credito_disponible' => 0, 'mensaje' => 'ID de cliente inválido']);
        exit();
    }

    // Verificar si el cliente existe
    $sql_verificar_cliente = "SELECT id, nombre FROM clientes WHERE id = ?";
    $stmt_verificar = $conn->prepare($sql_verificar_cliente);
    $stmt_verificar->bind_param("i", $cliente_id);
    $stmt_verificar->execute();
    $result_verificar = $stmt_verificar->get_result();

    if ($result_verificar->num_rows === 0) {
        echo json_encode(['success' => false, 'credito_disponible' => 0, 'mensaje' => 'Cliente no encontrado']);
        exit();
    }

    $cliente = $result_verificar->fetch_assoc();

    // Consultar crédito en la tabla creditos_clientes
    $sql_credito = "SELECT credito_disponible, credito_limite FROM creditos_clientes WHERE id_cliente = ?";
    $stmt_credito = $conn->prepare($sql_credito);
    $stmt_credito->bind_param("i", $cliente_id);
    $stmt_credito->execute();
    $result_credito = $stmt_credito->get_result();

    if ($result_credito->num_rows > 0) {
        $credito = $result_credito->fetch_assoc();
        $credito_disponible = floatval($credito['credito_disponible']);
        $credito_limite = floatval($credito['credito_limite']);
        
        echo json_encode([
            'success' => true,
            'credito_disponible' => $credito_disponible,
            'credito_limite' => $credito_limite,
            'cliente_id' => $cliente_id,
            'cliente_nombre' => $cliente['nombre'],
            'puede_comprar' => $credito_disponible > 0
        ]);
    } else {
        // Si no existe registro, el cliente no tiene crédito asignado
        echo json_encode([
            'success' => true,
            'credito_disponible' => 0,
            'credito_limite' => 0,
            'cliente_id' => $cliente_id,
            'cliente_nombre' => $cliente['nombre'],
            'puede_comprar' => false,
            'mensaje' => 'Cliente sin crédito asignado'
        ]);
    }

} catch (Exception $e) {
    error_log('Error en verificar_credito.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Error del servidor',
        'credito_disponible' => 0
    ]);
}

$conn->close();
?>