<?php
/*
Plugin Name: Ncol Publisher
Description: Publica automáticamente entradas en Facebook, X y WhatsApp vía AWS API Gateway.
Version: 1.5
Author: Miguel Antequera
*/

if (!defined('ABSPATH')) exit;

// Agregar Metabox
add_action('add_meta_boxes', function() {
    add_meta_box(
        'social_publish_options',
        'Publicar en redes sociales',
        'render_social_meta_box',
        'post',
        'side',
        'high'
    );
});

function render_social_meta_box($post) {
    wp_nonce_field('social_publish_meta_box', 'social_publish_nonce');

    $networks = [
        'facebook' => 'Facebook',
        'twitter' => 'X',
        'whatsapp' => 'WhatsApp',
        'instagram' => 'Instagram',
        'threads' => 'Threads'
    ];

    foreach ($networks as $key => $label) {
        if (get_option("ncol_publisher_enabled_$key") !== '1') continue;

        $checked = get_post_meta($post->ID, "_publish_$key", true);
        ?>
        <p>
            <label>
                <input type="checkbox" name="publish_<?php echo esc_attr($key); ?>" value="1" <?php checked($checked, '1'); ?> />
                Publicar en <?php echo esc_html($label); ?>
            </label>
        </p>
        <?php
    }
}

