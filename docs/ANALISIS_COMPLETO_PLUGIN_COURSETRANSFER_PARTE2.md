# Análisis Completo del Plugin local_coursetransfer - PARTE 2

## 5. Tareas Programadas (Cron y Ad-hoc)

### 5.1. Tareas Ad-hoc (Ejecutadas bajo demanda)

#### 5.1.1. create_backup_course_task

**Propósito**: Crear el archivo .mbz del backup en el servidor origen.

**Cuándo se ejecuta**: Cuando Moodle B solicita un curso.

**Proceso detallado**:

```php
// 1. Carga el backup_controller guardado anteriormente
$bc = backup_controller::load_controller($backupid);

// 2. Verifica estado y ejecución
if ($bc->get_status() == backup::STATUS_AWAITING && 
    $bc->get_execution() == backup::EXECUTION_DELAYED) {
    
    // 3. Ejecuta el plan de backup
    $bc->execute_plan();
    
    // Internamente execute_plan() hace:
    // - Crea estructura XML del curso
    // - Copia archivos del curso
    // - Exporta tablas de BD relevantes
    // - Comprime todo en .mbz
    // - Almacena en mdl_files
}
```

**Funciones del Core**:
- `backup_controller::load_controller($backupid)`: Recupera controlador existente de `mdl_backup_controllers`
- `$bc->execute_plan()`: Ejecuta todas las tareas de backup secuencialmente
- `$bc->get_results()`: Retorna array con `['backup_destination' => stored_file]`

**Manejo de errores**:
- Si falla creación de archivo: Reintenta hasta 3 veces con delays de 0s, 10s, 30s
- Si no se puede crear URL: Error 13002
- Si falla ejecución: Error code del exception, notifica a destino

**Tablas afectadas**:
- `mdl_backup_controllers`: Guarda estado del controlador
- `mdl_files`: Almacena .mbz generado
- `local_coursetransfer_request`: Actualiza status a `STATUS_BACKUP (30)`
- `local_coursetransfer_log`: Registra cada paso

---

#### 5.1.2. download_file_course_task

**Propósito**: Descargar el archivo .mbz desde el origen al destino.

**Cuándo se ejecuta**: Después de que el backup esté listo y notificado.

**Proceso detallado**:

```php
// 1. Obtiene tamaño del archivo remoto
$ch = curl_init($fileurl);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch);
$filesize = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
curl_close($ch);

// 2. Decide estrategia según tamaño y memoria
$memory_limit = ini_get('memory_limit');
$use_streaming = ($filesize > ($memory_limit * 0.5));

// 3a. Descarga streaming (para archivos grandes)
if ($use_streaming) {
    $tempfile = tempnam(sys_get_temp_dir(), 'mbz_');
    $fp = fopen($tempfile, 'w+');
    
    $ch = curl_init($fileurl);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3600);
    
    // Progreso opcional
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($ch, $dltotal, $dlnow) {
        if ($dltotal > 0) {
            $progress = ($dlnow / $dltotal) * 100;
            mtrace("Downloaded: {$progress}%");
        }
    });
    
    curl_exec($ch);
    fclose($fp);
}

// 3b. Descarga directa (archivos pequeños)
else {
    $content = file_get_contents($fileurl);
    $tempfile = tempnam(sys_get_temp_dir(), 'mbz_');
    file_put_contents($tempfile, $content);
}

// 4. Crea stored_file en Moodle
$fs = get_file_storage();
$context = context_course::instance($target_course_id);

$fileinfo = [
    'contextid' => $context->id,
    'component' => 'backup',     // Componente que "posee" el archivo
    'filearea' => 'course',       // Área específica
    'itemid' => 0,
    'filepath' => '/',
    'filename' => 'local_coursetransfer_' . $courseid . '_' . time() . '.mbz',
];

// Crea desde pathname (no carga en memoria)
$file = $fs->create_file_from_pathname($fileinfo, $tempfile);

// 5. Limpia archivo temporal
unlink($tempfile);
```

**Funciones del Core**:
- `get_file_storage()`: Singleton del API de archivos de Moodle
- `context_course::instance($id)`: Obtiene contexto del curso
- `$fs->create_file_from_pathname($fileinfo, $path)`: Crea stored_file sin cargar en RAM
- `curl_*`: Funciones PHP para HTTP

