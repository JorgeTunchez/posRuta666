<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

$id = intval($_GET['id']);
$sql = "SELECT * FROM proveedores WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $proveedor = $result->fetch_assoc();
    echo json_encode(['success' => true, 'proveedor' => $proveedor]);
} else {
    echo json_encode(['success' => false]);
}
?>