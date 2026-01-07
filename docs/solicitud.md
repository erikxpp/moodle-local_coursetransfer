# coursetransfer — Solicitud técnica para Gemini Pro (Antigravity)
> Objetivo: entregar a una IA (Gemini Pro) el contexto completo, el análisis del problema y un plan de trabajo **accionable** para corregir fallas frecuentes del plugin `local_coursetransfer` sin “hackear” Moodle (prioridad: usar APIs/servicios nativos).

---

## 1) Contexto y repositorios involucrados

### 1.1. Proyecto principal
- Plugin Moodle (instalado en **Moodle A (origen)** y **Moodle B (destino)**):  
  `.../Projects/Moodle-IPG/ipg-moodle-web/src/plugins/coursetransfer`

### 1.2. Referencias relacionadas (no son el foco, pero sirven como benchmark)
- Script de clonación local (mismo objetivo: copias masivas, pero dentro del mismo sitio):  
  `.../Projects/Moodle-IPG/scripts/moodle-clone-optimized.sh`
- CSV ejemplo: `.../Projects/Moodle-IPG/scripts/files/clonar.csv`
- Herramienta recomendada (benchmark): `.../src/plugins/moosh`
- Posible referencia a restore nativo: `.../ipg-moodle-files/backup/restore.php`

---

## 2) Qué hace el plugin (requerimientos que NO se deben romper)

**Escenario funcional actual (debe mantenerse y mejorar robustez):**
1. En **Moodle A (origen)** el usuario puede seleccionar:
   - **Con datos** (full): configuraciones + entregas + logs + notas + foros + “todo al 100%”.
   - **Sin datos**: respaldo del curso **sin datos de usuarios**.
2. Puede solicitar:
   - Un **curso puntual** o una **categoría completa**.
3. Con el `.mbz` listo:
   - **Moodle B** descarga el `.mbz`.
   - Encola una **tarea ad-hoc por curso**.
   - Todo paso/error queda en **log detallado**.
4. Restore:
   - Debe restaurar el curso, y **mapear usuarios existentes** (IDs distintos en B) usando manejo **nativo**.
5. Performance/operación:
   - Ad-hoc tasks para no colapsar.
   - Ejecución **secuencial (uno a uno)**.
   - **Retry**: hasta 3 intentos; si falla, marcar error y seguir con el lote.
6. Fidelidad:
   - “Toda configuración del curso origen debe quedar igual en destino” (restricciones, proctoring, etc.).
7. Naming:
   - Se corrigió que Moodle nativo a veces agrega “copia” o no setea bien `idnumber/shortname/fullname`; esa mejora **debe mantenerse**.

**Restricción clave de diseño:** “usar todo nativo… controller lo que sea nativo… evitar personalizar tanto”.

---

## 3) Incidente representativo (Caso ID 22) y evidencia

### 3.1. Datos del caso
- ID petición: **22**  
- Curso: **TALLER DE LIDERAZGO Y EMPRENDIMIENTO_CV_S72 (EDUC004ONL_CV_S72_2024_4)**  
- Curso origen: **32989**  
- Curso destino: **40**  
- Sitio: `https://virtual-legacy-ipg.ximple-tech.com`  
- Error final visible en la petición: **[11100] File not found : 106777**  

### 3.2. Línea de tiempo (simplificada)
1) **Descarga iniciada** desde `pluginfile.php` (token incluido) y se completa OK (≈133.8 MB).  
2) **Restore inicia** para `file_id: 106777`.  
3) Se ejecuta **user mapping**: “Total users in backup: 25. Mapped: 25.”  
4) **Precheck** “SUCCESS”, sin errores.  
5) Se setean `fullname/shortname` antes de ejecutar restore (esto ya es mejora y debe quedarse).  
6) Falla con:
   - **Restore exception** `error/not_specified_restore_task`
   - **Error Code 10400**
   - `exception: restore_step_exception`
   - Stack trace apunta al restore de preguntas (multichoice) y un mapping inexistente: `get_mapping('question_answer', '2083416')`  
7) Se agenda retry #1 y #2.  
8) Retry #2 termina con:
   - **Backup file not found in Moodle file system**
   - **Error Code 11100**
   - `file_id: 106777`  

---

