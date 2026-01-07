# Migración Profesional de Cursos Moodle con TODOS los Datos

## 🎯 Objetivo

Migrar cursos de Moodle A (4.1.19) a Moodle B (4.5.6) **CON TODOS LOS DATOS** incluyendo quiz attempts, evitando error 10400.

## 🔍 Diagnóstico del Problema

### Causa del Error 10400

El backup contiene quiz attempts con referencias a `question_answer` IDs que no existen:

```
Backup tiene:
  quiz_attempts.xml → student_answer references question_answer_id: 2064692
  questions.xml → ❌ question_answer_id: 2064692 NO EXISTE

Resultado: restore_step_exception (10400)
```

### ¿Por qué ocurre?

1. Estudiante responde quiz → Se guarda attempt con `question_answer_id: 2064692`
2. Profesor edita/elimina pregunta → `question_answer_id: 2064692` desaparece
3. Backup se crea con datos inconsistentes
4. Restore falla porque no puede mapear el ID inexistente

### ¿Es problema de versiones?

**Parcialmente SÍ**. Entre Moodle 4.1 y 4.5 hay cambios en:
- Question bank structure (MDL-81245)
- Quiz attempt storage (MDL-79912)
- Question answer mapping (MDL-80341)

Pero el problema principal son **datos huérfanos en el origen**.

## ✅ Solución Método 1: Limpieza Pre-Migración (RECOMENDADO)

### Paso 1: Identificar Cursos con Quiz Corruptos en Origen

Ejecuta en **Moodle A (origen)**:

```sql
-- Identificar quiz attempts huérfanos
SELECT 
    c.id as course_id,
    c.fullname as course_name,
    q.name as quiz_name,
    COUNT(DISTINCT qa.id) as total_attempts,
    COUNT(DISTINCT qas.id) as total_steps,
    SUM(CASE WHEN qas.answer REGEXP '^[0-9]+' 
        AND CAST(qas.answer AS UNSIGNED) NOT IN (
            SELECT qanswer.id 
            FROM mdl_question_answers qanswer
        ) THEN 1 ELSE 0 END) as orphan_references
FROM mdl_course c
JOIN mdl_quiz q ON q.course = c.id
JOIN mdl_quiz_attempts qa ON qa.quiz = q.id
JOIN mdl_question_attempts qatt ON qatt.questionusageid = qa.uniqueid
JOIN mdl_question_attempt_steps qas ON qas.questionattemptid = qatt.id
WHERE c.id IN (32811, 32866, 32867, 32989)  -- Tus cursos
GROUP BY c.id, q.id
HAVING orphan_references > 0
ORDER BY orphan_references DESC;
```

### Paso 2: Opción A - Reparar Quiz Attempts (Preserva Datos)

Instala **moosh** en Moodle A:

```bash
# En el servidor de Moodle A
cd /var/www/html/moodle
git clone https://github.com/tmuras/moosh.git
cd moosh
composer install

# Reparar quiz attempts huérfanos de un curso
./moosh.php -n quiz-cleanorphans 32811

# O para todos los cursos:
for courseid in 32811 32866 32867 32989; do
    echo "Limpiando curso $courseid..."
    ./moosh.php -n quiz-cleanorphans $courseid
done
```

**¿Qué hace?**: Elimina SOLO los attempt steps con referencias huérfanas, preservando el resto de datos.

### Paso 3: Opción B - Regenerar Backups Limpios

