# Análisis Profundo: Problema de Restauraciones Paralelas

## Fecha: 2025-01-XX
## Contexto: Restauración de categoría CV06003ONL (2025-4) con 20 cursos

---

## 1. RESUMEN EJECUTIVO

### El Problema
Al restaurar una categoría con 20 cursos, se iniciaron **3 tareas adhoc de restauración simultáneamente**, ocupando los 3 slots disponibles (`task_adhoc_concurrency_limit = 3`). Las tareas corrieron por más de 3 horas sin terminar y se convirtieron en **zombies** (el PID 15337 murió), bloqueando todo el sistema de tareas adhoc de Moodle.

### Causa Raíz
El mecanismo de control de concurrencia actual tiene **fallas de diseño fundamentales**:

1. **Race Condition en Pre-check**: Moodle puede iniciar 3 tareas simultáneamente antes de que cualquiera se marque como "iniciada"
2. **Lock Timeout Insuficiente**: 10 segundos es muy corto para operaciones de restauración
3. **Verificación basada en estado DB**: No funciona cuando las tareas inician al mismo tiempo

### Estado Actual
- ✅ **CLI SÍ se usa**: `restore_course_task.php` llama a `execute_restore_cli()` (línea 312)
- ❌ **Control de concurrencia NO funciona**: El lock y pre-check son inefectivos
- ❌ **Queue processor nunca existió**: Tabla `mdl_local_coursetransfer_queue` vacía

---

## 2. ARQUITECTURA ACTUAL

### 2.1 Flujo de Restauración de Categoría

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    FLUJO ACTUAL (PROBLEMÁTICO)                               │
└─────────────────────────────────────────────────────────────────────────────┘

Usuario solicita restaurar categoría (20 cursos)
         │
         ▼
┌─────────────────┐
│ Request Handler │  → Crea 20 requests en DB (status=REQUESTED)
└────────┬────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                     download_file_course_task (x20)                          │
│  Moodle puede ejecutar hasta 3 en paralelo según task_adhoc_concurrency_limit│
└────────┬────────────────────────────┬──────────────────────────┬────────────┘
         │                            │                          │
         ▼                            ▼                          ▼
    Download 1                   Download 2                 Download 3
    (success)                    (success)                  (success)
         │                            │                          │
         ▼                            ▼                          ▼
┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐
│ restore_course_ │       │ restore_course_ │       │ restore_course_ │
│ task #1         │       │ task #2         │       │ task #3         │
└────────┬────────┘       └────────┬────────┘       └────────┬────────┘
         │                         │                         │
         ▼                         ▼                         ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│           ⚠️  RACE CONDITION: 3 tareas inician SIMULTÁNEAMENTE               │
│                                                                              │
│  T=0ms:  Task1.check_running() → 0 running    (DB no tiene timestarted)     │
│  T=0ms:  Task2.check_running() → 0 running    (DB no tiene timestarted)     │
│  T=0ms:  Task3.check_running() → 0 running    (DB no tiene timestarted)     │
│                                                                              │
│  T=1ms:  Task1.get_lock() → SUCCESS (lock libre)                             │
│  T=1ms:  Task2.get_lock() → FAIL (10s timeout muy corto)                     │
│  T=1ms:  Task3.get_lock() → FAIL (10s timeout muy corto)                     │
│                                                                              │
│  T=11ms: Task2.reschedule() → Pero antes, Moodle ya lo marcó como "running"  │
│  T=11ms: Task3.reschedule() → Igual problema                                 │
│                                                                              │
│  RESULTADO: Las 3 tareas quedan en estado "running" en task_adhoc            │
│             pero solo 1 tiene el lock real                                   │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Código Actual del Pre-check (Líneas 100-145 de restore_course_task.php)

```php
// PRE-CHECK: Verify if there are other restore tasks currently running
$running_tasks = $this->check_running_restore_tasks($mytaskid);

if ($running_tasks > 0) {
    // Reschedule task
    $this->set_next_run_time(time() + $backoff);
    \core\task\manager::reschedule_or_queue_adhoc_task($this);
    return;
}
```

**Problema**: Este check consulta la columna `timestarted` de `task_adhoc`, pero Moodle actualiza `timestarted` **DESPUÉS** de que ya inició la ejecución del método `execute()`. Por lo tanto, si 3 tareas se lanzan simultáneamente, todas ven `running_tasks = 0`.

### 2.3 Código Actual del Lock (Líneas 145-180 de restore_course_task.php)

