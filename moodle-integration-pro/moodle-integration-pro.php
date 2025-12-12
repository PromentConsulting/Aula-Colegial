<?php
/**
 * Plugin Name: Moodle Integration
 * Description: Conecta con Moodle para crear usuarios y enrolarlos en cursos
 * Version: 1.0
 */

if (!defined('ABSPATH')) exit;

class MoodleIntegrationPro {

    private $moodle_url = 'https://campus.aulacolegial.com/webservice/rest/server.php';
    private $token = '0e495d92224b5c3a2230ffe0af494370';
    public $course_id;
    private $log_file;
    private $order_id;
    private $product_id;

    public function set_context($order_id, $product_id) {
        $this->order_id = $order_id;
        $this->product_id = $product_id;
    }

    public function __construct() {
        try {
            $this->log_file = plugin_dir_path(__FILE__) . 'moodle-integration.log';
            if (!file_exists($this->log_file)) {
                $this->escribir_log("=== MOODLE INTEGRATION LOG INICIADO ===");
            }
        } catch (Exception $e) {
            error_log("Error inicializando MoodleIntegrationPro: " . $e->getMessage());
        }
    }

    public function escribir_log($mensaje) {
        try {
            $timestamp = date('Y-m-d H:i:s');
            $linea_log = "[{$timestamp}] {$mensaje}" . PHP_EOL;
            file_put_contents($this->log_file, $linea_log, FILE_APPEND | LOCK_EX);
            error_log("MOODLE: " . $mensaje);
        } catch (Exception $e) {
            error_log("Error escribiendo log: " . $e->getMessage());
        }
    }

