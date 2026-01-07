# Propuesta: Sistema de Cola Secuencial Profesional

**Fecha**: 7 de enero de 2026
**Objetivo**: Control total de concurrencia sin depender de locks de Moodle
**Patrón**: Queue Processor Pattern

---

## 🎯 PROBLEMA ACTUAL

### Arquitectura Actual (CON PROBLEMAS):

```
Request → Crea 10 adhoc tasks SIMULTÁNEAS
            ↓
Cron ejecuta TODAS en paralelo
            ↓
Lock intenta prevenir → FALLA
            ↓
Restauraciones concurrentes → CORRUPCIÓN
```

**Problemas**:
- ❌ Dependencia del lock de Moodle (que no funciona bien)
- ❌ Sin control de cuántas tasks se ejecutan en paralelo
- ❌ Cron decide cuándo y cuántas ejecutar
- ❌ Imposible cancelar o pausar el proceso

---

## ✅ SOLUCIÓN PROFESIONAL: Queue Pattern

### Arquitectura Propuesta:

```
Request → Inserta cursos en COLA (tabla queue)
            ↓
Crea UNA SOLA adhoc task "queue_processor"
            ↓
Queue Processor:
  1. Toma PRIMER curso pendiente de la cola
  2. Cambia estado a "processing"
  3. Ejecuta restore
  4. Marca como "completed" o "failed"
  5. ¿Hay más cursos? → Se AUTO-ENCOLA y termina
  6. ¿No hay más? → Termina
            ↓
Próxima ejecución del cron → Procesa SIGUIENTE curso
```

**Ventajas**:
- ✅ **Secuencial por diseño**: Solo 1 curso procesándose a la vez
- ✅ **Sin locks**: No depende de locks de Moodle
- ✅ **Control total**: Sabes exactamente qué se está ejecutando
- ✅ **Pausable**: Puedes pausar la cola
- ✅ **Reiniciable**: Si falla, solo reintentas ese curso
- ✅ **Monitoreable**: Ves el progreso en tiempo real
- ✅ **Escalable**: Puedes tener múltiples queues por categoría

---

## 📊 DISEÑO DE LA TABLA DE COLA

### Nueva Tabla: mdl_local_coursetransfer_queue

```sql
CREATE TABLE mdl_local_coursetransfer_queue (
    id BIGINT(10) NOT NULL AUTO_INCREMENT,
    requestid BIGINT(10) NOT NULL,           -- FK a request
    origin_course_id BIGINT(10) NOT NULL,    -- Curso a restaurar
    origin_course_name VARCHAR(254),         -- Nombre del curso
    priority INT DEFAULT 0,                   -- Prioridad (0 = normal, 1 = alta)
    status VARCHAR(20) NOT NULL,             -- 'pending', 'processing', 'completed', 'failed', 'cancelled'
    attempts INT DEFAULT 0,                   -- Número de intentos
    max_attempts INT DEFAULT 3,               -- Máximo de reintentos
    processing_started BIGINT(10),           -- Timestamp cuando inició
    processing_completed BIGINT(10),         -- Timestamp cuando terminó
    error_message LONGTEXT,                  -- Error si falló
    timecreated BIGINT(10) NOT NULL,         -- Cuándo se agregó a la cola
    timemodified BIGINT(10) NOT NULL,        -- Última modificación
    PRIMARY KEY (id),
    KEY requestid (requestid),
    KEY status (status),
    KEY priority_created (priority DESC, timecreated ASC)  -- Para ordenar por prioridad
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Estados de la Cola**:
- `pending` → Esperando ser procesado
- `processing` → Actualmente ejecutándose
- `completed` → Terminado exitosamente
- `failed` → Falló después de max_attempts
- `cancelled` → Cancelado manualmente

---

## 🔧 IMPLEMENTACIÓN

### Paso 1: Agregar Cursos a la Cola

**Archivo**: `classes/coursetransfer_request.php`

```php
/**
 * Encola cursos para restauración secuencial
 *
 * @param int $requestid Request ID
 * @param array $courses Array de course objects [id, fullname]
 * @return bool Success
 */
