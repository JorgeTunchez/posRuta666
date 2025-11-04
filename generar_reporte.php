<?php
session_start();
include 'config_hora.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    die('Acceso denegado');
}

include 'conexion.php';
include 'funciones.php';

$tipo = $_GET['tipo'] ?? 'todas';
$exportar = $_GET['exportar'] ?? false;
$fecha_hoy = date('Y-m-d');

// Si se solicita exportar a PDF
if ($exportar == 'pdf') {
    exportarReportePDF($tipo);
    exit;
}

// Función para debug
function debugQuery($sql, $params = []) {
    error_log("SQL: " . $sql);
    error_log("Params: " . json_encode($params));
}

// Función para formatear tabla de ventas
function generarTablaVentas($ventas) {
    if (empty($ventas)) {
        return '<div style="text-align: center; color: #ff9900; padding: 40px; border: 2px dashed #333; border-radius: 10px;">
                <h3>⚠️ NO HAY VENTAS PARA MOSTRAR</h3>
                <p>No se encontraron ventas registradas para la fecha ' . date('d/m/Y') . '</p>
                </div>';
    }

    $html = '<div style="overflow-x: auto;">';
    $html .= '<table class="tabla-reporte" style="width: 100%; border-collapse: collapse; font-size: 12px;">';
    $html .= '<thead><tr style="background: linear-gradient(135deg, #ff0000, #cc0000);">
                <th style="padding: 12px; color: white; border: 1px solid #333;"># Venta</th>
                <th style="padding: 12px; color: white; border: 1px solid #333;">Cliente</th>
                <th style="padding: 12px; color: white; border: 1px solid #333;">Fecha/Hora</th>
                <th style="padding: 12px; color: white; border: 1px solid #333;">Productos</th>
                <th style="padding: 12px; color: white; border: 1px solid #333;">Subtotal</th>
                <th style="padding: 12px; color: white; border: 1px solid #333;">Descuento</th>
                <th style="padding: 12px; color: white; border: 1px solid #333;">Impuestos</th>
                <th style="padding: 12px; color: white; border: 1px solid #333;">Total</th>
                <th style="padding: 12px; color: white; border: 1px solid #333;">Método Pago</th>
              </tr></thead><tbody>';

    $total_general = 0;
    
    foreach ($ventas as $venta) {
        $total_general += $venta['total'];
        
        // Obtener detalles de productos para esta venta
        $productos_html = '';
        $sql_detalles = "SELECT vd.cantidad, p.nombre, vd.precio_unitario 
                        FROM venta_detalles vd 
                        JOIN productos p ON vd.id_producto = p.id 
                        WHERE vd.id_venta = ?";
        global $conn;
        $stmt_detalles = $conn->prepare($sql_detalles);
        $stmt_detalles->bind_param("i", $venta['id']);
        $stmt_detalles->execute();
        $detalles = $stmt_detalles->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($detalles as $detalle) {
            $productos_html .= '• ' . $detalle['cantidad'] . 'x ' . $detalle['nombre'] . ' (Q' . number_format($detalle['precio_unitario'], 2) . ')<br>';
        }

        // Color según método de pago
        $color_metodo = '';
        switch ($venta['metodo_pago']) {
            case 'efectivo': $color_metodo = 'color: #28a745; font-weight: bold;'; break;
            case 'tarjeta': $color_metodo = 'color: #007bff; font-weight: bold;'; break;
            case 'credito': $color_metodo = 'color: #ffc107; font-weight: bold;'; break;
            default: $color_metodo = 'color: #6c757d;';
        }

        $html .= '<tr style="border-bottom: 1px solid #333;">
                    <td style="padding: 10px; border: 1px solid #333;">#' . $venta['id'] . '</td>
                    <td style="padding: 10px; border: 1px solid #333;">' . htmlspecialchars($venta['cliente_nombre'] ?? 'Cliente General') . '</td>
                    <td style="padding: 10px; border: 1px solid #333;">' . date('H:i', strtotime($venta['created_at'])) . '</td>
                    <td style="padding: 10px; border: 1px solid #333; font-size: 11px;">' . $productos_html . '</td>
                    <td style="padding: 10px; border: 1px solid #333; text-align: right;">' . formato_moneda($venta['subtotal'] ?? $venta['total']) . '</td>
                    <td style="padding: 10px; border: 1px solid #333; text-align: right;">' . formato_moneda($venta['descuento'] ?? 0) . '</td>
                    <td style="padding: 10px; border: 1px solid #333; text-align: right;">' . formato_moneda($venta['impuestos'] ?? 0) . '</td>
                    <td style="padding: 10px; border: 1px solid #333; text-align: right; font-weight: bold;">' . formato_moneda($venta['total']) . '</td>
                    <td style="padding: 10px; border: 1px solid #333; text-align: center; ' . $color_metodo . '">' . ucfirst($venta['metodo_pago']) . '</td>
                  </tr>';
    }

    $html .= '</tbody>';
    $html .= '<tfoot><tr style="background: linear-gradient(135deg, #2a2a2a, #1a1a1a);">
                <td colspan="7" style="padding: 12px; text-align: right; color: white; border: 1px solid #333;"><strong>Total General:</strong></td>
                <td colspan="2" style="padding: 12px; text-align: right; color: #ff9900; font-weight: bold; border: 1px solid #333;">' . formato_moneda($total_general) . '</td>
              </tr></tfoot>';
    $html .= '</table></div>';

    return $html;
}