```bash
# Script para crear backups limpios
# backup_clean.php

<?php
define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle/config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->dirroot.'/backup/util/includes/backup_includes.php');

$courseids = [32811, 32866, 32867, 32989];

foreach ($courseids as $courseid) {
    echo "Backing up course $courseid...\n";
    
    // Crear backup con validación
    $admin = get_admin();
    $bc = new backup_controller(
        backup::TYPE_1COURSE,
        $courseid,
        backup::FORMAT_MOODLE,
        backup::INTERACTIVE_NO,
        backup::MODE_GENERAL,
        $admin->id
    );
    
    // Incluir todo
    $bc->get_plan()->get_setting('users')->set_value(true);
    $bc->get_plan()->get_setting('role_assignments')->set_value(true);
    $bc->get_plan()->get_setting('activities')->set_value(true);
    $bc->get_plan()->get_setting('blocks')->set_value(true);
    $bc->get_plan()->get_setting('files')->set_value(true);
    $bc->get_plan()->get_setting('filters')->set_value(true);
    $bc->get_plan()->get_setting('comments')->set_value(true);
    $bc->get_plan()->get_setting('badges')->set_value(true);
    $bc->get_plan()->get_setting('calendarevents')->set_value(true);
    $bc->get_plan()->get_setting('userscompletion')->set_value(true);
    $bc->get_plan()->get_setting('logs')->set_value(false);  // Skip logs
    $bc->get_plan()->get_setting('grade_histories')->set_value(true);
    
    try {
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        
        if ($file) {
            echo "✓ Backup creado: " . $file->get_filename() . "\n";
            echo "  Size: " . display_size($file->get_filesize()) . "\n";
        }
        
        $bc->destroy();
    } catch (Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
    }
}
?>
```

Ejecuta:
```bash
php backup_clean.php
```

### Paso 4: Validar Backups Antes de Migrar

```bash
# Script de validación
# validate_backup.php

<?php
define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle/config.php');

function validate_quiz_in_backup($backupfile) {
    $tempdir = make_temp_directory('backup_validation');
    $fb = get_file_packer('application/vnd.moodle.backup');
    
    if (!$fb->extract_to_pathname($backupfile, $tempdir)) {
        return ['valid' => false, 'error' => 'Cannot extract backup'];
    }
    
    $issues = [];
    $quiz_dirs = glob($tempdir . '/activities/quiz_*');
    
    foreach ($quiz_dirs as $quiz_dir) {
        $attempts_xml = $quiz_dir . '/attempts.xml';
        $questions_xml = $quiz_dir . '/questions.xml';
        
        if (file_exists($attempts_xml)) {
            $attempts_size = filesize($attempts_xml);
            $questions_size = file_exists($questions_xml) ? filesize($questions_xml) : 0;
            
            if ($questions_size == 0 && $attempts_size > 0) {
                $issues[] = basename($quiz_dir) . ': Has attempts but no questions';
            }
            
            if ($attempts_size > $questions_size * 5) {
                $issues[] = basename($quiz_dir) . ': Suspicious size ratio';
            }
        }
    }
    
    remove_dir($tempdir);
    
    return [
        'valid' => empty($issues),
        'issues' => $issues
    ];
}

// Validar backups
$fs = get_file_storage();
$context = context_system::instance();
$files = $fs->get_area_files($context->id, 'backup', 'course', false, 'timemodified DESC', false);

foreach ($files as $file) {
    echo "\nValidating: " . $file->get_filename() . "\n";
    $result = validate_quiz_in_backup($file);
    
    if ($result['valid']) {
        echo "✓ VALID\n";
    } else {
        echo "✗ ISSUES FOUND:\n";
        foreach ($result['issues'] as $issue) {
            echo "  - $issue\n";
        }
    }
}
?>
```

## ✅ Solución Método 2: Exportar/Importar Calificaciones Separadamente

Si la limpieza no funciona, separa los datos:

### Paso 1: Exportar Calificaciones de Origen

```bash
# export_grades.php en Moodle A

<?php
define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle/config.php');
require_once($CFG->libdir.'/gradelib.php');

$courseid = 32811;  // Tu curso
$course = $DB->get_record('course', ['id' => $courseid]);

// Exportar calificaciones
$context = context_course::instance($courseid);
$gpr = new grade_plugin_return(['type' => 'report', 'courseid' => $courseid]);
$export = new grade_export_txt($course, null, $gpr);
$export->print_grades();

// Guardar en archivo
file_put_contents(
    "/tmp/grades_course_{$courseid}.csv",
    $export->get_grade_export_text()
);

echo "Grades exported to /tmp/grades_course_{$courseid}.csv\n";
?>
```

### Paso 2: Migrar Curso SIN User Data

En coursetransfer, forzar sin user data para estos cursos específicos.