public static function enqueue_courses_for_restore($requestid, $courses) {
    global $DB;
    
    $now = time();
    
    foreach ($courses as $course) {
        $queue_item = new \stdClass();
        $queue_item->requestid = $requestid;
        $queue_item->origin_course_id = $course->id;
        $queue_item->origin_course_name = $course->fullname;
        $queue_item->priority = 0;
        $queue_item->status = 'pending';
        $queue_item->attempts = 0;
        $queue_item->max_attempts = 3;
        $queue_item->timecreated = $now;
        $queue_item->timemodified = $now;
        
        $DB->insert_record('local_coursetransfer_queue', $queue_item);
    }
    
    // Crear UNA SOLA adhoc task para procesar la cola
    $task = new \local_coursetransfer\task\queue_processor_task();
    $task->set_custom_data(['requestid' => $requestid]);
    \core\task\manager::queue_adhoc_task($task);
    
    return true;
}
```

### Paso 2: Crear Queue Processor Task

**Archivo**: `classes/task/queue_processor_task.php` (NUEVO)

```php
<?php
namespace local_coursetransfer\task;

use local_coursetransfer\coursetransfer_request;
use local_coursetransfer\coursetransfer_restore;
use local_coursetransfer\coursetransfer_logger;

defined('MOODLE_INTERNAL') || die();

/**
 * Queue processor task - Procesa UN curso a la vez de forma secuencial
 */
class queue_processor_task extends \core\task\adhoc_task {
    
    const MAX_PROCESSING_TIME = 3600; // 1 hora máximo por curso
    
    /**
     * Execute task
     */
    public function execute() {
        global $DB;
        
        $data = $this->get_custom_data();
        $requestid = $data->requestid ?? null;
        
        if (!$requestid) {
            mtrace("❌ Queue processor: No requestid provided");
            return;
        }
        
        mtrace("🔄 Queue processor starting for request {$requestid}");
        
        // 1. Verificar si hay cursos "processing" atascados (stuck)
        $this->cleanup_stuck_processing($requestid);
        
        // 2. Obtener el PRIMER curso pendiente (por prioridad y orden)
        $next_course = $DB->get_record_sql(
            "SELECT * FROM {local_coursetransfer_queue}
             WHERE requestid = :requestid
               AND status = 'pending'
               AND attempts < max_attempts
             ORDER BY priority DESC, timecreated ASC
             LIMIT 1",
            ['requestid' => $requestid]
        );
        
        if (!$next_course) {
            mtrace("✅ Queue processor: No pending courses. Queue is empty.");
            $this->log_queue_completion($requestid);
            return; // No hay más cursos → Terminamos
        }
        
        mtrace("📦 Processing course {$next_course->origin_course_id}: {$next_course->origin_course_name}");
        
        // 3. Marcar como "processing"
        $next_course->status = 'processing';
        $next_course->processing_started = time();
        $next_course->attempts++;
        $next_course->timemodified = time();
        $DB->update_record('local_coursetransfer_queue', $next_course);
        
        // 4. Ejecutar restore
        $success = false;
        $error_message = null;
        
        try {
            $restore_task = new restore_course_task();
            $restore_task->set_custom_data([
                'requestid' => $requestid,
                'origin_course_id' => $next_course->origin_course_id,
                'origin_course_name' => $next_course->origin_course_name
            ]);
            
            // Ejecutar restore directamente (sin encolar otra task)
            $restore_task->execute();
            
            $success = true;
            mtrace("✅ Course {$next_course->origin_course_id} restored successfully");
            
        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            mtrace("❌ Course {$next_course->origin_course_id} failed: {$error_message}");
        }
        
        // 5. Actualizar estado en la cola
        if ($success) {
            $next_course->status = 'completed';
            $next_course->processing_completed = time();
            $next_course->error_message = null;
        } else {
            // ¿Reintentar o marcar como failed?
            if ($next_course->attempts >= $next_course->max_attempts) {
                $next_course->status = 'failed';
                $next_course->processing_completed = time();
            } else {
                // Volver a pending para reintentar
                $next_course->status = 'pending';
            }
            $next_course->error_message = $error_message;
        }
        
        $next_course->timemodified = time();
        $DB->update_record('local_coursetransfer_queue', $next_course);
        
        // 6. ¿Hay más cursos pendientes? → AUTO-ENCOLAR para el siguiente
        $pending_count = $DB->count_records('local_coursetransfer_queue', [
            'requestid' => $requestid,
            'status' => 'pending'
        ]);
        
        if ($pending_count > 0) {
            mtrace("📋 {$pending_count} courses remaining. Re-queuing processor...");
            
            // Auto-encolarse para procesar el siguiente curso
            $next_task = new queue_processor_task();
            $next_task->set_custom_data(['requestid' => $requestid]);
            \core\task\manager::queue_adhoc_task($next_task);
        } else {
            mtrace("✅ All courses processed for request {$requestid}");
        }
    }
    
