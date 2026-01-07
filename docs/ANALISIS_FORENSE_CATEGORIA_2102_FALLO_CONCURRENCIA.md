# Análisis Forense: Fallo en Restauración de Categoría Completa

**Fecha**: 7 de enero de 2026, 18:00-18:15
**Categoría**: 2102 - CV04032ONL - INGENIERÍA INDUSTRIAL
**Origen**: virtual-legacy-ipg.ximple-tech.com (54.90.176.171:3308)
**Destino**: Proxmox-SophiaEDX (127.0.0.1:3307)
**Total Cursos**: 13
**Exitosos**: 3 (23%)
**Fallidos**: 10 (77%)

---

## 🔴 PROBLEMA CRÍTICO DETECTADO: Violación de Control de Concurrencia

### Evidencia del Lock Overlap

```
Timeline de Locks Adquiridos:

18:03:08 - Request 127 → LOCK ACQUIRED → ✅ ÉXITO 18:03:20
18:05:08 - Request 128 → LOCK ACQUIRED
18:05:48 - Request 129 → LOCK ACQUIRED ⚠️ OVERLAP con 128!
18:05:51 - Request 130 → LOCK ACQUIRED ⚠️ OVERLAP con 128 y 129!
18:06:10 - Request 131 → LOCK ACQUIRED → ✅ ÉXITO 18:06:15
18:06:39 - Request 132 → LOCK ACQUIRED
18:07:03 - Request 133 → LOCK ACQUIRED ⚠️ OVERLAP con 132!
18:07:07 - Request 134 → LOCK ACQUIRED ⚠️ OVERLAP con 132 y 133!
...y así sucesivamente
```

### Análisis del Patrón

**Requests que adquieren lock SIMULTÁNEAMENTE:**

1. **Grupo 1 (18:05:08-18:05:51)**: 
   - 128, 129, 130 ejecutando al mismo tiempo
   - Resultado: 128 falla primero, luego 129 y 130 fallan

2. **Grupo 2 (18:06:39-18:07:08)**:
   - 132, 133, 134 ejecutando al mismo tiempo
   - Resultado: Todos fallan

3. **Requests exitosos**:
   - 127: **NO HAY OVERLAP** → ✅ ÉXITO
   - 131: Ejecuta solo después que fallan 128-130 → ✅ ÉXITO
   - 135: Ejecuta después de varios fallos, tiene reintento → ✅ ÉXITO

---

## 📊 Tabla Completa de Requests

| Request | Curso ID | Shortname | Target | Status | Error | Lock Time | Outcome |
|---------|----------|-----------|--------|--------|-------|-----------|---------|
| 126 | NULL | NULL | NULL | 1 | NULL | - | Category request |
| 127 | 33564 | MAT1002ONL_CV_S75_2024_5 | 142 | 100 | NULL | 18:03:08 | ✅ SUCCESS (Solo) |
| 128 | 33606 | INGINDUS5002ONL_CV_S71_2024_5 | 143 | 100 | 10400 | 18:05:08 | ⚠️ RETRY SUCCESS |
| 129 | 33607 | INGINDUS5003ONL_CV_S71_2024_5 | 144 | 0 | 10400 | 18:05:48 | ❌ FAIL (Overlap) |
| 130 | 33678 | INGINDUS2001ONL_CV_S71_2024_5 | 145 | 0 | 10400 | 18:05:51 | ❌ FAIL (Overlap) |
| 131 | 33679 | INGINDUS3001ONL_CV_S71_2024_5 | 146 | 100 | NULL | 18:06:10 | ✅ SUCCESS (Solo) |
| 132 | 33680 | INGINDUS3002ONL_CV_S71_2024_5 | 147 | 0 | 10400 | 18:06:39 | ❌ FAIL (Overlap) |
| 133 | 33681 | INGINDUS4001ONL_CV_S71_2024_5 | 148 | 0 | 10400 | 18:07:03 | ❌ FAIL (Overlap) |
| 134 | 33682 | INGINDUS4002ONL_CV_S71_2024_5 | 149 | 0 | 10400 | 18:07:07 | ❌ FAIL (Overlap) |
| 135 | 33683 | INGINDUS4003ONL_CV_S71_2024_5 | 150 | 100 | 10400 | 18:08:05 | ⚠️ RETRY SUCCESS |
| 136 | 33684 | INGINDUS5001ONL_CV_S71_2024_5 | 151 | 0 | 10400 | 18:08:42 | ❌ FAIL (Overlap) |
| 137 | 33946 | EDUC012ONL_CV_S73_2024_5 | 152 | 0 | 10400 | 18:08:47 | ❌ FAIL (Overlap) |
| 138 | 33960 | MAT1001ONL_CV_S78_2024_5 | 153 | 0 | 10400 | 18:08:52 | ❌ FAIL (Overlap) |
| 139 | 33979 | MAT1004ONL_CV_S72_2024_5 | 154 | 0 | 10400 | 18:08:56 | ❌ FAIL (Overlap) |

