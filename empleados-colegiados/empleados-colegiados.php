<?php
/**
 * Plugin Name: Empleados Colegiados
 * Plugin URI: https://promentconsulting.com
 * Description: Permite a los usuarios gestionar empleados desde su área personal.
 * Version: 1.0
 * Author: Proment Consulting
 * Author URI: https://promentconsulting.com
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit; // Evita el acceso directo
}

// Activación del plugin - Crear la tabla de empleados
function ew_crear_tabla_empleados() {
    global $wpdb;
    $tabla_empleados = $wpdb->prefix . "empleados";
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $tabla_empleados (
        id INT NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        nombre VARCHAR(100) NOT NULL,
        apellidos VARCHAR(100) NOT NULL,
        nif VARCHAR(20) NOT NULL,
        correo VARCHAR(100) NOT NULL,
        PRIMARY KEY (id),
        FOREIGN KEY (user_id) REFERENCES {$wpdb->prefix}users(ID) ON DELETE CASCADE
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'ew_crear_tabla_empleados');

// Cambiar el enlace en el menú de WooCommerce
function ew_modificar_enlace_menu($items) {
    $items['mis-empleados'] = __('Mis Empleados', 'woocommerce');
    return $items;
}
add_filter('woocommerce_account_menu_items', 'ew_modificar_enlace_menu');

// Reemplazar la URL del enlace en "Mi Cuenta"
function ew_modificar_url_menu($url, $endpoint, $value, $permalink) {
    if ($endpoint === 'mis-empleados') {
        return site_url('/area-privada/mis-empleados/');
    }
    return $url;
}
add_filter('woocommerce_get_endpoint_url', 'ew_modificar_url_menu', 10, 4);


// Registrar nueva URL personalizada para "Mis Empleados"
function ew_rewrite_area_privada() {
    add_rewrite_rule('^area-privada/mis-empleados/?$', 'index.php?ew_area_privada=1', 'top');
}
add_action('init', 'ew_rewrite_area_privada');

// Añadir la variable de consulta (query var)
function ew_query_vars($vars) {
    $vars[] = 'ew_area_privada';
    return $vars;
}
add_filter('query_vars', 'ew_query_vars');

// Interceptar la solicitud y cargar la plantilla correcta
function ew_cargar_template_empleados($template) {
    if (get_query_var('ew_area_privada') == 1) {
        status_header(200);
        return plugin_dir_path(__FILE__) . 'templates/mis-empleados.php';
    }
    return $template;
}
add_filter('template_include', 'ew_cargar_template_empleados');

require_once plugin_dir_path(__FILE__) . 'empleados-productos.php';




