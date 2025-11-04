<?php
session_start();
include 'config_hora.php';

if (!isset($_SESSION['user_id'])) {
    die('Acceso denegado');
}

include 'conexion.php';
include 'funciones.php';

$tipo = $_GET['tipo'] ?? 'todas';
$exportar = $_GET['exportar'] ?? false;
$user_id = $_SESSION['user_id'];

// FUNCIÓN CORREGIDA PARA SINCRONIZAR EMPLEADO
function sincronizarEmpleado($conn, $user_id, $user_name, $user_role) {
    // Verificar si el empleado existe usando la estructura correcta
    $sql_check = "SELECT id FROM empleados WHERE id = ?";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("i", $user_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows === 0) {
        // El empleado no existe, crearlo con la estructura correcta
        // Primero determinamos las columnas disponibles
        $sql_columns = "DESCRIBE empleados";
        $result_columns = $conn->query($sql_columns);
        $columns = [];
        while ($row = $result_columns->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
        
        // Construir query dinámicamente basado en las columnas disponibles
        $available_columns = [];
        $values = [];
        $types = "";
        
        if (in_array('id', $columns)) {
            $available_columns[] = 'id';
            $values[] = $user_id;
            $types .= "i";
        }
        
        if (in_array('nombre', $columns)) {
            $available_columns[] = 'nombre';
            $values[] = $user_name;
            $types .= "s";
        }
        
        if (in_array('usuario', $columns)) {
            $available_columns[] = 'usuario';
            $usuario = generarUsuario($user_name);
            $values[] = $usuario;
            $types .= "s";
        }
        
        if (in_array('user', $columns)) { // Si la columna se llama 'user' en lugar de 'usuario'
            $available_columns[] = 'user';
            $usuario = generarUsuario($user_name);
            $values[] = $usuario;
            $types .= "s";
        }
        
        if (in_array('rol', $columns)) {
            $available_columns[] = 'rol';
            $values[] = $user_role;
            $types .= "s";
        }
        
        if (in_array('role', $columns)) { // Si la columna se llama 'role'
            $available_columns[] = 'role';
            $values[] = $user_role;
            $types .= "s";
        }
        
        if (in_array('estado', $columns)) {
            $available_columns[] = 'estado';
            $values[] = 'activo';
            $types .= "s";
        }
        
        if (in_array('created_at', $columns)) {
            $available_columns[] = 'created_at';
            $values[] = date('Y-m-d H:i:s');
            $types .= "s";
        }
        
        // Construir la consulta INSERT
        $placeholders = str_repeat('?, ', count($available_columns) - 1) . '?';
        $sql_insert = "INSERT INTO empleados (" . implode(', ', $available_columns) . ") 
                      VALUES ($placeholders)";
        
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->bind_param($types, ...$values);
        
        if ($stmt_insert->execute()) {
            error_log("✅ Empleado creado automáticamente: " . $user_name . " (ID: " . $user_id . ")");
            return true;
        } else {
            error_log("❌ Error al crear empleado: " . $conn->error);
            return false;
        }
    }
    
    return true;
}

function generarUsuario($nombre) {
    $usuario = strtolower(trim($nombre));
    $usuario = preg_replace('/[^a-z0-9]/', '', $usuario);
    return $usuario;
}

// Sincronizar empleado
if (!sincronizarEmpleado($conn, $user_id, $_SESSION['user_name'], $_SESSION['user_role'])) {
    // Si falla la sincronización, usar una alternativa
    $sql_alternativa = "SELECT id FROM empleados ORDER BY id LIMIT 1";
    $result_alt = $conn->query($sql_alternativa);
    if ($result_alt->num_rows > 0) {
        $emp_alt = $result_alt->fetch_assoc();
        $user_id = $emp_alt['id'];
        error_log("⚠️ Usando empleado alternativo ID: " . $user_id);
    } else {
        die("No se pudo sincronizar el empleado y no hay empleados alternativos.");
    }
}

// VERIFICAR TURNO CON EL USER_ID CORREGIDO
$turno_abierto = false;
$turno_id = null;
$fecha_apertura_turno = null;

$sql_turno = "SELECT id, fecha_apertura FROM turnos 
              WHERE id_empleado = ? AND estado = 'abierto' 
              ORDER BY id DESC LIMIT 1";
$stmt_turno = $conn->prepare($sql_turno);
$stmt_turno->bind_param("i", $user_id);
$stmt_turno->execute();
$result_turno = $stmt_turno->get_result();

if ($result_turno->num_rows > 0) {
    $turno = $result_turno->fetch_assoc();
    $turno_abierto = true;
    $turno_id = $turno['id'];
    $fecha_apertura_turno = $turno['fecha_apertura'];
}

// Si se solicita exportar a PDF
if ($exportar == 'pdf') {
    exportarReportePDF($tipo);
    exit;
}

// Si se solicita finalizar turno (SOLO DEL USUARIO ACTUAL)
if (isset($_POST['finalizar_turno'])) {
    if ($turno_abierto && $turno_id) {
        // Calcular totales del turno DEL USUARIO ACTUAL
        $sql_ventas_turno = "SELECT 
            COALESCE(SUM(
                CASE 
                    WHEN v.metodo_pago != 'parcial' THEN v.total 
                    ELSE 0 
                END
            ), 0) as ventas_normales,
            COALESCE((
                SELECT SUM(vp.monto) 
                FROM venta_pagos vp 
                JOIN ventas v2 ON vp.id_venta = v2.id 
                WHERE v2.created_at >= ? 
                AND v2.id_empleado = ? 
                AND v2.metodo_pago = 'parcial'
            ), 0) as ventas_parciales
        FROM ventas v 
        WHERE v.created_at >= ? 
            AND v.id_empleado = ?";  // Filtrar por empleado

        $stmt_ventas = $conn->prepare($sql_ventas_turno);
        $stmt_ventas->bind_param("sisi", $fecha_apertura_turno, $user_id, $fecha_apertura_turno, $user_id);
        $stmt_ventas->execute();
        $result_ventas = $stmt_ventas->get_result();
        
        $ventas_totales_turno = 0;
        if ($result_ventas->num_rows > 0) {
            $row = $result_ventas->fetch_assoc();
            $ventas_normales = $row['ventas_normales'] ? $row['ventas_normales'] : 0;
            $ventas_parciales = $row['ventas_parciales'] ? $row['ventas_parciales'] : 0;
            $ventas_totales_turno = $ventas_normales + $ventas_parciales;
        }
        
        // Cerrar el turno DEL USUARIO ACTUAL
$fecha_cierre = obtenerHoraGuatemala();
$sql_cerrar_turno = "UPDATE turnos 
                    SET fecha_cierre = ?, 
                        ventas_totales = ?,
                        estado = 'cerrado' 
                    WHERE id = ? AND id_empleado = ?";  // Verificar que sea del usuario
$stmt_cerrar = $conn->prepare($sql_cerrar_turno);
$stmt_cerrar->bind_param("sdii", $fecha_cierre, $ventas_totales_turno, $turno_id, $user_id);
        
        if ($stmt_cerrar->execute()) {
            $_SESSION['mensaje_exito'] = "Turno finalizado exitosamente. Ventas totales: Q" . number_format($ventas_totales_turno, 2);
            $turno_abierto = false;
            $turno_id = null;
            $fecha_apertura_turno = null;
        } else {
            $_SESSION['mensaje_error'] = "Error al finalizar el turno";
        }
    }
}


// Si se solicita abrir turno (SOLO PARA EL USUARIO ACTUAL)
if (isset($_POST['abrir_turno'])) {
    if (!$turno_abierto) {
        $fecha_apertura = obtenerHoraGuatemala();
        $sql_abrir_turno = "INSERT INTO turnos (id_empleado, fecha_apertura, estado) VALUES (?, ?, 'abierto')";
        $stmt_abrir = $conn->prepare($sql_abrir_turno);
        $stmt_abrir->bind_param("is", $user_id, $fecha_apertura);  // Usar $user_id específico
        
        if ($stmt_abrir->execute()) {
            $_SESSION['mensaje_exito'] = "Turno abierto exitosamente";
            $turno_abierto = true;
            $turno_id = $conn->insert_id;
            $fecha_apertura_turno = $fecha_apertura; // Usar la misma fecha que guardamos
            
            // Re-consultar el turno para obtener la fecha de apertura
            $stmt_turno->execute();
            $result_turno = $stmt_turno->get_result();
            $turno = $result_turno->fetch_assoc();
            $fecha_apertura_turno = $turno['fecha_apertura'];
        } else {
            $_SESSION['mensaje_error'] = "Error al abrir el turno";
        }
    }
}

// Obtener estadísticas para el dashboard (basadas en el turno actual o día completo si no hay turno)
$ventas_hoy = 0;

if ($turno_abierto) {
    // Usar fecha del turno abierto
    $sql_ventas = "SELECT 
        COALESCE(SUM(
            CASE 
                WHEN v.metodo_pago != 'parcial' THEN v.total 
                ELSE 0 
            END
        ), 0) as ventas_normales,
        COALESCE((
            SELECT SUM(vp.monto) 
            FROM venta_pagos vp 
            JOIN ventas v2 ON vp.id_venta = v2.id 
            WHERE v2.created_at >= ? 
            AND v2.id_empleado = ? 
            AND v2.metodo_pago = 'parcial'
        ), 0) as ventas_parciales
    FROM ventas v 
    WHERE v.created_at >= ? 
        AND v.id_empleado = ?";

    $stmt = $conn->prepare($sql_ventas);
    $stmt->bind_param("sisi", $fecha_apertura_turno, $_SESSION['user_id'], $fecha_apertura_turno, $_SESSION['user_id']);
} else {
    // Usar fecha actual (comportamiento original)
    $sql_ventas = "SELECT 
        COALESCE(SUM(
            CASE 
                WHEN v.metodo_pago != 'parcial' THEN v.total 
                ELSE 0 
            END
        ), 0) as ventas_normales,
        COALESCE((
            SELECT SUM(vp.monto) 
            FROM venta_pagos vp 
            JOIN ventas v2 ON vp.id_venta = v2.id 
            WHERE DATE(CONVERT_TZ(v2.created_at, 'SYSTEM', '-6:00')) = CURDATE() 
            AND v2.id_empleado = ? 
            AND v2.metodo_pago = 'parcial'
        ), 0) as ventas_parciales
    FROM ventas v 
    WHERE DATE(CONVERT_TZ(v.created_at, 'SYSTEM', '-6:00')) = CURDATE() 
        AND v.id_empleado = ?";

    $stmt = $conn->prepare($sql_ventas);
    $stmt->bind_param("ii", $_SESSION['user_id'], $_SESSION['user_id']);
}

$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $ventas_normales = $row['ventas_normales'] ? $row['ventas_normales'] : 0;
    $ventas_parciales = $row['ventas_parciales'] ? $row['ventas_parciales'] : 0;
    $ventas_hoy = $ventas_normales + $ventas_parciales;
}
$sql_stock = "SELECT COUNT(*) as count FROM productos WHERE stock <= stock_minimo";
$result = $conn->query($sql_stock);
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $productos_stock_bajo = $row['count'];
}

