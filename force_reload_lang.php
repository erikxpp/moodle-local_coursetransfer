<?php
/**
 * Script de emergencia para forzar recarga de cadenas de idioma
 * 
 * INSTRUCCIONES:
 * 1. Sube este archivo a: /local/coursetransfer/force_reload_lang.php
 * 2. Accede desde el navegador: https://tu-moodle.com/local/coursetransfer/force_reload_lang.php
 * 3. Elimina este archivo después de usarlo (por seguridad)
 */

require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

// Purgar todas las cachés
purge_all_caches();

// Resetear cachés de strings específicamente
get_string_manager()->reset_caches();

// Verificar que las cadenas existan en el archivo
$langfile = __DIR__ . '/lang/es/local_coursetransfer.php';
$strings_to_check = [
    'retry_request',
    'retry_request_help',
    'retry_request_confirm_title',
    'retry_request_confirm_message',
    'retry_request_success',
    'retry_request_failed',
    'retry',
];

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Recarga de Cadenas</title>';
echo '<style>
body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
.container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 800px; margin: 0 auto; }
h1 { color: #333; border-bottom: 3px solid #0066cc; padding-bottom: 10px; }
.success { color: #28a745; padding: 10px; background: #d4edda; border-left: 4px solid #28a745; margin: 10px 0; }
.error { color: #dc3545; padding: 10px; background: #f8d7da; border-left: 4px solid #dc3545; margin: 10px 0; }
.info { color: #0066cc; padding: 10px; background: #cce5ff; border-left: 4px solid #0066cc; margin: 10px 0; }
.check { margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 4px; }
.check-item { padding: 8px; margin: 5px 0; }
.check-item.ok { color: #28a745; }
.check-item.fail { color: #dc3545; }
code { background: #e9ecef; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
.btn { display: inline-block; padding: 10px 20px; background: #0066cc; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; }
.btn:hover { background: #0052a3; }
</style></head><body><div class="container">';

echo '<h1>🔄 Recarga Forzada de Cadenas de Idioma</h1>';

echo '<div class="success"><strong>✓ Cachés purgadas exitosamente</strong></div>';
echo '<div class="success"><strong>✓ Cachés de strings reseteadas</strong></div>';

echo '<div class="check">';
echo '<h2>Verificación de Cadenas en Archivo</h2>';
echo '<p>Archivo: <code>' . htmlspecialchars($langfile) . '</code></p>';

if (file_exists($langfile)) {
    $content = file_get_contents($langfile);
    echo '<div class="info">✓ Archivo de idioma encontrado</div>';
    
    foreach ($strings_to_check as $string) {
        if (strpos($content, "\$string['$string']") !== false) {
            echo '<div class="check-item ok">✓ <code>' . htmlspecialchars($string) . '</code> definida en archivo</div>';
        } else {
            echo '<div class="check-item fail">✗ <code>' . htmlspecialchars($string) . '</code> NO encontrada en archivo</div>';
        }
    }
} else {
    echo '<div class="error">✗ Archivo de idioma NO encontrado</div>';
}

echo '</div>';

echo '<div class="check">';
echo '<h2>Verificación de Carga en Memoria</h2>';

foreach ($strings_to_check as $string) {
    try {
        $value = get_string($string, 'local_coursetransfer');
        if (strpos($value, '[[') === false && strpos($value, ']]') === false) {
            echo '<div class="check-item ok">✓ <code>' . htmlspecialchars($string) . '</code>: "' . htmlspecialchars(substr($value, 0, 50)) . '"</div>';
        } else {
            echo '<div class="check-item fail">✗ <code>' . htmlspecialchars($string) . '</code>: No cargada (valor: ' . htmlspecialchars($value) . ')</div>';
        }
    } catch (Exception $e) {
        echo '<div class="check-item fail">✗ <code>' . htmlspecialchars($string) . '</code>: ERROR - ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

echo '</div>';

echo '<div class="info">';
echo '<h2>Próximos Pasos</h2>';
echo '<ol>';
echo '<li>Actualiza esta página (<code>Ctrl+F5</code>) para verificar que las cadenas se cargaron</li>';
echo '<li>Ve a <a href="' . new moodle_url('/local/coursetransfer/logs.php') . '">Logs de Transferencias</a></li>';
echo '<li>El botón debería decir <strong>"Reintentar"</strong> (no [[retry_request]])</li>';
echo '<li>Si funciona, <strong>elimina este archivo</strong> por seguridad: <code>force_reload_lang.php</code></li>';
echo '</ol>';
echo '</div>';

echo '<a href="' . new moodle_url('/local/coursetransfer/logs.php') . '" class="btn">Ir a Logs de Transferencias</a>';

echo '</div></body></html>';
