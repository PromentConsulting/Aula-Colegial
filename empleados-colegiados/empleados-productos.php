<?php
/**
 * Plugin Name: Empleados en Productos
 * Plugin URI: https://promentconsulting.com
 * Description: Permite a los usuarios seleccionar sus empleados al comprar cursos en WooCommerce.
 * Version: 1.0
 * Author: Proment Consulting
 * Author URI: https://promentconsulting.com
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit; // Evita el acceso directo
}

function ep_agregar_checkboxes_empleados() {
    global $wpdb, $product;

    if (!is_user_logged_in()) {
        echo '<p>Debes iniciar sesión para seleccionar empleados.</p>';
        return;
    }

    $user_id    = get_current_user_id();
    $user_data  = get_userdata($user_id);
    if (!$user_data) {
        echo '<p>No se pudo cargar la información del usuario.</p>';
        return;
    }

    $user_email = $user_data->user_email;
    $user_name  = $user_data->display_name;

    // ----- Nombre / Apellidos del colegiado (fallbacks) -----
    $first = (string) get_user_meta($user_id, 'first_name', true);
    $last  = (string) get_user_meta($user_id, 'last_name', true);

    // Intentar billing_* si faltan
    if ($first === '') {
        $billing_first = (string) get_user_meta($user_id, 'billing_first_name', true);
        if ($billing_first !== '') $first = $billing_first;
    }
    if ($last === '') {
        $billing_last = (string) get_user_meta($user_id, 'billing_last_name', true);
        if ($billing_last !== '') $last = $billing_last;
    }

    // Si siguen faltando, separar display_name
    if ($first === '' || $last === '') {
        $dn = trim(preg_replace('/\s+/', ' ', (string) $user_name));
        if ($dn !== '') {
            $parts = explode(' ', $dn);
            if ($first === '' && !empty($parts)) {
                $first = array_shift($parts);
            }
            if ($last === '') {
                $last = trim(implode(' ', $parts));
            }
        }
    }
    if ($last === '') $last = 'Pendiente';

    // ----- NIF (DNI) del colegiado: usar user_login o metadatos -----
    $nif_wp = '';
    if (!empty($user_data->user_login)) {
        $nif_wp = (string) $user_data->user_login; // ← aquí guardáis el DNI
    }
    if ($nif_wp === '') {
        $dni_meta = (string) get_user_meta($user_id, 'dni', true);
        $nif_meta = (string) get_user_meta($user_id, 'nif', true);
        $nif_wp   = $dni_meta !== '' ? $dni_meta : ($nif_meta !== '' ? $nif_meta : '');
    }

    // ----- Empleados del usuario -----
    $tabla_empleados = $wpdb->prefix . "empleados";
    $empleados = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM {$tabla_empleados} WHERE user_id = %d", (int) $user_id)
    );

    echo '<div class="woocommerce_custom_field" style="margin-bottom: 15px;">';

    // Sección 1: Checkbox para que el propio colegiado pueda inscribirse
    echo '<h4>¿Quieres realizar el curso tú mismo?</h4>';

    $payload_colegiado = [
        'nombre'    => $first !== '' ? $first : $user_name,
        'apellidos' => $last,
        'email'     => $user_email,
        'nif'       => $nif_wp, // ← ya viene con el DNI/username
        'telefono'  => ''
    ];
    echo '<label style="display: block; margin-bottom: 10px; background: #f7f7f7; padding: 10px; border-radius: 5px;">';
    echo '  <input type="checkbox" name="curso_empleados[]" value=\'' . esc_attr( wp_json_encode( $payload_colegiado ) ) . '\' class="empleado-checkbox">';
    echo '  ' . esc_html( ($first !== '' ? $first : $user_name) . ' ' . $last . " ({$user_email})" );
    echo '</label>';

    // Sección 2: Selección de empleados
    echo '<h4>Selecciona los empleados para este curso:</h4>';

    if (!$empleados) {
        echo '<p>No tienes empleados registrados.</p>';
    } else {
        foreach ($empleados as $empleado) {
            // Asegurar apellidos legibles si vienen vacíos en la tabla
            $apellidos_emp = isset($empleado->apellidos) && $empleado->apellidos !== '' ? $empleado->apellidos : 'Pendiente';
            $payload_empleado = [
                'nombre'    => (string) $empleado->nombre,
                'apellidos' => (string) $apellidos_emp,
                'email'     => (string) $empleado->correo,
                'nif'       => (string) $empleado->nif,
                'telefono'  => (string) $empleado->telefono,
            ];

            echo '<label style="display: block; margin-bottom: 5px; border-bottom: 1px solid #ddd; padding: 0.5em;">';
            echo '  <input type="checkbox" name="curso_empleados[]" value=\'' . esc_attr( wp_json_encode( $payload_empleado ) ) . '\' class="empleado-checkbox">';
            echo '  ' . esc_html( $empleado->nombre . ' ' . $apellidos_emp . ' (' . $empleado->correo . ')' );
            echo '</label>';
        }
    }

    echo '<button type="button" class="btn btn-add" id="abrir-modal-empleado">+ Añadir nuevo empleado</button>';

    echo '</div>';

    // Campo oculto para asegurar que WooCommerce recibe la cantidad seleccionada
    echo '<input type="hidden" name="cantidad_empleados" id="cantidad_empleados" value="1">';

    // Botón invisible para evitar errores de diseño
    echo '<button style="color: white!important; background-color: white!important;">.</button>';
}
add_action('woocommerce_before_add_to_cart_button', 'ep_agregar_checkboxes_empleados');

function ep_agregar_modal_empleados() {
    ?>
    <div id="modalAgregarEmpleado" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('modalAgregarEmpleado')">&times;</span>
            <h3>Añadir nuevo empleado</h3>
            <form id="formAgregarEmpleado">
                <label>Nombre:</label>
                <input type="text" name="nombre" id="nuevo_nombre" required>

                <label>Apellidos:</label>
                <input type="text" name="apellidos" id="nuevo_apellidos" required>

                <label>NIF/NIE:</label>
                <input type="text" name="nif" id="nuevo_nif" required>
                <span style="color: red; font-size: 0.9em; display: block; margin-top: 4px;" class="error-text" id="error_nif"></span>

                <label>Correo:</label>
                <input type="email" name="correo" id="nuevo_correo" required>
                <span style="color: red; font-size: 0.9em; display: block; margin-top: 4px;" class="error-text" id="error_correo"></span>

                <label>Teléfono:</label>
                <input type="text" name="telefono" id="nuevo_telefono" required>
                <span class="error-text" id="error_telefono" style="color:red;font-size:13px;"></span>

                <button type="button" onclick="guardarNuevoEmpleado()">Guardar</button>
            </form>
        </div>
    </div>
    <?php
}
add_action('wp_footer', 'ep_agregar_modal_empleados');

function ep_agregar_toast_empleado() {
    ?>
    <div id="toast-empleado" style="
        position: fixed;
        bottom: 30px;
        right: 30px;
        background-color: #28a745;
        color: white;
        padding: 15px 25px;
        border-radius: 5px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        opacity: 0;
        transition: opacity 0.5s ease-in-out;
        z-index: 9999;
    ">
        Empleado añadido correctamente
    </div>
    <?php
}
add_action('wp_footer', 'ep_agregar_toast_empleado');

function ep_agregar_estilos_empleados() {
    ?>
    <style>
        /* Estilos del modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            padding: 20px;
            border-radius: 8px;
            width: 40%;
            box-shadow: 0px 4px 8px rgba(0, 0, 0, 0.2);
        }

        .close-modal {
            float: right;
            cursor: pointer;
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }

        .close-modal:hover {
            color: red;
        }

        input {
            padding: 8px;
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        button {
            display: block;
            width: 100%;
            margin-top: 15px;
            padding: 10px;
            background: #0073aa;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        button:hover {
            background: #005a87;
        }

        .woocommerce_custom_field {
            margin-bottom: 15px;
        }
        .empleado-checkbox {
            margin-right: 10px;
        }
    </style>
    <?php
}
add_action('wp_head', 'ep_agregar_estilos_empleados');

function ep_validar_nif_nie($id) {
    $id = strtoupper(preg_replace('/[\s-]+/', '', (string)$id));
    if ($id === '') return false;

    $letras = 'TRWAGMYFPDXBNJZSQVHLCKE';

    // DNI: 8 dígitos + letra
    if (preg_match('/^\d{8}[A-Z]$/', $id)) {
        $num = (int) substr($id, 0, 8);
        $letra = substr($id, -1);
        return $letras[$num % 23] === $letra;
    }

    // NIE: X/Y/Z + 7 dígitos + letra
    if (preg_match('/^[XYZ]\d{7}[A-Z]$/', $id)) {
        $map = ['X' => '0', 'Y' => '1', 'Z' => '2'];
        $num = (int) ($map[$id[0]] . substr($id, 1, 7));
        $letra = substr($id, -1);
        return $letras[$num % 23] === $letra;
    }

    return false;
}

// Guardar nuevo empleado vía AJAX
function ep_guardar_nuevo_empleado() {
    global $wpdb;

    if (!is_user_logged_in()) {
        wp_send_json_error('Debes iniciar sesión.');
    }

    $user_id   = get_current_user_id();
    $nombre    = sanitize_text_field($_POST['nombre']);
    $apellidos = sanitize_text_field($_POST['apellidos']);
    $nif       = sanitize_text_field($_POST['nif']);
    $correo    = sanitize_email($_POST['correo']);
    $telefono  = sanitize_text_field($_POST['telefono']);

    if (empty($nombre) || empty($apellidos) || empty($nif) || empty($correo) || empty($telefono)) {
        wp_send_json_error('Todos los campos son obligatorios.');
    }

    $tabla_empleados = $wpdb->prefix . "empleados";

    // Comprobar si ya existe el correo o el NIF para ese usuario
    $errores = [];

    if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tabla_empleados WHERE user_id = %d AND nif = %s", $user_id, $nif))) {
        $errores['nif'] = 'Ya existe un empleado con ese NIF.';
    }
    if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tabla_empleados WHERE user_id = %d AND correo = %s", $user_id, $correo))) {
        $errores['correo'] = 'Ya existe un empleado con ese correo.';
    }

    // Validación de NIF/NIE en servidor (evita saltarse el JS)
    if (!ep_validar_nif_nie($nif)) {
        $errores['nif'] = 'NIF/NIE no válido. Revisa el formato y la letra.';
    }

    if (!empty($errores)) {
        wp_send_json_error($errores);
    }

    $insertado = $wpdb->insert($tabla_empleados, [
        'user_id'   => $user_id,
        'nombre'    => $nombre,
        'apellidos' => $apellidos,
        'nif'       => $nif,
        'correo'    => $correo,
        'telefono'  => $telefono
    ], ['%d', '%s', '%s', '%s', '%s', '%s']);

    if ($insertado) {
        wp_send_json_success([
            'mensaje' => 'Empleado añadido correctamente.',
            'empleado' => [
                'correo'    => $correo,
                'nombre'    => $nombre,
                'apellidos' => $apellidos,
                'telefono'  => $telefono
            ]
        ]);
    } else {
        wp_send_json_error('Error al guardar el empleado.');
    }
}
add_action('wp_ajax_ep_guardar_nuevo_empleado', 'ep_guardar_nuevo_empleado');
add_action('wp_ajax_nopriv_ep_guardar_nuevo_empleado', 'ep_guardar_nuevo_empleado');

// Función CORREGIDA para validar y dividir en carrito
function ep_validar_empleados_y_dividir_en_carrito($passed, $product_id, $quantity, $variation_id = 0, $variations = array()) {
    error_log("🔍 === VALIDACIÓN EMPLEADOS CORREGIDA ===");
    error_log("Product ID: {$product_id}");
    
    if (!isset($_POST['curso_empleados']) || !is_array($_POST['curso_empleados']) || empty($_POST['curso_empleados'])) {
        wc_add_notice('Debes seleccionar al menos un empleado para inscribir en el curso.', 'error');
        return false;
    }

    $empleados_json = $_POST['curso_empleados'];
    error_log("📋 Total empleados recibidos: " . count($empleados_json));

    // Eliminar items existentes de este producto para evitar duplicados
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        if ($cart_item['product_id'] == $product_id) {
            WC()->cart->remove_cart_item($cart_item_key);
        }
    }

    // Añadir cada empleado como item separado
    foreach ($empleados_json as $index => $empleado_json) {
        error_log("--- Procesando empleado #{$index} ---");
        error_log("JSON: {$empleado_json}");
        
        $empleado = json_decode(stripslashes($empleado_json), true);
        error_log("Decodificado: " . print_r($empleado, true));

        if (!$empleado || !isset($empleado['nombre'], $empleado['apellidos'], $empleado['email'])) {
            error_log("❌ Empleado #{$index} inválido - saltando");
            continue;
        }

        // Crear datos únicos para cada empleado
        $cart_item_data = array(
            'curso_empleados' => array(
                array(
                    'nombre' => sanitize_text_field($empleado['nombre']),
                    'apellidos' => sanitize_text_field($empleado['apellidos']),
                    'email' => sanitize_email($empleado['email']),
                    'nif' => sanitize_text_field($empleado['nif'] ?? ''),
                    'telefono' => sanitize_text_field($empleado['telefono'] ?? '')
                )
            ),
            // Clave única para cada empleado
            'empleado_unique_key' => md5($empleado['email'] . time() . $index)
        );

        error_log("📦 Añadiendo empleado #{$index}: {$empleado['nombre']} {$empleado['apellidos']} ({$empleado['email']})");
        error_log("Cart item data: " . print_r($cart_item_data, true));

        // Añadir al carrito
        $cart_item_key = WC()->cart->add_to_cart($product_id, 1, 0, array(), $cart_item_data);
        
        if ($cart_item_key) {
            error_log("✅ Empleado #{$index} añadido al carrito con key: {$cart_item_key}");
        } else {
            error_log("❌ Error añadiendo empleado #{$index} al carrito");
        }
    }

    // Verificar contenido del carrito después de añadir
    $cart_contents = WC()->cart->get_cart();
    error_log("🔍 Contenido del carrito después de añadir:");
    foreach ($cart_contents as $cart_key => $cart_item) {
        error_log("Cart key: {$cart_key}");
        if (isset($cart_item['curso_empleados'])) {
            error_log("  Empleados: " . print_r($cart_item['curso_empleados'], true));
        }
    }

    error_log("🔍 === FIN VALIDACIÓN EMPLEADOS ===");
    
    // Prevenir que WooCommerce añada el producto original
    return false;
}
add_filter('woocommerce_add_to_cart_validation', 'ep_validar_empleados_y_dividir_en_carrito', 10, 5);


// Nueva función que se ejecuta al crear cada line item del pedido
function ep_transferir_empleados_al_pedido( $item, $cart_item_key, $values, $order ) {
    error_log("🔍 === TRANSFIRIENDO EMPLEADOS AL PEDIDO ===");
    error_log("Cart item key: {$cart_item_key}");

    // Verificar si este item del carrito tiene empleados
    if ( empty($values['curso_empleados']) ) {
        error_log("❌ No se encontraron empleados en este cart item");
        error_log("🔍 === FIN TRANSFERENCIA EMPLEADOS ===");
        return;
    }

    // Normaliza entrada a array
    $empleados_input = is_array($values['curso_empleados']) ? $values['curso_empleados'] : [ $values['curso_empleados'] ];
    error_log("✅ Empleados encontrados en cart item");
    error_log("Empleados (raw): " . print_r($empleados_input, true));

    $final = [];
    $seen  = [];

    foreach ( $empleados_input as $e ) {
        // Puede venir como JSON string desde el front
        if ( is_string($e) ) {
            $decoded = json_decode( stripslashes($e), true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array($decoded) ) {
                $e = $decoded;
            } else {
                // Si no es JSON válido, saltar
                continue;
            }
        }
        if ( !is_array($e) ) continue;

        // Saneado y normalización
        $nombre    = isset($e['nombre'])    ? sanitize_text_field($e['nombre']) : '';
        $apellidos = isset($e['apellidos']) ? sanitize_text_field($e['apellidos']) : '';
        $email_raw = isset($e['email'])     ? $e['email'] : '';
        $email     = sanitize_email( strtolower(trim((string)$email_raw)) );
        $nif_raw   = isset($e['nif'])       ? $e['nif'] : '';
        $nif       = strtoupper( preg_replace('/\s+/', '', sanitize_text_field((string)$nif_raw)) );
        $telefono  = isset($e['telefono'])  ? sanitize_text_field($e['telefono']) : '';

        if ( empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) ) {
            continue;
        }

        // Completar NIF si falta: buscar usuario WP por email y usar user_login (DNI) o metas
        if ( $nif === '' ) {
            if ( $wpuser = get_user_by('email', $email) ) {
                if ( !empty($wpuser->user_login) ) {
                    $nif = (string) $wpuser->user_login;
                }
                if ( $nif === '' ) {
                    $dni_meta = (string) get_user_meta($wpuser->ID, 'dni', true);
                    $nif_meta = (string) get_user_meta($wpuser->ID, 'nif', true);
                    $nif = $dni_meta !== '' ? $dni_meta : ($nif_meta !== '' ? $nif_meta : '');
                    $nif = strtoupper( preg_replace('/\s+/', '', $nif) );
                }
            }
        }

        // Completar apellidos si faltan: meta last_name o separar nombre completo
        if ( $apellidos === '' ) {
            if ( $wpuser = get_user_by('email', $email) ) {
                $ln = (string) get_user_meta($wpuser->ID, 'last_name', true);
                if ( $ln !== '' ) {
                    $apellidos = $ln;
                }
            }
            if ( $apellidos === '' && $nombre !== '' ) {
                $dn = trim( preg_replace('/\s+/', ' ', $nombre) );
                $parts = explode(' ', $dn);
                array_shift($parts);
                $apellidos = trim( implode(' ', $parts) );
            }
            if ( $apellidos === '' ) {
                $apellidos = 'Pendiente';
            }
        }

        // Clave de deduplicación (email + nif si hay)
        $dedup_key = $email . '|' . ($nif ?: '-');
        if ( isset($seen[$dedup_key]) ) {
            continue;
        }
        $seen[$dedup_key] = true;

        $final[] = [
            'nombre'    => $nombre,
            'apellidos' => $apellidos,
            'email'     => $email,
            'nif'       => $nif,
            'telefono'  => $telefono,
        ];
    }

    // Si no quedó nadie, salir
    if ( empty($final) ) {
        error_log("❌ Tras normalizar, no hay empleados válidos");
        error_log("🔍 === FIN TRANSFERENCIA EMPLEADOS ===");
        return;
    }

    // Construir texto legible para admin (una sola vez)
    $lista_legible = array_map(function($e) {
        $tlf = !empty($e['telefono']) ? " – Tel: {$e['telefono']}" : '';
        $nif_txt = !empty($e['nif']) ? $e['nif'] : '—';
        return "{$e['nombre']} {$e['apellidos']} - NIF: {$nif_txt} ({$e['email']}){$tlf}";
    }, $final);
    $texto_admin = implode(", ", $lista_legible);

    // 💡 MUY IMPORTANTE: eliminar cualquier valor previo y guardar SOLO una vez
    $item->delete_meta_data('curso_empleados');
    $item->delete_meta_data('Empleados inscritos');

    // update_meta_data asegura que haya una sola fila por meta key
    $item->update_meta_data('curso_empleados', array_values($final));
    $item->update_meta_data('Empleados inscritos', $texto_admin);

    error_log("✅ Empleados añadidos/actualizados en el item correctamente");
    error_log("🔍 === FIN TRANSFERENCIA EMPLEADOS ===");
}

// Usar el hook correcto que se ejecuta al crear cada line item
if ( false === has_action('woocommerce_checkout_create_order_line_item', 'ep_transferir_empleados_al_pedido') ) {
    add_action('woocommerce_checkout_create_order_line_item', 'ep_transferir_empleados_al_pedido', 10, 4);
}


// Reemplazar la función ep_guardar_empleados_desde_carrito existente con esta versión corregida:
function ep_guardar_empleados_desde_carrito($order_id, $posted_data, $order) {
    error_log("🔍 === GUARDANDO DESDE CARRITO - Order ID: {$order_id} ===");
    
    try {
        $cart_items = WC()->cart->get_cart();
        $order_items = $order->get_items();
        
        error_log("📦 Cart items: " . count($cart_items));
        error_log("📦 Order items: " . count($order_items));
        
        // Debug del carrito
        error_log("🛒 CONTENIDO DEL CARRITO:");
        $cart_index = 0;
        foreach ($cart_items as $cart_key => $cart_item) {
            error_log("  Cart #{$cart_index} (key: {$cart_key}):");
            error_log("    Product ID: " . $cart_item['product_id']);
            if (isset($cart_item['curso_empleados'])) {
                error_log("    Empleados: " . print_r($cart_item['curso_empleados'], true));
            } else {
                error_log("    ❌ No tiene curso_empleados");
            }
            $cart_index++;
        }
        
        // Debug del pedido
        error_log("📋 ITEMS DEL PEDIDO:");
        $order_index = 0;
        foreach ($order_items as $item_id => $order_item) {
            error_log("  Order #{$order_index} (ID: {$item_id}):");
            error_log("    Product ID: " . $order_item->get_product_id());
            error_log("    Name: " . $order_item->get_name());
            $order_index++;
        }
        
        // Mapear por product_id en lugar de por índice
        $cart_values = array_values($cart_items);
        $order_values = array_values($order_items);
        
        // Intentar mapear cada order item con su cart item correspondiente
        foreach ($order_values as $order_index => $order_item) {
            $item_id = $order_item->get_id();
            $product_id = $order_item->get_product_id();
            
            error_log("--- Procesando order item {$order_index} (ID: {$item_id}, Product: {$product_id}) ---");
            
            // Buscar el cart item correspondiente
            $cart_item_found = null;
            foreach ($cart_values as $cart_index => $cart_item) {
                if ($cart_item['product_id'] == $product_id && isset($cart_item['curso_empleados'])) {
                    $cart_item_found = $cart_item;
                    error_log("✅ Encontrado cart item matching en índice {$cart_index}");
                    
                    // Remover este cart item para evitar duplicados
                    unset($cart_values[$cart_index]);
                    $cart_values = array_values($cart_values); // Reindexar
                    break;
                }
            }
            
            if ($cart_item_found && isset($cart_item_found['curso_empleados'])) {
                error_log("✅ Guardando empleados en order item {$item_id}");
                error_log("Empleados: " . print_r($cart_item_found['curso_empleados'], true));
                
                // Guardar en order meta
                $saved = wc_add_order_item_meta($item_id, 'curso_empleados', $cart_item_found['curso_empleados']);
                error_log("Resultado wc_add_order_item_meta: " . ($saved ? 'ÉXITO' : 'FALLO'));
                
                // Verificar inmediatamente
                $verificacion = wc_get_order_item_meta($item_id, 'curso_empleados');
                error_log("Verificación inmediata: " . print_r($verificacion, true));
                
                // Crear texto legible para el admin
                $lista = array_map(function($e) {
                    $telefono = isset($e['telefono']) && !empty($e['telefono']) ? " – Tel: {$e['telefono']}" : '';
                    return "{$e['nombre']} {$e['apellidos']} - NIF: {$e['nif']} ({$e['email']}){$telefono}";
                }, $cart_item_found['curso_empleados']);
                
                wc_add_order_item_meta($item_id, 'Empleados inscritos', implode(", ", $lista));
                
                error_log("✅ Empleados guardados correctamente en order item {$item_id}");
            } else {
                error_log("❌ No se encontró cart item con empleados para order item {$item_id}");
            }
        }
        
        // Verificación final
        error_log("🔍 VERIFICACIÓN FINAL:");
        foreach ($order_items as $item_id => $order_item) {
            $empleados_guardados = wc_get_order_item_meta($item_id, 'curso_empleados');
            if (is_array($empleados_guardados) && !empty($empleados_guardados)) {
                error_log("✅ Item {$item_id} tiene empleados guardados: " . count($empleados_guardados));
            } else {
                error_log("❌ Item {$item_id} NO tiene empleados guardados");
            }
        }
        
    } catch (Exception $e) {
        error_log("❌ Error en ep_guardar_empleados_desde_carrito: " . $e->getMessage());
        error_log("❌ Stack trace: " . $e->getTraceAsString());
    }
    
    error_log("🔍 === FIN GUARDADO DESDE CARRITO ===");
}
add_action('woocommerce_checkout_order_processed', 'ep_guardar_empleados_desde_carrito', 10, 3);

// Mostrar empleados en el carrito
function ep_mostrar_empleados_en_carrito($item_data, $cart_item) {
    if (isset($cart_item['curso_empleados']) && is_array($cart_item['curso_empleados'])) {
        $lista = array_map(function($e) {
            return esc_html("{$e['nombre']} {$e['apellidos']} - NIF: {$e['nif']} ({$e['email']}) – Tel: {$e['telefono']}");
        }, $cart_item['curso_empleados']);

        $item_data[] = array(
            'name'  => 'Empleado inscrito',
            'value' => implode(", ", $lista)
        );
    }
    return $item_data;
}
add_filter('woocommerce_get_item_data', 'ep_mostrar_empleados_en_carrito', 10, 2);

// Scripts JavaScript completos
function ep_agregar_script_empleados() {
    ?>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            let modal = document.getElementById("modalAgregarEmpleado");
            let btnAbrir = document.getElementById("abrir-modal-empleado");

            if (btnAbrir) {
                btnAbrir.addEventListener("click", function() {
                    modal.style.display = "flex";
                });
            }

            window.closeModal = function(id) {
                document.getElementById(id).style.display = "none";
            }

            function actualizarCantidad() {
                let checkboxes = document.querySelectorAll(".empleado-checkbox");
                let cantidadInput = document.querySelector("input.qty");
                let cantidadHidden = document.getElementById("cantidad_empleados");

                let seleccionados = document.querySelectorAll(".empleado-checkbox:checked").length;
                let nuevaCantidad = seleccionados > 0 ? seleccionados : 1;

                if (cantidadInput) {
                    cantidadInput.value = nuevaCantidad;
                }
                if (cantidadHidden) {
                    cantidadHidden.value = nuevaCantidad;
                }
            }

            // Asignar evento a los checkboxes para actualizar la cantidad
            let checkboxes = document.querySelectorAll(".empleado-checkbox");
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener("change", actualizarCantidad);
            });

            window.guardarNuevoEmpleado = function() {
                const nombre = document.getElementById("nuevo_nombre").value.trim();
                const apellidos = document.getElementById("nuevo_apellidos").value.trim();
                const nif = document.getElementById("nuevo_nif").value.trim().toUpperCase();
                const correo = document.getElementById("nuevo_correo").value.trim();
                const telefono = document.getElementById("nuevo_telefono").value.trim();

                // Limpiar errores
                document.querySelectorAll('.error-text').forEach(el => el.textContent = "");

                let hayErrores = false;

                if (!nombre) {
                    document.getElementById("error_nombre").textContent = "Este campo es obligatorio.";
                    hayErrores = true;
                }
                if (!apellidos) {
                    document.getElementById("error_apellidos").textContent = "Este campo es obligatorio.";
                    hayErrores = true;
                }

                // Valida DNI o NIE con cálculo de letra
                function validarNifNie(valor) {
                    if (!valor) return false;
                    let v = valor.toUpperCase().replace(/[\s-]/g, '');
                    const reDni = /^\d{8}[A-Z]$/;
                    const reNie = /^[XYZ]\d{7}[A-Z]$/;

                    if (!reDni.test(v) && !reNie.test(v)) return false;

                    const letras = 'TRWAGMYFPDXBNJZSQVHLCKE';
                    let numero = v.slice(0, 8);
                    const letra = v[8];

                    if (reNie.test(v)) {
                        const mapa = { 'X': '0', 'Y': '1', 'Z': '2' };
                        numero = mapa[v[0]] + v.slice(1, 8);
                    }
                    const calc = letras[parseInt(numero, 10) % 23];
                    return calc === letra;
                }

                // Sustituye el antiguo if por este:
                if (!validarNifNie(nif)) {
                    document.getElementById("error_nif").textContent = "Formato de NIF/NIE incorrecto o letra no válida (Ej: 12345678Z o X1234567L)";
                    hayErrores = true;
                }

                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo)) {
                    document.getElementById("error_correo").textContent = "Formato de correo no válido.";
                    hayErrores = true;
                }

                if (!/^\+?\d{6,15}$/.test(telefono)) {
                    document.getElementById("error_telefono").textContent = "Teléfono no válido.";
                    hayErrores = true;
                }

                if (hayErrores) return;

                const data = new FormData();
                data.append('action', 'ep_guardar_nuevo_empleado');
                data.append('nombre', nombre);
                data.append('apellidos', apellidos);
                data.append('nif', nif);
                data.append('correo', correo);
                data.append('telefono', telefono);

                fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                    method: 'POST',
                    body: data
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        mostrarToast();
                        closeModal("modalAgregarEmpleado");

                        const nuevoCheckbox = document.createElement("label");
                        nuevoCheckbox.setAttribute(
                            "style",
                            "display: block; margin-bottom: 5px; border-bottom: 1px solid #ddd; padding: 0.5em;"
                        );
                        nuevoCheckbox.innerHTML = `
                            <input type="checkbox" name="curso_empleados[]" value='${JSON.stringify({
                                nombre: result.data.empleado.nombre,
                                apellidos: result.data.empleado.apellidos,
                                email: result.data.empleado.correo,
                                nif: nif,
                                telefono: telefono
                            })}' class="empleado-checkbox">
                            ${result.data.empleado.nombre} ${result.data.empleado.apellidos} - (${result.data.empleado.correo})
                        `;

                        // Localizar el botón "+ Añadir nuevo empleado"
                        const botonAñadir = document.getElementById("abrir-modal-empleado");

                        // Insertar el nuevo <label> **antes** del botón
                        botonAñadir.parentNode.insertBefore(nuevoCheckbox, botonAñadir);

                        // Reactiva el listener para que actualice la cantidad cuando se marque
                        nuevoCheckbox
                            .querySelector("input")
                            .addEventListener("change", actualizarCantidad);
                        actualizarCantidad();
                    } else {
                        // Limpia errores previos
                        document.getElementById("error_nif").textContent = "";
                        document.getElementById("error_correo").textContent = "";

                        if (typeof result.data === "object") {
                            if (result.data.nif) {
                                document.getElementById("error_nif").textContent = result.data.nif;
                            }
                            if (result.data.correo) {
                                document.getElementById("error_correo").textContent = result.data.correo;
                            }
                        } else {
                            // Fallback si viene un string normal
                            document.getElementById("error_correo").textContent = result.data;
                        }
                    }
                })
                .catch(error => console.error("Error:", error));
            };

        });
        
        function mostrarToast() {
            const toast = document.getElementById("toast-empleado");
            toast.style.opacity = "1";
            setTimeout(() => {
                toast.style.opacity = "0";
            }, 3000);
        }
    </script>
    <?php
}
add_action('wp_footer', 'ep_agregar_script_empleados');

// Función para debug de la base de datos
function ep_debug_database($order_id) {
    global $wpdb;
    
    error_log("🔍 === DEBUG BASE DE DATOS - Order ID: {$order_id} ===");
    
    try {
        // Obtener items del pedido
        $order_items = $wpdb->get_results($wpdb->prepare("
            SELECT order_item_id, order_item_name, order_item_type 
            FROM {$wpdb->prefix}woocommerce_order_items 
            WHERE order_id = %d
        ", $order_id));
        
        error_log("📦 Items en la base de datos: " . count($order_items));
        
        foreach ($order_items as $item) {
            error_log("--- Item ID: {$item->order_item_id} ---");
            error_log("  Name: {$item->order_item_name}");
            error_log("  Type: {$item->order_item_type}");
            
            // Obtener metadatos del item
            $item_meta = $wpdb->get_results($wpdb->prepare("
                SELECT meta_key, meta_value 
                FROM {$wpdb->prefix}woocommerce_order_itemmeta 
                WHERE order_item_id = %d
            ", $item->order_item_id));
            
            error_log("  Metadatos (" . count($item_meta) . "):");
            foreach ($item_meta as $meta) {
                if ($meta->meta_key === 'curso_empleados') {
                    $empleados = maybe_unserialize($meta->meta_value);
                    error_log("    ✅ curso_empleados: " . print_r($empleados, true));
                } else {
                    $meta_value = strlen($meta->meta_value) > 100 ? substr($meta->meta_value, 0, 100) . '...' : $meta->meta_value;
                    error_log("    - {$meta->meta_key}: {$meta_value}");
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("❌ Error en debug_database: " . $e->getMessage());
    }
    
    error_log("🔍 === FIN DEBUG BASE DE DATOS ===");
}

// Hook para ejecutar el debug después del guardado
add_action('woocommerce_checkout_order_processed', 'ep_debug_database', 50, 1);

// Función para debug manual - añade ?debug_order=ID_PEDIDO a cualquier URL
function ep_debug_manual() {
    if (isset($_GET['debug_order']) && current_user_can('manage_options')) {
        $order_id = intval($_GET['debug_order']);
        error_log("🔍 === DEBUG MANUAL SOLICITADO ===");
        ep_debug_database($order_id);
        
        // También hacer el debug del plugin de Moodle
        $moodle = new MoodleIntegrationPro();
        $moodle->debug_order_meta($order_id);
        
        wp_die("Debug completado para pedido #{$order_id}. Revisa los logs.");
    }
}
add_action('init', 'ep_debug_manual');