$sql_clientes = "SELECT COUNT(*) as count FROM clientes WHERE DATE(created_at) = CURDATE()";
$result = $conn->query($sql_clientes);
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $clientes_nuevos = $row['count'];
}

// Obtener estadísticas detalladas de ventas por método de pago (INCLUYENDO PAGOS PARCIALES)
$efectivo_hoy = 0;
$tarjeta_hoy = 0;
$transferencia_hoy = 0;
$billetera_hoy = 0;
$credito_hoy = 0;
$parcial_hoy = 0;
// Consulta para ventas normales (no parciales)
if ($turno_abierto) {
    $sql_metodos_pago = "SELECT 
        COALESCE(SUM(CASE WHEN v.metodo_pago = 'efectivo' THEN v.total ELSE 0 END), 0) as efectivo,
        COALESCE(SUM(CASE WHEN v.metodo_pago = 'tarjeta' THEN v.total ELSE 0 END), 0) as tarjeta,
        COALESCE(SUM(CASE WHEN v.metodo_pago = 'transferencia' THEN v.total ELSE 0 END), 0) as transferencia,
        COALESCE(SUM(CASE WHEN v.metodo_pago = 'billetera_movil' THEN v.total ELSE 0 END), 0) as billetera,
        COALESCE(SUM(CASE WHEN v.metodo_pago = 'credito' THEN v.total ELSE 0 END), 0) as credito,
        COALESCE(SUM(CASE WHEN v.metodo_pago = 'parcial' THEN v.total ELSE 0 END), 0) as parcial
    FROM ventas v 
    WHERE v.created_at >= ? 
        AND v.id_empleado = ?";

    $stmt = $conn->prepare($sql_metodos_pago);
    $stmt->bind_param("si", $fecha_apertura_turno, $_SESSION['user_id']);
} else {
    $sql_metodos_pago = "SELECT 
        COALESCE(SUM(CASE WHEN v.metodo_pago = 'efectivo' THEN v.total ELSE 0 END), 0) as efectivo,
        COALESCE(SUM(CASE WHEN v.metodo_pago = 'tarjeta' THEN v.total ELSE 0 END), 0) as tarjeta,
        COALESCE(SUM(CASE WHEN v.metodo_pago = 'transferencia' THEN v.total ELSE 0 END), 0) as transferencia,
        COALESCE(SUM(CASE WHEN v.metodo_pago = 'billetera_movil' THEN v.total ELSE 0 END), 0) as billetera,
        COALESCE(SUM(CASE WHEN v.metodo_pago = 'credito' THEN v.total ELSE 0 END), 0) as credito,
        COALESCE(SUM(CASE WHEN v.metodo_pago = 'parcial' THEN v.total ELSE 0 END), 0) as parcial
    FROM ventas v 
    WHERE DATE(CONVERT_TZ(v.created_at, 'SYSTEM', '-6:00')) = CURDATE() 
        AND v.id_empleado = ?";

    $stmt = $conn->prepare($sql_metodos_pago);
    $stmt->bind_param("i", $_SESSION['user_id']);
}