// Función para verificar si hay ventas hoy
function verificarVentasHoy($conn, $user_id) {
    $sql = "SELECT COUNT(*) as total FROM ventas WHERE DATE(created_at) = CURDATE() AND id_empleado = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['total'] > 0;
}

// Primero verificamos si hay ventas hoy
if (!verificarVentasHoy($conn, $_SESSION['user_id'])) {
    echo '<div style="text-align: center; color: #ff9900; padding: 40px; border: 2px dashed #333; border-radius: 10px;">
            <h3>⚠️ NO HAY VENTAS REGISTRADAS</h3>
            <p>No se encontraron ventas para hoy (' . date('d/m/Y') . ')</p>
            <p><small>Las ventas que realices hoy aparecerán aquí automáticamente</small></p>
          </div>';
    exit;
}

// Generar reporte según el tipo
switch ($tipo) {
    case 'todas':
        $sql = "SELECT v.*, c.nombre as cliente_nombre
                FROM ventas v 
                LEFT JOIN clientes c ON v.id_cliente = c.id
                WHERE DATE(v.created_at) = CURDATE() AND v.id_empleado = ?
                ORDER BY v.created_at DESC";
        
        debugQuery($sql, [$fecha_hoy, $_SESSION['user_id']]);
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $ventas = $result->fetch_all(MYSQLI_ASSOC);
        
        error_log("Ventas encontradas: " . count($ventas));
        
        echo '<div class="resumen-rapido" style="background: linear-gradient(135deg, #2a2a2a, #1a1a1a); padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                <h3 style="color: #ff9900; margin: 0;">📊 Resumen General</h3>
                <p style="margin: 5px 0; color: #ccc;">Total de Ventas: <strong>' . count($ventas) . '</strong></p>
                <p style="margin: 5px 0; color: #ccc;">Fecha: <strong>' . date('d/m/Y') . '</strong></p>
              </div>';
        
        echo generarTablaVentas($ventas);
        break;

    case 'efectivo':
    case 'tarjeta':
    case 'transferencia':
    case 'billetera_movil':
    case 'credito':
    case 'parcial':
        $sql = "SELECT v.*, c.nombre as cliente_nombre
                FROM ventas v 
                LEFT JOIN clientes c ON v.id_cliente = c.id
                WHERE DATE(v.created_at) = CURDATE() AND v.metodo_pago = ? AND v.id_empleado = ?
                ORDER BY v.created_at DESC";
        
        debugQuery($sql, [$tipo, $_SESSION['user_id']]);
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $tipo, $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $ventas = $result->fetch_all(MYSQLI_ASSOC);
        
        error_log("Ventas encontradas para $tipo: " . count($ventas));
        
        $nombres_metodos = [
            'efectivo' => 'Efectivo',
            'tarjeta' => 'Tarjeta',
            'transferencia' => 'Transferencia',
            'billetera_movil' => 'Billetera Móvil',
            'credito' => 'Crédito',
            'parcial' => 'Pago Parcial'
        ];
        
        echo '<div class="resumen-rapido" style="background: linear-gradient(135deg, #2a2a2a, #1a1a1a); padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                <h3 style="color: #ff9900; margin: 0;">💳 Ventas en ' . $nombres_metodos[$tipo] . '</h3>
                <p style="margin: 5px 0; color: #ccc;">Total de Ventas: <strong>' . count($ventas) . '</strong></p>
                <p style="margin: 5px 0; color: #ccc;">Fecha: <strong>' . date('d/m/Y') . '</strong></p>
              </div>';
        
        echo generarTablaVentas($ventas);
        break;

    case 'compras':
        $sql = "SELECT c.*, p.nombre as proveedor_nombre 
                FROM compras c 
                LEFT JOIN proveedores p ON c.id_proveedor = p.id
                WHERE DATE(c.fecha_compra) = CURDATE()
                ORDER BY c.fecha_compra DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $compras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        if (empty($compras)) {
            echo '<div style="text-align: center; color: #ff9900; padding: 40px; border: 2px dashed #333; border-radius: 10px;">
                    <h3>⚠️ NO HAY COMPRAS REGISTRADAS</h3>
                    <p>No se encontraron compras para hoy (' . date('d/m/Y') . ')</p>
                  </div>';
        } else {
            $html = '<div class="resumen-rapido" style="background: linear-gradient(135deg, #2a2a2a, #1a1a1a); padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                        <h3 style="color: #28a745; margin: 0;">🛒 Compras del Día</h3>
                        <p style="margin: 5px 0; color: #ccc;">Total de Compras: <strong>' . count($compras) . '</strong></p>
                        <p style="margin: 5px 0; color: #ccc;">Fecha: <strong>' . date('d/m/Y') . '</strong></p>
                     </div>';
            
            $html .= '<div style="overflow-x: auto;"><table class="tabla-reporte" style="width: 100%; border-collapse: collapse;">';
            $html .= '<thead><tr style="background: linear-gradient(135deg, #28a745, #20c997);">
                        <th style="padding: 12px; color: white; border: 1px solid #333;"># Compra</th>
                        <th style="padding: 12px; color: white; border: 1px solid #333;">Proveedor</th>
                        <th style="padding: 12px; color: white; border: 1px solid #333;">Fecha</th>
                        <th style="padding: 12px; color: white; border: 1px solid #333;">Descripción</th>
                        <th style="padding: 12px; color: white; border: 1px solid #333;">Total</th>
                      </tr></thead><tbody>';

            $total_general = 0;
            
            foreach ($compras as $compra) {
                $total_general += $compra['total'];
                $html .= '<tr style="border-bottom: 1px solid #333;">
                            <td style="padding: 10px; border: 1px solid #333;">#' . $compra['id'] . '</td>
                            <td style="padding: 10px; border: 1px solid #333;">' . htmlspecialchars($compra['proveedor_nombre'] ?? 'N/A') . '</td>
                            <td style="padding: 10px; border: 1px solid #333;">' . date('d/m/Y H:i', strtotime($compra['fecha_compra'])) . '</td>
                            <td style="padding: 10px; border: 1px solid #333;">' . htmlspecialchars($compra['descripcion'] ?? 'Compra general') . '</td>
                            <td style="padding: 10px; border: 1px solid #333; text-align: right; font-weight: bold; color: #28a745;">' . formato_moneda($compra['total']) . '</td>
                          </tr>';
            }

            $html .= '</tbody>';
            $html .= '<tfoot><tr style="background: linear-gradient(135deg, #2a2a2a, #1a1a1a);">
                        <td colspan="4" style="padding: 12px; text-align: right; color: white; border: 1px solid #333;"><strong>Total General:</strong></td>
                        <td style="padding: 12px; text-align: right; color: #28a745; font-weight: bold; border: 1px solid #333;">' . formato_moneda($total_general) . '</td>
                      </tr></tfoot>';
            $html .= '</table></div>';

            echo $html;
        }
        break;

    case 'caja_chica':
        $sql = "SELECT cc.*, e.nombre as empleado_nombre 
                FROM caja_chica cc 
                LEFT JOIN empleados e ON cc.id_empleado = e.id
                ORDER BY cc.created_at DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $movimientos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        if (empty($movimientos)) {
            echo '<div style="text-align: center; color: #ff9900; padding: 40px; border: 2px dashed #333; border-radius: 10px;">
                    <h3>⚠️ NO HAY MOVIMIENTOS</h3>
                    <p>No se encontraron movimientos en la caja chica</p>
                  </div>';
        } else {
            $html = '<div class="resumen-rapido" style="background: linear-gradient(135deg, #2a2a2a, #1a1a1a); padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                        <h3 style="color: #ffc107; margin: 0;">💰 Movimientos de Caja Chica</h3>
                        <p style="margin: 5px 0; color: #ccc;">Total de Movimientos: <strong>' . count($movimientos) . '</strong></p>
                     </div>';
            
            $html .= '<div style="overflow-x: auto;"><table class="tabla-reporte" style="width: 100%; border-collapse: collapse;">';
            $html .= '<thead><tr style="background: linear-gradient(135deg, #ffc107, #fd7e14);">
                        <th style="padding: 12px; color: white; border: 1px solid #333;">Fecha/Hora</th>
                        <th style="padding: 12px; color: white; border: 1px solid #333;">Tipo</th>
                        <th style="padding: 12px; color: white; border: 1px solid #333;">Descripción</th>
                        <th style="padding: 12px; color: white; border: 1px solid #333;">Monto</th>
                        <th style="padding: 12px; color: white; border: 1px solid #333;">Empleado</th>
                      </tr></thead><tbody>';

            $total_ingresos = 0;
            $total_egresos = 0;
            
            foreach ($movimientos as $movimiento) {
                if ($movimiento['tipo'] == 'ingreso') {
                    $total_ingresos += $movimiento['monto'];
                    $color_tipo = '#28a745';
                    $simbolo_tipo = '⬆️';
                } else {
                    $total_egresos += $movimiento['monto'];
                    $color_tipo = '#dc3545';
                    $simbolo_tipo = '⬇️';
                }

                $html .= '<tr style="border-bottom: 1px solid #333;">
                            <td style="padding: 10px; border: 1px solid #333;">' . date('d/m/Y H:i', strtotime($movimiento['created_at'])) . '</td>
                            <td style="padding: 10px; border: 1px solid #333; color: ' . $color_tipo . '; font-weight: bold;">' . $simbolo_tipo . ' ' . ucfirst($movimiento['tipo']) . '</td>
                            <td style="padding: 10px; border: 1px solid #333;">' . htmlspecialchars($movimiento['descripcion']) . '</td>
                            <td style="padding: 10px; border: 1px solid #333; text-align: right; font-weight: bold; color: ' . $color_tipo . ';">' . formato_moneda($movimiento['monto']) . '</td>
                            <td style="padding: 10px; border: 1px solid #333;">' . htmlspecialchars($movimiento['empleado_nombre'] ?? 'N/A') . '</td>
                          </tr>';
            }

            $saldo = $total_ingresos - $total_egresos;

            $html .= '</tbody>';
            $html .= '<tfoot>
                        <tr style="background: linear-gradient(135deg, #2a2a2a, #1a1a1a);">
                            <td colspan="3" style="padding: 12px; text-align: right; color: white; border: 1px solid #333;"><strong>Total Ingresos:</strong></td>
                            <td colspan="2" style="padding: 12px; text-align: right; color: #28a745; font-weight: bold; border: 1px solid #333;">' . formato_moneda($total_ingresos) . '</td>
                        </tr>
                        <tr style="background: linear-gradient(135deg, #2a2a2a, #1a1a1a);">
                            <td colspan="3" style="padding: 12px; text-align: right; color: white; border: 1px solid #333;"><strong>Total Egresos:</strong></td>
                            <td colspan="2" style="padding: 12px; text-align: right; color: #dc3545; font-weight: bold; border: 1px solid #333;">' . formato_moneda($total_egresos) . '</td>
                        </tr>
                        <tr style="background: linear-gradient(135deg, #333, #222);">
                            <td colspan="3" style="padding: 12px; text-align: right; color: white; border: 1px solid #333;"><strong>Saldo Final:</strong></td>
                            <td colspan="2" style="padding: 12px; text-align: right; color: #ff9900; font-weight: bold; border: 1px solid #333;">' . formato_moneda($saldo) . '</td>
                        </tr>
                      </tfoot>';
            $html .= '</table></div>';

            echo $html;
        }
        break;

    default:
        echo '<div style="text-align: center; color: #dc3545; padding: 40px; border: 2px dashed #333; border-radius: 10px;">
                <h3>❌ TIPO DE REPORTE NO VÁLIDO</h3>
                <p>El tipo de reporte solicitado no existe</p>
              </div>';
}

// Función para debug final
error_log("=== FIN DEL REPORTE ===");
?>