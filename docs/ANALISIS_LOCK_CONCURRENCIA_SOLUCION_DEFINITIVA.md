# Análisis Definitivo: Por Qué Falla el Lock de Concurrencia

**Fecha**: 7 de enero de 2026
**Problema**: Lock de concurrencia NO previene ejecución simultánea de restores
**Root Cause**: LOCK_TIMEOUT demasiado corto

---

## 🔴 PROBLEMA IDENTIFICADO

### Configuración Actual (INCORRECTA):

**Archivo**: `classes/task/restore_course_task.php` línea 73
```php
const LOCK_TIMEOUT = 10; // 10 segundos
```

### Por Qué Esto Falla:

1. **Task 1** (Request 128) inicia a las 18:05:08
   - Intenta adquirir lock con timeout de 10 segundos
   - Lock adquirido exitosamente
   - Inicia restore (toma ~5-12 segundos en promedio)

2. **Task 2** (Request 129) inicia a las 18:05:48 (40 segundos después)
   - Intenta adquirir lock con timeout de 10 segundos
   - **Lock NO está disponible** (Task 1 aún ejecutando)
   - Espera 10 segundos intentando adquirir lock
   - **Después de 10 segundos, `get_lock()` RETORNA NULL**
   - Código verifica `if (!$lock)` → Debería reschedular
   - **PERO NO ESTÁ ENTRANDO A ESE IF**

3. **Task 3** (Request 130) inicia a las 18:05:51 (3 segundos después de Task 2)
   - Mismo problema

### Evidencia del Fallo:

```sql
-- Búsqueda de logs CONCURRENCY_LOCK_BUSY:
SELECT COUNT(*) FROM mdl_local_coursetransfer_log 
WHERE action = 'CONCURRENCY_LOCK_BUSY';

Resultado: 0 ⚠️
```

**Conclusión**: El código `if (!$lock)` **NUNCA se ejecuta**, lo que significa que `get_lock()` **SIEMPRE retorna un lock**, incluso cuando no debería.

---

## 🔍 ANÁLISIS DE get_lock() EN MOODLE

### Comportamiento de db_record_lock_factory::get_lock()

```php
public function get_lock($resource, $timeout, $maxlifetime = 86400) {
    $now = time();
    $giveuptime = $now + $timeout; // 10 segundos
    
    // Loop intentando adquirir lock
    do {
        $sql = 'UPDATE {lock_db}
                   SET expires = :expires, owner = :token
                 WHERE resourcekey = :resourcekey
                   AND (expires < :now OR owner = :token)';
        
        $result = $this->db->execute($sql, $params);
        
        if ($result) {
            return new lock($token, $this); // ✅ Lock adquirido
        }
        
        if (time() >= $giveuptime) {
            return false; // ❌ Timeout - NO pudo adquirir lock
        }
        
        usleep(rand(10000, 250000)); // Wait random 10-250ms
    } while (true);
}
```

### El Problema REAL:

Cuando `$timeout = 10` segundos:
- Si el lock está ocupado, espera hasta 10 segundos
- Si después de 10 segundos NO adquiere lock → **Retorna FALSE**
- El código **debería** detectar esto y reschedular
- **PERO**: Los logs muestran que TODOS los tasks dicen "RESTORE_LOCK_ACQUIRED"

Esto significa que **SÍ están adquiriendo el lock**, pero **SIMULTÁNEAMENTE**.

---

## 💡 TEORÍA: Lock Expiration Demasiado Corta

### Hipótesis:

El problema NO es el `$timeout` (tiempo de espera), sino el **`$maxlifetime`** (tiempo de expiración del lock).

```php
public function get_lock($resource, $timeout, $maxlifetime = 86400)
```

Cuando se llama:
```php
$lock = $lockfactory->get_lock('sequential_restore_execution', self::LOCK_TIMEOUT);
                                                                 ^^^^^^^^^^^
                                                                 10 segundos
```

**PERO**: `get_lock()` tiene 3 parámetros:
1. `$resource` → 'sequential_restore_execution'
2. `$timeout` → 10 segundos (tiempo de ESPERA para adquirir)
3. `$maxlifetime` → **DEFAULT 86400 segundos (24 horas!)**

