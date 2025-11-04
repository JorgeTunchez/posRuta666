<?php
session_start();
include 'conexion.php';

// Verificar tablas
$tablas = ['combos', 'combo_productos', 'combo_dias'];
foreach ($tablas as $tabla) {
    $result = $conn->query("SHOW TABLES LIKE '$tabla'");
    if ($result->num_rows > 0) {
        echo "✓ Tabla $tabla existe<br>";
    } else {
        echo "✗ Tabla $tabla NO existe<br>";
    }
}

// Verificar permisos de directorio
$directorio = 'uploads/combos';
if (is_dir($directorio)) {
    echo "✓ Directorio $directorio existe<br>";
    if (is_writable($directorio)) {
        echo "✓ Directorio $directorio tiene permisos de escritura<br>";
    } else {
        echo "✗ Directorio $directorio NO tiene permisos de escritura<br>";
    }
} else {
    echo "✗ Directorio $directorio NO existe<br>";
}

// Verificar productos disponibles
$result = $conn->query("SELECT COUNT(*) as total FROM productos WHERE stock > 0");
$total = $result->fetch_assoc()['total'];
echo "✓ Productos disponibles: $total<br>";
?>