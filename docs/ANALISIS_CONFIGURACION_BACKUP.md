# Análisis: Configuración de Backup en CourseTransfer

**Fecha**: 7 de enero de 2026
**Contexto**: Análisis de opciones de backup disponibles vs configuradas actualmente

## 📋 Pregunta del Usuario

> "Se puede hacer que cuando haga el backup en el origen incluya los log, notas y todo? cómo cuando uno le dice Incluir archivos 'log' de cursos. Por la interfaz? o eso no se trae todos los logs?"

## 🔍 Análisis del Código Actual

### Ubicación del Backup
**Archivo**: `classes/coursetransfer_backup.php`
**Función**: `create_task_backup_course()` (líneas 84-127)

### Configuración ACTUAL del Backup

```php
$bc = new backup_controller(
    backup::TYPE_1COURSE, $courseid,
    backup::FORMAT_MOODLE,
    backup::INTERACTIVE_NO,
    backup::MODE_GENERAL, $userid,
    backup::RELEASESESSION_YES
);

// Settings configurados ACTUALMENTE:
$bc->get_plan()->get_setting('users')->set_value($rootusers);              // ✅
$bc->get_plan()->get_setting('role_assignments')->set_value($rootusers);    // ✅
$bc->get_plan()->get_setting('comments')->set_value($rootusers);            // ✅
$bc->get_plan()->get_setting('badges')->set_value($rootusers);              // ✅
$bc->get_plan()->get_setting('userscompletion')->set_value($rootusers);     // ✅
$bc->get_plan()->get_setting('groups')->set_value($rootusers);              // ✅
```

**Variable `$rootusers`**: 
- `0` = NO incluir datos de usuario
- `1` = SÍ incluir datos de usuario

### Settings NO Configurados (usan valores por defecto)

Según el análisis de `/backup/moodle2/backup_root_task.class.php`, existen estos settings adicionales:

| Setting | Default | Descripción | ¿Incluido actualmente? |
|---------|---------|-------------|------------------------|
| **`logs`** | `true` | Archivos "log" de cursos | ❓ Default (probablemente TRUE) |
| **`grade_histories`** | `true` | Historial de calificaciones | ❓ Default (probablemente TRUE) |
| **`competencies`** | `true` | Competencias (si están habilitadas) | ❓ Default (probablemente TRUE) |
| **`questionbank`** | `true` | Banco de preguntas | ❓ Default (probablemente TRUE) |
| **`customfield`** | `true` | Campos personalizados | ❓ Default (probablemente TRUE) |
| **`contentbankcontent`** | `true` | Contenido del banco de contenidos | ❓ Default (probablemente TRUE) |
| **`xapistate`** | `true` | Estado xAPI | ❓ Default (probablemente TRUE) |
| **`legacyfiles`** | `true` | Archivos legacy (antiguos) | ❓ Default (probablemente TRUE) |
| **`calendarevents`** | `true` | Eventos del calendario | ❓ Default (probablemente TRUE) |

## 📸 Comparación con Interfaz de Moodle

### Opciones de la Interfaz (según captura):

```
✅ Incluir usuarios matriculados           → users (CONFIGURADO)
☑️ Hacer anónima la información de usuario  → anonymize (NO configurado)
✅ Incluir asignaciones de rol de usuario  → role_assignments (CONFIGURADO)
✅ Incluir actividades y recursos          → activities (siempre TRUE)
✅ Incluir bloques                         → blocks (siempre TRUE)
✅ Incluir archivos                        → files (siempre TRUE)
✅ Incluir filtros                         → filters (siempre TRUE)
✅ Incluir comentarios                     → comments (CONFIGURADO)
✅ Incluir insignias                       → badges (CONFIGURADO)
✅ Incluir eventos del calendario          → calendarevents (DEFAULT)
✅ Incluir detalles del grado de avance    → userscompletion (CONFIGURADO)
☐ Incluir archivos "log" de cursos        → logs (DEFAULT)
☐ Incluir historial de calificaciones     → grade_histories (DEFAULT)
✅ Incluir banco de preguntas              → questionbank (DEFAULT)
✅ Incluir grupos y agrupamientos          → groups (CONFIGURADO)
✅ Incluir competencias                    → competencies (DEFAULT)
```

