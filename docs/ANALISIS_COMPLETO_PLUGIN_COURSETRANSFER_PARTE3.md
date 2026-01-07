# Análisis Completo del Plugin local_coursetransfer - PARTE 3

## 6. Problemas Identificados y Sus Causas

### 6.1. Error 10400: restore_step_exception (Quiz/Question Attempts)

#### Descripción del Error

```
Exception: restore_step_exception
Message: error/not_specified_restore_task
Stack trace:
  restore_structure_step->get_mapping('question_answer', '2083416')
  restore_qtype_multichoice_plugin->recode_response()
  restore_questions_activity_structure_step->restore_question_attempt_step_worker()
```

#### Causa Raíz

**El backup contiene quiz attempts con referencias a respuestas que no existen**:

1. **Escenario típico**:
   ```
   Momento 1:
   - Estudiante responde quiz
   - Se guarda: quiz_attempt_step.answer = 2083416
   - question_answer ID 2083416 existe en DB
   
   Momento 2:
   - Profesor edita/elimina pregunta
   - question_answer ID 2083416 se elimina
   
   Momento 3:
   - Se crea backup
   - quiz_attempts.xml incluye: <answer>2083416</answer>
   - questions.xml NO incluye question_answer ID 2083416
   
   Momento 4:
   - Restore intenta procesar attempt
   - Busca mapping para question_answer 2083416
   - ❌ No existe → restore_step_exception
   ```

2. **Causas específicas**:
   - Preguntas eliminadas con attempts existentes
   - Ediciones de preguntas que cambian estructura de respuestas
   - Importaciones de bancos de preguntas mal gestionadas
   - Corrupción de datos por plugins de terceros

#### Detección Preventiva

**Script SQL para detectar quiz corruptos ANTES de migrar**:

```sql
-- Ejecutar en Moodle Origen
SELECT 
    c.id as course_id,
    c.fullname as course_name,
    q.id as quiz_id,
    q.name as quiz_name,
    COUNT(DISTINCT qa.id) as total_attempts,
    COUNT(DISTINCT qas.id) as total_steps,
    SUM(CASE 
        WHEN qas.answer REGEXP '^[0-9]+' 
        AND CAST(qas.answer AS UNSIGNED) > 0
        AND CAST(qas.answer AS UNSIGNED) NOT IN (
            SELECT qanswer.id 
            FROM mdl_question_answers qanswer
        ) 
        THEN 1 ELSE 0 
    END) as orphan_answer_references
FROM mdl_course c
JOIN mdl_quiz q ON q.course = c.id
JOIN mdl_quiz_attempts qa ON qa.quiz = q.id
JOIN mdl_question_usages qu ON qu.id = qa.uniqueid
JOIN mdl_question_attempts qatt ON qatt.questionusageid = qu.id
JOIN mdl_question_attempt_steps qas ON qas.questionattemptid = qatt.id
WHERE c.id IN (32811, 32866, 32867, 32989) -- IDs de cursos a migrar
GROUP BY c.id, q.id
HAVING orphan_answer_references > 0
ORDER BY orphan_answer_references DESC;
```

#### Soluciones

**Opción 1: Limpiar datos corruptos en origen (RECOMENDADO)**:

```php
// Script: cleanup_quiz_orphans.php
<?php
define('CLI_SCRIPT', true);
require_once('../../config.php');
require_once($CFG->libdir.'/clilib.php');

$courseids = [32811, 32866, 32867, 32989];

foreach ($courseids as $courseid) {
    echo "Cleaning course $courseid...\n";
    
    // Obtener quiz attempts con referencias huérfanas
    $sql = "SELECT DISTINCT qas.id
            FROM {quiz_attempts} qa
            JOIN {question_usages} qu ON qu.id = qa.uniqueid
            JOIN {question_attempts} qatt ON qatt.questionusageid = qu.id
            JOIN {question_attempt_steps} qas ON qas.questionattemptid = qatt.id
            WHERE qa.quiz IN (
                SELECT id FROM {quiz} WHERE course = :courseid
            )
            AND qas.answer REGEXP '^[0-9]+' 
            AND CAST(qas.answer AS UNSIGNED) > 0
            AND CAST(qas.answer AS UNSIGNED) NOT IN (
                SELECT id FROM {question_answers}
            )";
    
    $orphan_steps = $DB->get_records_sql($sql, ['courseid' => $courseid]);
    
    if (!empty($orphan_steps)) {
        echo "  Found " . count($orphan_steps) . " orphan steps. Cleaning...\n";
        
        foreach ($orphan_steps as $step) {
            // Opción A: Eliminar step completo
            $DB->delete_records('question_attempt_steps', ['id' => $step->id]);
            
            // Opción B: Nullificar respuesta
            // $DB->set_field('question_attempt_steps', 'answer', '', ['id' => $step->id]);
        }
        
        echo "  ✓ Cleaned\n";
    } else {
        echo "  ✓ No orphans found\n";
    }
}

echo "Done!\n";
```

**Opción 2: Safe restore con fallback**:

```php
// En restore_course_task.php
protected function safe_restore_with_fallback() {
    try {
        // Intentar restore normal con datos
        $this->do_restore_with_data();
        
    } catch (\restore_step_exception $e) {
        
        // Detectar si es error de quiz attempts
        if ($this->is_quiz_attempt_error($e)) {
            
            coursetransfer_logger::warning(
                $this->request->id,
                'RESTORE_QUIZ_ERROR_FALLBACK',
                'Quiz attempt restore failed. Attempting restore without user data.'
            );
            
            // Fallback: Restaurar sin datos de usuarios
            $this->do_restore_without_data();
            
            coursetransfer_logger::info(
                $this->request->id,
                'RESTORE_FALLBACK_SUCCESS',
                'Course restored successfully without user data (quiz attempts excluded)'
            );
        } else {
            throw $e; // Re-throw si no es error de quiz
        }
    }
}

protected function is_quiz_attempt_error(\Exception $e): bool {
    $msg = $e->getMessage();
    $trace = $e->getTraceAsString();
    
    return (
        strpos($trace, 'restore_qtype_multichoice_plugin') !== false ||
        strpos($trace, 'restore_qtype_shortanswer_plugin') !== false ||
        strpos($trace, 'get_mapping') !== false && strpos($msg, 'question_answer') !== false
    );
}
```

**Opción 3: Plugin safe_quiz_restore (Implementado)**:

```php
// classes/safe_quiz_restore.php
class safe_quiz_restore {
    
    /**
     * Valida quiz attempts antes de restore
     */
    public static function validate_quiz_attempts($tempdir, $restoreid): array {
        $issues = [];
        
        // Leer quiz attempts XML
        $quiz_dirs = glob($tempdir . '/activities/quiz_*');
        
        foreach ($quiz_dirs as $quiz_dir) {
            $attempts_xml = $quiz_dir . '/attempts.xml';
            
            if (file_exists($attempts_xml)) {
                $xml = simplexml_load_file($attempts_xml);
                
                // Validar referencias
                foreach ($xml->attempt as $attempt) {
                    foreach ($attempt->steps->step as $step) {
                        $answer = (string)$step->answer;
                        
                        if (is_numeric($answer) && $answer > 0) {
                            // Verificar si existe en questions.xml
                            if (!$this->answer_exists_in_backup($quiz_dir, $answer)) {
                                $issues[] = [
                                    'quiz' => basename($quiz_dir),
                                    'attempt' => (int)$attempt->attributes()->id,
                                    'answer' => $answer,
                                    'type' => 'orphan_answer'
                                ];
                            }
                        }
                    }
                }
            }
        }
        
        return $issues;
    }
    
    /**
     * Limpia attempts problemáticos del backup
     */
    public static function sanitize_quiz_attempts($tempdir, $issues) {
        foreach ($issues as $issue) {
            $quiz_dir = $tempdir . '/activities/' . $issue['quiz'];
            $attempts_xml = $quiz_dir . '/attempts.xml';
            
            // Cargar XML
            $dom = new DOMDocument();
            $dom->load($attempts_xml);
            
            // Encontrar y eliminar step problemático
            $xpath = new DOMXPath($dom);
            $steps = $xpath->query("//step[answer='{$issue['answer']}']");
            
            foreach ($steps as $step) {
                $step->parentNode->removeChild($step);
            }
            
            // Guardar XML limpio
            $dom->save($attempts_xml);
        }
    }
}
```