**Manejo de errores**:
- Si falla descarga: Reintenta hasta 5 veces con backoff exponencial (60s base)
- Si archivo remoto no existe: Reintenta (puede no estar listo aún)
- Si target_course_id inválido: Error inmediato

**Tablas afectadas**:
- `mdl_files`: Nuevo registro del .mbz descargado
- `local_coursetransfer_request`: Actualiza `status=DOWNLOADED (70)` y guarda `fileid`
- `local_coursetransfer_log`: Registra progreso

**Tabla mdl_files - Campos importantes**:
```sql
SELECT 
    id,              -- fileid usado en restore_course_task
    contenthash,     -- Hash SHA1 del contenido
    contextid,       -- Contexto del curso destino
    component,       -- 'backup'
    filearea,        -- 'course'
    itemid,          -- 0
    filepath,        -- '/'
    filename,        -- 'local_coursetransfer_123_1234567890.mbz'
    filesize         -- Tamaño en bytes
FROM mdl_files
WHERE component = 'backup' AND filearea = 'course'
ORDER BY id DESC LIMIT 1;
```

---

#### 5.1.3. restore_course_task ⚠️ **TAREA MÁS CRÍTICA**

**Propósito**: Restaurar el curso desde el archivo .mbz.

**Cuándo se ejecuta**: Inmediatamente después de download_file_course_task.

**Proceso COMPLETO detallado**:

##### Paso 1: Control de Concurrencia (CRÍTICO)

**Problema**: Múltiples restores simultáneos corrompen `backup_ids_temp`.

**Solución**: Lock de exclusión mutua + Pre-check.

```php
// PRE-CHECK: Consulta directa a BD
$sql = "SELECT COUNT(*) 
        FROM {task_adhoc} 
        WHERE classname = :classname 
          AND id != :mytaskid 
          AND (nextruntime <= :now OR nextruntime IS NULL)";

$running = $DB->count_records_sql($sql, [
    'classname' => '\local_coursetransfer\task\restore_course_task',
    'mytaskid' => $this->get_id(),
    'now' => time()
]);

if ($running > 0) {
    // Hay otras tareas, reprogramar sin intentar lock
    $this->set_next_run_time(time() + 60);
    \core\task\manager::reschedule_or_queue_adhoc_task($this);
    return; // EXIT
}

// LOCK: Solo 1 restore a la vez en TODA la instancia
$lockfactory = \core\lock\lock_config::get_lock_factory('local_coursetransfer');
$lock = $lockfactory->get_lock('sequential_restore_execution', 10); // 10s timeout

if (!$lock) {
    // Otra tarea adquirió el lock entre pre-check y este momento
    // Reprogramar con backoff exponencial
    $backoff = min(90 * ($reschedule_count + 1), 300);
    $this->set_next_run_time(time() + $backoff);
    \core\task\manager::reschedule_or_queue_adhoc_task($this);
    return; // EXIT
}

// LOCK ADQUIRIDO - proceder
try {
    $this->do_restore(...);
} finally {
    $lock->release(); // SIEMPRE liberar
}
```

**Funciones del Core**:
- `\core\lock\lock_config::get_lock_factory($component)`: Factory de locks por componente
- `$factory->get_lock($resource, $timeout)`: Intenta adquirir lock
  - Retorna `lock` object o `false`
  - Timeout: cuánto esperar (10 segundos)
- `$lock->release()`: Libera el lock
- `\core\task\manager::reschedule_or_queue_adhoc_task($task)`: Reprograma tarea

**¿Por qué es crítico?**:
- Tabla `backup_ids_temp` es temporal y compartida
- Múltiples restores escriben mappings simultáneamente
- Corrupción causa error 10400: `restore_step_exception`

---

##### Paso 2: Recuperar archivo .mbz

```php
$fs = get_file_storage();
$file = $fs->get_file_by_id($fileid);

if (!$file) {
    // Error 11100: File not found
    
    // Intento de recuperación si tenemos fileurl
    if ($fileurl) {
        $file = $this->recover_missing_file($fileurl, $target_course_id, $origin_course_id);
    }
    
    if (!$file) {
        throw new \Exception('Error 11100: Backup file not found');
    }
}
```

