# Ubicación del Botón Retry en la Interfaz de Logs

## 📍 Dos Ubicaciones Disponibles

El botón **"Reintentar"** ahora aparece en **DOS lugares diferentes**:

---

## 1️⃣ Tabla Principal de Logs (`logs.php`)

### Ubicación:
```
Administración del sitio > Plugins > Transferencia de Cursos > Logs de Transferencias
```

### URL:
```
https://tu-moodle.com/local/coursetransfer/logs.php
```

### Vista:
La tabla muestra todas las solicitudes con las siguientes columnas:

| ID | Sitio Origen | Estado | ... | Ver logs | **Reintentar** |
|----|--------------|--------|-----|----------|----------------|
| 1175 | https://... | ERROR | ... | 🔍 Ver logs | 🔄 Reintentar |

### Características:
- ✅ El botón aparece **directamente en la tabla**
- ✅ No necesitas entrar a los detalles de cada solicitud
- ✅ Reintentas con un solo clic desde la lista
- ⚠️ Solo visible si: `status = ERROR` y `type = COURSE`

---

## 2️⃣ Página de Detalles (`logs_detail.php`)

### Ubicación:
```
Logs de Transferencias > Hacer clic en "Ver logs" de una solicitud
```

### URL:
```
https://tu-moodle.com/local/coursetransfer/logs_detail.php?requestid=1175
```

### Vista:
Página completa con:
- Información detallada de la solicitud
- Logs completos del proceso
- Botón "Reintentar" en la parte superior

### Características:
- ✅ Vista detallada con toda la información
- ✅ Logs completos del error
- ✅ Botón grande y visible
- ⚠️ Solo visible si: `status = ERROR` y `type = COURSE`

---

## 🎯 ¿Cuál usar?

### Usa la **Tabla Principal** (`logs.php`) si:
- Necesitas reintentar **múltiples cursos** rápidamente
- Ya sabes qué solicitudes fallaron
- Quieres una vista rápida de todos los errores

### Usa la **Página de Detalles** (`logs_detail.php`) si:
- Necesitas **revisar el error** antes de reintentar
- Quieres ver los logs completos
- Es tu primer reintento de esa solicitud

---

## 🚀 Activación

Para que aparezcan ambos botones, ejecuta:

```bash
cd /var/www/html/moodle/local/coursetransfer
bash activate_retry_column.sh
```

O manualmente:

```bash
cd /var/www/html/moodle
php admin/cli/purge_caches.php
php admin/cli/upgrade.php --non-interactive
```

---

## 🔍 Condiciones para que el Botón Aparezca

El botón **solo se muestra** cuando se cumplen **ambas condiciones**:

1. ✅ **Estado de la solicitud**: `ERROR` (status = 0)
2. ✅ **Tipo de transferencia**: `COURSE` (type = 0)

### ❌ El botón NO aparece si:
- Estado es `COMPLETED`, `IN_PROGRESS`, `DOWNLOADED`, etc.
- Tipo es `CATEGORY` (transferencia por categoría)
- Tipo es `REMOVE_COURSE` (eliminación de curso)

---

## 📊 Ejemplo Visual

### Tabla Principal (logs.php):

```
┌─────────────────────────────────────────────────────────────────────┐
│ Logs de Transferencias - Solicitudes de Cursos                      │
├────┬──────────────┬─────────┬─────────────────┬────────────────────┤
│ ID │ Sitio Origen │ Estado  │ Ver logs        │ Reintentar         │
├────┼──────────────┼─────────┼─────────────────┼────────────────────┤
│ 80 │ aula.es      │ ERROR   │ 🔍 Ver logs     │ 🔄 Reintentar     │
│1175│ aula.es      │ ERROR   │ 🔍 Ver logs     │ 🔄 Reintentar     │
│1180│ aula.es      │COMPLETED│ 🔍 Ver logs     │ (no aparece)       │
└────┴──────────────┴─────────┴─────────────────┴────────────────────┘
```

### Página de Detalles (logs_detail.php):

```
┌─────────────────────────────────────────────────────────────────────┐
│ Detalles de Solicitud #1175                                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│ [🔄 Reintentar Transferencia]  <-- Botón grande y visible           │
│                                                                       │
│ Estado: ERROR                                                        │
│ Curso Origen: 12345                                                  │
│ Error: [11100] File not found! :28433332                            │
│                                                                       │
│ === Logs Detallados ===                                              │
│ 2024-01-15 10:30:00 - Starting backup...                            │
│ 2024-01-15 10:35:00 - Backup completed                              │
│ 2024-01-15 10:40:00 - ERROR: File not found                         │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 🛠️ Archivos Modificados

### 1. Tabla Principal:
- `/classes/tables/logs_course_request_table.php`
  - Línea ~75: Añadida columna `'retry'`
  - Línea ~95: Añadido header `'retry_request'`
  - Línea ~325+: Nuevo método `col_retry()`

### 2. Página de Detalles:
- `/logs_detail.php`
  - Línea ~370-392: Botón de retry

### 3. Backend:
- `/classes/external/frontend/retry_request_external.php`
- `/db/services.php`

### 4. Frontend:
- `/amd/src/retry_request.js`

### 5. Idioma:
- `/lang/es/local_coursetransfer.php`
  - Líneas 415-461: 11 nuevas cadenas

---

## 📝 Resumen

| Ubicación | Ventaja | Cuándo Usar |
|-----------|---------|-------------|
| **logs.php** | Rápido, múltiples reintentos | Reintentos masivos |
| **logs_detail.php** | Contexto completo, logs | Primera revisión |

✅ **Ambos botones funcionan igual**: limpian recursos, recrean la solicitud, inician transferencia.

¡Ahora puedes reintentar transferencias fallidas con un solo clic desde cualquiera de las dos ubicaciones! 🎉
