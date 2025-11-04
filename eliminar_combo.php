<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] != 'administrador' && $_SESSION['user_role'] != 'gerente')) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $combo_id = intval($_POST['id']);
    
    // Verificar que el combo existe
    $sql_verificar = "SELECT * FROM combos WHERE id = ?";
    $stmt_verificar = $conn->prepare($sql_verificar);
    $stmt_verificar->bind_param("i", $combo_id);
    $stmt_verificar->execute();
    $combo = $stmt_verificar->get_result()->fetch_assoc();
    
    if (!$combo) {
        echo json_encode(['success' => false, 'message' => 'Combo no encontrado']);
        exit();
    }
    
    // Desactivar el combo en lugar de eliminarlo (para mantener historial)
    $sql_desactivar = "UPDATE combos SET activo = 0 WHERE id = ?";
    $stmt_desactivar = $conn->prepare($sql_desactivar);
    $stmt_desactivar->bind_param("i", $combo_id);
    
    if ($stmt_desactivar->execute()) {
        echo json_encode(['success' => true, 'message' => 'Combo eliminado correctamente']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error al eliminar combo']);
    }
}
?>