    /**
     * Limpia cursos "processing" que llevan más de MAX_PROCESSING_TIME
     * (probablemente el cron se cayó o hubo un error fatal)
     */
    private function cleanup_stuck_processing($requestid) {
        global $DB;
        
        $timeout_threshold = time() - self::MAX_PROCESSING_TIME;
        
        $stuck = $DB->get_records_sql(
            "SELECT * FROM {local_coursetransfer_queue}
             WHERE requestid = :requestid
               AND status = 'processing'
               AND processing_started < :threshold",
            ['requestid' => $requestid, 'threshold' => $timeout_threshold]
        );
        
        foreach ($stuck as $course) {
            mtrace("⚠️  Found stuck course {$course->origin_course_id}, resetting to pending");
            $course->status = 'pending';
            $course->processing_started = null;
            $course->timemodified = time();
            $DB->update_record('local_coursetransfer_queue', $course);
        }
    }
    
    /**
     * Log queue completion
     */
    private function log_queue_completion($requestid) {
        global $DB;
        
        $stats = [
            'completed' => $DB->count_records('local_coursetransfer_queue', ['requestid' => $requestid, 'status' => 'completed']),
            'failed' => $DB->count_records('local_coursetransfer_queue', ['requestid' => $requestid, 'status' => 'failed']),
            'cancelled' => $DB->count_records('local_coursetransfer_queue', ['requestid' => $requestid, 'status' => 'cancelled'])
        ];
        
        coursetransfer_logger::info(
            $requestid,
            coursetransfer_logger::DIRECTION_TARGET,
            'QUEUE_COMPLETED',
            sprintf("Queue processing finished: %d completed, %d failed, %d cancelled",
                $stats['completed'], $stats['failed'], $stats['cancelled']),
            null,
            $stats
        );
    }
}
```

### Paso 3: Modificar restore_course_task

El `restore_course_task` ya NO necesita:
- ❌ Lock (el queue processor garantiza secuencialidad)
- ❌ Auto-encolarse
- ✅ Solo ejecutar el restore cuando es llamado por queue_processor

**Simplificación**:
```php
// ANTES (con lock):
$lock = $lockfactory->get_lock('sequential_restore_execution', self::LOCK_TIMEOUT);
if (!$lock) {
    // Reschedule...
}
try {
    // Restore...
} finally {
    $lock->release();
}