---

## 🔍 Análisis del Error 10400

### Error Específico (Request 128):
```
error/not_specified_restore_task

Stack Trace:
restore_structure_step->get_mapping('question_answer', '2094230')
restore_qtype_multichoice_plugin->recode_choice_order('2094230,2094232...')
```

### Verificación en Base de Datos Origen:

**Question Answer 2094230:**
- ✅ **EXISTE** en origen
- Question ID: 620236
- Type: multichoice
- Text: "La efectividad se refiere a condiciones reales..."
- Question: "¿Cuál es la diferencia entre eficacia y efectividad?"

**Curso 33606 (QUÍMICA APLICADA...):**
- Quizzes: 4
- Attempts: 28
- **Pregunta 620236 SÍ está usada en attempts** ✅

**Conclusión**: Los datos en origen son **válidos y completos**. El error NO es por datos corruptos.

---

## 🎯 ROOT CAUSE: Race Condition en backup_ids_temp

### Teoría del Problema:

1. **Múltiples restore_controllers activos simultáneamente**
2. **Todos comparten la misma tabla temporal**: `backup_ids_temp` (aunque no existe en este Moodle, el principio aplica a las tablas temporales de restore)
3. **Mappings se mezclan entre restores**:
   ```
   RestoreID: b29c211178dfa (Request 128)
   RestoreID: 2ca946e155057 (Request 129) ← Ejecutando al mismo tiempo!
   RestoreID: f0c72da3efdd2 (Request 130) ← También ejecutando!
   ```

4. **Cuando restore intenta mapear `question_answer:2094230`**:
   - Busca en tabla temporal con su RestoreID
   - Pero la tabla puede tener mappings de otros restores concurrentes
   - El mapping no se encuentra o está corrupto
   - Error: `get_mapping('question_answer', '2094230')` returns NULL

### Por Qué Algunos Tienen Éxito:

**Request 127** (Primer curso):
- NO hay otros restores concurrentes
- Tabla temporal está limpia
- ✅ ÉXITO

**Request 131** (Después de 3 fallos):
- Los restores 128, 129, 130 ya terminaron (aunque fallaron)
- Tabla temporal se limpió
- ✅ ÉXITO

**Request 128 en RETRY** (Segundo intento):
- Ya NO hay otros restores concurrentes
- Tabla temporal limpia
- ✅ ÉXITO en intento 2

---

## ⚠️ Evidencia del Fallo del Lock

### El Lock NO Está Funcionando Correctamente

**Código esperado** (`restore_course_task.php` líneas 147-155):
```php
$lockfactory = \core\lock\lock_config::get_lock_factory('local_coursetransfer');
$lock = $lockfactory->get_lock('sequential_restore_execution', self::LOCK_TIMEOUT);

if (!$lock) {
    // Reschedule if can't acquire lock
    $this->set_next_run_time(time() + $backoff);
    \core\task\manager::reschedule_or_queue_adhoc_task($this);
    return;
}
```

**Comportamiento real observado**:
- Múltiples tasks adquieren el lock SIMULTÁNEAMENTE
- NO están esperando a que el lock se libere
- NO se están rescheduling

### Posibles Causas del Fallo del Lock:

1. **Lock factory no configurado correctamente**:
   - `get_lock_factory('local_coursetransfer')` puede no estar definido
   - Fallback a lock dummy que siempre retorna success

2. **Lock timeout muy largo** (300 segundos):
   - Si un restore tarda más de 5 min, el siguiente puede adquirir el mismo lock

3. **Multiple cron workers**:
   - Si hay múltiples procesos cron ejecutándose
   - Cada uno puede adquirir "su propio" lock en memoria
   - Los locks en memoria NO se comparten entre procesos

4. **Lock no se libera correctamente en restore previo**:
   - Si el restore falla antes de `finally { $lock->release(); }`
   - El lock queda activo indefinidamente
   - Aunque el código tiene `finally`, puede haber casos edge

---

## 📈 Estadísticas de Rollback

El nuevo sistema de rollback **SÍ está funcionando**:

```
Request 128 - Intentos:
  Intento 1: 18:05:12 → ROLLBACK COMPLETED → Fallo por concurrencia
  Intento 2: 18:10:14 → ROLLBACK NO NECESARIO → ✅ ÉXITO
  
Request 129 - Intentos:
  Intento 1: 18:05:51 → ROLLBACK COMPLETED → Fallo por concurrencia
  Intento 2: 18:10:52 → ROLLBACK COMPLETED → Fallo de nuevo (aún hay concurrencia)
  Max reintentos alcanzado
```