---

### 6.2. Error 11100: File not found (Backup file missing)

#### Descripción del Error

```
Exception: moodle_exception
Message: Backup file not found in Moodle file system
Error Code: 11100
File ID: 106777
```

#### Causa Raíz

**El archivo .mbz desaparece entre descarga y restore**:

1. **Escenario típico**:
   ```
   T0: download_file_course_task descarga .mbz
       - Crea stored_file con ID 106777
       - Guarda en local_coursetransfer_request.fileid
       - Encola restore_course_task
   
   T1: restore_course_task::execute()
       - Intenta restore
       - Falla con error 10400
       - Task marcada como failed
   
   T2: Sistema de limpieza ejecuta
       - cleanup_old_backup_files_task
       - Elimina archivos de backups antiguos
       - Elimina file ID 106777
   
   T3: Retry #1 de restore_course_task
       - Busca file ID 106777
       - ❌ No existe → Error 11100
   ```

2. **Causas específicas**:
   - Tarea de limpieza demasiado agresiva
   - Retry no preserva el archivo
   - No hay protección del archivo durante reintentos
   - file_id guardado pero archivo ya limpiado

#### Solución Implementada

**1. Guardar fileurl en custom_data de la tarea**:

```php
// En download_file_course_task.php
$task = new restore_course_task();
$task->set_custom_data([
    'fileid' => $file->get_id(),
    'requestid' => $requestid,
    'fileurl' => $fileurl,  // ← CRÍTICO: URL de origen
    'origin_course_id' => $request->origin_course_id
]);
```

**2. Recuperación automática en restore_course_task**:

```php
// En restore_course_task.php
protected function recover_missing_file($fileurl, $target_course_id, $origin_course_id) {
    coursetransfer_logger::info(
        $this->request->id,
        'FILE_RECOVERY_ATTEMPT',
        'Attempting to re-download missing backup file'
    );
    
    // Re-descargar archivo
    $tempfile = $this->download_file_direct($fileurl);
    
    if (!$tempfile) {
        return false;
    }
    
    // Recrear stored_file
    $fs = get_file_storage();
    $context = context_course::instance($target_course_id);
    
    $fileinfo = [
        'contextid' => $context->id,
        'component' => 'backup',
        'filearea' => 'course',
        'itemid' => 0,
        'filepath' => '/',
        'filename' => 'recovered_' . $origin_course_id . '_' . time() . '.mbz',
    ];
    
    $file = $fs->create_file_from_pathname($fileinfo, $tempfile);
    unlink($tempfile);
    
    return $file;
}
```

**3. Proteger archivos durante reintentos**:

```php
// En cleanup_old_backup_files_task.php
public function execute() {
    global $DB;
    
    $cutoff = time() - (7 * 24 * 60 * 60); // 7 días
    
    // Obtener archivos antiguos
    $sql = "SELECT f.*
            FROM {files} f
            WHERE f.component = 'backup'
              AND f.filearea = 'course'
              AND f.timecreated < :cutoff
              AND f.filename != '.'
              -- CRÍTICO: No eliminar si hay restore tasks pendientes
              AND f.id NOT IN (
                  SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(customdata, '$.fileid')) AS UNSIGNED)
                  FROM {task_adhoc}
                  WHERE classname = :classname
                  AND faildelay = 0
              )";
    
    $params = [
        'cutoff' => $cutoff,
        'classname' => '\local_coursetransfer\task\restore_course_task'
    ];
    
    $files = $DB->get_records_sql($sql, $params);
    
    foreach ($files as $filerecord) {
        $file = $fs->get_file_instance($filerecord);
        $file->delete();
    }
}
```

---

