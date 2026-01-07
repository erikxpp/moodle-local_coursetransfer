# 📚 Análisis Completo del Plugin local_coursetransfer

## 🎯 Índice de Documentación

Este análisis completo está dividido en múltiples archivos para facilitar la navegación:

### Documentos Generados

1. **[ANALISIS_COMPLETO_PLUGIN_COURSETRANSFER.md](./ANALISIS_COMPLETO_PLUGIN_COURSETRANSFER.md)**
   - Resumen ejecutivo
   - Arquitectura general del plugin
   - Estructura de base de datos (4 tablas principales)
   - Flujo general del proceso (0% - 100%)
   - Transiciones de estado

2. **[ANALISIS_COMPLETO_PLUGIN_COURSETRANSFER_PARTE2.md](./ANALISIS_COMPLETO_PLUGIN_COURSETRANSFER_PARTE2.md)**
   - Tareas Ad-hoc detalladas
     - create_backup_course_task
     - download_file_course_task  
     - restore_course_task (⚠️ CRÍTICA)
   - Control de concurrencia (doble capa)
   - Mapeo de usuarios (user_mapper)
   - Funciones del Core de Moodle utilizadas

3. **[ANALISIS_COMPLETO_PLUGIN_COURSETRANSFER_PARTE3.md](./ANALISIS_COMPLETO_PLUGIN_COURSETRANSFER_PARTE3.md)**
   - Problemas identificados
     - Error 10400: Quiz attempts corruptos
     - Error 11100: File not found
     - Usuarios duplicados
     - Problemas de concurrencia
   - Tabla de códigos de error
   - Mejores prácticas para migración masiva
   - Resumen de funciones del Core
   - Conclusiones y recomendaciones

---

## 🔍 Resumen Ejecutivo

### ¿Qué hace el plugin?

El plugin **local_coursetransfer** permite transferir cursos completos entre dos instalaciones de Moodle diferentes:

- **Moodle A (Origen)**: Donde están los cursos originales
- **Moodle B (Destino)**: Donde se copian los cursos

### Flujo Simplificado

```
Usuario en Moodle B solicita curso
    ↓
Moodle B llama a Moodle A via REST
    ↓
Moodle A crea backup .mbz (tarea ad-hoc)
    ↓
Moodle B descarga .mbz (tarea ad-hoc)
    ↓
Moodle B restaura curso (tarea ad-hoc)
    ↓
✓ Curso disponible en Moodle B
```

### Estados del Proceso

| % | Estado | Descripción |
|---|--------|-------------|
| 0-1% | NOT_STARTED | Solicitud creada |
| 5-60% | BACKUP | Creando archivo .mbz en origen |
| 65-75% | DOWNLOAD | Descargando .mbz a destino |
| 75-85% | DOWNLOADED | Archivo listo para restore |
| 85-100% | RESTORE | Restaurando curso |
| 100% | COMPLETED | ✓ Completado |
| - | ERROR | ❌ Falló |

---

## 🔑 Conceptos Clave

### 1. Tablas Principales

- **local_coursetransfer_request**: Registro de cada solicitud (origen y destino)
- **local_coursetransfer_origin**: Sitios origen configurados
- **local_coursetransfer_target**: Sitios destino configurados  
- **local_coursetransfer_log**: Log detallado de cada paso

### 2. Tareas Ad-hoc

- **create_backup_course_task**: Crea .mbz en origen
- **download_file_course_task**: Descarga .mbz a destino
- **restore_course_task**: Restaura curso (CON LOCK de concurrencia)

### 3. Tareas Programadas (Cron)

- **clean_adhoc_failed_task**: Limpia tareas fallidas (5:00 AM diario)
- **cleanup_old_backup_files_task**: Elimina backups antiguos (2:30 AM diario)
- **check_stuck_transfers_task**: Detecta transferencias atascadas (cada 30 min)

### 4. Componente Crítico: user_mapper

**Problema que resuelve**: Evita crear usuarios duplicados.

**Cómo funciona**:
1. Lee users.xml del backup
2. Busca cada usuario por username en destino
3. Si existe, crea mapping en backup_ids_temp
4. Durante restore, usa IDs mapeados en vez de crear nuevos usuarios

**Sin mapeo**:
```
Origen: juan.perez (ID 100)
Destino existente: juan.perez (ID 500)
Resultado: juan.perez2 (ID 850) ← DUPLICADO ❌
```

**Con mapeo**:
```
Origen: juan.perez (ID 100)
Destino existente: juan.perez (ID 500)
Mapping: 100 → 500
Resultado: Usa juan.perez (ID 500) ← CORRECTO ✓
```

---

