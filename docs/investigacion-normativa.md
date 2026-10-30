# Investigación normativa: Expediente Clínico Electrónico (México)

Fecha de la investigación: 2026-10-28. Verificar siempre contra el texto oficial del DOF antes de tomar decisiones de cumplimiento.

## 1. Marco legal aplicable

| Norma | Qué regula | Impacto en el sistema |
|---|---|---|
| **NOM-004-SSA3-2012** (Del expediente clínico) | Contenido obligatorio, reglas de las notas, conservación y confidencialidad | Define los formularios y campos |
| **NOM-024-SSA3-2012** (SIRES / intercambio de información en salud) | Requisitos técnicos de los sistemas electrónicos: identificación, catálogos, interoperabilidad, seguridad, certificación DGIS | Define la arquitectura, seguridad y BD |
| **Ley General de Salud**, reforma DOF 15-01-2026 (salud digital, Cap. VI Bis) | Telesalud, telemedicina, expediente electrónico | Consentimiento y documentación de atención digital. La obligación expresa es para instituciones públicas; no hay plazo claro para privados |
| **LFPDPPP 2025** (DOF 20-03-2025, sustituye la de 2010) | Datos personales; los datos de salud son **sensibles** | Aviso de privacidad, consentimiento expreso, derechos ARCO |
| NOM-006-SSA3-2011 | Anestesiología | Nota preanestésica / registro anestésico (si hay hospitalización) |
| NOM-035-SSA3-2012 | Información en salud | Reportes estadísticos (si aplica) |

## 2. NOM-004: contenido del expediente

### Reglas generales para TODAS las notas (5.9–5.11)
- Datos del paciente: nombre completo, edad, sexo, nº de expediente (y cama, si aplica).
- Fecha, hora, nombre completo y **firma** (autógrafa, electrónica o digital) de quien la elabora, más su cédula profesional.
- Lenguaje técnico-médico, **sin abreviaturas**, sin enmendaduras ni tachaduras. En el sistema esto se traduce en que **una nota firmada no se edita**: se corrige con una nota aclaratoria nueva.
- **Conservación: mínimo 5 años** desde el último acto médico (5.4). El expediente es propiedad de la institución; el paciente es titular de sus datos.

### Consulta externa (6)
- **Historia clínica (6.1)**
  - Interrogatorio: ficha de identificación, grupo étnico, antecedentes heredofamiliares, personales patológicos (incluye tabaco, alcohol y otras sustancias) y no patológicos, padecimiento actual e interrogatorio por aparatos y sistemas.
  - Exploración física: habitus exterior, signos vitales (temperatura, tensión arterial, frecuencia cardiaca y respiratoria), peso, talla y exploración por regiones.
  - Resultados de laboratorio y gabinete, diagnósticos, pronóstico e indicación terapéutica.
- **Nota de evolución (6.2):** evolución del cuadro, signos vitales, resultados relevantes, diagnósticos, pronóstico, tratamiento. Para cada medicamento: **dosis, vía y periodicidad**.
- **Nota de interconsulta (6.3):** solicitud del médico tratante más la nota del consultado (criterios diagnósticos, plan de estudios, sugerencias).
- **Nota de referencia/traslado (6.4):** establecimiento que envía y que recibe, resumen clínico, motivo, impresión diagnóstica y terapéutica empleada.

### Urgencias (7)
- **Nota inicial:** fecha y hora, signos vitales, motivo de atención, resumen del interrogatorio, exploración y estado mental, diagnósticos, tratamiento y pronóstico.

### Hospitalización (8)
- Nota de ingreso y historia clínica.
- Nota de evolución **al menos una vez al día**.
- Nota preoperatoria, lista de verificación quirúrgica (OMS), nota preanestésica, nota postoperatoria (técnica, hallazgos, conteo de gasas, sangrado, equipo quirúrgico, piezas a patología).
- **Nota de egreso:** fechas, motivo, diagnósticos finales, resumen de la evolución, pendientes, plan, pronóstico y, en caso de defunción, causas y necropsia.

### Otros documentos (9–10)
- **Hoja de enfermería:** habitus, gráfica de signos vitales, medicamentos (fecha, hora, cantidad y vía), procedimientos y observaciones.
- **Servicios auxiliares de diagnóstico:** fecha y hora, solicitante, estudio, problema clínico, resultados, incidentes, quién lo realizó y firma.
- **Carta de consentimiento informado:**
  - Contenido: establecimiento, título, lugar y fecha, acto autorizado, riesgos y beneficios.
  - Firmas del paciente (o su representante), del médico y de **dos testigos**.
  - Es obligatoria en: ingreso hospitalario, cirugía mayor, anestesia general o regional, salpingoclasia y vasectomía, trasplantes, investigación, necropsia, procedimientos de alto riesgo y mutilantes.