**Funciones del Core**:
- `get_file_storage()`: Repositorio global de archivos
- `$fs->get_file_by_id($id)`: Busca en `mdl_files` por ID
  - Retorna `stored_file` object o `false`

**Error 11100 - Causas**:
1. Archivo eliminado entre descarga y restore
2. Retry anterior falló y archivo fue limpiado
3. ID incorrecto guardado en request

**Solución implementada**: 
- Guardar `fileurl` en custom_data de la tarea
- Si falta archivo, reintenta descarga "just-in-time"

---

##### Paso 3: Determinar curso destino

```php
// Opción A: Crear nuevo curso
if ($request->target_target == 2) { // TARGET_NEW_COURSE
    
    $targetcourseid = \restore_dbops::create_new_course(
        $request->origin_course_fullname,
        $request->origin_course_shortname,
        $request->target_category_id
    );
    
    // Guardar ID del curso creado
    $request->target_course_id = $targetcourseid;
    $DB->update_record('local_coursetransfer_request', $request);
}

// Opción B: Sobreescribir curso existente
else if ($request->target_target == 3) { // TARGET_EXISTING_DELETING
    $targetcourseid = $request->target_course_id;
    // El restore eliminará contenido existente
}

// Opción C: Añadir a curso existente
else if ($request->target_target == 4) { // TARGET_EXISTING_ADDING
    $targetcourseid = $request->target_course_id;
    // El restore agregará contenido
}
```

**Funciones del Core**:
- `\restore_dbops::create_new_course($fullname, $shortname, $categoryid)`: 
  - Crea registro en `mdl_course`
  - Asigna valores por defecto
  - Retorna `courseid`
  - **NO** crea contexto ni inscripciones aún

**Tablas afectadas**:
- `mdl_course`: Nuevo registro si es TARGET_NEW_COURSE

---

##### Paso 4: Crear restore_controller

```php
$admin = get_admin(); // Usuario administrador del sitio

$rc = new \restore_controller(
    $file->get_contenthash(),  // O filename
    $targetcourseid,
    \backup::INTERACTIVE_NO,   // No requiere interacción
    \backup::MODE_GENERAL,     // Modo estándar
    $admin->id,                // Usuario que ejecuta
    $request->target_target    // 2, 3 o 4
);

$restoreid = $rc->get_restoreid(); // ID único: cadena hexadecimal
```

**Funciones del Core**:
- `get_admin()`: Retorna objeto de usuario admin (ID=2 generalmente)
- `restore_controller::__construct()`: Constructor del controlador
  - Parámetro 1: Identificador del backup (hash o filename)
  - Parámetro 2: ID del curso destino
  - Parámetro 3: Modo interactivo (INTERACTIVE_NO)
  - Parámetro 4: Modo de operación (MODE_GENERAL, MODE_IMPORT, etc.)
  - Parámetro 5: ID de usuario
  - Parámetro 6: Target (2=nuevo, 3=deleting, 4=adding)
- `$rc->get_restoreid()`: Retorna ID único del restore (usado para mappings)

**Tabla mdl_backup_controllers**:
```sql
INSERT INTO mdl_backup_controllers (
    backupid,      -- restoreid generado
    operation,     -- 'restore'
    type,          -- 'course'
    itemid,        -- courseid
    format,        -- 'moodle'
    interactive,   -- 0
    purpose,       -- 1 (backup::MODE_GENERAL)
    userid,        -- admin ID
    status,        -- 800 (STATUS_REQUIRE_CONV)
    execution,     -- 1 (EXECUTION_INMEDIATE)
    executiontime, -- timestamp
    controller     -- Objeto serializado
)
```

---

##### Paso 5: Extraer backup a directorio temporal