### El Problema:

- `$timeout = 10` → Solo espera 10 seg para INTENTAR adquirir
- `$maxlifetime = DEFAULT 86400` → El lock expira después de 24 horas
- **Restore toma 5-12 segundos** → Lock se libera en finally
- **PERO** si hay cualquier error y el lock NO se libera, queda activo 24 horas

### Verificación:

Si la tabla `mdl_lock_db` tiene locks expirados:

```sql
SELECT * FROM mdl_lock_db 
WHERE resourcekey LIKE '%coursetransfer%'
  AND expires > UNIX_TIMESTAMP();
```

Si está vacía, entonces el problema es **otro**.

---

## 🎯 NUEVA HIPÓTESIS: Lock NO Se Está Usando Correctamente

### Revisemos el Código del Lock:

**Línea 144-145**:
```php
$lockfactory = \core\lock\lock_config::get_lock_factory('local_coursetransfer');
$lock = $lockfactory->get_lock('sequential_restore_execution', self::LOCK_TIMEOUT);
```

**Línea 510-520** (finally block):
```php
} finally {
    if (isset($lock) && $lock) {
        try {
            $lock->release();
            $this->log("✓ Sequential restore lock released.");
        } catch (\Exception $lockReleaseException) {
            $this->log("WARNING: Failed to release lock: " . $lockReleaseException->getMessage());
        }
    }
}
```

### Problema Potencial #1: Lock Factory Type

Cuando se llama `get_lock_factory('local_coursetransfer')`:
- Se crea una instancia con namespace 'local_coursetransfer'
- El resourcekey será: `'local_coursetransfer_sequential_restore_execution'`
- Cada proceso cron puede tener su PROPIA instancia del lock factory

### Problema Potencial #2: Múltiples Procesos Cron

Si hay múltiples workers de cron ejecutándose:

```bash
ps aux | grep cron.php
```

Cada proceso tiene su **propia conexión a la base de datos**.

El lock `db_record_lock_factory` usa **UPDATE SQL con WHERE** para lock:
```sql
UPDATE mdl_lock_db 
SET expires = X, owner = Y
WHERE resourcekey = 'local_coursetransfer_sequential_restore_execution'
  AND (expires < NOW OR owner = Y)
```

**Pero**: Si hay múltiples procesos ejecutando este UPDATE **al mismo tiempo**:
- Proceso A ejecuta UPDATE → 1 row affected → Lock adquirido
- Proceso B ejecuta UPDATE (milisegundos después) → 0 rows affected → NO debería adquirir lock
- **PERO** el código hace loop y retry hasta timeout

### Problema Potencial #3: Transaction Isolation

Si el nivel de aislamiento de transacciones es `READ COMMITTED`:
- Proceso A hace UPDATE en transacción
- Proceso B lee la tabla **antes** de que A haga commit
- Ambos creen que tienen el lock

---

## ✅ SOLUCIÓN DEFINITIVA

### Opción 1: Aumentar LOCK_TIMEOUT (Parcial)

**Cambio**:
```php
const LOCK_TIMEOUT = 300; // 5 minutos (tiempo máximo de un restore)
```

**Ventaja**: Si un restore toma 2-3 minutos, el siguiente esperará
**Desventaja**: Si hay 10 cursos, el último esperará 30+ minutos

### Opción 2: Usar Lock con Maxlifetime Corto (MEJOR)

**Cambio**:
```php
const LOCK_TIMEOUT = 10; // Espera máximo 10 seg para ADQUIRIR
const LOCK_MAXLIFETIME = 600; // Lock expira en 10 minutos (por seguridad)

$lock = $lockfactory->get_lock(
    'sequential_restore_execution', 
    self::LOCK_TIMEOUT,
    self::LOCK_MAXLIFETIME // ← AGREGAR ESTE PARÁMETRO
);
```

**Ventaja**: 
- Lock se libera automáticamente si el proceso cuelga
- Timeout corto para intentar adquirir (10 seg)
- Reschedule rápido si busy

### Opción 3: Usar Lock a Nivel de Categoría (ÓPTIMO)