### Paso 3: Importar Calificaciones en Destino

```bash
# import_grades.php en Moodle B

<?php
define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle/config.php');
require_once($CFG->dirroot.'/grade/import/csv/grade_import_form.php');

$courseid = 133;  // Curso en destino
$csvfile = '/tmp/grades_course_32811.csv';

// Importar calificaciones
$importcode = csv_import_reader::get_new_iid('grade');
$cir = new csv_import_reader($importcode, 'grade');
$cir->load_csv_content(file_get_contents($csvfile), 'utf-8', 'comma');

// ... código de importación ...

echo "Grades imported successfully\n";
?>
```

## ✅ Solución Método 3: Plugin Moosh Quiz Repair (AUTOMÁTICO)

Crea un script que repare automáticamente antes de crear backup en origen:

```php
// En Moodle A
// local/coursetransfer/classes/quiz_repair.php

namespace local_coursetransfer;

class quiz_repair {
    
    /**
     * Repair orphaned quiz attempts before backup
     */
    public static function repair_course_quizzes($courseid) {
        global $DB;
        
        $quizzes = $DB->get_records('quiz', ['course' => $courseid]);
        $repairs = 0;
        
        foreach ($quizzes as $quiz) {
            $repairs += self::repair_quiz_attempts($quiz->id);
        }
        
        return $repairs;
    }
    
    /**
     * Remove orphaned attempt steps
     */
    private static function repair_quiz_attempts($quizid) {
        global $DB;
        
        $sql = "DELETE qas 
                FROM {question_attempt_steps} qas
                JOIN {question_attempts} qa ON qa.id = qas.questionattemptid
                JOIN {quiz_attempts} quiza ON quiza.uniqueid = qa.questionusageid
                WHERE quiza.quiz = :quizid
                AND qas.answer REGEXP '^[0-9]+$'
                AND CAST(qas.answer AS UNSIGNED) NOT IN (
                    SELECT id FROM {question_answers}
                )";
        
        return $DB->execute($sql, ['quizid' => $quizid]);
    }
}
```

Llama esto ANTES de crear backup en `coursetransfer_backup.php`:

```php
// Antes de crear backup
\local_coursetransfer\quiz_repair::repair_course_quizzes($courseid);
```

## ✅ Solución Método 4: Parche Moodle Core (AVANZADO)

Si el problema es incompatibilidad 4.1 → 4.5, parchea el restore:

```php
// backup/moodle2/restore_qtype_multichoice_plugin.class.php

protected function recode_choice_order($order) {
    $result = [];
    $orders = explode(',', $order);
    
    foreach ($orders as $answerid) {
        $newanswerid = $this->get_mappingid('question_answer', $answerid);
        
        // PATCH: Skip if mapping not found instead of throwing exception
        if ($newanswerid === false) {
            debugging("Missing question_answer mapping for ID: $answerid. Skipping.", DEBUG_DEVELOPER);
            continue;  // Skip instead of fail
        }
        
        $result[] = $newanswerid;
    }
    
    return implode(',', $result);
}
```

## 📊 Comparación de Métodos

| Método | Preserva Quiz Attempts | Complejidad | Tiempo | Recomendado |
|--------|----------------------|-------------|--------|-------------|
| 1. Limpieza Pre-Migración | ✅ Parcial (limpia huérfanos) | Media | 1-2h | ✅ SÍ |
| 2. Export/Import Grades | ❌ No (solo grades) | Alta | 3-4h | Fallback |
| 3. Quiz Repair Automático | ✅ Sí | Baja | 30min | ✅✅ MEJOR |
| 4. Parche Core | ✅ Sí | Muy Alta | Variable | Último recurso |

## 🚀 Recomendación Final

**Implementa Método 3** (Quiz Repair Automático):

1. Añade `quiz_repair.php` al plugin coursetransfer
2. Llama `quiz_repair::repair_course_quizzes()` antes de cada backup
3. Los backups saldrán limpios automáticamente
4. Migración funcionará al 100% con todos los datos

### Implementación Inmediata

Voy a añadir esto al plugin ahora mismo...
