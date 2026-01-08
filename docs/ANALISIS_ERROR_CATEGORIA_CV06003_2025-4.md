# Análisis del Error 11001: ID de Categoría en Uso

**Fecha:** 8 de enero de 2026  
**Error:** "El número ID ya está en uso por otra categoría"  
**Categoría afectada:** CV06003ONL - TÉCNICO DE NIVEL SUPERIOR EN CONTABILIDAD GENERAL  
**idnumber a restaurar:** 2025-4-CV06003ONL

---

## 📋 Resumen del Problema

El error **11001** indica que el `idnumber` de la categoría que se intenta restaurar ya existe en el sistema destino. Sin embargo, tras el análisis de ambas bases de datos, se encontró un **problema diferente**: **conflicto de IDs numéricos** entre las bases de datos.

---

## 🔍 Hallazgos del Análisis

### 1. Estado en Base de Datos Legacy (Origen)

| Campo | Valor |
|-------|-------|
| **ID** | 2439 |
| **Nombre** | CV06003ONL - TÉCNICO DE NIVEL SUPERIOR EN CONTABILIDAD GENERAL |
| **idnumber** | 2025-4-CV06003ONL |
| **Parent** | 2405 (categoría "4" período 2025-4) |
| **Path** | /2134/2405/2439 |
| **Visible** | 1 (activo) |

### 2. Estado en Base de Datos Producción (Destino)

#### ⚠️ Conflicto de ID Numérico

El **ID 2439** ya existe en producción pero con **contenido diferente**:

| Campo | Valor en Producción |
|-------|---------------------|
| **ID** | 2439 |
| **Nombre** | CV06045ONL - INGENIERÍA EN ADMINISTRACIÓN DE EMPRESAS PLAN CONTINUIDAD |
| **idnumber** | 2025-4-CV06045ONL |
| **Parent** | 2412 |
| **Path** | /2134/2412/2439 |

#### ⚠️ Conflicto de ID de Categoría Padre

El **ID 2405** también existe con contenido diferente:

| Campo | Legacy (Origen) | Producción (Destino) |
|-------|-----------------|----------------------|
| **ID** | 2405 | 2405 |
| **Nombre** | 4 (período) | CV06059ONL - INGENIERÍA EN ADMINISTRACIÓN... |
| **idnumber** | 2025-4 | 2025-5-CV06059ONL |

### 3. Categoría 2025-4-CV06003ONL en Producción

**NO EXISTE** la categoría `2025-4-CV06003ONL` en producción. Solo existen:
- `2025-1-CV06003ONL` (ID: 2228)
- `2025-2-CV06003ONL` (ID: 2278)
- `2025-3-CV06003ONL` (ID: 2325)
- `2025-5-CV06003ONL` (ID: 2391)

❌ **Falta 2025-4-CV06003ONL**

### 4. Configuración de Papelera de Reciclaje

```
tool_recyclebin:
├── coursebinenable: 1 (activado)
├── coursebinexpiry: 604800 (7 días)
├── categorybinenable: 1 (activado)
├── categorybinexpiry: 604800 (7 días)
└── autohide: 1
```

La papelera **NO** contiene la categoría `2025-4-CV06003ONL`. Los registros en `mdl_tool_recyclebin_category` son de **cursos**, no de categorías.

---

## 🎯 Diagnóstico Final

El problema **NO es la papelera de reciclaje**. El problema es un **desincronismo de IDs** entre las bases de datos:

1. **Las bases de datos Legacy y Producción tienen estructuras de IDs diferentes**
2. El plugin CourseTransfer intenta usar el mismo ID numérico (2439) que ya existe en producción con otra categoría
3. La categoría padre (2405) también tiene conflicto de IDs

### Estructura de Categorías 2025-4

| Sistema | ID Período "4" | idnumber |
|---------|----------------|----------|
| Legacy | 2405 | 2025-4 |
| Producción | 2412 | 2025-4 |

---

## ✅ Soluciones Recomendadas

### Opción 1: Crear la Categoría Manualmente en Producción (RECOMENDADA)

Crear la categoría `2025-4-CV06003ONL` directamente en producción bajo el padre correcto:

1. En producción, la categoría padre "4" (2025-4) tiene **ID 2412**
2. Crear categoría con:
   - **Nombre:** CV06003ONL - TÉCNICO DE NIVEL SUPERIOR EN CONTABILIDAD GENERAL
   - **idnumber:** 2025-4-CV06003ONL
   - **Parent:** 2412 (categoría "4" en 2025-4 de producción)

### Opción 2: Modificar la Configuración del Plugin CourseTransfer

Configurar el plugin para que **NO preserve IDs** de categorías y genere nuevos IDs automáticamente:

```php
// En la configuración del plugin
$config->preserve_category_ids = false;
```

### Opción 3: Restaurar Solo los Cursos

Si la categoría es solo contenedora, restaurar los cursos directamente a una categoría ya existente en producción.

---

## 📊 Resumen de Conflictos de IDs

| ID | Contenido en Legacy | Contenido en Producción |
|----|---------------------|-------------------------|
| 2405 | Período "4" (2025-4) | CV06059ONL - ING. ADMIN. EMPRESAS... |
| 2412 | (no existe) | Período "4" (2025-4) |
| 2439 | CV06003ONL - CONTABILIDAD | CV06045ONL - ING. ADMIN. EMPRESAS... |

---

## 🔧 Acción Inmediata

Para resolver el error actual sin modificaciones en la base de datos:

1. **Crear manualmente** la categoría `2025-4-CV06003ONL` en producción bajo el padre con idnumber `2025-4` (ID 2412)
2. Una vez creada, el plugin debería poder restaurar los cursos dentro de ella

---

## 📝 Notas Adicionales

- La papelera de reciclaje de Moodle **no retiene categorías eliminadas**, solo retiene cursos eliminados de categorías
- El conflicto se debe a que ambos sistemas evolucionaron de forma independiente generando IDs diferentes
- Se recomienda revisar la configuración del plugin CourseTransfer para manejar la sincronización de categorías sin depender del ID numérico