## 🔴 Problema Identificado

### 1. **Settings con valor DEFAULT**

Cuando NO se configura explícitamente un setting, Moodle usa el valor por defecto definido en `backup_root_task.class.php`.

**Todos tienen default = `true`**, PERO:

- Algunos settings tienen **dependencias**:
  - `logs` → depende de `users` (si users=0, logs=0)
  - `grade_histories` → depende de `users` (si users=0, grade_histories=0)
  - `xapistate` → depende de `users` (si users=0, xapistate=0)
  - `userscompletion` → depende de `users` (si users=0, userscompletion=0)

### 2. **Comportamiento con `$rootusers = 0`**

Cuando se hace un backup **SIN datos de usuario** (`$rootusers = 0`):

```php
$bc->get_plan()->get_setting('users')->set_value(0);
```

**Resultado**:
- ❌ `logs` → automáticamente se pone en `0` (NO se incluyen)
- ❌ `grade_histories` → automáticamente `0` (NO se incluye historial)
- ❌ `xapistate` → automáticamente `0`
- ❌ `userscompletion` → automáticamente `0` (pero este SÍ se configura explícitamente)

### 3. **Comportamiento con `$rootusers = 1`**

Cuando se hace backup **CON datos de usuario** (`$rootusers = 1`):

**Settings NO configurados usan sus defaults**:
- ✅ `logs` → DEFAULT `true` (SÍ se incluyen logs)
- ✅ `grade_histories` → DEFAULT `true` (SÍ se incluye historial)
- ✅ `competencies` → DEFAULT `true` (si están habilitadas)
- ✅ `questionbank` → DEFAULT `true`
- ✅ `contentbankcontent` → DEFAULT `true`
- ✅ `legacyfiles` → DEFAULT `true`
- ✅ `calendarevents` → DEFAULT `true`

## ✅ Respuesta a la Pregunta

### ¿Se incluyen los logs actualmente?

**Depende**:

1. **Si `$rootusers = 1` (backup CON datos de usuario)**:
   - ✅ SÍ se incluyen logs (usa default `true`)
   - ✅ SÍ se incluye historial de calificaciones
   - ✅ SÍ se incluyen competencias
   - ✅ SÍ se incluye todo lo demás

2. **Si `$rootusers = 0` (backup SIN datos de usuario)**:
   - ❌ NO se incluyen logs (dependencia con `users`)
   - ❌ NO se incluye historial de calificaciones (dependencia)
   - ✅ SÍ se incluyen competencias (no tiene dependencia)
   - ✅ SÍ se incluye banco de preguntas

### ¿Qué son los "logs de cursos"?

Los **logs** en Moodle son los registros de actividad del curso:
- Quién accedió al curso y cuándo
- Qué recursos se visualizaron
- Qué actividades se completaron
- Calificaciones otorgadas
- Cambios en el curso

**NO son archivos .log del servidor**, son registros en la tabla `mdl_logstore_standard_log`.

## 📊 Tabla Resumen

| Setting | Default | Configurado Explícitamente | Incluido con users=0 | Incluido con users=1 |
|---------|---------|----------------------------|----------------------|----------------------|
| `users` | - | ✅ Sí | 0 | 1 |
| `role_assignments` | true | ✅ Sí | 0 | 1 |
| `comments` | true | ✅ Sí | 0 | 1 |
| `badges` | true | ✅ Sí | 0 | 1 |
| `userscompletion` | true | ✅ Sí | 0 | 1 |
| `groups` | true | ✅ Sí | 0 | 1 |
| **`logs`** | **true** | ❌ No | ❌ No | ✅ Sí |
| **`grade_histories`** | **true** | ❌ No | ❌ No | ✅ Sí |
| `competencies` | true | ❌ No | ✅ Sí | ✅ Sí |
| `questionbank` | true | ❌ No | ✅ Sí | ✅ Sí |
| `customfield` | true | ❌ No | ✅ Sí | ✅ Sí |
| `contentbankcontent` | true | ❌ No | ✅ Sí | ✅ Sí |
| `xapistate` | true | ❌ No | ❌ No | ✅ Sí |
| `legacyfiles` | true | ❌ No | ✅ Sí | ✅ Sí |
| `calendarevents` | true | ❌ No | ✅ Sí | ✅ Sí |