$stmt->execute();
$result_metodos = $stmt->get_result();
$row_metodos = $result_metodos->fetch_assoc();
// Consulta para pagos parciales (desglosados por método)
if ($turno_abierto) {
    $sql_pagos_parciales = "SELECT 
        vp.metodo_pago,
        COALESCE(SUM(vp.monto), 0) as total
    FROM venta_pagos vp
    JOIN ventas v ON vp.id_venta = v.id
    WHERE v.created_at >= ? 
        AND v.id_empleado = ?
        AND v.metodo_pago = 'parcial'
    GROUP BY vp.metodo_pago";

    $stmt_parcial = $conn->prepare($sql_pagos_parciales);
    $stmt_parcial->bind_param("si", $fecha_apertura_turno, $_SESSION['user_id']);
} else {
    $sql_pagos_parciales = "SELECT 
        vp.metodo_pago,
        COALESCE(SUM(vp.monto), 0) as total
    FROM venta_pagos vp
    JOIN ventas v ON vp.id_venta = v.id
    WHERE DATE(CONVERT_TZ(v.created_at, 'SYSTEM', '-6:00')) = CURDATE() 
        AND v.id_empleado = ?
        AND v.metodo_pago = 'parcial'
    GROUP BY vp.metodo_pago";

    $stmt_parcial = $conn->prepare($sql_pagos_parciales);
    $stmt_parcial->bind_param("i", $_SESSION['user_id']);
}

$stmt_parcial->execute();
$result_parcial = $stmt_parcial->get_result();

// Inicializar arrays para pagos parciales
$pagos_parciales_detalle = [
    'efectivo' => 0,
    'tarjeta' => 0,
    'transferencia' => 0,
    'billetera_movil' => 0,
    'credito' => 0
];
// Sumar pagos parciales por método
while ($row_parcial = $result_parcial->fetch_assoc()) {
    $metodo = $row_parcial['metodo_pago'];
    $monto = $row_parcial['total'];
    
    if (isset($pagos_parciales_detalle[$metodo])) {
        $pagos_parciales_detalle[$metodo] += $monto;
    }
}

// COMBINAR VENTAS NORMALES + PAGOS PARCIALES
$efectivo_hoy = $row_metodos['efectivo'] + $pagos_parciales_detalle['efectivo'];
$tarjeta_hoy = $row_metodos['tarjeta'] + $pagos_parciales_detalle['tarjeta'];
$transferencia_hoy = $row_metodos['transferencia'] + $pagos_parciales_detalle['transferencia'];
$billetera_hoy = $row_metodos['billetera'] + $pagos_parciales_detalle['billetera_movil'];
$credito_hoy = $row_metodos['credito'] + $pagos_parciales_detalle['credito'];
$parcial_hoy = $row_metodos['parcial']; // Este es el total de ventas marcadas como parciales

// Obtener compras del día
$compras_hoy = 0;
$sql_compras = "SELECT SUM(total) as total FROM compras WHERE DATE(fecha_compra) = CURDATE()";
$result_compras = $conn->query($sql_compras);
if ($result_compras->num_rows > 0) {
    $row = $result_compras->fetch_assoc();
    $compras_hoy = $row['total'] ? $row['total'] : 0;
}
// Obtener SALDO TOTAL de caja chica (ingresos - egresos)
$caja_chica_saldo = 0;
$sql_caja = "SELECT 
                SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END) as total_ingresos,
                SUM(CASE WHEN tipo = 'egreso' THEN monto ELSE 0 END) as total_egresos
            FROM caja_chica";
$result_caja = $conn->query($sql_caja);
if ($result_caja && $result_caja->num_rows > 0) {
    $row = $result_caja->fetch_assoc();
    $total_ingresos = $row['total_ingresos'] ? $row['total_ingresos'] : 0;
    $total_egresos = $row['total_egresos'] ? $row['total_egresos'] : 0;
    $caja_chica_saldo = $total_ingresos - $total_egresos;
}

// ========== DATOS PARA GRÁFICOS REALES ==========

// 1. Ventas de la última semana
$ventas_semana = array_fill(0, 7, 0);
$dias_semana = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
if ($turno_abierto) {
    // Para turnos abiertos, mostrar datos desde la apertura del turno
    $sql_ventas_semana = "SELECT 
        DAYOFWEEK(created_at) as dia_semana,
        SUM(total) as total
    FROM ventas 
    WHERE created_at >= ? 
        AND id_empleado = ?
    GROUP BY DAYOFWEEK(created_at)
    ORDER BY dia_semana";

    $stmt_semana = $conn->prepare($sql_ventas_semana);
    $stmt_semana->bind_param("si", $fecha_apertura_turno, $_SESSION['user_id']);
} else {
    $sql_ventas_semana = "SELECT 
        DAYOFWEEK(created_at) as dia_semana,
        SUM(total) as total
    FROM ventas 
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
        AND id_empleado = ?
    GROUP BY DAYOFWEEK(created_at)
    ORDER BY dia_semana";

    $stmt_semana = $conn->prepare($sql_ventas_semana);
    $stmt_semana->bind_param("i", $_SESSION['user_id']);
}

$stmt_semana->execute();
$result_semana = $stmt_semana->get_result();

while ($row = $result_semana->fetch_assoc()) {
    // Ajustar índice (MySQL: 1=Dom, 2=Lun, ..., 7=Sab)
    $index = $row['dia_semana'] - 2; // 2=Lun -> 0, 3=Mar -> 1, etc.
    if ($index < 0) $index = 6; // Domingo (1) va al final (índice 6)
    
    if ($index >= 0 && $index < 7) {
        $ventas_semana[$index] = floatval($row['total']);
    }
}
// 2. Productos más vendidos (últimos 30 días o desde inicio del turno)
$nombres_productos = [];
$cantidades_productos = [];

