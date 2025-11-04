<?php
/* Conexion a produccion

$servername = "localhost";
$username = "admin_ruta666";
$password = "_.r?,Pl@aIZo";
$dbname = "ruta_pos";

*/


$servername = "localhost:3306";
$username = "root";
$password = "";
$dbname = "ruta_pos";

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Establecer el charset a utf8
$conn->set_charset("utf8");
?>