// DESPUÉS (sin lock, llamado por queue_processor):
try {
    // Restore...
} catch (\Exception $e) {
    // Log error y throw para que queue_processor lo maneje
    throw $e;
}
```

---

## 🎛️ FUNCIONALIDADES ADICIONALES

### 1. Ver Estado de la Cola (UI)

**Página**: `queue_status.php`

```php
// Mostrar tabla con:
- Cursos pendientes
- Curso actualmente procesándose
- Cursos completados
- Cursos fallidos
- Tiempo estimado de finalización
```

### 2. Pausar/Reanudar Cola

```php
public static function pause_queue($requestid) {
    global $DB;
    
    // Marcar request como paused
    $DB->set_field('local_coursetransfer_request', 'queue_paused', 1, ['id' => $requestid]);
    
    // El queue_processor verificará este flag y no procesará más
}
```

### 3. Cancelar Cursos Específicos

```php
public static function cancel_course_in_queue($queue_id) {
    global $DB;
    
    $DB->set_field('local_coursetransfer_queue', 'status', 'cancelled', ['id' => $queue_id]);
}
```

### 4. Priorizar Cursos

```php
public static function prioritize_course($queue_id) {
    global $DB;
    
    // Aumentar prioridad
    $DB->set_field('local_coursetransfer_queue', 'priority', 1, ['id' => $queue_id]);
}
```

---

## 📈 VENTAJAS SOBRE WEBHOOK

Tu pregunta menciona webhooks. Comparación:

| Característica | Queue Processor | Webhook |
|----------------|-----------------|---------|
| **Complejidad** | Media | Alta |
| **Dependencias** | Solo Moodle DB | Servidor externo + HTTP |
| **Confiabilidad** | Alta (todo en DB) | Media (red puede fallar) |
| **Latencia** | Baja (misma DB) | Media (HTTP overhead) |
| **Debugging** | Fácil (logs en DB) | Difícil (logs externos) |
| **Mantenimiento** | Bajo | Alto |
| **Seguridad** | No requiere abrir puertos | Requiere endpoint público |

**Conclusión**: Queue processor es MÁS profesional que webhook para este caso.

---

## 🚀 PLAN DE IMPLEMENTACIÓN

### Fase 1: Crear Infraestructura (1-2 horas)

1. ✅ Crear tabla `mdl_local_coursetransfer_queue`
2. ✅ Crear `queue_processor_task.php`
3. ✅ Agregar funciones `enqueue_courses_for_restore()`
4. ✅ Agregar script de migración en `db/upgrade.php`

### Fase 2: Modificar Flujo Existente (30 min)

1. ✅ En `restore_categories()`: Usar `enqueue_courses_for_restore()` en lugar de crear múltiples adhoc tasks
2. ✅ Simplificar `restore_course_task` (remover lock)

### Fase 3: Testing (1 hora)

1. ✅ Test con 1 curso
2. ✅ Test con 5 cursos
3. ✅ Test con 20 cursos
4. ✅ Verificar secuencialidad
5. ✅ Probar reintentos en fallos

### Fase 4: UI de Monitoreo (Opcional, 2 horas)

1. ✅ Página `queue_status.php`
2. ✅ Botones pause/resume/cancel
3. ✅ Progress bar

---

## 📊 COMPARACIÓN: Antes vs Después

### ANTES (Sistema Actual):

```
Category con 10 cursos
  ↓
Crea 10 adhoc tasks SIMULTÁNEAS
  ↓
Cron ejecuta las 10 EN PARALELO
  ↓
Lock intenta prevenir → FALLA
  ↓
7 restores se corrompen ❌
```

### DESPUÉS (Con Queue):

```
Category con 10 cursos
  ↓
Inserta 10 registros en queue (status: pending)
  ↓
Crea 1 adhoc task: queue_processor
  ↓
Queue processor:
  1. Procesa curso #1 → completed ✅
  2. Auto-encola → Procesa curso #2 → completed ✅
  3. Auto-encola → Procesa curso #3 → completed ✅
  ...
  10. Auto-encola → Procesa curso #10 → completed ✅
  ↓
Todos los cursos restaurados correctamente ✅
```

---

## ✅ RECOMENDACIÓN FINAL

**Implementar Queue Pattern es la solución más profesional porque**:

1. ✅ **Control total**: No dependes de locks de Moodle
2. ✅ **Debugging fácil**: Ves estado en DB en tiempo real
3. ✅ **Secuencial garantizado**: Imposible que se ejecuten en paralelo
4. ✅ **Pausable/Cancelable**: Control total del proceso
5. ✅ **Escalable**: Puedes tener múltiples queues (por categoría, por site origin, etc.)
6. ✅ **Sin dependencias externas**: Todo dentro de Moodle
7. ✅ **Patrón estándar**: Usado en sistemas enterprise

**No necesitas webhook**. El queue processor se auto-encola, lo cual es más eficiente y confiable.

---

## 🎯 PRÓXIMO PASO

¿Quieres que implemente el Queue Pattern completo?

Incluiría:
1. ✅ Script SQL para crear tabla
2. ✅ Clase `queue_processor_task.php`
3. ✅ Función `enqueue_courses_for_restore()`
4. ✅ Modificaciones a `restore_categories()`
5. ✅ Script de upgrade para migration
6. ✅ Tests básicos

Tiempo estimado de implementación: **2-3 horas**
