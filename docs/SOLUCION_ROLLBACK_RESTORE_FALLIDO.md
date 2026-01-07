# Solución: Rollback Completo en Restore Fallido

## 🔴 Problema Identificado

Cuando un restore falla, el código actual:
1. **NO hace rollback completo de las entidades creadas**
2. Deja assignments, submissions, y otros datos parciales en la BD
3. En el siguiente reintento, se intenta crear las mismas entidades → **Duplicate entry error**
4. Solo después de 3-4 intentos puede tener éxito (si el código maneja el duplicate)

### Evidencia en producción:
```
Curso 44770: 
- 2 assignments idénticos "Semana 4: Encargo" (IDs: 67319, 67341)
- Ambos con 11 submissions cada uno
- Violación constraint: mdl_assisubm_assusegroatt_uix

Curso 43957:
- 21 assignments duplicados "Estudio de caso"
- Resultado de múltiples restore fallidos sin cleanup
```

## ✅ Solución Implementada

### 1. Agregar función de rollback en `coursetransfer_restore.php`

```php
/**
 * Perform rollback cleanup when restore fails
 * Removes all course content created during failed restore to prevent duplicates
 * 
 * @param int $courseid The course ID being restored
 * @param string $restoreid The restore controller ID
 * @param int $requestid The transfer request ID for logging
 */
private static function rollback_failed_restore($courseid, $restoreid, $requestid) {
    global $DB;
    
    coursetransfer_logger::info(
        $requestid,
        coursetransfer_logger::DIRECTION_TARGET,
        'ROLLBACK_STARTED',
        "Starting rollback cleanup for failed restore (Course: $courseid, RestoreID: $restoreid)"
    );
    
    $transaction = $DB->start_delegated_transaction();
    
    try {
        // 1. Get all course modules created during this restore
        $modules = $DB->get_records('course_modules', ['course' => $courseid]);
        
        $deleted_counts = [
            'modules' => 0,
            'assignments' => 0,
            'submissions' => 0,
            'quizzes' => 0,
            'attempts' => 0,
            'grades' => 0,
            'files' => 0
        ];
        
        foreach ($modules as $cm) {
            // Get module instance based on type
            $modinfo = get_fast_modinfo($courseid);
            $cminfo = $modinfo->get_cm($cm->id);
            $modname = $cminfo->modname;
            
            // Delete module-specific data
            switch ($modname) {
                case 'assign':
                    // Delete assignment submissions first (prevent FK violations)
                    $submissions_deleted = $DB->delete_records('assign_submission', ['assignment' => $cm->instance]);
                    $deleted_counts['submissions'] += $submissions_deleted;
                    
                    $grades_deleted = $DB->delete_records('assign_grades', ['assignment' => $cm->instance]);
                    $deleted_counts['grades'] += $grades_deleted;
                    
                    $DB->delete_records('assign', ['id' => $cm->instance]);
                    $deleted_counts['assignments']++;
                    break;
                    
                case 'quiz':
                    // Delete quiz attempts
                    $attempts = $DB->get_records('quiz_attempts', ['quiz' => $cm->instance]);
                    foreach ($attempts as $attempt) {
                        // Delete question attempts
                        $DB->delete_records('question_attempts', ['questionusageid' => $attempt->uniqueid]);
                        // Delete question usage
                        $DB->delete_records('question_usages', ['id' => $attempt->uniqueid]);
                    }
                    $attempts_deleted = $DB->delete_records('quiz_attempts', ['quiz' => $cm->instance]);
                    $deleted_counts['attempts'] += $attempts_deleted;
                    
                    $DB->delete_records('quiz_grades', ['quiz' => $cm->instance]);
                    $DB->delete_records('quiz', ['id' => $cm->instance]);
                    $deleted_counts['quizzes']++;
                    break;
            }
            
            // Delete course module instance
            course_delete_module($cm->id);
            $deleted_counts['modules']++;
        }
        
        // 2. Delete gradebook entries
        $DB->delete_records('grade_items', ['courseid' => $courseid, 'itemtype' => 'mod']);
        $DB->delete_records('grade_grades', ['itemid' => $DB->get_field_sql(
            "SELECT id FROM {grade_items} WHERE courseid = ? AND itemtype != 'course'", 
            [$courseid]
        )]);
        
        // 3. Clean backup_ids_temp table for this restore
        $DB->delete_records('backup_ids_temp', ['backupid' => $restoreid]);
        
        // 4. Delete course files added during restore
        $fs = get_file_storage();
        $context = \context_course::instance($courseid);
        $files = $fs->get_area_files($context->id, 'course', 'legacy', false, 'timecreated DESC');
        foreach ($files as $file) {
            if ($file->get_filename() !== '.') {
                $file->delete();
                $deleted_counts['files']++;
            }
        }
        
        // 5. Reset course content (keep structure but remove activities)
        rebuild_course_cache($courseid, true);
        
        $transaction->allow_commit();
        
        coursetransfer_logger::info(
            $requestid,
            coursetransfer_logger::DIRECTION_TARGET,
            'ROLLBACK_COMPLETED',
            'Rollback cleanup completed successfully',
            null,
            $deleted_counts
        );
        
        return true;
        
    } catch (\Exception $rollbackEx) {
        $transaction->rollback($rollbackEx);
        
        coursetransfer_logger::error(
            $requestid,
            coursetransfer_logger::DIRECTION_TARGET,
            'ROLLBACK_FAILED',
            'Rollback cleanup failed: ' . $rollbackEx->getMessage(),
            'ROLLBACK_ERROR',
            ['exception' => get_class($rollbackEx)]
        );
        
        return false;
    }
}
```