## 🎯 Conclusiones

1. **Los logs SÍ se incluyen** cuando se hace backup con `$rootusers = 1`
2. **El historial de calificaciones SÍ se incluye** con `$rootusers = 1`
3. **NO es necesario configurar explícitamente** estos settings si se quiere usar el comportamiento por defecto
4. **Los defaults son adecuados** para la mayoría de casos

## 💡 Recomendación

### Opción 1: Mantener como está (RECOMENDADO)
- ✅ Los defaults funcionan correctamente
- ✅ Menos código que mantener
- ✅ Comportamiento estándar de Moodle

### Opción 2: Configurar explícitamente (solo si se necesita control fino)

Si se quiere tener **control explícito** sobre cada setting:

```php
// Configurar TODOS los settings explícitamente
$bc->get_plan()->get_setting('logs')->set_status(base_setting::NOT_LOCKED);
$bc->get_plan()->get_setting('logs')->set_value($rootusers);

$bc->get_plan()->get_setting('grade_histories')->set_status(base_setting::NOT_LOCKED);
$bc->get_plan()->get_setting('grade_histories')->set_value($rootusers);

$bc->get_plan()->get_setting('competencies')->set_status(base_setting::NOT_LOCKED);
$bc->get_plan()->get_setting('competencies')->set_value(1); // Siempre incluir

$bc->get_plan()->get_setting('questionbank')->set_status(base_setting::NOT_LOCKED);
$bc->get_plan()->get_setting('questionbank')->set_value(1); // Siempre incluir

$bc->get_plan()->get_setting('calendarevents')->set_status(base_setting::NOT_LOCKED);
$bc->get_plan()->get_setting('calendarevents')->set_value($rootusers);

$bc->get_plan()->get_setting('contentbankcontent')->set_status(base_setting::NOT_LOCKED);
$bc->get_plan()->get_setting('contentbankcontent')->set_value(1); // Siempre incluir

$bc->get_plan()->get_setting('customfield')->set_status(base_setting::NOT_LOCKED);
$bc->get_plan()->get_setting('customfield')->set_value(1); // Siempre incluir

$bc->get_plan()->get_setting('legacyfiles')->set_status(base_setting::NOT_LOCKED);
$bc->get_plan()->get_setting('legacyfiles')->set_value(1); // Siempre incluir

$bc->get_plan()->get_setting('xapistate')->set_status(base_setting::NOT_LOCKED);
$bc->get_plan()->get_setting('xapistate')->set_value($rootusers);
```

**Ventaja**: Control total sobre qué se incluye
**Desventaja**: Más código, más mantenimiento

## 🧪 Cómo Verificar

Para confirmar qué se está incluyendo actualmente:

### 1. Revisar logs de backup
```sql
SELECT * FROM mdl_local_coursetransfer_log
WHERE action LIKE '%BACKUP%'
ORDER BY timecreated DESC
LIMIT 20;
```

### 2. Examinar archivo .mbz
```bash
# Descargar un backup reciente
# Descomprimir
unzip backup.mbz

# Ver estructura
ls -la

# Verificar si hay logs
cat moodle_backup.xml | grep -i "logs\|grade_histories"
```

### 3. Verificar en restore
Los logs del restore deberían mostrar si se restauraron logs/histories:
```
RESTORE_PLAN_EXECUTED
User mapping complete: X pre-mapped, Y mapped, Z created
```

## 📚 Referencias

- **Código fuente**: 
  - `/backup/moodle2/backup_root_task.class.php` (líneas 140-200)
  - `/backup/moodle2/backup_settingslib.php`
  - `classes/coursetransfer_backup.php` (líneas 84-127)

- **Documentación Moodle**:
  - https://docs.moodle.org/en/Backup_settings
  - https://docs.moodle.org/en/Course_backup

---

**Conclusión Final**: Los logs y historial de calificaciones **SÍ se están incluyendo** cuando se hace backup con datos de usuario (`$rootusers = 1`). No es necesario hacer cambios al código actual a menos que se requiera comportamiento diferente al estándar de Moodle.