    private function moodle_api_call($function, $params = []) {
        try {
            $url = $this->moodle_url . '?wstoken=' . $this->token . '&wsfunction=' . $function . '&moodlewsrestformat=json';
            $post_data = http_build_query($params);
            $args = [
                'body' => $post_data,
                'timeout' => 30,
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']
            ];

            $this->escribir_log("Llamando a función Moodle: {$function}");

            $response = wp_remote_post($url, $args);

            if (is_wp_error($response)) {
                $error = $response->get_error_message();
                $this->escribir_log("❌ Error de conexión: $error");
                return ['error' => $error];
            }

            $body = wp_remote_retrieve_body($response);
            $this->escribir_log("Respuesta Moodle: $body");

            return json_decode($body, true);
        } catch (Exception $e) {
            $this->escribir_log("❌ Excepción en moodle_api_call: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    // Función para buscar usuario por email (sin permisos de core_user_get_users)
    private function find_user_by_email($email) {
        try {
            $this->escribir_log("🔍 Buscando usuario por email: {$email}");
            
            // Intentar crear el usuario - si ya existe, fallará con mensaje específico
            $temp_user = [
                'username' => 'temp_' . time(),
                'password' => 'TempPass123!',
                'firstname' => 'Temp',
                'lastname' => 'User',
                'email' => $email
            ];
            
            $params = ['users' => [$temp_user]];
            $result = $this->moodle_api_call('core_user_create_users', $params);
            
            // Si contiene error y menciona que el email ya existe
            if (isset($result['exception']) && strpos($result['message'], 'email') !== false) {
                $this->escribir_log("✅ Usuario encontrado - email ya existe en Moodle");
                return true;
            }
            
            // Si se creó exitosamente, eliminar el usuario temporal (no podemos, así que dejamos el log)
            if (isset($result[0]['id'])) {
                $this->escribir_log("ℹ️ Usuario temporal creado (será sobrescrito), ID: " . $result[0]['id']);
                return false;
            }
            
            return false;
        } catch (Exception $e) {
            $this->escribir_log("❌ Error en find_user_by_email: " . $e->getMessage());
            return false;
        }
    }

    public function create_moodle_user($user_data) {
        try {
            $this->escribir_log("Creando usuario en Moodle: {$user_data['email']}");
            
            // Parámetros mínimos para Moodle
            $moodle_user = [
                'username' => $user_data['username'],
                'password' => $user_data['password'],
                'firstname' => $user_data['firstname'],
                'lastname' => $user_data['lastname'],
                'email' => $user_data['email']
            ];

            // Añadir campos opcionales
            if (!empty($user_data['city'])) {
                $moodle_user['city'] = $user_data['city'];
            }
            if (!empty($user_data['country'])) {
                $moodle_user['country'] = $user_data['country'];
            }
            if (!empty($user_data['lang'])) {
                $moodle_user['lang'] = $user_data['lang'];
            }

            if (!empty($user_data['institution'])) {
                $moodle_user['institution'] = $user_data['institution'];
                $this->escribir_log("   🏫 Institución/Colegio: {$user_data['institution']}");
            }

            $this->escribir_log("Datos del usuario a enviar: " . print_r($moodle_user, true));
            
            $params = ['users' => [$moodle_user]];
            $result = $this->moodle_api_call('core_user_create_users', $params);

            if (isset($result[0]['id'])) {
                $this->escribir_log("✅ Usuario creado con ID: {$result[0]['id']}");
                return ['success' => true, 'user_id' => $result[0]['id']];
            }

            $this->escribir_log("❌ Error al crear usuario: " . json_encode($result));
            return ['error' => 'Error al crear usuario: ' . json_encode($result)];
        } catch (Exception $e) {
            $this->escribir_log("❌ Excepción en create_moodle_user: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    public function enroll_user_in_course($user_id, $course_id, $role_id = 5) {
        try {
            $this->escribir_log("Inscribiendo usuario ID {$user_id} en curso {$course_id}");
            $params = [
                'enrolments' => [[
                    'roleid' => $role_id,
                    'userid' => $user_id,
                    'courseid' => $course_id
                ]]
            ];
            $result = $this->moodle_api_call('enrol_manual_enrol_users', $params);

            if (empty($result)) {
                $this->escribir_log("✅ Usuario enrolado correctamente");
                return ['success' => true];
            }

            $this->escribir_log("❌ Error al enrolar usuario: " . json_encode($result));
            return ['error' => json_encode($result)];
        } catch (Exception $e) {
            $this->escribir_log("❌ Excepción en enroll_user_in_course: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    // Función para enrolar usuario existente por username (NIF en minúsculas)
    public function enroll_existing_user_by_username($username, $course_id) {
        try {
            $this->escribir_log("🔄 Intentando enrolar usuario existente por username: {$username}");

            // Buscar por username usando core_user_get_users_by_field
            $params = ['field' => 'username', 'values' => [$username]];
            $result = $this->moodle_api_call('core_user_get_users_by_field', $params);

            if (isset($result[0]['id'])) {
                $user_id = $result[0]['id'];
                $this->escribir_log("✅ Usuario encontrado con ID: {$user_id}");
                return $this->enroll_user_in_course($user_id, $course_id);
            }

            $this->escribir_log("❌ No se pudo encontrar usuario por username");
            return ['error' => 'Usuario no encontrado por username'];
        } catch (Exception $e) {
            $this->escribir_log("❌ Error en enroll_existing_user_by_username: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    // Función para limpiar NIF y usarlo como username
    private function sanitize_nif_username($nif) {
        try {
            $nif = strtolower(trim($nif));
            $nif = preg_replace('/[^a-z0-9]/', '', $nif);
            
            if (empty($nif)) {
                return 'user' . time();
            }
            
            if (strlen($nif) > 100) {
                $nif = substr($nif, 0, 100);
            }
            
            return $nif;
        } catch (Exception $e) {
            $this->escribir_log("❌ Error en sanitize_nif_username: " . $e->getMessage());
            return 'user' . time();
        }
    }

    // Función para crear username desde email
    private function sanitize_email_username($email) {
        try {
            $username = explode('@', $email)[0];
            $username = preg_replace('/[^a-z0-9]/', '', strtolower($username));
            
            if (empty($username)) {
                return 'user' . time();
            }
            
            if (strlen($username) > 100) {
                $username = substr($username, 0, 100);
            }
            
            return $username;
        } catch (Exception $e) {
            $this->escribir_log("❌ Error en sanitize_email_username: " . $e->getMessage());
            return 'user' . time();
        }
    }

// REEMPLAZAR esta función dentro de la clase MoodleIntegrationPro
    public function create_or_enroll_user($username, $email, $firstname, $lastname, $password = null, $nombre_curso = '', $colegio = '') {
        try {
            $this->escribir_log("🚀 Procesando usuario: {$email}");
            
            $password = wp_generate_password(12, true, true);

            
            // Validaciones básicas
            if (empty($username) || empty($email) || empty($firstname) || empty($lastname)) {
                $this->escribir_log("❌ Datos incompletos");
                return ['error' => 'Datos incompletos'];
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->escribir_log("❌ Email inválido: {$email}");
                return ['error' => "Email inválido: {$email}"];
            }
            
            // Obtener nombre del curso para ambos tipos de email
            $curso_nombre = $nombre_curso;
            
            // Primero intentar enrolar usuario existente por username (DNI/NIF en Moodle)
            $enroll_result = $this->enroll_existing_user_by_username($username, $this->course_id);
            
            if (isset($enroll_result['success'])) {
                $this->escribir_log("✅ Usuario existente enrolado correctamente");
                
                // 📧 ENVIAR EMAIL DE INSCRIPCIÓN PARA USUARIO EXISTENTE
                $this->escribir_log("📧 Enviando email de inscripción a usuario existente");
                
                $email_enviado = ep_enviar_email_inscripcion_curso(
                    $email,           // Email del empleado
                    $firstname,       // Nombre
                    $lastname,        // Apellidos
                    $curso_nombre     // Nombre del curso
                );
                
                if ($email_enviado) {
                    $this->escribir_log("✅ Email de inscripción enviado a usuario existente: {$email}");
                } else {
                    $this->escribir_log("❌ Error enviando email de inscripción a usuario existente: {$email}");
                }
                
                return $enroll_result;
            }
            
            // Si no existe, crear nuevo usuario
            $this->escribir_log("📝 Usuario no existe, creando nuevo con username: {$username}");
            
            $user_data = [
                'username' => $username,
                'password' => $password,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => $email,
                'city' => 'Barcelona',
                'country' => 'ES',
                'lang' => 'es'
            ];

            if (!empty($colegio)) {
                $user_data['institution'] = $colegio;
                $this->escribir_log("🏫 Añadiendo colegio: {$colegio}");
            }


            $user_result = $this->create_moodle_user($user_data);
            
            if (isset($user_result['error'])) {
                // Si falla por username duplicado, intentar enrolar (el usuario podría existir)
                if (strpos($user_result['error'], 'username') !== false) {
                    $this->escribir_log("⚠️ Username ya existe, intentando enrolar usuario existente");
                    $enroll_fallback = $this->enroll_existing_user_by_username($username, $this->course_id);
                    
                    // Si el enrol funciona, enviar email de inscripción
                    if (isset($enroll_fallback['success'])) {
                        $this->escribir_log("📧 Enviando email de inscripción (fallback username duplicado)");
                        
                        ep_enviar_email_inscripcion_curso(
                            $email,
                            $firstname,
                            $lastname,
                            $curso_nombre
                        );
                    }
                    
                    return $enroll_fallback;
                }
                return $user_result;
            }

            $moodle_user_id = $user_result['user_id'];
            $this->escribir_log("✅ Usuario creado con ID: {$moodle_user_id}");
            
            // Enrolar en curso
            $enroll_result = $this->enroll_user_in_course($moodle_user_id, $this->course_id);
            
            // 📧 ENVIAR EMAIL DE BIENVENIDA PARA USUARIO NUEVO
            if (isset($enroll_result['success'])) {
                $this->escribir_log("✅ Usuario enrolado correctamente, enviando email de bienvenida para usuario nuevo");
                
                // Enviar email de bienvenida con datos de acceso (usuario nuevo)
                $email_enviado = ep_enviar_email_acceso_campus(
                    $email,           // Email del empleado
                    $firstname,       // Nombre
                    $lastname,        // Apellidos  
                    $username,        // Username generado
                    $password,        // Password generado
                    $curso_nombre     // Nombre del curso
                );
                
                if ($email_enviado) {
                    $this->escribir_log("✅ Email de bienvenida enviado a usuario nuevo: {$email}");
                } else {
                    $this->escribir_log("❌ Error enviando email de bienvenida a usuario nuevo: {$email}");
                }
            } else {
                $this->escribir_log("❌ Error en inscripción de usuario nuevo, no se envía email");
            }
            
            // Retornar resultado de la inscripción
            return $enroll_result;
            
        } catch (Exception $e) {
            $this->escribir_log("❌ Excepción en create_or_enroll_user: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    public function debug_cambio_estado($order_id, $old_status, $new_status, $order) {
        try {
            $this->escribir_log("CAMBIO DE ESTADO: Pedido {$order_id} de '{$old_status}' a '{$new_status}'");
            
            // Solo procesar si está cambiando A 'completed' desde otro estado
            if ($new_status === 'completed' && $old_status !== 'completed') {
                $this->escribir_log("✅ Procesando cambio válido a completed");
                $this->ep_inscribir_empleados_en_moodle($order_id);
            } else {
                $this->escribir_log("⚠️ Cambio ignorado - no es un cambio válido a completed");
            }
        } catch (Exception $e) {
            $this->escribir_log("❌ Error crítico en debug_cambio_estado: " . $e->getMessage());
            error_log("MOODLE ERROR CRÍTICO: " . $e->getMessage());
        }
    }

    // Función principal mejorada
    public function ep_inscribir_empleados_en_moodle($order_id) {
        try {
            $this->escribir_log("🚀 Iniciando inscripción para el pedido #{$order_id}");
            
            $order = wc_get_order($order_id);
            if (!$order) {
                $this->escribir_log("❌ Pedido no encontrado");
                return;
            }

            $colegio_pedido = $order->get_meta('school_name');
            if (!empty($colegio_pedido)) {
                $this->escribir_log("🏫 Colegio del pedido: {$colegio_pedido}");
            }

            // Recopilar TODOS los empleados de TODOS los items
            $todos_empleados = [];
            $items = $order->get_items();
            
            $this->escribir_log("📦 Total items en el pedido: " . count($items));
            
            foreach ($items as $item_id => $item) {
                $this->escribir_log("🔍 === PROCESANDO ITEM #{$item_id} ===");
                
                $product_id = $item->get_product_id();
                $course_id = get_field('moodle_course_id', $product_id);
                
                $this->escribir_log("   📦 Producto ID: {$product_id}");
                $this->escribir_log("   🎯 Curso ID: " . ($course_id ?: 'NO DEFINIDO'));
                
                if (empty($course_id)) {
                    $this->escribir_log("⚠️ Item {$item_id} - Producto {$product_id} sin curso Moodle");
                    continue;
                }
                
                // Método 1: get_meta
                $empleados = $item->get_meta('curso_empleados');
                $this->escribir_log("   🔍 Método 1 (get_meta): " . (is_array($empleados) ? count($empleados) . ' empleados' : 'NO ARRAY'));
                
                if (!is_array($empleados) || empty($empleados)) {
                    // Método 2: wc_get_order_item_meta
                    $empleados = wc_get_order_item_meta($item_id, 'curso_empleados');
                    $this->escribir_log("   🔍 Método 2 (wc_get_order_item_meta): " . (is_array($empleados) ? count($empleados) . ' empleados' : 'NO ARRAY'));
                }
                
                // Debug todos los metadatos del item
                $all_meta = $item->get_meta_data();
                $this->escribir_log("   📋 Metadatos del item (" . count($all_meta) . "):");
                foreach ($all_meta as $meta) {
                    if ($meta->key === 'curso_empleados') {
                        $this->escribir_log("     ✅ curso_empleados: " . print_r($meta->value, true));
                    } else {
                        $this->escribir_log("     - {$meta->key}: " . (is_array($meta->value) ? 'ARRAY' : $meta->value));
                    }
                }
                
                if (is_array($empleados) && !empty($empleados)) {
                    $this->escribir_log("   👥 Empleados encontrados en item {$item_id}: " . count($empleados));
                    
                    foreach ($empleados as $index => $empleado) {
                        $this->escribir_log("     👤 Empleado #{$index}: " . print_r($empleado, true));
                        
                        $clave_unica = md5($empleado['email'] . '_' . $course_id);
                        
                        if (!isset($todos_empleados[$clave_unica])) {
                            $empleado['course_id'] = $course_id;
                            $empleado['item_id'] = $item_id;
                            $empleado['product_id'] = $product_id;
                            $empleado['nombre_curso'] = $item->get_name();
                            $empleado['colegio'] = $colegio_pedido;
                            $curso_name = $item->get_name();
                            $todos_empleados[$clave_unica] = $empleado;
                            $this->escribir_log("     Nombre del curso: {$curso_name}");
                            $this->escribir_log("     ✅ Empleado añadido con clave: {$clave_unica}");
                        } else {
                            $this->escribir_log("     ⚠️ Empleado ya existe con clave: {$clave_unica}");
                        }
                    }
                } else {
                    $this->escribir_log("   ❌ No hay empleados en item {$item_id}");
                }
                
                $this->escribir_log("🔍 === FIN ITEM #{$item_id} ===");
            }

            $this->escribir_log("📊 Total empleados únicos encontrados: " . count($todos_empleados));
            $this->escribir_log("🗝️ Claves de empleados: " . implode(', ', array_keys($todos_empleados)));

            // Procesar cada empleado único
            $procesados = 0;
            foreach ($todos_empleados as $clave => $empleado) {
                try {
                    $procesados++;
                    $this->escribir_log("🔄 Procesando empleado {$procesados}/" . count($todos_empleados));
                    
                    // Establecer course_id para este empleado
                    $this->course_id = $empleado['course_id'];
                    
                    // Extraer datos
                    $nombre = sanitize_text_field($empleado['nombre'] ?? '');
                    $apellidos = sanitize_text_field($empleado['apellidos'] ?? '');
                    $email = sanitize_email($empleado['email'] ?? '');
                    $nif = sanitize_text_field($empleado['nif'] ?? '');
                    $colegio = sanitize_text_field($empleado['colegio'] ?? '');
                    
                    // Validar datos
                    if (empty($nombre) || empty($apellidos) || empty($email)) {
                        $this->escribir_log("❌ Datos incompletos para empleado: {$email}");
                        continue;
                    }
                    
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $this->escribir_log("❌ Email inválido: {$email}");
                        continue;
                    }
                    
                    // Crear username usando NIF preferentemente
                    if (!empty($nif)) {
                        $username = $this->sanitize_nif_username($nif);
                    } else {
                        $username = $this->sanitize_email_username($email);
                    }
                    
                    $this->escribir_log("🧾 Procesando: {$nombre} {$apellidos} ({$email})");
                    $this->escribir_log("   📄 NIF: {$nif}");
                    $this->escribir_log("   👤 Username: {$username}");
                    $this->escribir_log("   🎯 Curso: {$this->course_id}");
                    if (!empty($colegio)) {
                        $this->escribir_log("   🏫 Colegio: {$colegio}");
                    }
                    
                    // Procesar empleado
                    $result = $this->create_or_enroll_user($username, $email, $nombre, $apellidos, null, $empleado['nombre_curso'], $colegio);
                    
                    if (isset($result['success'])) {
                        $this->escribir_log("✅ Empleado {$email} procesado correctamente");
                    } else {
                        $error_msg = isset($result['error']) ? $result['error'] : 'Error desconocido';
                        $this->escribir_log("❌ Error con {$email}: {$error_msg}");
                    }
                    
                } catch (Exception $e) {
                    $this->escribir_log("❌ Error procesando empleado: " . $e->getMessage());
                    continue;
                }
            }

            $this->escribir_log("📊 Resumen final: {$procesados} empleados procesados");
            $this->escribir_log("✅ Finalizado procesamiento del pedido #{$order_id}");
            
        } catch (Exception $e) {
            $this->escribir_log("❌ Error crítico en ep_inscribir_empleados_en_moodle: " . $e->getMessage());
            error_log("MOODLE ERROR CRÍTICO: " . $e->getMessage());
        }
    }
}

/**
 * Envía un correo de inscripción a curso para usuarios existentes
 */
function ep_enviar_email_inscripcion_curso($email, $nombre, $apellidos, $curso_nombre = '') {
    error_log("📧 Enviando email de inscripción a curso a: {$email}");
    
    $nombre_completo = trim($nombre . ' ' . $apellidos);
    $campus_url = 'https://campus.aulacolegial.com/';
    
    // Configurar el email como HTML
    add_filter('wp_mail_content_type', function() {
        return 'text/html';
    });
    
    $subject = '✅ Inscripción Confirmada - Nuevo Curso Disponible';
    
    $message = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Inscripción Confirmada</title>
    </head>
    <body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f5f5f5;">
        <table role="presentation" style="width: 100%; border-collapse: collapse;">
            <tr>
                <td align="center" style="padding: 40px 0;">
                    <table role="presentation" style="width: 600px; max-width: 90%; background-color: #ffffff; border-radius: 10px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); border-collapse: collapse;">
                        
                        <!-- Header -->
                        <tr>
                            <td style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); padding: 40px 30px; text-align: center; border-radius: 10px 10px 0 0;">
                                <h1 style="color: #ffffff; margin: 0; font-size: 28px; font-weight: bold;">
                                    ✅ Inscripción Confirmada
                                </h1>
                                <p style="color: #ffffff; margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;">
                                    Campus Virtual - Aula Colegial
                                </p>
                            </td>
                        </tr>
                        
                        <!-- Contenido Principal -->
                        <tr>
                            <td style="padding: 40px 30px;">
                                <h2 style="color: #333333; margin: 0 0 20px 0; font-size: 24px;">
                                    ¡Hola ' . esc_html($nombre_completo) . '! 👋
                                </h2>
                                
                                <p style="color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 16px;">
                                    Te confirmamos que tu inscripción ha sido <strong>procesada exitosamente</strong>. Ya tienes acceso a tu nuevo curso en el Campus Virtual.
                                </p>';
    
    if (!empty($curso_nombre)) {
        $message .= '
                                <div style="background-color: #d4edda; border-left: 4px solid #28a745; padding: 20px; margin: 25px 0; border-radius: 0 8px 8px 0;">
                                    <h3 style="margin: 0 0 10px 0; color: #155724; font-size: 20px;">
                                        📚 Nuevo Curso Disponible
                                    </h3>
                                    <p style="margin: 0; color: #155724; font-size: 18px; font-weight: bold;">
                                        ' . esc_html($curso_nombre) . '
                                    </p>
                                </div>';
    } else {
        $message .= '
                                <div style="background-color: #d4edda; border-left: 4px solid #28a745; padding: 20px; margin: 25px 0; border-radius: 0 8px 8px 0;">
                                    <h3 style="margin: 0; color: #155724; font-size: 18px;">
                                        📚 Tu nuevo curso ya está disponible en tu campus virtual
                                    </h3>
                                </div>';
    }
    
    $message .= '
                                <!-- Acceso al Campus -->
                                <div style="background-color: #17a2b8; color: white; padding: 25px; border-radius: 8px; margin: 30px 0;">
                                    <h3 style="margin: 0 0 15px 0; font-size: 20px; text-align: center;">
                                        🌐 Accede a tu Campus Virtual
                                    </h3>
                                    <p style="margin: 0 0 20px 0; text-align: center; font-size: 16px; opacity: 0.9;">
                                        Utiliza tus credenciales habituales para acceder
                                    </p>
                                    <table role="presentation" style="width: 100%; border-collapse: collapse;">
                                        <tr>
                                            <td style="text-align: center;">
                                                <a href="' . esc_url($campus_url) . '" style="color: #ffffff; text-decoration: underline; font-size: 16px;">
                                                    ' . esc_html($campus_url) . '
                                                </a>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                
                                <!-- Botón de Acceso -->
                                <div style="text-align: center; margin: 30px 0;">
                                    <a href="' . esc_url($campus_url) . '" style="display: inline-block; background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; text-decoration: none; padding: 15px 30px; border-radius: 8px; font-size: 18px; font-weight: bold; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
                                        🚀 Ir al Campus Virtual
                                    </a>
                                </div>
                                
                                <!-- Próximos pasos -->
                                <div style="background-color: #e2e3e5; border: 1px solid #d6d8db; border-radius: 8px; padding: 20px; margin: 30px 0;">
                                    <h4 style="color: #383d41; margin: 0 0 15px 0; font-size: 18px;">
                                        🎯 Próximos pasos:
                                    </h4>
                                    <ol style="color: #383d41; margin: 0; padding-left: 20px; line-height: 1.6;">
                                        <li>Accede al campus con tus credenciales habituales</li>
                                        <li>Localiza tu nuevo curso en el panel de control</li>
                                        <li>¡Comienza tu formación cuando quieras!</li>
                                    </ol>
                                </div>
                                
                                <!-- Información adicional -->
                                <div style="background-color: #cce5ff; border: 1px solid #99d3ff; border-radius: 8px; padding: 15px; margin: 20px 0;">
                                    <p style="color: #004085; margin: 0; font-size: 14px;">
                                        <strong>💡 Recordatorio:</strong> Si no recuerdas tus credenciales de acceso, puedes usar la opción "¿Olvidaste tu contraseña?" en la página de inicio de sesión.
                                    </p>
                                </div>
                                
                                <p style="color: #555555; line-height: 1.6; margin: 20px 0 0 0; font-size: 16px;">
                                    ¡Esperamos que disfrutes de tu nueva formación! Si tienes alguna pregunta, no dudes en contactarnos.
                                </p>
                            </td>
                        </tr>
                        
                        <!-- Footer -->
                        <tr>
                            <td style="background-color: #f8f9fa; padding: 25px 30px; border-radius: 0 0 10px 10px; border-top: 1px solid #dee2e6;">
                                <p style="text-align: center; color: #6c757d; margin: 0; font-size: 14px;">
                                    Campus Virtual - Aula Colegial<br>
                                    <a href="' . esc_url($campus_url) . '" style="color: #28a745;">' . esc_html($campus_url) . '</a>
                                </p>
                            </td>
                        </tr>
                        
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';
    
    // Headers del email
    $headers = array(
        'From: Campus Virtual <noreply@aulacolegial.com>',
        'Reply-To: soporte@aulacolegial.com'
    );
    
    // Enviar el email
    $enviado = wp_mail($email, $subject, $message, $headers);
    
    // Restaurar el content type
    remove_filter('wp_mail_content_type', function() {
        return 'text/html';
    });
    
    if ($enviado) {
        error_log("✅ Email de inscripción enviado correctamente a: {$email}");
    } else {
        error_log("❌ Error enviando email de inscripción a: {$email}");
    }
    
    return $enviado;
}

/**
 * Envía un correo estilizado con los datos de acceso al campus virtual (USUARIOS NUEVOS)
 */
function ep_enviar_email_acceso_campus($email, $nombre, $apellidos, $username, $password, $curso_nombre = '') {
    error_log("📧 Enviando email de acceso a: {$email}");
    
    $nombre_completo = trim($nombre . ' ' . $apellidos);
    $campus_url = 'https://campus.aulacolegial.com/';
    
    // Configurar el email como HTML
    add_filter('wp_mail_content_type', function() {
        return 'text/html';
    });
    
    $subject = '🎓 Bienvenido al Campus Virtual - Datos de Acceso';
    
    $message = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Bienvenido al Campus Virtual</title>
    </head>
    <body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f5f5f5;">
        <table role="presentation" style="width: 100%; border-collapse: collapse;">
            <tr>
                <td align="center" style="padding: 40px 0;">
                    <table role="presentation" style="width: 600px; max-width: 90%; background-color: #ffffff; border-radius: 10px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); border-collapse: collapse;">
                        
                        <!-- Header -->
                        <tr>
                            <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 40px 30px; text-align: center; border-radius: 10px 10px 0 0;">
                                <h1 style="color: #ffffff; margin: 0; font-size: 28px; font-weight: bold;">
                                    🎓 Campus Virtual
                                </h1>
                                <p style="color: #ffffff; margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;">
                                    Aula Colegial
                                </p>
                            </td>
                        </tr>
                        
                        <!-- Contenido Principal -->
                        <tr>
                            <td style="padding: 40px 30px;">
                                <h2 style="color: #333333; margin: 0 0 20px 0; font-size: 24px;">
                                    ¡Hola ' . esc_html($nombre_completo) . '! 👋
                                </h2>
                                
                                <p style="color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 16px;">
                                    Te damos la bienvenida al <strong>Campus Virtual de Aula Colegial</strong>. Tu cuenta ha sido creada exitosamente y ya puedes acceder a tu curso.
                                </p>';
    
    if (!empty($curso_nombre)) {
        $message .= '
                                <div style="background-color: #f8f9fa; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0; border-radius: 0 5px 5px 0;">
                                    <p style="margin: 0; color: #333333; font-size: 16px;">
                                        <strong>📚 Curso inscrito:</strong> ' . esc_html($curso_nombre) . '
                                    </p>
                                </div>';
    }
    
    $message .= '
                                <!-- Datos de Acceso -->
                                <div style="background-color: #667eea; color: white; padding: 25px; border-radius: 8px; margin: 30px 0;">
                                    <h3 style="margin: 0 0 20px 0; font-size: 20px; text-align: center;">
                                        🔑 Tus Datos de Acceso
                                    </h3>
                                    
                                    <table role="presentation" style="width: 100%; border-collapse: collapse;">
                                        <tr>
                                            <td style="padding: 10px 0; font-size: 16px;">
                                                <strong>🌐 Campus Virtual:</strong>
                                            </td>
                                            <td style="padding: 10px 0;">
                                                <a href="' . esc_url($campus_url) . '" style="color: #ffffff; text-decoration: underline;">
                                                    ' . esc_html($campus_url) . '
                                                </a>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 10px 0; font-size: 16px;">
                                                <strong>👤 Usuario:</strong>
                                            </td>
                                            <td style="padding: 10px 0; font-family: monospace; background-color: rgba(255,255,255,0.2); padding: 8px; border-radius: 4px;">
                                                ' . esc_html($username) . '
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 10px 0; font-size: 16px;">
                                                <strong>🔒 Contraseña:</strong>
                                            </td>
                                            <td style="padding: 10px 0; font-family: monospace; background-color: rgba(255,255,255,0.2); padding: 8px; border-radius: 4px;">
                                                ' . esc_html($password) . '
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                
                                <!-- Botón de Acceso -->
                                <div style="text-align: center; margin: 30px 0;">
                                    <a href="' . esc_url($campus_url) . '" style="display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; padding: 15px 30px; border-radius: 8px; font-size: 18px; font-weight: bold; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
                                        🚀 Acceder al Campus Virtual
                                    </a>
                                </div>
                                
                                <!-- Instrucciones -->
                                <div style="background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 20px; margin: 30px 0;">
                                    <h4 style="color: #856404; margin: 0 0 15px 0; font-size: 18px;">
                                        📝 Primeros pasos:
                                    </h4>
                                    <ol style="color: #856404; margin: 0; padding-left: 20px; line-height: 1.6;">
                                        <li>Haz clic en el botón "Acceder al Campus Virtual"</li>
                                        <li>Introduce tu usuario y contraseña</li>
                                        <li>¡Ya puedes comenzar con tu formación!</li>
                                    </ol>
                                </div>
                                
                                <!-- Recomendación de seguridad -->
                                <div style="background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 8px; padding: 15px; margin: 20px 0;">
                                    <p style="color: #721c24; margin: 0; font-size: 14px;">
                                        <strong>🔐 Recomendación de seguridad:</strong> Te recomendamos cambiar tu contraseña después del primer acceso desde tu perfil en el campus.
                                    </p>
                                </div>
                                
                                <p style="color: #555555; line-height: 1.6; margin: 20px 0 0 0; font-size: 16px;">
                                    Si tienes alguna duda o problema para acceder, no dudes en contactarnos.
                                </p>
                            </td>
                        </tr>
                        
                        <!-- Footer -->
                        <tr>
                            <td style="background-color: #f8f9fa; padding: 25px 30px; border-radius: 0 0 10px 10px; border-top: 1px solid #dee2e6;">
                                <p style="text-align: center; color: #6c757d; margin: 0; font-size: 14px;">
                                    Campus Virtual - Aula Colegial<br>
                                    <a href="' . esc_url($campus_url) . '" style="color: #667eea;">' . esc_html($campus_url) . '</a>
                                </p>
                            </td>
                        </tr>
                        
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';
    
    // Headers del email
    $headers = array(
        'From: Campus Virtual <noreply@aulacolegial.com>',
        'Reply-To: soporte@aulacolegial.com'
    );
    
    // Enviar el email
    $enviado = wp_mail($email, $subject, $message, $headers);
    
    // Restaurar el content type
    remove_filter('wp_mail_content_type', function() {
        return 'text/html';
    });
    
    if ($enviado) {
        error_log("✅ Email enviado correctamente a: {$email}");
    } else {
        error_log("❌ Error enviando email a: {$email}");
    }
    
    return $enviado;
}

/**
 * Función auxiliar para obtener el nombre del curso por ID
 */
function ep_obtener_nombre_curso_moodle($order_id, $product_id) {
    // Aquí puedes implementar la lógica para obtener el nombre del curso
    // desde tu base de datos o API de Moodle si es necesario

    $order = wc_get_order($order_id);
    if (!$order) return "Pedido no encontrado";

    foreach ($order->get_items() as $item) {
        if ($item->get_product_id() == $product_id) {
            return $item->get_name();
        }
    }
    return "Producto no encontrado";
    
    // Por ahora retorna un placeholder, puedes mejorarlo según tus necesidades
    //return "Curso #{$curso_id}";
}

function inicializar_moodle_integration() {
    try {
        $moodle = new MoodleIntegrationPro();
        add_action('woocommerce_order_status_changed', [$moodle, 'debug_cambio_estado'], 10, 4);
    } catch (Exception $e) {
        error_log("Error inicializando Moodle Integration: " . $e->getMessage());
    }
}
add_action('init', 'inicializar_moodle_integration');

class MoodleCourseSync_Fixed extends MoodleIntegrationPro {
    
    private $table_name;
    private $moodle_api_url;
    private $moodle_token;
    
    public function __construct() {
        parent::__construct();
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'moodle_courses';
        
        // CONFIGURACIÓN DIRECTA (reemplaza con tus valores reales)
        $this->moodle_api_url = 'https://campus.aulacolegial.com/webservice/rest/server.php';
        $this->moodle_token = '0e495d92224b5c3a2230ffe0af494370';
        
        // Verificar que las URLs y token están configurados
        if (empty($this->moodle_api_url) || empty($this->moodle_token)) {
            error_log("❌ MOODLE: URL o Token no configurados correctamente");
            return;
        }
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_sync_moodle_courses', array($this, 'ajax_sync_courses'));
        add_action('admin_init', array($this, 'maybe_create_table'));
    }
    
    /**
     * Método API propio con configuración fija
     */
    private function call_moodle_api($function, $params = array()) {
        try {
            $url = $this->moodle_api_url . '?wstoken=' . $this->moodle_token . '&wsfunction=' . $function . '&moodlewsrestformat=json';
            $post_data = http_build_query($params);
            $args = array(
                'body' => $post_data,
                'timeout' => 30,
                'headers' => array('Content-Type' => 'application/x-www-form-urlencoded')
            );

            $this->escribir_log("🔗 Llamando: {$function} a {$url}");

            $response = wp_remote_post($url, $args);

            if (is_wp_error($response)) {
                $error = $response->get_error_message();
                $this->escribir_log("❌ Error de conexión: $error");
                return array('error' => $error);
            }

            $body = wp_remote_retrieve_body($response);
            $http_code = wp_remote_retrieve_response_code($response);
            
            $this->escribir_log("📡 Código HTTP: {$http_code}");
            $this->escribir_log("📄 Respuesta: " . substr($body, 0, 200) . "...");

            $decoded = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->escribir_log("❌ Error JSON: " . json_last_error_msg());
                return array('error' => 'Respuesta JSON inválida: ' . json_last_error_msg());
            }

            return $decoded;
            
        } catch (Exception $e) {
            $this->escribir_log("❌ Excepción en API: " . $e->getMessage());
            return array('error' => $e->getMessage());
        }
    }
    
    public function maybe_create_table() {
        global $wpdb;
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") == $this->table_name;
        
        if (!$table_exists) {
            $this->create_courses_table();
        }
    }
    
    public function create_courses_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            moodle_id int(11) NOT NULL,
            shortname varchar(255) NOT NULL,
            fullname text NOT NULL,
            categoryid int(11) DEFAULT NULL,
            categoryname varchar(255) DEFAULT NULL,
            summary longtext DEFAULT NULL,
            startdate bigint(20) DEFAULT NULL,
            enddate bigint(20) DEFAULT NULL,
            visible tinyint(1) DEFAULT 1,
            last_sync datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY moodle_id (moodle_id),
            KEY shortname (shortname)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        $this->escribir_log("✅ Tabla creada: {$this->table_name}");
    }
    
    public function test_moodle_connection() {
        try {
            $this->escribir_log("🧪 Probando conexión con Moodle...");
            
            $result = $this->call_moodle_api('core_webservice_get_site_info');
            
            if (isset($result['error'])) {
                $this->escribir_log("❌ Error de conexión: " . $result['error']);
                return array(
                    'success' => false,
                    'error' => $result['error']
                );
            }
            
            if (isset($result['exception'])) {
                $error = isset($result['message']) ? $result['message'] : 'Error desconocido de Moodle';
                $this->escribir_log("❌ Excepción Moodle: " . $error);
                return array(
                    'success' => false,
                    'error' => 'Error Moodle: ' . $error
                );
            }
            
            $this->escribir_log("✅ Conexión exitosa con Moodle");
            
            return array(
                'success' => true,
                'sitename' => isset($result['sitename']) ? $result['sitename'] : 'N/A',
                'username' => isset($result['username']) ? $result['username'] : 'N/A',
                'version' => isset($result['version']) ? $result['version'] : 'N/A'
            );
            
        } catch (Exception $e) {
            $this->escribir_log("❌ Excepción: " . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    public function get_moodle_courses() {
        try {
            $this->escribir_log("📚 Obteniendo lista de cursos...");
            
            $result = $this->call_moodle_api('core_course_get_courses');
            
            if (isset($result['error'])) {
                return array('error' => $result['error']);
            }
            
            if (isset($result['exception'])) {
                $error = isset($result['message']) ? $result['message'] : 'Error obteniendo cursos';
                return array('error' => $error);
            }
            
            if (!is_array($result)) {
                return array('error' => 'Respuesta inválida al obtener cursos');
            }
            
            $this->escribir_log("✅ Obtenidos " . count($result) . " cursos");
            return $result;
            
        } catch (Exception $e) {
            return array('error' => 'Excepción: ' . $e->getMessage());
        }
    }
    
    public function sync_courses_to_db() {
        global $wpdb;
        
        try {
            $this->escribir_log("🚀 Iniciando sincronización completa...");
            
            // Primero probar conexión
            $connection_test = $this->test_moodle_connection();
            if (!$connection_test['success']) {
                return array('error' => 'Conexión fallida: ' . $connection_test['error']);
            }
            
            $courses = $this->get_moodle_courses();
            
            if (isset($courses['error'])) {
                return array('error' => $courses['error']);
            }
            
            if (!is_array($courses) || empty($courses)) {
                return array('error' => 'No se obtuvieron cursos válidos');
            }
            
            $inserted = 0;
            $updated = 0;
            $errors = 0;
            $processed = 0;
            
            foreach ($courses as $course) {
                // Saltar curso site
                if (!isset($course['id']) || $course['id'] == 1) {
                    continue;
                }
                
                $processed++;
                
                try {
                    $course_data = array(
                        'moodle_id' => intval($course['id']),
                        'shortname' => sanitize_text_field($course['shortname'] ?? ''),
                        'fullname' => sanitize_text_field($course['fullname'] ?? ''),
                        'categoryid' => intval($course['categoryid'] ?? 0),
                        'categoryname' => null, // Lo añadiremos después si es necesario
                        'summary' => isset($course['summary']) ? wp_kses_post($course['summary']) : '',
                        'startdate' => isset($course['startdate']) ? intval($course['startdate']) : null,
                        'enddate' => isset($course['enddate']) ? intval($course['enddate']) : null,
                        'visible' => isset($course['visible']) ? intval($course['visible']) : 1,
                        'last_sync' => current_time('mysql')
                    );
                    
                    // Verificar si existe
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$this->table_name} WHERE moodle_id = %d",
                        $course['id']
                    ));
                    
                    if ($existing) {
                        // Actualizar
                        $result = $wpdb->update(
                            $this->table_name,
                            $course_data,
                            array('moodle_id' => $course['id'])
                        );
                        
                        if ($result !== false) {
                            $updated++;
                        } else {
                            $errors++;
                            $this->escribir_log("❌ Error actualizando curso {$course['id']}: " . $wpdb->last_error);
                        }
                    } else {
                        // Insertar
                        $result = $wpdb->insert($this->table_name, $course_data);
                        
                        if ($result !== false) {
                            $inserted++;
                        } else {
                            $errors++;
                            $this->escribir_log("❌ Error insertando curso {$course['id']}: " . $wpdb->last_error);
                        }
                    }
                    
                } catch (Exception $e) {
                    $errors++;
                    $this->escribir_log("❌ Error procesando curso: " . $e->getMessage());
                }
            }
            
            $this->escribir_log("📊 Sincronización finalizada: {$inserted} nuevos, {$updated} actualizados, {$errors} errores de {$processed} procesados");
            
            return array(
                'success' => true,
                'inserted' => $inserted,
                'updated' => $updated,
                'errors' => $errors,
                'total' => $processed,
                'connection' => $connection_test
            );
            
        } catch (Exception $e) {
            $this->escribir_log("❌ Error crítico en sincronización: " . $e->getMessage());
            return array('error' => 'Error crítico: ' . $e->getMessage());
        }
    }
    
    public function get_local_courses($search = '', $visible_only = true) {
        global $wpdb;
        
        $where_conditions = array('1=1');
        $params = array();
        
        if ($visible_only) {
            $where_conditions[] = 'visible = 1';
        }
        
        if (!empty($search)) {
            $where_conditions[] = '(fullname LIKE %s OR shortname LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $params = array($search_term, $search_term);
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        $query = "SELECT * FROM {$this->table_name} WHERE {$where_clause} ORDER BY fullname ASC";
        
        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }
        
        return $wpdb->get_results($query);
    }
    
    public function get_course_by_moodle_id($moodle_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE moodle_id = %d",
            $moodle_id
        ));
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Cursos Moodle',
            'Cursos Moodle',
            'manage_woocommerce',
            'moodle-courses',
            array($this, 'admin_page')
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'moodle-courses') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        
        $nonce = wp_create_nonce('moodle_courses_nonce');
        
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                $('#sync-courses-btn').on('click', function() {
                    var btn = $(this);
                    var status = $('#sync-status');
                    var results = $('#sync-results');
                    
                    btn.prop('disabled', true).text('🔄 Sincronizando...');
                    status.html('<span style=\"color: #0073aa;\">⏳ Conectando...</span>');
                    results.hide();
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'sync_moodle_courses',
                            nonce: '{$nonce}'
                        },
                        timeout: 60000,
                        success: function(response) {
                            console.log('Respuesta:', response);
                            
                            if (response.success) {
                                status.html('<span style=\"color: green;\">✅ Completado</span>');
                                results.html(
                                    '<div class=\"notice notice-success inline\">' +
                                    '<p><strong>📊 Resultados:</strong></p>' +
                                    '<ul>' +
                                    '<li>📥 Nuevos: ' + response.data.inserted + '</li>' +
                                    '<li>🔄 Actualizados: ' + response.data.updated + '</li>' +
                                    '<li>❌ Errores: ' + response.data.errors + '</li>' +
                                    '<li>📚 Total: ' + response.data.total + '</li>' +
                                    '</ul></div>'
                                ).show();
                                
                                setTimeout(function() { window.location.reload(); }, 3000);
                            } else {
                                var errorMsg = response.data || 'Error desconocido';
                                status.html('<span style=\"color: red;\">❌ Error</span>');
                                results.html(
                                    '<div class=\"notice notice-error inline\">' +
                                    '<p><strong>❌ Error:</strong> ' + errorMsg + '</p>' +
                                    '</div>'
                                ).show();
                            }
                        },
                        error: function(xhr, textStatus, errorThrown) {
                            console.error('Error AJAX:', xhr, textStatus, errorThrown);
                            
                            var errorMessage = 'Error desconocido';
                            if (xhr.status === 0) {
                                errorMessage = 'Sin conexión con servidor';
                            } else if (xhr.status === 403) {
                                errorMessage = 'Acceso denegado';
                            } else if (xhr.status === 500) {
                                errorMessage = 'Error interno del servidor';
                            } else if (textStatus === 'timeout') {
                                errorMessage = 'Tiempo de espera agotado';
                            }
                            
                            status.html('<span style=\"color: red;\">❌ ' + errorMessage + '</span>');
                            results.html(
                                '<div class=\"notice notice-error inline\">' +
                                '<p><strong>Error HTTP:</strong> ' + xhr.status + ' - ' + textStatus + '</p>' +
                                '<p><strong>Detalles:</strong> ' + (xhr.responseText || errorThrown) + '</p>' +
                                '</div>'
                            ).show();
                        },
                        complete: function() {
                            btn.prop('disabled', false).text('🔄 Sincronizar Cursos');
                        }
                    });
                });
            });
        ");
    }
    
    public function admin_page() {
        // Test de conexión
        $connection_test = $this->test_moodle_connection();
        $courses = $this->get_local_courses('', false);
        $total_courses = count($courses);
        
        ?>
        <div class="wrap">
            <h1>🎓 Gestión de Cursos Moodle</h1>
            
            <!-- DEBUG INFO -->
            <div class="card">
                <h2>🐛 Información de Debug</h2>
                <p><strong>URL API:</strong> <code><?php echo esc_html($this->moodle_api_url); ?></code></p>
                <p><strong>Token:</strong> <code><?php echo esc_html(substr($this->moodle_token, 0, 10)) . '...'; ?></code></p>
                <p><strong>Tabla BD:</strong> <code><?php echo esc_html($this->table_name); ?></code></p>
                <p><strong>Debug completo:</strong> <a href="<?php echo admin_url('admin.php?debug_moodle=1'); ?>" target="_blank">Ver debug detallado</a></p>
            </div>
            
            <!-- ESTADO DE CONEXIÓN -->
            <div class="card" style="margin-top: 20px;">
                <h2>🔗 Estado de Conexión</h2>
                <?php if ($connection_test['success']): ?>
                    <div class="notice notice-success inline">
                        <p>✅ <strong>Conexión exitosa con Moodle</strong></p>
                        <ul>
                            <li><strong>Sitio:</strong> <?php echo esc_html($connection_test['sitename']); ?></li>
                            <li><strong>Usuario:</strong> <?php echo esc_html($connection_test['username']); ?></li>
                            <li><strong>Versión:</strong> <?php echo esc_html($connection_test['version'] ?? 'N/A'); ?></li>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="notice notice-error inline">
                        <p>❌ <strong>Error de conexión:</strong></p>
                        <p><?php echo esc_html($connection_test['error']); ?></p>
                        
                        <h4>🔧 Posibles soluciones:</h4>
                        <ul>
                            <li>Verificar que la URL sea correcta: <code><?php echo esc_html($this->moodle_api_url); ?></code></li>
                            <li>Verificar que el token sea válido</li>
                            <li>Asegurarse de que los servicios web estén habilitados en Moodle</li>
                            <li>Verificar que el usuario del token tenga permisos suficientes</li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- SINCRONIZACIÓN -->
            <div class="card" style="margin-top: 20px;">
                <h2>🔄 Sincronización de Cursos</h2>
                <?php if ($connection_test['success']): ?>
                    <p>Sincroniza la lista de cursos desde Moodle hacia WordPress.</p>
                    <div style="margin: 20px 0;">
                        <button type="button" id="sync-courses-btn" class="button button-primary button-large">
                            🔄 Sincronizar Cursos desde Moodle
                        </button>
                        <span id="sync-status" style="margin-left: 15px;"></span>
                    </div>
                    <div id="sync-results" style="display: none; margin-top: 15px;"></div>
                <?php else: ?>
                    <div class="notice notice-warning inline">
                        <p>⚠️ <strong>Conexión requerida:</strong> Corrige los problemas de conexión antes de sincronizar.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- CURSOS LOCALES -->
            <div class="card" style="margin-top: 20px;">
                <h2>📚 Cursos en Base de Datos (<?php echo $total_courses; ?>)</h2>
                
                <?php if (empty($courses)): ?>
                    <div class="notice notice-info inline">
                        <p>ℹ️ <strong>Sin cursos:</strong> No hay cursos sincronizados en la base de datos local. Ejecuta la sincronización primero.</p>
                    </div>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                        <thead>
                            <tr>
                                <th style="width: 80px;">ID Moodle</th>
                                <th>Nombre del Curso</th>
                                <th>Nombre Corto</th>
                                <th style="width: 80px;">Visible</th>
                                <th style="width: 120px;">Última Sync</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($courses, 0, 15) as $course): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($course->moodle_id); ?></strong></td>
                                    <td>
                                        <strong><?php echo esc_html($course->fullname); ?></strong>
                                        <?php if (!empty($course->summary)): ?>
                                            <br><small style="color: #666;"><?php echo esc_html(wp_trim_words(strip_tags($course->summary), 10)); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?php echo esc_html($course->shortname); ?></code></td>
                                    <td><?php echo $course->visible ? '✅ Sí' : '❌ No'; ?></td>
                                    <td><small><?php echo date('d/m H:i', strtotime($course->last_sync)); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (count($courses) > 15): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; font-style: italic; color: #666;">
                                        ... y <?php echo count($courses) - 15; ?> cursos más
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        .card { background: #fff; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card h2 { margin-top: 0; }
        .notice.inline { padding: 15px; margin: 15px 0; }
        </style>
        <?php
    }
    
    public function ajax_sync_courses() {
        try {
            // Verificar nonce
            if (!check_ajax_referer('moodle_courses_nonce', 'nonce', false)) {
                wp_send_json_error('Token de seguridad inválido');
                return;
            }
            
            // Verificar permisos
            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error('Permisos insuficientes');
                return;
            }
            
            $this->escribir_log("🚀 AJAX: Iniciando sincronización desde admin");
            
            // Ejecutar sincronización
            $result = $this->sync_courses_to_db();
            
            $this->escribir_log("📊 AJAX: Resultado: " . print_r($result, true));
            
            if (isset($result['error'])) {
                wp_send_json_error($result['error']);
            } else {
                wp_send_json_success($result);
            }
            
        } catch (Exception $e) {
            $this->escribir_log("❌ AJAX: Excepción crítica: " . $e->getMessage());
            wp_send_json_error('Error crítico: ' . $e->getMessage());
        }
    }
}

