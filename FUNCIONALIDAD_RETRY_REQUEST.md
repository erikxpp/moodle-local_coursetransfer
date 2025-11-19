# Funcionalidad de Reprocesamiento de Cursos Fallidos

## 📋 Descripción

Se ha implementado una funcionalidad completa para **reprocesar cursos con errores** directamente desde la interfaz de logs, sin necesidad de usar el buscador y repetir todo el proceso manualmente.

## ✨ Características Implementadas

### 1. **Botón de "Reprocesar Solicitud"**
- Aparece automáticamente en la página de detalles de logs (`logs_detail.php`)
- Solo visible para solicitudes con `status = 0` (ERROR) y de tipo `COURSE`
- Color naranja (warning) para destacar visualmente
- Incluye ícono de refresh y descripción clara

### 2. **Limpieza Automática**
Antes de crear la nueva solicitud, el sistema limpia automáticamente:
- ✅ **Archivos huérfanos** del backup anterior
- ✅ **Tareas adhoc fallidas** relacionadas con la solicitud
- ✅ Registro en logs de todas las acciones de limpieza

### 3. **Nuevo Servicio Web**
- **Nombre**: `local_coursetransfer_retry_failed_request`
- **Clase**: `retry_request_external`
- **Método**: `retry_failed_request(int $requestid)`

### 4. **Trazabilidad Completa**
Cada retry genera logs detallados:
- `RETRY_INITIATED`: Cuando el usuario inicia el reprocesamiento
- `CLEANUP_FILE_DELETED`: Cada archivo eliminado
- `CLEANUP_TASK_DELETED`: Cada tarea eliminada
- `RETRY_SUCCESS`: Nueva solicitud creada exitosamente
- `CREATED_FROM_RETRY`: En la nueva solicitud, indica que fue creada como retry

## 🚀 Cómo Usar

### Desde la Interfaz Web

1. **Navegar a los logs**:
   - Ir a `Administración del sitio > Plugins > Local > Course Transfer > Logs`
   - O directamente: `/local/coursetransfer/logs.php`

2. **Seleccionar solicitud fallida**:
   - Buscar una solicitud con estado "Error"
   - Click en el botón de "ver detalles" (🔍)

3. **Reprocesar**:
   - En la página de detalles, verás el botón naranja **"🔄 Reprocesar Solicitud"**
   - Click en el botón
   - Confirmar en el diálogo de confirmación
   - El sistema:
     1. Limpia archivos y tareas fallidas
     2. Crea una nueva solicitud idéntica
     3. Redirige automáticamente a los detalles de la nueva solicitud

### Desde la Base de Datos (Consulta SQL)

Para ver qué solicitudes pueden ser reprocesadas:

```sql
SELECT
    r.id AS request_id,
    r.origin_course_fullname,
    r.origin_course_shortname,
    r.target_course_id,
    r.error_code,
    r.error_message,
    FROM_UNIXTIME(r.timecreated) AS created_date,
    
    -- Link directo a detalles
    CONCAT('/local/coursetransfer/logs_detail.php?requestid=', r.id) AS detail_url
    
FROM mdl_local_coursetransfer_request r

WHERE 
    r.type = 0  -- Solo cursos
    AND r.status = 0  -- Solo errores
    AND r.direction = 0  -- Solo peticiones (no respuestas)
    AND FROM_UNIXTIME(r.timecreated) > DATE_SUB(NOW(), INTERVAL 7 DAY)  -- Última semana
    
ORDER BY r.timecreated DESC;
```

## 🔍 Caso de Uso: Error por Archivo No Encontrado

Tu caso específico con el error `[11100] File not found! :28433332` se resuelve así:

### Problema Original:
1. ✅ Descarga exitosa (file_id: 28433332)
2. ❌ Fallo en primer intento de restore (error en course_format_ipgonetopic)
3. ❌ Retry automático falla porque el archivo ya no existe

### Solución con Retry:
1. **Click en "Reprocesar Solicitud"**
2. Sistema limpia el archivo huérfano (si existe)
3. Crea nueva solicitud desde cero
4. Nueva descarga del backup desde el origen
5. Nuevo intento de restauración con archivo fresco

## 📊 Logs Generados

Ejemplo de logs en una operación de retry:

```
[Original Request #1175]
├─ RETRY_INITIATED (info)
│  └─ User 123 initiated retry of failed request
├─ CLEANUP_FILE_DELETED (info)  
│  └─ Deleted orphaned backup file: backup_1763496658_1048.mbz
├─ CLEANUP_TASK_DELETED (info)
│  └─ Deleted failed adhoc task: restore_course_task
└─ RETRY_SUCCESS (success)
   └─ New request created successfully: Request ID 1250

[New Request #1250]
└─ CREATED_FROM_RETRY (info)
   └─ This request was created as retry of failed request 1175
```

## 🛡️ Validaciones de Seguridad

El sistema valida:
- ✅ Solo usuarios con permiso `local/coursetransfer:origin_restore_course`
- ✅ Solo solicitudes en estado `ERROR` (status = 0)
- ✅ Solo solicitudes de tipo `COURSE` (type = 0)
- ✅ La solicitud original debe existir
- ✅ El sitio origen debe estar configurado

## 📁 Archivos Modificados/Creados

### Nuevos Archivos:
```
classes/external/frontend/retry_request_external.php
amd/src/retry_request.js
```

### Archivos Modificados:
```
db/services.php                      (Nuevo servicio agregado)
logs_detail.php                      (Botón y JavaScript)
lang/es/local_coursetransfer.php    (11 nuevas strings)
```

## 🔧 Instalación/Actualización

1. **Subir archivos** al servidor
2. **Ejecutar upgrade** de Moodle:
   ```bash
   php admin/cli/upgrade.php
   ```
3. **Compilar JavaScript** (si usas AMD minificado):
   ```bash
   php admin/cli/purge_caches.php
   ```

## 💡 Ventajas vs Método Manual

| Aspecto | Método Manual | Con Botón Retry |
|---------|---------------|-----------------|
| **Tiempo** | ~5 minutos | ~10 segundos |
| **Pasos** | 7-8 pasos | 2 clicks |
| **Limpieza** | Manual/Olvidada | Automática |
| **Trazabilidad** | Ninguna | Logs completos |
| **Errores** | Fácil equivocarse | Imposible |
| **Parámetros** | Hay que recordarlos | Se replican exactos |

## ⚠️ Limitaciones Conocidas

- Solo funciona para **solicitudes de curso individual** (no categorías)
- Solo para solicitudes con **status = ERROR**
- No se puede retry de solicitudes de tipo `REMOVE`

## 🐛 Troubleshooting

### El botón no aparece
- Verificar que la solicitud esté en estado ERROR (`status = 0`)
- Verificar que sea de tipo COURSE (`type = 0`)
- Limpiar caché de Moodle

### Error "Only error requests can be retried"
- La solicitud ya fue procesada o está en progreso
- Verificar el estado actual en la BD

### Error de permisos
- Verificar que el usuario tenga el capability `local/coursetransfer:origin_restore_course`

## 📞 Soporte

Para reportar problemas o sugerencias relacionadas con esta funcionalidad, incluir:
1. ID de la solicitud original
2. Código de error del fallo
3. Logs de la página de detalles
4. Screenshot del error (si aplica)

---

**Fecha de Implementación**: 18 de noviembre de 2025  
**Versión**: Compatible con plugin coursetransfer v1.3.4+
