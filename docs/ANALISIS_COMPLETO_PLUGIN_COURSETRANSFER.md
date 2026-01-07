# Análisis Completo del Plugin local_coursetransfer

> **Documento de Análisis Técnico Detallado**  
> **Fecha**: 7 de enero de 2026  
> **Propósito**: Documentar paso a paso el funcionamiento completo del plugin coursetransfer desde 0% hasta 100%, incluyendo flujos, tablas, funciones del core de Moodle y problemas identificados.

---

## 📑 Tabla de Contenidos

1. [Resumen Ejecutivo](#1-resumen-ejecutivo)
2. [Arquitectura General del Plugin](#2-arquitectura-general-del-plugin)
3. [Estructura de Base de Datos](#3-estructura-de-base-de-datos)
4. [Flujo Completo: Paso a Paso (0% - 100%)](#4-flujo-completo-paso-a-paso)
5. [Tareas Programadas (Cron y Ad-hoc)](#5-tareas-programadas-cron-y-ad-hoc)
6. [Proceso de Backup Detallado](#6-proceso-de-backup-detallado)
7. [Proceso de Descarga](#7-proceso-de-descarga)
8. [Proceso de Restauración Detallado](#8-proceso-de-restauración-detallado)
9. [Manejo y Creación de Usuarios](#9-manejo-y-creación-de-usuarios)
10. [Problemas Identificados](#10-problemas-identificados)
11. [Funciones del Core de Moodle Utilizadas](#11-funciones-del-core-de-moodle-utilizadas)
12. [Diagramas de Flujo](#12-diagramas-de-flujo)

---

## 1. Resumen Ejecutivo

### 1.1. ¿Qué hace el plugin?

El plugin **local_coursetransfer** permite la transferencia de cursos completos entre dos instalaciones de Moodle diferentes:

- **Moodle A (Origen)**: Donde están los cursos originales
- **Moodle B (Destino)**: Donde se quieren copiar los cursos

### 1.2. Características principales

✅ **Transferencia de cursos individuales o categorías completas**  
✅ **Con datos o sin datos** (incluye entregas, calificaciones, quiz attempts, etc.)  
✅ **Mapeo automático de usuarios** entre plataformas  
✅ **Ejecución asíncrona** mediante tareas ad-hoc  
✅ **Sistema de retry** hasta 3 intentos  
✅ **Logging detallado** de cada paso  
✅ **Control de concurrencia** para evitar corrupciones  

### 1.3. Versiones compatibles

- Moodle 4.1.1+
- Moodle 4.5.6+
- Moodle 3.11.17+

### 1.4. Arquitectura

```
┌─────────────────┐                    ┌─────────────────┐
│   Moodle A      │                    │   Moodle B      │
│   (ORIGEN)      │                    │   (DESTINO)     │
│                 │                    │                 │
│  Plugin         │ ◄──── REST ─────► │  Plugin         │
│  coursetransfer │      WebService   │  coursetransfer │
│                 │                    │                 │
│  1. Crea backup │                    │  3. Descarga    │
│  2. Expone URL  │                    │  4. Restaura    │
└─────────────────┘                    └─────────────────┘
```

---

## 2. Arquitectura General del Plugin

### 2.1. Estructura de directorios

```
/local/coursetransfer/
├── classes/
│   ├── api/                    # API y comunicación REST
│   ├── task/                   # Tareas ad-hoc y programadas
│   │   ├── create_backup_course_task.php
│   │   ├── download_file_course_task.php
│   │   ├── restore_course_task.php
│   │   ├── remove_course_task.php
│   │   ├── remove_category_task.php
│   │   ├── clean_adhoc_failed_task.php
│   │   ├── cleanup_old_backup_files_task.php
│   │   └── check_stuck_transfers_task.php
│   ├── models/                 # Modelos de datos
│   ├── external/               # Web services externos
│   ├── forms/                  # Formularios
│   ├── tables/                 # Tablas de visualización
│   ├── coursetransfer.php      # Clase principal
│   ├── coursetransfer_request.php
│   ├── coursetransfer_backup.php
│   ├── coursetransfer_download.php
│   ├── coursetransfer_restore.php
│   ├── coursetransfer_logger.php
│   ├── coursetransfer_sites.php
│   ├── coursetransfer_notification.php
│   ├── user_mapper.php
│   └── coursetransfer_user_merger.php
├── db/
│   ├── install.xml             # Esquema de base de datos
│   ├── tasks.php               # Definición de tareas cron
│   ├── services.php            # Definición de web services
│   ├── access.php              # Capacidades
│   └── upgrade.php
├── docs/                       # Documentación
├── lang/                       # Archivos de idioma
├── templates/                  # Plantillas Mustache
└── version.php
```

### 2.2. Componentes clave

#### 2.2.1. Clases principales

| Clase | Responsabilidad |
|-------|----------------|
| `coursetransfer` | Coordinador principal, orquesta el flujo |
| `coursetransfer_request` | Manejo de solicitudes (CRUD) |
| `coursetransfer_backup` | Creación de backups |
| `coursetransfer_download` | Descarga de archivos .mbz |
| `coursetransfer_restore` | Restauración de cursos |
| `coursetransfer_logger` | Sistema de logging detallado |
| `coursetransfer_sites` | Gestión de sitios origen/destino |
| `user_mapper` | Mapeo de usuarios entre plataformas |
| `coursetransfer_user_merger` | Fusión de usuarios duplicados |

#### 2.2.2. Tareas Ad-hoc

| Tarea | Cuándo se ejecuta | Propósito |
|-------|-------------------|-----------|
| `create_backup_course_task` | Al solicitar curso desde destino | Crea el backup .mbz en origen |
| `download_file_course_task` | Después del backup | Descarga el .mbz a destino |
| `restore_course_task` | Después de descargar | Restaura el curso |
| `remove_course_task` | Si se solicita eliminación | Elimina curso del origen |
| `remove_category_task` | Si se solicita eliminación | Elimina categoría del origen |

#### 2.2.3. Tareas Programadas (Cron)

| Tarea | Frecuencia | Propósito |
|-------|-----------|-----------|
| `clean_adhoc_failed_task` | Cada día a las 5:00 AM | Limpia tareas fallidas |
| `cleanup_old_backup_files_task` | Cada día a las 2:30 AM | Elimina backups antiguos |
| `check_stuck_transfers_task` | Cada 30 minutos | Detecta transferencias atascadas |

---

## 3. Estructura de Base de Datos

El plugin utiliza 4 tablas principales:

### 3.1. Tabla: `local_coursetransfer_request`

**Propósito**: Almacena todas las solicitudes de transferencia.

#### Campos principales:

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT | ID único de la solicitud |
| `type` | INT | 0=curso, 1=categoría, 2=remover curso, 3=remover categoría |
| `direction` | INT | 0=request (desde destino), 1=response (desde origen) |
| `siteurl` | TEXT | URL del sitio remoto |
| `userid` | INT | Usuario que creó la solicitud |
| `status` | INT | Estado actual (ver estados abajo) |
| **Campos de origen** |
| `origin_course_id` | INT | ID del curso en el origen |
| `origin_course_fullname` | TEXT | Nombre completo del curso |
| `origin_course_shortname` | TEXT | Nombre corto del curso |
| `origin_course_idnumber` | TEXT | Número identificador del curso |
| `origin_category_id` | INT | ID de categoría en origen |
| `origin_enrolusers` | INT | 1=incluir usuarios inscritos, 0=sin usuarios |
| `origin_backup_size` | INT | Tamaño final del backup en bytes |
| `origin_backup_url` | TEXT | URL para descargar el backup |
| **Campos de destino** |
| `target_course_id` | INT | ID del curso creado en destino |
| `target_category_id` | INT | ID de categoría donde restaurar |
| `target_request_id` | INT | ID de la solicitud relacionada |
| `target_target` | INT | 2=nuevo curso, 3=sobreescribir, 4=añadir |
| `target_remove_enrols` | INT | 1=eliminar inscripciones, 0=mantener |
| `target_remove_groups` | INT | 1=eliminar grupos, 0=mantener |
| **Campos de error** |
| `error_code` | INT | Código de error si falla |
| `error_message` | TEXT | Mensaje de error detallado |
| `fileurl` | TEXT | URL del archivo backup |
| `timecreated` | INT | Timestamp de creación |
| `timemodified` | INT | Timestamp de última modificación |

#### Estados (`status`):

```php
const STATUS_ERROR = 0;           // Error fatal
const STATUS_NOT_STARTED = 1;     // Creada pero no iniciada
const STATUS_IN_PROGRESS = 10;    // En proceso
const STATUS_BACKUP = 30;         // Creando backup
const STATUS_DOWNLOAD = 50;       // Descargando
const STATUS_DOWNLOADED = 70;     // Descarga completa
const STATUS_RESTORE = 80;        // Restaurando
const STATUS_INCOMPLETED = 90;    // Incompleta (para categorías)
const STATUS_COMPLETED = 100;     // Completada exitosamente
```

### 3.2. Tabla: `local_coursetransfer_origin`

**Propósito**: Lista de sitios origen configurados.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT | ID único |
| `host` | TEXT | URL del sitio origen |
| `token` | TEXT | Token de autenticación |
| `userid` | INT | Usuario que configuró |
| `timecreated` | INT | Timestamp de creación |

### 3.3. Tabla: `local_coursetransfer_target`

**Propósito**: Lista de sitios destino configurados.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT | ID único |
| `host` | TEXT | URL del sitio destino |
| `token` | TEXT | Token de autenticación |
| `userid` | INT | Usuario que configuró |
| `timecreated` | INT | Timestamp de creación |

### 3.4. Tabla: `local_coursetransfer_log`

**Propósito**: Registro detallado de cada paso de la transferencia.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT | ID único del log |
| `request_id` | INT | FK a local_coursetransfer_request |
| `direction` | INT | 0=origin, 1=target |
| `action` | VARCHAR(50) | Acción: backup_started, backup_completed, etc. |
| `status` | VARCHAR(20) | success, error, info, warning |
| `message` | TEXT | Mensaje detallado |
| `error_code` | VARCHAR(20) | Código de error si aplica |
| `task_id` | INT | ID de la tarea ad-hoc |
| `task_classname` | VARCHAR(255) | Nombre de la clase de tarea |
| `extra_data` | TEXT | JSON con datos adicionales |
| `timecreated` | INT | Timestamp |

#### Acciones (`action`) comunes:

```
backup_started, backup_completed, backup_failed,
download_started, download_progress, download_completed, download_failed,
restore_started, restore_prechecking, restore_user_mapping, 
restore_completed, restore_failed,
file_created, file_not_found,
user_mapped, user_created, user_merged
```

---

## 4. Flujo Completo: Paso a Paso (0% - 100%)

### 4.1. Vista General del Flujo

```
┌──────────────────────────────────────────────────────────────────┐
│                  MOODLE B (DESTINO)                              │
│  [0%] Usuario solicita curso desde Moodle A                      │
└───────────────────────────┬──────────────────────────────────────┘
                            │
                            ▼ REST API call
┌──────────────────────────────────────────────────────────────────┐
│                  MOODLE A (ORIGEN)                               │
│  [1%] Recibe solicitud y valida                                  │
│  [5%] Crea registro en local_coursetransfer_request             │
│  [10%] Encola tarea ad-hoc: create_backup_course_task           │
└───────────────────────────┬──────────────────────────────────────┘
                            │
                            ▼ Cron ejecuta tarea ad-hoc
┌──────────────────────────────────────────────────────────────────┐
│              MOODLE A - Tarea Backup                             │
│  [15%] create_backup_course_task::execute()                      │
│  [20%] Crea backup_controller                                    │
│  [25%] Configura settings (usuarios, actividades, etc.)          │
│  [30%] Ejecuta backup_controller->execute_plan()                │
│  [40%] Genera archivo .mbz                                       │
│  [50%] Almacena en file system de Moodle                         │
│  [55%] Genera URL de descarga con token                          │
│  [60%] Actualiza request: status=BACKUP, origin_backup_url=...  │
│  [65%] Notifica a Moodle B via REST                             │
└───────────────────────────┬──────────────────────────────────────┘
                            │
                            ▼ REST notifica con URL
┌──────────────────────────────────────────────────────────────────┐
│                  MOODLE B - Recibe URL                           │
│  [70%] Actualiza request: fileurl, status=DOWNLOAD              │
│  [72%] Encola tarea: download_file_course_task                  │
└───────────────────────────┬──────────────────────────────────────┘
                            │
                            ▼ Cron ejecuta descarga
┌──────────────────────────────────────────────────────────────────┐
│              MOODLE B - Tarea Descarga                           │
│  [75%] download_file_course_task::execute()                      │
│  [76%] Verifica tamaño del archivo remoto                        │
│  [77%] Decide estrategia: streaming vs directo                   │
│  [78%] Descarga archivo .mbz desde origen                        │
│  [80%] Guarda en file system (component: backup, area: course)  │
│  [82%] Actualiza request: status=DOWNLOADED, fileid=...         │
│  [85%] Encola tarea: restore_course_task                        │
└───────────────────────────┬──────────────────────────────────────┘
                            │
                            ▼ Cron ejecuta restore
┌──────────────────────────────────────────────────────────────────┐
│              MOODLE B - Tarea Restauración                       │
│  [87%] restore_course_task::execute()                            │
│  [88%] PRE-CHECK: Verifica si hay otras tareas ejecutándose     │
│  [89%] Adquiere LOCK de concurrencia                            │
│  [90%] Recupera archivo .mbz del file system                     │
│  [91%] Crea restore_controller                                   │
│  [92%] Extrae .mbz a directorio temporal                         │
│  [93%] MAPEO DE USUARIOS (user_mapper::map_users)               │
│         - Lee users.xml del backup                               │
│         - Busca usuarios por username en DB destino              │
│         - Inserta mappings en backup_ids_temp                    │
│  [94%] Ejecuta restore_controller->execute_precheck()           │
│  [95%] Configura fullname, shortname, idnumber                  │
│  [96%] Ejecuta restore_controller->execute_plan()               │
│  [98%] Actualiza request: status=COMPLETED, target_course_id    │
│  [99%] Notifica usuario (email/mensaje Moodle)                  │
│  [100%] Libera LOCK, limpia archivos temporales                 │
└──────────────────────────────────────────────────────────────────┘
```

### 4.2. Resumen de Transiciones de Estado

| % | Estado (status) | Valor | ¿Dónde? | Descripción |
|---|----------------|-------|---------|-------------|
| 0-1% | NOT_STARTED | 1 | Destino | Solicitud creada, esperando procesamiento |
| 5-10% | IN_PROGRESS | 10 | Origen | Tarea de backup encolada |
| 10-60% | BACKUP | 30 | Origen | Creando archivo .mbz |
| 65-70% | DOWNLOAD | 50 | Destino | Descargando .mbz |
| 70-85% | DOWNLOADED | 70 | Destino | Archivo descargado, esperando restore |
| 85-98% | RESTORE | 80 | Destino | Restaurando curso |
| 100% | COMPLETED | 100 | Destino | Proceso completado exitosamente |
| Error | ERROR | 0 | Cualquiera | Falló en algún punto |

