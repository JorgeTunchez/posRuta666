<?php
session_start();
include 'config_hora.php';
// Endpoint para obtener datos del combo (para AJAX)
if (isset($_GET['editar_combo']) && $_GET['editar_combo'] > 0 && isset($_GET['ajax'])) {
    include 'conexion.php';
    
    $combo_id = intval($_GET['editar_combo']);
    
    // Obtener datos del combo
    $sql_combo = "SELECT * FROM combos WHERE id = ? AND activo = 1";
    $stmt_combo = $conn->prepare($sql_combo);
    $stmt_combo->bind_param("i", $combo_id);
    $stmt_combo->execute();
    $result_combo = $stmt_combo->get_result();
    $combo = $result_combo->fetch_assoc();
    
    if ($combo) {
        // Obtener productos del combo
        $sql_combo_productos = "SELECT cp.id_producto, cp.cantidad, p.nombre 
                               FROM combo_productos cp 
                               JOIN productos p ON cp.id_producto = p.id 
                               WHERE cp.id_combo = ?";
        $stmt_productos = $conn->prepare($sql_combo_productos);
        $stmt_productos->bind_param("i", $combo_id);
        $stmt_productos->execute();
        $result_combo_prod = $stmt_productos->get_result();
        $combo['productos'] = [];
        while ($prod = $result_combo_prod->fetch_assoc()) {
            $combo['productos'][] = $prod;
        }
        
        // Obtener días del combo
        $sql_combo_dias = "SELECT dia_semana FROM combo_dias WHERE id_combo = ?";
        $stmt_dias = $conn->prepare($sql_combo_dias);
        $stmt_dias->bind_param("i", $combo_id);
        $stmt_dias->execute();
        $result_combo_dias = $stmt_dias->get_result();
        $combo['dias'] = [];
        while ($dia = $result_combo_dias->fetch_assoc()) {
            $combo['dias'][] = $dia['dia_semana'];
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'combo' => $combo]);
        exit();
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Combo no encontrado']);
        exit();
    }
}
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}
// Incluir archivos necesarios
include 'conexion.php';
include 'funciones.php';

// Configuración de paginación
$productos_por_pagina = 10; // Número de productos por página
$pagina_actual = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
if ($pagina_actual < 1) $pagina_actual = 1;

// Calcular el offset para la consulta
$offset = ($pagina_actual - 1) * $productos_por_pagina;

// Procesar operaciones del inventario
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Agregar nuevo producto
    if (isset($_POST['agregar_producto'])) {
        $nombre = trim($_POST['nombre']);
        $descripcion = trim($_POST['descripcion']);
        $id_categoria = intval($_POST['id_categoria']);
        $id_proveedor = isset($_POST['id_proveedor']) && !empty($_POST['id_proveedor']) ? intval($_POST['id_proveedor']) : NULL;
        $precio_compra = floatval($_POST['precio_compra']);
        $precio_venta = floatval($_POST['precio_venta']);
        $precio_after = isset($_POST['precio_after']) && !empty($_POST['precio_after']) ? floatval($_POST['precio_after']) : NULL;
        $stock = intval($_POST['stock']);
        $stock_minimo = intval($_POST['stock_minimo']);
        
        // Validaciones básicas
        if (empty($nombre) || $precio_venta <= 0) {
            $mensaje_error = "Nombre y precio de venta son obligatorios";
        } else {
            // Manejar la imagen si se subió
            $nombre_imagen = NULL;
            if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
                $directorio_imagenes = 'uploads/productos/';
                if (!is_dir($directorio_imagenes)) {
                    mkdir($directorio_imagenes, 0755, true);
                }
                
                $extension = pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION);
                $nombre_imagen = uniqid() . '.' . $extension;
                $ruta_imagen = $directorio_imagenes . $nombre_imagen;
                
                if (!move_uploaded_file($_FILES['imagen']['tmp_name'], $ruta_imagen)) {
                    $nombre_imagen = NULL; // Si falla la subida, continuar sin imagen
                }
            }
            
            // Insertar producto
            $sql = "INSERT INTO productos (nombre, descripcion, id_categoria, id_proveedor, precio_compra, precio_venta, precio_after, stock, stock_minimo, imagen, activo) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssiidddiis", $nombre, $descripcion, $id_categoria, $id_proveedor, $precio_compra, $precio_venta, $precio_after, $stock, $stock_minimo, $nombre_imagen);
            
            if ($stmt->execute()) {
                $mensaje_exito = "Producto agregado correctamente";
                // Redirigir a la primera página después de agregar
                header("Location: inventario.php?pagina=1&exito=1");
                exit();
            } else {
                $mensaje_error = "Error al agregar producto: " . $conn->error;
            }
        }
    }
    
    // Editar producto
    if (isset($_POST['editar_producto'])) {
        $id = intval($_POST['id']);
        $nombre = trim($_POST['nombre']);
        $descripcion = trim($_POST['descripcion']);
        $id_categoria = intval($_POST['id_categoria']);
        $id_proveedor = isset($_POST['id_proveedor']) && !empty($_POST['id_proveedor']) ? intval($_POST['id_proveedor']) : NULL;
        $precio_compra = floatval($_POST['precio_compra']);
        $precio_venta = floatval($_POST['precio_venta']);
        $precio_after = isset($_POST['precio_after']) && !empty($_POST['precio_after']) ? floatval($_POST['precio_after']) : NULL;
        $stock = intval($_POST['stock']);
        $stock_minimo = intval($_POST['stock_minimo']);
        
        // Obtener imagen actual
        $sql_imagen_actual = "SELECT imagen FROM productos WHERE id = ?";
        $stmt_imagen = $conn->prepare($sql_imagen_actual);
        $stmt_imagen->bind_param("i", $id);
        $stmt_imagen->execute();
        $result_imagen = $stmt_imagen->get_result();
        $imagen_actual = $result_imagen->fetch_assoc()['imagen'];
        
        $nombre_imagen = $imagen_actual;
        
        // Manejar nueva imagen si se subió
        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
            $directorio_imagenes = 'uploads/productos/';
            if (!is_dir($directorio_imagenes)) {
                mkdir($directorio_imagenes, 0755, true);
            }
            
            // Eliminar imagen anterior si existe
            if ($imagen_actual && file_exists($directorio_imagenes . $imagen_actual)) {
                unlink($directorio_imagenes . $imagen_actual);
            }
            
            $extension = pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION);
            $nombre_imagen = uniqid() . '.' . $extension;
            $ruta_imagen = $directorio_imagenes . $nombre_imagen;
            
            if (!move_uploaded_file($_FILES['imagen']['tmp_name'], $ruta_imagen)) {
                $nombre_imagen = $imagen_actual; // Si falla, mantener la anterior
            }
        }
        
        $sql = "UPDATE productos SET nombre = ?, descripcion = ?, id_categoria = ?, id_proveedor = ?, 
                precio_compra = ?, precio_venta = ?, precio_after = ?, stock = ?, stock_minimo = ?, imagen = ?
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssiidddiisi", $nombre, $descripcion, $id_categoria, $id_proveedor, 
                         $precio_compra, $precio_venta, $precio_after, $stock, $stock_minimo, $nombre_imagen, $id);
        
        if ($stmt->execute()) {
            $mensaje_exito = "Producto actualizado correctamente";
            // Mantener la página actual después de editar
            header("Location: inventario.php?pagina=" . $pagina_actual . "&exito=1");
            exit();
        } else {
            $mensaje_error = "Error al actualizar producto: " . $conn->error;
        }
    }
    
    // Desactivar producto (MODIFICADO - ahora desactiva en lugar de eliminar)
    if (isset($_POST['eliminar_producto'])) {
        $id = intval($_POST['id']);
        
        // Marcar producto como inactivo
        $sql = "UPDATE productos SET activo = 0 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $mensaje_exito = "Producto desactivado correctamente";
            header("Location: inventario.php?pagina=" . $pagina_actual . "&exito=1");
            exit();
        } else {
            $mensaje_error = "Error al desactivar producto: " . $conn->error;
        }
    }
}