```php
// Crear directorio temporal único
$tempdir = make_backup_temp_directory('coursetransfer_' . $restoreid);
// Ejemplo: /var/moodledata/temp/backup/coursetransfer_a1b2c3d4e5f6/

// Obtener descompresor
$packer = get_file_packer('application/vnd.moodle.backup');

// Extraer .mbz
$file->extract_to_pathname($packer, $tempdir);

// Resultado: 
// /var/moodledata/temp/backup/coursetransfer_xxx/
//     moodle_backup.xml
//     course/
//         course.xml
//         enrolments.xml
//         ...
//     activities/
//         quiz_123/
//             quiz.xml
//             questions.xml
//             attempts.xml
//         ...
//     users.xml
//     files.xml
//     ...
```

**Funciones del Core**:
- `make_backup_temp_directory($name)`: Crea dir en `/moodledata/temp/backup/`
- `get_file_packer($type)`: Retorna objeto packer para formato específico
  - `'application/vnd.moodle.backup'`: Para .mbz
  - `'application/zip'`: Para .zip
- `$file->extract_to_pathname($packer, $destpath)`: Extrae contenido
  - Método de `stored_file`
  - Usa `$packer` para descomprimir
  - Extrae a `$destpath`

---

##### Paso 6: MAPEO DE USUARIOS ⚠️ **MUY CRÍTICO**

**Ubicación**: `classes/user_mapper.php`

**Propósito**: Mapear usuarios del backup a usuarios existentes en destino.

**¿Por qué es crítico?**:
- Evita crear usuarios duplicados
- Preserva inscripciones y datos de estudiantes
- Si no se mapea: Moodle crea usuarios nuevos con username modificado

**Proceso COMPLETO**:

```php
class user_mapper {
    
    public function map_users(): bool {
        global $DB;
        
        // 1. Leer users.xml del backup extraído
        $usersfile = $this->tempdir . '/users.xml';
        
        if (!file_exists($usersfile)) {
            // No hay usuarios en el backup, nada que mapear
            return true;
        }
        
        // 2. Parsear XML
        $xml = simplexml_load_file($usersfile);
        
        $total_users = 0;
        $mapped_users = 0;
        
        // 3. Iterar cada usuario del backup
        foreach ($xml->user as $userxml) {
            $total_users++;
            
            $backup_userid = (int)$userxml->attributes()->id;
            $username = (string)$userxml->username;
            $email = (string)$userxml->email;
            $idnumber = (string)$userxml->idnumber;
            
            // 4. Buscar usuario existente en destino
            $existing = $DB->get_record('user', [
                'username' => $username,
                'deleted' => 0,
                'mnethostid' => $CFG->mnet_localhost_id
            ]);
            
            // Alternativa: Buscar por email o idnumber
            // if (!$existing && !empty($email)) {
            //     $existing = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);
            // }
            
            if ($existing) {
                // 5. Crear MAPPING en backup_ids_temp
                $mapping = new stdClass();
                $mapping->backupid = $this->restoreid;
                $mapping->itemname = 'user';
                $mapping->itemid = $backup_userid;      // ID en el backup
                $mapping->newitemid = $existing->id;    // ID en destino
                $mapping->parentitemid = 0;
                $mapping->info = 'mapped_by_plugin';
                
                // 6. Eliminar mapping previo si existe
                $DB->delete_records('backup_ids_temp', [
                    'backupid' => $this->restoreid,
                    'itemname' => 'user',
                    'itemid' => $backup_userid
                ]);
                
                // 7. Insertar nuevo mapping
                $DB->insert_record('backup_ids_temp', $mapping);
                
                $mapped_users++;
                
                // Log
                coursetransfer_logger::info(
                    $this->request->id,
                    'USER_MAPPED',
                    "User {$username} (backup ID {$backup_userid}) mapped to existing user ID {$existing->id}"
                );
            } else {
                // Usuario no existe en destino
                // El restore lo creará automáticamente
                coursetransfer_logger::warning(
                    $this->request->id,
                    'USER_NOT_FOUND',
                    "User {$username} (backup ID {$backup_userid}) not found in destination. Will be created."
                );
            }
        }
        
        coursetransfer_logger::info(
            $this->request->id,
            'USER_MAPPING_COMPLETE',
            "Mapped {$mapped_users} of {$total_users} users"
        );
        
        return true;
    }
}
```