```php
const LOCK_TIMEOUT = 10; // PROBLEMA: Muy corto

$lockfactory = \core\lock\lock_config::get_lock_factory('local_coursetransfer');
$lock = $lockfactory->get_lock('sequential_restore_execution', self::LOCK_TIMEOUT);

if (!$lock) {
    // Reschedule
    $this->set_next_run_time(time() + $backoff);
    \core\task\manager::reschedule_or_queue_adhoc_task($this);
    return;
}
```

**Problema**: Timeout de 10 segundos es muy corto. Una restauración puede tardar 30+ minutos. Pero más importante: si la tarea no obtiene el lock y hace `reschedule_or_queue_adhoc_task()`, Moodle puede ignorarlo porque la tarea ya está marcada como "running" en la DB.

### 2.4 Ejecución CLI (Líneas 924-1037 de restore_course_task.php)

```php
private function execute_restore_cli($requestid, $fileid = null) {
    $php_binary = $this->get_php_binary();
    $cli_script = $CFG->dirroot . '/local/coursetransfer/cli/restore_course_cli.php';
    
    $cmd = sprintf(
        '%s %s --requestid=%d --fileid=%d 2>&1',
        escapeshellcmd($php_binary),
        escapeshellarg($cli_script),
        $requestid,
        $fileid ? (int)$fileid : 0
    );
    
    $process = proc_open($cmd, $descriptors, $pipes, $CFG->dirroot);
    // ... monitorea output con timeout de 90 minutos
}
```

✅ **BUENO**: La restauración real SÍ se ejecuta en proceso CLI aislado.

---

## 3. ANÁLISIS DEL PROBLEMA: POR QUÉ LAS TAREAS SE VOLVIERON ZOMBIES

### 3.1 Cronología del Fallo

```
T+0h:00m - Moodle inicia 3 restore_course_task simultáneamente (slots llenos)
         - Cada una llama a execute_restore_cli() y spawn un proceso PHP
         - 3 procesos CLI corriendo: restore_course_cli.php
         
T+0h:30m - Los 3 procesos CLI están restaurando cursos distintos
         - Cada uno tiene su propio restore_controller
         - Comparten tablas temporales (backup_ids_temp) → CONFLICTO
         
T+1h:00m - Posible deadlock en restore_controller por tablas compartidas
         - Un proceso CLI muere (PID 15337)
         - proc_open() en parent no detecta el crash inmediatamente
         
T+2h:00m - Los adhoc tasks siguen "ejecutándose" desde perspectiva de Moodle
         - No hay timeout a nivel de task_adhoc (solo en el proceso CLI interno)
         - Los slots siguen ocupados
         
T+3h:00m - Manual intervention requerida
         - truncate de tablas
         - kill de procesos
```

### 3.2 El Problema Real: Moodle Task Runner vs Plugin

El plugin intenta controlar la concurrencia, pero **Moodle decide primero**:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    PROBLEMA DE TIMING                                        │
└─────────────────────────────────────────────────────────────────────────────┘

Moodle Task Runner (cron):
  1. SELECT tasks WHERE nextruntime <= NOW() ORDER BY nextruntime LIMIT 3
  2. Para cada task: UPDATE SET timestarted = NOW(), pid = X
  3. Fork/spawn worker process
  4. Worker ejecuta $task->execute()  ← Aquí recién entra el pre-check del plugin
  
El pre-check del plugin se ejecuta DESPUÉS de que Moodle ya:
  - Marcó la tarea como "running"
  - Ocupó un slot de concurrencia
  - Inició el proceso worker
  
Por eso el pre-check ve "0 running tasks" cuando 3 inician simultáneamente.
```

---

## 4. COMPARACIÓN CON MOOSH

Moosh implementa un patrón diferente para operaciones de restauración:

### 4.1 Moosh Course Restore (líneas 177-195)

```php
// Moosh/Command/Moodle39/Course/CourseRestore.php

// Crea restore_controller directamente (no usa adhoc tasks)
$rc = new restore_controller($backupdir, $courseid, backup::INTERACTIVE_NO,
    backup::MODE_GENERAL, $USER->id, 0);