// INICIALIZACIÓN
function init_moodle_course_sync_debug() {
    try {
        if (!class_exists('MoodleIntegrationPro')) {
            error_log("❌ Clase MoodleIntegrationPro no encontrada");
            return;
        }
        
        new MoodleCourseSync_Fixed();
        error_log("✅ MoodleCourseSync_Fixed inicializado");
        
    } catch (Exception $e) {
        error_log("❌ Error inicializando MoodleCourseSync_Fixed: " . $e->getMessage());
    }
}

add_action('init', 'init_moodle_course_sync_debug', 25);

class WooCommerceMoodleCourseSelector {
    
    private $course_sync;
    
    public function __construct() {
        // Solo inicializar si ACF está disponible
        if (!function_exists('get_field')) {
            add_action('admin_notices', array($this, 'acf_missing_notice'));
            return;
        }
        
        $this->course_sync = new MoodleCourseSync_Fixed();
        
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_course_selector'));
        add_action('woocommerce_process_product_meta', array($this, 'save_course_selection'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_product_scripts'));
    }
    
    /**
     * Aviso si ACF no está disponible
     */
    public function acf_missing_notice() {
        if (get_current_screen()->id === 'product') {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>⚠️ ACF requerido:</strong> Para asignar cursos Moodle a productos, instala Advanced Custom Fields.';
            echo '</p></div>';
        }
    }
    
    /**
     * Añadir selector de curso en productos
     */
    public function add_course_selector() {
        global $post;
        
        if (!$post || $post->post_type !== 'product') {
            return;
        }
        
        $courses = $this->course_sync->get_local_courses();
        $current_course_id = get_field('moodle_course_id', $post->ID);
        
        echo '<div class="options_group">';
        echo '<h2 style="margin: 20px 0 10px 0; padding: 0; font-size: 18px; color: #333;">🎓 Configuración Moodle</h2>';
        
        if (empty($courses)) {
            echo '<div class="form-field" style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; padding: 15px; margin: 10px 0;">';
            echo '<p style="margin: 0;"><strong>⚠️ No hay cursos disponibles.</strong></p>';
            echo '<p style="margin: 5px 0 0 0;">';
            echo '<a href="' . admin_url('admin.php?page=moodle-courses') . '" class="button button-secondary">🔄 Sincronizar Cursos desde Moodle</a>';
            echo '</p>';
            echo '</div>';
        } else {
            // Selector de curso
            echo '<p class="form-field moodle_course_selector_field" style="margin: 15px 0;">';
            echo '<label for="moodle_course_selector" style="font-weight: bold; display: block; margin-bottom: 5px;">Curso Moodle:</label>';
            echo '<select id="moodle_course_selector" name="moodle_course_selector" style="width: 100%; max-width: 500px;">';
            echo '<option value="">-- Seleccionar curso Moodle --</option>';
            
            // Agrupar cursos por categoría
            $courses_by_category = array();
            foreach ($courses as $course) {
                $category = $course->categoryname ?: 'Sin categoría';
                if (!isset($courses_by_category[$category])) {
                    $courses_by_category[$category] = array();
                }
                $courses_by_category[$category][] = $course;
            }
            
            // Mostrar cursos agrupados
            ksort($courses_by_category);
            foreach ($courses_by_category as $category_name => $category_courses) {
                echo '<optgroup label="' . esc_attr($category_name) . '">';
                
                foreach ($category_courses as $course) {
                    $selected = ($current_course_id == $course->moodle_id) ? 'selected="selected"' : '';
                    echo '<option value="' . esc_attr($course->moodle_id) . '" ' . $selected . '>';
                    echo esc_html($course->fullname);
                    if (!empty($course->shortname)) {
                        echo ' (' . esc_html($course->shortname) . ')';
                    }
                    echo '</option>';
                }
                
                echo '</optgroup>';
            }
            
            echo '</select>';
            echo '<span class="description" style="display: block; margin-top: 5px; color: #666; font-style: italic;">';
            echo 'Selecciona el curso de Moodle que se asignará cuando se compre este producto. El campo ACF se actualiza automáticamente.';
            echo '</span>';
            echo '</p>';
            
            // Mostrar ID actual (solo lectura)
            echo '<p class="form-field" style="margin: 15px 0;">';
            echo '<label for="current_moodle_id" style="font-weight: bold; display: block; margin-bottom: 5px;">ID de Curso Actual:</label>';
            echo '<input type="text" id="current_moodle_id" value="' . esc_attr($current_course_id) . '" readonly ';
            echo 'style="background: #f9f9f9; border: 1px solid #ddd; padding: 8px; border-radius: 4px; width: 100px;" />';
            
            if ($current_course_id) {
                $assigned_course = $this->course_sync->get_course_by_moodle_id($current_course_id);
                if ($assigned_course) {
                    echo '<span style="margin-left: 10px; color: #0073aa; font-weight: bold;">';
                    echo '✅ ' . esc_html($assigned_course->fullname);
                    echo '</span>';
                } else {
                    echo '<span style="margin-left: 10px; color: #d63384; font-weight: bold;">';
                    echo '❌ Curso no encontrado (ID: ' . $current_course_id . ')';
                    echo '</span>';
                }
            }
            
            echo '<span class="description" style="display: block; margin-top: 5px; color: #666; font-style: italic;">';
            echo 'Este campo se actualiza automáticamente cuando seleccionas un curso arriba.';
            echo '</span>';
            echo '</p>';
            
            // Información adicional
            echo '<div style="background: #e3f2fd; border: 1px solid #90caf9; border-radius: 4px; padding: 15px; margin: 15px 0;">';
            echo '<h4 style="margin: 0 0 10px 0; color: #1976d2;">💡 ¿Cómo funciona?</h4>';
            echo '<ul style="margin: 0; color: #555;">';
            echo '<li>Cuando un cliente compre este producto, será <strong>automáticamente inscrito</strong> en el curso seleccionado.</li>';
            echo '<li>Si el usuario ya existe en Moodle, solo se inscribe. Si no existe, se <strong>crea la cuenta</strong> automáticamente.</li>';
            echo '<li>El cliente recibe un <strong>email</strong> con los datos de acceso al campus virtual.</li>';
            echo '</ul>';
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * Guardar selección de curso
     */
    public function save_course_selection($product_id) {
        if (isset($_POST['moodle_course_selector'])) {
            $course_id = intval($_POST['moodle_course_selector']);
            
            if ($course_id > 0) {
                // Actualizar campo ACF
                update_field('moodle_course_id', $course_id, $product_id);
                
                // Log para seguimiento
                error_log("✅ MOODLE: Curso {$course_id} asignado al producto {$product_id}");
                
                // Mostrar mensaje de confirmación
                add_action('admin_notices', function() use ($course_id, $product_id) {
                    echo '<div class="notice notice-success is-dismissible">';
                    echo '<p><strong>✅ Curso Moodle asignado:</strong> ID ' . $course_id . ' al producto #' . $product_id . '</p>';
                    echo '</div>';
                });
                
            } else {
                // Eliminar asignación si se selecciona vacío
                delete_field('moodle_course_id', $product_id);
                error_log("❌ MOODLE: Curso removido del producto {$product_id}");
            }
        }
    }
    
    /**
     * Scripts para mejorar la experiencia de usuario
     */
    public function enqueue_product_scripts($hook) {
        global $post;
        
        // Solo en páginas de edición de productos
        if ($hook === 'post.php' && $post && $post->post_type === 'product') {
            // Cargar Select2 si está disponible
            wp_enqueue_script('select2');
            wp_enqueue_style('select2');
            
            // Script personalizado - CORREGIDO para ACF
            wp_add_inline_script('select2', '
                jQuery(document).ready(function($) {
                    // Mejorar el selector con Select2
                    if ($.fn.select2) {
                        $("#moodle_course_selector").select2({
                            placeholder: "🔍 Buscar curso...",
                            allowClear: true,
                            width: "100%"
                        });
                    }
                    
                    // Encontrar el campo ACF dinámicamente
                    function findACFField() {
                        var acfField = null;
                        
                        // Buscar por data-name="moodle_course_id"
                        var acfContainer = $("[data-name=\"moodle_course_id\"]");
                        if (acfContainer.length) {
                            acfField = acfContainer.find("input[type=\"text\"]");
                        }
                        
                        // Buscar por ID que contenga "moodle_course_id" 
                        if (!acfField || !acfField.length) {
                            acfField = $("input[id*=\"moodle_course_id\"]");
                        }
                        
                        // Buscar por name que contenga "moodle_course_id"
                        if (!acfField || !acfField.length) {
                            acfField = $("input[name*=\"moodle_course_id\"]");
                        }
                        
                        // Debug: mostrar lo que encontramos
                        if (acfField && acfField.length) {
                            console.log("✅ Campo ACF encontrado:", acfField.attr("id"), acfField.attr("name"));
                        } else {
                            console.log("❌ Campo ACF NO encontrado. Campos disponibles:");
                            $("input[type=\"text\"]").each(function() {
                                var id = $(this).attr("id") || "sin-id";
                                var name = $(this).attr("name") || "sin-name";
                                if (id.includes("acf") || name.includes("acf")) {
                                    console.log("- ID:", id, "Name:", name);
                                }
                            });
                        }
                        
                        return acfField;
                    }
                    
                    // Actualizar campo ACF cuando cambie la selección
                    $("#moodle_course_selector").on("change", function() {
                        var selectedId = $(this).val();
                        var selectedText = $(this).find("option:selected").text();
                        
                        console.log("🔄 Curso seleccionado:", selectedId, selectedText);
                        
                        // Actualizar nuestro campo de visualización
                        $("#current_moodle_id").val(selectedId);
                        
                        // Buscar y actualizar campo ACF
                        var acfField = findACFField();
                        if (acfField && acfField.length) {
                            acfField.val(selectedId);
                            acfField.trigger("change"); // Disparar evento para ACF
                            
                            console.log("✅ Campo ACF actualizado:", acfField.attr("id"));
                            
                            // Efecto visual en campo ACF
                            acfField.css({
                                "background": "#d4edda",
                                "border-color": "#28a745"
                            });
                            
                            setTimeout(function() {
                                acfField.css({
                                    "background": "",
                                    "border-color": ""
                                });
                            }, 2000);
                        } else {
                            console.log("❌ No se pudo actualizar el campo ACF");
                        }
                        
                        // Cambiar color de nuestro campo de visualización
                        if (selectedId) {
                            $("#current_moodle_id").css("background", "#d4edda");
                            
                            // Mostrar confirmación temporal
                            $("#temp-confirmation").remove();
                            $("#current_moodle_id").after("<span id=\"temp-confirmation\" style=\"margin-left: 10px; color: #28a745; font-weight: bold;\">✓ ID " + selectedId + " asignado</span>");
                            setTimeout(function() {
                                $("#temp-confirmation").fadeOut(function() {
                                    $(this).remove();
                                });
                            }, 3000);
                        } else {
                            $("#current_moodle_id").css("background", "#f9f9f9");
                        }
                    });
                    
                    // Efecto visual al cargar si ya hay curso asignado
                    var currentId = $("#current_moodle_id").val();
                    if (currentId) {
                        $("#current_moodle_id").css("background", "#d4edda");
                    }
                    
                    // Sincronizar cuando se carga la página
                    setTimeout(function() {
                        var acfField = findACFField();
                        if (acfField && acfField.length) {
                            var acfValue = acfField.val();
                            var selectorValue = $("#moodle_course_selector").val();
                            
                            if (acfValue && acfValue !== selectorValue) {
                                $("#moodle_course_selector").val(acfValue).trigger("change");
                                console.log("🔄 Sincronizado selector con ACF:", acfValue);
                            }
                        }
                    }, 500);
                });
            ');
        }
    }
}

// Inicializar selector de cursos en productos
function init_woocommerce_moodle_selector() {
    if (class_exists('WooCommerce') && class_exists('MoodleCourseSync_Fixed')) {
        new WooCommerceMoodleCourseSelector();
    }
}
add_action('init', 'init_woocommerce_moodle_selector', 30);