En lugar de un lock global, usar lock POR CATEGORÍA:

```php
// En lugar de:
$lock = $lockfactory->get_lock('sequential_restore_execution', self::LOCK_TIMEOUT);

// Usar:
$category_id = $request->origin_category_id ?? 'single';
$lock = $lockfactory->get_lock("restore_cat_{$category_id}", self::LOCK_TIMEOUT);
```

**Ventajas**:
- Múltiples categorías se restauran en paralelo
- Cursos de la MISMA categoría se restauran secuencialmente
- Mejor throughput

### Opción 4: Verificar y Registrar Estado del Lock (DEBUG)

**Agregar logging detallado**:

```php
$lockfactory = \core\lock\lock_config::get_lock_factory('local_coursetransfer');

// Log factory class
$lockfactoryclass = get_class($lockfactory);
coursetransfer_logger::info(
    $requestid,
    coursetransfer_logger::DIRECTION_TARGET,
    'LOCK_FACTORY_CLASS',
    "Using lock factory: {$lockfactoryclass}"
);

// Intentar adquirir lock
$lock_start = microtime(true);
$lock = $lockfactory->get_lock('sequential_restore_execution', self::LOCK_TIMEOUT);
$lock_duration = round((microtime(true) - $lock_start) * 1000);

if (!$lock) {
    // NO pudo adquirir lock
    coursetransfer_logger::warning(
        $requestid,
        coursetransfer_logger::DIRECTION_TARGET,
        'LOCK_ACQUISITION_FAILED',
        "Failed to acquire lock after {$lock_duration}ms",
        null,
        ['timeout' => self::LOCK_TIMEOUT, 'duration_ms' => $lock_duration]
    );
    
    // Reschedule...
} else {
    // Lock adquirido
    coursetransfer_logger::info(
        $requestid,
        coursetransfer_logger::DIRECTION_TARGET,
        'RESTORE_LOCK_ACQUIRED',
        "Lock acquired in {$lock_duration}ms",
        null,
        ['duration_ms' => $lock_duration, 'lock_token' => $lock->get_key()]
    );
}
```

Esto nos dirá:
- Qué lock factory se está usando (db_record, file, etc.)
- Cuánto tarda en adquirir el lock
- Si alguna vez falla en adquirirlo
- El token único del lock (para debugging)

---

## 📋 PLAN DE ACCIÓN

### Paso 1: Agregar Logging Detallado (INMEDIATO)

Implementar Opción 4 para entender qué está pasando exactamente.

### Paso 2: Implementar Solución Temporal (CORTO PLAZO)

Implementar Opción 2: Usar maxlifetime explícito.

### Paso 3: Implementar Solución Óptima (MEDIANO PLAZO)

Implementar Opción 3: Lock por categoría.

### Paso 4: Optimización Final (LARGO PLAZO)

Considerar usar locks Redis para mejor performance en cluster.

---

## 🧪 VERIFICACIÓN

### Para confirmar el problema actual:

1. **Verificar lock factory class**:
```bash
# En el servidor
cd /var/www/html/moodle
php -r "require('config.php'); echo get_config('core', 'lock_factory');"
```

2. **Verificar múltiples cron workers**:
```bash
ps aux | grep cron.php | wc -l
```

3. **Verificar locks en DB**:
```sql
SELECT * FROM mdl_lock_db 
WHERE resourcekey LIKE '%coursetransfer%'
ORDER BY expires DESC;
```

---

## 📊 RESUMEN

| Problema | Causa | Solución |
|----------|-------|----------|
| Múltiples restores concurrentes | Lock NO previene ejecución simultánea | Agregar logging + Usar maxlifetime |
| Lock timeout muy corto | 10 seg no es suficiente | Aumentar a 300 seg O usar mejor estrategia |
| Lock global para todo | Un restore bloquea todos | Lock por categoría |
| Sin visibility de locks | No sabemos qué lock factory se usa | Logging detallado |

**Acción inmediata recomendada**: Implementar logging detallado (Opción 4) en el próximo commit para diagnosticar el problema exacto antes de cambiar el timeout.