## 4) Análisis del error 10400 (restore_step_exception / mapping question_answer)

### 4.1. Qué significa (interpretación práctica)
El restore está procesando **intentos de cuestionarios / estados de preguntas** y, durante el recodificado de respuestas de una pregunta de tipo **multichoice**, intenta mapear un `question_answer` (ID de origen `2083416`) hacia su ID en el destino, pero **no existe mapping** para ese registro en el restore. El stack trace lo muestra explícitamente en:

- `restore_structure_step->get_mapping('question_answer', '2083416')`
- `restore_qtype_multichoice_plugin->recode_response(...)`
- `restore_questions_activity_structure_step->restore_question_attempt_step_worker(...)`  

En resumen: **el restore llega a un intento/step que referencia una answer que no está en el backup/mapeo**, o el backup trae datos inconsistentes para ese intento.

### 4.2. Hipótesis más probables (ordenadas)
**H1 — Datos de intentos corruptos / inconsistentes en el curso origen (o en el backup):**  
Los intentos de quiz guardan respuestas referenciando `question_answers` que fueron eliminadas, editadas de forma incompatible, o quedaron huérfanas. Esto calza con el warning del plugin: “quiz/question attempt restore issue… references corrupted or missing question answers”.

**H2 — El backup fue generado sin incluir ciertas estructuras necesarias para recodificar intentos** (edge cases según settings).  
Aunque el restore precheck “SUCCESS”, el precheck no garantiza que los datos de intentos estén *sanitizados*.

**H3 — Diferencias de versiones / plugins (qtype multichoice) entre Moodle A y B** que generan restauración parcial o distinta del orden de restauración/mappings (menos probable si ambos sitios son equivalentes, pero hay que confirmarlo).

### 4.3. Implicación importante (decisión de producto)
Si el usuario pidió “**con datos**” y en el origen hay intentos inconsistentes, el restore **no debiera tumbar toda la restauración del curso** si el objetivo principal es clonar contenido; en esos casos se requiere una estrategia:

- **Estrategia A (fidelidad máxima):** bloquear y devolver error explicando que el origen está inconsistente (requiere corrección en origen).
- **Estrategia B (robustez operativa):** ofrecer fallback automático (o semiautomático) a **restaurar sin datos** cuando el problema está *acotado a intentos*, preservando contenido y configuración.

Este documento propone implementar ambas opciones como política configurable.

---

## 5) Análisis del error 11100 (Backup file not found) durante retries

### 5.1. Qué significa
En el retry #2, el restore intenta nuevamente usar el mismo `file_id: 106777` pero **ya no está disponible en el file pool** (“Backup file not found in Moodle file system”).

### 5.2. Hipótesis más probables
**H1 — Limpieza/eliminación del stored_file entre reintentos**  
Algún flujo del plugin o del propio Moodle está eliminando el archivo (o el área/ítem) después del primer fallo, pero el scheduler sigue reintentando con el mismo ID.

**H2 — El file_id es válido, pero el archivo está en un área temporal** que se limpia al finalizar o fallar el restore.

**H3 — Condición de carrera**: otro proceso (otro job/curso) elimina o reubica el archivo, y los reintentos quedan apuntando a un ID inválido.

### 5.3. Implicación
Los reintentos actuales **no son idempotentes**, porque dependen de un `file_id` que puede desaparecer. Para que el retry sea real, se requiere:

- Mantener el `.mbz` **inmutable y vigente** hasta que el job termine (éxito o fallo final).
- O bien, si falta el archivo, **reconstruir el input** (re-descargar o pedir regeneración al origen).

---

## 6) Qué necesito que Gemini Pro haga (trabajo solicitado)

### 6.1. Objetivo principal
Mejorar `local_coursetransfer` para que el flujo sea **resistente a datos corruptos de quiz attempts** y a problemas de **persistencia del backup file**, manteniendo:

- restore nativo
- user mapping nativo
- ejecución secuencial + adhoc
- logs exhaustivos
- retry hasta 3 intentos, pero con retry real (misma entrada disponible) o fallback automático

### 6.2. Entregables esperados
1) **Diagnóstico técnico** (documentado en el repo) con:
   - Root cause probable del 10400 (quiz attempts + question_answer mapping)
   - Root cause probable del 11100 (file lifecycle)