// Mostrar mensaje de éxito si viene por GET
if (isset($_GET['exito'])) {
    $mensaje_exito = "Operación realizada correctamente";
}

// Obtener productos con información de categoría y proveedor - EXCLUYENDO ENSERES, GENERAL E INACTIVOS
$sql_productos = "SELECT p.*, c.nombre as categoria_nombre, pr.nombre as proveedor_nombre 
                  FROM productos p 
                  LEFT JOIN categorias c ON p.id_categoria = c.id 
                  LEFT JOIN proveedores pr ON p.id_proveedor = pr.id 
                  WHERE c.nombre NOT IN ('enseres', 'general') 
                  AND p.activo = 1 
                  ORDER BY p.nombre 
                  LIMIT $productos_por_pagina OFFSET $offset";

$result_productos = $conn->query($sql_productos);

// Obtener el total de productos para la paginación (solo activos)
$sql_total = "SELECT COUNT(*) as total 
              FROM productos p 
              LEFT JOIN categorias c ON p.id_categoria = c.id 
              WHERE c.nombre NOT IN ('enseres', 'general')
              AND p.activo = 1";
$result_total = $conn->query($sql_total);
$total_productos = $result_total->fetch_assoc()['total'];

// Calcular el total de páginas
$total_paginas = ceil($total_productos / $productos_por_pagina);

// Asegurar que la página actual no exceda el total de páginas
if ($pagina_actual > $total_paginas && $total_paginas > 0) {
    $pagina_actual = $total_paginas;
    // Redirigir a la última página válida
    header("Location: inventario.php?pagina=" . $total_paginas);
    exit();
}

// Obtener categorías para los selects
$sql_categorias = "SELECT * FROM categorias ORDER BY nombre";
$result_categorias = $conn->query($sql_categorias);

// Obtener proveedores para los selects
$sql_proveedores = "SELECT * FROM proveedores ORDER BY nombre";
$result_proveedores = $conn->query($sql_proveedores);

// Estadísticas del inventario - ACTUALIZADA PARA EXCLUIR ENSERES, GENERAL E INACTIVOS
$sql_stats = "SELECT 
                COUNT(*) as total_productos,
                SUM(stock) as total_stock,
                SUM(CASE WHEN stock <= stock_minimo THEN 1 ELSE 0 END) as productos_bajo_stock,
                SUM(precio_compra * stock) as valor_inventario
              FROM productos p
              LEFT JOIN categorias c ON p.id_categoria = c.id
              WHERE c.nombre NOT IN ('enseres', 'general')
              AND p.activo = 1";
$result_stats = $conn->query($sql_stats);
$stats = $result_stats->fetch_assoc();

