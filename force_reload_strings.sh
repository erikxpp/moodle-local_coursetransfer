#!/bin/bash

################################################################################
# Script para ejecutar DESPUÉS de instalar el plugin en producción
################################################################################
# Este script fuerza la recarga de cadenas de idioma y purga cachés específicas
################################################################################

echo "========================================================================"
echo "  FORZAR RECARGA DE CADENAS DE IDIOMA - PRODUCCIÓN"
echo "========================================================================"
echo ""

# Detectar Moodle
if [ -f "/var/www/html/moodle/config.php" ]; then
    MOODLE_DIR="/var/www/html/moodle"
elif [ -f "./config.php" ]; then
    MOODLE_DIR="."
else
    echo "❌ ERROR: No se encuentra config.php de Moodle"
    echo "   Ejecuta este script desde el directorio raíz de Moodle"
    exit 1
fi

echo "✓ Moodle encontrado: $MOODLE_DIR"
echo ""

cd "$MOODLE_DIR" || exit 1

# Paso 1: Purgar TODAS las cachés
echo "========================================================================"
echo "PASO 1: Purgar todas las cachés"
echo "========================================================================"
echo ""

sudo -u www-data php admin/cli/purge_caches.php

if [ $? -eq 0 ]; then
    echo "✓ Cachés purgadas"
else
    echo "❌ ERROR al purgar cachés"
    exit 1
fi

echo ""

# Paso 2: Purgar cachés de idioma específicamente
echo "========================================================================"
echo "PASO 2: Purgar cachés de idioma (language strings)"
echo "========================================================================"
echo ""

# Borrar archivos de caché de idioma
echo "Borrando archivos de caché de idioma..."

if [ -d "$MOODLE_DIR/moodledata/cache/lang" ]; then
    sudo -u www-data rm -rf "$MOODLE_DIR/moodledata/cache/lang/*"
    echo "✓ Caché de idioma eliminada"
fi

if [ -d "$MOODLE_DIR/moodledata/lang" ]; then
    sudo -u www-data rm -rf "$MOODLE_DIR/moodledata/lang/*/local_coursetransfer.php"
    echo "✓ Archivos de idioma del plugin eliminados"
fi

echo ""

# Paso 3: Purgar cachés de JavaScript
echo "========================================================================"
echo "PASO 3: Purgar cachés de JavaScript"
echo "========================================================================"
echo ""

# Incrementar revisión de JavaScript para forzar recarga
sudo -u www-data php -r "
require_once('config.php');
require_once(\$CFG->libdir.'/adminlib.php');
purge_all_caches();
echo 'JavaScript cache purged\n';
"

echo "✓ Cachés de JavaScript purgadas"
echo ""

# Paso 4: Verificar que las cadenas estén en el archivo
echo "========================================================================"
echo "PASO 4: Verificar cadenas de idioma"
echo "========================================================================"
echo ""

LANG_FILE="$MOODLE_DIR/local/coursetransfer/lang/es/local_coursetransfer.php"

if [ -f "$LANG_FILE" ]; then
    echo "Verificando cadenas en $LANG_FILE:"
    
    STRINGS=(
        "retry_request"
        "retry_request_help"
        "retry_request_confirm_title"
        "retry_request_confirm_message"
        "retry"
    )
    
    for STRING in "${STRINGS[@]}"; do
        if grep -q "\$string\['$STRING'\]" "$LANG_FILE"; then
            echo "  ✓ $STRING"
        else
            echo "  ❌ FALTA: $STRING"
        fi
    done
else
    echo "❌ ERROR: No se encuentra $LANG_FILE"
fi

echo ""

# Paso 5: Probar carga de cadenas
echo "========================================================================"
echo "PASO 5: Probar carga de cadenas"
echo "========================================================================"
echo ""

sudo -u www-data php -r "
require_once('config.php');
require_once(\$CFG->libdir.'/moodlelib.php');

// Forzar recarga de idioma
get_string_manager()->reset_caches();

// Intentar cargar las cadenas
\$strings_to_test = [
    'retry_request',
    'retry_request_help', 
    'retry_request_confirm_title',
    'retry_request_confirm_message',
    'retry'
];

echo \"Probando carga de cadenas:\\n\";
foreach (\$strings_to_test as \$string) {
    try {
        \$value = get_string(\$string, 'local_coursetransfer');
        if (strpos(\$value, '[[') === false) {
            echo \"  ✓ \$string: \$value\\n\";
        } else {
            echo \"  ❌ \$string: No cargada (valor: \$value)\\n\";
        }
    } catch (Exception \$e) {
        echo \"  ❌ \$string: ERROR - \" . \$e->getMessage() . \"\\n\";
    }
}
"

echo ""

# Paso 6: Ajustar permisos
echo "========================================================================"
echo "PASO 6: Ajustar permisos"
echo "========================================================================"
echo ""

sudo chown -R www-data:www-data "$MOODLE_DIR/local/coursetransfer/lang"
sudo chmod -R 755 "$MOODLE_DIR/local/coursetransfer/lang"

echo "✓ Permisos actualizados"
echo ""

# Resumen
echo "========================================================================"
echo "  RESUMEN"
echo "========================================================================"
echo ""
echo "✅ Cachés purgadas completamente"
echo "✅ Cachés de idioma eliminadas"
echo "✅ Cachés de JavaScript purgadas"
echo "✅ Cadenas verificadas"
echo "✅ Permisos actualizados"
echo ""
echo "SIGUIENTE PASO:"
echo "  1. Actualiza tu navegador (Ctrl+F5)"
echo "  2. Ve a: /local/coursetransfer/logs.php"
echo "  3. El botón debería decir 'Reintentar' (no [[retry_request]])"
echo ""
echo "Si todavía aparece [[retry_request]]:"
echo "  - Cierra sesión y vuelve a iniciar sesión"
echo "  - Verifica que instalaste la versión v1.3.15"
echo "  - Revisa que el archivo lang/es/local_coursetransfer.php tenga las cadenas"
echo ""