// Ejecuta sincrónicamente
if (!$rc->execute_precheck()) {
    cli_problem("Restore pre-check failed!");
}
$rc->execute_plan();
```

**Moosh es CLI puro**: No usa tareas adhoc. Cada comando se ejecuta secuencialmente porque es invocado desde línea de comandos.

### 4.2 Lección de Moosh

Para garantizar ejecución secuencial, **no debemos depender del sistema de tareas adhoc de Moodle para el control de concurrencia**. Necesitamos un mecanismo propio.

---

## 5. SOLUCIÓN PROPUESTA: PROCESADOR SECUENCIAL DEDICADO

### 5.1 Arquitectura Nueva

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    NUEVA ARQUITECTURA PROPUESTA                              │
└─────────────────────────────────────────────────────────────────────────────┘

Usuario solicita restaurar categoría (20 cursos)
         │
         ▼
┌─────────────────────┐
│ Request Handler     │  → Crea 20 requests (status=REQUESTED)
│                     │  → Encola 20 download tasks (pueden correr en paralelo)
└─────────┬───────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│              download_file_course_task (x20, paralelo OK)                    │
│  Descargas pueden ser paralelas sin problema                                 │
└─────────┬───────────────────────────────────────────────────────────────────┘
          │
          │ Cuando termina: NO crear restore_course_task
          │ En su lugar: INSERT en mdl_local_coursetransfer_queue
          │
          ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│           mdl_local_coursetransfer_queue                                     │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │ id │ request_id │ file_id │ priority │ status    │ created_at          ││
│  │ 1  │ 101        │ 5001    │ 1        │ pending   │ 2025-01-XX 10:00:00 ││
│  │ 2  │ 102        │ 5002    │ 2        │ pending   │ 2025-01-XX 10:00:01 ││
│  │ ... │                                                                   ││
│  │ 20 │ 120        │ 5020    │ 20       │ pending   │ 2025-01-XX 10:00:19 ││
│  └─────────────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────────────┘
          │
          │ UN SOLO scheduled task periódico (cada 30 segundos)
          │
          ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│         queue_processor_task.php (SCHEDULED, no adhoc)                       │
│                                                                              │
│  1. Check si hay proceso restore activo (PID file o lock)                    │
│     - Si hay uno activo → exit (esperar siguiente ejecución)                 │
│                                                                              │
│  2. SELECT * FROM queue WHERE status='pending' ORDER BY priority LIMIT 1     │
│                                                                              │
│  3. UPDATE queue SET status='processing', started_at=NOW() WHERE id=X        │
│                                                                              │
│  4. Ejecutar restore vía CLI (igual que ahora):                              │
│     exec('php restore_course_cli.php --requestid=X --fileid=Y')              │
│                                                                              │
│  5. Si éxito: UPDATE queue SET status='completed'                            │
│     Si fallo: UPDATE queue SET status='failed', retry_count++                │
│                                                                              │
│  6. Exit (la siguiente ejecución del scheduled task tomará el próximo)       │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 5.2 Ventajas de Esta Arquitectura

1. **Descargas paralelas**: Mantener rendimiento en la descarga de backups
2. **Restauraciones secuenciales**: Una a la vez, sin importar configuración de Moodle
3. **Cola persistente**: Si Moodle crashea, la cola está en DB y se retoma
4. **Prioridades**: Cursos individuales pueden tener mayor prioridad que categorías
5. **Visibilidad**: Tabla de cola muestra estado real de procesamiento
6. **No bloquea adhoc slots**: Scheduled task no cuenta contra concurrency_limit

### 5.3 Cambios Necesarios

| Archivo | Cambio |
|---------|--------|
| `download_file_course_task.php` | Eliminar llamada a `create_task_restore_course()`, insertar en cola |
| `coursetransfer_restore.php` | Eliminar método `create_task_restore_course()` |
| `restore_course_task.php` | **ELIMINAR** (ya no se usa como adhoc task) |
| `queue_processor_task.php` | **CREAR** scheduled task |
| `db/tasks.php` | Agregar scheduled task (cada 30 segundos) |
| `db/install.xml` | La tabla ya existe, agregar campos si necesario |

---

## 6. SOLUCIÓN ALTERNATIVA: FIX MÍNIMO

Si la arquitectura completa es demasiado invasiva, hay un fix mínimo:

### 6.1 Cambiar adhoc a scheduled task único

```php
// En download_file_course_task.php línea 227
// ANTES:
coursetransfer_restore::create_task_restore_course($request, $file, $fileurl);

// DESPUÉS:
// Insertar en cola de restauración pendiente
$queue_entry = new \stdClass();
$queue_entry->request_id = $request->id;
$queue_entry->file_id = $file->get_id();
$queue_entry->status = 'pending';
$queue_entry->timecreated = time();
$DB->insert_record('local_coursetransfer_queue', $queue_entry);
```

### 6.2 Crear scheduled task que procese uno a la vez

```php
// classes/task/process_restore_queue_task.php (scheduled)

