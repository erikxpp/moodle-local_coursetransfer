# Sistema de Logging Detallado - CourseTransfer Plugin v1.3.0

## 📋 Resumen de Implementación

Se ha implementado un sistema completo de logging detallado para el plugin CourseTransfer que permite rastrear cada paso del proceso de transferencia de cursos entre instancias de Moodle.

## 🎯 Objetivos Cumplidos

✅ **Sistema de Logging Granular**: Registro de cada paso del proceso (backup, descarga, restauración, limpieza)
✅ **Interfaz de Visualización**: Página dedicada para ver logs con timeline visual
✅ **Detección de Tareas Atascadas**: Identificación de transferencias que quedaron "en progreso" indefinidamente
✅ **Enlaces a Tareas Adhoc**: Acceso directo a los logs de las tareas de Moodle
✅ **Información Detallada**: Timestamps, mensajes, códigos de error, datos adicionales (tamaño de archivos, duración, etc.)

## 🗄️ Cambios en Base de Datos

### Nueva Tabla: `mdl_local_coursetransfer_log`

```sql
CREATE TABLE mdl_local_coursetransfer_log (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    request_id BIGINT NOT NULL,
    direction TINYINT NOT NULL,  -- 0=origin, 1=target
    action VARCHAR(50) NOT NULL,  -- backup_started, download_completed, etc.
    status VARCHAR(20) NOT NULL,  -- info, success, warning, error
    message TEXT,
    error_code VARCHAR(20),
    task_id BIGINT,  -- FK to mdl_task_adhoc
    task_classname VARCHAR(255),
    extra_data TEXT,  -- JSON
    timecreated BIGINT NOT NULL,
    FOREIGN KEY (request_id) REFERENCES mdl_local_coursetransfer_request(id),
    INDEX (request_id),
    INDEX (action),
    INDEX (status)
);
```

## 📁 Archivos Creados/Modificados

### Nuevos Archivos

1. **`classes/coursetransfer_logger.php`**
   - Clase helper para logging
   - Métodos estáticos para registrar eventos
   - Constantes para acciones y estados
   - Métodos de consulta de logs

2. **`logs_detail.php`**
   - Interfaz web para visualizar logs detallados
   - Timeline visual de eventos
   - Lista de tareas adhoc asociadas
   - Detección de tareas atascadas
   - Enlaces directos a logs de tareas

3. **`db/upgrade.php`** (actualizado)
   - Script de migración para crear tabla de logs
   - Versión 2024110200

### Archivos Modificados

1. **`classes/task/create_backup_course_task.php`**
   - Logging de inicio de backup
   - Logging de backup completado con éxito
   - Logging de errores de backup

2. **`classes/task/download_file_course_task.php`**
   - Logging de inicio de descarga
   - Logging de descarga completada
   - Logging de errores de descarga

3. **`classes/task/restore_course_task.php`**
   - Logging de inicio de restauración
   - Logging de restauración completada
   - Logging de errores de restauración

4. **`db/install.xml`**
   - Definición de tabla `local_coursetransfer_log`

5. **`version.php`**
   - Versión actualizada: 2024110200 (1.3.0)

## 🎨 Interfaz de Usuario

### Página: `logs_detail.php?requestid=X`

#### Secciones:

1. **Resumen de Request**
   - ID, dirección, estado
   - Curso origen y destino
   - URL del sitio
   - Fechas de creación y modificación
   - ⚠️ Advertencia si la tarea está atascada

2. **Tareas Adhoc**
   - Lista de todas las tareas relacionadas
   - Estado de cada tarea (Pending, Running, Failed)
   - Enlaces directos a logs de tareas (`/admin/tasklogs.php`)
   - Información de intentos fallidos

3. **Timeline de Logs**
   - **Pestaña Origen**: Logs del servidor de origen
   - **Pestaña Destino**: Logs del servidor de destino
   - Timeline visual con iconos de estado
   - Mensajes detallados
   - Códigos de error
   - Datos adicionales (JSON)
   - Enlaces a tareas específicas

## 🔍 Acciones de Logging

### Constantes Definidas en `coursetransfer_logger.php`:

```php
// Actions
ACTION_REQUEST_CREATED = 'request_created'
ACTION_BACKUP_STARTED = 'backup_started'
ACTION_BACKUP_COMPLETED = 'backup_completed'
ACTION_BACKUP_FAILED = 'backup_failed'
ACTION_DOWNLOAD_STARTED = 'download_started'
ACTION_DOWNLOAD_PROGRESS = 'download_progress'
ACTION_DOWNLOAD_COMPLETED = 'download_completed'
ACTION_DOWNLOAD_FAILED = 'download_failed'
ACTION_RESTORE_STARTED = 'restore_started'
ACTION_RESTORE_COMPLETED = 'restore_completed'
ACTION_RESTORE_FAILED = 'restore_failed'
ACTION_CLEANUP_STARTED = 'cleanup_started'
ACTION_CLEANUP_COMPLETED = 'cleanup_completed'
ACTION_CLEANUP_FAILED = 'cleanup_failed'
ACTION_TASK_CREATED = 'task_created'
ACTION_TASK_STARTED = 'task_started'
ACTION_TASK_COMPLETED = 'task_completed'
ACTION_TASK_FAILED = 'task_failed'

// Status
STATUS_INFO = 'info'
STATUS_SUCCESS = 'success'
STATUS_WARNING = 'warning'
STATUS_ERROR = 'error'

// Direction
DIRECTION_ORIGIN = 0
DIRECTION_TARGET = 1
```

## 💡 Uso de la Clase Logger

### Ejemplo Básico:

```php
use local_coursetransfer\coursetransfer_logger;

// Log simple
coursetransfer_logger::info(
    $requestid,
    coursetransfer_logger::DIRECTION_ORIGIN,
    coursetransfer_logger::ACTION_BACKUP_STARTED,
    'Starting backup process'
);

// Log con datos adicionales
coursetransfer_logger::success(
    $requestid,
    coursetransfer_logger::DIRECTION_TARGET,
    coursetransfer_logger::ACTION_DOWNLOAD_COMPLETED,
    'Download completed successfully',
    [
        'file_size' => 700000000,
        'duration_seconds' => 120,
        'file_url' => 'https://...'
    ]
);

// Log de error
coursetransfer_logger::error(
    $requestid,
    coursetransfer_logger::DIRECTION_ORIGIN,
    coursetransfer_logger::ACTION_BACKUP_FAILED,
    'Backup failed: insufficient disk space',
    '13001',
    ['disk_available' => '1GB', 'disk_required' => '2GB']
);

// Log de tarea
coursetransfer_logger::log_task_started(
    $requestid,
    coursetransfer_logger::DIRECTION_TARGET,
    $taskid,
    get_class($this),
    'Task started'
);
```

### Consultas:

```php
// Obtener todos los logs de un request
$logs = coursetransfer_logger::get_logs($requestid);

// Obtener logs agrupados por dirección
$logsByDirection = coursetransfer_logger::get_logs_by_direction($requestid);

// Verificar si hay errores
$hasErrors = coursetransfer_logger::has_errors($requestid);

// Obtener último log
$latestLog = coursetransfer_logger::get_latest_log($requestid);
```

## 🔧 Detección de Tareas Atascadas

El sistema detecta automáticamente requests que:
- Tienen status "En progreso" (STATUS_IN_PROGRESS, STATUS_BACKUP, STATUS_DOWNLOADED)
- NO tienen tareas adhoc pendientes o en ejecución

Cuando se detecta, se muestra una advertencia en la interfaz con el mensaje:
> ⚠️ Esta transferencia parece estar atascada. No hay tareas activas pero el estado no se ha completado.

## 📊 Datos Adicionales (extra_data)

El campo `extra_data` almacena información contextual en formato JSON:

### Ejemplos:

**Backup completado:**
```json
{
    "file_url": "https://aula.erikxp.com/webservice/pluginfile.php/398/...",
    "file_size": 700008000,
    "backup_id": "abc123",
    "duration_seconds": 45
}
```

**Descarga completada:**
```json
{
    "file_size": 700008000,
    "filename": "local_coursetransfer_7_1730498550.mbz",
    "context_id": 398
}
```

**Tarea creada:**
```json
{
    "adhoc_task_id": 12345,
    "backup_id": "abc123"
}
```

## 🚀 Instalación/Actualización

1. **Copiar archivos al servidor**
2. **Ejecutar upgrade de Moodle**:
   ```bash
   php admin/cli/upgrade.php
   ```
3. **Verificar que la tabla se creó**:
   ```sql
   SHOW TABLES LIKE '%coursetransfer_log%';
   ```

## 📝 Strings de Traducción Necesarios

### lang/es/local_coursetransfer.php:

```php
$string['request_logs_detail'] = 'Detalle de Logs de Transferencia';
$string['request_details'] = 'Detalles de la Solicitud';
$string['adhoc_tasks'] = 'Tareas Ad-hoc';
$string['logs_timeline'] = 'Línea de Tiempo de Logs';
$string['origin_logs'] = 'Logs del Origen';
$string['target_logs'] = 'Logs del Destino';
$string['no_logs_found'] = 'No se encontraron logs para esta transferencia';
$string['request_stuck'] = 'Esta transferencia parece estar atascada. No hay tareas activas pero el estado no se ha completado.';
$string['view_logs'] = 'Ver Logs';
$string['view_task_log'] = 'Ver Log de Tarea';
$string['back_to_logs'] = 'Volver a Logs';
$string['task_id'] = 'ID de Tarea';
$string['task_name'] = 'Nombre de Tarea';
$string['classname'] = 'Clase';
$string['next_run'] = 'Próxima Ejecución';
$string['fail_delay'] = 'Retraso por Fallo';
$string['additional_info'] = 'Información Adicional';
$string['error_code'] = 'Código de Error';
$string['view_task'] = 'Ver Tarea';

// Log actions
$string['log_action_request_created'] = 'Solicitud Creada';
$string['log_action_backup_started'] = 'Backup Iniciado';
$string['log_action_backup_completed'] = 'Backup Completado';
$string['log_action_backup_failed'] = 'Backup Fallido';
$string['log_action_download_started'] = 'Descarga Iniciada';
$string['log_action_download_completed'] = 'Descarga Completada';
$string['log_action_download_failed'] = 'Descarga Fallida';
$string['log_action_restore_started'] = 'Restauración Iniciada';
$string['log_action_restore_completed'] = 'Restauración Completada';
$string['log_action_restore_failed'] = 'Restauración Fallida';
$string['log_action_cleanup_started'] = 'Limpieza Iniciada';
$string['log_action_cleanup_completed'] = 'Limpieza Completada';
$string['log_action_cleanup_failed'] = 'Limpieza Fallida';
$string['log_action_task_created'] = 'Tarea Creada';
$string['log_action_task_started'] = 'Tarea Iniciada';
$string['log_action_task_completed'] = 'Tarea Completada';
$string['log_action_task_failed'] = 'Tarea Fallida';
```

## 🧹 Retención y Limpieza de Logs

### Configuración de Retención

El sistema incluye limpieza automática de logs antiguos para evitar que la tabla crezca indefinidamente.

**Configuración disponible:**
- **Días de retención**: Configurable en `Administración del sitio → Plugins → Local → CourseTransfer`
- **Valor por defecto**: 90 días
- **Configuración**: `log_retention_days`

### Tarea de Limpieza

La tarea programada `cleanup_old_backup_files_task` se encarga de:
1. Limpiar archivos de backup antiguos (origin y target)
2. Limpiar registros de logs más antiguos que el período de retención

**Programación:**
- Ejecuta diariamente a las 2:30 AM (configurable en `/admin/tool/task/scheduledtasks.php`)
- Elimina automáticamente logs con `timecreated < (ahora - log_retention_days)`

**Logs de la tarea:**
```
Starting cleanup of old log entries (retention: 90 days)
  Deleted 150 log entries older than 01/08/2025 12:00
Log cleanup completed: 150 log entries removed
```

### Beneficios

- ✅ Mantiene la tabla de logs en un tamaño manejable
- ✅ Mejora el rendimiento de consultas
- ✅ Cumple con políticas de retención de datos
- ✅ Completamente automático y configurable

## 📈 Próximos Pasos (No Implementados)

1. ✅ Añadir columna "Ver Logs" en `logs_table.php` con enlace a `logs_detail.php`
2. ⬜ Implementar botón para "reintentar" tareas atascadas
3. ⬜ Exportar logs a CSV/PDF
4. ⬜ Notificaciones por email cuando una tarea falla
5. ⬜ Dashboard con estadísticas de transferencias

## 🐛 Testing

### Escenarios a Probar:

1. **Transferencia Exitosa Completa**
   - Verificar que se registren todos los pasos
   - Origen: backup_started → backup_completed
   - Destino: download_started → download_completed → restore_started → restore_completed

2. **Transferencia con Error de Backup**
   - Verificar log de backup_failed
   - Verificar código de error

3. **Transferencia con Error de Descarga**
   - Verificar log de download_failed
   - Verificar mensaje de error

4. **Transferencia con Error de Restauración**
   - Verificar log de restore_failed

5. **Tareas Atascadas**
   - Crear request en progreso sin tareas activas
   - Verificar que se muestre advertencia

6. **Limpieza de Logs**
   - Verificar que la tarea programada se ejecute correctamente
   - Confirmar que se eliminan logs antiguos según la configuración

## 📞 Soporte

Para cualquier problema o pregunta sobre el sistema de logging, consultar:
- Documentación de Moodle sobre logging
- Documentación del plugin CourseTransfer
- Logs del sistema en `/admin/tasklogs.php`

---

**Versión**: 1.3.0 (2024110200)  
**Fecha**: 1 de Noviembre de 2025  
**Autor**: UNIMOODLE Group