// Procesar operaciones de combos
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_combo'])) {
    error_log("=== INICIO PROCESAMIENTO COMBO ===");
    
    // Solo permitir a administradores y gerentes
    if ($_SESSION['user_role'] != 'administrador' && $_SESSION['user_role'] != 'gerente') {
        $mensaje_error = "No tienes permisos para realizar esta acción";
        error_log("DEBUG: Permiso denegado para rol: " . $_SESSION['user_role']);
    } else {
        $combo_id = isset($_POST['combo_id']) ? intval($_POST['combo_id']) : 0;
        $nombre = trim($_POST['nombre']);
        $descripcion = trim($_POST['descripcion']);
        $precio_venta = floatval($_POST['precio_venta']);
        $precio_after = isset($_POST['precio_after']) && !empty($_POST['precio_after']) ? floatval($_POST['precio_after']) : NULL;
        
        // Obtener productos y días
        $productos_ids = isset($_POST['producto_id']) ? $_POST['producto_id'] : [];
        $productos_cantidades = isset($_POST['producto_cantidad']) ? $_POST['producto_cantidad'] : [];
        $dias = isset($_POST['dias']) ? $_POST['dias'] : [];

        error_log("DEBUG: Combo ID: $combo_id");
        error_log("DEBUG: Nombre: $nombre");
        error_log("DEBUG: Precio venta: $precio_venta");
        error_log("DEBUG: Precio after: " . ($precio_after ?? 'NULL'));
        error_log("DEBUG: Productos IDs: " . implode(', ', $productos_ids));
        error_log("DEBUG: Productos Cantidades: " . implode(', ', $productos_cantidades));
        error_log("DEBUG: Días: " . implode(', ', $dias));

        // Validaciones
        if (empty($nombre)) {
            $mensaje_error = "El nombre del combo es obligatorio";
            error_log("DEBUG: Validación fallida - Nombre vacío");
        } elseif ($precio_venta <= 0) {
            $mensaje_error = "El precio de venta debe ser mayor a 0";
            error_log("DEBUG: Validación fallida - Precio inválido: $precio_venta");
        } elseif (empty($productos_ids)) {
            $mensaje_error = "Debe agregar al menos un producto al combo";
            error_log("DEBUG: Validación fallida - Sin productos");
        } elseif (empty($dias)) {
            $mensaje_error = "Debe seleccionar al menos un día";
            error_log("DEBUG: Validación fallida - Sin días");
        } else {
            $conn->begin_transaction();
            error_log("DEBUG: Transacción iniciada");

            try {
                // Manejar imagen
                $nombre_imagen = NULL;
                if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
                    error_log("DEBUG: Procesando imagen...");
                    $directorio_imagenes = 'uploads/combos/';
                    if (!is_dir($directorio_imagenes)) {
                        mkdir($directorio_imagenes, 0755, true);
                    }
                    
                    $extension = pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION);
                    $nombre_imagen = uniqid() . '.' . $extension;
                    $ruta_imagen = $directorio_imagenes . $nombre_imagen;
                    
                    if (move_uploaded_file($_FILES['imagen']['tmp_name'], $ruta_imagen)) {
                        error_log("DEBUG: Imagen subida: $nombre_imagen");
                    } else {
                        $nombre_imagen = NULL;
                        error_log("DEBUG: Error al subir imagen");
                    }
                } else {
                    error_log("DEBUG: No se subió imagen o hubo error: " . ($_FILES['imagen']['error'] ?? 'NO_FILE'));
                }

                if ($combo_id > 0) {
                    // Editar combo existente
                    error_log("DEBUG: Editando combo existente ID: $combo_id");
                    
                    if ($nombre_imagen) {
                        $sql = "UPDATE combos SET nombre=?, descripcion=?, precio_venta=?, precio_after=?, imagen=? WHERE id=?";
                        $stmt = $conn->prepare($sql);
                        if (!$stmt) {
                            throw new Exception("Error preparando consulta UPDATE: " . $conn->error);
                        }
                        $stmt->bind_param("ssddsi", $nombre, $descripcion, $precio_venta, $precio_after, $nombre_imagen, $combo_id);
                    } else {
                        $sql = "UPDATE combos SET nombre=?, descripcion=?, precio_venta=?, precio_after=? WHERE id=?";
                        $stmt = $conn->prepare($sql);
                        if (!$stmt) {
                            throw new Exception("Error preparando consulta UPDATE: " . $conn->error);
                        }
                        $stmt->bind_param("ssddi", $nombre, $descripcion, $precio_venta, $precio_after, $combo_id);
                    }
                    
                    if (!$stmt->execute()) {
                        throw new Exception("Error ejecutando UPDATE: " . $stmt->error);
                    }
                    error_log("DEBUG: Combo actualizado correctamente");
                } else {
                    // Crear nuevo combo
                    error_log("DEBUG: Creando nuevo combo");
                    $sql = "INSERT INTO combos (nombre, descripcion, precio_venta, precio_after, imagen, activo) VALUES (?, ?, ?, ?, ?, 1)";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        throw new Exception("Error preparando consulta INSERT: " . $conn->error);
                    }
                    $stmt->bind_param("ssdds", $nombre, $descripcion, $precio_venta, $precio_after, $nombre_imagen);
                    
                    if (!$stmt->execute()) {
                        throw new Exception("Error ejecutando INSERT: " . $stmt->error);
                    }
                    $combo_id = $conn->insert_id;
                    error_log("DEBUG: Nuevo combo creado con ID: $combo_id");
                }

                // Eliminar productos anteriores del combo
                error_log("DEBUG: Eliminando productos anteriores del combo");
                $sql_delete = "DELETE FROM combo_productos WHERE id_combo = ?";
                $stmt_delete = $conn->prepare($sql_delete);
                if (!$stmt_delete) {
                    throw new Exception("Error preparando DELETE productos: " . $conn->error);
                }
                $stmt_delete->bind_param("i", $combo_id);
                if (!$stmt_delete->execute()) {
                    throw new Exception("Error ejecutando DELETE productos: " . $stmt_delete->error);
                }
                error_log("DEBUG: Productos anteriores eliminados");

                // Insertar nuevos productos del combo
                error_log("DEBUG: Insertando nuevos productos del combo");
                $sql_producto = "INSERT INTO combo_productos (id_combo, id_producto, cantidad) VALUES (?, ?, ?)";
                $stmt_producto = $conn->prepare($sql_producto);
                if (!$stmt_producto) {
                    throw new Exception("Error preparando INSERT productos: " . $conn->error);
                }
                
                for ($i = 0; $i < count($productos_ids); $i++) {
                    $id_producto = intval($productos_ids[$i]);
                    $cantidad = intval($productos_cantidades[$i]);
                    
                    if ($id_producto > 0 && $cantidad > 0) {
                        error_log("DEBUG: Agregando producto ID: $id_producto, Cantidad: $cantidad");
                        $stmt_producto->bind_param("iii", $combo_id, $id_producto, $cantidad);
                        if (!$stmt_producto->execute()) {
                            throw new Exception("Error ejecutando INSERT producto $id_producto: " . $stmt_producto->error);
                        }
                    }
                }
                error_log("DEBUG: Productos del combo insertados");

                // Manejar días de la semana
                error_log("DEBUG: Eliminando días anteriores del combo");
                $sql_delete_dias = "DELETE FROM combo_dias WHERE id_combo = ?";
                $stmt_delete_dias = $conn->prepare($sql_delete_dias);
                if (!$stmt_delete_dias) {
                    throw new Exception("Error preparando DELETE días: " . $conn->error);
                }
                $stmt_delete_dias->bind_param("i", $combo_id);
                if (!$stmt_delete_dias->execute()) {
                    throw new Exception("Error ejecutando DELETE días: " . $stmt_delete_dias->error);
                }
                error_log("DEBUG: Días anteriores eliminados");

                error_log("DEBUG: Insertando nuevos días del combo");
                $sql_dia = "INSERT INTO combo_dias (id_combo, dia_semana) VALUES (?, ?)";
                $stmt_dia = $conn->prepare($sql_dia);
                if (!$stmt_dia) {
                    throw new Exception("Error preparando INSERT días: " . $conn->error);
                }
                
                foreach ($dias as $dia) {
                    if (!empty($dia)) {
                        error_log("DEBUG: Agregando día: $dia");
                        $stmt_dia->bind_param("is", $combo_id, $dia);
                        if (!$stmt_dia->execute()) {
                            throw new Exception("Error ejecutando INSERT día $dia: " . $stmt_dia->error);
                        }
                    }
                }
                error_log("DEBUG: Días del combo insertados");

                $conn->commit();
                $mensaje_exito = "Combo guardado correctamente";
                error_log("DEBUG: Transacción completada exitosamente");
                
                // Redirigir para evitar reenvío del formulario
                header("Location: inventario.php?exito=1");
                exit();
                
            } catch (Exception $e) {
                $conn->rollback();
                $mensaje_error = "Error al guardar combo: " . $e->getMessage();
                error_log("ERROR: " . $e->getMessage());
                error_log("ERROR Stack trace: " . $e->getTraceAsString());
            }
        }
    }
    error_log("=== FIN PROCESAMIENTO COMBO ===");
}

