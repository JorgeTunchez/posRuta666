<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] != 'administrador' && $_SESSION['user_role'] != 'gerente')) {
    header("HTTP/1.1 403 Forbidden");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_combo'])) {
    $combo_id = isset($_POST['combo_id']) ? intval($_POST['combo_id']) : 0;
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);
    $precio_venta = floatval($_POST['precio_venta']);
    $precio_after = isset($_POST['precio_after']) && !empty($_POST['precio_after']) ? floatval($_POST['precio_after']) : NULL;
    $dias = isset($_POST['dias']) ? $_POST['dias'] : [];
    $productos = isset($_POST['productos']) ? $_POST['productos'] : [];

    // Validaciones
    if (empty($nombre) || $precio_venta <= 0 || empty($productos)) {
        echo json_encode(['success' => false, 'message' => 'Nombre, precio y productos son obligatorios']);
        exit();
    }

    $conn->begin_transaction();

    try {
        // Manejar imagen
        $nombre_imagen = NULL;
        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
            $directorio_imagenes = 'uploads/combos/';
            if (!is_dir($directorio_imagenes)) {
                mkdir($directorio_imagenes, 0755, true);
            }
            
            $extension = pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION);
            $nombre_imagen = uniqid() . '.' . $extension;
            $ruta_imagen = $directorio_imagenes . $nombre_imagen;
            
            if (!move_uploaded_file($_FILES['imagen']['tmp_name'], $ruta_imagen)) {
                $nombre_imagen = NULL;
            }
        }

        if ($combo_id > 0) {
            // Editar combo existente
            if ($nombre_imagen) {
                $sql = "UPDATE combos SET nombre=?, descripcion=?, precio_venta=?, precio_after=?, imagen=? WHERE id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssddsi", $nombre, $descripcion, $precio_venta, $precio_after, $nombre_imagen, $combo_id);
            } else {
                $sql = "UPDATE combos SET nombre=?, descripcion=?, precio_venta=?, precio_after=? WHERE id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssddi", $nombre, $descripcion, $precio_venta, $precio_after, $combo_id);
            }
            $stmt->execute();
        } else {
            // Crear nuevo combo
            $sql = "INSERT INTO combos (nombre, descripcion, precio_venta, precio_after, imagen) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssdds", $nombre, $descripcion, $precio_venta, $precio_after, $nombre_imagen);
            $stmt->execute();
            $combo_id = $conn->insert_id;
        }

        // Eliminar productos anteriores del combo
        $sql_delete = "DELETE FROM combo_productos WHERE id_combo = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i", $combo_id);
        $stmt_delete->execute();

        // Insertar nuevos productos del combo
        $sql_producto = "INSERT INTO combo_productos (id_combo, id_producto, cantidad) VALUES (?, ?, ?)";
        $stmt_producto = $conn->prepare($sql_producto);
        
        foreach ($productos as $producto) {
            $id_producto = intval($producto['id']);
            $cantidad = intval($producto['cantidad']);
            $stmt_producto->bind_param("iii", $combo_id, $id_producto, $cantidad);
            $stmt_producto->execute();
        }

        // Manejar días de la semana
        $sql_delete_dias = "DELETE FROM combo_dias WHERE id_combo = ?";
        $stmt_delete_dias = $conn->prepare($sql_delete_dias);
        $stmt_delete_dias->bind_param("i", $combo_id);
        $stmt_delete_dias->execute();

        $sql_dia = "INSERT INTO combo_dias (id_combo, dia_semana) VALUES (?, ?)";
        $stmt_dia = $conn->prepare($sql_dia);
        
        foreach ($dias as $dia) {
            $stmt_dia->bind_param("is", $combo_id, $dia);
            $stmt_dia->execute();
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Combo guardado correctamente']);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error al guardar combo: ' . $e->getMessage()]);
    }
}
?>