public function execute() {
    global $DB;
    
    // Check si ya hay un restore corriendo (usando lock file)
    $lockfile = sys_get_temp_dir() . '/coursetransfer_restore.lock';
    if (file_exists($lockfile)) {
        $pid = file_get_contents($lockfile);
        if (posix_kill($pid, 0)) {
            // Proceso aún activo
            return;
        }
        // PID muerto, limpiar lock
        unlink($lockfile);
    }
    
    // Tomar siguiente de la cola
    $next = $DB->get_record('local_coursetransfer_queue', 
        ['status' => 'pending'], 
        '*', 
        IGNORE_MULTIPLE
    );
    
    if (!$next) {
        return; // Cola vacía
    }
    
    // Crear lock file con nuestro PID
    file_put_contents($lockfile, getmypid());
    
    try {
        // Ejecutar restore CLI
        $this->execute_single_restore($next->request_id, $next->file_id);
        
        $DB->update_record('local_coursetransfer_queue', [
            'id' => $next->id,
            'status' => 'completed',
            'timemodified' => time()
        ]);
    } catch (\Exception $e) {
        $DB->update_record('local_coursetransfer_queue', [
            'id' => $next->id,
            'status' => 'failed',
            'error' => $e->getMessage(),
            'timemodified' => time()
        ]);
    } finally {
        unlink($lockfile);
    }
}
```

---

## 7. CÓDIGO A ELIMINAR

Si se implementa la solución propuesta, estos componentes ya no son necesarios:

### 7.1 En restore_course_task.php

```php
// ELIMINAR TODO ESTO (líneas 100-180):
// Pre-check de running tasks
// Lock factory y sequential_restore_execution lock
// Lógica de reschedule

// El archivo entero puede eliminarse si usamos scheduled task
```

### 7.2 En coursetransfer_restore.php

```php
// ELIMINAR método create_task_restore_course() (líneas 82-118)
// Ya no creamos adhoc tasks para restore
```

---

## 8. VERIFICACIÓN DE USO DE CLI

### 8.1 Confirmación: CLI SÍ se usa

En `restore_course_task.php` línea 312:
```php
$this->log('Executing restore via isolated CLI process...');
$cli_result = $this->execute_restore_cli($requestid, $fileid);
```

El método `execute_restore_cli()` (líneas 924-1037):
- Construye comando: `php restore_course_cli.php --requestid=X --fileid=Y`
- Usa `proc_open()` para ejecutar en proceso aislado
- Timeout de 90 minutos (5400 segundos)
- Lee stdout/stderr en tiempo real

### 8.2 Fallback (código legacy)

Si el CLI script no existe, hay fallback a restauración directa:
```php
if (!file_exists($cli_script)) {
    // Fallback to direct restore
    $success = coursetransfer_restore::restore_course($request, $file);
}
```

Este fallback debería eliminarse o hacerse más explícito.

---

## 9. PLAN DE ACCIÓN RECOMENDADO

### Fase 1: Fix Inmediato (para producción actual)
1. Usar CLI manual `run_sequential_restores.php` para procesar restauraciones
2. Deshabilitar temporalmente restore_course_task (o aumentar su delay)

### Fase 2: Implementar Procesador de Cola
1. Crear `process_restore_queue_task.php` como scheduled task
2. Modificar `download_file_course_task.php` para insertar en cola
3. Testing exhaustivo

### Fase 3: Limpieza
1. Eliminar `restore_course_task.php` (adhoc)
2. Eliminar código de lock y pre-check
3. Actualizar documentación

---

## 10. CONCLUSIONES

### Lo que funciona bien:
- ✅ Descargas de backup (pueden ser paralelas)
- ✅ CLI para restauración aislada
- ✅ Logging detallado

### Lo que necesita cambio:
- ❌ Control de concurrencia basado en lock (no funciona)
- ❌ Pre-check de tareas running (race condition)
- ❌ Uso de adhoc tasks para restauración (conflicto con Moodle)

### Recomendación Final:
**Migrar a arquitectura de cola con scheduled task**. Es la única forma de garantizar ejecución secuencial independiente de la configuración de Moodle.

---

## ANEXO: Comparación de Enfoques

| Aspecto | Actual (Adhoc) | Propuesto (Cola + Scheduled) |
|---------|----------------|------------------------------|
| Control concurrencia | Moodle decide | Plugin decide |
| Slots adhoc ocupados | 1-3 por restore | 0 |
| Race conditions | Posible | Imposible |
| Recuperación crash | Manual | Automática |
| Visibilidad estado | task_adhoc | Tabla propia |
| Complejidad | Media | Media |
