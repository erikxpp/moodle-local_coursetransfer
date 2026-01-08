# Estado Actual del Sistema de Restauración Secuencial

**Fecha de actualización**: 8 de enero de 2026  
**Estado**: ⚠️ PARCIALMENTE IMPLEMENTADO

---

## 📋 Resumen del Estado Actual

### Lo que SÍ está implementado:

1. **Tabla `mdl_local_coursetransfer_queue`** - CREADA pero NO SE USA
2. **Lock `sequential_restore_execution`** en `restore_course_task.php`
3. **Pre-check `check_running_restore_tasks()`** antes de adquirir lock
4. **Backoff exponencial** cuando el lock está ocupado

### Lo que NO está implementado:

1. ❌ **`queue_processor_task.php`** - NUNCA SE CREÓ
2. ❌ **Lógica de encolar cursos** en `coursetransfer_request::enqueue_courses_for_restore()`
3. ❌ **Integración con `restore_category()`** para usar la cola

---

## 🔴 Problema Actual (8 de enero 2026)

El sistema de lock + pre-check **NO funciona correctamente** porque:

1. Moodle tiene `task_adhoc_concurrency_limit = 3`
2. Cuando se restaura una categoría, se crean N adhoc tasks
3. El cron de Moodle **inicia 3 tareas simultáneamente**
4. El pre-check no las detecta porque todas empiezan en el mismo instante
5. El lock de Moodle (`mdl_lock_db`) tampoco funciona correctamente en este escenario

### Evidencia del 8 de enero 2026:

```sql
-- Tareas ejecutándose simultáneamente (deberían ser 1)
| id      | timestarted         | minutos_running |
|---------|---------------------|-----------------|
| 7283338 | 2026-01-08 13:26:15 | 24              |
| 7283342 | 2026-01-08 13:26:40 | 24              |
| 7283346 | 2026-01-08 13:27:03 | 23              |

-- Locks activos en mdl_lock_db: NINGUNO
```

Las 3 tareas empezaron con **25 segundos de diferencia** y todas están ejecutándose en paralelo.

---

## 🛠️ Solución: Usar el CLI de Moodle con límite de concurrencia

Hasta que se implemente `queue_processor_task.php`, la solución es:

### Opción A: Cambiar configuración global de Moodle (NO RECOMENDADO)

```bash
# En el pod de Moodle:
php admin/cli/cfg.php --name=task_adhoc_concurrency_limit --set=1
```

⚠️ **Problema**: Afecta TODAS las tareas adhoc del sistema, no solo coursetransfer.

### Opción B: Ejecutar cron con límite específico (RECOMENDADO)

```bash
# Ejecutar cron con solo 1 tarea a la vez
php admin/cli/cron.php --adhoc-limit=1
```

✅ **Ventaja**: Solo afecta esa ejecución del cron.

### Opción C: Ejecutar restauraciones via CLI directamente

```bash
# Para cada request pendiente, ejecutar:
php local/coursetransfer/cli/restore_course_cli.php --requestid=XXXX
```

✅ **Ventaja**: Control total, no depende del cron.

---

## 📊 Documentación Incorrecta a Actualizar

Los siguientes documentos describen un sistema que **NO está implementado**:

1. **IMPLEMENTACION_SISTEMA_COLA_SECUENCIAL.md** 
   - ⚠️ Describe `queue_processor_task.php` que NO EXISTE
   - ⚠️ Describe funciones `enqueue_courses_for_restore()` que NO SE USAN

2. **PROPUESTA_QUEUE_SECUENCIAL_PROFESIONAL.md**
   - ✅ Es una PROPUESTA, no implementación

---

## 🎯 Plan para Implementar Correctamente

### Fase 1: CLI de Control (Corto plazo)

Crear `cli/run_sequential_restores.php`:
- Lee tareas adhoc pendientes de `restore_course_task`
- Ejecuta UNA a la vez usando `restore_course_cli.php`
- Pausa configurable entre tareas
- No depende del cron de Moodle

### Fase 2: Queue Processor (Mediano plazo)

Implementar `queue_processor_task.php` como se describe en la documentación:
- Usa la tabla `mdl_local_coursetransfer_queue`
- Procesa UN curso, luego se auto-encola
- Garantiza secuencialidad por diseño

### Fase 3: Integración Completa (Largo plazo)

Modificar `restore_category()` para:
- NO crear múltiples adhoc tasks
- Insertar en `mdl_local_coursetransfer_queue`
- Crear UNA sola `queue_processor_task`

---

## 🔧 Comandos Útiles para Monitoreo

```sql
-- Ver tareas de restore en ejecución
SELECT id, 
       JSON_EXTRACT(customdata, '$.requestid') as request_id,
       FROM_UNIXTIME(timestarted) as inicio,
       TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(timestarted), NOW()) as minutos
FROM mdl_task_adhoc 
WHERE classname = '\\local_coursetransfer\\task\\restore_course_task'
  AND timestarted IS NOT NULL;

-- Ver tareas pendientes
SELECT id, 
       JSON_EXTRACT(customdata, '$.requestid') as request_id,
       FROM_UNIXTIME(nextruntime) as proxima
FROM mdl_task_adhoc 
WHERE classname = '\\local_coursetransfer\\task\\restore_course_task'
  AND timestarted IS NULL
ORDER BY nextruntime;

-- Ver estado de requests de una categoría
SELECT id, origin_course_id, status, error_code,
       FROM_UNIXTIME(timemodified) as modificado
FROM mdl_local_coursetransfer_request
WHERE origin_category_id = XXXX
ORDER BY id;
```

---

## 📝 Notas

- La tabla `mdl_local_coursetransfer_queue` fue creada en versión `2025010701` pero nunca se pobló
- El lock `sequential_restore_execution` se adquiere pero **no impide** que Moodle inicie otras tareas
- El problema de concurrencia persiste a pesar de las mejoras documentadas
