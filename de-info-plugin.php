<?php
/*
Plugin Name: Server Resource Monitor
Description: Real-time server resource monitor
Version: 2.1.1
Author: Alexis Olivero 
Author URI: https://oliverodev.com/
*/

// Verificar que WordPress está cargado
if (!defined('ABSPATH')) {
    exit;
}

// Agregar scripts y estilos necesarios
function enqueue_monitor_scripts($hook) {
    // Solo cargar en la página del plugin
    if($hook != 'toplevel_page_server-monitor') {
        return;
    }
    
    wp_enqueue_script('jquery');
    
    // Verificar si los archivos existen antes de cargarlos
    $js_file = plugin_dir_path(__FILE__) . 'js/monitor.js';
    $css_file = plugin_dir_path(__FILE__) . 'css/monitor.css';
    
    if (file_exists($css_file)) {
        wp_enqueue_style('server-monitor-style', plugins_url('css/monitor.css', __FILE__));
    }
    
    if (file_exists($js_file)) {
        wp_enqueue_script('server-monitor', plugins_url('js/monitor.js', __FILE__), array('jquery'), '1.0', true);
        wp_localize_script('server-monitor', 'monitorAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('monitor_nonce')
        ));
    }
}
add_action('admin_enqueue_scripts', 'enqueue_monitor_scripts');

// Función para rastrear solicitudes PHP lentas
function get_php_requests_performance() {
    $slow_requests = array();
    
    // Intentar leer el log de PHP si existe
    $log_file = ini_get('error_log');
    if ($log_file && file_exists($log_file)) {
        $lines = array_slice(file($log_file), -100); // Últimas 100 líneas
        foreach ($lines as $line) {
            if (strpos($line, 'execution time') !== false) {
                $slow_requests[] = sanitize_text_field($line);
            }
        }
    }
    
    // Si no hay solicitudes lentas, agregar mensaje informativo
    if (empty($slow_requests)) {
        $slow_requests[] = "No slow requests have been detected recently - The system is working properly.";
    }
    
    // Obtener información de rendimiento de la página actual
    $current_page_stats = array(
        'page' => $_SERVER['REQUEST_URI'],
        'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
        'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024, // MB
        'included_files' => count(get_included_files())
    );
    
    return array(
        'slow_requests' => array_slice($slow_requests, -5), // Últimas 5 solicitudes lentas
        'current_page' => $current_page_stats,
        'has_slow_requests' => count($slow_requests) > 1 // true si hay solicitudes reales, false si solo está el mensaje predeterminado
    );
}

function get_realtime_data() {
    // Verificar permisos de usuario
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permisos insuficientes');
        return;
    }
    
    // Verificar nonce
    check_ajax_referer('monitor_nonce', 'nonce');
    
    $data = array(
        'memory' => get_server_memory_usage(),
        'cpu' => get_simple_cpu_usage(),
        'services' => get_top_resource_consuming_services(),
        'server' => get_server_usage_percentage(),
        'api_requests' => get_api_requests_data(),
        'php_performance' => get_php_requests_performance() // Nueva información
    );
    
    wp_send_json_success($data);
}
add_action('wp_ajax_get_realtime_data', 'get_realtime_data');

function get_server_memory_usage() {
    $memory_limit = ini_get('memory_limit');
    $memory_used = memory_get_usage(true);
    $memory_limit_bytes = return_bytes($memory_limit);
    
    return array(
        'used' => round($memory_used / 1024 / 1024, 2),
        'limit' => $memory_limit,
        'percentage' => round(($memory_used / $memory_limit_bytes) * 100, 2)
    );
}