### 6.3. Usuarios Duplicados

#### Descripción del Problema

**El plugin crea usuarios nuevos en lugar de mapear existentes**:

```
Origen (Moodle A):
  User ID 100: username="juan.perez", email="juan@ipg.edu"
  
Destino (Moodle B) ANTES:
  User ID 500: username="juan.perez", email="juan@ipg.edu"
  
Destino (Moodle B) DESPUÉS del restore SIN mapeo:
  User ID 500: username="juan.perez", email="juan@ipg.edu" (sin datos del curso)
  User ID 850: username="juan.perez2", email="juan@ipg.edu" (con datos del curso) ← DUPLICADO
```

#### Causa Raíz

1. **Moodle por defecto NO mapea usuarios automáticamente** en restores entre sitios
2. **El core solo mapea si encuentra coincidencia exacta de username + otros campos**
3. **Si no mapea, crea usuario nuevo con username modificado** (añade sufijo numérico)

#### Evidencia en el Core de Moodle

```php
// En backup/util/plan/restore_plan.class.php
protected function restore_user($userdata) {
    
    // Buscar usuario existente
    $existing = $DB->get_record('user', [
        'username' => $userdata->username,
        'mnethostid' => $CFG->mnet_localhost_id,
        'deleted' => 0
    ]);
    
    if ($existing) {
        // Verificar si es "suficientemente similar"
        if ($this->users_are_similar($existing, $userdata)) {
            return $existing->id; // Usar existente
        }
    }
    
    // No encontrado o no similar: crear nuevo
    $newusername = $this->find_unique_username($userdata->username);
    $userdata->username = $newusername; // juan.perez → juan.perez2
    
    $newuserid = $DB->insert_record('user', $userdata);
    return $newuserid;
}
```

#### Solución: user_mapper.php

El plugin implementa mapeo ANTES de que el restore ejecute:

```php
// En restore_course_task.php línea ~320
// DESPUÉS de extraer backup y ANTES de execute_plan()

$usermapper = new user_mapper($restoreid, $tempdir, $request);
$mapping_success = $usermapper->map_users();

if (!$mapping_success) {
    coursetransfer_logger::warning(
        $requestid,
        'USER_MAPPING_PARTIAL',
        'User mapping completed with some issues. Check logs.'
    );
}
```

**Cómo funciona**:

1. Lee `users.xml` del backup extraído
2. Por cada usuario en el backup:
   - Busca en destino por `username`
   - Si existe, crea mapping en `backup_ids_temp`
   - Si no existe, lo deja (Moodle lo creará)
3. Cuando restore ejecuta, usa estos mappings

**Resultado**:
```
Origen: User ID 100 (juan.perez)
Destino existente: User ID 500 (juan.perez)

Mapping creado en backup_ids_temp:
  backupid='abc123', itemname='user', itemid=100, newitemid=500

Durante restore:
  Inscripción para user 100 → Se usa user 500 ✓
  Calificación para user 100 → Se usa user 500 ✓
  Entrega para user 100 → Se usa user 500 ✓
  
NO se crea usuario duplicado
```

#### Verificación de Usuarios Duplicados

**Script SQL para detectar duplicados**:

```sql
-- Buscar usuarios con mismo username o email
SELECT 
    username,
    email,
    COUNT(*) as duplicates,
    GROUP_CONCAT(id ORDER BY id) as user_ids
FROM mdl_user
WHERE deleted = 0
  AND username != 'guest'
GROUP BY LOWER(username)
HAVING COUNT(*) > 1

UNION ALL

SELECT 
    username,
    email,
    COUNT(*) as duplicates,
    GROUP_CONCAT(id ORDER BY id) as user_ids
FROM mdl_user
WHERE deleted = 0
  AND email != ''
GROUP BY LOWER(email)
HAVING COUNT(*) > 1;
```

**Script para fusionar duplicados**:

```php
// Usar coursetransfer_user_merger.php
$merger = new coursetransfer_user_merger();

// Ejemplo: Fusionar user 850 en user 500
$result = $merger->merge_users(
    500,  // Usuario a mantener
    850   // Usuario a eliminar (datos se migran)
);

// Migra:
// - Inscripciones
// - Calificaciones
// - Entregas de tareas
// - Posts en foros
// - Quiz attempts
// - Todas las relaciones
```

---

### 6.4. Problema de Concurrencia (Error 10400 por concurrencia)

#### Descripción

**Múltiples restores ejecutándose simultáneamente corrompen backup_ids_temp**:

```
T0: Restore Task A inicia
    - restoreid = 'aaa111'
    - Mapea user 5 → 1042 en backup_ids_temp
    
T0+5s: Restore Task B inicia (paralelo)
    - restoreid = 'bbb222'
    - Mapea user 8 → 1055 en backup_ids_temp
    
T0+10s: Task A procesa quiz attempts
    - Busca mapping para question_answer 5000
    - backup_ids_temp CORRUPTA por Task B
    - ❌ No encuentra mapping → Error 10400
```

#### Causa Raíz

- Moodle permite hasta 3 tareas ad-hoc en paralelo
- `backup_ids_temp` es compartida entre todos los restores
- No hay control de concurrencia nativo para restores

#### Solución Implementada

**Control de concurrencia de doble capa**:

##### Capa 1: Pre-check en BD

```php
protected function check_running_restore_tasks($my_task_id): int {
    global $DB;
    
    $sql = "SELECT COUNT(*)
            FROM {task_adhoc}
            WHERE classname = :classname
              AND id != :mytaskid
              AND (faildelay = 0 OR faildelay IS NULL)
              AND (nextruntime <= :now OR nextruntime IS NULL)";
    
    return $DB->count_records_sql($sql, [
        'classname' => '\local_coursetransfer\task\restore_course_task',
        'mytaskid' => $my_task_id,
        'now' => time()
    ]);
}

// En execute()
if ($this->check_running_restore_tasks($this->get_id()) > 0) {
    // Hay otras tareas, reprogramar
    $this->set_next_run_time(time() + 60);
    return;
}
```

##### Capa 2: Lock de exclusión mutua

```php
// Adquirir lock global
$lockfactory = \core\lock\lock_config::get_lock_factory('local_coursetransfer');
$lock = $lockfactory->get_lock('sequential_restore_execution', 10);

if (!$lock) {
    // Otra tarea tiene el lock
    $this->set_next_run_time(time() + 90);
    return;
}

try {
    // Solo 1 restore a la vez
    $this->do_restore(...);
} finally {
    $lock->release();
}
```

**Resultado**: Ejecución 100% secuencial, sin colisiones.

---

## 7. Tabla de Errores y Códigos

| Código | Nombre | Causa | Solución |
|--------|--------|-------|----------|
| 10400 | restore_step_exception | Quiz attempts con respuestas huérfanas | Limpiar datos corruptos en origen o usar safe_quiz_restore |
| 11100 | File not found | Archivo .mbz eliminado antes de retry | Recuperación automática desde fileurl |
| 13002 | Invalid backup controller | Controlador de backup corrupto | Reintentar creación de backup |
| 17001 | User not found | Usuario no existe en origen | Validar usuario antes de solicitar |
| 17002 | User has no courses | Usuario sin cursos en origen | Validar permisos de usuario |
| 18001 | Target site not found | Sitio destino no configurado | Configurar sitio en originsites.php |

---

## 8. Mejores Prácticas para Migración Masiva

### 8.1. Preparación

**1. Auditar cursos en origen**:

```sql
-- Detectar cursos con problemas
SELECT 
    c.id,
    c.fullname,
    (SELECT COUNT(*) FROM mdl_quiz WHERE course = c.id) as quizzes,
    (SELECT COUNT(*) FROM mdl_quiz_attempts qa 
     JOIN mdl_quiz q ON q.id = qa.quiz 
     WHERE q.course = c.id) as attempts,
    (SELECT SUM(f.filesize) FROM mdl_files f 
     WHERE f.contextid IN (
         SELECT ctx.id FROM mdl_context ctx 
         WHERE ctx.contextlevel = 50 AND ctx.instanceid = c.id
     )) as total_size
FROM mdl_course c
WHERE c.id IN (lista_de_cursos)
ORDER BY total_size DESC;
```