2) **Plan de cambios** + implementación en el plugin, idealmente con PR/commits organizados:
   - Fix de persistencia de `.mbz` a través de reintentos
   - Manejo de error 10400 con política configurable (fallback / fail-fast)
   - Logs estructurados (IDs, restoreid, fileid, attempt, estado)
3) **Checklist de validación** + pruebas manuales reproducibles.

---

## 7) Propuesta de solución (diseño recomendado)

### 7.1. Cambios “sí o sí” (robustez de retries / file lifecycle)
**Problema:** retry intenta usar `file_id` que ya no existe.

**Solución recomendada:**
- Definir un **contrato de persistencia**: un backup file descargado debe permanecer hasta:
  - restore exitoso, o
  - fallo final (max_attempts alcanzado), o
  - cancelación explícita.
- Implementar en el plugin:
  - Antes de ejecutar restore: validar `stored_file` existe + tamaño + checksum (si aplica).
  - En cada retry:
    - Si `stored_file` no existe: reintentar **re-descarga** desde origen (si se guardó la URL) o solicitar al origen regeneración/reenviar el `.mbz`.
- Nunca borrar el archivo “a mano” mientras existan reintentos pendientes.
- Agregar logging en cada etapa:
  - `file_id`, `contenthash`, `filesize`, `contextid`, `component/filearea/itemid`.

### 7.2. Manejo del 10400 (quiz attempts corruptos) — política configurable
Cuando el stack trace indique `restore_qtype_multichoice_plugin` + `question_answer` mapping inexistente, tratarlo como **fallo conocido “QUIZ_ATTEMPT_CORRUPTION”**.

**Política 1 (recomendada): fallback automático**
- Si la solicitud fue “con datos”:
  1) Marcar el intento como fallo por quiz attempts.
  2) Solicitar (en origen) un nuevo backup del curso **sin datos de usuarios**.
  3) Descargar y restaurar ese nuevo `.mbz` automáticamente.
  4) Dejar el resultado como “restaurado sin datos” con warning auditado.
- Beneficio: “salva” la clonación de contenido (lo más importante en operación masiva).
- Costo: pierde intentos/notas/logs de usuarios (solo cuando el origen está inconsistente).

**Política 2: fail-fast**
- Si la solicitud es “con datos” y falla por quiz attempts:
  - dejar en error con un mensaje claro: “el curso origen tiene intentos de quiz inconsistentes; corregir en origen o reintentar sin datos”.
- Beneficio: preserva requerimiento estricto.
- Costo: baja disponibilidad del proceso masivo.

**Política 3: retry con settings alternativos (si Moodle lo permite)**
- Evaluar si es viable ejecutar restore en modo que **no restaure intentos** pero sí otras cosas de usuario.
- Ojo: esto suele ser difícil porque “user data” en Moodle incluye muchas cosas; por eso el fallback “sin datos” es el más realista y estable.

### 7.3. Validación previa en origen (pre-flight opcional, pero muy útil)
Antes de generar backup “con datos”, ejecutar validaciones rápidas para detectar cursos “riesgosos”:

- Si se detecta inconsistencia de quiz attempts → advertir y sugerir “sin datos” (o permitir continuar bajo riesgo).
- Esto reduce jobs fallidos y tiempo desperdiciado en destino.

> Nota: esta etapa es “nice-to-have”, pero mejora muchísimo la tasa de éxito.

---

## 8) Sugerencias técnicas concretas (para guiar implementación)

### 8.1. Dónde está fallando hoy
El stack trace indica que el restore se ejecuta desde:

- `local/coursetransfer/classes/coursetransfer_restore.php` (línea ~337 según trace)  
- `local/coursetransfer/classes/task/restore_course_task.php` (línea ~156 según trace)  

### 8.2. Instrumentación mínima recomendada (logs)
Agregar log estructurado por curso/job con:
- `request_id` (ej: 22)  
- `courseid_source`, `courseid_target`  
- `adhoc_task_id` (download y restore)  
- `attempt`, `max_attempts`  
- `restoreid` (ej: `83e0...`)  
- `file_id`, `filesize`, `contenthash`
- `failure_category` (p.ej. `QUIZ_ATTEMPT_CORRUPTION`, `MISSING_BACKUP_FILE`, `NETWORK_ERROR`, etc.)