if ($turno_abierto) {
    $sql_productos_vendidos = "SELECT 
        p.nombre,
        SUM(vd.cantidad) as total_vendido
    FROM venta_detalles vd
    JOIN productos p ON vd.id_producto = p.id
    JOIN ventas v ON vd.id_venta = v.id
    WHERE v.created_at >= ?
        AND v.id_empleado = ?
    GROUP BY p.id, p.nombre
    ORDER BY total_vendido DESC
    LIMIT 5";

    $stmt_productos = $conn->prepare($sql_productos_vendidos);
    $stmt_productos->bind_param("si", $fecha_apertura_turno, $_SESSION['user_id']);
} else {
    $sql_productos_vendidos = "SELECT 
        p.nombre,
        SUM(vd.cantidad) as total_vendido
    FROM venta_detalles vd
    JOIN productos p ON vd.id_producto = p.id
    JOIN ventas v ON vd.id_venta = v.id
    WHERE v.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        AND v.id_empleado = ?
    GROUP BY p.id, p.nombre
    ORDER BY total_vendido DESC
    LIMIT 5";

    $stmt_productos = $conn->prepare($sql_productos_vendidos);
    $stmt_productos->bind_param("i", $_SESSION['user_id']);
}

$stmt_productos->execute();
$result_productos = $stmt_productos->get_result();

while ($row = $result_productos->fetch_assoc()) {
    $nombres_productos[] = $row['nombre'];
    $cantidades_productos[] = intval($row['total_vendido']);
}
// Si no hay datos, usar valores por defecto
if (empty($nombres_productos)) {
    $nombres_productos = ['No hay datos'];
    $cantidades_productos = [0];
}

// 3. Métodos de pago (ya tenemos estos datos)
$metodos_pago_labels = ['Efectivo', 'Tarjeta', 'Transferencia', 'Billetera Móvil', 'Crédito', 'Parcial'];
$metodos_pago_data = [
    $efectivo_hoy,
    $tarjeta_hoy, 
    $transferencia_hoy,
    $billetera_hoy,
    $credito_hoy,
    $parcial_hoy
];

function exportarReportePDF($tipo) {
    global $conn;
    
    // Verificar si TCPDF está disponible
    if (!file_exists('tcpdf/tcpdf.php')) {
        die('Error: No se encontró la librería TCPDF');
    }
    
    // Incluir TCPDF
    require_once('tcpdf/tcpdf.php');
    
    // Crear nuevo PDF EN ORIENTACIÓN VERTICAL
    $pdf = new TCPDF('P', PDF_UNIT, 'A4', true, 'UTF-8', false);
    
    // Configurar documento
    $pdf->SetCreator('Ruta 666 POS');
    $pdf->SetAuthor('Sistema Ruta 666');
    
    $titulos = [
        'todas' => 'Reporte Completo de Ventas',
        'efectivo' => 'Ventas en Efectivo',
        'tarjeta' => 'Ventas con Tarjeta', 
        'transferencia' => 'Ventas por Transferencia',
        'billetera_movil' => 'Ventas con Billetera Móvil',
        'credito' => 'Ventas a Crédito',
        'parcial' => 'Ventas con Pago Parcial',
        'compras' => 'Reporte de Compras',
        'caja_chica' => 'Movimientos de Caja Chica'
    ];
    
    $titulo = $titulos[$tipo] ?? 'Reporte del Sistema';
    $pdf->SetTitle($titulo);
        // Configurar márgenes MÁS PEQUEÑOS para vertical
    $pdf->SetMargins(10, 20, 10);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(8);
    
    // Auto page breaks - CONFIGURACIÓN PARA EVITAR HOJA EXTRA
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Desactivar auto header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Agregar página
    $pdf->AddPage();
    
    // Header personalizado manual - AJUSTADO PARA VERTICAL
    $pdf->SetY(8);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(255, 0, 0);
    $pdf->Cell(0, 10, 'RUTA 666', 0, 1, 'C');
    
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(255, 0, 0);
    $pdf->Line(10, 20, 200, 20);
    
    // Contenido del reporte
    $pdf->SetY(25);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(255, 0, 0);
    $pdf->Cell(0, 8, $titulo, 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 5, 'Fecha: ' . date('d/m/Y'), 0, 1);
    $pdf->Cell(0, 5, 'Generado por: ' . $_SESSION['user_name'], 0, 1);
    $pdf->Cell(0, 5, 'Rol: ' . ucfirst($_SESSION['user_role']), 0, 1);
    $pdf->Ln(5);
    
    // Obtener datos
    $datos = obtenerDatosReportePDF($tipo);
    
    if (empty($datos['ventas']) && empty($datos['compras']) && empty($datos['movimientos'])) {
        $pdf->SetTextColor(255, 0, 0);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'NO HAY DATOS PARA MOSTRAR', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 6, 'No se encontraron registros para la fecha ' . date('d/m/Y'), 0, 1, 'C');
    } else {
        // Generar tabla según el tipo
        generarTablaVerticalPDF($pdf, $datos, $tipo);
    }
        // Footer personalizado SOLO SI HAY ESPACIO SUFICIENTE
    $currentY = $pdf->GetY();
    if ($currentY < 250) { // Si hay espacio en la misma página
        $pdf->SetY(-15);
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 8, 'Página ' . $pdf->getAliasNumPage() . ' de ' . $pdf->getAliasNbPages(), 0, 0, 'C');
        $pdf->Cell(0, 8, 'Generado: ' . date('d/m/Y H:i:s'), 0, 0, 'R');
    }
    
    // Limpiar buffer de salida
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Generar nombre de archivo
    $nombre_archivo = 'Reporte_' . str_replace(' ', '_', $titulo) . '_' . date('Y-m-d') . '.pdf';
    
    // Forzar descarga
    $pdf->Output($nombre_archivo, 'D');
    exit;
}