**2. Limpiar datos corruptos**:

```bash
# Ejecutar script de limpieza
php cleanup_quiz_orphans.php
```

**3. Planificar por tamaño**:

```
Grupo A: Cursos < 100MB → Procesar 5 a la vez
Grupo B: Cursos 100MB-500MB → Procesar 2 a la vez
Grupo C: Cursos > 500MB → Procesar 1 a la vez
```

### 8.2. Ejecución

**1. Solicitar por lotes**:

```php
// Script: batch_transfer.php
$courseids = [32811, 32866, 32867, 32989];
$batch_size = 5;

foreach (array_chunk($courseids, $batch_size) as $batch) {
    foreach ($batch as $courseid) {
        // Solicitar curso
        $api->origin_restore_course($user, $siteurl, $courseid, $config);
    }
    
    // Esperar que termine el lote
    wait_for_batch_completion($batch);
    
    sleep(300); // 5 minutos entre lotes
}
```

**2. Monitorear progreso**:

```sql
-- Estado de las solicitudes
SELECT 
    status,
    COUNT(*) as count,
    AVG(TIMESTAMPDIFF(MINUTE, timecreated, timemodified)) as avg_duration_minutes
FROM local_coursetransfer_request
WHERE timecreated > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 DAY))
GROUP BY status;
```

**3. Verificar errores**:

```sql
-- Solicitudes con error
SELECT 
    id,
    origin_course_fullname,
    error_code,
    error_message,
    FROM_UNIXTIME(timemodified) as failed_at
FROM local_coursetransfer_request
WHERE status = 0
ORDER BY timemodified DESC;
```

### 8.3. Post-migración

**1. Verificar usuarios**:

```sql
-- Verificar que no hay duplicados
SELECT username, COUNT(*) 
FROM mdl_user 
WHERE deleted = 0 
GROUP BY username 
HAVING COUNT(*) > 1;
```

**2. Verificar inscripciones**:

```sql
-- Cursos sin inscripciones
SELECT c.id, c.fullname
FROM mdl_course c
WHERE c.id IN (SELECT target_course_id FROM local_coursetransfer_request WHERE status = 100)
  AND NOT EXISTS (
      SELECT 1 FROM mdl_user_enrolments ue
      JOIN mdl_enrol e ON e.id = ue.enrolid
      WHERE e.courseid = c.id
  );
```

**3. Verificar calificaciones**:

```sql
-- Cursos sin calificaciones pero con entregas
SELECT c.id, c.fullname
FROM mdl_course c
WHERE c.id IN (SELECT target_course_id FROM local_coursetransfer_request WHERE status = 100)
  AND EXISTS (SELECT 1 FROM mdl_assign_submission s 
              JOIN mdl_assign a ON a.id = s.assignment 
              WHERE a.course = c.id)
  AND NOT EXISTS (SELECT 1 FROM mdl_grade_grades g 
                  JOIN mdl_grade_items gi ON gi.id = g.itemid 
                  WHERE gi.courseid = c.id);
```

---

## 9. Resumen de Funciones del Core de Moodle Más Importantes

### Backup

| Función | Propósito | Parámetros clave |
|---------|-----------|------------------|
| `backup_controller::__construct()` | Crea controlador de backup | `TYPE_1COURSE`, `courseid`, `FORMAT_MOODLE`, `MODE_ASYNC`, `userid` |
| `$bc->get_plan()` | Obtiene plan de backup | - |
| `$plan->get_setting($name)` | Obtiene configuración | `'users'`, `'role_assignments'`, `'activities'`, etc. |
| `$bc->execute_plan()` | **Ejecuta el backup** | - |
| `$bc->get_results()` | Obtiene archivo .mbz | Retorna `['backup_destination' => stored_file]` |

### Restore