### 8.3. Reintentos idempotentes (modelo de estado)
Recomiendo modelar el job como un state machine simple:

- `DOWNLOADING` → `DOWNLOADED` → `RESTORING` → (`SUCCESS` | `FAILED_RETRYABLE` | `FAILED_FINAL`)
- `FAILED_RETRYABLE` solo si:
  - aún hay intentos disponibles, y
  - el input (`.mbz`) está accesible o puede regenerarse automáticamente.

### 8.4. Tratamiento “inteligente” de errores
Detectar por patrones:
- Si excepción contiene `restore_qtype_multichoice_plugin` + `question_answer` mapping: categorizar como `QUIZ_ATTEMPT_CORRUPTION`.
- Si error code == 11100 y mensaje “File not found”: categorizar como `MISSING_BACKUP_FILE`.

Y luego aplicar política:
- `QUIZ_ATTEMPT_CORRUPTION`:
  - si política fallback activa: disparar backup sin datos + restore.
  - si no: fail-fast.
- `MISSING_BACKUP_FILE`:
  - re-descargar (si URL disponible) o pedir regeneración al origen.
  - si no se puede: fail con mensaje claro.

---

## 9) Plan de trabajo propuesto (paso a paso)

### Fase 1 — Reproducibilidad y evidencia
1. Ejecutar el caso ID 22 (u otro) con logging extendido para:
   - confirmar si el file es eliminado y por quién (plugin o cleanup).
2. Confirmar paridad entre Moodle A/B (versiones, plugins relevantes).

### Fase 2 — Fix de persistencia del `.mbz` y retries reales
1. Asegurar que el archivo descargado en B:
   - quede en un filearea estable del plugin (no temporal),
   - no sea purgado hasta finalizar el job.
2. Reintentos:
   - si `stored_file` falta → recuperar input (re-descargar / regenerar).

### Fase 3 — Manejo del 10400 (quiz attempts) con fallback
1. Implementar clasificación de error por patrones del stack trace.
2. Política fallback:
   - si se pidió “con datos” y falla por quiz attempts:
     - lanzar backup sin datos en origen + restaurar sin datos en destino.
3. Exponer en UI/setting del plugin:
   - `strict_restore_with_userdata` (true/false)
   - si false: habilita fallback.

### Fase 4 — Observabilidad y reporte
1. En logs: categorizar, agregar métricas de éxito/fracaso.
2. Reporte final por lote:
   - cuántos OK con datos / OK sin datos (fallback) / FAIL final.

---

## 10) Criterios de aceptación

1. **No se pierde el procesamiento por lote**: si un curso falla, el resto continúa.  
2. **Retry real**: si se reintenta, el input existe o se reconstruye (no debe aparecer 11100 por cleanup interno).
3. **Fallback controlado**:
   - Si falla por quiz attempts, el sistema puede completar restore sin datos (si política lo permite).
4. **Logs suficientes para auditoría**: cada paso/estado queda registrado.  
5. **Fidelidad del contenido**: configuración y recursos del curso quedan equivalentes al origen.  
6. **No se “hackea” Moodle**: se usan APIs/restore nativo, con mínima lógica propia.  

---

## 11) Preguntas técnicas que Gemini Pro debe responder (para cerrar el diagnóstico)
1. ¿Por qué desaparece `file_id: 106777` entre retry #1 y retry #2?  
   - ¿lo borra el plugin? ¿un cleanup cron? ¿un almacenamiento temporal?
2. ¿El backup “con datos” del origen incluye intentos de quiz y está realmente consistente?
3. ¿Existe evidencia en el curso origen de preguntas/answers eliminadas y aún referenciadas por attempts?
4. ¿Moodle A y B tienen la misma versión y el mismo qtype multichoice?
5. ¿Se puede ajustar el restore setting para excluir intentos sin excluir todo user data? (probablemente no de forma segura; confirmar).

---

## 12) Notas finales
- Este caso no pide cambios en core Moodle.
- La prioridad es robustez operativa sin perder el enfoque de “clonado/transferencia masiva”.
- Si el usuario pidió “con datos” pero el origen está inconsistente, la plataforma debe:
  - o fallar con explicación clara,
  - o completar con fallback y dejar evidencia en el log.