**Funciones del Core**:
- `simplexml_load_file($path)`: Parsea XML
- `$DB->get_record('user', $conditions)`: Busca usuario único
- `$DB->insert_record('backup_ids_temp', $object)`: Inserta mapping

**Tabla backup_ids_temp**:
```sql
CREATE TABLE mdl_backup_ids_temp (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    backupid VARCHAR(32),    -- restoreid
    itemname VARCHAR(160),   -- 'user', 'course', 'course_module', etc.
    itemid BIGINT,           -- ID en el backup
    newitemid BIGINT,        -- ID en el destino
    parentitemid BIGINT,     -- ID del padre si aplica
    info TEXT                -- Información adicional (serializada)
);

-- Ejemplo de mappings:
-- backupid='abc123', itemname='user', itemid=5, newitemid=1042
-- backupid='abc123', itemname='user', itemid=8, newitemid=1055
-- backupid='abc123', itemname='course_module', itemid=100, newitemid=450
```

**¿Cómo usa Moodle estos mappings?**:

Durante `restore_controller->execute_plan()`, al procesar inscripciones, calificaciones, entregas, etc:

```php
// En restore_enrolments_structure_step.php
protected function process_enrolment($data) {
    global $DB;
    
    // userid viene del backup
    $data = (object)$data;
    $old_userid = $data->userid;
    
    // Buscar mapping
    $mapping = $this->get_mapping('user', $old_userid);
    
    if ($mapping) {
        // Usuario mapeado, usar ID existente
        $new_userid = $mapping->newitemid;
    } else {
        // Usuario no mapeado, crear nuevo
        $new_userid = $this->create_new_user($data);
    }
    
    // Inscribir con el ID correcto
    $enrol_plugin->enrol_user($instance, $new_userid, $data->roleid);
}
```

**⚠️ PROBLEMA IDENTIFICADO: Usuarios Duplicados**:

Si el mapeo falla o no se ejecuta:
```
Backup tiene:
  User ID 5: username="juan.perez", email="juan@example.com"

Destino YA tiene:
  User ID 1042: username="juan.perez", email="juan@example.com"

SIN MAPEO:
  Restore crea:
    User ID 2500: username="juan.perez2", email="juan@example.com"
  
  Resultado: DUPLICADO!
  - Calificaciones van a usuario nuevo
  - Entregas van a usuario nuevo
  - Usuario original no ve sus datos
```

**Solución**: El plugin implementa `user_mapper` que previene esto.

---

##### Paso 7: Configurar plan de restore

```php
$plan = $rc->get_plan();

// Configurar inscripciones
if ($request->target_remove_enrols) {
    // Eliminar inscripciones existentes
    $plan->get_setting('enrolments')->set_value(\backup::ENROL_WITHUSERS);
} else {
    // Mantener inscripciones existentes
    $plan->get_setting('enrolments')->set_value(\backup::ENROL_NEVER);
}

// Configurar grupos
if ($request->target_remove_groups) {
    $plan->get_setting('groups')->set_value(true);
} else {
    $plan->get_setting('groups')->set_value(false);
}

// Otros settings comunes
$plan->get_setting('users')->set_value(true);
$plan->get_setting('role_assignments')->set_value(true);
$plan->get_setting('activities')->set_value(true);
$plan->get_setting('blocks')->set_value(true);
$plan->get_setting('files')->set_value(true);
$plan->get_setting('filters')->set_value(true);
$plan->get_setting('comments')->set_value(true);
$plan->get_setting('userscompletion')->set_value(true);
$plan->get_setting('logs')->set_value(false);
$plan->get_setting('grade_histories')->set_value(true);
```

**Funciones del Core**:
- `$rc->get_plan()`: Retorna `restore_plan` object
- `$plan->get_setting($name)`: Retorna `restore_generic_setting` object
- `$setting->set_value($value)`: Establece valor
  - Valores posibles dependen del setting
  - `enrolments`: `ENROL_NEVER`, `ENROL_WITHUSERS`, `ENROL_ALWAYS`
  - Booleanos: `true/false`

---

##### Paso 8: Ejecutar PRE-CHECK