function get_simple_cpu_usage() {
    try {
        if (stristr(PHP_OS, 'win')) {
            // Sanitizar y escapar comandos
            $cmd = escapeshellcmd("wmic cpu get loadpercentage /value");
            $wmic_output = shell_exec($cmd);
            
            if (preg_match('/LoadPercentage=(\d+)/', $wmic_output, $matches)) {
                $percentage = absint($matches[1]);
            } else {
                $cmd = escapeshellcmd("powershell \"Get-Counter '\\Processor(_Total)\\% Processor Time' -SampleInterval 1 -MaxSamples 1 | Select-Object -ExpandProperty CounterSamples | Select-Object -ExpandProperty CookedValue\"");
                $ps_output = shell_exec($cmd);
                $percentage = is_numeric(trim($ps_output)) ? absint(round((float)trim($ps_output), 2)) : 0;
            }
        } else {
            $load = sys_getloadavg();
            $percentage = isset($load[0]) ? absint(round($load[0] * 100 / get_nprocs(), 2)) : 0;
        }

        return array(
            'percentage' => min($percentage, 100), // Asegurar que no exceda 100%
            'timestamp' => time()
        );
    } catch (Exception $e) {
        error_log('Server Resource Monitor - CPU Error: ' . sanitize_text_field($e->getMessage()));
        return array('percentage' => 0, 'timestamp' => time());
    }
}

function get_nprocs() {
    if (stristr(PHP_OS, 'win')) {
        $cmd = "wmic cpu get NumberOfLogicalProcessors /value";
        $wmic_output = @shell_exec($cmd);
        if (preg_match('/NumberOfLogicalProcessors=(\d+)/', $wmic_output, $matches)) {
            return (int)$matches[1];
        }
    } else {
        return (int)trim(shell_exec("nproc"));
    }
    return 1; // Default to 1 if unable to determine
}

