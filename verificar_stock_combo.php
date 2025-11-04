<?php
session_start();
include 'conexion.php';

if (!isset($_GET['combo_id']) || !isset($_GET['cantidad'])) {
    echo json_encode(['stock_suficiente' => false]);
    exit();
}

$combo_id = intval($_GET['combo_id']);
$cantidad = intval($_GET['cantidad']);

// Obtener productos del combo
$sql_productos_combo = "SELECT cp.id_producto, cp.cantidad as cantidad_combo, p.nombre, p.stock 
                       FROM combo_productos cp 
                       JOIN productos p ON cp.id_producto = p.id 
                       WHERE cp.id_combo = ?";
$stmt = $conn->prepare($sql_productos_combo);
$stmt->bind_param("i", $combo_id);
$stmt->execute();
$result = $stmt->get_result();

$stock_suficiente = true;

while ($producto = $result->fetch_assoc()) {
    $cantidad_necesaria = $producto['cantidad_combo'] * $cantidad;
    if ($producto['stock'] < $cantidad_necesaria) {
        $stock_suficiente = false;
        break;
    }
}

echo json_encode(['stock_suficiente' => $stock_suficiente]);
?>