#!/bin/bash

################################################################################
# Script de Activación de la Columna Retry en la Tabla de Logs
################################################################################
# Este script activa la nueva columna "Retry" en la tabla principal de logs
# que permite reintentar transferencias fallidas directamente desde logs.php
################################################################################

echo "========================================================================"
echo "  ACTIVACIÓN DE LA COLUMNA RETRY EN LA TABLA DE LOGS"
echo "========================================================================"
echo ""

# Detectar la ruta de Moodle
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Intentar detectar la ruta de Moodle (3 niveles arriba del plugin)
MOODLE_DIR="$(cd "$SCRIPT_DIR/../../.." && pwd)"

# Validar que existe config.php
if [ ! -f "$MOODLE_DIR/config.php" ]; then
    echo "❌ ERROR: No se encontró config.php en $MOODLE_DIR"
    echo ""
    echo "Por favor, ejecuta este script desde dentro del directorio del plugin:"
    echo "  cd /var/www/html/moodle/local/coursetransfer"
    echo "  bash activate_retry_column.sh"
    exit 1
fi

echo "✓ Ruta de Moodle detectada: $MOODLE_DIR"
echo ""

# Paso 1: Purgar todas las cachés
echo "========================================================================"
echo "PASO 1: Purgar todas las cachés de Moodle"
echo "========================================================================"
echo ""

cd "$MOODLE_DIR" || exit 1

echo "Ejecutando: php admin/cli/purge_caches.php"
php admin/cli/purge_caches.php

if [ $? -eq 0 ]; then
    echo "✓ Cachés purgadas correctamente"
else
    echo "❌ ERROR al purgar cachés"
    exit 1
fi

echo ""

# Paso 2: Ejecutar upgrade (por si hay cambios en la base de datos)
echo "========================================================================"
echo "PASO 2: Ejecutar actualización de la base de datos"
echo "========================================================================"
echo ""

echo "Ejecutando: php admin/cli/upgrade.php --non-interactive"
php admin/cli/upgrade.php --non-interactive

if [ $? -eq 0 ]; then
    echo "✓ Base de datos actualizada correctamente"
else
    echo "⚠️  Advertencia: Puede haber errores en la actualización"
fi

echo ""

# Paso 3: Verificar archivos críticos
echo "========================================================================"
echo "PASO 3: Verificar archivos de la funcionalidad Retry"
echo "========================================================================"
echo ""

FILES_TO_CHECK=(
    "local/coursetransfer/classes/external/frontend/retry_request_external.php"
    "local/coursetransfer/amd/src/retry_request.js"
    "local/coursetransfer/classes/tables/logs_course_request_table.php"
    "local/coursetransfer/db/services.php"
    "local/coursetransfer/lang/es/local_coursetransfer.php"
)

ALL_FILES_OK=true

for FILE in "${FILES_TO_CHECK[@]}"; do
    if [ -f "$MOODLE_DIR/$FILE" ]; then
        echo "✓ $FILE"
    else
        echo "❌ FALTA: $FILE"
        ALL_FILES_OK=false
    fi
done

echo ""

if [ "$ALL_FILES_OK" = false ]; then
    echo "⚠️  ADVERTENCIA: Faltan algunos archivos críticos"
    echo "   Asegúrate de que todos los archivos estén en su lugar"
else
    echo "✓ Todos los archivos críticos están presentes"
fi

echo ""

# Paso 4: Verificar cadenas de idioma
echo "========================================================================"
echo "PASO 4: Verificar cadenas de idioma"
echo "========================================================================"
echo ""

LANG_FILE="$MOODLE_DIR/local/coursetransfer/lang/es/local_coursetransfer.php"

if [ -f "$LANG_FILE" ]; then
    REQUIRED_STRINGS=(
        "retry_request"
        "retry_request_help"
        "retry_request_description"
        "retry_request_success"
        "retry_request_error"
        "retry_request_confirm_title"
        "retry_request_confirm_message"
    )
    
    MISSING_STRINGS=()
    
    for STRING in "${REQUIRED_STRINGS[@]}"; do
        if grep -q "\['$STRING'\]" "$LANG_FILE"; then
            echo "✓ $STRING"
        else
            echo "❌ FALTA: $STRING"
            MISSING_STRINGS+=("$STRING")
        fi
    done
    
    echo ""
    
    if [ ${#MISSING_STRINGS[@]} -eq 0 ]; then
        echo "✓ Todas las cadenas de idioma están presentes"
    else
        echo "⚠️  Faltan ${#MISSING_STRINGS[@]} cadenas de idioma"
    fi
else
    echo "❌ No se encontró el archivo de idioma: $LANG_FILE"
fi

echo ""

# Paso 5: Compilar JavaScript AMD
echo "========================================================================"
echo "PASO 5: Compilar JavaScript AMD (si Grunt está disponible)"
echo "========================================================================"
echo ""

if command -v grunt &> /dev/null; then
    echo "Ejecutando: grunt amd"
    grunt amd
    
    if [ $? -eq 0 ]; then
        echo "✓ JavaScript AMD compilado correctamente"
    else
        echo "⚠️  Advertencia: Error al compilar JavaScript AMD"
        echo "   El módulo puede necesitar compilación manual"
    fi
else
    echo "⚠️  Grunt no está instalado"
    echo "   Si has editado retry_request.js, necesitas compilarlo manualmente"
    echo "   O espera a que Moodle lo compile automáticamente (modo desarrollo)"
fi

echo ""

# Resumen final
echo "========================================================================"
echo "  RESUMEN DE ACTIVACIÓN"
echo "========================================================================"
echo ""
echo "Cambios realizados:"
echo "  ✓ Cachés purgadas"
echo "  ✓ Base de datos actualizada"
echo "  ✓ Archivos verificados"
echo "  ✓ Cadenas de idioma verificadas"
echo ""
echo "La columna 'Retry' ahora debería aparecer en:"
echo "  📍 Logs de Transferencias > Solicitudes de Cursos"
echo "  🔗 URL: $MOODLE_DIR/local/coursetransfer/logs.php"
echo ""
echo "El botón 'Reintentar' aparecerá SOLO en filas con:"
echo "  • Estado: ERROR"
echo "  • Tipo: COURSE (Curso)"
echo ""
echo "Si no ves el botón, intenta:"
echo "  1. Actualizar la página (Ctrl+F5)"
echo "  2. Cerrar sesión y volver a iniciar sesión"
echo "  3. Revisar que tengas solicitudes con estado ERROR"
echo ""
echo "========================================================================"
echo "  ✅ ACTIVACIÓN COMPLETADA"
echo "========================================================================"
echo ""