function get_plugins_resource_usage() {
    if (!function_exists('get_plugin_data')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
    
    $plugins_info = array();
    $active_plugins = (array) get_option('active_plugins', array());
    
    foreach ($active_plugins as $plugin_path) {
        try {
            $full_path = WP_PLUGIN_DIR . '/' . $plugin_path;
            if (!file_exists($full_path)) continue;

            $time_start = microtime(true);
            include_once($full_path);
            $time_end = microtime(true);
            
            $plugin_data = get_plugin_data($full_path);
            $plugins_info[] = array(
                'name' => $plugin_data['Name'],
                'impact' => round(($time_end - $time_start) * 1000, 2)
            );
        } catch (Exception $e) {
            error_log("Error midiendo plugin {$plugin_path}: " . $e->getMessage());
        }
    }
    
    return $plugins_info;
}

function return_bytes($size_str) {
    switch (substr($size_str, -1)) {
        case 'G': case 'g': return (int)$size_str * 1024 * 1024 * 1024;
        case 'M': case 'm': return (int)$size_str * 1024 * 1024;
        case 'K': case 'k': return (int)$size_str * 1024;
        default: return (int)$size_str;
    }
}

function get_quick_response_time() {
    $start = microtime(true);
    $test_url = admin_url('admin-ajax.php');
    
    $args = array(
        'timeout' => 2,
        'blocking' => true,
        'sslverify' => false
    );
    
    $response = wp_remote_head($test_url, $args);
    return round((microtime(true) - $start) * 1000, 2);
}

function get_server_usage_percentage() {
    $cpu = get_simple_cpu_usage()['percentage'];
    $memory = get_server_memory_usage()['percentage'];
    return round(($cpu + $memory) / 2, 2);
}

function get_top_resource_consuming_services() {
    $services = array();
    
    if (stristr(PHP_OS, 'win')) {
        $cmd = escapeshellcmd("tasklist /FO CSV /NH");
        $output = shell_exec($cmd);
        $lines = explode("\n", trim($output));
        
        foreach ($lines as $line) {
            $columns = str_getcsv($line);
            if (count($columns) >= 6) {
                $memory_mb = round(absint(str_replace(',', '', $columns[4])) / 1024, 2);
                $services[] = array(
                    'name' => sanitize_text_field($columns[0]),
                    'pid' => absint($columns[1]),
                    'memory' => floatval($memory_mb)
                );
            }
        }
    } else {
        $cmd = escapeshellcmd("ps aux --sort=-%mem | awk 'NR>1 {print $11, $2, $6}' | head -n 10");
        $output = shell_exec($cmd);
        $lines = explode("\n", trim($output));
        
        foreach ($lines as $line) {
            $columns = preg_split('/\s+/', $line);
            if (count($columns) >= 3) {
                $memory_mb = round(absint($columns[2]) / 1024, 2);
                $services[] = array(
                    'name' => sanitize_text_field($columns[0]),
                    'pid' => absint($columns[1]),
                    'memory' => floatval($memory_mb)
                );
            }
        }
    }
    
    return array_slice($services, 0, 10); // Limitar a 10 resultados
}

// Añadir nueva función para monitorear solicitudes API
function get_api_requests_data() {
    global $wpdb;
    
    // Crear tabla si no existe
    maybe_create_requests_log_table();
    
    // Obtener últimas solicitudes
    $results = $wpdb->get_results("
        SELECT request_method, request_path, request_ip, count(*) as count, 
        MAX(timestamp) as last_request,
        SUM(CASE WHEN timestamp > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) as recent_count
        FROM {$wpdb->prefix}api_requests_log
        WHERE timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        GROUP BY request_method, request_path, request_ip
        ORDER BY recent_count DESC
        LIMIT 10
    ");
    
    return array(
        'requests' => $results,
        'total_last_hour' => $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}api_requests_log 
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ")
    );
}

// Función para crear la tabla de log
function maybe_create_requests_log_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'api_requests_log';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            request_method varchar(10) NOT NULL,
            request_path varchar(255) NOT NULL,
            request_ip varchar(45) NOT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY timestamp (timestamp),
            KEY request_ip (request_ip)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Función para registrar las solicitudes
function log_api_request() {
    global $wpdb;
    
    if (defined('REST_REQUEST') && REST_REQUEST) {
        // Sanitizar datos de entrada
        $request_method = sanitize_text_field($_SERVER['REQUEST_METHOD']);
        $request_path = esc_url_raw($_SERVER['REQUEST_URI']);
        $request_ip = sanitize_text_field($_SERVER['REMOTE_ADDR']);
        
        // Preparar consulta de manera segura
        $wpdb->insert(
            $wpdb->prefix . 'api_requests_log',
            array(
                'request_method' => substr($request_method, 0, 10),
                'request_path' => substr($request_path, 0, 255),
                'request_ip' => substr($request_ip, 0, 45)
            ),
            array('%s', '%s', '%s')
        );
    }
}
add_action('init', 'log_api_request');

function server_monitor_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    ?>
    <div class="wrap">
        <h1>Real-Time Resource Monitor</h1>
        
        <div class="card" id="memory-card">
            <h2>Server Memory</h2>
            <div class="resource-meter">
                <div class="meter-bar" id="memory-bar"></div>
            </div>
            <p>Usage: <span id="memory-used">0</span> MB of <span id="memory-limit">0</span></p>
            <p>Percentage: <span id="memory-percentage">0</span>%</p>
        </div>

        <div class="card" id="server-card">
            <h2>Server Usage</h2>
            <div class="resource-meter">
                <div class="meter-bar" id="server-bar"></div>
            </div>
            <p>Total usage: <span id="server-percentage">0</span>%</p>
        </div>

        <div class="card">
            <h2>Top Resource Consuming Services</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>PID</th>
                        <th>Memory (MB)</th>
                    </tr>
                </thead>
                <tbody id="services-list"></tbody>
            </table>
        </div>

        <div class="card">
            <h2>API Request Monitor</h2>
            <p>Total requests in last hour: <span id="total-requests">0</span></p>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Method</th>
                        <th>Path</th>
                        <th>IP</th>
                        <th>Count (1h)</th>
                        <th>Recent (5m)</th>
                        <th>Last Request</th>
                    </tr>
                </thead>
                <tbody id="api-requests-list"></tbody>
            </table>
        </div>

        <div class="card">
            <h2>PHP Performance Monitor</h2>
            <div class="current-page-stats">
                <h3>Current Page Performance</h3>
                <p>Execution Time: <span id="current-execution-time">0</span> seconds</p>
                <p>Memory Peak: <span id="current-memory-peak">0</span> MB</p>
                <p>Included Files: <span id="current-included-files">0</span></p>
            </div>
            <h3>Recent Slow Requests</h3>
            <div id="slow-requests-list" class="slow-requests-container"></div>
        </div>
    </div>
    <style>
        .slow-requests-container {
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
            margin-top: 10px;
        }
        .slow-request-item {
            padding: 8px;
            margin-bottom: 5px;
            border-bottom: 1px solid #eee;
        }
        .slow-request-item.no-requests {
            color: #0c831c;
            font-weight: 500;
        }
    </style>
    <script>
        jQuery(document).ready(function($) {
            function updateMonitorData() {
                $.ajax({
                    url: monitorAjax.ajaxurl,
                    type: 'post',
                    data: {
                        action: 'get_realtime_data',
                        nonce: monitorAjax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            $('#memory-used').text(data.memory.used);
                            $('#memory-limit').text(data.memory.limit);
                            $('#memory-percentage').text(data.memory.percentage);
                            $('#memory-bar').css('width', data.memory.percentage + '%');
                            
                            $('#server-percentage').text(data.server);
                            $('#server-bar').css('width', data.server + '%');
                            
                            var servicesList = $('#services-list');
                            servicesList.empty();
                            data.services.forEach(function(service) {
                                servicesList.append('<tr>' +
                                    '<td>' + service.name + '</td>' +
                                    '<td>' + service.pid + '</td>' +
                                    '<td>' + service.memory + '</td>' +
                                '</tr>');
                            });

                            // Update API requests data
                            $('#total-requests').text(data.api_requests.total_last_hour);
                            var requestsList = $('#api-requests-list');
                            requestsList.empty();
                            
                            data.api_requests.requests.forEach(function(req) {
                                var rowClass = req.recent_count > 50 ? 'high-usage' : 
                                req.recent_count > 20 ? 'medium-usage' : '';
                                requestsList.append('<tr class="' + rowClass + '">' +
                                    '<td>' + req.request_method + '</td>' +
                                    '<td>' + req.request_path + '</td>' +
                                    '<td>' + req.request_ip + '</td>' +
                                    '<td>' + req.count + '</td>' +
                                    '<td>' + req.recent_count + '</td>' +
                                    '<td>' + req.last_request + '</td>' +
                                '</tr>');
                            });

                            // Actualizar información de rendimiento de PHP
                            if (data.php_performance) {
                                $('#current-execution-time').text(data.php_performance.current_page.execution_time.toFixed(4));
                                $('#current-memory-peak').text(data.php_performance.current_page.memory_peak.toFixed(2));
                                $('#current-included-files').text(data.php_performance.current_page.included_files);
                                
                                var slowRequestsList = $('#slow-requests-list');
                                slowRequestsList.empty();
                                data.php_performance.slow_requests.forEach(function(req) {
                                    var cssClass = data.php_performance.has_slow_requests ? 
                                        'slow-request-item' : 'slow-request-item no-requests';
                                    slowRequestsList.append('<div class="' + cssClass + '">' + req + '</div>');
                                });
                            }
                        }
                    }
                });
            }
            
            setInterval(updateMonitorData, 5000);
            updateMonitorData();
        });
    </script>
    <?php
}

// Registrar el menú del plugin
function server_monitor_menu() {
    add_menu_page(
        'Resource Monitor',
        'Resource Monitor',
        'manage_options',
        'server-monitor',
        'server_monitor_page',
        'dashicons-performance',
        90
    );
}

add_action('admin_menu', 'server_monitor_menu');
?>
