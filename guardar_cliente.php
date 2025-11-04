<?php
session_start();
include 'config_hora.php';
if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.1 401 Unauthorized");
    exit();
}

include 'conexion.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!empty($data['nombre'])) {
    $nombre = trim($data['nombre']);
    $telefono = !empty($data['telefono']) ? trim($data['telefono']) : '';
    $email = !empty($data['email']) ? trim($data['email']) : '';
    
    // Validar formato de email si se proporcionó
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false,
            'message' => 'Formato de email inválido'
        ]);
        exit();
    }
    
    $sql = "INSERT INTO clientes (nombre, telefono, email, puntos, visitas, ultima_visita) 
            VALUES (?, ?, ?, 0, 0, CURDATE())";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $nombre, $telefono, $email);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'id' => $stmt->insert_id,
            'message' => 'Cliente guardado correctamente'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Error al guardar cliente: ' . $stmt->error
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Nombre de cliente requerido'
    ]);
}

$conn->close();
?>