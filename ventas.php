    <?php
    session_start();
    // MEJOR MANEJO DE ERRORES
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/php_errors.log');

    // Función para loguear errores detallados
    function log_error($message, $data = null)
    {
        $log_message = "[" . date('Y-m-d H:i:s') . "] " . $message;
        if ($data !== null) {
            $log_message .= " - Data: " . print_r($data, true);
        }
        $log_message .= " - Backtrace: " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5));
        error_log($log_message);
    }

    include 'config_hora.php';
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit();
    }
    $usar_precio_after = false;
    $hora_actual = date('H');
    $minuto_actual = date('i');
    if (($hora_actual == 0 && $minuto_actual >= 30) ||
        ($hora_actual >= 1 && $hora_actual <= 5) ||
        ($hora_actual == 6 && $minuto_actual == 0)
    ) {
        $usar_precio_after = true;
    }
    // Incluir archivos necesarios PRIMERO - ESTO ES CRÍTICO
    include 'conexion.php';
    include 'funciones.php';
    // Obtener el día actual en español
    $dias_semana = [
        'Sunday' => 'domingo',
        'Monday' => 'lunes',
        'Tuesday' => 'martes',
        'Wednesday' => 'miercoles',
        'Thursday' => 'jueves',
        'Friday' => 'viernes',
        'Saturday' => 'sabado'
    ];
    $dia_actual = $dias_semana[date('l')];

    // Obtener combos activos para hoy
    $sql_combos = "SELECT c.*, 
               GROUP_CONCAT(CONCAT(cp.cantidad, 'x', p.nombre)) as productos_info
               FROM combos c
               JOIN combo_dias cd ON c.id = cd.id_combo
               JOIN combo_productos cp ON c.id = cp.id_combo
               JOIN productos p ON cp.id_producto = p.id
               WHERE c.activo = 1 
               AND cd.dia_semana = ?
               AND cd.activo = 1
               GROUP BY c.id
               ORDER BY c.nombre";
    $stmt_combos = $conn->prepare($sql_combos);
    $stmt_combos->bind_param("s", $dia_actual);
    $stmt_combos->execute();
    $result_combos = $stmt_combos->get_result();

    // Procesar venta de combo
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['vender_combo'])) {
        $combo_id = intval($_POST['combo_id']);
        $cantidad = intval($_POST['cantidad']);

        // Verificar que el combo esté activo hoy
        $sql_verificar = "SELECT c.* FROM combos c 
                     JOIN combo_dias cd ON c.id = cd.id_combo 
                     WHERE c.id = ? AND cd.dia_semana = ? AND c.activo = 1";
        $stmt_verificar = $conn->prepare($sql_verificar);
        $stmt_verificar->bind_param("is", $combo_id, $dia_actual);
        $stmt_verificar->execute();
        $combo = $stmt_verificar->get_result()->fetch_assoc();

        if (!$combo) {
            $mensaje_error = "El combo no está disponible hoy";
        } else {
            // Verificar stock de todos los productos del combo
            $sql_productos_combo = "SELECT cp.id_producto, cp.cantidad as cantidad_combo, p.nombre, p.stock 
                               FROM combo_productos cp 
                               JOIN productos p ON cp.id_producto = p.id 
                               WHERE cp.id_combo = ?";
            $stmt_productos = $conn->prepare($sql_productos_combo);
            $stmt_productos->bind_param("i", $combo_id);
            $stmt_productos->execute();
            $productos_combo = $stmt_productos->get_result();

            $stock_suficiente = true;
            $productos_sin_stock = [];

            while ($producto = $productos_combo->fetch_assoc()) {
                $cantidad_necesaria = $producto['cantidad_combo'] * $cantidad;
                if ($producto['stock'] < $cantidad_necesaria) {
                    $stock_suficiente = false;
                    $productos_sin_stock[] = $producto['nombre'] . " (necesario: $cantidad_necesaria, disponible: {$producto['stock']})";
                }
            }

            if (!$stock_suficiente) {
                $mensaje_error = "Stock insuficiente para: " . implode(", ", $productos_sin_stock);
            } else {
                $conn->begin_transaction();

                try {
                    // Descontar stock de cada producto del combo
                    $sql_descontar = "UPDATE productos p 
                                 JOIN combo_productos cp ON p.id = cp.id_producto 
                                 SET p.stock = p.stock - (cp.cantidad * ?) 
                                 WHERE cp.id_combo = ?";
                    $stmt_descontar = $conn->prepare($sql_descontar);
                    $stmt_descontar->bind_param("ii", $cantidad, $combo_id);
                    $stmt_descontar->execute();

                    // Registrar la venta del combo
                    $total_venta = $combo['precio_venta'] * $cantidad;
                    $sql_venta = "INSERT INTO ventas (id_usuario, total, tipo_venta, fecha_venta) 
                             VALUES (?, ?, 'combo', NOW())";
                    $stmt_venta = $conn->prepare($sql_venta);
                    $stmt_venta->bind_param("id", $_SESSION['user_id'], $total_venta);
                    $stmt_venta->execute();
                    $id_venta = $conn->insert_id;

                    // Registrar detalle de venta del combo
                    $sql_detalle = "INSERT INTO venta_detalles (id_venta, id_producto, cantidad, precio_unitario, tipo_item) 
                               VALUES (?, NULL, ?, ?, 'combo')";
                    $stmt_detalle = $conn->prepare($sql_detalle);
                    $stmt_detalle->bind_param("iid", $id_venta, $cantidad, $combo['precio_venta']);
                    $stmt_detalle->execute();

                    $conn->commit();
                    $mensaje_exito = "Combo vendido exitosamente! Total: $" . number_format($total_venta, 2);
                } catch (Exception $e) {
                    $conn->rollback();
                    $mensaje_error = "Error al procesar la venta: " . $e->getMessage();
                }
            }
        }
    }
    // Procesar agregar combo al carrito - AGREGAR ESTO EN ventas.php
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['agregar_combo_carrito'])) {
        $combo_id = intval($_POST['combo_id']);
        $cantidad = intval($_POST['cantidad']);
        $cuenta_id = isset($_POST['cuenta_id']) ? intval($_POST['cuenta_id']) : null;

        // Obtener información del combo
        $sql_combo = "SELECT c.* FROM combos c 
                 JOIN combo_dias cd ON c.id = cd.id_combo 
                 WHERE c.id = ? AND cd.dia_semana = ? AND c.activo = 1";
        $stmt_combo = $conn->prepare($sql_combo);
        $stmt_combo->bind_param("is", $combo_id, $dia_actual);
        $stmt_combo->execute();
        $combo = $stmt_combo->get_result()->fetch_assoc();

        if (!$combo) {
            echo json_encode(['success' => false, 'message' => 'El combo no está disponible hoy']);
            exit();
        }

        // Verificar stock de productos del combo
        $sql_productos_combo = "SELECT cp.id_producto, cp.cantidad as cantidad_combo, p.nombre, p.stock 
                           FROM combo_productos cp 
                           JOIN productos p ON cp.id_producto = p.id 
                           WHERE cp.id_combo = ?";
        $stmt_productos = $conn->prepare($sql_productos_combo);
        $stmt_productos->bind_param("i", $combo_id);
        $stmt_productos->execute();
        $productos_combo = $stmt_productos->get_result();

        $stock_suficiente = true;
        $productos_sin_stock = [];

        while ($producto = $productos_combo->fetch_assoc()) {
            $cantidad_necesaria = $producto['cantidad_combo'] * $cantidad;
            if ($producto['stock'] < $cantidad_necesaria) {
                $stock_suficiente = false;
                $productos_sin_stock[] = $producto['nombre'] . " (necesario: $cantidad_necesaria, disponible: {$producto['stock']})";
            }
        }

        if (!$stock_suficiente) {
            echo json_encode(['success' => false, 'message' => "Stock insuficiente: " . implode(", ", $productos_sin_stock)]);
            exit();
        }

        // Calcular precio según hora
        $precio_final = $usar_precio_after ?
            (($combo['precio_after'] && $combo['precio_after'] > 0) ? $combo['precio_after'] : $combo['precio_venta']) :
            $combo['precio_venta'];

        $subtotal = $precio_final * $cantidad;

        if ($cuenta_id) {
            // Agregar a cuenta en base de datos
            $sql_agregar = "INSERT INTO cuenta_detalles (id_cuenta, id_producto, cantidad, precio_unitario, subtotal, tipo_item, combo_id) 
                       VALUES (?, NULL, ?, ?, ?, 'combo', ?)";
            $stmt_agregar = $conn->prepare($sql_agregar);
            $stmt_agregar->bind_param("iiddi", $cuenta_id, $cantidad, $precio_final, $subtotal, $combo_id);

            if ($stmt_agregar->execute()) {
                // Actualizar total de la cuenta
                $sql_actualizar_total = "UPDATE cuentas_pendientes SET total = total + ? WHERE id = ?";
                $stmt_actualizar = $conn->prepare($sql_actualizar_total);
                $stmt_actualizar->bind_param("di", $subtotal, $cuenta_id);
                $stmt_actualizar->execute();

                echo json_encode(['success' => true, 'message' => 'Combo agregado a la cuenta correctamente']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al agregar combo a la cuenta']);
            }
        } else {
            // Agregar al carrito de sesión
            $item_combo = [
                'tipo' => 'combo',
                'combo_id' => $combo_id,
                'nombre' => $combo['nombre'] . ' (Combo)',
                'precio' => $precio_final,
                'cantidad' => $cantidad,
                'subtotal' => $subtotal
            ];

            $_SESSION['carrito'][] = $item_combo;
            echo json_encode(['success' => true, 'message' => 'Combo agregado al carrito correctamente']);
        }
        exit();
    }

    // ========== FUNCIONES PARA GESTIÓN DE CRÉDITO ==========

    // Función para actualizar el crédito del cliente
    function actualizarCreditoCliente($cliente_id, $monto, $venta_id, $conn)
    {
        // Verificar si el cliente tiene crédito
        $sql_verificar = "SELECT credito_disponible, credito_limite FROM creditos_clientes WHERE id_cliente = ?";
        $stmt_verificar = $conn->prepare($sql_verificar);
        $stmt_verificar->bind_param("i", $cliente_id);
        $stmt_verificar->execute();
        $result_verificar = $stmt_verificar->get_result();

        if ($result_verificar->num_rows > 0) {
            $credito_actual = $result_verificar->fetch_assoc();
            $nuevo_credito = $credito_actual['credito_disponible'] - $monto;

            // Actualizar crédito disponible
            $sql_actualizar = "UPDATE creditos_clientes SET credito_disponible = ? WHERE id_cliente = ?";
            $stmt_actualizar = $conn->prepare($sql_actualizar);
            $stmt_actualizar->bind_param("di", $nuevo_credito, $cliente_id);

            if ($stmt_actualizar->execute()) {
                // Registrar movimiento de crédito
                registrarMovimientoCredito(
                    $cliente_id,
                    $venta_id,
                    'venta',
                    $monto,
                    $credito_actual['credito_disponible'],
                    $nuevo_credito,
                    "Venta #$venta_id",
                    $conn
                );

                error_log("✅ Crédito actualizado para cliente $cliente_id: -$monto (Nuevo saldo: $nuevo_credito)");
                return true;
            } else {
                error_log("❌ Error actualizando crédito: " . $stmt_actualizar->error);
                return false;
            }
        } else {
            error_log("❌ Cliente $cliente_id no tiene crédito registrado");
            return false;
        }
    }

    // Función para registrar movimientos de crédito
    function registrarMovimientoCredito($cliente_id, $venta_id, $tipo, $monto, $credito_anterior, $credito_actual, $descripcion, $conn)
    {
        $sql = "INSERT INTO movimientos_credito (id_cliente, id_venta, tipo, monto, credito_anterior, credito_actual, descripcion) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iisddds", $cliente_id, $venta_id, $tipo, $monto, $credito_anterior, $credito_actual, $descripcion);

        if ($stmt->execute()) {
            error_log("✅ Movimiento de crédito registrado para cliente $cliente_id");
            return true;
        } else {
            error_log("❌ Error registrando movimiento crédito: " . $stmt->error);
            return false;
        }
    }

    // ========== FIN FUNCIONES CRÉDITO ==========

    // Inicializar carrito si no existe
    if (!isset($_SESSION['carrito'])) {
        $_SESSION['carrito'] = array();
    }
    // ... el resto de tu código continúa igual ...
    // Inicializar carrito si no existe
    if (!isset($_SESSION['carrito'])) {
        $_SESSION['carrito'] = array();
    }

    // Determinar si estamos trabajando con una cuenta - DEFINIR PRIMERO
    $cuenta_activa = isset($_GET['cuenta']) ? intval($_GET['cuenta']) : null;

    // Si se accede sin parámetro de cuenta pero hay una cuenta activa en sesión, limpiar
    if (!isset($_GET['cuenta']) && isset($_SESSION['cuenta_activa'])) {
        unset($_SESSION['cuenta_activa']);
        // También limpiar carrito si no hay cuenta activa
        $_SESSION['carrito'] = array();
    }

    // Guardar cuenta activa en sesión para referencia
    if ($cuenta_activa) {
        $_SESSION['cuenta_activa'] = $cuenta_activa;
    }

    // Inicializar variables
    $carrito_actual = array();
    $info_cuenta = null;

    // Obtener productos del carrito según el tipo (cuenta o sesión)
    if ($cuenta_activa) {
        // Obtener productos de la cuenta desde la base de datos - ACTUALIZADO PARA COMBOS
        $sql_cuenta = "SELECT cd.*, p.nombre as producto_nombre, c.nombre as combo_nombre,
                   CASE 
                       WHEN cd.tipo_item = 'combo' THEN c.nombre
                       ELSE p.nombre 
                   END as nombre_final
                   FROM cuenta_detalles cd 
                   LEFT JOIN productos p ON cd.id_producto = p.id 
                   LEFT JOIN combos c ON cd.combo_id = c.id
                   WHERE cd.id_cuenta = ?";
        $stmt_cuenta = $conn->prepare($sql_cuenta);
        $stmt_cuenta->bind_param("i", $cuenta_activa);
        $stmt_cuenta->execute();
        $result_cuenta = $stmt_cuenta->get_result();

        while ($item = $result_cuenta->fetch_assoc()) {
            if ($item['tipo_item'] == 'combo') {
                $carrito_actual[] = array(
                    'tipo' => 'combo',
                    'combo_id' => $item['combo_id'],
                    'id' => $item['combo_id'],
                    'nombre' => $item['combo_nombre'] . ' (Combo)',
                    'precio' => $item['precio_unitario'],
                    'cantidad' => $item['cantidad'],
                    'subtotal' => $item['subtotal']
                );
            } else {
                $carrito_actual[] = array(
                    'tipo' => 'producto',
                    'id' => $item['id_producto'],
                    'nombre' => $item['producto_nombre'],
                    'precio' => $item['precio_unitario'],
                    'cantidad' => $item['cantidad'],
                    'subtotal' => $item['subtotal']
                );
            }
        }
    } else {
        // Usar carrito de la sesión - ya debería incluir combos
        $carrito_actual = isset($_SESSION['carrito']) ? $_SESSION['carrito'] : array();
    }

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {

        // Finalizar venta normal - VERSIÓN CORREGIDA PARA COMBOS
        if (isset($_POST['finalizar_venta'])) {

            print __LINE__ . " - Iniciando proceso de venta\n";

            error_log("=== INICIANDO PROCESO DE VENTA ===");

            // Limpiar cualquier output anterior
            while (ob_get_level()) ob_end_clean();

            $cliente_id = isset($_POST['cliente_id']) && !empty($_POST['cliente_id']) ? intval($_POST['cliente_id']) : NULL;
            $metodo_pago = $_POST['metodo_pago'];
            $descuento = isset($_POST['descuento']) ? floatval($_POST['descuento']) : 0;
            $cuenta_id = isset($_POST['cuenta_id']) ? intval($_POST['cuenta_id']) : null;

            // OBTENER DATOS DE COMBOS SI EXISTEN
            $combos_data = array();
            if (isset($_POST['combos_data']) && !empty($_POST['combos_data'])) {
                $combos_data = json_decode($_POST['combos_data'], true);
                error_log("Combos en venta: " . print_r($combos_data, true));
            }

            error_log("Cliente ID: " . $cliente_id);
            error_log("Método pago: " . $metodo_pago);
            error_log("Descuento: " . $descuento);
            error_log("Cuenta ID: " . $cuenta_id);
            error_log("Combos data: " . count($combos_data));

            // Si es pago parcial, obtener los pagos
            $pagos_parciales = array();
            if ($metodo_pago == 'parcial' && isset($_POST['pagos_parciales'])) {

                print __LINE__ . " - Procesando pagos parciales\n";
                $pagos_parciales = json_decode($_POST['pagos_parciales'], true);
            }

            // Obtener productos del carrito o de la cuenta
            if ($cuenta_id) {
                print __LINE__ . " - Obteniendo productos de la cuenta ID: $cuenta_id\n";
                // Obtener productos de la cuenta desde la base de datos - INCLUYENDO COMBOS
                $sql_cuenta = "SELECT cd.*, 
                           p.nombre as producto_nombre,
                           c.nombre as combo_nombre,
                           CASE 
                               WHEN cd.tipo_item = 'combo' THEN c.nombre
                               ELSE p.nombre 
                           END as nombre_final,
                           cd.tipo_item,
                           cd.combo_id
                           FROM cuenta_detalles cd 
                           LEFT JOIN productos p ON cd.id_producto = p.id 
                           LEFT JOIN combos c ON cd.combo_id = c.id
                           WHERE cd.id_cuenta = ?";
                $stmt_cuenta = $conn->prepare($sql_cuenta);
                $stmt_cuenta->bind_param("i", $cuenta_id);
                $stmt_cuenta->execute();
                $result_cuenta = $stmt_cuenta->get_result();

                $carrito = array();
                while ($item = $result_cuenta->fetch_assoc()) {
                    if ($item['tipo_item'] == 'combo') {
                        $carrito[] = array(
                            'tipo' => 'combo',
                            'combo_id' => $item['combo_id'],
                            'id' => $item['combo_id'],
                            'nombre' => $item['combo_nombre'] . ' (Combo)',
                            'precio' => $item['precio_unitario'],
                            'cantidad' => $item['cantidad'],
                            'subtotal' => $item['subtotal']
                        );
                    } else {
                        $carrito[] = array(
                            'tipo' => 'producto',
                            'id' => $item['id_producto'],
                            'nombre' => $item['producto_nombre'],
                            'precio' => $item['precio_unitario'],
                            'cantidad' => $item['cantidad'],
                            'subtotal' => $item['subtotal']
                        );
                    }
                }
            } else {
                // Usar carrito de la sesión - ya incluye combos
                $carrito = $_SESSION['carrito'];
            }

            // DEBUG: Mostrar contenido del carrito
            error_log("Contenido del carrito para venta:");
            error_log(print_r($carrito, true));

            // Verificar que hay productos
            if (empty($carrito)) {
                echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'No hay productos en el carrito'
                });
            </script>";
                exit();
            }

            // ========== VERIFICAR STOCK ANTES DE PROCESAR VENTA ==========
            $stock_suficiente = true;
            $productos_sin_stock = array();

            foreach ($carrito as $item) {
                if ($item['tipo'] == 'combo') {
                    // Verificar stock para combo
                    $combo_id = $item['combo_id'];
                    $cantidad_combo = $item['cantidad'];

                    $sql_productos_combo = "SELECT cp.id_producto, cp.cantidad as cantidad_combo, p.nombre, p.stock 
                                       FROM combo_productos cp 
                                       JOIN productos p ON cp.id_producto = p.id 
                                       WHERE cp.id_combo = ?";
                    $stmt_productos = $conn->prepare($sql_productos_combo);
                    $stmt_productos->bind_param("i", $combo_id);
                    $stmt_productos->execute();
                    $productos_combo = $stmt_productos->get_result();

                    while ($producto_combo = $productos_combo->fetch_assoc()) {
                        $cantidad_necesaria = $producto_combo['cantidad_combo'] * $cantidad_combo;
                        if ($producto_combo['stock'] < $cantidad_necesaria) {
                            $stock_suficiente = false;
                            $productos_sin_stock[] = $producto_combo['nombre'] . " (necesario: $cantidad_necesaria, disponible: {$producto_combo['stock']})";
                        }
                    }
                } else {
                    // Verificar stock para producto normal
                    $producto_id = $item['id'];
                    $cantidad = $item['cantidad'];

                    $sql_stock = "SELECT nombre, stock FROM productos WHERE id = ?";
                    $stmt_stock = $conn->prepare($sql_stock);
                    $stmt_stock->bind_param("i", $producto_id);
                    $stmt_stock->execute();
                    $producto = $stmt_stock->get_result()->fetch_assoc();

                    if ($producto['stock'] < $cantidad) {
                        $stock_suficiente = false;
                        $productos_sin_stock[] = $producto['nombre'] . " (necesario: $cantidad, disponible: {$producto['stock']})";
                    }
                }
            }

            if (!$stock_suficiente) {
                echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Stock insuficiente',
                    html: 'No hay suficiente stock para:<br>" . implode("<br>", $productos_sin_stock) . "'
                });
            </script>";
                exit();
            }

            // Calcular subtotal
            $subtotal = 0;
            foreach ($carrito as $item) {
                $item_subtotal = floatval($item['subtotal']);
                if ($item_subtotal > 0) {
                    $subtotal += $item_subtotal;
                }
            }

            // Validar subtotal
            if ($subtotal <= 0) {
                echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'El subtotal debe ser mayor a cero'
                });
            </script>";
                exit();
            }

            // Validar descuento
            if ($descuento < 0) {
                $descuento = 0;
            }
            if ($descuento > $subtotal) {
                $descuento = $subtotal;
            }

            // Calcular impuestos (10% si es tarjeta y monto < 100)
            $impuestos = 0;
            $base_imponible = $subtotal - $descuento;

            if ($base_imponible > 0 && $metodo_pago == 'tarjeta' && $base_imponible < 100) {
                $impuestos = $base_imponible * 0.10;
            }

            $total = $base_imponible + $impuestos;

            // Validar total final
            if ($total <= 0) {
                echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'El total debe ser mayor a cero'
                });
            </script>";
                exit();
            }

            // Para pago parcial, verificar que la suma coincida
            if ($metodo_pago == 'parcial') {
                print __LINE__ . " - Validando suma de pagos parciales\n";
                $suma_parcial = 0;
                foreach ($pagos_parciales as $pago) {
                    $suma_parcial += floatval($pago['monto']);
                }

                if (abs($suma_parcial - $total) > 0.01) {
                    echo "<script>
                    Swal.fire({
                        icon: 'error',
                        title: 'Error en pago parcial',
                        text: 'La suma de los pagos (" . formato_moneda($suma_parcial) . ") no coincide con el total (" . formato_moneda($total) . ")'
                    });
                </script>";
                    exit();
                }
            }

            // INICIAR TRANSACCIÓN PARA GARANTIZAR CONSISTENCIA
            $conn->begin_transaction();

            try {
                print __LINE__ . " - Insertando venta en base de datos\n";
                // Insertar venta
                $sql_venta = "INSERT INTO ventas (id_cliente, id_empleado, total, descuento, impuestos, metodo_pago, estado) 
                        VALUES (?, ?, ?, ?, ?, ?, 'completada')";
                $stmt = $conn->prepare($sql_venta);

                if (!$stmt) {
                    throw new Exception('Error preparando consulta: ' . $conn->error);
                }

                // Asegurar valores numéricos válidos
                $total_venta = floatval($total);
                $descuento_venta = floatval($descuento);
                $impuestos_venta = floatval($impuestos);

                $stmt->bind_param("iiddds", $cliente_id, $_SESSION['user_id'], $total_venta, $descuento_venta, $impuestos_venta, $metodo_pago);

                if (!$stmt->execute()) {
                    throw new Exception('Error al registrar la venta: ' . $conn->error);
                }

                $venta_id = $stmt->insert_id;

                // ========== PROCESAR DETALLES DE VENTA Y ACTUALIZAR STOCK ==========
                foreach ($carrito as $item) {
                    if ($item['tipo'] == 'combo') {
                        print __LINE__ . " - Procesando combo ID: " . $item['combo_id'] . "\n";
                        // Procesar combo
                        $combo_id = $item['combo_id'];
                        $cantidad = intval($item['cantidad']);
                        $precio_unitario = floatval($item['precio']);
                        $subtotal_item = floatval($item['subtotal']);

                        // Insertar detalle de venta del combo
                        $sql_detalle = "INSERT INTO venta_detalles (id_venta, id_producto, cantidad, precio_unitario, subtotal, tipo_item) 
                                   VALUES (?, NULL, ?, ?, ?, 'combo')";
                        $stmt_detalle = $conn->prepare($sql_detalle);
                        $stmt_detalle->bind_param("iidd", $venta_id, $cantidad, $precio_unitario, $subtotal_item);

                        if (!$stmt_detalle->execute()) {
                            throw new Exception('Error insertando detalle de combo: ' . $stmt_detalle->error);
                        }

                        // Descontar stock de productos del combo
                        $sql_productos_combo = "SELECT cp.id_producto, cp.cantidad as cantidad_combo 
                                           FROM combo_productos cp 
                                           WHERE cp.id_combo = ?";
                        $stmt_productos = $conn->prepare($sql_productos_combo);
                        $stmt_productos->bind_param("i", $combo_id);
                        $stmt_productos->execute();
                        $productos_combo = $stmt_productos->get_result();

                        while ($producto_combo = $productos_combo->fetch_assoc()) {
                            $cantidad_descontar = $producto_combo['cantidad_combo'] * $cantidad;
                            $sql_update_stock = "UPDATE productos SET stock = stock - ? WHERE id = ?";
                            $stmt_stock = $conn->prepare($sql_update_stock);
                            $stmt_stock->bind_param("ii", $cantidad_descontar, $producto_combo['id_producto']);

                            if (!$stmt_stock->execute()) {
                                throw new Exception('Error actualizando stock de producto de combo: ' . $stmt_stock->error);
                            }
                        }
                    } else {
                        // Procesar producto normal
                        print __LINE__ . " - Procesando producto ID: " . $item['id'] . "\n";
                        $sql_detalle = "INSERT INTO venta_detalles (id_venta, id_producto, cantidad, precio_unitario, subtotal) 
                                   VALUES (?, ?, ?, ?, ?)";
                        $stmt_detalle = $conn->prepare($sql_detalle);

                        $id_producto = intval($item['id']);
                        $cantidad = intval($item['cantidad']);
                        $precio_unitario = floatval($item['precio']);
                        $subtotal_item = floatval($item['subtotal']);

                        $stmt_detalle->bind_param("iiidd", $venta_id, $id_producto, $cantidad, $precio_unitario, $subtotal_item);

                        if (!$stmt_detalle->execute()) {
                            throw new Exception('Error insertando detalle de producto: ' . $stmt_detalle->error);
                        }

                        // Actualizar stock
                        $sql_update_stock = "UPDATE productos SET stock = stock - ? WHERE id = ?";
                        $stmt_stock = $conn->prepare($sql_update_stock);
                        $stmt_stock->bind_param("ii", $cantidad, $id_producto);

                        if (!$stmt_stock->execute()) {
                            throw new Exception('Error actualizando stock de producto: ' . $stmt_stock->error);
                        }
                    }
                }

                // ========== PROCESAR PAGOS PARCIALES SI APLICA ==========
                if ($metodo_pago == 'parcial' && !empty($pagos_parciales)) {
                    print __LINE__ . " - Procesando pagos parciales\n";
                    foreach ($pagos_parciales as $index => $pago) {
                        $metodo_individual = $pago['metodo'];
                        $monto_individual = floatval($pago['monto']);

                        // Insertar en venta_pagos
                        $sql_pago = "INSERT INTO venta_pagos (id_venta, metodo_pago, monto) VALUES (?, ?, ?)";
                        $stmt_pago = $conn->prepare($sql_pago);
                        $stmt_pago->bind_param("isd", $venta_id, $metodo_individual, $monto_individual);

                        if (!$stmt_pago->execute()) {
                            throw new Exception('Error insertando pago parcial: ' . $stmt_pago->error);
                        }

                        // Registrar en caja chica si es efectivo
                        if ($metodo_individual == 'efectivo') {
                            $sql_caja = "INSERT INTO caja_chica (tipo, monto, descripcion, id_empleado) 
                                    VALUES ('ingreso', ?, 'Pago en efectivo - Venta #$venta_id', ?)";
                            $stmt_caja = $conn->prepare($sql_caja);
                            $stmt_caja->bind_param("di", $monto_individual, $_SESSION['user_id']);
                            $stmt_caja->execute(); // No critical if this fails
                        }

                        // Actualizar crédito si es pago con crédito
                        if ($metodo_individual == 'credito' && $cliente_id) {
                            if (!actualizarCreditoCliente($cliente_id, $monto_individual, $venta_id, $conn)) {
                                throw new Exception('Error actualizando crédito del cliente');
                            }
                        }
                    }
                }

                // ========== ACTUALIZAR CRÉDITO SI LA VENTA ES COMPLETA A CRÉDITO ==========
                if ($metodo_pago == 'credito' && $cliente_id) {
                    print __LINE__ . " - Actualizando crédito del cliente por venta completa a crédito\n";
                    if (!actualizarCreditoCliente($cliente_id, $total, $venta_id, $conn)) {
                        print __LINE__ . " - Error actualizando crédito del cliente\n";
                        throw new Exception('Error actualizando crédito del cliente');
                    }
                }

                // ========== LIMPIAR CARRITO O CUENTA ==========
                if (!$cuenta_id) {
                    $_SESSION['carrito'] = array();
                } else {
                    // Si era una cuenta, marcarla como completada
                    $sql_cerrar_cuenta = "UPDATE cuentas_pendientes SET estado = 'completada' WHERE id = ?";
                    $stmt_cerrar = $conn->prepare($sql_cerrar_cuenta);
                    $stmt_cerrar->bind_param("i", $cuenta_id);
                    $stmt_cerrar->execute();
                }

                // CONFIRMAR TRANSACCIÓN
                $conn->commit();

                // Mensaje de éxito
                if ($metodo_pago == 'parcial') {
                    $mensaje_pagos = '';
                    foreach ($pagos_parciales as $pago) {
                        $mensaje_pagos .= ucfirst($pago['metodo']) . ': ' . formato_moneda($pago['monto']) . '<br>';
                    }

                    echo "<script>
                    Swal.fire({
                        icon: 'success',
                        title: '¡Venta completada!',
                        html: 'Venta #$venta_id registrada exitosamente.<br>Subtotal: " . formato_moneda($subtotal) .
                        "<br>Descuento: -" . formato_moneda($descuento) .
                        "<br>Impuestos: +" . formato_moneda($impuestos) .
                        "<br><strong>Total: " . formato_moneda($total) . "</strong>" .
                        "<br><br>Pagos realizados:<br>" . $mensaje_pagos . "'
                    }).then(() => {
                        window.location.href = 'ventas.php';
                    });
                </script>";
                } else {
                    echo "<script>
                    Swal.fire({
                        icon: 'success',
                        title: '¡Venta completada!',
                        html: 'Venta #$venta_id registrada exitosamente.<br>Subtotal: " . formato_moneda($subtotal) .
                        "<br>Descuento: -" . formato_moneda($descuento) .
                        "<br>Impuestos: +" . formato_moneda($impuestos) .
                        "<br><strong>Total: " . formato_moneda($total) . "</strong>'
                    }).then(() => {
                        window.location.href = 'ventas.php';
                    });
                </script>";
                }
                exit();
            } catch (Exception $e) {
                // REVERTIR TRANSACCIÓN EN CASO DE ERROR
                $conn->rollback();
                // LOG DETALLADO DEL ERROR
                error_log("❌ ERROR EN TRANSACCIÓN DE VENTA:");
                error_log("❌ Mensaje: " . $e->getMessage());
                error_log("❌ Archivo: " . $e->getFile());
                error_log("❌ Línea: " . $e->getLine());
                error_log("❌ Cliente ID: " . $cliente_id);
                error_log("❌ Método Pago: " . $metodo_pago);
                error_log("❌ Total: " . $total);
                error_log("❌ Carrito: " . print_r($carrito, true));

                // También loguear el último error de MySQL si existe
                error_log("❌ Error MySQL: " . $conn->error);

                // Verificar si hay problemas de conexión
                if ($conn->connect_error) {
                    error_log("❌ Error de conexión: " . $conn->connect_error);
                }

                echo "<script>
        Swal.fire({
            icon: 'error',
            title: 'Error al procesar venta',
            text: 'Ha ocurrido un error. Por favor, intente nuevamente.'
        });
    </script>";
                exit();
            }
        }

        // ========== CREAR NUEVA CUENTA ==========
        if (isset($_POST['crear_cuenta'])) {
            print __LINE__ . " - Iniciando creación de nueva cuenta\n";
            // Limpiar cualquier output anterior
            while (ob_get_level()) ob_end_clean();

            header('Content-Type: application/json');

            $cliente_id = isset($_POST['cliente_id_cuenta']) && !empty($_POST['cliente_id_cuenta']) ? intval($_POST['cliente_id_cuenta']) : null;
            $cliente_nombre = isset($_POST['cliente_nombre_cuenta']) ? trim($_POST['cliente_nombre_cuenta']) : 'Cliente General';

            // Si se proporcionó un nuevo cliente, crearlo primero
            if (empty($cliente_id) && !empty($cliente_nombre) && $cliente_nombre != 'Cliente General') {
                $cliente_telefono = isset($_POST['cliente_telefono_cuenta']) ? trim($_POST['cliente_telefono_cuenta']) : '';

                $sql_nuevo_cliente = "INSERT INTO clientes (nombre, telefono, puntos, visitas, created_at) 
                                VALUES (?, ?, 0, 0, NOW())";
                $stmt_cliente = $conn->prepare($sql_nuevo_cliente);
                $stmt_cliente->bind_param("ss", $cliente_nombre, $cliente_telefono);

                if ($stmt_cliente->execute()) {
                    $cliente_id = $stmt_cliente->insert_id;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error al crear cliente: ' . $conn->error]);
                    exit();
                }
            }

            // Insertar cuenta en la base de datos
            $sql_cuenta = "INSERT INTO cuentas_pendientes (id_cliente, id_empleado, total, estado) 
                    VALUES (?, ?, 0, 'activa')";
            $stmt = $conn->prepare($sql_cuenta);

            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'Error preparando consulta: ' . $conn->error]);
                exit();
            }

            $stmt->bind_param("ii", $cliente_id, $_SESSION['user_id']);

            if ($stmt->execute()) {
                $cuenta_id = $stmt->insert_id;

                // LIMPIAR EL CARRITO AL CREAR NUEVA CUENTA
                $_SESSION['carrito'] = array();

                // Determinar nombre para mostrar
                $nombre_display = $cliente_nombre;
                if ($cliente_id && $cliente_nombre == 'Cliente General') {
                    $nombre_display = 'Cliente Existente';
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Cuenta #' . $cuenta_id . ' creada para ' . $nombre_display,
                    'cuenta_id' => $cuenta_id
                ]);
                exit();
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al crear la cuenta: ' . $conn->error]);
                exit();
            }
        }

        // ========== VACIAR CARRITO ==========
        if (isset($_POST['vaciar_carrito'])) {
            print __LINE__ . " - Iniciando vaciado de carrito/cuenta\n";
            $cuenta_id = isset($_POST['cuenta_id']) ? intval($_POST['cuenta_id']) : null;

            if ($cuenta_id) {
                // Vaciar cuenta en base de datos
                $sql_eliminar_detalles = "DELETE FROM cuenta_detalles WHERE id_cuenta = ?";
                $stmt_eliminar = $conn->prepare($sql_eliminar_detalles);
                $stmt_eliminar->bind_param("i", $cuenta_id);

                if ($stmt_eliminar->execute()) {
                    // Actualizar total de la cuenta a 0
                    $sql_actualizar_total = "UPDATE cuentas_pendientes SET total = 0 WHERE id = ?";
                    $stmt_actualizar = $conn->prepare($sql_actualizar_total);
                    $stmt_actualizar->bind_param("i", $cuenta_id);
                    $stmt_actualizar->execute();

                    echo "<script>
                    Swal.fire({
                        icon: 'success',
                        title: 'Cuenta vaciada',
                        text: 'Todos los productos han sido eliminados de la cuenta'
                    }).then((result) => {
                        window.location.reload();
                    });
                </script>";
                    exit();
                }
            } else {
                // Vaciar carrito de sesión
                $_SESSION['carrito'] = array();

                echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'Carrito vaciado',
                    text: 'Todos los productos han sido eliminados del carrito'
                }).then((result) => {
                    window.location.reload();
                });
            </script>";
                exit();
            }
        }

        // ========== ELIMINAR PRODUCTO INDIVIDUAL ==========
        if (isset($_POST['eliminar_producto'])) {
            $index = intval($_POST['index']);
            $cuenta_id = isset($_POST['cuenta_id']) ? intval($_POST['cuenta_id']) : null;

            if ($cuenta_id) {
                // Eliminar producto de cuenta en base de datos
                $sql_obtener_productos = "SELECT * FROM cuenta_detalles WHERE id_cuenta = ? ORDER BY id";
                $stmt = $conn->prepare($sql_obtener_productos);
                $stmt->bind_param("i", $cuenta_id);
                $stmt->execute();
                $result = $stmt->get_result();

                $productos = array();
                while ($row = $result->fetch_assoc()) {
                    $productos[] = $row;
                }

                if (isset($productos[$index])) {
                    $producto = $productos[$index];

                    // Eliminar el producto
                    $sql_eliminar = "DELETE FROM cuenta_detalles WHERE id = ?";
                    $stmt_eliminar = $conn->prepare($sql_eliminar);
                    $stmt_eliminar->bind_param("i", $producto['id']);

                    if ($stmt_eliminar->execute()) {
                        // Actualizar total de la cuenta
                        $sql_actualizar_total = "UPDATE cuentas_pendientes SET total = total - ? WHERE id = ?";
                        $stmt_actualizar = $conn->prepare($sql_actualizar_total);
                        $stmt_actualizar->bind_param("di", $producto['subtotal'], $cuenta_id);
                        $stmt_actualizar->execute();

                        echo "<script>
                        Swal.fire({
                            icon: 'success',
                            title: 'Producto eliminado',
                            text: 'Producto eliminado de la cuenta correctamente'
                        }).then((result) => {
                            window.location.reload();
                        });
                    </script>";
                        exit();
                    }
                }
            } else {
                // Eliminar producto del carrito de sesión
                if (isset($_SESSION['carrito'][$index])) {
                    unset($_SESSION['carrito'][$index]);
                    // Reindexar el array
                    $_SESSION['carrito'] = array_values($_SESSION['carrito']);

                    echo "<script>
                    Swal.fire({
                        icon: 'success',
                        title: 'Producto eliminado',
                        text: 'Producto eliminado del carrito correctamente'
                    }).then((result) => {
                        window.location.reload();
                    });
                </script>";
                    exit();
                }
            }

            // Si llegamos aquí, hubo un error
            echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'No se pudo eliminar el producto'
            }).then((result) => {
                window.location.reload();
            });
        </script>";
            exit();
        }
    } // FIN DEL BLOQUE POST


    $hora_actual = date('H');
    $minuto_actual = date('i');
    $hora_minuto_actual = $hora_actual . ':' . $minuto_actual;

    // Precio nocturno entre 12:30 am y 6:00 am
    $usar_precio_after = false;
    if (($hora_actual == 0 && $minuto_actual >= 30) || // 12:30 am - 12:59 am
        ($hora_actual >= 1 && $hora_actual <= 5) ||    // 1:00 am - 5:59 am
        ($hora_actual == 6 && $minuto_actual == 0)
    ) {  // 6:00 am exacto
        $usar_precio_after = true;
    }

    error_log("Hora actual: " . $hora_actual . ":" . $minuto_actual . ", Usar precio after: " . ($usar_precio_after ? 'SÍ' : 'NO'));
    // Consulta para obtener productos ordenados por más vendidos
    // Consulta para obtener productos Y combos activos
    $sql_productos = "SELECT 
    p.id,
    p.nombre,
    p.precio_venta,
    p.precio_after,
    p.stock,
    p.id_categoria,
    'producto' as tipo,
    COALESCE(SUM(vd.cantidad), 0) as total_vendido