// Procesar eliminación de combo (también desactiva en lugar de eliminar)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['eliminar_combo'])) {
    $combo_id = intval($_POST['combo_id']);
    
    if ($_SESSION['user_role'] == 'administrador' || $_SESSION['user_role'] == 'gerente') {
        // Marcar combo como inactivo
        $sql = "UPDATE combos SET activo = 0 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $combo_id);
        
        if ($stmt->execute()) {
            $mensaje_exito = "Combo desactivado correctamente";
            header("Location: inventario.php?exito=1");
            exit();
        } else {
            $mensaje_error = "Error al desactivar combo: " . $conn->error;
        }
    }
}
// Obtener datos del combo para editar si se solicita
$combo_editar = null;
if (isset($_GET['editar_combo'])) {
    $combo_id = intval($_GET['editar_combo']);
    
    // Obtener datos del combo
    $sql_combo = "SELECT * FROM combos WHERE id = ? AND activo = 1";
    $stmt_combo = $conn->prepare($sql_combo);
    $stmt_combo->bind_param("i", $combo_id);
    $stmt_combo->execute();
    $result_combo = $stmt_combo->get_result();
    $combo_editar = $result_combo->fetch_assoc();
    
    if ($combo_editar) {
        // Obtener productos del combo
        $sql_combo_productos = "SELECT cp.id_producto, cp.cantidad, p.nombre 
                               FROM combo_productos cp 
                               JOIN productos p ON cp.id_producto = p.id 
                               WHERE cp.id_combo = ?";
        $stmt_productos = $conn->prepare($sql_combo_productos);
        $stmt_productos->bind_param("i", $combo_id);
        $stmt_productos->execute();
        $result_combo_prod = $stmt_productos->get_result();
        $combo_editar['productos'] = [];
        while ($prod = $result_combo_prod->fetch_assoc()) {
            $combo_editar['productos'][] = $prod;
        }
        
        // Obtener días del combo
        $sql_combo_dias = "SELECT dia_semana FROM combo_dias WHERE id_combo = ?";
        $stmt_dias = $conn->prepare($sql_combo_dias);
        $stmt_dias->bind_param("i", $combo_id);
        $stmt_dias->execute();
        $result_combo_dias = $stmt_dias->get_result();
        $combo_editar['dias'] = [];
        while ($dia = $result_combo_dias->fetch_assoc()) {
            $combo_editar['dias'][] = $dia['dia_semana'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ruta 666 - Inventario</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Metal+Mania&display=swap" rel="stylesheet">
    <style>
        :root {
            --dark-bg: #1a1a1a;
            --darker-bg: #121212;
            --accent: #ff0000;
            --text: #ffffff;
            --text-secondary: #cccccc;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }
        body {
            background-color: var(--darker-bg);
            color: var(--text);
            min-height: 100vh;
        }
        .sidebar {
            position: fixed;
            width: 250px;
            height: 100%;
            background-color: var(--dark-bg);
            padding: 20px 0;
            border-right: 1px solid #333;
            display: flex;
            flex-direction: column;
        }
        .logo {
            text-align: center;
            padding: 20px 0;
            font-family: 'Metal Mania', cursive;
            font-size: 28px;
            color: var(--accent);
            text-shadow: 0 0 10px rgba(255, 0, 0, 0.7);
            border-bottom: 1px solid #333;
            margin-bottom: 20px;
            flex-shrink: 0;
        }
        .menu-container {
            flex: 1;
            overflow-y: auto;
        }
        .menu-item {
            padding: 15px 20px;
            display: block;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 4px solid transparent;
        }
        .menu-item:hover, .menu-item.active {
            background-color: #333;
            color: var(--accent);
            border-left: 4px solid var(--accent);
        }
        .user-info {
            padding: 15px 20px;
            border-top: 1px solid #333;
            background-color: var(--dark-bg);
            flex-shrink: 0;
        }
        .user-info strong {
            display: block;
            margin-bottom: 5px;
        }
        .user-info small {
            color: var(--text-secondary);
            display: block;
            margin-bottom: 10px;
        }
        .content {
            margin-left: 250px;
            padding: 20px;
            min-height: 100vh;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 20px;
            background-color: var(--dark-bg);
            border-bottom: 1px solid #333;
            margin-bottom: 20px;
        }
        .btn {
            padding: 8px 15px;
            background-color: var(--accent);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background-color: #cc0000;
        }
        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }
        .btn-danger {
            background-color: #dc3545;
        }
        .btn-danger:hover {
            background-color: #c82333;
        }
        .btn-success {
            background-color: #28a745;
        }
        .btn-success:hover {
            background-color: #218838;
        }
        .btn-warning {
            background-color: #ffc107;
            color: #000;
        }
        .btn-warning:hover {
            background-color: #e0a800;
        }
        .btn-info {
            background-color: #17a2b8;
        }
        .btn-info:hover {
            background-color: #138496;
        }
        
        /* Estadísticas */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: var(--dark-bg);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid var(--accent);
        }
        .stat-card.warning {
            border-left-color: #ffc107;
        }
        .stat-card.danger {
            border-left-color: #dc3545;
        }
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: var(--accent);
        }
        .stat-card.warning .stat-number {
            color: #ffc107;
        }
        .stat-card.danger .stat-number {
            color: #dc3545;
        }
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9em;
        }
        
        /* Controles */
        .controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .search-box {
            flex: 1;
            min-width: 300px;
        }
        .search-box input {
            width: 100%;
            padding: 10px;
            background-color: #333;
            border: 1px solid #444;
            border-radius: 5px;
            color: white;
        }
        .filters {
            display: flex;
            gap: 10px;
        }
        .filter-select {
            padding: 10px;
            background-color: #333;
            border: 1px solid #444;
            border-radius: 5px;
            color: white;
            min-width: 150px;
        }
        
        /* Tabla */
        .table-container {
            background: var(--dark-bg);
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .table-responsive {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #333;
        }
        th {
            background-color: #2a2a2a;
            color: var(--accent);
            font-weight: bold;
        }
        tr:hover {
            background-color: #2a2a2a;
        }
        .stock-bajo {
            color: #dc3545;
            font-weight: bold;
        }
        .stock-ok {
            color: #28a745;
        }
        
        /* Paginación */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        .pagination-info {
            color: var(--text-secondary);
            margin: 0 15px;
        }
        .page-link {
            padding: 8px 12px;
            background-color: #333;
            color: var(--text);
            text-decoration: none;
            border-radius: 5px;
            border: 1px solid #444;
            transition: all 0.3s;
        }
        .page-link:hover {
            background-color: var(--accent);
            border-color: var(--accent);
        }
        .page-link.active {
            background-color: var(--accent);
            border-color: var(--accent);
            color: white;
        }
        .page-link.disabled {
            background-color: #222;
            color: #666;
            cursor: not-allowed;
        }
        .pagination-controls {
            display: flex;
            gap: 5px;
        }
        
        /* Mensajes */
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .alert-success {
            background-color: #155724;
            color: #d4edda;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background-color: #721c24;
            color: #f8d7da;
            border: 1px solid #f5c6cb;
        }
        
        /* Imagen del producto */
        .product-image {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 5px;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: var(--dark-bg);
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-control {
            width: 100%;
            padding: 8px;
            background: #333;
            border: 1px solid #444;
            color: white;
            border-radius: 4px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

    </style>
</head>
<body>
    <div class="sidebar">
        <div class="logo">RUTA 666</div>
        
        <div class="menu-container">
            <a href="dashboard_<?php echo $_SESSION['user_role']; ?>.php" class="menu-item">Dashboard</a>
            <a href="ventas.php" class="menu-item">Punto de Venta</a>
            <a href="inventario.php" class="menu-item active">Inventario</a>
            <a href="clientes.php" class="menu-item">CRM</a>
            <a href="proveedores.php" class="menu-item">Proveedores</a>
            <a href="caja_chica.php" class="menu-item">Caja Chica</a>
            <a href="compras.php" class="menu-item">Compras</a>
            <?php if ($_SESSION['user_role'] != 'bar_tender'): ?>
            <a href="reportes.php" class="menu-item">Reportes</a>
            <?php endif; ?>
            <?php if ($_SESSION['user_role'] == 'administrador'): ?>
            <a href="empleados.php" class="menu-item">Empleados</a>
            <a href="configuracion.php" class="menu-item">Configuración</a>
            <?php endif; ?>
            <a href="editar_perfil.php" class="menu-item">Mi Perfil</a>
        </div>
        
        <div class="user-info">
            <strong><?php echo $_SESSION['user_name']; ?></strong>
            <small><?php echo ucfirst($_SESSION['user_role']); ?></small>
            <a href="logout.php" class="btn btn-sm">Cerrar Sesión</a>
        </div>
    </div>

    <div class="content">
        <div class="header">
            <h1>Inventario</h1>
            <div>
                <?php if ($_SESSION['user_role'] == 'gerente'): ?>
                    <button class="btn btn-success" onclick="mostrarModalProducto()">Agregar Producto</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($mensaje_exito)): ?>
            <div class="alert alert-success">
                <?php echo $mensaje_exito; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($mensaje_error)): ?>
            <div class="alert alert-error">
                <?php echo $mensaje_error; ?>
            </div>
        <?php endif; ?>

        <!-- Estadísticas del inventario -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_productos']; ?></div>
                <div class="stat-label">Total Productos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_stock']; ?></div>
                <div class="stat-label">Unidades en Stock</div>
            </div>
            <div class="stat-card <?php echo $stats['productos_bajo_stock'] > 0 ? 'warning' : ''; ?>">
                <div class="stat-number"><?php echo $stats['productos_bajo_stock']; ?></div>
                <div class="stat-label">Productos con Stock Bajo</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">Q <?php echo number_format($stats['valor_inventario'], 2); ?></div>
                <div class="stat-label">Valor del Inventario</div>
            </div>
        </div>

        <!-- Sección de Combos - Solo para administrador y gerente -->
        <?php if ($_SESSION['user_role'] == 'administrador' || $_SESSION['user_role'] == 'gerente'): ?>
        <div class="combos-section" style="margin-bottom: 30px;">
            <div class="header" style="margin-bottom: 15px;">
                <h2>Combos y Ofertas</h2>
                <button class="btn btn-success" onclick="mostrarModalCombo()">Crear Nuevo Combo</button>
            </div>

            <!-- Lista de combos activos -->
            <?php
            // Obtener combos activos
            // En la sección de combos, modifica la consulta:
$sql_combos = "SELECT c.*, 
              GROUP_CONCAT(DISTINCT cd.dia_semana) as dias_activos
              FROM combos c 
              LEFT JOIN combo_dias cd ON c.id = cd.id_combo
              WHERE c.activo = 1 
              GROUP BY c.id 
              ORDER BY c.nombre";
            $result_combos = $conn->query($sql_combos);
            ?>

            <?php if ($result_combos && $result_combos->num_rows > 0): ?>
            <div class="table-container">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Imagen</th>
                                <th>Nombre</th>
                                <th>Precio</th>
                                <th>Días Activo</th>
                                <th>Productos</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($combo = $result_combos->fetch_assoc()): ?>
                                <?php
                                // Obtener productos del combo
                                $sql_combo_productos = "SELECT cp.cantidad, p.nombre, p.stock 
                                                       FROM combo_productos cp 
                                                       JOIN productos p ON cp.id_producto = p.id 
                                                       WHERE cp.id_combo = ?";
                                $stmt_productos = $conn->prepare($sql_combo_productos);
                                $stmt_productos->bind_param("i", $combo['id']);
                                $stmt_productos->execute();
                                $result_combo_prod = $stmt_productos->get_result();
                                $productos_combo = [];
                                while ($prod = $result_combo_prod->fetch_assoc()) {
                                    $productos_combo[] = $prod;
                                }
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($combo['imagen']): ?>
                                            <img src="uploads/combos/<?php echo $combo['imagen']; ?>" 
                                                 class="product-image"
                                                 onerror="this.style.display='none'">
                                        <?php else: ?>
                                            <div style="width:50px;height:50px;background:#333;display:flex;align-items:center;justify-content:center;border-radius:5px;">
                                                <small>Sin img</small>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($combo['nombre']); ?></strong>
                                        <?php if ($combo['descripcion']): ?>
                                            <br><small><?php echo htmlspecialchars(substr($combo['descripcion'], 0, 50)); ?>...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong>Q <?php echo number_format($combo['precio_venta'], 2); ?></strong>
                                        <?php if ($combo['precio_after']): ?>
                                            <br><small style="text-decoration: line-through; color: #ccc;">
                                                Q <?php echo number_format($combo['precio_after'], 2); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $dias = $combo['dias_activos'] ? explode(',', $combo['dias_activos']) : [];
                                        $dias_esp = [
                                            'lunes' => 'Lun', 'martes' => 'Mar', 'miercoles' => 'Mié',
                                            'jueves' => 'Jue', 'viernes' => 'Vie', 'sabado' => 'Sáb', 'domingo' => 'Dom'
                                        ];
                                        foreach ($dias as $dia) {
                                            echo '<span style="background:#333;padding:2px 5px;border-radius:3px;margin:1px;display:inline-block;font-size:11px;">'.$dias_esp[$dia].'</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php foreach ($productos_combo as $prod): ?>
                                            <small><?php echo $prod['cantidad']; ?>x <?php echo htmlspecialchars($prod['nombre']); ?></small><br>
                                        <?php endforeach; ?>
                                    </td>
                                    <td>
                                        <?php if ($_SESSION['user_role'] == 'gerente'): ?>
                                            <button class="btn btn-sm btn-warning" onclick="editarCombo(<?php echo $combo['id']; ?>)">Editar</button>
                                            <button class="btn btn-sm btn-danger" onclick="eliminarCombo(<?php echo $combo['id']; ?>)">Eliminar</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
                <div style="text-align: center; padding: 20px; background: var(--dark-bg); border-radius: 8px;">
                    No hay combos activos. Crea el primero haciendo clic en "Crear Nuevo Combo".
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Controles de búsqueda y filtros -->
        <div class="controls">
            <div class="search-box">
                <input type="text" id="searchProducto" placeholder="Buscar producto..." onkeyup="filtrarProductos()">
            </div>
            <div class="filters">
                <select id="filterCategoria" class="filter-select" onchange="filtrarProductos()">
                    <option value="">Todas las categorías</option>
                    <?php 
                    // Resetear el puntero del resultado de categorías
                    $result_categorias->data_seek(0);
                    while ($categoria = $result_categorias->fetch_assoc()): 
                        // Excluir también en el filtro las categorías no deseadas
                        if (!in_array(strtolower($categoria['nombre']), ['enseres', 'general'])): ?>
                        <option value="<?php echo $categoria['id']; ?>"><?php echo $categoria['nombre']; ?></option>
                        <?php endif; ?>
                    <?php endwhile; ?>
                </select>
                <select id="filterStock" class="filter-select" onchange="filtrarProductos()">
                    <option value="">Todo el stock</option>
                    <option value="bajo">Stock Bajo</option>
                    <option value="agotado">Agotados</option>
                </select>
            </div>
        </div>

        <!-- Información de paginación -->
        <div class="pagination-info">
            Mostrando <?php echo ($offset + 1); ?> - <?php echo min($offset + $productos_por_pagina, $total_productos); ?> de <?php echo $total_productos; ?> productos
        </div>

        <!-- Tabla de productos -->
        <div class="table-container">
            <div class="table-responsive">
                <table id="tablaProductos">
                    <thead>
                        <tr>
                            <th>Imagen</th>
                            <th>Nombre</th>
                            <th>Categoría</th>
                            <th>Precio Compra</th>
                            <th>Precio Venta</th>
                            <th>Stock</th>
                            <th>Proveedor</th>
                            <?php if ($_SESSION['user_role'] == 'gerente'): ?>
                                <th>Acciones</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_productos && $result_productos->num_rows > 0): ?>
                            <?php 
                            // Guardar los productos en un array para usar en JavaScript
                            $productos_data = array();
                            while ($producto = $result_productos->fetch_assoc()): 
                                $productos_data[$producto['id']] = $producto;
                            ?>
                                <tr data-id="<?php echo $producto['id']; ?>" 
                                    data-categoria="<?php echo $producto['id_categoria']; ?>"
                                    data-stock="<?php echo $producto['stock']; ?>"
                                    data-stock-minimo="<?php echo $producto['stock_minimo']; ?>">
                                    <td>
                                        <?php if ($producto['imagen']): ?>
                                            <img src="uploads/productos/<?php echo $producto['imagen']; ?>" 
                                                 alt="<?php echo htmlspecialchars($producto['nombre']); ?>" 
                                                 class="product-image"
                                                 onerror="this.style.display='none'">
                                        <?php else: ?>
                                            <div style="width:50px;height:50px;background:#333;display:flex;align-items:center;justify-content:center;border-radius:5px;">
                                                <small>Sin img</small>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($producto['nombre']); ?></strong>
                                        <?php if ($producto['descripcion']): ?>
                                            <br><small style="color: #ccc;"><?php echo htmlspecialchars(substr($producto['descripcion'], 0, 50)); ?>...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($producto['categoria_nombre']); ?></td>
                                    <td>Q <?php echo number_format($producto['precio_compra'], 2); ?></td>
                                    <td>Q <?php echo number_format($producto['precio_venta'], 2); ?></td>
                                    <td>
                                        <span class="<?php echo $producto['stock'] <= $producto['stock_minimo'] ? 'stock-bajo' : 'stock-ok'; ?>">
                                            <?php echo $producto['stock']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($producto['proveedor_nombre']): ?>
                                            <?php echo htmlspecialchars($producto['proveedor_nombre']); ?>
                                        <?php else: ?>
                                            <small style="color: #666;">N/A</small>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($_SESSION['user_role'] == 'gerente'): ?>
                                        <td>
                                            <button class="btn btn-sm btn-warning" onclick="editarProducto(<?php echo $producto['id']; ?>)">Editar</button>
                                            <button class="btn btn-sm btn-danger" onclick="eliminarProducto(<?php echo $producto['id']; ?>)">Eliminar</button>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?php echo $_SESSION['user_role'] == 'gerente' ? '8' : '7'; ?>" style="text-align: center; padding: 20px;">
                                    No hay productos en el inventario (excluyendo enseres y general)
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Paginación -->
        <?php if ($total_paginas > 1): ?>
        <div class="pagination">
            <!-- Primera página -->
            <a href="inventario.php?pagina=1" class="page-link <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                &laquo; Primera
            </a>

            <!-- Página anterior -->
            <a href="inventario.php?pagina=<?php echo max(1, $pagina_actual - 1); ?>" class="page-link <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>">
                &lsaquo; Anterior
            </a>

            <!-- Números de página -->
            <div class="pagination-controls">
                <?php
                // Mostrar páginas alrededor de la actual
                $inicio = max(1, $pagina_actual - 2);
                $fin = min($total_paginas, $pagina_actual + 2);
                
                for ($i = $inicio; $i <= $fin; $i++): 
                ?>
                    <a href="inventario.php?pagina=<?php echo $i; ?>" class="page-link <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>

            <!-- Página siguiente -->
            <a href="inventario.php?pagina=<?php echo min($total_paginas, $pagina_actual + 1); ?>" class="page-link <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                Siguiente &rsaquo;
            </a>

            <!-- Última página -->
            <a href="inventario.php?pagina=<?php echo $total_paginas; ?>" class="page-link <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>">
                Última &raquo;
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal para productos -->
    <div id="modalProducto" class="modal">
        <div class="modal-content">
            <h3 id="modalProductoTitulo">Agregar Nuevo Producto</h3>
            
            <form id="formProducto" method="POST" enctype="multipart/form-data">
                <input type="hidden" id="productoId" name="id">
                
                <div class="form-group">
                    <label>Nombre del Producto:</label>
                    <input type="text" id="productoNombre" name="nombre" required class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Descripción:</label>
                    <textarea id="productoDescripcion" name="descripcion" class="form-control" style="height: 80px;"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Categoría:</label>
                        <select id="productoCategoria" name="id_categoria" required class="form-control">
                            <option value="">Seleccionar categoría</option>
                            <?php 
                            $result_categorias->data_seek(0);
                            while ($categoria = $result_categorias->fetch_assoc()): 
                                if (!in_array(strtolower($categoria['nombre']), ['enseres', 'general'])): ?>
                                <option value="<?php echo $categoria['id']; ?>"><?php echo $categoria['nombre']; ?></option>
                                <?php endif; ?>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Proveedor:</label>
                        <select id="productoProveedor" name="id_proveedor" class="form-control">
                            <option value="">Sin proveedor</option>
                            <?php 
                            $result_proveedores->data_seek(0);
                            while ($proveedor = $result_proveedores->fetch_assoc()): ?>
                                <option value="<?php echo $proveedor['id']; ?>"><?php echo $proveedor['nombre']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Precio de Compra (Q):</label>
                        <input type="number" id="productoPrecioCompra" name="precio_compra" step="0.01" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label>Precio de Venta (Q):</label>
                        <input type="number" id="productoPrecioVenta" name="precio_venta" step="0.01" required class="form-control">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Stock:</label>
                        <input type="number" id="productoStock" name="stock" value="0" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label>Stock Mínimo:</label>
                        <input type="number" id="productoStockMinimo" name="stock_minimo" value="0" class="form-control">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Imagen:</label>
                    <input type="file" id="productoImagen" name="imagen" accept="image/*" class="form-control">
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn btn-danger" onclick="cerrarModalProducto()">Cancelar</button>
                    <button type="submit" class="btn btn-success" name="agregar_producto" id="btnSubmitProducto">Agregar Producto</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para combos -->
    <div id="modalCombo" class="modal">
        <div class="modal-content">
            <h3 id="modalComboTitulo">Crear Nuevo Combo</h3>
            
            <form id="formCombo" method="POST" enctype="multipart/form-data">
                <input type="hidden" id="comboId" name="combo_id">
                
                <div class="form-group">
                    <label>Nombre del Combo:</label>
                    <input type="text" id="comboNombre" name="nombre" required class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Descripción:</label>
                    <textarea id="comboDescripcion" name="descripcion" class="form-control" style="height: 80px;"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Precio de Venta (Q):</label>
                        <input type="number" id="comboPrecio" name="precio_venta" step="0.01" required class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Precio Regular (Q) (opcional):</label>
                        <input type="number" id="comboPrecioAfter" name="precio_after" step="0.01" class="form-control">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Imagen:</label>
                    <input type="file" id="comboImagen" name="imagen" accept="image/*" class="form-control">
                </div>
                
                <!-- Selección de productos -->
                <div class="form-group">
                    <label>Productos del Combo:</label>
                    <div id="productosCombo" style="border: 1px solid #444; padding: 10px; border-radius: 4px; max-height: 200px; overflow-y: auto;">
                        <!-- Los productos se agregarán aquí dinámicamente -->
                    </div>
                    <button type="button" class="btn btn-sm" onclick="agregarProductoCombo()" style="margin-top: 10px;">+ Agregar Producto</button>
                </div>
                
                <!-- Días de la semana -->
                <div class="form-group">
                    <label>Días activo:</label>
                    <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 5px;">
                        <?php
                        $dias_semana = [
                            'lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miércoles',
                            'jueves' => 'Jueves', 'viernes' => 'Viernes', 'sabado' => 'Sábado', 'domingo' => 'Domingo'
                        ];
                        foreach ($dias_semana as $key => $dia): ?>
                            <label style="display: flex; align-items: center; gap: 5px;">
                                <input type="checkbox" name="dias[]" value="<?php echo $key; ?>" class="dia-checkbox">
                                <?php echo $dia; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn btn-danger" onclick="cerrarModalCombo()">Cancelar</button>
                    <button type="submit" class="btn btn-success" name="guardar_combo">Guardar Combo</button>
                </div>
            </form>
        </div>
    </div>


    <script>
        // Datos de los productos disponibles en JavaScript
        const productosData = <?php echo json_encode($productos_data); ?>;

        // Función para filtrar productos
        function filtrarProductos() {
            const searchTerm = document.getElementById('searchProducto').value.toLowerCase();
            const filterCategoria = document.getElementById('filterCategoria').value;
            const filterStock = document.getElementById('filterStock').value;
            const filas = document.querySelectorAll('#tablaProductos tbody tr');
            
            filas.forEach(fila => {
                const nombre = fila.cells[1].textContent.toLowerCase();
                const categoria = fila.getAttribute('data-categoria');
                const stock = parseInt(fila.getAttribute('data-stock'));
                const stockMinimo = parseInt(fila.getAttribute('data-stock-minimo'));
                
                let coincideBusqueda = nombre.includes(searchTerm);
                let coincideCategoria = !filterCategoria || categoria === filterCategoria;
                let coincideStock = true;
                
                if (filterStock === 'bajo') {
                    coincideStock = stock <= stockMinimo;
                } else if (filterStock === 'agotado') {
                    coincideStock = stock === 0;
                }
                
                fila.style.display = (coincideBusqueda && coincideCategoria && coincideStock) ? '' : 'none';
            });
        }

        // Funciones para productos
        function mostrarModalProducto() {
            document.getElementById('modalProducto').style.display = 'flex';
            document.getElementById('modalProductoTitulo').textContent = 'Agregar Nuevo Producto';
            document.getElementById('formProducto').reset();
            document.getElementById('productoId').value = '';
            document.getElementById('btnSubmitProducto').name = 'agregar_producto';
            document.getElementById('btnSubmitProducto').textContent = 'Agregar Producto';
        }

        function cerrarModalProducto() {
            document.getElementById('modalProducto').style.display = 'none';
        }

        function editarProducto(id) {
            const producto = productosData[id];
            if (!producto) return;

            document.getElementById('modalProducto').style.display = 'flex';
            document.getElementById('modalProductoTitulo').textContent = 'Editar Producto';
            document.getElementById('productoId').value = producto.id;
            document.getElementById('productoNombre').value = producto.nombre;
            document.getElementById('productoDescripcion').value = producto.descripcion || '';
            document.getElementById('productoCategoria').value = producto.id_categoria;
            document.getElementById('productoProveedor').value = producto.id_proveedor || '';
            document.getElementById('productoPrecioCompra').value = producto.precio_compra;
            document.getElementById('productoPrecioVenta').value = producto.precio_venta;
            document.getElementById('productoStock').value = producto.stock;
            document.getElementById('productoStockMinimo').value = producto.stock_minimo;
            
            document.getElementById('btnSubmitProducto').name = 'editar_producto';
            document.getElementById('btnSubmitProducto').textContent = 'Actualizar Producto';
        }

        function eliminarProducto(id) {
            Swal.fire({
                title: '¿Desactivar producto?',
                text: "El producto se marcará como inactivo y no aparecerá en el inventario. Esta acción se puede revertir editando el producto.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sí, desactivar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.style.display = 'none';
                    
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'eliminar_producto';
                    input.value = '1';
                    form.appendChild(input);
                    
                    const inputId = document.createElement('input');
                    inputId.type = 'hidden';
                    inputId.name = 'id';
                    inputId.value = id;
                    form.appendChild(inputId);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Funciones para combos
function mostrarModalCombo(comboId = null) {
    document.getElementById('modalCombo').style.display = 'flex';
    document.getElementById('comboId').value = comboId || '';
    
    if (comboId) {
        document.getElementById('modalComboTitulo').textContent = 'Editar Combo';
        // Cargar datos del combo via AJAX
        cargarDatosCombo(comboId);
    } else {
        document.getElementById('modalComboTitulo').textContent = 'Crear Nuevo Combo';
        document.getElementById('formCombo').reset();
        document.getElementById('productosCombo').innerHTML = '';
        
        // Limpiar checkboxes
        document.querySelectorAll('.dia-checkbox').forEach(cb => cb.checked = false);
        
        // Agregar un producto por defecto
        agregarProductoCombo();
    }
}

function cargarDatosCombo(comboId) {
    fetch(`inventario.php?editar_combo=${comboId}&ajax=1`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const combo = data.combo;
                
                // Llenar campos del formulario
                document.getElementById('comboNombre').value = combo.nombre || '';
                document.getElementById('comboDescripcion').value = combo.descripcion || '';
                document.getElementById('comboPrecio').value = combo.precio_venta || '';
                document.getElementById('comboPrecioAfter').value = combo.precio_after || '';
                
                // Llenar productos
                document.getElementById('productosCombo').innerHTML = '';
                if (combo.productos && combo.productos.length > 0) {
                    combo.productos.forEach((producto, index) => {
                        agregarProductoComboEditar(producto.id_producto, producto.cantidad);
                    });
                } else {
                    agregarProductoCombo();
                }
                
                // Llenar días
                document.querySelectorAll('.dia-checkbox').forEach(cb => {
                    cb.checked = combo.dias && combo.dias.includes(cb.value);
                });
                
            } else {
                Swal.fire('Error', 'No se pudieron cargar los datos del combo', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Error al cargar los datos del combo', 'error');
        });
}

function agregarProductoComboEditar(productoId = '', cantidad = 1) {
    const contenedor = document.getElementById('productosCombo');
    const index = contenedor.children.length;
    
    const div = document.createElement('div');
    div.className = 'producto-combo-item';
    div.style.display = 'flex';
    div.style.gap = '10px';
    div.style.marginBottom = '10px';
    div.style.alignItems = 'center';
    
    div.innerHTML = `
        <select name="producto_id[]" required style="flex: 2; padding: 8px; background: #333; border: 1px solid #444; color: white; border-radius: 4px;">
            <option value="">Seleccionar producto</option>
            <?php 
            $result_productos_select = $conn->query("SELECT id, nombre, stock FROM productos WHERE stock > 0 AND activo = 1 ORDER BY nombre");
            while ($prod = $result_productos_select->fetch_assoc()): ?>
                <option value="<?php echo $prod['id']; ?>" ${productoId == <?php echo $prod['id']; ?> ? 'selected' : ''}>
                    <?php echo htmlspecialchars($prod['nombre']); ?> (Stock: <?php echo $prod['stock']; ?>)
                </option>
            <?php endwhile; ?>
        </select>
        <input type="number" name="producto_cantidad[]" value="${cantidad}" min="1" required style="flex: 1; padding: 8px; background: #333; border: 1px solid #444; color: white; border-radius: 4px;" placeholder="Cantidad">
        <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove()">X</button>
    `;
    
    // Seleccionar el producto si se proporciona un ID
    if (productoId) {
        setTimeout(() => {
            const select = div.querySelector('select');
            if (select) {
                select.value = productoId;
            }
        }, 100);
    }
    
    contenedor.appendChild(div);
}

function agregarProductoCombo() {
    agregarProductoComboEditar('', 1);
}
// Manejar el envío del formulario de combos
document.getElementById('formCombo').addEventListener('submit', function(e) {
    e.preventDefault();
    console.log('DEBUG: Formulario de combo enviado');
    
    // Validaciones básicas
    const nombre = document.getElementById('comboNombre').value.trim();
    const precio = parseFloat(document.getElementById('comboPrecio').value);
    const productos = document.querySelectorAll('#productosCombo .producto-combo-item');
    const diasSeleccionados = document.querySelectorAll('.dia-checkbox:checked');
    
    console.log('DEBUG: Validando datos - Nombre:', nombre, 'Precio:', precio);
    console.log('DEBUG: Productos encontrados:', productos.length);
    console.log('DEBUG: Días seleccionados:', diasSeleccionados.length);
    
    if (!nombre) {
        Swal.fire('Error', 'El nombre del combo es obligatorio', 'error');
        return;
    }
    
    if (!precio || precio <= 0 || isNaN(precio)) {
        Swal.fire('Error', 'El precio de venta debe ser mayor a 0', 'error');
        return;
    }
    
    if (productos.length === 0) {
        Swal.fire('Error', 'Debe agregar al menos un producto al combo', 'error');
        return;
    }
    
    if (diasSeleccionados.length === 0) {
        Swal.fire('Error', 'Debe seleccionar al menos un día', 'error');
        return;
    }
    
    // Validar que todos los productos tengan valores válidos
    let productosValidos = true;
    productos.forEach(producto => {
        const select = producto.querySelector('select');
        const input = producto.querySelector('input[type="number"]');
        
        if (!select.value || !input.value || input.value <= 0) {
            productosValidos = false;
            select.style.borderColor = 'red';
            input.style.borderColor = 'red';
        } else {
            select.style.borderColor = '';
            input.style.borderColor = '';
        }
    });
    
    if (!productosValidos) {
        Swal.fire('Error', 'Todos los productos deben estar correctamente configurados', 'error');
        return;
    }
    
    console.log('DEBUG: Todas las validaciones pasaron');
    
    // Mostrar loader
    Swal.fire({
        title: 'Guardando combo...',
        text: 'Por favor espere',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    // Crear FormData manualmente para asegurar que todos los datos se capturen
    const formData = new FormData();
    
    // Agregar campos básicos
    formData.append('guardar_combo', '1');
    formData.append('combo_id', document.getElementById('comboId').value);
    formData.append('nombre', nombre);
    formData.append('descripcion', document.getElementById('comboDescripcion').value);
    formData.append('precio_venta', precio);
    
    const precioAfter = document.getElementById('comboPrecioAfter').value;
    if (precioAfter && precioAfter > 0) {
        formData.append('precio_after', precioAfter);
    }
    
    // Agregar imagen si existe
    const imagenInput = document.getElementById('comboImagen');
    if (imagenInput.files[0]) {
        formData.append('imagen', imagenInput.files[0]);
    }
    
    // Agregar productos
    productos.forEach((producto, index) => {
        const select = producto.querySelector('select');
        const input = producto.querySelector('input[type="number"]');
        
        if (select.value && input.value) {
            formData.append('producto_id[]', select.value);
            formData.append('producto_cantidad[]', input.value);
        }
    });
    
    // Agregar días
    diasSeleccionados.forEach((checkbox, index) => {
        formData.append('dias[]', checkbox.value);
    });
    
    console.log('DEBUG: Enviando FormData...');
    
    // Enviar formulario
    fetch('inventario.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('DEBUG: Respuesta recibida, status:', response.status);
        if (!response.ok) {
            throw new Error(`Error HTTP: ${response.status}`);
        }
        return response.text();
    })
    .then(data => {
        console.log('DEBUG: Datos recibidos del servidor');
        
        // Verificar si la respuesta indica éxito (redirección)
        if (data.includes('Location: inventario.php?exito=1')) {
            Swal.fire({
                icon: 'success',
                title: 'Éxito',
                text: 'Combo guardado correctamente',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                window.location.href = 'inventario.php?exito=1';
            });
        } else if (data.includes('mensaje_error') || data.includes('Error al guardar combo')) {
            // Extraer mensaje de error si existe en la respuesta
            const errorMatch = data.match(/mensaje_error[^>]*>([^<]+)/);
            const errorMsg = errorMatch ? errorMatch[1] : 'Error desconocido al guardar el combo';
            Swal.fire('Error', errorMsg, 'error');
        } else {
            // Si no hay indicadores claros, asumir éxito
            Swal.fire({
                icon: 'success',
                title: 'Éxito',
                text: 'Combo guardado correctamente',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                window.location.reload();
            });
        }
    })
    .catch(error => {
        console.error('DEBUG: Error en fetch:', error);
        Swal.fire('Error', 'Error de conexión: ' + error.message, 'error');
    });
});
function cerrarModalCombo() {
    document.getElementById('modalCombo').style.display = 'none';
}

function editarCombo(id) {
    mostrarModalCombo(id);
}

function eliminarCombo(id) {
    Swal.fire({
        title: '¿Desactivar combo?',
        text: "El combo se marcará como inactivo y no aparecerá en el inventario.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sí, desactivar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'eliminar_combo';
            input.value = '1';
            form.appendChild(input);
            
            const inputId = document.createElement('input');
            inputId.type = 'hidden';
            inputId.name = 'combo_id';
            inputId.value = id;
            form.appendChild(inputId);
            
            document.body.appendChild(form);
            form.submit();
        }
    });
}
        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Sistema de inventario cargado');
            console.log('Total de productos cargados:', Object.keys(productosData).length);
            console.log('Página actual:', <?php echo $pagina_actual; ?>);
            console.log('Total de páginas:', <?php echo $total_paginas; ?>);
        });
    </script>
</body>
</html>