# 🎯 SOLUCIÓN COMPLETA: Botón Retry Ahora Visible en la Tabla Principal

## 📍 Problema Resuelto

Antes solo podías ver el botón "Reintentar" entrando a los detalles de cada solicitud (`logs_detail.php`).  
**Ahora el botón aparece directamente en la tabla principal de logs** (`logs.php`) para reintentos más rápidos.

---

## ✅ Cambios Implementados

### 1. Nueva Columna en la Tabla de Logs

**Archivo modificado**: `/classes/tables/logs_course_request_table.php`

#### Cambios realizados:
1. **Línea ~75-90**: Añadida columna `'retry'` al final de `define_columns()`
2. **Línea ~95-111**: Añadido header `get_string('retry_request', 'local_coursetransfer')`
3. **Línea ~325-348**: Nuevo método `col_retry()`:
   ```php
   public function col_retry(stdClass $row): string {
       global $PAGE;
       
       // Solo mostrar botón si estado=ERROR y tipo=COURSE
       if ($row->status == coursetransfer_request::STATUS_ERROR && 
           $row->type == coursetransfer_request::TYPE_COURSE) {
           
           $PAGE->requires->js_call_amd('local_coursetransfer/retry_request', 'init');
           
           return '<button type="button" class="btn btn-warning btn-sm retry-request-btn" 
                       data-request-id="' . $row->id . '" 
                       title="' . get_string('retry_request_help', 'local_coursetransfer') . '">' .
                   '<i class="fa fa-refresh"></i> ' . get_string('retry_request', 'local_coursetransfer') .
                   '</button>';
       }
       
       return '';
   }
   ```

---

## 🚀 Cómo Activar

### Opción 1: Script Automatizado (Recomendado)

```bash
cd /var/www/html/moodle/local/coursetransfer
bash activate_retry_column.sh
```

Este script:
- ✅ Purga todas las cachés de Moodle
- ✅ Actualiza la base de datos
- ✅ Verifica que todos los archivos estén presentes
- ✅ Valida las cadenas de idioma
- ✅ Compila JavaScript AMD (si Grunt está disponible)

### Opción 2: Manual

```bash
cd /var/www/html/moodle

# 1. Purgar cachés
php admin/cli/purge_caches.php

# 2. Actualizar base de datos
php admin/cli/upgrade.php --non-interactive

# 3. Refrescar navegador (Ctrl+F5)
```

---

## 📊 Resultado Visual

### ANTES (solo en logs_detail.php):
```
Tabla Principal (logs.php):
┌────┬──────────────┬─────────┬─────────────────┐
│ ID │ Sitio Origen │ Estado  │ Ver logs        │
├────┼──────────────┼─────────┼─────────────────┤
│ 80 │ aula.es      │ ERROR   │ 🔍 Ver logs     │
│1175│ aula.es      │ ERROR   │ 🔍 Ver logs     │  ❌ No hay botón
└────┴──────────────┴─────────┴─────────────────┘

Para reintentar: Clic en "Ver logs" → Entrar a detalles → Clic en botón
```

### AHORA (en ambos lugares):
```
Tabla Principal (logs.php):
┌────┬──────────────┬─────────┬─────────────────┬────────────────────┐
│ ID │ Sitio Origen │ Estado  │ Ver logs        │ Reintentar         │
├────┼──────────────┼─────────┼─────────────────┼────────────────────┤
│ 80 │ aula.es      │ ERROR   │ 🔍 Ver logs     │ 🔄 Reintentar     │
│1175│ aula.es      │ ERROR   │ 🔍 Ver logs     │ 🔄 Reintentar     │  ✅ UN SOLO CLIC
└────┴──────────────┴─────────┴─────────────────┴────────────────────┘

Para reintentar: Clic directo en "Reintentar" → Confirmar → Listo
```

---

## 🎯 Dónde Aparece el Botón

### 1️⃣ Tabla Principal (`logs.php`)
- **URL**: `/local/coursetransfer/logs.php`
- **Vista**: Lista de todas las solicitudes
- **Columna**: "Reintentar" (última columna)
- **Ventaja**: Reintentos rápidos sin entrar a detalles

### 2️⃣ Página de Detalles (`logs_detail.php`)
- **URL**: `/local/coursetransfer/logs_detail.php?requestid=XXX`
- **Vista**: Detalles completos de una solicitud
- **Ubicación**: Parte superior, antes de los logs
- **Ventaja**: Contexto completo del error

---

## 🔍 Condiciones para Mostrar el Botón