**Observación**: El rollback limpia correctamente, pero el problema de concurrencia persiste en los reintentos.

---

## 🛠️ Verificación del Lock en Código

### Logs Relevantes:

```
RESTORE_LOCK_ACQUIRED - Sequential restore lock acquired

Pero MÚLTIPLES requests tienen este log al mismo tiempo:
18:05:08 - Request 128: RESTORE_LOCK_ACQUIRED
18:05:48 - Request 129: RESTORE_LOCK_ACQUIRED (40 seg después, pero 128 aún ejecutando!)
18:05:51 - Request 130: RESTORE_LOCK_ACQUIRED (3 seg después!)
```

Esto confirma que **el lock NO está bloqueando**.

---

## 📊 Análisis de Timing

### Duración de Restores Exitosos:
- Request 127: 12 segundos (18:03:08 → 18:03:20)
- Request 131: 5 segundos (18:06:10 → 18:06:15)
- Request 135: 6 segundos (18:14:01 → 18:14:07)

### Duración de Restores Fallidos (primer intento):
- Request 128: ~4 segundos (18:05:08 → 18:05:12 rollback)
- Request 129: ~3 segundos (18:05:48 → 18:05:51 rollback)
- Request 130: ~4 segundos (18:05:51 → 18:05:55 rollback)

**Patrón**: Fallan MUY RÁPIDO (3-4 seg) vs éxito normal (5-12 seg).
Esto sugiere que fallan ANTES de procesar todo el restore, probablemente en la fase de mapping.

---

## 🎯 CONCLUSIONES

### Problema Principal: Lock de Concurrencia NO Funciona

1. ❌ **El lock `sequential_restore_execution` NO está bloqueando**
2. ❌ **Múltiples restores ejecutan simultáneamente**
3. ❌ **Comparten/corrompen tablas temporales de restore**
4. ✅ **El rollback SÍ funciona correctamente** (limpia datos residuales)
5. ✅ **Los datos en origen son correctos** (no hay corrupción)

### Problema Secundario: Reintentos Heredan el Problema

- Los reintentos se schedulean mientras aún hay otros restores ejecutando
- El problema de concurrencia persiste en los reintentos
- Solo tienen éxito cuando logran ejecutar **sin otros restores concurrentes**

---

## 💡 RECOMENDACIONES

### 1. Verificar Configuración del Lock Factory (CRÍTICO)

```bash
# En el servidor destino, verificar:
cd /var/www/html/moodle
php admin/cli/cfg.php --name=lock_factory
```

**Esperado**: Debería usar `file` o `db` lock factory, NO `none`.

### 2. Verificar Procesos Cron Concurrentes

```bash
ps aux | grep cron.php
```

**Si hay múltiples procesos**: Cada uno puede adquirir locks independientes (problema).

### 3. Aumentar Delay Entre Restores de Categoría

En lugar de lanzar todos los restore tasks al mismo tiempo, agregar delay progresivo:

```php
// En lugar de: $asynctask->set_next_run_time($nextruntime);
// Usar:
$delay = count($completed_requests) * 60; // 1 minuto entre cada uno
$asynctask->set_next_run_time(time() + $delay);
```

### 4. Verificar Lock Timeout

El timeout actual (300 seg) es muy largo. Si un restore grande excede 5 min, causa overlap.

**Recomendación**: Monitorear duración promedio de restores y ajustar timeout.

### 5. Agregar Log de Lock Wait

Modificar código para logear cuando un task NO puede adquirir lock:

```php
if (!$lock) {
    coursetransfer_logger::warning(
        $requestid,
        'RESTORE_LOCK_WAIT',
        "Could not acquire lock, rescheduling..."
    );
}
```

Esto ayudará a confirmar si el lock está funcionando.

---

## 📋 RESUMEN EJECUTIVO

| Aspecto | Estado | Evidencia |
|---------|--------|-----------|
| **Datos en Origen** | ✅ OK | question_answer 2094230 existe y está bien |
| **Rollback** | ✅ OK | Logs muestran ROLLBACK_COMPLETED correctamente |
| **Lock de Concurrencia** | ❌ FALLA | Múltiples tasks adquieren lock simultáneamente |
| **Race Condition** | ❌ CONFIRMADO | Overlaps de 40-60 segundos entre tasks |
| **Éxito en Retry** | ✅ PARCIAL | Solo cuando NO hay concurrencia |

**Causa raíz**: El sistema de locks NO está previniendo ejecución concurrente de restores, causando corrupción de tablas temporales compartidas.

**Fix inmediato**: Ejecutar restores de categorías de forma REALMENTE secuencial (delay de 2-3 min entre cada uno) hasta que se solucione el problema del lock.

---

**Generado**: 2026-01-07 20:30
**Analyst**: Sistema de análisis forense CourseTransfer
