#!/bin/bash

################################################################################
# Script de Preparación para Producción
################################################################################
# Este script prepara el plugin para ser comprimido e instalado en producción
# Ejecutar ANTES de comprimir el plugin
################################################################################

echo "========================================================================"
echo "  PREPARACIÓN DEL PLUGIN PARA PRODUCCIÓN"
echo "========================================================================"
echo ""

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "📦 Plugin: $PLUGIN_DIR"
echo ""

# Verificar que estamos en el directorio correcto
if [ ! -f "$PLUGIN_DIR/version.php" ]; then
    echo "❌ ERROR: No se encontró version.php"
    echo "   ¿Estás en el directorio del plugin?"
    exit 1
fi

# Paso 1: Verificar estructura de directorios
echo "========================================================================"
echo "PASO 1: Verificar estructura de directorios"
echo "========================================================================"
echo ""

# Crear directorio build si no existe
if [ ! -d "$PLUGIN_DIR/amd/build" ]; then
    mkdir -p "$PLUGIN_DIR/amd/build"
    echo "✓ Directorio creado: amd/build/"
else
    echo "✓ Directorio existe: amd/build/"
fi

echo ""

# Paso 2: Copiar JavaScript como versión compilada
echo "========================================================================"
echo "PASO 2: Preparar módulos JavaScript"
echo "========================================================================"
echo ""

SRC_JS="$PLUGIN_DIR/amd/src/retry_request.js"
BUILD_JS="$PLUGIN_DIR/amd/build/retry_request.min.js"
MAP_JS="$PLUGIN_DIR/amd/build/retry_request.min.js.map"

if [ -f "$SRC_JS" ]; then
    # Copiar como minificado
    cp "$SRC_JS" "$BUILD_JS"
    echo "✓ Copiado: retry_request.min.js"
    
    # Crear mapa fuente básico
    echo '{"version":3,"file":"retry_request.min.js","sources":["../src/retry_request.js"],"names":[],"mappings":""}' > "$MAP_JS"
    echo "✓ Creado: retry_request.min.js.map"
else
    echo "❌ ERROR: No existe amd/src/retry_request.js"
    exit 1
fi

echo ""

# Paso 3: Verificar archivos críticos del plugin
echo "========================================================================"
echo "PASO 3: Verificar archivos críticos"
echo "========================================================================"
echo ""

CRITICAL_FILES=(
    "version.php"
    "db/services.php"
    "classes/external/frontend/retry_request_external.php"
    "classes/tables/logs_course_request_table.php"
    "amd/src/retry_request.js"
    "amd/build/retry_request.min.js"
    "lang/es/local_coursetransfer.php"
    "logs.php"
    "logs_detail.php"
)

ALL_OK=true

for FILE in "${CRITICAL_FILES[@]}"; do
    if [ -f "$PLUGIN_DIR/$FILE" ]; then
        echo "✓ $FILE"
    else
        echo "❌ FALTA: $FILE"
        ALL_OK=false
    fi
done

echo ""

if [ "$ALL_OK" = false ]; then
    echo "❌ ERROR: Faltan archivos críticos"
    exit 1
fi

# Paso 4: Verificar contenido de archivos clave
echo "========================================================================"
echo "PASO 4: Verificar contenido de archivos clave"
echo "========================================================================"
echo ""

# Verificar servicio web
if grep -q "local_coursetransfer_retry_failed_request" "$PLUGIN_DIR/db/services.php"; then
    echo "✓ Servicio web 'retry_failed_request' registrado"
else
    echo "❌ ERROR: Servicio web NO registrado en services.php"
    ALL_OK=false
fi

# Verificar método col_retry en tabla
if grep -q "function col_retry" "$PLUGIN_DIR/classes/tables/logs_course_request_table.php"; then
    echo "✓ Método col_retry() existe en tabla"
else
    echo "❌ ERROR: Método col_retry() NO existe en tabla"
    ALL_OK=false
fi

# Verificar columna 'retry' en tabla
if grep -q "'retry'" "$PLUGIN_DIR/classes/tables/logs_course_request_table.php"; then
    echo "✓ Columna 'retry' definida en tabla"
else
    echo "❌ ERROR: Columna 'retry' NO definida en tabla"
    ALL_OK=false
fi

# Verificar cadenas de idioma
LANG_FILE="$PLUGIN_DIR/lang/es/local_coursetransfer.php"
REQUIRED_STRINGS=(
    "retry_request"
    "retry_request_help"
    "retry_request_success"
    "retry_request_failed"
)

MISSING_STRINGS=0
for STRING in "${REQUIRED_STRINGS[@]}"; do
    if grep -q "\['$STRING'\]" "$LANG_FILE"; then
        echo "✓ Cadena: $STRING"
    else
        echo "❌ FALTA cadena: $STRING"
        MISSING_STRINGS=$((MISSING_STRINGS + 1))
        ALL_OK=false
    fi
done

echo ""

# Paso 5: Información del plugin
echo "========================================================================"
echo "PASO 5: Información del plugin"
echo "========================================================================"
echo ""

if [ -f "$PLUGIN_DIR/version.php" ]; then
    VERSION=$(grep '$plugin->version' "$PLUGIN_DIR/version.php" | grep -oE '[0-9]+' | head -1)
    RELEASE=$(grep '$plugin->release' "$PLUGIN_DIR/version.php" | sed -n "s/.*'\(.*\)'.*/\1/p")
    
    echo "Versión: $VERSION"
    echo "Release: $RELEASE"
fi

echo ""

# Paso 6: Mostrar tamaños
echo "========================================================================"
echo "PASO 6: Tamaños de archivos JavaScript"
echo "========================================================================"
echo ""

if [ -f "$BUILD_JS" ]; then
    SIZE_SRC=$(du -h "$SRC_JS" | cut -f1)
    SIZE_BUILD=$(du -h "$BUILD_JS" | cut -f1)
    
    echo "Fuente:    $SIZE_SRC  ($SRC_JS)"
    echo "Compilado: $SIZE_BUILD  ($BUILD_JS)"
fi

echo ""

# Resumen final
echo "========================================================================"
echo "  RESUMEN"
echo "========================================================================"
echo ""

if [ "$ALL_OK" = true ]; then
    echo "✅ El plugin está LISTO para producción"
    echo ""
    echo "Próximos pasos:"
    echo ""
    echo "  1. Comprimir el plugin:"
    echo "     cd $(dirname "$PLUGIN_DIR")"
    echo "     zip -r coursetransfer.zip coursetransfer/ -x '*.git*' -x '*.sh'"
    echo ""
    echo "  2. Subir a producción:"
    echo "     - Ve a: Administración > Plugins > Instalar plugins"
    echo "     - Sube: coursetransfer.zip"
    echo "     - Actualiza el plugin"
    echo ""
    echo "  3. Después de instalar:"
    echo "     - Las cachés se purgarán automáticamente"
    echo "     - La BD se actualizará automáticamente"
    echo "     - El botón aparecerá en logs.php"
    echo ""
    echo "El botón funcionará INMEDIATAMENTE después de la instalación ✨"
    echo ""
else
    echo "⚠️  ADVERTENCIA: Hay problemas que resolver"
    echo ""
    echo "Revisa los errores marcados con ❌ arriba"
    echo "NO comprimas el plugin hasta resolver los problemas"
fi

echo ""
echo "========================================================================"
