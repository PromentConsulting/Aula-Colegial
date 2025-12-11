<?php
/**
 * Plantilla personalizada para "Mis Empleados"
 */

if (!defined('ABSPATH')) {
    exit; // Seguridad
}

// Incluir el header del tema
get_header();
function tpl_validar_nif_nie($id) {
    $id = strtoupper(preg_replace('/[\s-]+/', '', (string)$id));
    if ($id === '') return false;

    $letras = 'TRWAGMYFPDXBNJZSQVHLCKE';

    // DNI: 8 dígitos + letra
    if (preg_match('/^\d{8}[A-Z]$/', $id)) {
        $num   = (int) substr($id, 0, 8);
        $letra = substr($id, -1);
        return $letras[$num % 23] === $letra;
    }

    // NIE: X/Y/Z + 7 dígitos + letra
    if (preg_match('/^[XYZ]\d{7}[A-Z]$/', $id)) {
        $map   = ['X'=>'0','Y'=>'1','Z'=>'2'];
        $num   = (int) ($map[$id[0]] . substr($id, 1, 7));
        $letra = substr($id, -1);
        return $letras[$num % 23] === $letra;
    }

    return false;
}
?>
<style>
    .empleados-container {
        max-width: 80%;
        margin: 20px auto;
        padding: 20px;
        background: #fff;
    }

    h1 {
        text-align: left;
        color: #333;
    }

    /* Estilos de la tabla */
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        background: #fafafa;
        border-radius: 8px;
        overflow: hidden;
    }

    th, td {
        padding: 12px;
        text-align: left;
    }

    th {
        background: #0073aa;
        color: #fff;
    }

    tr {
        border-bottom: 1px solid #ddd;
    }

    tr:last-child {
        border-bottom: none;
    }
    table td {
        border: 0 !important;
    }

    tr:hover {
        background: #f1f1f1;
    }

    /* Botones */
    .btn {
        display: inline-block;
        padding: 8px 14px;
        font-size: 14px;
        text-decoration: none;
        border-radius: 4px;
        transition: all 0.3s ease;
        cursor: pointer;
        border: none;
    }

    .btn-primary {
        background: #ffffff;
    color: #3dc53d;
    border: 1px solid #3dc53d;
    }

    .btn-primary:hover {
        background: #3dc53d;
    }

    .btn-danger {
        background: transparent;
        color: #d9534f;
        border: none;
        font-size: 18px;
    }
    .btn-danger:hover {
        color: #a80000;
        background: transparent;
    }


    .btn-add {
        display: block;
        width: fit-content;
        margin: 20px auto;
        padding: 10px 20px;
        font-size: 16px;
        font-weight: bold;
        background: #ffffff;
    color: #be3a34;
    border-radius: 5px;
    float: right;
    border: 1px solid #be3a34;
    }
    .table tbody>tr:nth-child(odd)>th {
        background-color: #ffffff;
    }

    .btn-add:hover {
        background: #218838;
    }

    /* Ventana emergente (modal) */
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
        width: 50%;
        box-shadow: 0px 4px 8px rgba(0, 0, 0, 0.2);
        text-align: center;
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

    form {
        text-align: left;
    }

    label {
        font-weight: bold;
        display: block;
        margin-top: 10px;
    }

    input {
        width: 100%;
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
    .encabezado, td {
        background-color: #ffffff !important; 
        border: none; 
        color: #54595F;
    }
</style>



<div class="empleados-container">
    <h1>Mis Empleados</h1>

     <!-- Botón para abrir el formulario de añadir empleado -->
     <button class="btn-add" onclick="openModal('modalAgregar')">+ Añadir nuevo empleado</button>

    <?php
    if (!is_user_logged_in()) {
        echo '<p>Debes iniciar sesión para acceder a esta página.</p>';
    } else {
        global $wpdb;
        $user_id = get_current_user_id();
        $tabla_empleados = $wpdb->prefix . "empleados";

        // Eliminar empleado
        if (isset($_GET['eliminar_empleado'])) {
            $id_empleado = intval($_GET['eliminar_empleado']);
        
            // Obtener nombre y apellidos antes de eliminar
            $empleado = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT nombre, apellidos FROM $tabla_empleados WHERE id = %d AND user_id = %d",
                    $id_empleado,
                    $user_id
                )
            );
        
            if ($empleado) {
                $wpdb->delete($tabla_empleados, ['id' => $id_empleado, 'user_id' => $user_id]);
        
                echo '<p style="color: #ffffff; background: red;padding: 1em;display: block;width: 50%;margin: auto;text-align: center;border-radius: 10px;">El usuario <b>' . esc_html($empleado->nombre . ' ' . $empleado->apellidos) . '</b> se ha eliminado correctamente.</p>';
            } else {
                echo '<p style="color:red;">No se ha encontrado el empleado o no tienes permisos.</p>';
            }
        }
    $modo_edicion = isset($_GET['edit_empleado']) ? true : false;

    // Guardar cambios de empleado editado
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['agregar_empleado'])) {
        global $wpdb;
        $user_id = get_current_user_id();
        $nombre = sanitize_text_field($_POST['nombre']);
        $apellidos = sanitize_text_field($_POST['apellidos']);
        $nif = strtoupper(preg_replace('/[\s-]+/','', sanitize_text_field($_POST['nif'])));
        $correo = sanitize_email($_POST['correo']);
        $telefono = sanitize_text_field($_POST['telefono']);
    
        if (empty($nombre) || empty($apellidos) || empty($nif) || empty($correo)) {
            echo '<p style="color:red;">Todos los campos son obligatorios.</p>';
        } else {
            // Verificaciones previas
            $errores = [];
        
            if (!tpl_validar_nif_nie($nif)) {
            $errores[] = 'NIF/NIE no válido. Revisa el formato y la letra (ej: 12345678Z o X1234567L).';
            }
        
            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $errores[] = 'El correo no tiene un formato válido.';
            }
        
            $duplicado = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}empleados 
                    WHERE user_id = %d AND (nif = %s OR correo = %s)",
                    $user_id, $nif, $correo
                )
            );
        
            if ($duplicado) {
                $errores[] = 'Ya existe un empleado con este NIF o correo electrónico.';
            }
        
            if (!empty($errores)) {
                foreach ($errores as $error) {
                    echo '<p style="color: #ffffff; background: red;padding: 1em;display: block;width: 50%;margin: auto;text-align: center;border-radius: 10px;">' . esc_html($error) . '</p>';
                }
            } else {
                    $wpdb->insert(
                        "{$wpdb->prefix}empleados",
                        [
                            'user_id' => $user_id,
                            'nombre' => $nombre,
                            'apellidos' => $apellidos,
                            'nif' => $nif,
                            'correo' => $correo,
                            'telefono' => $telefono
                        ],
                        ['%d', '%s', '%s', '%s', '%s', '%s']
                    );
        
                if ($wpdb->insert_id) {
                    echo '<p style="color:#ffffff; background:#02b502; padding:1em; display:block; width:50%; margin:auto; text-align:center; border-radius:10px;">El usuario <b>' . esc_html($nombre . ' ' . $apellidos) . '</b> se ha añadido correctamente.</p>';
                } else {
                    echo '<p style="color:red;">Error al añadir el empleado.</p>';
                }
            }
        }
    }   
    //Editar empleado
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['editar_empleado'])) {
        global $wpdb;
        $id_empleado = intval($_POST['id_empleado']);
        $nombre = sanitize_text_field($_POST['nombre']);
        $apellidos = sanitize_text_field($_POST['apellidos']);
        $nif = strtoupper(preg_replace('/[\s-]+/','', sanitize_text_field($_POST['nif'])));
        $correo = sanitize_email($_POST['correo']);
        $telefono = sanitize_text_field($_POST['telefono']);
        $user_id = get_current_user_id();
    
        // Verificar que el empleado existe y pertenece al usuario
        $empleado_existente = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}empleados WHERE id = %d AND user_id = %d",
            $id_empleado, $user_id
        ));
    
        if ($empleado_existente) {
            $wpdb->update(
                "{$wpdb->prefix}empleados",
                [
                    'nombre' => $nombre,
                    'apellidos' => $apellidos,
                    'nif' => $nif,
                    'correo' => $correo,
                    'telefono' => $telefono

                ],
                ['id' => $id_empleado, 'user_id' => $user_id],
                ['%s', '%s', '%s', '%s', '%s'],
                ['%d', '%d']
            );
        
            echo '<p style="color: #ffffff; background: #02b502; padding: 1em; display: block; width: 50%; margin: auto; text-align: center; border-radius: 10px;">Los datos de <b>' . esc_html($nombre) .' ' . esc_html($apellidos) . '</b> se han actualizado correctamente.</p>';
        } else {
            echo '<p style="color:red;">No tienes permisos para editar este empleado.</p>';
        }
    }
        // Obtener empleados
        $empleados = $wpdb->get_results("SELECT * FROM $tabla_empleados WHERE user_id = $user_id");

        if ($empleados) {
            echo '<table style="border: 1px solid #ddd;">
                <tr>
                    <th class="encabezado">Nombre</th>
                    <th class="encabezado">Apellidos</th>
                    <th class="encabezado">NIF/NIE</th>
                    <th class="encabezado">Correo</th>
                    <th class="encabezado">Teléfono</th>
                    <th class="encabezado"></th>
                </tr>';
            foreach ($empleados as $empleado) {
                echo "<tr>
                    <td>{$empleado->nombre}</td>
                    <td>{$empleado->apellidos}</td>
                    <td>{$empleado->nif}</td>
                    <td>{$empleado->correo}</td>
                    <td>{$empleado->telefono}</td>
                    <td style='text-align:right; padding: 0px 15px 15px;'>
                        <button class='btn btn-primary' style='width:auto;' onclick=\"openModalEdit({$empleado->id}, '{$empleado->nombre}', '{$empleado->apellidos}', '{$empleado->nif}', '{$empleado->correo}', '{$empleado->telefono}')\">Editar</button>
                        <a class='btn btn-danger' href='?eliminar_empleado={$empleado->id}' onclick='return confirm(\"¿Seguro que deseas eliminar este empleado?\")'>🗑️</a>
                    </td>
                </tr>";
            }
            echo '</table>';
        } else {
            echo '<p>No tienes empleados registrados.</p>';
        }
    }
    ?>
    <!-- Modal para añadir empleado -->
    <div id="modalAgregar" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('modalAgregar')">&times;</span>
            <h3>Añadir nuevo empleado</h3>
            <form method="POST">
                <label>Nombre:</label>
                <input type="text" name="nombre" required>

                <label>Apellidos:</label>
                <input type="text" name="apellidos" required>

                <label>NIF/NIE:</label>
                <input type="text" name="nif" required>

                <label>Correo:</label>
                <input type="email" name="correo" required>

                <label>Teléfono:</label>
                <input type="text" name="telefono" required>

                <button type="submit" name="agregar_empleado">Añadir</button>
            </form>
        </div>
    </div>

    <!-- Modal para editar empleado -->
    <div id="modalEditar" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('modalEditar')">&times;</span>
            <h3>Editar Empleado</h3>
            <form method="POST">
                <input type="hidden" name="id_empleado" id="edit_id">
                <label>Nombre:</label>
                <input type="text" name="nombre" id="edit_nombre" required>
                <label>Apellidos:</label>
                <input type="text" name="apellidos" id="edit_apellidos" required>
                <label>NIF/NIE:</label>
                <input type="text" name="nif" id="edit_nif" required>
                <label>Correo:</label>
                <input type="email" name="correo" id="edit_correo" required>
                <label>Teléfono:</label>
                <input type="text" name="telefono" id="edit_telefono" required>

                <button type="submit" name="editar_empleado">Actualizar</button>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }
        function openModalEdit(id, nombre, apellidos, nif, correo, telefono) {
            document.getElementById("edit_id").value = id;
            document.getElementById("edit_nombre").value = nombre;
            document.getElementById("edit_apellidos").value = apellidos;
            document.getElementById("edit_nif").value = nif;
            document.getElementById("edit_correo").value = correo;
            document.getElementById("edit_telefono").value = telefono;
            openModal("modalEditar");
        }
    </script>
    <script>
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
             const mapa = { X:'0', Y:'1', Z:'2' };
             numero = mapa[v[0]] + v.slice(1, 8);
           }
           const calc = letras[parseInt(numero,10) % 23];
           return calc === letra;
         }

    document.addEventListener('DOMContentLoaded', function () {
    // VALIDA MODAL "AÑADIR"
    const formAdd = document.querySelector('#modalAgregar form');
    const nifAdd = formAdd.querySelector('input[name="nif"]');
    const correoAdd = formAdd.querySelector('input[name="correo"]');
    formAdd.addEventListener('submit', function (e) {
            let errores = [];

        if (!validarNifNie(nifAdd.value)) {
            errores.push("NIF/NIE incorrecto o letra no válida (ej: 12345678Z o X1234567L).");
         }

             if (!correoAdd.validity.valid) {
                errores.push("El correo no es válido.");
            }

            // Si hay errores, los mostramos y cancelamos el envío
            if (errores.length > 0) {
                e.preventDefault(); // Evita que se envíe el formulario

                let contenedorErrores = document.getElementById('errores-nuevo-empleado');
                if (!contenedorErrores) {
                    contenedorErrores = document.createElement('div');
                    contenedorErrores.id = 'errores-nuevo-empleado';
                    contenedorErrores.style.marginTop = '1em';
                    formAdd.prepend(contenedorErrores);
                }

                contenedorErrores.innerHTML = '';
                errores.forEach(function (error) {
                    contenedorErrores.innerHTML += `<p style="color: #fff; background: #be3a34; padding: 1em; text-align: center; border-radius: 10px; font-weight: bold; margin-bottom: 5px;">${error}</p>`;
                });
            }
        });
        // VALIDA MODAL "EDITAR"
        const formEdit = document.querySelector('#modalEditar form');
        const nifEdit = formEdit.querySelector('input[name="nif"]');
        const correoEdit = formEdit.querySelector('input[name="correo"]');
        formEdit.addEventListener('submit', function (e) {
            let errores = [];
            if (!validarNifNie(nifEdit.value)) {
                errores.push("NIF/NIE incorrecto o letra no válida.");
            }
            if (!correoEdit.validity.valid) {
                errores.push("El correo no es válido.");
            }
            if (errores.length > 0) {
                e.preventDefault();
                let box = document.getElementById('errores-editar-empleado');
                if (!box) {
                box = document.createElement('div');
                box.id = 'errores-editar-empleado';
                box.style.marginTop = '1em';
                formEdit.prepend(box);
                }
                box.innerHTML = '';
                errores.forEach(function (error) {
                box.innerHTML += `<p style="color:#fff;background:#be3a34;padding:1em;text-align:center;border-radius:10px;font-weight:bold;margin-bottom:5px;">${error}</p>`;
                });
            }
        });        
    });
</script>


</div>

<?php
// Incluir el footer del tema
get_footer();
?>