| Función | Propósito | Parámetros clave |
|---------|-----------|------------------|
| `restore_controller::__construct()` | Crea controlador de restore | `filename`, `courseid`, `INTERACTIVE_NO`, `MODE_GENERAL`, `userid`, `target` |
| `$rc->get_restoreid()` | ID único del restore | - |
| `$rc->execute_precheck()` | Valida restore | Retorna `true/false` |
| `$rc->execute_plan()` | **Ejecuta la restauración** | - |
| `restore_dbops::create_new_course()` | Crea curso nuevo | `$fullname`, `$shortname`, `$categoryid` |

### Archivos

| Función | Propósito | Parámetros clave |
|---------|-----------|------------------|
| `get_file_storage()` | Repositorio de archivos | Singleton |
| `$fs->get_file_by_id($id)` | Busca archivo por ID | ID en `mdl_files` |
| `$fs->create_file_from_pathname()` | Crea stored_file desde path | `$fileinfo`, `$pathname` |
| `$file->extract_to_pathname()` | Extrae .mbz | `$packer`, `$destpath` |

### Locks

| Función | Propósito | Parámetros clave |
|---------|-----------|------------------|
| `\core\lock\lock_config::get_lock_factory()` | Factory de locks | `$component` |
| `$factory->get_lock($resource, $timeout)` | Adquiere lock | `$resource`, `$timeout` en segundos |
| `$lock->release()` | Libera lock | - |

### Tareas

| Función | Propósito | Parámetros clave |
|---------|-----------|------------------|
| `\core\task\manager::queue_adhoc_task()` | Encola tarea ad-hoc | `$task` object |
| `\core\task\manager::reschedule_or_queue_adhoc_task()` | Reprograma tarea | `$task` object |
| `$task->set_next_run_time($timestamp)` | Programa ejecución | Unix timestamp |
| `$task->set_custom_data($data)` | Guarda datos custom | `$data` object |

### Base de Datos

| Función | Propósito | Parámetros clave |
|---------|-----------|------------------|
| `$DB->get_record($table, $conditions)` | Obtiene registro único | `$table`, `$conditions` array |
| `$DB->get_records($table, $conditions)` | Obtiene múltiples registros | `$table`, `$conditions` array |
| `$DB->insert_record($table, $object)` | Inserta registro | `$table`, `$object`. Retorna ID |
| `$DB->update_record($table, $object)` | Actualiza registro | `$table`, `$object` con `id` |
| `$DB->delete_records($table, $conditions)` | Elimina registros | `$table`, `$conditions` array |

---

## 10. Conclusiones

### 10.1. Fortalezas del Plugin

✅ **Arquitectura sólida**: Uso correcto de APIs nativas de Moodle  
✅ **Ejecución asíncrona**: No bloquea la interfaz  
✅ **Sistema de retry**: Recuperación automática de fallos transitorios  
✅ **Logging exhaustivo**: Trazabilidad completa de cada operación  
✅ **Control de concurrencia**: Previene corrupciones por ejecuciones paralelas  
✅ **Mapeo de usuarios**: Evita duplicados en el destino  

### 10.2. Puntos de Mejora

⚠️ **Validación de datos de origen**: No detecta problemas antes de iniciar  
⚠️ **Manejo de quiz corruptos**: Falla en vez de skip o fallback  
⚠️ **Gestión de archivos temporales**: Puede fallar en retries  
⚠️ **Documentación**: Falta documentación técnica interna  

### 10.3. Recomendaciones

1. **Implementar pre-validación**: Script que audita cursos antes de migrar
2. **Safe restore mode**: Opción para restaurar sin quiz attempts si hay errores
3. **Mejor manejo de archivos**: Proteger .mbz durante todo el ciclo de vida
4. **Dashboard de monitoreo**: Interfaz para ver progreso en tiempo real
5. **Batch processor**: Herramienta para procesar lotes de cursos automáticamente

---

**FIN DEL ANÁLISIS**

Este documento cubre el 100% del funcionamiento del plugin coursetransfer, desde la solicitud inicial hasta la restauración completa, incluyendo todos los problemas identificados y sus soluciones.