## ⚠️ Problemas Identificados

### 1. Error 10400: restore_step_exception

**Causa**: Quiz attempts con referencias a respuestas que no existen.

**Solución**:
- Limpiar datos corruptos en origen ANTES de migrar
- Implementar safe_quiz_restore con fallback
- Validar backups antes de restaurar

### 2. Error 11100: File not found

**Causa**: Archivo .mbz eliminado entre descarga y restore.

**Solución**:
- Guardar fileurl en custom_data
- Implementar recuperación automática
- Proteger archivos durante reintentos

### 3. Usuarios Duplicados

**Causa**: Mapeo de usuarios no ejecutado o fallido.

**Solución**:
- user_mapper implementado
- Búsqueda por username
- Inserción en backup_ids_temp

### 4. Concurrencia

**Causa**: Múltiples restores corrompen backup_ids_temp.

**Solución**:
- Pre-check en BD
- Lock de exclusión mutua
- Ejecución 100% secuencial

---

## 📊 Funciones del Core de Moodle Más Usadas

### Backup
- `backup_controller::__construct()` - Crea controlador
- `$bc->execute_plan()` - **Ejecuta backup**
- `$bc->get_results()` - Obtiene .mbz

### Restore  
- `restore_controller::__construct()` - Crea controlador
- `$rc->execute_precheck()` - Valida restore
- `$rc->execute_plan()` - **Ejecuta restauración**

### Archivos
- `get_file_storage()` - Repositorio de archivos
- `$fs->create_file_from_pathname()` - Crea stored_file
- `$file->extract_to_pathname()` - Extrae .mbz

### Locks
- `\core\lock\lock_config::get_lock_factory()` - Factory
- `$factory->get_lock($resource, $timeout)` - Adquiere lock
- `$lock->release()` - Libera lock

### Tareas
- `\core\task\manager::queue_adhoc_task()` - Encola tarea
- `\core\task\manager::reschedule_or_queue_adhoc_task()` - Reprograma

---

## 🎓 Casos de Uso

### Migración Individual
```php
// Usuario solicita 1 curso
$api->origin_restore_course($user, $siteurl, $courseid, $config);
```

### Migración Masiva
```php
// Script para múltiples cursos
$courseids = [32811, 32866, 32867, 32989];
foreach ($courseids as $courseid) {
    $api->origin_restore_course($user, $siteurl, $courseid, $config);
    sleep(60); // 1 minuto entre solicitudes
}
```

### Con Datos vs Sin Datos

**Con datos** (`enrolusers=1`):
- Usuarios inscritos
- Calificaciones
- Entregas de tareas
- Quiz attempts
- Posts en foros
- Todo el contenido de estudiantes

**Sin datos** (`enrolusers=0`):
- Solo estructura del curso
- Actividades vacías
- No hay inscripciones
- No hay calificaciones

---

## 📈 Monitoreo

### Ver estado de solicitudes
```sql
SELECT 
    id,
    origin_course_fullname,
    status,
    error_code,
    FROM_UNIXTIME(timecreated) as created,
    FROM_UNIXTIME(timemodified) as modified
FROM local_coursetransfer_request
ORDER BY timecreated DESC
LIMIT 20;
```

### Ver logs detallados
```sql
SELECT 
    request_id,
    action,
    status,
    message,
    FROM_UNIXTIME(timecreated) as log_time
FROM local_coursetransfer_log
WHERE request_id = 22
ORDER BY timecreated DESC;
```

---

## 🚀 Próximos Pasos Recomendados

1. ✅ **Pre-validación**: Script para auditar cursos antes de migrar
2. ✅ **Safe mode**: Restaurar sin quiz attempts si hay errores  
3. ✅ **Dashboard**: Interfaz de monitoreo en tiempo real
4. ✅ **Batch processor**: Herramienta para migración masiva automatizada
5. ✅ **Alertas**: Notificaciones cuando hay errores

---

## 📞 Soporte

Para dudas o problemas:
1. Revisa los logs: `local_coursetransfer_log`
2. Verifica errores: [PARTE 3 - Sección 6](./ANALISIS_COMPLETO_PLUGIN_COURSETRANSFER_PARTE3.md#6-problemas-identificados-y-sus-causas)
3. Consulta funciones del Core: [PARTE 2 - Sección 5](./ANALISIS_COMPLETO_PLUGIN_COURSETRANSFER_PARTE2.md#5-tareas-programadas-cron-y-ad-hoc)

---

**Documentación generada el**: 7 de enero de 2026  
**Versión del plugin**: Compatible con Moodle 4.1+ y 4.5+  
**Autor del análisis**: GitHub Copilot (Claude Sonnet 4.5)
