#!/bin/bash

################################################################################
# Script para ejecutar EN EL SERVIDOR (EC2)
################################################################################
# Este script debe copiarse y ejecutarse en el servidor EC2 de Moodle
################################################################################

echo "========================================================================"
echo "  ACTIVACIÓN DEL BOTÓN RETRY EN SERVIDOR"
echo "========================================================================"
echo ""

# Ruta de Moodle en el servidor
MOODLE_DIR="/var/www/html/moodle"
PLUGIN_DIR="$MOODLE_DIR/local/coursetransfer"

# Validar que existe Moodle
if [ ! -f "$MOODLE_DIR/config.php" ]; then
    echo "❌ ERROR: No se encontró Moodle en $MOODLE_DIR"
    echo "   Modifica MOODLE_DIR en este script si está en otra ruta"
    exit 1
fi

echo "✓ Moodle encontrado en: $MOODLE_DIR"
echo ""

# Verificar que el archivo JavaScript compilado existe
JS_MIN="$PLUGIN_DIR/amd/build/retry_request.min.js"
if [ ! -f "$JS_MIN" ]; then
    echo "❌ ERROR: No existe el archivo JavaScript compilado"
    echo "   Ruta esperada: $JS_MIN"
    echo ""
    echo "   Debes copiar primero los archivos al servidor:"
    echo "   - amd/src/retry_request.js"
    echo "   - amd/build/retry_request.min.js"
    echo "   - amd/build/retry_request.min.js.map"
    exit 1
fi

echo "✓ Archivo JavaScript encontrado"
echo ""

# Paso 1: Purgar cachés
echo "========================================================================"
echo "PASO 1: Purgar cachés"
echo "========================================================================"
echo ""

cd "$MOODLE_DIR" || exit 1
sudo -u www-data php admin/cli/purge_caches.php

if [ $? -eq 0 ]; then
    echo "✓ Cachés purgadas"
else
    echo "❌ ERROR al purgar cachés"
    exit 1
fi

echo ""

# Paso 2: Actualizar base de datos
echo "========================================================================"
echo "PASO 2: Actualizar base de datos"
echo "========================================================================"
echo ""

sudo -u www-data php admin/cli/upgrade.php --non-interactive

if [ $? -eq 0 ]; then
    echo "✓ Base de datos actualizada"
else
    echo "⚠️  Advertencia en actualización"
fi

echo ""

# Paso 3: Verificar permisos
echo "========================================================================"
echo "PASO 3: Verificar permisos"
echo "========================================================================"
echo ""

sudo chown -R www-data:www-data "$PLUGIN_DIR/amd"
sudo chmod -R 755 "$PLUGIN_DIR/amd"

echo "✓ Permisos actualizados"
echo ""

# Paso 4: Información
echo "========================================================================"
echo "  ACTIVACIÓN COMPLETADA"
echo "========================================================================"
echo ""
echo "El botón 'Reintentar' ya debería funcionar:"
echo ""
echo "  1. Actualiza el navegador (Ctrl+F5)"
echo "  2. Ve a: Logs de Transferencias"
echo "  3. Busca una fila con estado ERROR"
echo "  4. Haz clic en 'Reintentar'"
echo ""
echo "Si no funciona, verifica la consola del navegador (F12)"
echo ""
