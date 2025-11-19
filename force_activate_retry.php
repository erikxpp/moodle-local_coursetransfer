#!/usr/bin/env php
<?php
/**
 * Script para forzar la activación del botón de retry
 * Ejecutar desde la raíz de Moodle: php local/coursetransfer/force_activate_retry.php
 */

define('CLI_SCRIPT', true);

// Intentar encontrar config.php
$paths = [
    __DIR__ . '/../../config.php',
    __DIR__ . '/../../../config.php',
    dirname(__DIR__, 3) . '/config.php',
];

$config_found = false;
foreach ($paths as $config_path) {
    if (file_exists($config_path)) {
        require_once($config_path);
        $config_found = true;
        break;
    }
}

if (!$config_found) {
    echo "❌ ERROR: No se pudo encontrar config.php\n";
    echo "   Por favor ejecuta este script desde la raíz de Moodle:\n";
    echo "   php local/coursetransfer/force_activate_retry.php\n";
    exit(1);
}

require_once($CFG->libdir . '/clilib.php');

echo "\n";
echo "================================================\n";
echo "  ACTIVACIÓN FORZADA: Botón Retry Request\n";
echo "================================================\n\n";

// 1. Verificar archivos
echo "1️⃣  Verificando archivos del plugin...\n\n";

$plugin_path = $CFG->dirroot . '/local/coursetransfer';
$files_to_check = [
    'External Service' => '/classes/external/frontend/retry_request_external.php',
    'JavaScript AMD' => '/amd/src/retry_request.js',
    'Services DB' => '/db/services.php',
    'Strings ES' => '/lang/es/local_coursetransfer.php',
];

$all_ok = true;
foreach ($files_to_check as $name => $file) {
    $full_path = $plugin_path . $file;
    if (file_exists($full_path)) {
        echo "   ✅ $name\n";
        
        // Verificar contenido
        if ($name === 'Strings ES') {
            $content = file_get_contents($full_path);
            if (strpos($content, "retry_request") === false) {
                echo "      ⚠️  NO contiene strings 'retry_request'\n";
                $all_ok = false;
            }
        }
        
        if ($name === 'Services DB') {
            $content = file_get_contents($full_path);
            if (strpos($content, "local_coursetransfer_retry_failed_request") === false) {
                echo "      ⚠️  NO contiene servicio 'retry_failed_request'\n";
                $all_ok = false;
            } else {
                echo "      ✅ Servicio registrado\n";
            }
        }
    } else {
        echo "   ❌ $name - NO ENCONTRADO\n";
        $all_ok = false;
    }
}

echo "\n";

if (!$all_ok) {
    echo "⚠️  ADVERTENCIA: Faltan archivos o contenido\n";
    echo "   Por favor verifica que todos los archivos se hayan copiado correctamente\n\n";
}

// 2. Purgar cachés
echo "2️⃣  Purgando cachés...\n\n";

purge_all_caches();

echo "   ✅ Cachés purgados\n\n";

// 3. Verificar strings
echo "3️⃣  Verificando strings de idioma...\n\n";

$strings_needed = [
    'retry_request',
    'retry_request_help',
    'retry_request_description',
    'retry_request_confirm_title',
    'retry_request_confirm_message',
    'retry_request_success',
    'retry_request_failed',
];

$missing_strings = [];
foreach ($strings_needed as $string_key) {
    try {
        $value = get_string($string_key, 'local_coursetransfer');
        if (!empty($value)) {
            echo "   ✅ $string_key\n";
        } else {
            echo "   ⚠️  $string_key (vacío)\n";
            $missing_strings[] = $string_key;
        }
    } catch (Exception $e) {
        echo "   ❌ $string_key - NO ENCONTRADO\n";
        $missing_strings[] = $string_key;
    }
}

echo "\n";

if (count($missing_strings) > 0) {
    echo "⚠️  Faltan " . count($missing_strings) . " strings de idioma\n";
    echo "   Esto puede causar que el botón no aparezca correctamente\n\n";
}

// 4. Verificar servicio web
echo "4️⃣  Verificando servicio web...\n\n";

$service_name = 'local_coursetransfer_retry_failed_request';
$service_exists = $DB->record_exists('external_functions', ['name' => $service_name]);

if ($service_exists) {
    echo "   ✅ Servicio registrado en BD: $service_name\n\n";
} else {
    echo "   ⚠️  Servicio NO registrado en BD\n";
    echo "   Ejecuta: php admin/cli/upgrade.php\n\n";
}

// 5. Verificar una solicitud de prueba
echo "5️⃣  Verificando solicitud de prueba (ID: 1175)...\n\n";

$test_request = $DB->get_record('local_coursetransfer_request', ['id' => 1175]);

if ($test_request) {
    echo "   ✅ Solicitud encontrada\n";
    echo "   - Status: {$test_request->status} " . ($test_request->status == 0 ? "(ERROR) ✅" : "") . "\n";
    echo "   - Type: {$test_request->type} " . ($test_request->type == 0 ? "(COURSE) ✅" : "") . "\n";
    echo "   - Course: {$test_request->origin_course_fullname}\n\n";
    
    if ($test_request->status == 0 && $test_request->type == 0) {
        echo "   ✅ Esta solicitud DEBE mostrar el botón\n\n";
        
        $url = new moodle_url('/local/coursetransfer/logs_detail.php', ['requestid' => 1175]);
        echo "   🔗 URL: " . $url->out(false) . "\n\n";
    } else {
        echo "   ⚠️  Esta solicitud NO cumple las condiciones\n";
        echo "      (Debe ser: status=0 y type=0)\n\n";
    }
} else {
    echo "   ℹ️  Solicitud 1175 no encontrada (buscar otra con error)\n\n";
    
    // Buscar cualquier solicitud con error
    $error_request = $DB->get_record_sql(
        "SELECT * FROM {local_coursetransfer_request} 
         WHERE status = 0 AND type = 0 
         ORDER BY timecreated DESC 
         LIMIT 1"
    );
    
    if ($error_request) {
        echo "   ✅ Solicitud con error encontrada: ID {$error_request->id}\n";
        $url = new moodle_url('/local/coursetransfer/logs_detail.php', ['requestid' => $error_request->id]);
        echo "   🔗 URL: " . $url->out(false) . "\n\n";
    }
}

// 6. Resumen
echo "================================================\n";
echo "  RESUMEN\n";
echo "================================================\n\n";

if ($all_ok && count($missing_strings) == 0 && $service_exists) {
    echo "✅ TODO CORRECTO - El botón debería aparecer\n\n";
    
    echo "PASOS FINALES:\n";
    echo "1. Abre la URL en tu navegador\n";
    echo "2. Recarga con Ctrl+F5 (hard reload)\n";
    echo "3. Busca el botón naranja debajo de la tabla de detalles\n\n";
    
    echo "SI NO APARECE:\n";
    echo "1. Abre DevTools (F12)\n";
    echo "2. Ve a Console → Busca errores JS\n";
    echo "3. Ve a Elements → Busca: data-action=\"retry-request\"\n";
    echo "4. Ve a Network → Busca: retry_request.js\n\n";
    
} else {
    echo "⚠️  HAY PROBLEMAS - Revisar lo siguiente:\n\n";
    
    if (!$all_ok) {
        echo "- Verificar que todos los archivos estén en su lugar\n";
    }
    if (count($missing_strings) > 0) {
        echo "- Faltan strings de idioma\n";
    }
    if (!$service_exists) {
        echo "- Ejecutar: php admin/cli/upgrade.php\n";
    }
    echo "\n";
}

echo "================================================\n\n";

exit(0);
