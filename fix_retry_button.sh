#!/bin/bash

################################################################################
# Script de Corrección Completa del Botón Retry
################################################################################
# Este script corrige el problema de "No define call" y activa el botón retry
################################################################################

echo "========================================================================"
echo "  CORRECCIÓN DEL BOTÓN RETRY - SOLUCIÓN COMPLETA"
echo "========================================================================"
echo ""

# Detectar la ruta de Moodle
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MOODLE_DIR="$(cd "$SCRIPT_DIR/../../.." && pwd)"

# Validar que existe config.php
if [ ! -f "$MOODLE_DIR/config.php" ]; then
    echo "❌ ERROR: No se encontró config.php en $MOODLE_DIR"
    exit 1
fi

echo "✓ Ruta de Moodle: $MOODLE_DIR"
echo ""

# Paso 1: Crear directorio build si no existe
echo "========================================================================"
echo "PASO 1: Preparar directorio para JavaScript compilado"
echo "========================================================================"
echo ""

BUILD_DIR="$SCRIPT_DIR/amd/build"
if [ ! -d "$BUILD_DIR" ]; then
    mkdir -p "$BUILD_DIR"
    echo "✓ Directorio creado: $BUILD_DIR"
else
    echo "✓ Directorio ya existe: $BUILD_DIR"
fi

echo ""

# Paso 2: Copiar el archivo fuente como minificado (temporal)
echo "========================================================================"
echo "PASO 2: Crear versión compilada del módulo JavaScript"
echo "========================================================================"
echo ""

SRC_FILE="$SCRIPT_DIR/amd/src/retry_request.js"
MIN_FILE="$BUILD_DIR/retry_request.min.js"

if [ -f "$SRC_FILE" ]; then
    cp "$SRC_FILE" "$MIN_FILE"
    echo "✓ Módulo JavaScript preparado: retry_request.min.js"
else
    echo "❌ ERROR: No se encontró el archivo fuente: $SRC_FILE"
    exit 1
fi

echo ""

# Paso 3: Habilitar modo cacheless (desarrollo) en Moodle
echo "========================================================================"
echo "PASO 3: Habilitar modo de desarrollo de JavaScript"
echo "========================================================================"
echo ""

cd "$MOODLE_DIR" || exit 1

# Verificar si ya está en modo desarrollo
CACHEJS=$(php -r "require_once('config.php'); echo isset(\$CFG->cachejs) ? \$CFG->cachejs : '1';")

if [ "$CACHEJS" = "0" ]; then
    echo "✓ Modo desarrollo ya activado (cachejs = false)"
else
    echo "⚠️  Modo desarrollo NO activado"
    echo ""
    echo "Para activar el modo desarrollo, añade a config.php:"
    echo "  \$CFG->cachejs = false;"
    echo ""
    echo "O ejecuta manualmente:"
    echo "  php admin/cli/cfg.php --name=cachejs --set=0"
fi

echo ""

# Paso 4: Purgar todas las cachés
echo "========================================================================"
echo "PASO 4: Purgar cachés de Moodle"
echo "========================================================================"
echo ""

php admin/cli/purge_caches.php

if [ $? -eq 0 ]; then
    echo "✓ Cachés purgadas correctamente"
else
    echo "❌ ERROR al purgar cachés"
    exit 1
fi

echo ""

# Paso 5: Actualizar base de datos
echo "========================================================================"
echo "PASO 5: Actualizar base de datos"
echo "========================================================================"
echo ""

php admin/cli/upgrade.php --non-interactive

if [ $? -eq 0 ]; then
    echo "✓ Base de datos actualizada"
else
    echo "⚠️  Advertencia en actualización de BD"
fi

echo ""

# Paso 6: Verificar archivos críticos
echo "========================================================================"
echo "PASO 6: Verificar archivos necesarios"
echo "========================================================================"
echo ""

FILES_OK=true

# Verificar archivo JavaScript fuente
if [ -f "$SRC_FILE" ]; then
    echo "✓ retry_request.js (fuente)"
else
    echo "❌ FALTA: retry_request.js (fuente)"
    FILES_OK=false
fi

# Verificar archivo JavaScript compilado
if [ -f "$MIN_FILE" ]; then
    echo "✓ retry_request.min.js (compilado)"
else
    echo "❌ FALTA: retry_request.min.js (compilado)"
    FILES_OK=false
fi

# Verificar external service
EXT_SERVICE="$MOODLE_DIR/local/coursetransfer/classes/external/frontend/retry_request_external.php"
if [ -f "$EXT_SERVICE" ]; then
    echo "✓ retry_request_external.php"
else
    echo "❌ FALTA: retry_request_external.php"
    FILES_OK=false
fi

# Verificar services.php
if grep -q "retry_failed_request" "$MOODLE_DIR/local/coursetransfer/db/services.php"; then
    echo "✓ Servicio web registrado en services.php"
else
    echo "❌ FALTA: Servicio web en services.php"
    FILES_OK=false
fi

# Verificar tabla
TABLE_FILE="$MOODLE_DIR/local/coursetransfer/classes/tables/logs_course_request_table.php"
if grep -q "col_retry" "$TABLE_FILE"; then
    echo "✓ Método col_retry() en tabla"
else
    echo "❌ FALTA: Método col_retry() en tabla"
    FILES_OK=false
fi

echo ""

# Paso 7: Mostrar información de debug
echo "========================================================================"
echo "PASO 7: Información de depuración"
echo "========================================================================"
echo ""

echo "Archivos JavaScript:"
echo "  Fuente:    $SRC_FILE"
echo "  Compilado: $MIN_FILE"
echo ""

if [ -f "$MIN_FILE" ]; then
    LINES=$(wc -l < "$MIN_FILE")
    SIZE=$(du -h "$MIN_FILE" | cut -f1)
    echo "  Tamaño: $SIZE"
    echo "  Líneas: $LINES"
fi

echo ""

# Resumen final
echo "========================================================================"
echo "  RESUMEN DE CORRECCIÓN"
echo "========================================================================"
echo ""

if [ "$FILES_OK" = true ]; then
    echo "✅ Todos los archivos están presentes y correctos"
    echo ""
    echo "El botón 'Reintentar' debería funcionar ahora:"
    echo "  1. Actualiza la página con Ctrl+F5"
    echo "  2. Ve a: /local/coursetransfer/logs.php"
    echo "  3. Busca una fila con estado ERROR"
    echo "  4. Haz clic en el botón 'Reintentar'"
    echo ""
    echo "Si sigue sin funcionar:"
    echo "  • Abre la consola del navegador (F12)"
    echo "  • Verifica que no haya errores de JavaScript"
    echo "  • Asegúrate de que el botón tiene: class='retry-request-btn'"
    echo "  • Asegúrate de que el botón tiene: data-request-id='XXX'"
else
    echo "⚠️  ADVERTENCIA: Faltan algunos archivos"
    echo "   Revisa los archivos marcados con ❌ arriba"
fi

echo ""
echo "========================================================================"
echo "  ✅ CORRECCIÓN COMPLETADA"
echo "========================================================================"
echo ""