// Función auxiliar para obtener datos del reporte PDF
function obtenerDatosReportePDF($tipo) {
    global $conn;
    $datos = ['ventas' => [], 'compras' => [], 'movimientos' => []];
    
    // Verificar si hay turno abierto
    $sql_turno = "SELECT fecha_apertura FROM turnos WHERE id_empleado = ? AND estado = 'abierto' ORDER BY id DESC LIMIT 1";
    $stmt_turno = $conn->prepare($sql_turno);
    $stmt_turno->bind_param("i", $_SESSION['user_id']);
    $stmt_turno->execute();
    $result_turno = $stmt_turno->get_result();
    $tiene_turno = $result_turno->num_rows > 0;
    $fecha_turno = $tiene_turno ? $result_turno->fetch_assoc()['fecha_apertura'] : null;
    
    switch ($tipo) {
        case 'todas':
            if ($tiene_turno) {
                $sql = "SELECT v.*, c.nombre as cliente_nombre
                        FROM ventas v 
                        LEFT JOIN clientes c ON v.id_cliente = c.id
                        WHERE v.created_at >= ? AND v.id_empleado = ?
                        ORDER BY v.created_at DESC";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $fecha_turno, $_SESSION['user_id']);
            } else {
                $sql = "SELECT v.*, c.nombre as cliente_nombre
                        FROM ventas v 
                        LEFT JOIN clientes c ON v.id_cliente = c.id
                        WHERE DATE(CONVERT_TZ(v.created_at, 'SYSTEM', '-6:00')) = CURDATE() AND v.id_empleado = ?
                        ORDER BY v.created_at DESC";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $_SESSION['user_id']);
            }
            $stmt->execute();
            $datos['ventas'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            break;
                    case in_array($tipo, ['efectivo', 'tarjeta', 'transferencia', 'billetera_movil', 'credito', 'parcial']):
            if ($tiene_turno) {
                $sql = "SELECT v.*, c.nombre as cliente_nombre
                        FROM ventas v 
                        LEFT JOIN clientes c ON v.id_cliente = c.id
                        WHERE v.created_at >= ? AND v.metodo_pago = ? AND v.id_empleado = ?
                        ORDER BY v.created_at DESC";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssi", $fecha_turno, $tipo, $_SESSION['user_id']);
            } else {
                $sql = "SELECT v.*, c.nombre as cliente_nombre
                        FROM ventas v 
                        LEFT JOIN clientes c ON v.id_cliente = c.id
                        WHERE DATE(CONVERT_TZ(v.created_at, 'SYSTEM', '-6:00')) = CURDATE() AND v.metodo_pago = ? AND v.id_empleado = ?
                        ORDER BY v.created_at DESC";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $tipo, $_SESSION['user_id']);
            }
            $stmt->execute();
            $datos['ventas'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            break;
            
        case 'compras':
            $sql = "SELECT c.*, p.nombre as proveedor_nombre 
                    FROM compras c 
                    LEFT JOIN proveedores p ON c.id_proveedor = p.id
                    WHERE DATE(c.fecha_compra) = CURDATE()
                    ORDER BY c.fecha_compra DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $datos['compras'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            break;
            
        case 'caja_chica':
            $sql = "SELECT cc.*, e.nombre as empleado_nombre 
                    FROM caja_chica cc 
                    LEFT JOIN empleados e ON cc.id_empleado = e.id
                    ORDER BY cc.created_at DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $datos['movimientos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            break;
    }
    
    return $datos;
}

function generarTablaVerticalPDF($pdf, $datos, $tipo) {
        switch ($tipo) {
        case 'compras':
            if (!empty($datos['compras'])) {
                // Cabecera para compras - AJUSTADA PARA VERTICAL
                $header = ['# Compra', 'Proveedor', 'Fecha', 'Total'];
                $widths = [20, 70, 30, 30]; // Ajustadas para vertical
                
                // Color de cabecera
                $pdf->SetFillColor(0, 100, 0);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('helvetica', 'B', 9);
                
                for ($i = 0; $i < count($header); $i++) {
                    $pdf->Cell($widths[$i], 7, $header[$i], 1, 0, 'C', true);
                }
                $pdf->Ln();
                
                $pdf->SetFont('helvetica', '', 8);
                $pdf->SetTextColor(0, 0, 0);
                $total_general = 0;
                $fill = false;
                
                foreach ($datos['compras'] as $compra) {
                    // Verificar espacio para nueva página
                    if ($pdf->GetY() > 250) {
                        $pdf->AddPage();
                        // Volver a poner cabecera
                        $pdf->SetFont('helvetica', 'B', 9);
                        for ($i = 0; $i < count($header); $i++) {
                            $pdf->Cell($widths[$i], 7, $header[$i], 1, 0, 'C', true);
                        }
                        $pdf->Ln();
                        $pdf->SetFont('helvetica', '', 8);
                    }
                    
                    $total_general += $compra['total'];
                    
                    // Color de fondo alternado
                    if ($fill) {
                        $pdf->SetFillColor(240, 240, 240);
                    } else {
                        $pdf->SetFillColor(255, 255, 255);
                    }
                    
                    $pdf->Cell($widths[0], 6, '#' . $compra['id'], 1, 0, 'C', $fill);
                    $pdf->Cell($widths[1], 6, substr($compra['proveedor_nombre'] ?? 'N/A', 0, 30), 1, 0, 'L', $fill);
                    $pdf->Cell($widths[2], 6, date('d/m H:i', strtotime($compra['fecha_compra'])), 1, 0, 'C', $fill);
                    $pdf->Cell($widths[3], 6, 'Q' . number_format($compra['total'], 2), 1, 0, 'R', $fill);
                    $pdf->Ln();
                    $fill = !$fill;
                }
                                // Total general
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->SetFillColor(70, 70, 70);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->Cell(array_sum($widths) - $widths[3], 7, 'TOTAL GENERAL:', 1, 0, 'R', true);
                $pdf->Cell($widths[3], 7, 'Q' . number_format($total_general, 2), 1, 0, 'R', true);
            }
            break;
            
        case 'caja_chica':
            if (!empty($datos['movimientos'])) {
                // Cabecera para caja chica - AJUSTADA PARA VERTICAL
                $header = ['Fecha', 'Tipo', 'Descripción', 'Monto'];
                $widths = [30, 20, 90, 25]; // Ajustadas para vertical
                
                // Color de cabecera
                $pdf->SetFillColor(139, 69, 19);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('helvetica', 'B', 9);
                
                for ($i = 0; $i < count($header); $i++) {
                    $pdf->Cell($widths[$i], 7, $header[$i], 1, 0, 'C', true);
                }
                $pdf->Ln();
                
                $pdf->SetFont('helvetica', '', 8);
                $total_ingresos = 0;
                $total_egresos = 0;
                $fill = false;
                
                foreach ($datos['movimientos'] as $mov) {
                    // Verificar espacio para nueva página
                    if ($pdf->GetY() > 250) {
                        $pdf->AddPage();
                        // Volver a poner cabecera
                        $pdf->SetFont('helvetica', 'B', 9);
                        for ($i = 0; $i < count($header); $i++) {
                            $pdf->Cell($widths[$i], 7, $header[$i], 1, 0, 'C', true);
                        }
                        $pdf->Ln();
                        $pdf->SetFont('helvetica', '', 8);
                    }
                                        if ($mov['tipo'] == 'ingreso') {
                        $total_ingresos += $mov['monto'];
                    } else {
                        $total_egresos += $mov['monto'];
                    }
                    
                    // Color de fondo alternado
                    if ($fill) {
                        $pdf->SetFillColor(240, 240, 240);
                    } else {
                        $pdf->SetFillColor(255, 255, 255);
                    }
                    
                    // Color según tipo
                    if ($mov['tipo'] == 'ingreso') {
                        $pdf->SetTextColor(0, 128, 0);
                    } else {
                        $pdf->SetTextColor(255, 0, 0);
                    }
                    
                    $pdf->Cell($widths[0], 6, date('d/m H:i', strtotime($mov['created_at'])), 1, 0, 'C', $fill);
                    $pdf->Cell($widths[1], 6, ucfirst($mov['tipo']), 1, 0, 'C', $fill);
                    $pdf->Cell($widths[2], 6, substr($mov['descripcion'], 0, 50), 1, 0, 'L', $fill);
                    $pdf->Cell($widths[3], 6, 'Q' . number_format($mov['monto'], 2), 1, 0, 'R', $fill);
                    $pdf->Ln();
                    $fill = !$fill;
                }
                
                $saldo = $total_ingresos - $total_egresos;
                
                // Resumen
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->SetFillColor(70, 70, 70);
                $pdf->SetTextColor(255, 255, 255);
                
                $pdf->Cell(array_sum($widths), 7, 'RESUMEN:', 1, 1, 'C', true);
                $pdf->Cell($widths[0] + $widths[1] + $widths[2], 7, 'Total Ingresos:', 1, 0, 'R', true);
                $pdf->SetTextColor(0, 255, 0);
                $pdf->Cell($widths[3], 7, 'Q' . number_format($total_ingresos, 2), 1, 0, 'R', true);
                $pdf->Ln();
                
                $pdf->SetTextColor(255, 255, 255);
                $pdf->Cell($widths[0] + $widths[1] + $widths[2], 7, 'Total Egresos:', 1, 0, 'R', true);
                $pdf->SetTextColor(255, 0, 0);
                $pdf->Cell($widths[3], 7, 'Q' . number_format($total_egresos, 2), 1, 0, 'R', true);
                $pdf->Ln();
                                $pdf->SetTextColor(255, 255, 255);
                $pdf->Cell($widths[0] + $widths[1] + $widths[2], 7, 'Saldo Final:', 1, 0, 'R', true);
                $pdf->SetTextColor(255, 165, 0);
                $pdf->Cell($widths[3], 7, 'Q' . number_format($saldo, 2), 1, 0, 'R', true);
            }
            break;
            
        default:
            // Para ventas - VERSIÓN VERTICAL
            if (!empty($datos['ventas'])) {
                // Cabecera para ventas - AJUSTADA PARA VERTICAL
                $header = ['# Venta', 'Cliente', 'Hora', 'Total', 'Método'];
                $widths = [18, 55, 20, 25, 32]; // Ajustadas para vertical
                
                // Color de cabecera
                $pdf->SetFillColor(255, 0, 0);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('helvetica', 'B', 9);
                
                for ($i = 0; $i < count($header); $i++) {
                    $pdf->Cell($widths[$i], 7, $header[$i], 1, 0, 'C', true);
                }
                $pdf->Ln();
                
                $pdf->SetFont('helvetica', '', 8);
                $pdf->SetTextColor(0, 0, 0);
                $total_general = 0;
                $fill = false;
                
                foreach ($datos['ventas'] as $venta) {
                    // Verificar espacio para nueva página
                    if ($pdf->GetY() > 250) {
                        $pdf->AddPage();
                        // Volver a poner cabecera
                        $pdf->SetFont('helvetica', 'B', 9);
                        for ($i = 0; $i < count($header); $i++) {
                            $pdf->Cell($widths[$i], 7, $header[$i], 1, 0, 'C', true);
                        }
                        $pdf->Ln();
                        $pdf->SetFont('helvetica', '', 8);
                    }
                                        $total_general += $venta['total'];
                    
                    // Color de fondo alternado
                    if ($fill) {
                        $pdf->SetFillColor(240, 240, 240);
                    } else {
                        $pdf->SetFillColor(255, 255, 255);
                    }
                    
                    $pdf->Cell($widths[0], 6, '#' . $venta['id'], 1, 0, 'C', $fill);
                    $pdf->Cell($widths[1], 6, substr($venta['cliente_nombre'] ?? 'General', 0, 25), 1, 0, 'L', $fill);
                    $pdf->Cell($widths[2], 6, date('H:i', strtotime($venta['created_at'])), 1, 0, 'C', $fill);
                    $pdf->Cell($widths[3], 6, 'Q' . number_format($venta['total'], 2), 1, 0, 'R', $fill);
                    
                    // Color según método de pago
                    switch ($venta['metodo_pago']) {
                        case 'efectivo': $pdf->SetTextColor(0, 128, 0); break;
                        case 'tarjeta': $pdf->SetTextColor(0, 0, 255); break;
                        case 'credito': $pdf->SetTextColor(255, 165, 0); break;
                        default: $pdf->SetTextColor(128, 128, 128);
                    }
                    
                    $pdf->Cell($widths[4], 6, ucfirst($venta['metodo_pago']), 1, 0, 'C', $fill);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->Ln();
                    $fill = !$fill;
                }
                
                // Total general
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->SetFillColor(70, 70, 70);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->Cell(array_sum($widths) - $widths[3], 7, 'TOTAL GENERAL:', 1, 0, 'R', true);
                $pdf->Cell($widths[3], 7, 'Q' . number_format($total_general, 2), 1, 0, 'R', true);
            }
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ruta 666 - Dashboard Bartender</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Metal+Mania&display=swap" rel="stylesheet">
    <style>
        /* Tus estilos CSS existentes se mantienen igual */
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
        }
        .sidebar {
            position: fixed;
            width: 250px;
            height: 100%;
            background-color: var(--dark-bg);
            padding: 20px 0;
            border-right: 1px solid #333;
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
        }
        .menu-item {
            padding: 15px 20px;
            display: block;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.3s;
        }
        .menu-item:hover, .menu-item.active {
            background-color: #333;
            color: var(--accent);
            border-left: 4px solid var(--accent);
        }
        .user-info {
            position: absolute;
            bottom: 20px;
            width: 100%;
            padding: 10px 20px;
            border-top: 1px solid #333;
        }
        .content {
            margin-left: 250px;
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 20px;
            background-color: var(--dark-bg);
            border-bottom: 1px solid #333;
        }
                /* Control de turno */
        .turno-control {
            margin-bottom: 20px;
            padding: 15px;
            background-color: var(--dark-bg);
            border-radius: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid <?php echo $turno_abierto ? '#00ff00' : '#ff0000'; ?>;
        }
        .turno-control h3 {
            margin: 0;
            color: <?php echo $turno_abierto ? '#00ff00' : '#ff0000'; ?>;
        }
        .turno-control small {
            color: #cccccc;
        }
        
        /* Mensajes */
        .mensaje-exito {
            background-color: #00aa00;
            color: white;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .mensaje-error {
            background-color: #ff4444;
            color: white;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        
        /* Estadísticas con Grid de 3 columnas */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background-color: var(--dark-bg);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 4px solid var(--accent);
            min-height: 150px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            transition: transform 0.3s ease;
            cursor: pointer;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(255, 0, 0, 0.2);
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--accent);
            margin: 10px 0;
        }
        .stat-card p {
            color: var(--text-secondary);
            font-size: 0.9em;
            margin: 0;
        }
        .stat-card small {
            color: #ff9900;
            font-size: 0.8em;
            margin-top: 5px;
        }
                .chart-container {
            background-color: var(--dark-bg);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .btn {
            padding: 10px 15px;
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
        
        /* Responsive */
        @media (max-width: 1200px) {
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            .content {
                margin-left: 0;
            }
        }

        /* Modal para reportes */
        .modal-reporte {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background-color: var(--dark-bg);
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 1000px;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #333;
        }
        .modal-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.5em;
            cursor: pointer;
        }
        .tabla-reporte {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .tabla-reporte th,
        .tabla-reporte td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #333;
        }
        .tabla-reporte th {
            background-color: #2a2a2a;
            color: var(--accent);
        }
        .tabla-reporte tr:hover {
            background-color: #2a2a2a;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="logo">RUTA 666</div>
        <a href="dashboard_<?php echo $_SESSION['user_role']; ?>.php" class="menu-item active">Dashboard</a>
        <a href="ventas.php" class="menu-item">Punto de Venta</a>
        <a href="inventario.php" class="menu-item">Inventario</a>
        <a href="clientes.php" class="menu-item">CRM</a>
        <!-- AGREGAR ENLACES FALTANTES -->
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
                <div class="user-info">
            <strong><?php echo $_SESSION['user_name']; ?></strong><br>
            <small><?php echo ucfirst($_SESSION['user_role']); ?></small><br>
            <a href="logout.php" class="btn" style="margin-top: 10px; padding: 5px 10px; font-size: 12px;">Cerrar Sesión</a>
        </div>
    </div>

    <div class="content">
        <div class="header">
            <h1>Dashboard Bartender</h1>
            <span><?php echo obtenerFechaFormateada(); ?></span>
        </div>

        <!-- Control de turno -->
        <div class="turno-control">
            <div>
                <h3><?php echo $turno_abierto ? '🟢 TURNO ABIERTO' : '🔴 TURNO CERRADO'; ?></h3>
                <?php if ($turno_abierto): ?>
                    <small>Abierto desde: <?php echo date('H:i', strtotime($fecha_apertura_turno)); ?></small>
                <?php endif; ?>
            </div>
            
            <div>
                <?php if ($turno_abierto): ?>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="finalizar_turno" class="btn" 
                                onclick="return confirm('¿Estás seguro de que deseas finalizar el turno?')"
                                style="background-color: #ff4444;">
                            🔴 Finalizar Turno
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="abrir_turno" class="btn" 
                                style="background-color: #00aa00;">
                            🟢 Abrir Turno
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Mostrar mensajes -->
        <?php if (isset($_SESSION['mensaje_exito'])): ?>
            <div class="mensaje-exito">
                <?php echo $_SESSION['mensaje_exito']; unset($_SESSION['mensaje_exito']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['mensaje_error'])): ?>
            <div class="mensaje-error">
                <?php echo $_SESSION['mensaje_error']; unset($_SESSION['mensaje_error']); ?>
            </div>
        <?php endif; ?>

        <!-- Grid de 3 columnas para las estadísticas -->
        <div class="stats-container">
            <!-- Card de Ventas Totales Hoy -->
            <div class="stat-card" onclick="generarReporte('todas', 'Todas las Ventas de Hoy')">
                <h3>Mis Ventas <?php echo $turno_abierto ? 'del Turno' : 'Hoy'; ?></h3>
                <div class="stat-number"><?php echo formato_moneda($ventas_hoy); ?></div>
                <p>Total de mis ventas <?php echo $turno_abierto ? 'del turno actual' : 'del día'; ?></p>
                <small>Click para ver reporte detallado</small>
            </div>
            
            <!-- Card de Efectivo -->
            <div class="stat-card" onclick="generarReporte('efectivo', 'Ventas en Efectivo')">
                <h3>Efectivo</h3>
                <div class="stat-number"><?php echo formato_moneda($efectivo_hoy); ?></div>
                <p>Ventas en efectivo <?php echo $turno_abierto ? 'del turno' : 'hoy'; ?></p>
                <small>Click para ver reporte detallado</small>
            </div>
            
            <!-- Card de Tarjeta -->
            <div class="stat-card" onclick="generarReporte('tarjeta', 'Ventas con Tarjeta')">
                <h3>Tarjeta</h3>
                <div class="stat-number"><?php echo formato_moneda($tarjeta_hoy); ?></div>
                <p>Ventas con tarjeta <?php echo $turno_abierto ? 'del turno' : 'hoy'; ?></p>
                <small>Click para ver reporte detallado</small>
            </div>
                        <!-- Card de Transferencia -->
            <div class="stat-card" onclick="generarReporte('transferencia', 'Ventas por Transferencia')">
                <h3>Transferencia</h3>
                <div class="stat-number"><?php echo formato_moneda($transferencia_hoy); ?></div>
                <p>Ventas por transferencia <?php echo $turno_abierto ? 'del turno' : 'hoy'; ?></p>
                <small>Click para ver reporte detallado</small>
            </div>
            
            <!-- Card de Billetera Móvil -->
            <div class="stat-card" onclick="generarReporte('billetera_movil', 'Ventas con Billetera Móvil')">
                <h3>Billetera Móvil</h3>
                <div class="stat-number"><?php echo formato_moneda($billetera_hoy); ?></div>
                <p>Ventas con billetera móvil <?php echo $turno_abierto ? 'del turno' : 'hoy'; ?></p>
                <small>Click para ver reporte detallado</small>
            </div>
            
            <!-- Card de Crédito -->
            <div class="stat-card" onclick="generarReporte('credito', 'Ventas a Crédito')">
                <h3>Crédito</h3>
                <div class="stat-number"><?php echo formato_moneda($credito_hoy); ?></div>
                <p>Ventas a crédito <?php echo $turno_abierto ? 'del turno' : 'hoy'; ?></p>
                <small>Click para ver reporte detallado</small>
            </div>
            
            <!-- Card de Pagos Parciales -->
            <div class="stat-card" onclick="generarReporte('parcial', 'Ventas con Pago Parcial')">
                <h3>Pagos Parciales</h3>
                <div class="stat-number"><?php echo formato_moneda($parcial_hoy); ?></div>
                <p>Ventas con pago parcial <?php echo $turno_abierto ? 'del turno' : 'hoy'; ?></p>
                <small>Click para ver reporte detallado</small>
            </div>
            
            <!-- Card de Compras -->
            <div class="stat-card" onclick="generarReporte('compras', 'Compras del Día')">
                <h3>Compras</h3>
                <div class="stat-number"><?php echo formato_moneda($compras_hoy); ?></div>
                <p>Total de compras hoy</p>
                <small>Click para ver reporte detallado</small>
            </div>
            
            <!-- Card de Caja Chica -->
            <div class="stat-card" onclick="generarReporte('caja_chica', 'Movimientos de Caja Chica')">
                <h3>Caja Chica</h3>
                <div class="stat-number"><?php echo formato_moneda($caja_chica_saldo); ?></div>
                <p>Saldo total en efectivo</p>
                <small>Click para ver reporte detallado</small>
            </div>
        </div>

        <!-- Modal para reportes -->
        <div class="modal-reporte" id="modalReporte">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="modalTitulo">Reporte Detallado</h3>
                    <div>
                        <button class="btn btn-sm btn-danger" onclick="descargarPDFDesdeModal()" style="margin-right: 10px;">
                            📄 Exportar PDF
                        </button>
                        <button class="modal-close" onclick="cerrarModalReporte()">&times;</button>
                    </div>
                </div>
                <div id="contenidoReporte"></div>
            </div>
        </div>

        <div class="chart-container">
            <h3>Mis Ventas <?php echo $turno_abierto ? 'del Turno' : 'de la Semana'; ?></h3>
            <canvas id="ventasChart" height="100"></canvas>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
            <div class="chart-container">
                <h3>Mis Productos Más Vendidos (<?php echo $turno_abierto ? 'Este Turno' : 'Últimos 30 días'; ?>)</h3>
                <canvas id="productosChart" height="200"></canvas>
            </div>
            <div class="chart-container">
                <h3>Métodos de Pago (<?php echo $turno_abierto ? 'Este Turno' : 'Hoy'; ?>)</h3>
                <canvas id="pagosChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <script>
        // Función simplificada para generar reportes
        // Función simplificada para generar reportes
        function generarReporte(tipo, titulo) {
            Swal.fire({
                title: '📊 ' + titulo,
                text: 'Selecciona una opción:',
                icon: 'question',
                showDenyButton: true,
                showCancelButton: true,
                confirmButtonText: '👁️ Ver en Pantalla',
                denyButtonText: '📄 Descargar PDF',
                cancelButtonText: '❌ Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    verReportePantalla(tipo, titulo);
                } else if (result.isDenied) {
                    descargarPDF(tipo, titulo);
                }
            });
        }

        // Función para ver reporte en pantalla
        function verReportePantalla(tipo, titulo) {
            Swal.fire({
                title: 'Generando reporte...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            fetch(`generar_reporte.php?tipo=${tipo}`)
                .then(response => response.text())
                .then(html => {
                    Swal.close();
                    document.getElementById('modalTitulo').textContent = titulo + ' - ' + new Date().toLocaleDateString();
                    document.getElementById('contenidoReporte').innerHTML = html;
                    document.getElementById('modalReporte').style.display = 'flex';
                })
                .catch(error => {
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'No se pudo generar el reporte: ' + error.message
                    });
                });
        }

        // Función para descargar PDF
        function descargarPDF(tipo, titulo) {
            // Mostrar notificación
            Swal.fire({
                title: 'Generando PDF...',
                text: 'Por favor espera',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            // Crear enlace temporal para descarga
            const link = document.createElement('a');
            link.href = `?tipo=${tipo}&exportar=pdf&t=${Date.now()}`;
            link.target = '_blank';
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            // Cerrar notificación después de un tiempo
            setTimeout(() => {
                Swal.close();
                Swal.fire({
                    icon: 'success',
                    title: 'PDF Generado',
                    text: 'El PDF se está descargando',
                    timer: 2000,
                    showConfirmButton: false
                });
            }, 1000);
        }

        // Función para descargar PDF desde el modal
        function descargarPDFDesdeModal() {
            const titulo = document.getElementById('modalTitulo').textContent;
            const tipo = obtenerTipoDesdeTitulo(titulo);
            descargarPDF(tipo, titulo);
        }

        // Función auxiliar para obtener el tipo desde el título
        function obtenerTipoDesdeTitulo(titulo) {
            const mapping = {
                'Todas las Ventas': 'todas',
                'Ventas en Efectivo': 'efectivo',
                'Ventas con Tarjeta': 'tarjeta',
                'Ventas por Transferencia': 'transferencia',
                'Ventas con Billetera Móvil': 'billetera_movil',
                'Ventas a Crédito': 'credito',
                'Ventas con Pago Parcial': 'parcial',
                'Compras del Día': 'compras',
                'Movimientos de Caja Chica': 'caja_chica'
            };
            
            for (const [key, value] of Object.entries(mapping)) {
                if (titulo.includes(key)) {
                    return value;
                }
            }
            return 'todas';
        }

        // Función para cerrar el modal
        function cerrarModalReporte() {
            document.getElementById('modalReporte').style.display = 'none';
        }

        // Cerrar modal al hacer clic fuera
        document.getElementById('modalReporte').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarModalReporte();
            }
        });

        // Cerrar modal con ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cerrarModalReporte();
            }
        });

        // Gráficos (mantén tu código existente de gráficos)
        const ventasCtx = document.getElementById('ventasChart').getContext('2d');
        const ventasChart = new Chart(ventasCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dias_semana); ?>,
                datasets: [{
                    label: 'Ventas Q',
                    data: <?php echo json_encode($ventas_semana); ?>,
                    borderColor: '#ff0000',
                    tension: 0.1,
                    backgroundColor: 'rgba(255, 0, 0, 0.1)',
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        labels: {
                            color: '#fff'
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `Ventas: Q${context.raw.toFixed(2)}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#ccc',
                            callback: function(value) {
                                return 'Q' + value.toLocaleString();
                            }
                        },
                        grid: {
                            color: '#333'
                        }
                    },
                    x: {
                        ticks: {
                            color: '#ccc'
                        },
                        grid: {
                            color: '#333'
                        }
                    }
                }
            }
        });

        const productosCtx = document.getElementById('productosChart').getContext('2d');
        const productosChart = new Chart(productosCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($nombres_productos); ?>,
                datasets: [{
                    label: 'Unidades Vendidas',
                    data: <?php echo json_encode($cantidades_productos); ?>,
                    backgroundColor: '#ff0000'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        labels: {
                            color: '#fff'
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#ccc'
                        },
                        grid: {
                            color: '#333'
                        }
                    },
                    x: {
                        ticks: {
                            color: '#ccc'
                        },
                        grid: {
                            color: '#333'
                        }
                    }
                }
            }
        });

        const pagosCtx = document.getElementById('pagosChart').getContext('2d');
        const pagosChart = new Chart(pagosCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($metodos_pago_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($metodos_pago_data); ?>,
                    backgroundColor: [
                        '#ff0000', '#ff6b6b', '#ff9999', '#cc0000', '#990000', '#660000'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#fff',
                            padding: 15
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return `${label}: Q${value.toFixed(2)} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>