### Confidencialidad (5.5–5.7)
- La información solo se entrega a terceros con solicitud **escrita** del paciente, su tutor o su representante, o a una autoridad judicial o administrativa competente.
- Los resúmenes clínicos se solicitan por escrito.

## 3. NOM-024: requisitos técnicos del sistema (SIRES)

### Identificación del paciente (6.5)
- **CURP** como identificador único. El sistema **no debe autogenerarla**: se captura y se valida.
- Datos mínimos: CURP, primer apellido, segundo apellido, nombre, fecha de nacimiento, sexo, entidad de nacimiento, nacionalidad, folio interno y domicilio.

### Catálogos fundamentales (Apéndice A)
- **CLUES** (clave del establecimiento, DGIS).
- Catálogos geográficos INEGI (entidad, municipio, localidad) y código postal.
- **CIE-10** para diagnósticos (versión oficial vigente, CEMECE/DGIS) y CIE-9-MC para procedimientos.
- Medicamentos (Compendio Nacional de Insumos) y nacionalidad (RENAPO).

### Interoperabilidad (6.1–6.4)
- Intercambio según las Guías de Intercambio de Información en Salud (GIIS) de la DGIS.
- Estándares de referencia: **HL7 CDA**, HL7 v3, XML y perfiles IHE.
- Documentos electrónicos **estructurados e inalterables**.
- **Exportar** la información del paciente (6.6.6).

### Seguridad (6.6)
- Confidencialidad, integridad, disponibilidad, **trazabilidad** y **no repudio**.
- Autenticación con usuario y contraseña como mínimo; se recomienda un segundo factor.
- **Autorización por roles** (médico, enfermería, recepción, administrador, etc.).
- **Registro de auditoría** que asocie cada acción a un usuario.
- **Firma Electrónica Avanzada** del profesional en las notas. La e.firma del SAT es la opción más común en México.
- Cifrado en el intercambio entre prestadores.
- Control de los consentimientos del titular.

### Certificación
- La otorga la **DGIS** (o un organismo acreditado) tras un dictamen de verificación. Tiene **vigencia de 2 años**.
- Se evalúa: intercambio según las GIIS, uso de catálogos, identificación del paciente y funciones de seguridad.

## 4. Traducción a decisiones técnicas (stack: HTML5 + CSS + PHP 8.2 + MySQL/MariaDB en XAMPP)

- **BD relacional** con tablas de catálogos (CIE-10, CLUES, INEGI) importadas de los archivos oficiales de la DGIS e INEGI.
- **Paciente** con CURP única validada por formato (regex de 18 caracteres y dígito verificador).
- **Notas inmutables:**
  - Estado `borrador` → `firmada`.
  - Al firmar se guarda un hash SHA-256 del contenido y la firma; después ya no se permite UPDATE.
  - Las correcciones se hacen con una nota aclaratoria (addendum).
- **Bitácora de auditoría** en una tabla de solo inserción: usuario, acción, registro, IP y fecha y hora. Incluye las **lecturas** de expedientes.
- **Seguridad PHP:**
  - `password_hash()` (bcrypt/argon2), sesiones seguras, PDO con consultas preparadas, tokens CSRF y escape de salida (XSS).
  - HTTPS obligatorio y RBAC.
- **Eliminación lógica**, nunca física, por la retención mínima de 5 años.
- **Respaldos** automáticos de MySQL.
- **Impresión y PDF** de cada nota con los datos que exige la NOM-004.
- **Aviso de privacidad** y registro del consentimiento conforme a la LFPDPPP 2025.
- **Exportación** del expediente (PDF y, a futuro, HL7 CDA/XML).

## Fuentes
- NOM-004-SSA3-2012, DOF: https://dof.gob.mx/nota_detalle_popup.php?codigo=5272787
- NOM-024-SSA3-2012, DOF: https://dof.gob.mx/nota_detalle.php?codigo=5280847&fecha=30/11/2012
- Certificación SIRES, DGIS: http://www.dgis.salud.gob.mx/contenidos/intercambio/sires_certificacion_gobmx.html
- Reforma LGS salud digital (DOF 15-01-2026): https://www.hlc.com/en/publications/se-publica-en-el-diario-oficial-de-la-federacion-el-decreto-por-el-que-se-reforman
- LFPDPPP 2025: https://www.garrigues.com/es_ES/noticia/mexico-nueva-ley-federal-proteccion-datos-personales-posesion-particulares-introduce · https://www.diputados.gob.mx/LeyesBiblio/pdf/LFPDPPP.pdf