// Guardar selección del metabox
add_action('save_post', function($post_id) {
    if (!isset($_POST['social_publish_nonce']) || !wp_verify_nonce($_POST['social_publish_nonce'], 'social_publish_meta_box')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $fields = ['facebook', 'twitter', 'whatsapp', 'instagram', 'threads'];
    foreach ($fields as $field) {
        $value = isset($_POST['publish_' . $field]) ? '1' : '0';
        update_post_meta($post_id, '_publish_' . $field, $value);
    }
});

// Hook confiable para publicar luego de guardar metadatos
add_action('transition_post_status', function($new_status, $old_status, $post) {
    $post_id = $post->ID;

    error_log("🟡 [NcolPublisher] transition_post_status: $old_status -> $new_status para post ID: $post_id");

    if ($new_status !== 'publish') {
        error_log("🔴 [NcolPublisher] Nuevo estado no es 'publish', abortando.");
        return;
    }

    $fields = ['facebook', 'twitter', 'whatsapp', 'instagram', 'threads'];
    $platforms = [];

    foreach ($fields as $field) {
        $val = isset($_POST['publish_' . $field]) ? '1' : '0';
        error_log("🟡 Plataforma $field desde POST (por defecto 0 si ausente): $val");

        if ($val === '1') {
            $platforms[] = $field;
        }
    }

    if (empty($platforms)) {
        error_log("🔴 [NcolPublisher] Ninguna plataforma seleccionada, abortando.");
        return;
    }

    // Siempre enviamos todas las plataformas seleccionadas y dejamos que la Lambda decida si publicar o no
    error_log("🟡 [NcolPublisher] Enviando plataformas seleccionadas para evaluación en Lambda: " . implode(', ', $platforms));

    $permalink = get_permalink($post_id);
    $permalink = str_replace('https://admin.noticiascol.com', 'https://www.noticiascol.com', $permalink);
    $image_url = get_the_post_thumbnail_url($post_id, 'large') ?: null;

    $payload = [
        'postId' => (string) $post_id,
        'title' => get_the_title($post_id),
        'permalink' => $permalink,
        'excerpt' => get_the_excerpt($post_id),
        'targetPlatforms' => $platforms,
        'imageUrl' => $image_url,
        'content' => apply_filters('the_content', $post->post_content),
    ];

    error_log("🟢 Imagen incluida: " . ($image_url ?: 'No image'));

    $invoke_url = get_option('ncol_publisher_api_url');
    $api_key    = get_option('ncol_publisher_api_key');

    if (!$invoke_url || !$api_key) {
        error_log('Ncol Publisher: Falta configurar URL del API Gateway o API Key.');
        return;
    }

    error_log("🟢 Ncol Publisher [transition_post_status] ejecutado para el post '{$post->post_title}' (ID {$post_id})");
    error_log("🟢 Plataformas seleccionadas: " . implode(', ', $platforms));

    $response = wp_remote_post($invoke_url, [
        'method'  => 'POST',
        'headers' => [
            'Content-Type' => 'application/json',
            'x-api-key'    => $api_key,
        ],
        'body'    => json_encode($payload),
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        error_log('🟢 Ncol Publisher: Error al llamar a API Gateway - ' . $response->get_error_message());
    } else {
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 300) {
            error_log("🟢 Ncol Publisher: Error HTTP $code - " . wp_remote_retrieve_body($response));
        } else {
            error_log("🟢 Ncol Publisher: Publicación enviada con éxito para el post ID: $post_id");
            $previous_platforms = get_post_meta($post_id, '_ncol_published_platforms', true) ?: [];
            update_post_meta($post_id, '_ncol_published_platforms', array_merge($previous_platforms, $platforms));
        }
    }
}, 20, 3);

// Configuración en el admin
add_action('admin_menu', function() {
    add_options_page(
        'Ncol Publisher - Configuración',
        'Ncol Publisher',
        'manage_options',
        'ncol-publisher-settings',
        'ncol_publisher_settings_page'
    );
});

add_action('admin_init', function() {
    register_setting('ncol_publisher_options', 'ncol_publisher_api_url');
    register_setting('ncol_publisher_options', 'ncol_publisher_api_key');
    register_setting('ncol_publisher_options', 'ncol_publisher_enabled_facebook');
    register_setting('ncol_publisher_options', 'ncol_publisher_enabled_twitter');
    register_setting('ncol_publisher_options', 'ncol_publisher_enabled_whatsapp');
    register_setting('ncol_publisher_options', 'ncol_publisher_enabled_instagram');
    register_setting('ncol_publisher_options', 'ncol_publisher_enabled_threads');
});

function ncol_publisher_settings_page() {
    ?>
    <div class="wrap">
        <h1>Configuración de Ncol Publisher</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('ncol_publisher_options');
            do_settings_sections('ncol_publisher_options');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">URL del API Gateway</th>
                    <td><input type="text" name="ncol_publisher_api_url" value="<?php echo esc_attr(get_option('ncol_publisher_api_url')); ?>" size="60"/></td>
                </tr>
                <tr valign="top">
                    <th scope="row">API Key</th>
                    <td><input type="text" name="ncol_publisher_api_key" value="<?php echo esc_attr(get_option('ncol_publisher_api_key')); ?>" size="60"/></td>
                </tr>
                <tr valign="top">
                <th scope="row">Redes habilitadas</th>
                <td>
                   <label><input type="checkbox" name="ncol_publisher_enabled_facebook" value="1" <?php checked(get_option('ncol_publisher_enabled_facebook'), '1'); ?> /> Facebook</label><br>
                   <label><input type="checkbox" name="ncol_publisher_enabled_twitter" value="1" <?php checked(get_option('ncol_publisher_enabled_twitter'), '1'); ?> /> X / Twitter</label><br>
                   <label><input type="checkbox" name="ncol_publisher_enabled_whatsapp" value="1" <?php checked(get_option('ncol_publisher_enabled_whatsapp'), '1'); ?> /> WhatsApp</label><br>
                   <label><input type="checkbox" name="ncol_publisher_enabled_instagram" value="1" <?php checked(get_option('ncol_publisher_enabled_instagram'), '1'); ?> /> Instagram</label><br>
                   <label><input type="checkbox" name="ncol_publisher_enabled_threads" value="1" <?php checked(get_option('ncol_publisher_enabled_threads'), '1'); ?> /> Threads</label>
                </td>
            </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Endpoints personalizados para Facebook Login, Deauthorize y Data Deletion
add_action('init', function () {
    add_rewrite_rule('^facebook-callback/?', 'index.php?facebook_callback=1', 'top');
    add_rewrite_rule('^facebook-deauthorize/?', 'index.php?facebook_deauthorize=1', 'top');
    add_rewrite_rule('^facebook-data-deletion/?', 'index.php?facebook_data_deletion=1', 'top');

    add_rewrite_rule('^threads-callback/?', 'index.php?threads_callback=1', 'top');
    add_rewrite_rule('^threads-deauthorize/?', 'index.php?threads_deauthorize=1', 'top');
    add_rewrite_rule('^threads-data-deletion/?', 'index.php?threads_data_deletion=1', 'top');
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'facebook_callback';
    $vars[] = 'facebook_deauthorize';
    $vars[] = 'facebook_data_deletion';

    $vars[] = 'threads_callback';
    $vars[] = 'threads_deauthorize';
    $vars[] = 'threads_data_deletion';

    return $vars;
});

add_action('template_redirect', function () {
    if (get_query_var('facebook_callback')) {
        status_header(200);
        echo "<h1>✅ Autenticación de Facebook completada</h1><p>Puedes cerrar esta ventana.</p>";
        exit;
    }

    if (get_query_var('facebook_deauthorize')) {
        status_header(200);
        echo "<h1>Desautorización recibida</h1><p>Tu cuenta fue desvinculada de nuestra aplicación.</p>";
        exit;
    }

    if (get_query_var('facebook_data_deletion')) {
        status_header(200);
        echo "<h1>Eliminación de datos</h1><p>Si deseas eliminar tus datos de nuestra plataforma, por favor contáctanos a contacto@noticiascol.com y atenderemos tu solicitud.</p>";
        exit;
    }

    // Threads
    if (get_query_var('threads_callback')) {
        status_header(200);
        echo "<h1>✅ Autenticación de Threads completada</h1><p>Puedes cerrar esta ventana.</p>";
        exit;
    }

    if (get_query_var('threads_deauthorize')) {
        status_header(200);
        echo "<h1>Desautorización de Threads</h1><p>Tu cuenta de Threads fue desvinculada.</p>";
        exit;
    }

    if (get_query_var('threads_data_deletion')) {
        status_header(200);
        echo "<h1>Eliminación de datos de Threads</h1><p>Contáctanos a contacto@noticiascol.com para eliminar tus datos asociados.</p>";
        exit;
    }
});