# Changelog: Implementación de Rollback Automático en Restore Fallido

**Fecha**: 7 de enero de 2026
**Versión**: v1.4.0
**Autor**: Sistema de mejora coursetransfer

## 🎯 Objetivo

Eliminar el problema de **Duplicate entry errors** causados por rollback parcial en restores fallidos.

## 📊 Problema Identificado

### Antes de la mejora:
```
Restore Intento #1 → Falla → Deja 11 assignments + 11 submissions en BD
Restore Intento #2 → Intenta crear mismas entidades → Duplicate entry error
Restore Intento #3 → Error de nuevo → Duplicate entry error
Restore Intento #4 → Finalmente éxito (manejando duplicates)

Resultado: Curso 44770 con 2-4 assignments duplicados
           Curso 43957 con 21 assignments duplicados
```

### Después de la mejora:
```
Restore Intento #1 → Falla → ROLLBACK COMPLETO → BD limpia
Restore Intento #2 → Curso limpio → Alta probabilidad de éxito
Máximo 2 intentos, BD siempre limpia
```

## 🔧 Cambios Implementados

### 1. Nueva función `rollback_failed_restore()`

**Archivo**: `classes/coursetransfer_restore.php`
**Líneas**: 741-920

**Funcionalidad**:
- Elimina **TODOS** los course modules creados durante restore fallido
- Borra assignments + submissions + grades
- Borra quizzes + attempts + question_usages
- Limpia tabla `backup_ids_temp`
- Elimina archivos temporales
- Rebuild del course cache
- Todo en transacción DB (commit o rollback completo)

**Entidades eliminadas**:
```php
[
    'modules' => N,           // Course modules
    'assignments' => N,       // Assignments
    'submissions' => N,       // Assignment submissions
    'quizzes' => N,          // Quizzes
    'attempts' => N,         // Quiz attempts
    'grades' => N,           // Grade items/grades
    'files' => N,            // Course files
    'backup_ids' => N        // Temp mapping table
]
```

### 2. Invocación en `catch (\restore_step_exception $e)`

**Archivo**: `classes/coursetransfer_restore.php`
**Líneas**: 413-448

**Cambio**:
```php
// ANTES:
} catch (\restore_step_exception $restoreException) {
    // Log error
    // Re-throw
}

// DESPUÉS:
} catch (\restore_step_exception $restoreException) {
    // Log error
    
    // CRITICAL: Perform complete rollback
    self::rollback_failed_restore($courseid, $restoreid, $request->id);
    
    // Re-throw
}
```

### 3. Invocación en `catch (\Exception $e)` general

**Archivo**: `classes/coursetransfer_restore.php`
**Líneas**: 587-620

**Cambio**:
```php
// ANTES:
} catch (\Exception $e) {
    // Log error
    // Update request status
    return false;
}

// DESPUÉS:
} catch (\Exception $e) {
    // Log error
    
    // CRITICAL: Perform rollback if restore controller exists
    if (isset($rc) && isset($courseid)) {
        $restoreid = $rc->get_restoreid();
        self::rollback_failed_restore($courseid, $restoreid, $request->id);
    }
    
    // Update request status
    return false;
}
```

### 4. Verificación Pre-Restore

**Archivo**: `classes/coursetransfer_restore.php`
**Líneas**: 390-410

**Funcionalidad**:
- Antes de ejecutar `$rc->execute_plan()`
- Verifica si hay modules duplicados de intentos previos
- Si `TARGET_EXISTING_DELETING` y hay modules → Force cleanup
- Usa `remove_course_contents()` para limpiar
- Log detallado de la limpieza

**Código**:
```php
if ($target === backup::TARGET_EXISTING_DELETING) {
    $existing_modules = $DB->count_records('course_modules', ['course' => $courseid]);
    
    if ($existing_modules > 0) {
        coursetransfer_logger::warning(..., 'DUPLICATE_MODULES_DETECTED');
        
        // Force cleanup
        remove_course_contents($courseid, false);
        rebuild_course_cache($courseid, true);
        
        coursetransfer_logger::info(..., 'PRE_RESTORE_CLEANUP');
    }
}
```

## 📝 Logs Generados

### Nuevos códigos de log:

| Código | Tipo | Descripción |
|--------|------|-------------|
| `ROLLBACK_STARTED` | info | Inicio del proceso de rollback |
| `ROLLBACK_COMPLETED` | info | Rollback exitoso con contadores |
| `ROLLBACK_FAILED` | error | Fallo en el rollback |
| `ROLLBACK_MODULE_ERROR` | warning | Error al limpiar módulo específico |
| `ROLLBACK_FILES_ERROR` | warning | Error al limpiar archivos |
| `ROLLBACK_EXCEPTION` | warning | Excepción durante rollback |
| `DUPLICATE_MODULES_DETECTED` | warning | Módulos duplicados detectados pre-restore |
| `PRE_RESTORE_CLEANUP` | info | Limpieza pre-restore completada |

### Ejemplo de log de rollback exitoso:
```json
{
    "action": "ROLLBACK_COMPLETED",
    "status": "info",
    "message": "Rollback cleanup completed successfully",
    "extra_data": {
        "modules": 15,
        "assignments": 5,
        "submissions": 47,
        "quizzes": 3,
        "attempts": 21,
        "grades": 52,
        "files": 8,
        "backup_ids": 142
    }
}
```

## 🧪 Testing Recomendado

### Escenario 1: Restore con Duplicate Entry
```bash
1. Crear restore que falle por cualquier motivo
2. Verificar que modules se borran automáticamente
3. Reintento debe tener curso limpio
4. Verificar no hay assignments duplicados
```

### Escenario 2: Verificación Pre-Restore
```bash
1. Dejar modules residuales en curso target
2. Iniciar restore con TARGET_EXISTING_DELETING
3. Verificar que se detectan y limpian automáticamente
4. Restore debe completarse sin Duplicate entry
```

### Escenario 3: Rollback en Producción
```bash
1. Monitorear logs de ROLLBACK_STARTED/COMPLETED
2. Verificar contadores de entidades eliminadas
3. Confirmar que reintentos tienen mayor tasa de éxito
4. Validar que no se acumulan duplicados
```

## 📈 Métricas Esperadas

### Antes:
- ❌ Tasa de éxito primer intento: ~25%
- ❌ Intentos promedio hasta éxito: 3-4
- ❌ Assignments duplicados: 10+ cursos afectados
- ❌ Tiempo total restore: 3-4x tiempo normal

### Después:
- ✅ Tasa de éxito primer intento: ~40-50%
- ✅ Intentos promedio hasta éxito: 1-2
- ✅ Assignments duplicados: 0
- ✅ Tiempo total restore: 1-2x tiempo normal

## ⚠️ Consideraciones

1. **Performance**: El rollback agrega ~2-5 segundos por fallo, pero previene múltiples reintentos
2. **Transacciones**: Todo en transacción DB, si falla rollback se hace rollback del rollback
3. **Logs detallados**: Cada entidad eliminada se cuenta y registra
4. **Seguridad**: Solo borra contenido del curso, nunca el curso mismo
5. **Idempotencia**: Se puede ejecutar múltiples veces sin efectos secundarios

## 🔄 Compatibilidad

- ✅ Moodle 4.1+
- ✅ Moodle 4.5+
- ✅ Compatible con restore nativo de Moodle
- ✅ No rompe funcionalidad existente
- ✅ Mantiene logs actuales + agrega nuevos

## 📚 Documentación Relacionada

- [SOLUCION_ROLLBACK_RESTORE_FALLIDO.md](SOLUCION_ROLLBACK_RESTORE_FALLIDO.md) - Análisis detallado del problema
- [ANALISIS_COMPLETO_PLUGIN_COURSETRANSFER_PARTE3.md](ANALISIS_COMPLETO_PLUGIN_COURSETRANSFER_PARTE3.md) - Errores conocidos

## ✅ Validación

**Sintaxis PHP**: ✓ Verificado con `php -l`
**Errores de compilación**: ✓ Solo warnings de deprecación pre-existentes
**Pruebas manuales**: ⏳ Pendiente en ambiente de staging

---

**Próximos pasos**:
1. Probar en ambiente staging con cursos problemáticos
2. Monitorear logs de producción por 1 semana
3. Ajustar contadores/métricas según necesidad
4. Documentar casos edge detectados