FROM productos p 
LEFT JOIN venta_detalles vd ON p.id = vd.id_producto 
WHERE p.stock > 0 
GROUP BY p.id 

UNION ALL

SELECT 
    c.id,
    c.nombre,
    c.precio_venta,
    c.precio_after,
    NULL as stock,
    'combos' as id_categoria,
    'combo' as tipo,
    0 as total_vendido
FROM combos c
JOIN combo_dias cd ON c.id = cd.id_combo
WHERE c.activo = 1 
AND cd.dia_semana = ?
AND cd.activo = 1

ORDER BY total_vendido DESC, nombre ASC";

    $stmt_productos = $conn->prepare($sql_productos);
    $stmt_productos->bind_param("s", $dia_actual);
    $stmt_productos->execute();
    $result_productos = $stmt_productos->get_result();
    // Calcular totales
    $subtotal = 0;
    foreach ($carrito_actual as $item) {
        $subtotal += $item['subtotal'];
    }
    $total = $subtotal;
    // DEBUG: Mostrar contenido del carrito
    error_log("Contenido del carrito: " . print_r($carrito_actual, true));
    ?>

    <!DOCTYPE html>
    <html lang="es">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Ruta 666 - Punto de Venta</title>
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
                height: 100vh;
                overflow: hidden;
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

            .menu-item:hover,
            .menu-item.active {
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
                height: 100vh;
                overflow-y: auto;
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

            /* Layout principal */
            .pos-container {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 20px;
                height: calc(100vh - 100px);
            }

            /* Panel de productos */
            .productos-panel {
                background-color: var(--dark-bg);
                border-radius: 10px;
                padding: 20px;
                overflow-y: auto;
            }

            .search-box {
                margin-bottom: 20px;
            }

            .search-box input {
                width: 100%;
                padding: 10px;
                background-color: #333;
                border: 1px solid #444;
                border-radius: 5px;
                color: white;
            }

            .categorias {
                display: flex;
                gap: 10px;
                margin-bottom: 20px;
                flex-wrap: wrap;
            }

            .categoria-btn {
                padding: 8px 15px;
                background-color: #333;
                border: 1px solid #444;
                border-radius: 20px;
                color: var(--text-secondary);
                cursor: pointer;
            }

            .categoria-btn.active {
                background-color: var(--accent);
                color: white;
            }

            .productos-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                gap: 15px;
            }

            .producto-card {
                background-color: #333;
                border-radius: 8px;
                padding: 15px;
                text-align: center;
                cursor: pointer;
                transition: transform 0.2s;
            }

            .producto-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 8px rgba(255, 0, 0, 0.2);
            }

            .producto-precio {
                color: var(--accent);
                font-weight: bold;
                margin: 5px 0;
            }

            .producto-stock {
                font-size: 12px;
                color: var(--text-secondary);
            }

            /* Panel del carrito */
            .carrito-panel {
                background-color: var(--dark-bg);
                border-radius: 10px;
                padding: 20px;
                display: flex;
                flex-direction: column;
            }

            .carrito-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 1px solid #333;
            }

            .carrito-items {
                flex: 1;
                overflow-y: auto;
                margin-bottom: 20px;
            }

            .carrito-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 0;
                border-bottom: 1px solid #333;
            }

            .carrito-item-info {
                flex: 1;
            }

            .carrito-item-nombre {
                font-weight: bold;
            }

            .carrito-item-precio {
                color: var(--accent);
            }

            .carrito-item-cantidad {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .cantidad-btn {
                padding: 2px 8px;
                background-color: #333;
                border: 1px solid #444;
                border-radius: 3px;
                color: white;
                cursor: pointer;
            }

            .carrito-totales {
                padding: 15px;
                background-color: #333;
                border-radius: 8px;
                margin-bottom: 20px;
            }

            .total-line {
                display: flex;
                justify-content: space-between;
                margin-bottom: 10px;
            }

            .total-final {
                font-size: 1.2em;
                font-weight: bold;
                color: var(--accent);
                border-top: 1px solid #444;
                padding-top: 10px;
            }

            .input-descuento {
                width: 80px;
                padding: 5px;
                background: #444;
                border: 1px solid #555;
                color: white;
                border-radius: 3px;
                text-align: center;
            }

            .cliente-select {
                width: 100%;
                padding: 10px;
                background-color: #333;
                border: 1px solid #444;
                border-radius: 5px;
                color: white;
                margin-bottom: 15px;
            }

            .metodo-pago {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                margin-bottom: 15px;
            }

            .metodo-pago-btn {
                padding: 10px;
                background-color: #333;
                border: 1px solid #444;
                border-radius: 5px;
                color: white;
                text-align: center;
                cursor: pointer;
            }

            .metodo-pago-btn.active {
                background-color: var(--accent);
                border-color: var(--accent);
            }

            .info-impuesto {
                background: #333;
                padding: 10px;
                border-radius: 5px;
                margin-bottom: 15px;
                border-left: 3px solid #ff9900;
            }

            .cuenta-info {
                background: linear-gradient(45deg, #ff6b6b, #ff0000);
                padding: 10px;
                border-radius: 5px;
                margin-bottom: 15px;
                text-align: center;
            }

            /* Modal para seleccionar cantidad */
            .modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.8);
                z-index: 1000;
                justify-content: center;
                align-items: center;
            }

            .modal-content {
                background-color: var(--dark-bg);
                padding: 30px;
                border-radius: 10px;
                width: 400px;
                text-align: center;
            }

            .cantidad-input {
                width: 100%;
                padding: 15px;
                font-size: 1.2em;
                text-align: center;
                background-color: #333;
                border: 1px solid #444;
                border-radius: 5px;
                color: white;
                margin: 20px 0;
            }

            /* Panel de cuentas */
            .cuentas-panel {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: var(--dark-bg);
                padding: 20px;
                border-radius: 10px;
                z-index: 1001;
                width: 400px;
                display: none;
            }

            .cuentas-list {
                max-height: 300px;
                overflow-y: auto;
                margin: 15px 0;
            }

            .cuenta-item {
                padding: 10px;
                border: 1px solid #444;
                border-radius: 5px;
                margin-bottom: 10px;
                cursor: pointer;
            }

            .cuenta-item:hover {
                background: #333;
            }

            /* Modal buscar cliente */
            .modal-buscar-cliente {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.8);
                z-index: 1000;
                justify-content: center;
                align-items: center;
            }

            .modal-buscar-cliente-content {
                background-color: var(--dark-bg);
                padding: 30px;
                border-radius: 10px;
                width: 500px;
            }

            .search-client-results {
                max-height: 300px;
                overflow-y: auto;
                margin-top: 15px;
            }

            .client-item {
                padding: 10px;
                border: 1px solid #444;
                border-radius: 5px;
                margin-bottom: 8px;
                cursor: pointer;
                transition: background-color 0.2s;
            }

            .client-item:hover {
                background: #333;
                border-color: #ff0000;
            }

            .client-item strong {
                color: #ff0000;
            }

            .client-item small {
                color: #cccccc;
            }

            /* Loading animation */
            @keyframes pulse {
                0% {
                    opacity: 0.6;
                }

                50% {
                    opacity: 1;
                }

                100% {
                    opacity: 0.6;
                }
            }

            .loading {
                animation: pulse 1.5s infinite;
                text-align: center;
                padding: 20px;
                color: #ccc;
            }

            /* Estilos para la sección de nuevo cliente */
            #nuevo-cliente-section {
                background: rgba(255, 255, 255, 0.05);
                border-radius: 8px;
                padding: 15px;
            }

            #nuevo-cliente-section h4 {
                margin-bottom: 15px;
                color: #ff9900;
                text-align: center;
            }

            #nuevoClienteNombre,
            #nuevoClienteTelefono,
            #nuevoClienteEmail {
                width: 100%;
                padding: 10px;
                background: #333;
                border: 1px solid #555;
                border-radius: 5px;
                color: white;
                margin-bottom: 10px;
            }

            #nuevoClienteNombre:focus,
            #nuevoClienteTelefono:focus,
            #nuevoClienteEmail:focus {
                border-color: #ff9900;
                outline: none;
            }

            /* Estilos para pago parcial */
            #pago-parcial-section {
                background: rgba(255, 255, 255, 0.05);
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 15px;
            }

            #pago-parcial-section h4 {
                margin-bottom: 15px;
                color: #ff9900;
                text-align: center;
            }

            .pago-parcial-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 8px;
                border-bottom: 1px solid #444;
            }

            .pago-parcial-item:last-child {
                border-bottom: none;
            }

            /* Estilos para precios nocturnos */
            .precio-nocturno {
                color: #ff9900 !important;
                font-weight: bold;
            }

            .precio-normal {
                color: var(--accent) !important;
            }

            /* Estilos para el botón de calcular cambio */
            #btnCalcularCambio {
                background: linear-gradient(45deg, #ff9800, #ff5722);
                border: none;
            }

            #btnCalcularCambio:hover {
                background: linear-gradient(45deg, #f57c00, #e64a19);
            }

            /* Indicador de hora */
            #hora-guatemala {
                font-family: 'Courier New', monospace;
                background: rgba(255, 255, 255, 0.1);
                padding: 5px 10px;
                border-radius: 5px;
                border: 1px solid #333;
            }

            /* Agregar al CSS existente */
            option:disabled {
                color: #999 !important;
                background-color: #333 !important;
            }

            .pago-parcial-item[data-metodo="credito"] {
                border-left: 3px solid #ff9900;
                background: rgba(255, 153, 0, 0.1);
            }

            .pago-parcial-item[data-metodo="credito"] span:first-child {
                color: #ff9900;
                font-weight: bold;
            }

            .combos-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 15px;
                margin-bottom: 20px;
            }

            .combo-card {
                background: var(--dark-bg);
                border-radius: 8px;
                padding: 15px;
                border: 1px solid #333;
                transition: all 0.3s;
            }

            .combo-card:hover {
                border-color: var(--accent);
                transform: translateY(-2px);
            }

            .combo-header {
                display: flex;
                justify-content: between;
                align-items: flex-start;
                margin-bottom: 10px;
            }

            .combo-nombre {
                font-size: 1.2em;
                font-weight: bold;
                color: var(--accent);
                flex: 1;
            }

            .combo-precio {
                text-align: right;
            }

            .precio-actual {
                font-size: 1.3em;
                font-weight: bold;
                color: var(--accent);
            }

            .precio-anterior {
                text-decoration: line-through;
                color: #666;
                font-size: 0.9em;
            }

            .combo-descripcion {
                color: var(--text-secondary);
                margin-bottom: 10px;
                font-size: 0.9em;
            }

            .combo-productos {
                background: #2a2a2a;
                padding: 10px;
                border-radius: 5px;
                margin-bottom: 10px;
            }

            .producto-item {
                display: flex;
                justify-content: space-between;
                font-size: 0.8em;
                margin-bottom: 3px;
            }

            .combo-actions {
                display: flex;
                gap: 10px;
                align-items: center;
            }

            .cantidad-input {
                width: 60px;
                padding: 5px;
                background: #333;
                border: 1px solid #444;
                border-radius: 4px;
                color: white;
                text-align: center;
            }

            .btn-combo {
                flex: 1;
                padding: 8px 15px;
                background: var(--accent);
                color: white;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                transition: background 0.3s;
            }

            .btn-combo:hover {
                background: #cc0000;
            }

            .dia-actual {
                background: var(--accent);
                color: white;
                padding: 5px 10px;
                border-radius: 20px;
                font-size: 0.8em;
                margin-left: 10px;
            }

            .seccion-titulo {
                display: flex;
                align-items: center;
                margin: 20px 0 15px 0;
                padding-bottom: 10px;
                border-bottom: 2px solid var(--accent);
            }
        </style>
    </head>

    <body>
        <div class="sidebar">
            <div class="logo">RUTA 666</div>

            <div class="menu-container">
                <a href="dashboard_<?php echo $_SESSION['user_role']; ?>.php" class="menu-item">Dashboard</a>
                <a href="ventas.php" class="menu-item active">Punto de Venta</a>
                <a href="inventario.php" class="menu-item">Inventario</a>
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
                <h1>Punto de Venta <?php echo $cuenta_activa ? '(Cuenta Activa)' : ''; ?></h1>
                <span id="hora-guatemala"><?php
                                            // Mostrar hora de Guatemala
                                            $fecha = new DateTime('now', new DateTimeZone('America/Guatemala'));
                                            echo $fecha->format('d/m/Y H:i:s');
                                            ?></span>
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
            <div class="pos-container">
                <!-- Panel de productos -->
                <div class="productos-panel">
                    <div class="search-box">
                        <input type="text" id="searchProducto" placeholder="Buscar producto..." onkeyup="filtrarProductos()">
                    </div>

                    <div class="categorias">
                        <button class="categoria-btn active" data-categoria="all">Todos</button>
                        <?php
                        $sql_categorias = "SELECT * FROM categorias WHERE nombre NOT IN ('enseres', 'general') ORDER BY nombre";
                        $result_categorias = $conn->query($sql_categorias);
                        while ($categoria = $result_categorias->fetch_assoc()): ?>
                            <button class="categoria-btn" data-categoria="<?php echo $categoria['id']; ?>">
                                <?php echo $categoria['nombre']; ?>
                            </button>
                        <?php endwhile; ?>
                    </div>

                    <div class="productos-grid" id="productosGrid">
                        <?php if ($result_productos && $result_productos->num_rows > 0): ?>
                            <?php while ($item = $result_productos->fetch_assoc()): ?>
                                <?php if ($item['tipo'] == 'producto'): ?>
                                    <!-- Tarjeta de producto normal -->
                                    <div class="producto-card"
                                        data-id="<?php echo $item['id']; ?>"
                                        data-categoria="<?php echo $item['id_categoria']; ?>"
                                        data-nombre="<?php echo htmlspecialchars($item['nombre']); ?>"
                                        data-precio="<?php echo $usar_precio_after ?
                                                            (($item['precio_after'] && $item['precio_after'] > 0) ? $item['precio_after'] : $item['precio_venta']) :
                                                            $item['precio_venta']; ?>"
                                        data-stock="<?php echo $item['stock']; ?>"
                                        data-tipo="producto"
                                        onclick="seleccionarProducto(this)">
                                        <div class="producto-nombre"><?php echo $item['nombre']; ?></div>
                                        <div class="producto-precio">
                                            <?php echo formato_moneda($usar_precio_after ?
                                                (($item['precio_after'] && $item['precio_after'] > 0) ? $item['precio_after'] : $item['precio_venta']) :
                                                $item['precio_venta']); ?>
                                            <?php if ($usar_precio_after && $item['precio_after'] && $item['precio_after'] > 0): ?>
                                                <br><small style="color: #ff9900; font-size: 10px;">Precio Nocturno</small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="producto-stock">Stock: <?php echo $item['stock']; ?></div>
                                    </div>
                                <?php else: ?>
                                    <!-- Tarjeta de combo -->
                                    <?php
                                    // Obtener productos del combo para mostrar
                                    $sql_detalle_combo = "SELECT cp.cantidad, p.nombre, p.stock 
                                    FROM combo_productos cp 
                                    JOIN productos p ON cp.id_producto = p.id 
                                    WHERE cp.id_combo = ?";
                                    $stmt_detalle = $conn->prepare($sql_detalle_combo);
                                    $stmt_detalle->bind_param("i", $item['id']);
                                    $stmt_detalle->execute();
                                    $productos_combo = $stmt_detalle->get_result();
                                    ?>

                                    <div class="producto-card combo-card"
                                        data-id="<?php echo $item['id']; ?>"
                                        data-categoria="combos"
                                        data-nombre="<?php echo htmlspecialchars($item['nombre']); ?>"
                                        data-precio="<?php echo $usar_precio_after ?
                                                            (($item['precio_after'] && $item['precio_after'] > 0) ? $item['precio_after'] : $item['precio_venta']) :
                                                            $item['precio_venta']; ?>"
                                        data-stock="999" // Los combos no tienen stock propio
                                        data-tipo="combo"
                                        onclick="seleccionarCombo(this, <?php echo $item['id']; ?>)">

                                        <div class="producto-nombre" style="color: #ff9900;">
                                            <?php echo $item['nombre']; ?>
                                        </div>
                                        <div class="producto-precio">
                                            <?php echo formato_moneda($usar_precio_after ?
                                                (($item['precio_after'] && $item['precio_after'] > 0) ? $item['precio_after'] : $item['precio_venta']) :
                                                $item['precio_venta']); ?>
                                        </div>
                                        <div class="producto-stock" style="font-size: 10px; color: #ccc;">
                                            <?php
                                            $productos_info = [];
                                            while ($prod = $productos_combo->fetch_assoc()) {
                                                $productos_info[] = $prod['cantidad'] . 'x ' . $prod['nombre'];
                                            }
                                            echo implode(' + ', $productos_info);
                                            ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p style="text-align: center; color: var(--text-secondary); padding: 20px;">
                                No hay productos disponibles
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Panel del carrito -->
                <div class="carrito-panel">
                    <?php if ($cuenta_activa && isset($info_cuenta)): ?>
                        <div class="cuenta-info">
                            <strong>CUENTA ACTIVA</strong><br>
                            <small>Cliente: <?php echo htmlspecialchars($info_cuenta['cliente_nombre'] ?? 'Cliente General'); ?></small><br>
                            <small>Creada: <?php echo date('H:i', strtotime($info_cuenta['created_at'])); ?></small>
                        </div>
                    <?php endif; ?>

                    <div class="carrito-header">
                        <h3><?php echo $cuenta_activa ? 'Cuenta' : 'Carrito'; ?> de Venta</h3>
                        <form method="POST" style="display:inline;" id="formVaciarCarrito">
                            <?php if ($cuenta_activa): ?>
                                <input type="hidden" name="cuenta_id" value="<?php echo $cuenta_activa; ?>">
                            <?php endif; ?>
                            <button type="submit" name="vaciar_carrito" class="btn btn-danger btn-sm"
                                <?php echo empty($carrito_actual) ? 'disabled' : ''; ?>>
                                Vaciar
                            </button>
                        </form>
                    </div>

                    <div class="carrito-items">
                        <?php if (empty($carrito_actual)): ?>
                            <p style="text-align: center; color: var(--text-secondary); padding: 20px;">
                                <?php echo $cuenta_activa ? 'La cuenta está vacía' : 'El carrito está vacío'; ?>
                            </p>
                        <?php else: ?>
                            <?php foreach ($carrito_actual as $index => $item): ?>
                                <div class="carrito-item">
                                    <div class="carrito-item-info">
                                        <div class="carrito-item-nombre"><?php echo $item['nombre']; ?></div>
                                        <div class="carrito-item-precio"><?php echo formato_moneda($item['precio']); ?> c/u</div>
                                    </div>
                                    <div class="carrito-item-cantidad">
                                        <span><?php echo $item['cantidad']; ?></span>
                                        <!-- DESPUÉS (correcto) -->
                                        <form method="POST" style="display:inline;" class="formEliminarProducto">
                                            <?php if ($cuenta_activa): ?>
                                                <input type="hidden" name="cuenta_id" value="<?php echo $cuenta_activa; ?>">
                                            <?php endif; ?>
                                            <input type="hidden" name="index" value="<?php echo $index; ?>">
                                            <input type="hidden" name="eliminar_producto" value="true">
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                ×
                                            </button>
                                        </form>
                                    </div>
                                    <div class="carrito-item-total">
                                        <?php echo formato_moneda($item['subtotal']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="carrito-totales">
                        <div class="total-line">
                            <span>Subtotal:</span>
                            <span id="subtotal"><?php echo formato_moneda($subtotal); ?></span>
                        </div>
                        <div class="total-line">
                            <span>Descuento:</span>
                            <span>
                                <input type="number" name="descuento" id="descuento" class="input-descuento"
                                    value="0" min="0" max="<?php echo $subtotal; ?>"
                                    onchange="calcularTotales()">
                            </span>
                        </div>
                        <div class="total-line" id="impuestos-line" style="display: none;">
                            <span>Impuestos (10%):</span>
                            <span id="impuestos">+ <?php echo formato_moneda(0); ?></span>
                        </div>
                        <div class="total-line total-final">
                            <span>TOTAL:</span>
                            <span id="total-final"><?php echo formato_moneda($total); ?></span>
                        </div>
                    </div>

                    <div id="info-impuesto" class="info-impuesto" style="display: none;">
                        <small style="color: #ff9900;">
                            ⓘ Se aplicará 10% de impuesto por pagos con tarjeta en compras menores a Q100.00
                        </small>
                    </div>

                    <!-- Formulario de venta CORREGIDO -->
                    <form method="POST" id="formVenta">
                        <?php if ($cuenta_activa): ?>
                            <input type="hidden" name="cuenta_id" value="<?php echo $cuenta_activa; ?>">
                        <?php endif; ?>

                        <!-- Campo oculto para pagos parciales -->
                        <!-- Campo oculto para pagos parciales -->
                        <input type="hidden" name="pagos_parciales" id="pagos_parciales" value="">
                        <!-- NUEVO: Campo oculto para información de combos -->
                        <input type="hidden" name="combos_data" id="combos_data" value="">

                        <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                            <button type="button" class="btn btn-info" style="flex: 1;" onclick="buscarCliente()">
                                Buscar Cliente
                            </button>
                            <button type="button" class="btn btn-warning" style="flex: 1;" onclick="mostrarCuentas()">
                                Llevar Cuenta
                            </button>
                            <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="nuevaVenta()">
                                Nueva Venta
                            </button>
                        </div>

                        <input type="hidden" name="cliente_id" id="cliente_id" value="">
                        <div id="cliente-info" style="background: #333; padding: 10px; border-radius: 5px; margin-bottom: 15px; display: none;">
                            <small>Cliente seleccionado: <span id="cliente-nombre"></span></small>
                        </div>

                        <div class="metodo-pago">
                            <label class="metodo-pago-btn active">
                                <input type="radio" name="metodo_pago" value="efectivo" checked onclick="togglePagoParcial()"> Efectivo
                            </label>
                            <label class="metodo-pago-btn">
                                <input type="radio" name="metodo_pago" value="tarjeta" onclick="togglePagoParcial()"> Tarjeta
                            </label>
                            <label class="metodo-pago-btn">
                                <input type="radio" name="metodo_pago" value="transferencia" onclick="togglePagoParcial()"> Transferencia
                            </label>
                            <label class="metodo-pago-btn">
                                <input type="radio" name="metodo_pago" value="billetera_movil" onclick="togglePagoParcial()"> Billetera Móvil
                            </label>
                            <!-- NUEVO BOTÓN DE CRÉDITO -->
                            <label class="metodo-pago-btn">
                                <input type="radio" name="metodo_pago" value="credito" onclick="togglePagoParcial()"> Crédito
                            </label>
                            <label class="metodo-pago-btn">
                                <input type="radio" name="metodo_pago" value="parcial" onclick="togglePagoParcial()"> Pago Parcial
                            </label>
                        </div>

                        <!-- Sección de pago parcial -->
                        <div id="pago-parcial-section" style="display: none; margin-bottom: 15px;">
                            <h4 style="margin-bottom: 10px; color: #ff9900;">Pago Parcial</h4>

                            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 10px; margin-bottom: 10px;">
                                <select id="metodo-parcial" class="cliente-select" style="margin-bottom: 0;">
                                    <option value="efectivo">Efectivo</option>
                                    <option value="tarjeta">Tarjeta</option>
                                    <option value="transferencia">Transferencia</option>
                                    <option value="billetera_movil">Billetera Móvil</option>
                                    <option value="credito">Crédito</option>
                                </select>
                                <input type="number" id="monto-parcial" class="input-descuento" placeholder="Monto" min="0" step="0.01"
                                    onchange="calcularPagoParcial()">
                            </div>

                            <button type="button" class="btn btn-sm btn-info" onclick="agregarPagoParcial()" style="width: 100%;">
                                Agregar Pago
                            </button>

                            <div id="lista-pagos-parciales" style="margin-top: 10px; max-height: 150px; overflow-y: auto;">
                                <!-- Aquí se listarán los pagos parciales -->
                            </div>

                            <div style="display: flex; justify-content: space-between; margin-top: 10px; padding-top: 10px; border-top: 1px solid #444;">
                                <span>Total pagado:</span>
                                <span id="total-parcial">Q0.00</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; color: #ff9900;">
                                <span>Restante:</span>
                                <span id="restante-parcial">Q0.00</span>
                            </div>
                        </div>

                        <button type="button" name="finalizar_venta" class="btn btn-success"
                            style="width: 100%; padding: 15px; font-size: 1.2em;"
                            onclick="finalizarVenta()" <?php echo empty($carrito_actual) ? 'disabled' : ''; ?>>
                            <?php echo $cuenta_activa ? 'COBRAR CUENTA' : 'FINALIZAR VENTA'; ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal para seleccionar cantidad -->
        <div class="modal" id="modalCantidad">
            <div class="modal-content">
                <h3 id="modalProductoNombre"></h3>
                <p id="modalProductoPrecio"></p>
                <p id="modalProductoStock"></p>

                <form id="formAgregarProducto">
                    <?php if ($cuenta_activa): ?>
                        <input type="hidden" name="cuenta_id" value="<?php echo $cuenta_activa; ?>">
                    <?php endif; ?>
                    <input type="hidden" name="producto_id" id="modalProductoId">
                    <input type="hidden" name="agregar_producto" value="true">
                    <input type="number" name="cantidad" id="modalCantidadInput" class="cantidad-input"
                        min="1" value="1" required>

                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="button" class="btn btn-danger" onclick="cerrarModal()" style="flex: 1;">
                            Cancelar
                        </button>
                        <button type="button" onclick="agregarProductoAjax()" class="btn btn-success" style="flex: 1;">
                            Agregar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal para buscar cliente -->
        <div class="modal-buscar-cliente" id="modalBuscarCliente">
            <div class="modal-buscar-cliente-content">
                <h3>Buscar Cliente</h3>
                <div style="position: relative; margin-bottom: 15px;">
                    <input type="text" id="searchCliente" placeholder="Buscar por nombre o teléfono..."
                        style="width: 100%; padding: 12px; background: #333; border: 1px solid #444; border-radius: 5px; color: white;"
                        autocomplete="off">
                    <div style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #666;">
                        🔍
                    </div>
                </div>

                <div class="search-client-results" id="clientResults" style="min-height: 100px;">
                    <p style="text-align: center; color: #ccc; padding: 20px;">Escribe al menos 2 caracteres...</p>
                </div>

                <!-- Sección para agregar nuevo cliente -->
                <div id="nuevo-cliente-section" style="display: none; margin-top: 20px; padding-top: 15px; border-top: 1px solid #444;">
                    <h4>Agregar Nuevo Cliente</h4>
                    <div style="display: grid; grid-template-columns: 1fr; gap: 10px; margin-bottom: 15px;">
                        <input type="text" id="nuevoClienteNombre" placeholder="Nombre completo *"
                            style="padding: 10px; background: #333; border: 1px solid #444; border-radius: 5px; color: white;" required>
                        <input type="text" id="nuevoClienteTelefono" placeholder="Teléfono"
                            style="padding: 10px; background: #333; border: 1px solid #444; border-radius: 5px; color: white;">
                        <input type="email" id="nuevoClienteEmail" placeholder="Email"
                            style="padding: 10px; background: #333; border: 1px solid #444; border-radius: 5px; color: white;">
                    </div>
                    <button type="button" class="btn btn-success" onclick="agregarNuevoCliente()" style="width: 100%;">
                        Agregar Cliente
                    </button>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <button type="button" class="btn btn-danger" onclick="cerrarBuscarCliente()" style="flex: 1;">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal para crear cuenta -->
        <div class="modal" id="modalCrearCuenta">
            <div class="modal-content">
                <h3>Crear Nueva Cuenta</h3>

                <div style="margin-bottom: 15px;">
                    <p style="color: #ccc; margin-bottom: 10px;">Selecciona un cliente existente o ingresa un nuevo cliente:</p>
                </div>

                <form id="formCrearCuenta">
                    <!-- Cliente existente -->
                    <div id="cliente-existente-section">
                        <div style="position: relative; margin-bottom: 15px;">
                            <input type="text" id="searchClienteCuenta" placeholder="Buscar cliente..."
                                style="width: 100%; padding: 10px; background: #333; border: 1px solid #444; border-radius: 5px; color: white;"
                                onkeyup="buscarClientesCuenta(this.value)">
                        </div>
                        <div id="resultados-clientes-cuenta" style="max-height: 150px; overflow-y: auto; margin-bottom: 15px;">
                            <p style="text-align: center; color: #ccc; padding: 10px;">Escribe para buscar clientes...</p>
                        </div>
                    </div>

                    <!-- Nuevo cliente -->
                    <div id="nuevo-cliente-cuenta-section" style="display: none;">
                        <div style="display: grid; grid-template-columns: 1fr; gap: 10px; margin-bottom: 15px;">
                            <input type="text" name="cliente_nombre_cuenta" id="cliente_nombre_cuenta"
                                placeholder="Nombre del cliente *" required
                                style="padding: 10px; background: #333; border: 1px solid #444; border-radius: 5px; color: white;">
                            <input type="text" name="cliente_telefono_cuenta" id="cliente_telefono_cuenta"
                                placeholder="Teléfono"
                                style="padding: 10px; background: #333; border: 1px solid #444; border-radius: 5px; color: white;">
                        </div>
                    </div>

                    <input type="hidden" name="cliente_id_cuenta" id="cliente_id_cuenta" value="">
                    <input type="hidden" name="crear_cuenta" value="true">

                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn btn-danger" onclick="cerrarCrearCuenta()" style="flex: 1;">
                            Cancelar
                        </button>
                        <button type="button" onclick="crearCuentaAjax()" class="btn btn-success" style="flex: 1;">
                            Crear Cuenta
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Panel de cuentas activas -->
        <div class="cuentas-panel" id="cuentasPanel">
            <h3>Cuentas Activas</h3>

            <div class="cuentas-list" id="cuentasList">
                <div class="loading" id="loadingCuentas">Cargando cuentas...</div>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 15px;">
                <button type="button" class="btn btn-danger" onclick="cerrarCuentas()" style="flex: 1;">
                    Cerrar
                </button>
                <button type="button" class="btn btn-success" onclick="crearNuevaCuenta()" style="flex: 1;">
                    Nueva Cuenta
                </button>
            </div>
        </div>

        <script>
            // ========== VARIABLES GLOBALES ==========
            const USAR_PRECIO_AFTER = <?php echo $usar_precio_after ? 'true' : 'false'; ?>;
            const HORA_ACTUAL = '<?php echo date('H:i'); ?>';

            // ========== FUNCIONES PRINCIPALES ==========

            // Función para abrir modal de cantidad
            function seleccionarProducto(element) {
                const id = element.getAttribute('data-id');
                const nombre = element.getAttribute('data-nombre');
                const precio = element.getAttribute('data-precio');
                const stock = element.getAttribute('data-stock');

                document.getElementById('modalProductoId').value = id;
                document.getElementById('modalProductoNombre').textContent = nombre;
                document.getElementById('modalProductoPrecio').textContent = 'Precio: Q' + parseFloat(precio).toFixed(2) +
                    (USAR_PRECIO_AFTER ? ' (Precio Nocturno)' : '');
                document.getElementById('modalProductoStock').textContent = 'Stock disponible: ' + stock;
                document.getElementById('modalCantidadInput').max = stock;
                document.getElementById('modalCantidadInput').value = 1;

                document.getElementById('modalCantidad').style.display = 'flex';

                setTimeout(() => {
                    document.getElementById('modalCantidadInput').focus();
                    document.getElementById('modalCantidadInput').select();
                }, 100);
            }

            // Función para cerrar modal
            function cerrarModal() {
                document.getElementById('modalCantidad').style.display = 'none';
                document.getElementById('formAgregarProducto').reset();
            }

            // Función para buscar cliente
            function buscarCliente() {
                document.getElementById('modalBuscarCliente').style.display = 'flex';
                document.getElementById('clientResults').innerHTML = '<p style="text-align: center; color: #ccc; padding: 20px;">Escribe al menos 2 caracteres...</p>';
                document.getElementById('searchCliente').value = '';
                document.getElementById('nuevo-cliente-section').style.display = 'none';
            }

            function cerrarBuscarCliente() {
                document.getElementById('modalBuscarCliente').style.display = 'none';
            }

            // Función para mostrar cuentas activas
            function mostrarCuentas() {
                document.getElementById('cuentasPanel').style.display = 'block';
                cargarCuentasActivas();
            }

            function cerrarCuentas() {
                document.getElementById('cuentasPanel').style.display = 'none';
            }

            function crearNuevaCuenta() {
                cerrarCuentas();
                setTimeout(() => {
                    document.getElementById('modalCrearCuenta').style.display = 'flex';
                    document.getElementById('formCrearCuenta').reset();
                    document.getElementById('cliente_id_cuenta').value = '';
                    document.getElementById('resultados-clientes-cuenta').innerHTML = '<p style="text-align: center; color: #ccc; padding: 10px;">Escribe para buscar clientes...</p>';
                    document.getElementById('nuevo-cliente-cuenta-section').style.display = 'none';
                }, 300);
            }

            function cerrarCrearCuenta() {
                document.getElementById('modalCrearCuenta').style.display = 'none';
            }

            function filtrarProductos() {
                const searchTerm = document.getElementById('searchProducto').value.toLowerCase();
                const categoriaActiva = document.querySelector('.categoria-btn.active').getAttribute('data-categoria');
                const productos = document.querySelectorAll('.producto-card');

                productos.forEach(producto => {
                    const nombre = producto.getAttribute('data-nombre').toLowerCase();
                    const categoria = producto.getAttribute('data-categoria');
                    const tipo = producto.getAttribute('data-tipo');
                    const coincideBusqueda = nombre.includes(searchTerm);

                    let coincideCategoria = false;
                    if (categoriaActiva === 'all') {
                        coincideCategoria = true;
                    } else if (categoriaActiva === 'combos') {
                        coincideCategoria = (tipo === 'combo');
                    } else {
                        coincideCategoria = (categoria === categoriaActiva && tipo === 'producto');
                    }

                    producto.style.display = (coincideBusqueda && coincideCategoria) ? 'block' : 'none';
                });
            }

            // Función para calcular totales en tiempo real
            function calcularTotales() {
                const subtotal = <?php echo $subtotal; ?>;
                const descuento = parseFloat(document.getElementById('descuento').value) || 0;
                const metodoPago = document.querySelector('input[name="metodo_pago"]:checked').value;

                let impuestos = 0;
                const baseImponible = subtotal - descuento;

                if (metodoPago === 'tarjeta' && baseImponible < 100 && baseImponible > 0) {
                    impuestos = baseImponible * 0.10;
                    document.getElementById('impuestos-line').style.display = 'flex';
                } else {
                    document.getElementById('impuestos-line').style.display = 'none';
                }

                const total = baseImponible + impuestos;

                document.getElementById('subtotal').textContent = formatoMoneda(subtotal);
                document.getElementById('impuestos').textContent = '+ ' + formatoMoneda(impuestos);
                document.getElementById('total-final').textContent = formatoMoneda(total);

                if (document.querySelector('input[name="metodo_pago"][value="parcial"]').checked) {
                    document.getElementById('restante-parcial').textContent = formatoMoneda(total);
                }
            }

            // Función para formatear moneda en JavaScript
            function formatoMoneda(monto) {
                return 'Q' + parseFloat(monto).toFixed(2);
            }

            // Mostrar/ocultar mensaje informativo
            function toggleInfoImpuesto() {
                const metodoPago = document.querySelector('input[name="metodo_pago"]:checked').value;
                const infoImpuesto = document.getElementById('info-impuesto');

                if (metodoPago === 'tarjeta') {
                    infoImpuesto.style.display = 'block';
                } else {
                    infoImpuesto.style.display = 'none';
                }
            }

            // Funciones para pago parcial
            function togglePagoParcial() {
                const pagoParcialSection = document.getElementById('pago-parcial-section');
                const metodoParcial = document.querySelector('input[name="metodo_pago"][value="parcial"]');
                const metodoCredito = document.querySelector('input[name="metodo_pago"][value="credito"]');

                if (metodoParcial && metodoParcial.checked) {
                    pagoParcialSection.style.display = 'block';
                    resetPagoParcial();
                    actualizarOpcionesPagoParcial();
                } else if (metodoCredito && metodoCredito.checked) {
                    pagoParcialSection.style.display = 'none';
                    setTimeout(() => {
                        verificarCreditoCliente();
                    }, 500);
                } else {
                    pagoParcialSection.style.display = 'none';
                    document.querySelector('button[name="finalizar_venta"]').disabled = false;
                }
            }

            function resetPagoParcial() {
                document.getElementById('lista-pagos-parciales').innerHTML = '';
                document.getElementById('total-parcial').textContent = 'Q0.00';
                document.getElementById('restante-parcial').textContent = 'Q0.00';
                document.getElementById('monto-parcial').value = '';

                const subtotal = <?php echo $subtotal; ?>;
                const descuento = parseFloat(document.getElementById('descuento').value) || 0;
                const baseImponible = subtotal - descuento;

                let impuestos = 0;
                const metodoPago = document.querySelector('input[name="metodo_pago"]:checked').value;
                if (metodoPago === 'tarjeta' && baseImponible < 100 && baseImponible > 0) {
                    impuestos = baseImponible * 0.10;
                }

                const total = baseImponible + impuestos;
                document.getElementById('restante-parcial').textContent = formatoMoneda(total);

                document.querySelector('button[name="finalizar_venta"]').disabled = true;
            }

            async function agregarPagoParcial() {
                const metodo = document.getElementById('metodo-parcial').value;
                let monto = parseFloat(document.getElementById('monto-parcial').value);
                const clienteId = document.getElementById('cliente_id').value;

                if (!monto || monto <= 0) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Ingrese un monto válido'
                    });
                    return;
                }

                if (metodo === 'credito' && !clienteId) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Cliente requerido',
                        text: 'Debe seleccionar un cliente para usar crédito'
                    });
                    return;
                }

                if (metodo === 'credito' && !await verificarCreditoParcial(clienteId, monto)) {
                    return;
                }

                const listaPagos = document.getElementById('lista-pagos-parciales');
                const totalActual = parseFloat(document.getElementById('total-parcial').textContent.replace('Q', '')) || 0;
                const restanteActual = parseFloat(document.getElementById('restante-parcial').textContent.replace('Q', '')) || 0;

                if (monto > restanteActual) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Atención',
                        text: 'El monto excede el saldo restante. Se ajustará al saldo pendiente.'
                    });
                    monto = restanteActual;
                }

                const nuevoTotal = totalActual + monto;
                const nuevoRestante = restanteActual - monto;

                const divPago = document.createElement('div');
                divPago.className = 'pago-parcial-item';
                divPago.innerHTML = `
            <span>${metodo.charAt(0).toUpperCase() + metodo.slice(1)}</span>
            <span>${formatoMoneda(monto)} 
                <button type="button" onclick="eliminarPagoParcial(this)" 
                        style="background: none; border: none; color: #ff6b6b; cursor: pointer; margin-left: 5px;">×</button>
            </span>
        `;
                divPago.setAttribute('data-metodo', metodo);
                divPago.setAttribute('data-monto', monto);

                listaPagos.appendChild(divPago);

                document.getElementById('total-parcial').textContent = formatoMoneda(nuevoTotal);
                document.getElementById('restante-parcial').textContent = formatoMoneda(nuevoRestante);
                document.getElementById('monto-parcial').value = '';

                actualizarPagosOcultos();

                if (nuevoRestante <= 0) {
                    document.querySelector('button[name="finalizar_venta"]').disabled = false;
                }
            }

            async function verificarCreditoParcial(clienteId, monto) {
                try {
                    const response = await fetch(`verificar_credito.php?cliente_id=${clienteId}`);
                    const data = await response.json();

                    if (data.success) {
                        const creditoDisponible = data.credito_disponible || 0;

                        if (creditoDisponible < monto) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Crédito insuficiente',
                                html: `Crédito disponible: <strong>${formatoMoneda(creditoDisponible)}</strong><br>
                            Monto del pago: <strong>${formatoMoneda(monto)}</strong><br>
                            Faltante: <strong style="color: red;">${formatoMoneda(monto - creditoDisponible)}</strong>`,
                                confirmButtonText: 'Entendido'
                            });
                            return false;
                        }
                        return true;
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'No se pudo verificar el crédito del cliente'
                        });
                        return false;
                    }
                } catch (error) {
                    console.error('Error al verificar crédito:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error de conexión',
                        text: 'No se pudo verificar el crédito del cliente'
                    });
                    return false;
                }
            }

            function eliminarPagoParcial(elemento) {
                const divPago = elemento.closest('.pago-parcial-item');
                const monto = parseFloat(divPago.getAttribute('data-monto'));

                divPago.remove();

                const totalActual = parseFloat(document.getElementById('total-parcial').textContent.replace('Q', '')) || 0;
                const restanteActual = parseFloat(document.getElementById('restante-parcial').textContent.replace('Q', '')) || 0;

                const nuevoTotal = totalActual - monto;
                const nuevoRestante = restanteActual + monto;

                document.getElementById('total-parcial').textContent = formatoMoneda(nuevoTotal);
                document.getElementById('restante-parcial').textContent = formatoMoneda(nuevoRestante);

                actualizarPagosOcultos();

                if (nuevoRestante > 0.01) {
                    document.querySelector('button[name="finalizar_venta"]').disabled = true;
                }
            }

            function actualizarPagosOcultos() {
                const pagos = [];
                const items = document.getElementById('lista-pagos-parciales').children;

                for (let i = 0; i < items.length; i++) {
                    const metodo = items[i].getAttribute('data-metodo');
                    const monto = parseFloat(items[i].getAttribute('data-monto'));

                    pagos.push({
                        metodo: metodo,
                        monto: monto
                    });
                }

                document.getElementById('pagos_parciales').value = JSON.stringify(pagos);
            }

            // Función para verificar crédito del cliente
            function verificarCreditoCliente() {
                const clienteId = document.getElementById('cliente_id').value;
                const metodoCredito = document.querySelector('input[name="metodo_pago"][value="credito"]');
                const totalVenta = parseFloat(document.getElementById('total-final').textContent.replace('Q', '')) || 0;

                if (!metodoCredito || !metodoCredito.checked || !clienteId) {
                    return;
                }

                fetch(`verificar_credito.php?cliente_id=${clienteId}`)
                    .then(response => response.json())
                    .then(data => {
                        const creditoDisponible = data.credito_disponible || 0;

                        if (!data.puede_comprar || creditoDisponible <= 0) {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Sin crédito disponible',
                                text: 'Este cliente no tiene crédito disponible',
                                timer: 3000
                            });
                            cambiarMetodoPago('efectivo');
                        } else if (creditoDisponible < totalVenta) {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Crédito insuficiente',
                                html: `Crédito disponible: <strong>${formatoMoneda(creditoDisponible)}</strong><br>Total: <strong>${formatoMoneda(totalVenta)}</strong>`,
                                confirmButtonText: 'Entendido'
                            });
                            cambiarMetodoPago('efectivo');
                        } else {
                            Swal.fire({
                                icon: 'success',
                                title: 'Crédito disponible',
                                html: `Crédito aprobado: <strong>${formatoMoneda(creditoDisponible)}</strong>`,
                                timer: 3000,
                                showConfirmButton: false
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'No se pudo verificar el crédito'
                        });
                    });
            }

            function cambiarMetodoPago(metodo) {
                document.querySelector(`input[name="metodo_pago"][value="${metodo}"]`).checked = true;
                document.querySelectorAll('.metodo-pago-btn').forEach(b => b.classList.remove('active'));
                document.querySelector(`input[name="metodo_pago"][value="${metodo}"]`).parentElement.classList.add('active');
                togglePagoParcial();
            }

            function calcularCambioEfectivo() {
                return new Promise((resolve) => {
                    const total = parseFloat(document.getElementById('total-final').textContent.replace('Q', '')) || 0;

                    Swal.fire({
                        title: 'Calcular Cambio',
                        html: `Total a pagar: <strong>${formatoMoneda(total)}</strong><br><br>
                    <input type="number" id="montoRecibido" class="swal2-input" 
                            placeholder="Monto recibido" min="${total}" step="0.01" 
                            style="font-size: 1.2em; text-align: center;" autofocus>`,
                        showCancelButton: true,
                        confirmButtonText: 'Calcular Cambio',
                        cancelButtonText: 'Cancelar',
                        preConfirm: () => {
                            const montoRecibido = parseFloat(document.getElementById('montoRecibido').value);
                            if (!montoRecibido || montoRecibido < total) {
                                Swal.showValidationMessage('El monto debe ser mayor o igual al total');
                                return false;
                            }
                            return montoRecibido;
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            const recibido = result.value;
                            const cambio = recibido - total;

                            Swal.fire({
                                icon: 'info',
                                title: 'Cambio a devolver',
                                html: `<div style="text-align: center;">
                                <p>Total: <strong>${formatoMoneda(total)}</strong></p>
                                <p>Recibido: <strong>${formatoMoneda(recibido)}</strong></p>
                                <p style="font-size: 1.5em; color: #28a745; font-weight: bold;">
                                    Cambio: ${formatoMoneda(cambio)}
                                </p>
                            </div>`,
                                confirmButtonText: 'Aceptar'
                            }).then(() => resolve());
                        } else {
                            resolve();
                        }
                    });
                });
            }

            // Función para obtener hora de Guatemala
            function obtenerHoraGuatemala() {
                const ahora = new Date();
                const offsetGuatemala = -6;
                const offsetLocal = ahora.getTimezoneOffset() / 60;
                const diferencia = offsetGuatemala + offsetLocal;

                ahora.setHours(ahora.getHours() + diferencia);

                return ahora.toLocaleString('es-GT', {
                    timeZone: 'America/Guatemala',
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
            }

            // Actualizar la hora cada segundo
            function actualizarHora() {
                const elementoHora = document.querySelector('.header span');
                if (elementoHora) {
                    elementoHora.textContent = obtenerHoraGuatemala();
                }
            }

            // ========== FUNCIÓN PRINCIPAL FINALIZAR VENTA ==========
            function finalizarVenta() {
                const carritoVacio = <?php echo empty($carrito_actual) ? 'true' : 'false'; ?>;

                if (carritoVacio) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'No hay productos en el carrito'
                    });
                    return;
                }

                const metodoPago = document.querySelector('input[name="metodo_pago"]:checked').value;
                const clienteId = document.getElementById('cliente_id').value;

                // Validaciones
                if (metodoPago === 'parcial') {
                    const restante = parseFloat(document.getElementById('restante-parcial').textContent.replace('Q', '')) || 0;
                    if (restante > 0.01) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Pago incompleto',
                            text: 'Faltan Q' + restante.toFixed(2)
                        });
                        return;
                    }
                } else if (metodoPago === 'credito' && !clienteId) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Cliente requerido',
                        text: 'Debe seleccionar un cliente para usar crédito'
                    });
                    return;
                }

                // Para efectivo, ofrecer calcular cambio
                if (metodoPago === 'efectivo') {
                    Swal.fire({
                        title: '¿Desea calcular el cambio?',
                        text: 'Puede calcular el cambio antes de finalizar la venta',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Sí, calcular cambio',
                        cancelButtonText: 'No, finalizar directamente'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            calcularCambioEfectivo().then(() => {
                                confirmarFinalizarVenta();
                            });
                        } else {
                            confirmarFinalizarVenta();
                        }
                    });
                } else {
                    confirmarFinalizarVenta();
                }
            }

            function confirmarFinalizarVenta() {
                const metodoPago = document.querySelector('input[name="metodo_pago"]:checked').value;
                const total = document.getElementById('total-final').textContent;

                Swal.fire({
                    title: '¿Finalizar venta?',
                    html: `Método de pago: <strong>${metodoPago.toUpperCase()}</strong><br>
                Total: <strong>${total}</strong>`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#28a745',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Sí, finalizar venta',
                    cancelButtonText: 'Cancelar'
                }).then((result) => {
                    if (result.isConfirmed) {
                        procesarVenta();
                    }
                });
            }

            function procesarVenta() {
                const form = document.getElementById('formVenta');
                const formData = new FormData(form);

                // CAPTURAR INFORMACIÓN DE COMBOS DEL CARRITO
                const combosData = [];
                <?php foreach ($carrito_actual as $index => $item): ?>
                    <?php if (isset($item['tipo']) && $item['tipo'] == 'combo'): ?>
                        combosData.push({
                            combo_id: <?php echo $item['combo_id']; ?>,
                            cantidad: <?php echo $item['cantidad']; ?>,
                            precio: <?php echo $item['precio']; ?>,
                            subtotal: <?php echo $item['subtotal']; ?>
                        });
                    <?php endif; ?>
                <?php endforeach; ?>

                // Agregar datos al FormData
                formData.append('finalizar_venta', 'true');
                if (combosData.length > 0) {
                    formData.append('combos_data', JSON.stringify(combosData));
                }

                // DEBUG: Mostrar datos que se envían
                console.log('Datos enviados:');
                for (let pair of formData.entries()) {
                    console.log(pair[0] + ': ' + pair[1]);
                }

                Swal.fire({
                    title: 'Procesando venta...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                fetch('ventas.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(html => {
                        console.log('Respuesta completa del servidor:', html);

                        // Cerrar loading
                        Swal.close();

                        // Ejecutar scripts de respuesta
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = html;
                        const scripts = tempDiv.getElementsByTagName('script');

                        for (let i = 0; i < scripts.length; i++) {
                            try {
                                eval(scripts[i].innerHTML);
                            } catch (e) {
                                console.error('Error ejecutando script:', e);
                            }
                        }

                        // Si no hay scripts, recargar la página
                        if (scripts.length === 0) {
                            window.location.reload();
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error de conexión',
                            text: 'No se pudo procesar la venta: ' + error.message
                        });
                    });
            }

            // ========== FUNCIONES AUXILIARES ==========
            function actualizarOpcionesPagoParcial() {
                const selectMetodo = document.getElementById('metodo-parcial');
                const clienteId = document.getElementById('cliente_id').value;
                const opcionCredito = selectMetodo.querySelector('option[value="credito"]');

                if (opcionCredito) {
                    if (clienteId) {
                        opcionCredito.disabled = false;
                        opcionCredito.textContent = 'Crédito ✓';
                    } else {
                        opcionCredito.disabled = true;
                        opcionCredito.textContent = 'Crédito (seleccione cliente)';
                    }
                }
            }

            function nuevaVenta() {
                Swal.fire({
                    title: '¿Iniciar nueva venta?',
                    text: "Se limpiará el carrito actual",
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Sí, nueva venta',
                    cancelButtonText: 'Cancelar'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = 'ventas.php';
                    }
                });
            }

            document.addEventListener('DOMContentLoaded', function() {
                // Configurar eventos
                setInterval(actualizarHora, 1000);
                actualizarHora();

                document.getElementById('descuento').addEventListener('input', calcularTotales);
                document.getElementById('modalCantidadInput').addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') agregarProductoAjax();
                });

                // Configurar categorías
                document.querySelectorAll('.categoria-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        document.querySelectorAll('.categoria-btn').forEach(b => b.classList.remove('active'));
                        this.classList.add('active');
                        filtrarProductos();
                    });
                });

                // Configurar métodos de pago
                document.querySelectorAll('.metodo-pago-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        document.querySelectorAll('.metodo-pago-btn').forEach(b => b.classList.remove('active'));
                        this.classList.add('active');
                        this.querySelector('input').checked = true;
                        calcularTotales();
                        toggleInfoImpuesto();
                        togglePagoParcial();
                    });
                });

                // Configurar formularios de eliminación
                const formVaciar = document.getElementById('formVaciarCarrito');
                if (formVaciar) {
                    formVaciar.addEventListener('submit', function(e) {
                        e.preventDefault();
                        vaciarCarrito();
                    });
                }

                const formsEliminar = document.querySelectorAll('.formEliminarProducto');
                formsEliminar.forEach((form, index) => {
                    form.addEventListener('submit', function(e) {
                        e.preventDefault();
                        eliminarProductoIndividual(this);
                    });
                });

                // Cerrar modales al hacer clic fuera
                const modalBuscar = document.getElementById('modalBuscarCliente');
                if (modalBuscar) {
                    modalBuscar.addEventListener('click', function(e) {
                        if (e.target === modalBuscar) cerrarBuscarCliente();
                    });
                }

                const cuentasPanel = document.getElementById('cuentasPanel');
                if (cuentasPanel) {
                    cuentasPanel.addEventListener('click', function(e) {
                        if (e.target === cuentasPanel) cerrarCuentas();
                    });
                }

                const modalCrearCuenta = document.getElementById('modalCrearCuenta');
                if (modalCrearCuenta) {
                    modalCrearCuenta.addEventListener('click', function(e) {
                        if (e.target === modalCrearCuenta) cerrarCrearCuenta();
                    });
                }

                const modalCantidad = document.getElementById('modalCantidad');
                if (modalCantidad) {
                    modalCantidad.addEventListener('click', function(e) {
                        if (e.target === modalCantidad) cerrarModal();
                    });
                }

                // Inicializar cálculos
                calcularTotales();
                toggleInfoImpuesto();
            });
            // ========== FUNCIONES FALTANTES ==========

            // Función para agregar producto con AJAX
            function agregarProductoAjax() {
                const productoId = document.getElementById('modalProductoId').value;
                const cantidad = document.getElementById('modalCantidadInput').value;
                const productoCard = document.querySelector(`.producto-card[data-id="${productoId}"]`);

                if (!productoCard) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'No se pudo encontrar la información del producto'
                    });
                    return;
                }

                const precio = productoCard.getAttribute('data-precio');

                const formData = new FormData();
                formData.append('producto_id', productoId);
                formData.append('cantidad', cantidad);
                formData.append('precio', precio);
                formData.append('agregar_producto', 'true');

                <?php if ($cuenta_activa): ?>
                    formData.append('cuenta_id', '<?php echo $cuenta_activa; ?>');
                <?php endif; ?>

                Swal.fire({
                    title: 'Agregando producto...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                fetch('agregar_producto.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        Swal.close();

                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: '¡Producto agregado!',
                                text: data.message,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        Swal.close();
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error de conexión',
                            text: 'No se pudo agregar el producto'
                        });
                    })
                    .finally(() => {
                        cerrarModal();
                    });
            }

            // Función para eliminar producto individual
            function eliminarProductoIndividual(form) {
                Swal.fire({
                    title: '¿Eliminar producto?',
                    text: 'Esta acción no se puede deshacer',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Sí, eliminar',
                    cancelButtonText: 'Cancelar'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Eliminando...',
                            text: 'Por favor espere',
                            allowOutsideClick: false,
                            didOpen: () => Swal.showLoading()
                        });

                        const formData = new FormData(form);

                        fetch('ventas.php', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.text())
                            .then(html => {
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = html;
                                const scripts = tempDiv.getElementsByTagName('script');

                                for (let i = 0; i < scripts.length; i++) {
                                    eval(scripts[i].innerHTML);
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error de conexión',
                                    text: 'No se pudo eliminar el producto'
                                }).then(() => {
                                    window.location.reload();
                                });
                            });
                    }
                });
            }

            // Función para vaciar carrito
            function vaciarCarrito() {
                const cuentaActiva = <?php echo $cuenta_activa ? 'true' : 'false'; ?>;
                const tieneProductos = <?php echo !empty($carrito_actual) ? 'true' : 'false'; ?>;

                if (!tieneProductos) {
                    Swal.fire({
                        icon: 'info',
                        title: 'Carrito vacío',
                        text: 'No hay productos para vaciar'
                    });
                    return;
                }

                Swal.fire({
                    title: '¿Estás seguro?',
                    text: cuentaActiva ?
                        'Se eliminarán todos los productos de la cuenta' : 'Se vaciará todo el carrito de venta',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Sí, vaciar',
                    cancelButtonText: 'Cancelar'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Vaciando...',
                            text: 'Por favor espere',
                            allowOutsideClick: false,
                            didOpen: () => Swal.showLoading()
                        });

                        const formData = new FormData();
                        formData.append('vaciar_carrito', 'true');

                        <?php if ($cuenta_activa): ?>
                            formData.append('cuenta_id', '<?php echo $cuenta_activa; ?>');
                        <?php endif; ?>

                        fetch('ventas.php', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.text())
                            .then(html => {
                                if (html.includes('Swal.fire')) {
                                    const tempDiv = document.createElement('div');
                                    tempDiv.innerHTML = html;
                                    const scripts = tempDiv.getElementsByTagName('script');

                                    for (let i = 0; i < scripts.length; i++) {
                                        try {
                                            eval(scripts[i].innerHTML);
                                        } catch (e) {
                                            console.error('Error ejecutando script:', e);
                                        }
                                    }
                                } else {
                                    window.location.reload();
                                }
                            })
                            .catch(error => {
                                console.error('Error de conexión:', error);
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error de conexión',
                                    text: 'No se pudo vaciar el carrito'
                                }).then(() => {
                                    window.location.reload();
                                });
                            });
                    }
                });
            }

            // ========== FUNCIONES PARA CLIENTES Y CUENTAS ==========

            // Buscar clientes en tiempo real
            let timeoutBusqueda;
            document.getElementById('searchCliente').addEventListener('input', function(e) {
                clearTimeout(timeoutBusqueda);
                const termino = e.target.value.trim();

                if (termino.length < 2) {
                    document.getElementById('clientResults').innerHTML =
                        '<p style="text-align: center; color: #ccc; padding: 20px;">Escribe al menos 2 caracteres...</p>';
                    document.getElementById('nuevo-cliente-section').style.display = 'none';
                    return;
                }

                timeoutBusqueda = setTimeout(() => {
                    buscarClientes(termino);
                }, 300);
            });

            function buscarClientes(termino) {
                fetch('buscar_clientes.php?q=' + encodeURIComponent(termino))
                    .then(response => response.json())
                    .then(data => {
                        const resultados = document.getElementById('clientResults');
                        if (data.success && data.clientes.length > 0) {
                            let html = '';
                            data.clientes.forEach(cliente => {
                                html += `
                            <div class="client-item" onclick="seleccionarClienteModal(${cliente.id}, '${cliente.nombre.replace(/'/g, "\\'")}', '${cliente.telefono || ''}')">
                                <strong>${cliente.nombre}</strong><br>
                                <small>Tel: ${cliente.telefono || 'N/A'} | Puntos: ${cliente.puntos || 0}</small>
                            </div>
                        `;
                            });
                            html += `
                        <div class="client-item" onclick="mostrarFormularioNuevoClienteModal()" style="background: #2d5016; border-color: #4CAF50;">
                            <strong>+ Agregar nuevo cliente</strong><br>
                            <small>Crear un nuevo cliente</small>
                        </div>
                    `;
                            resultados.innerHTML = html;
                        } else {
                            resultados.innerHTML = `
                        <p style="text-align: center; color: #ccc; padding: 10px;">No se encontraron clientes</p>
                        <div class="client-item" onclick="mostrarFormularioNuevoClienteModal()" style="background: #2d5016; border-color: #4CAF50;">
                            <strong>+ Agregar nuevo cliente</strong><br>
                            <small>Crear un nuevo cliente</small>
                        </div>
                    `;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        document.getElementById('clientResults').innerHTML =
                            '<p style="text-align: center; color: #ff6b6b; padding: 20px;">Error al buscar clientes</p>';
                    });
            }

            function seleccionarClienteModal(clienteId, clienteNombre, clienteTelefono) {
                document.getElementById('cliente_id').value = clienteId;
                document.getElementById('cliente-nombre').textContent = clienteNombre;
                document.getElementById('cliente-info').style.display = 'block';

                cerrarBuscarCliente();

                const metodoCredito = document.querySelector('input[name="metodo_pago"][value="credito"]');
                if (metodoCredito && metodoCredito.checked) {
                    verificarCreditoCliente();
                }

                const metodoParcial = document.querySelector('input[name="metodo_pago"][value="parcial"]');
                if (metodoParcial && metodoParcial.checked) {
                    actualizarOpcionesPagoParcial();
                }

                Swal.fire({
                    icon: 'success',
                    title: 'Cliente seleccionado',
                    text: clienteNombre,
                    timer: 1500,
                    showConfirmButton: false
                });
            }

            function mostrarFormularioNuevoClienteModal() {
                document.getElementById('clientResults').innerHTML = '';
                document.getElementById('nuevo-cliente-section').style.display = 'block';
            }

            function agregarNuevoCliente() {
                const nombre = document.getElementById('nuevoClienteNombre').value.trim();
                const telefono = document.getElementById('nuevoClienteTelefono').value.trim();
                const email = document.getElementById('nuevoClienteEmail').value.trim();

                if (!nombre) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'El nombre del cliente es obligatorio'
                    });
                    return;
                }

                const formData = new FormData();
                formData.append('nombre', nombre);
                formData.append('telefono', telefono);
                formData.append('email', email);
                formData.append('agregar_cliente', 'true');

                fetch('agregar_cliente.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            seleccionarClienteModal(data.cliente_id, nombre, telefono);
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error de conexión',
                            text: 'No se pudo agregar el cliente'
                        });
                    });
            }

            // Funciones para cuentas
            function cargarCuentasActivas() {
                const cuentasList = document.getElementById('cuentasList');
                cuentasList.innerHTML = '<div class="loading">Cargando cuentas...</div>';

                fetch('obtener_cuentas_activas.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (data.cuentas.length > 0) {
                                let html = '';
                                data.cuentas.forEach(cuenta => {
                                    html += `
                                <div class="cuenta-item" onclick="seleccionarCuenta(${cuenta.id})">
                                    <strong>${cuenta.cliente_nombre}</strong><br>
                                    <small>Cuenta #${cuenta.id} | Creada: ${cuenta.fecha_creacion}</small><br>
                                    <small>Productos: ${cuenta.total_productos} | Total: ${formatoMoneda(cuenta.total)}</small>
                                </div>
                            `;
                                });
                                cuentasList.innerHTML = html;
                            } else {
                                cuentasList.innerHTML = '<p style="text-align: center; color: #ccc; padding: 20px;">No hay cuentas activas</p>';
                            }
                        } else {
                            cuentasList.innerHTML = '<p style="text-align: center; color: #ff6b6b; padding: 20px;">Error al cargar cuentas</p>';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        cuentasList.innerHTML = '<p style="text-align: center; color: #ff6b6b; padding: 20px;">Error de conexión</p>';
                    });
            }

            function seleccionarCuenta(cuentaId) {
                window.location.href = 'ventas.php?cuenta=' + cuentaId;
            }

            // Funciones para crear cuenta
            function buscarClientesCuenta(termino) {
                if (termino.length < 2) {
                    document.getElementById('resultados-clientes-cuenta').innerHTML =
                        '<p style="text-align: center; color: #ccc; padding: 10px;">Escribe al menos 2 caracteres...</p>';
                    return;
                }

                fetch('buscar_clientes.php?q=' + encodeURIComponent(termino))
                    .then(response => response.json())
                    .then(data => {
                        const resultados = document.getElementById('resultados-clientes-cuenta');
                        if (data.success && data.clientes.length > 0) {
                            let html = '';
                            data.clientes.forEach(cliente => {
                                html += `
                            <div class="client-item" onclick="seleccionarClienteCuenta(${cliente.id}, '${cliente.nombre.replace(/'/g, "\\'")}')">
                                <strong>${cliente.nombre}</strong><br>
                                <small>Tel: ${cliente.telefono || 'N/A'} | Puntos: ${cliente.puntos || 0}</small>
                            </div>
                        `;
                            });
                            html += `
                        <div class="client-item" onclick="mostrarNuevoClienteCuenta()" style="background: #2d5016; border-color: #4CAF50;">
                            <strong>+ Usar nuevo cliente</strong>
                        </div>
                    `;
                            resultados.innerHTML = html;
                        } else {
                            resultados.innerHTML = `
                        <p style="text-align: center; color: #ccc; padding: 10px;">No se encontraron clientes</p>
                        <div class="client-item" onclick="mostrarNuevoClienteCuenta()" style="background: #2d5016; border-color: #4CAF50;">
                            <strong>+ Usar nuevo cliente</strong>
                        </div>
                    `;
                        }
                    });
            }

            function seleccionarClienteCuenta(clienteId, clienteNombre) {
                document.getElementById('cliente_id_cuenta').value = clienteId;
                document.getElementById('cliente_nombre_cuenta').value = clienteNombre;

                document.getElementById('resultados-clientes-cuenta').innerHTML =
                    `<p style="text-align: center; color: #4CAF50; padding: 10px;">
            <strong>✓ Cliente seleccionado:</strong><br>${clienteNombre}
            </p>`;
            }

            function mostrarNuevoClienteCuenta() {
                document.getElementById('cliente-existente-section').style.display = 'none';
                document.getElementById('nuevo-cliente-cuenta-section').style.display = 'block';
                document.getElementById('cliente_id_cuenta').value = '';
                document.getElementById('cliente_nombre_cuenta').value = '';
                document.getElementById('cliente_telefono_cuenta').value = '';
            }

            function crearCuentaAjax() {
                const formData = new FormData(document.getElementById('formCrearCuenta'));

                const clienteId = document.getElementById('cliente_id_cuenta').value;
                const clienteNombre = document.getElementById('cliente_nombre_cuenta').value;
                const seccionNuevoCliente = document.getElementById('nuevo-cliente-cuenta-section').style.display !== 'none';

                if (seccionNuevoCliente) {
                    if (!clienteNombre || clienteNombre.trim() === '' || clienteNombre === 'Cliente General') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Debes ingresar un nombre para el nuevo cliente'
                        });
                        return;
                    }
                } else {
                    if (!clienteId) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Debes seleccionar un cliente existente'
                        });
                        return;
                    }
                }

                Swal.fire({
                    title: 'Creando cuenta...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                fetch('ventas.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        Swal.close();

                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Cuenta creada',
                                text: data.message,
                                confirmButtonText: 'Abrir Cuenta'
                            }).then((result) => {
                                cerrarCrearCuenta();
                                if (result.isConfirmed) {
                                    window.location.href = 'ventas.php?cuenta=' + data.cuenta_id;
                                } else {
                                    window.location.href = 'ventas.php';
                                }
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.close();
                        Swal.fire({
                            icon: 'error',
                            title: 'Error de conexión',
                            text: 'No se pudo crear la cuenta'
                        });
                    });
            }
            document.addEventListener('DOMContentLoaded', function() {
                const forms = document.querySelectorAll('form[class="combo-actions"]');
                forms.forEach(form => {
                    form.addEventListener('submit', function(e) {
                        e.preventDefault();
                        const cantidad = this.querySelector('input[name="cantidad"]').value;
                        const comboNombre = this.closest('.combo-card').querySelector('.combo-nombre').textContent;

                        Swal.fire({
                            title: 'Confirmar Venta',
                            html: `¿Vender <strong>${cantidad}</strong> combo(s) de <strong>${comboNombre}</strong>?`,
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonColor: '#28a745',
                            cancelButtonColor: '#d33',
                            confirmButtonText: 'Sí, vender',
                            cancelButtonText: 'Cancelar'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                this.submit();
                            }
                        });
                    });
                });
            });

            // Validación de cantidad en tiempo real
            document.querySelectorAll('.cantidad-input').forEach(input => {
                input.addEventListener('change', function() {
                    if (this.value < 1) this.value = 1;
                    if (this.value > 10) this.value = 10;
                });
            });
            // Función para seleccionar combo
            function seleccionarCombo(element, comboId) {
                const nombre = element.getAttribute('data-nombre');
                const precio = element.getAttribute('data-precio');

                // Verificar stock antes de agregar
                verificarStockCombo(comboId, 1).then(stockSuficiente => {
                    if (stockSuficiente) {
                        mostrarModalCombo(comboId, nombre, precio);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Stock insuficiente',
                            text: 'No hay suficiente stock para este combo'
                        });
                    }
                }).catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'No se pudo verificar el stock del combo'
                    });
                });
            }

            // Función para verificar stock del combo
            async function verificarStockCombo(comboId, cantidad) {
                try {
                    const response = await fetch(`verificar_stock_combo.php?combo_id=${comboId}&cantidad=${cantidad}`);
                    const data = await response.json();
                    return data.stock_suficiente;
                } catch (error) {
                    console.error('Error verificando stock:', error);
                    return false;
                }
            }

            // Función para mostrar modal de combo
            function mostrarModalCombo(comboId, nombre, precio) {
                Swal.fire({
                    title: nombre,
                    html: `Precio: <strong>${formatoMoneda(precio)}</strong><br><br>
              <input type="number" id="cantidadCombo" class="swal2-input" 
                     value="1" min="1" max="10" placeholder="Cantidad">`,
                    showCancelButton: true,
                    confirmButtonText: 'Agregar al Carrito',
                    cancelButtonText: 'Cancelar',
                    preConfirm: () => {
                        const cantidad = parseInt(document.getElementById('cantidadCombo').value);
                        if (!cantidad || cantidad < 1) {
                            Swal.showValidationMessage('Ingrese una cantidad válida');
                            return false;
                        }
                        return {
                            cantidad: cantidad
                        };
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        agregarComboAlCarrito(comboId, result.value.cantidad);
                    }
                });
            }

            // Función para agregar combo al carrito - CORREGIDA
            function agregarComboAlCarrito(comboId, cantidad) {
                const formData = new FormData();
                formData.append('combo_id', comboId);
                formData.append('cantidad', cantidad);
                formData.append('agregar_combo_carrito', 'true');

                <?php if ($cuenta_activa): ?>
                    formData.append('cuenta_id', '<?php echo $cuenta_activa; ?>');
                <?php endif; ?>

                Swal.fire({
                    title: 'Agregando combo...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                // CAMBIAR ESTA LÍNEA - enviar a ventas.php en lugar de agregar_combo_carrito.php
                fetch('ventas.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Combo agregado!',
                                text: data.message,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error de conexión',
                            text: 'No se pudo agregar el combo'
                        });
                    });
            }
        </script>
    </body>

    </html>