```php
if (!$rc->execute_precheck()) {
    $precheckresults = $rc->get_precheck_results();
    
    $errors = [];
    foreach ($precheckresults as $result) {
        if ($result->level == \backup::LOG_ERROR) {
            $errors[] = $result->message;
        }
    }
    
    throw new \Exception('Precheck failed: ' . implode(', ', $errors));
}
```

**Funciones del Core**:
- `$rc->execute_precheck()`: Valida que el restore es posible
  - Verifica estructura del backup
  - Valida compatibilidad de versiones
  - Comprueba permisos
  - Retorna `true` si OK, `false` si errores
- `$rc->get_precheck_results()`: Array de objetos con resultados
  - Cada objeto tiene: `level`, `message`, `code`

**¿Qué valida el precheck?**:
- Formato del .mbz es correcto
- Versión de Moodle compatible
- Todos los XML están presentes
- No faltan plugins críticos
- **NO valida** integridad de datos (quiz attempts, etc.)

---

##### Paso 9: Forzar nombres correctos

```php
// BUG de Moodle: A veces añade "copia de" o modifica nombres
// Solución: Forzar nombres ANTES del restore

$course = $DB->get_record('course', ['id' => $targetcourseid]);
$course->fullname = $request->origin_course_fullname;
$course->shortname = $request->origin_course_shortname;
$course->idnumber = $request->origin_course_idnumber;
$DB->update_record('course', $course);

// Rebuild cache
rebuild_course_cache($targetcourseid, true);
```

**Funciones del Core**:
- `$DB->get_record('course', $conditions)`: Obtiene registro de curso
- `$DB->update_record('course', $object)`: Actualiza curso
- `rebuild_course_cache($courseid, $clearonly)`: Reconstruye caché del curso

---

##### Paso 10: EJECUTAR RESTORE ⚠️ **MOMENTO CRÍTICO**

```php
try {
    // EJECUCIÓN DEL RESTORE
    $rc->execute_plan();
    
    // Verificar resultado
    if ($rc->get_status() == \backup::STATUS_FINISHED_OK) {
        // ✅ SUCCESS
        
        $request->status = coursetransfer_request::STATUS_COMPLETED;
        $request->target_course_id = $targetcourseid;
        $request->timemodified = time();
        $DB->update_record('local_coursetransfer_request', $request);
        
        coursetransfer_logger::success(
            $requestid,
            'RESTORE_COMPLETED',
            "Course restored successfully. Target course ID: {$targetcourseid}"
        );
        
    } else {
        throw new \Exception('Restore status not OK: ' . $rc->get_status());
    }
    
} catch (\restore_step_exception $e) {
    // Error 10400: Quiz/question attempts corruption
    
    $error_code = '10400';
    $error_msg = $e->getMessage();
    
    // Extraer información del stack trace
    $stack = $e->getTraceAsString();
    
    // Detectar si es problema de quiz attempts
    if (strpos($stack, 'restore_qtype_multichoice_plugin') !== false ||
        strpos($stack, 'get_mapping') !== false) {
        
        $error_msg = 'Quiz/question attempt restore issue. ' .
                     'Possible corrupted or missing question answers in backup. ' .
                     $error_msg;
    }
    
    // Log detallado
    coursetransfer_logger::error(
        $requestid,
        'RESTORE_STEP_EXCEPTION',
        $error_msg,
        $error_code,
        [
            'exception_class' => get_class($e),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
            'stack_trace' => $stack
        ]
    );
    
    // Actualizar request con error
    $request->status = coursetransfer_request::STATUS_ERROR;
    $request->error_code = $error_code;
    $request->error_message = substr($error_msg, 0, 1000);
    $DB->update_record('local_coursetransfer_request', $request);
    
    // Intentar retry si no se alcanzó el máximo
    if ($retryattempt < self::MAX_RETRY_ATTEMPTS) {
        $this->schedule_retry($requestid, $fileid, $fileurl, $retryattempt);
    }
    
    throw $e; // Re-throw para que la tarea falle
    
} catch (\Exception $e) {
    // Otros errores
    $this->handle_general_exception($e, $request);
    throw $e;
}
```