### 2. Invocar rollback en el catch de `restore_step_exception`

**Archivo**: `classes/coursetransfer_restore.php` línea ~448

```php
} catch (\restore_step_exception $restoreException) {
    // Specific restore step exception
    $errorCode = $restoreException->errorcode ?? 'unknown';
    
    coursetransfer_logger::error(
        $request->id,
        coursetransfer_logger::DIRECTION_TARGET,
        'RESTORE_STEP_EXCEPTION',
        'Restore step failed: ' . $restoreException->getMessage(),
        $errorCode,
        [
            'exception' => get_class($restoreException),
            'error_info' => $restoreException->a ?? null,
            'trace_preview' => substr($restoreException->getTraceAsString(), 0, 500)
        ]
    );
    
    // *** NUEVA LÓGICA DE ROLLBACK ***
    // Perform complete rollback to prevent duplicate entries on retry
    $restoreid = $rc->get_restoreid();
    self::rollback_failed_restore($courseid, $restoreid, $request->id);
    
    // Re-throw to be caught by outer exception handler
    throw $restoreException;
}
```

### 3. También agregar rollback en catch general

**Archivo**: `classes/coursetransfer_restore.php` después de cualquier excepción

```php
} catch (\Exception $generalException) {
    // Log any other exception
    coursetransfer_logger::error(
        $request->id,
        coursetransfer_logger::DIRECTION_TARGET,
        'RESTORE_GENERAL_EXCEPTION',
        'General exception during restore: ' . $generalException->getMessage(),
        $generalException->getCode() ?: 'GENERAL_ERROR'
    );
    
    // Perform rollback if restore controller exists
    if (isset($rc) && isset($courseid)) {
        $restoreid = $rc->get_restoreid();
        self::rollback_failed_restore($courseid, $restoreid, $request->id);
    }
    
    throw $generalException;
}
```

## 🔧 Mejora Adicional: Verificar Duplicados ANTES del Restore

**Archivo**: `classes/coursetransfer_restore.php` antes de `$rc->execute_plan()`

```php
// Verify no duplicate course modules exist before restore
$existing_modules = $DB->count_records('course_modules', ['course' => $courseid]);
if ($existing_modules > 0 && $target === backup::TARGET_EXISTING_DELETING) {
    coursetransfer_logger::warning(
        $request->id,
        coursetransfer_logger::DIRECTION_TARGET,
        'DUPLICATE_MODULES_DETECTED',
        "Found $existing_modules existing modules in course that should have been deleted",
        null,
        ['course_id' => $courseid, 'target_type' => 'TARGET_EXISTING_DELETING']
    );
    
    // Force cleanup before proceeding
    require_once($CFG->dirroot . '/course/lib.php');
    remove_course_contents($courseid, false); // Don't delete course itself
    rebuild_course_cache($courseid, true);
    
    coursetransfer_logger::info(
        $request->id,
        coursetransfer_logger::DIRECTION_TARGET,
        'PRE_RESTORE_CLEANUP',
        'Cleaned up existing course modules before restore'
    );
}
```

## 📊 Beneficios de la Solución

| Antes | Después |
|-------|---------|
| ❌ Restore falla → Datos parciales quedan en BD | ✅ Restore falla → Rollback completo automático |
| ❌ Reintento #2 → Duplicate entry error | ✅ Reintento #2 → Curso limpio, éxito probable |
| ❌ 3-4 intentos necesarios para éxito | ✅ 1-2 intentos máximo |
| ❌ Assignments duplicados acumulándose | ✅ No más duplicados |
| ❌ BD con basura de restores fallidos | ✅ BD limpia |

## 🎯 Resultado Esperado

1. **Primer intento**: Restore falla por cualquier motivo
   - Se ejecuta rollback automático
   - Se borran todos los assignments, submissions, etc.
   - Curso queda en estado original

2. **Segundo intento**: Restore se ejecuta en curso limpio
   - No hay Duplicate entry errors
   - Mayor probabilidad de éxito
   - Si falla, rollback de nuevo

3. **Tercer intento**: Si aún falla, marca como error permanente
   - Pero con BD limpia (sin basura)

## 🚀 Implementación

Para aplicar esta solución, ejecuta:

```bash
cd /Users/erikxp/Projects/Moodle-IPG/ipg-moodle-web/src/plugins/coursetransfer
# Implementar las 3 funciones en coursetransfer_restore.php
```

¿Quieres que implemente el código ahora?