El botón **solo aparece** cuando:

1. ✅ **Estado = ERROR** (`status = 0`)
2. ✅ **Tipo = COURSE** (`type = 0`)

### ❌ No aparece si:
- Estado es `COMPLETED`, `IN_PROGRESS`, etc.
- Tipo es `CATEGORY` (transferencia por categoría)
- Tipo es `REMOVE_COURSE` (eliminación)

---

## 📁 Archivos Modificados

### Backend:
```
✅ /classes/tables/logs_course_request_table.php  (Nueva columna)
✅ /classes/external/frontend/retry_request_external.php  (Ya existía)
✅ /db/services.php  (Ya existía)
```

### Frontend:
```
✅ /amd/src/retry_request.js  (Ya existía)
```

### Idioma:
```
✅ /lang/es/local_coursetransfer.php  (Ya existía)
```

### Nuevos Scripts:
```
✅ activate_retry_column.sh  (Script de activación)
✅ UBICACION_BOTON_RETRY.md  (Documentación de ubicaciones)
✅ SOLUCION_BOTON_RETRY_TABLA.md  (Este archivo)
```

---

## 🧪 Cómo Probar

1. **Ejecuta el script de activación**:
   ```bash
   cd /var/www/html/moodle/local/coursetransfer
   bash activate_retry_column.sh
   ```

2. **Accede a la tabla de logs**:
   ```
   Administración del sitio > Plugins > Transferencia de Cursos > Logs
   O directamente: https://tu-moodle.com/local/coursetransfer/logs.php
   ```

3. **Busca solicitudes con estado ERROR**:
   - Deberías ver una nueva columna "Reintentar" al final
   - Solo las filas con estado ERROR tendrán el botón

4. **Haz clic en "Reintentar"**:
   - Aparecerá un diálogo de confirmación
   - Confirma y espera el resultado
   - La página se recargará automáticamente

---

## 🛠️ Troubleshooting

### El botón no aparece

1. **Verifica el estado de la solicitud**:
   ```sql
   SELECT id, status, type FROM mdl_local_coursetransfer_request WHERE id = 1175;
   ```
   - `status` debe ser `0` (ERROR)
   - `type` debe ser `0` (COURSE)

2. **Purga cachés manualmente**:
   ```bash
   cd /var/www/html/moodle
   php admin/cli/purge_caches.php
   ```

3. **Refresca el navegador**:
   - Presiona `Ctrl+F5` (Windows/Linux)
   - Presiona `Cmd+Shift+R` (Mac)

4. **Verifica que existe la columna**:
   - Abre `/classes/tables/logs_course_request_table.php`
   - Busca `'retry'` en `define_columns()` (línea ~89)
   - Busca el método `col_retry()` (línea ~325+)

### El botón no hace nada al hacer clic

1. **Verifica JavaScript en consola del navegador**:
   - Presiona `F12`
   - Ve a la pestaña "Console"
   - Busca errores relacionados con `retry_request`

2. **Verifica que el servicio web está registrado**:
   ```bash
   grep -n "retry_failed_request" /var/www/html/moodle/local/coursetransfer/db/services.php
   ```
   Debe aparecer `'local_coursetransfer_retry_failed_request'`

3. **Recompila JavaScript AMD**:
   ```bash
   cd /var/www/html/moodle
   grunt amd
   ```
   (Solo si tienes Grunt instalado)

---

## 📈 Ventajas de Esta Implementación

| Característica | Antes | Ahora |
|----------------|-------|-------|
| **Clics necesarios** | 3-4 clics | 1 clic |
| **Ubicaciones** | Solo detalles | Tabla + Detalles |
| **Contexto visual** | Mínimo | Completo |
| **Reintentos masivos** | Lento | Rápido |
| **Experiencia de usuario** | Regular | Excelente |

---

## 🎉 Resumen

✅ **Botón "Reintentar" ahora visible en la tabla principal**  
✅ **Un solo clic para reintentar transferencias fallidas**  
✅ **Compatible con la implementación existente en logs_detail.php**  
✅ **Script de activación automatizado incluido**  
✅ **Documentación completa de ubicaciones**  

---

## 📞 Siguiente Paso

Ejecuta el script de activación y accede a `/local/coursetransfer/logs.php` para ver el botón en acción:

```bash
cd /var/www/html/moodle/local/coursetransfer
bash activate_retry_column.sh
```

¡Ahora podrás reintentar cursos fallidos con un solo clic directamente desde la lista! 🚀
