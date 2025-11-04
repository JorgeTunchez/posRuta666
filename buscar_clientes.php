<?php
session_start();
include 'config_hora.php';

include 'conexion.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sesión expirada']);
    exit();
}

$termino = $_GET['q'] ?? '';

if (strlen($termino) < 2) {
    echo json_encode(['success' => true, 'clientes' => []]);
    exit();
}

try {
    $sql = "SELECT id, nombre, telefono, puntos 
            FROM clientes 
            WHERE nombre LIKE ? OR telefono LIKE ? 
            ORDER BY nombre 
            LIMIT 10";
    $stmt = $conn->prepare($sql);
    $likeTerm = '%' . $termino . '%';
    $stmt->bind_param("ss", $likeTerm, $likeTerm);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $clientes = [];
    while ($row = $result->fetch_assoc()) {
        $clientes[] = $row;
    }
    
    echo json_encode(['success' => true, 'clientes' => $clientes]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?>