**Funciones del Core**:
- `$rc->execute_plan()`: **EJECUTA LA RESTAURACIÓN**
  - Proceso INTERNO:
    ```
    1. restore_decode_content: Decodifica contenido
    2. restore_enrolments_structure_step: Restaura inscripciones
    3. restore_groups_structure_step: Restaura grupos
    4. restore_course_structure_step: Restaura estructura del curso
    5. restore_activity_structure_step: Por cada actividad:
       - restore_quiz_activity_structure_step
       - restore_assign_activity_structure_step
       - etc.
    6. restore_final_task: Tareas finales
    7. restore_log_task: Opcionalmente restaura logs
    ```
- `$rc->get_status()`: Estado final
  - `STATUS_FINISHED_OK (1000)`: Éxito
  - `STATUS_FINISHED_ERR (1100)`: Error

**¿Qué hace execute_plan() internamente?**:

```php
// En backup/util/plan/restore_plan.class.php
public function execute() {
    // 1. Iterar cada tarea del plan
    foreach ($this->tasks as $task) {
        
        // 2. Ejecutar pasos de la tarea
        foreach ($task->get_steps() as $step) {
            $step->execute();
        }
    }
}

// Ejemplo: restore_quiz_activity_structure_step
protected function process_quiz_attempt($data) {
    global $DB;
    
    $data = (object)$data;
    $oldid = $data->id;
    
    // Mapear usuario
    $data->userid = $this->get_mappingid('user', $data->userid);
    
    // Mapear quiz
    $data->quiz = $this->get_new_parentid('quiz');
    
    // Insertar attempt
    $newitemid = $DB->insert_record('quiz_attempts', $data);
    
    // Guardar mapping
    $this->set_mapping('quiz_attempt', $oldid, $newitemid);
}

// Ejemplo: Procesar respuestas de quiz
protected function process_question_attempt_step($data) {
    $data = (object)$data;
    
    // Aquí es donde ocurre el ERROR 10400
    // Si el backup tiene answer ID que no existe
    
    if (!empty($data->answer)) {
        // Intentar mapear question_answer
        $newanswerid = $this->get_mappingid('question_answer', $data->answer);
        
        if (!$newanswerid) {
            // ❌ ERROR: No existe mapping para question_answer
            throw new restore_step_exception('question_answer_not_mapped', $data->answer);
        }
        
        $data->answer = $newanswerid;
    }
    
    $DB->insert_record('question_attempt_steps', $data);
}
```

**Tablas afectadas durante execute_plan()**:

Aproximadamente 50-100 tablas dependiendo del contenido:

```sql
-- Tablas del curso
mdl_course
mdl_course_sections
mdl_course_modules
mdl_course_format_options

-- Inscripciones
mdl_enrol
mdl_user_enrolments
mdl_role_assignments

-- Grupos
mdl_groups
mdl_groups_members
mdl_groupings
mdl_groupings_groups

-- Actividades (ejemplo: Quiz)
mdl_quiz
mdl_quiz_attempts
mdl_quiz_grades
mdl_question
mdl_question_attempts
mdl_question_attempt_steps
mdl_question_answers

-- Calificaciones
mdl_grade_items
mdl_grade_grades
mdl_grade_categories

-- Archivos
mdl_files (múltiples registros copiados)

-- Y muchas más...
```

---

##### Paso 11: Limpieza y finalización

```php
// 1. Destruir controlador
$rc->destroy();
// Elimina registro de mdl_backup_controllers
// Limpia backup_ids_temp

// 2. Eliminar archivo .mbz (opcional)
$file->delete();

// 3. Eliminar directorio temporal
remove_dir($tempdir);

// 4. Liberar memoria
gc_collect_cycles();

// 5. Notificar usuario
$this->send_completion_notification($request, $targetcourseid);

// 6. Liberar LOCK
if ($lock) {
    $lock->release();
}
```

**Funciones del Core**:
- `$rc->destroy()`: Limpia controlador
  - Elimina de `mdl_backup_controllers`
  - Limpia `backup_ids_temp` con este `backupid`
- `$file->delete()`: Marca archivo como eliminado en `mdl_files`
- `remove_dir($path)`: Elimina directorio recursivamente
- `gc_collect_cycles()`: Recolección de basura PHP

---

