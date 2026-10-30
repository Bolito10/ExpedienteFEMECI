-- ==========================================================================
-- Expediente FEMECI — esquema de base de datos MySQL
-- SQL estándar de MySQL 8; también corre en el MariaDB que trae XAMPP.
-- ==========================================================================

CREATE DATABASE IF NOT EXISTS expediente_femeci
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_spanish_ci;

USE expediente_femeci;

-- Usuarios del sistema (NOM-024 6.6.3 autenticación, 6.6.4 autorización por roles)
CREATE TABLE IF NOT EXISTS usuarios (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre              VARCHAR(120) NOT NULL,
  correo              VARCHAR(160) NOT NULL UNIQUE,
  cedula_profesional  VARCHAR(20)  NULL,                       -- requerida en notas firmadas (NOM-004 5.10)
  foto                VARCHAR(120) NULL,                       -- foto de perfil (assets/subidas/perfil-…)
  clave_hash          VARCHAR(255) NOT NULL,                   -- password_hash()
  rol                 ENUM('administrador','medico','enfermeria','recepcion') NOT NULL DEFAULT 'medico',
  activo              TINYINT(1)   NOT NULL DEFAULT 0,          -- un administrador aprueba las cuentas nuevas
  intentos_fallidos   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta     DATETIME     NULL,
  ultimo_acceso       DATETIME     NULL,
  creado_en           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Códigos de verificación de 10 dígitos enviados por correo al iniciar sesión (segundo factor)
CREATE TABLE IF NOT EXISTS codigos_verificacion (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT UNSIGNED NOT NULL,
  codigo_hash   VARCHAR(255) NOT NULL,                          -- nunca se guarda el código en claro
  expira_en     DATETIME     NOT NULL,
  intentos      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  usado_en      DATETIME     NULL,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_codigos_usuario (usuario_id, creado_en),
  CONSTRAINT fk_codigos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Pacientes: datos de identificación (NOM-004 6.1.1 / NOM-024 6.5, tabla 1)
CREATE TABLE IF NOT EXISTS pacientes (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  folio                   VARCHAR(20)  NOT NULL UNIQUE,          -- folio interno del establecimiento
  curp                    CHAR(18)     NULL UNIQUE,              -- se captura y valida, nunca se genera
  nombre                  VARCHAR(80)  NOT NULL,
  primer_apellido         VARCHAR(60)  NOT NULL,
  segundo_apellido        VARCHAR(60)  NULL,
  fecha_nacimiento        DATE         NOT NULL,
  sexo                    ENUM('H','M','X') NOT NULL,
  entidad_nacimiento      CHAR(2)      NULL,                     -- clave RENAPO (AS, BC, ... NE)
  nacionalidad            VARCHAR(40)  NULL,

  telefono                VARCHAR(20)  NULL,
  correo                  VARCHAR(120) NULL,
  domicilio               VARCHAR(200) NULL,
  municipio_estado        VARCHAR(120) NULL,
  contacto_emergencia     VARCHAR(120) NULL,
  telefono_emergencia     VARCHAR(20)  NULL,

  alergias                VARCHAR(255) NULL,
  grupo_sanguineo         VARCHAR(12)  NULL,
  padecimientos_cronicos  VARCHAR(255) NULL,
  medicamentos_actuales   VARCHAR(255) NULL,
  motivo_consulta         TEXT         NULL,

  confirmacion_datos      TINYINT(1)   NOT NULL DEFAULT 0,       -- datos proporcionados por el paciente o representante
  extras                  TEXT         NULL,                     -- propiedades agregadas en la tabla (JSON)
  foto                    VARCHAR(60)  NULL,                     -- archivo en privado/fotos_pacientes (se sirve con sesión)

  creado_en               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en          DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  eliminado_en            DATETIME     NULL,                     -- borrado lógico: conservación mínima 5 años (NOM-004 5.4)

  INDEX idx_pacientes_nombre (primer_apellido, segundo_apellido, nombre),
  INDEX idx_pacientes_activos (eliminado_en, creado_en)
) ENGINE=InnoDB;

-- Historial clínico del paciente: notas médicas y documentos de la NOM-004 (consulta/evolución,
-- urgencias, interconsulta, referencia/traslado, egreso, consentimiento informado), recetas,
-- hospitalizaciones y resultados de estudios. Los campos de cada tipo van en "detalle" (JSON);
-- el catálogo de tipos está en app/datos/registros_clinicos.php.
-- Inmutable (NOM-004 5.10): no se edita ni se borra; las correcciones son entradas nuevas.
-- Cada entrada guarda nombre y cédula de quien la elaboró en ese momento.
CREATE TABLE IF NOT EXISTS historial_clinico (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  paciente_id   INT UNSIGNED NOT NULL,
  tipo          ENUM('consulta','receta','hospitalizacion','estudio','urgencias',
                     'interconsulta','referencia','egreso','consentimiento') NOT NULL,
  fecha         DATETIME     NOT NULL,
  titulo        VARCHAR(200) NOT NULL,
  detalle       JSON         NOT NULL,                        -- campos propios de cada tipo
  autor_id      INT UNSIGNED NOT NULL,
  autor_nombre  VARCHAR(120) NOT NULL,
  autor_cedula  VARCHAR(20)  NULL,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_historial_paciente (paciente_id, fecha),
  CONSTRAINT fk_historial_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes (id),
  CONSTRAINT fk_historial_autor FOREIGN KEY (autor_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;
-- Migración de bases anteriores (solo tenían consulta, receta, hospitalización y estudio); repetirla no cambia nada.
ALTER TABLE historial_clinico
  MODIFY tipo ENUM('consulta','receta','hospitalizacion','estudio','urgencias',
                   'interconsulta','referencia','egreso','consentimiento') NOT NULL;

-- Portal del paciente: rol 'paciente' y cuenta ligada a su propio expediente (solo lectura).
-- Idempotente: repetirlo no cambia nada (va aquí porque la llave foránea necesita la tabla pacientes).
ALTER TABLE usuarios
  MODIFY rol ENUM('administrador','medico','enfermeria','recepcion','paciente') NOT NULL DEFAULT 'medico';
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS paciente_id INT UNSIGNED NULL AFTER rol;  -- expediente del usuario con rol paciente
SET @fk_usuarios_paciente := (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'
                                 AND COLUMN_NAME = 'paciente_id' AND REFERENCED_TABLE_NAME = 'pacientes');
SET @sql := IF(@fk_usuarios_paciente > 0, 'DO 0',
  'ALTER TABLE usuarios ADD CONSTRAINT fk_usuarios_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes (id)');
PREPARE consulta_fk FROM @sql; EXECUTE consulta_fk; DEALLOCATE PREPARE consulta_fk;

-- Configuración del sistema (foto de la clínica, proveedor del asistente de IA…).
-- Los valores secretos (clave de API) se guardan cifrados con AES-256-GCM.
CREATE TABLE IF NOT EXISTS configuracion (
  clave            VARCHAR(60)  PRIMARY KEY,
  valor            TEXT         NULL,
  actualizado_por  INT UNSIGNED NULL,
  actualizado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Límites de intentos (acceso, registro, código, asistente). La clave se guarda como SHA-256.
CREATE TABLE IF NOT EXISTS limites (
  clave        CHAR(64)     PRIMARY KEY,
  intentos     INT UNSIGNED NOT NULL DEFAULT 1,
  ventana_fin  DATETIME     NOT NULL,
  INDEX idx_limites_fin (ventana_fin)
) ENGINE=InnoDB;

-- Calificación del dictado por voz (👍/👎). Sin texto ni datos del paciente: solo si fue útil.
CREATE TABLE IF NOT EXISTS dictados_calificacion (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id       INT UNSIGNED NOT NULL,
  util             TINYINT(1)   NOT NULL,
  duracion_seg     DECIMAL(6,1) NULL,
  campos_llenados  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  creado_en        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_calificacion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Bitácora de auditoría: cada alta y cambio queda registrado (NOM-024 trazabilidad).
-- Solo se inserta; nunca se actualiza ni se borra.
CREATE TABLE IF NOT EXISTS bitacora (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tabla           VARCHAR(40)  NOT NULL,
  registro_id     INT UNSIGNED NOT NULL,
  accion          ENUM('crear','actualizar','eliminar','acceso','salida') NOT NULL,
  campo           VARCHAR(60)  NULL,
  valor_anterior  TEXT         NULL,
  valor_nuevo     TEXT         NULL,
  usuario_id      INT UNSIGNED NULL,                      -- se llenará cuando exista inicio de sesión
  ip              VARCHAR(45)  NULL,
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bitacora_registro (tabla, registro_id),
  INDEX idx_bitacora_fecha (tabla, registro_id, creado_en),
  INDEX idx_bitacora_usuario (usuario_id, creado_en)
) ENGINE=InnoDB;

-- Espacio de trabajo: carpetas, notas y bases de datos anidadas (carpeta > carpeta > nota…).
-- Privadas de quien las crea; al compartir una página, ella y todo lo que contiene lo ve el equipo.
CREATE TABLE IF NOT EXISTS paginas (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  padre_id        INT UNSIGNED NULL,
  tipo            ENUM('carpeta','nota','base_datos') NOT NULL DEFAULT 'nota',
  titulo          VARCHAR(200) NOT NULL DEFAULT '',
  portada         VARCHAR(160) NULL,                        -- imagen de portada (galería o subida)
  portada_alto    SMALLINT UNSIGNED NULL,                   -- alto elegido en px (vacío = predeterminado)
  contenido       MEDIUMTEXT   NULL,                       -- JSON: bloques de la nota o columnas de la base de datos
  version         INT UNSIGNED NOT NULL DEFAULT 1,          -- evita que dos sesiones se sobrescriban
  propietario_id  INT UNSIGNED NOT NULL,
  compartida      TINYINT(1)   NOT NULL DEFAULT 0,
  paciente_id     INT UNSIGNED NULL,                         -- página del expediente de un paciente (notas y bases de datos propias)
  orden           INT          NOT NULL DEFAULT 0,
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_por INT UNSIGNED NULL,
  eliminado_en    DATETIME     NULL,                        -- papelera
  eliminado_por   INT UNSIGNED NULL,
  INDEX idx_paginas_padre (padre_id, orden),
  INDEX idx_paginas_propietario (propietario_id),
  INDEX idx_paginas_paciente (paciente_id, eliminado_en),
  CONSTRAINT fk_paginas_padre FOREIGN KEY (padre_id) REFERENCES paginas (id) ON DELETE CASCADE,
  CONSTRAINT fk_paginas_propietario FOREIGN KEY (propietario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

-- Filas de las páginas de tipo base de datos; los valores van por id de columna.
CREATE TABLE IF NOT EXISTS pagina_filas (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pagina_id       INT UNSIGNED NOT NULL,
  valores         TEXT         NOT NULL,                    -- JSON {"c1": "…", "c2": 3}
  orden           INT          NOT NULL DEFAULT 0,
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_filas_pagina (pagina_id, orden),
  CONSTRAINT fk_filas_pagina FOREIGN KEY (pagina_id) REFERENCES paginas (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Favoritos de cada usuario (la estrella de la barra superior)
CREATE TABLE IF NOT EXISTS paginas_favoritas (
  usuario_id  INT UNSIGNED NOT NULL,
  pagina_id   INT UNSIGNED NOT NULL,
  creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, pagina_id),
  CONSTRAINT fk_favoritas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
  CONSTRAINT fk_favoritas_pagina FOREIGN KEY (pagina_id) REFERENCES paginas (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Personas invitadas a una página (y a todo lo que contiene) con su nivel de acceso
CREATE TABLE IF NOT EXISTS pagina_permisos (
  pagina_id     INT UNSIGNED NOT NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  nivel         ENUM('completo','editar','ver') NOT NULL DEFAULT 'editar',
  invitado_por  INT UNSIGNED NULL,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (pagina_id, usuario_id),
  INDEX idx_permisos_usuario (usuario_id),
  CONSTRAINT fk_permisos_pagina FOREIGN KEY (pagina_id) REFERENCES paginas (id) ON DELETE CASCADE,
  CONSTRAINT fk_permisos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Presencia: quién está en cada sección o página y si está editando (se renueva cada 15 s)
CREATE TABLE IF NOT EXISTS presencia (
  usuario_id  INT UNSIGNED NOT NULL,
  clave       VARCHAR(60)  NOT NULL,                        -- p. ej. "pagina:12", "paciente:3", "pacientes:lista"
  editando    TINYINT(1)   NOT NULL DEFAULT 0,
  visto_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, clave),
  INDEX idx_presencia_clave (clave, visto_en),
  CONSTRAINT fk_presencia_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Invitaciones: el administrador invita por correo con un rol; el enlace (token de un solo uso,
-- guardado como SHA-256) abre el registro en tres pasos. Vencen a los 7 días.
CREATE TABLE IF NOT EXISTS invitaciones (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  correo        VARCHAR(160) NOT NULL,
  rol           ENUM('administrador','medico','enfermeria','recepcion') NOT NULL,
  token_hash    CHAR(64)     NOT NULL UNIQUE,
  invitado_por  INT UNSIGNED NOT NULL,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en     DATETIME     NOT NULL,
  aceptada_en   DATETIME     NULL,
  usuario_id    INT UNSIGNED NULL,                        -- cuenta creada con la invitación
  cancelada_en  DATETIME     NULL,
  INDEX idx_invitaciones_correo (correo),
  CONSTRAINT fk_invitaciones_autor FOREIGN KEY (invitado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB;

-- Agenda de citas: cada cita es de un profesional y (normalmente) de un paciente.
-- Recordatorio: N minutos antes se avisa en la app y por correo al profesional (y al paciente si se pidió).
CREATE TABLE IF NOT EXISTS citas (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  paciente_id             INT UNSIGNED NULL,
  profesional_id          INT UNSIGNED NOT NULL,
  inicio                  DATETIME     NOT NULL,
  fin                     DATETIME     NOT NULL,
  motivo                  VARCHAR(200) NOT NULL DEFAULT '',
  notas                   TEXT         NULL,
  estado                  ENUM('programada','confirmada','atendida','cancelada','no_asistio') NOT NULL DEFAULT 'programada',
  recordatorio_min        SMALLINT UNSIGNED NULL,            -- minutos antes (vacío = sin recordatorio)
  avisar_paciente         TINYINT(1)   NOT NULL DEFAULT 0,   -- también por correo al paciente
  recordatorio_enviado_en DATETIME     NULL,
  recordatorio_visto_en   DATETIME     NULL,                 -- aviso mostrado en la app
  creado_por              INT UNSIGNED NOT NULL,
  creado_en               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en          DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_citas_inicio (inicio),
  INDEX idx_citas_profesional (profesional_id, inicio),
  INDEX idx_citas_paciente (paciente_id, inicio),
  INDEX idx_citas_recordatorio (recordatorio_enviado_en, inicio),
  CONSTRAINT fk_citas_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes (id),
  CONSTRAINT fk_citas_profesional FOREIGN KEY (profesional_id) REFERENCES usuarios (id),
  CONSTRAINT fk_citas_autor FOREIGN KEY (creado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB;

-- Notificaciones: avisos persistentes para cada usuario (solo eventos reales: recordatorios de citas,
-- invitaciones aceptadas, páginas compartidas). Se marcan como leídas o se archivan; no se borran.
CREATE TABLE IF NOT EXISTS notificaciones (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT UNSIGNED NOT NULL,                     -- destinatario
  tipo          VARCHAR(40)  NOT NULL,                     -- cita_recordatorio, invitacion_aceptada, pagina_compartida
  titulo        VARCHAR(200) NOT NULL,
  texto         VARCHAR(500) NOT NULL DEFAULT '',
  url           VARCHAR(500) NULL,                         -- ruta dentro de la app (sin URL_BASE)
  origen_tabla  VARCHAR(40)  NULL,
  origen_id     INT UNSIGNED NULL,
  actor_id      INT UNSIGNED NULL,                         -- quién provocó el aviso (si aplica)
  leida_en      DATETIME     NULL,
  archivada_en  DATETIME     NULL,
  creada_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notificaciones_bandeja (usuario_id, archivada_en, id),
  INDEX idx_notificaciones_no_leidas (usuario_id, leida_en),
  INDEX idx_notificaciones_origen (origen_tabla, origen_id),
  CONSTRAINT fk_notificaciones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
  CONSTRAINT fk_notificaciones_actor FOREIGN KEY (actor_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Conversiones de datos (importar y exportar): cada archivo que entra o sale queda registrado
-- con su huella SHA-256, quién lo hizo, el formato y cuántas filas se procesaron (NOM-024 trazabilidad).
-- Solo se inserta; nunca se actualiza ni se borra.
CREATE TABLE IF NOT EXISTS conversiones (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id      INT UNSIGNED NOT NULL,
  direccion       ENUM('importar','exportar') NOT NULL,
  formato         VARCHAR(30)  NOT NULL,                     -- csv, xlsx, json, xml, sql_mysql, hl7v2, fhir…
  archivo_nombre  VARCHAR(255) NOT NULL,                     -- archivo de origen (importar) o generado (exportar)
  archivo_sha256  CHAR(64)     NOT NULL,
  archivo_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
  origen          VARCHAR(255) NOT NULL,                     -- p. ej. "pacientes.xlsx · hoja Hoja1" o "Pacientes"
  destino         VARCHAR(255) NOT NULL,                     -- p. ej. "Pacientes" o "Base de datos «Laboratorio»"
  filas_ok        INT UNSIGNED NOT NULL DEFAULT 0,
  filas_error     INT UNSIGNED NOT NULL DEFAULT 0,
  notas           TEXT         NULL,
  ip              VARCHAR(45)  NULL,
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_conversiones_fecha (creado_en),
  INDEX idx_conversiones_usuario (usuario_id, creado_en),
  CONSTRAINT fk_conversiones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

-- Peticiones idempotentes: la clave X-Idempotencia que manda el navegador con cada POST JSON a /api/.
-- Si la red se corta después de que el servidor guardó, la cola sin conexión reintenta con la misma clave
-- y recibe la respuesta guardada en lugar de repetir la escritura. Se borran solas a los 7 días.
CREATE TABLE IF NOT EXISTS peticiones_idempotentes (
  clave       CHAR(64)          NOT NULL PRIMARY KEY,          -- SHA-256 de usuario + clave del navegador
  usuario_id  INT UNSIGNED      NOT NULL,
  ruta        VARCHAR(190)      NOT NULL,                      -- endpoint que la atendió
  estado      ENUM('en_proceso','terminada') NOT NULL DEFAULT 'en_proceso',
  codigo      SMALLINT UNSIGNED NULL,                          -- código HTTP de la respuesta
  respuesta   MEDIUMTEXT        NULL,                          -- cuerpo JSON (null si era muy grande)
  creado_en   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_idempotentes_fecha (creado_en),
  CONSTRAINT fk_idempotentes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- ==========================================================================
-- Varias instituciones en una sola base (expediente_femeci) y códigos de acceso.
-- Repetirlo no cambia nada (IF NOT EXISTS de MariaDB).
-- ==========================================================================

-- Instituciones: consultorio, clínica u hospital. Quien la registra queda como su administrador.
CREATE TABLE IF NOT EXISTS instituciones (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(160) NOT NULL,
  tipo            ENUM('consultorio','clinica','hospital') NOT NULL,
  clues           CHAR(11)     NULL,                       -- Clave Única de Establecimientos de Salud (DGIS), opcional
  rfc             VARCHAR(13)  NULL,                       -- RFC de la persona física o moral, opcional
  domicilio       VARCHAR(250) NOT NULL,
  entidad         CHAR(2)      NOT NULL,                   -- clave RENAPO de la entidad federativa
  creada_por      INT UNSIGNED NOT NULL,
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_instituciones_clues (clues),
  CONSTRAINT fk_instituciones_autor FOREIGN KEY (creada_por) REFERENCES usuarios (id)
) ENGINE=InnoDB;

-- Usuarios: institución, rol paciente (portal del paciente), datos del médico y estado de su cédula.
ALTER TABLE usuarios
  MODIFY rol ENUM('administrador','medico','enfermeria','recepcion','paciente') NOT NULL DEFAULT 'medico',
  ADD COLUMN IF NOT EXISTS institucion_id      INT UNSIGNED NULL AFTER id,
  ADD COLUMN IF NOT EXISTS paciente_id         INT UNSIGNED NULL AFTER rol,          -- solo rol paciente
  ADD COLUMN IF NOT EXISTS fecha_nacimiento    DATE         NULL AFTER nombre,
  ADD COLUMN IF NOT EXISTS especialidad        VARCHAR(120) NULL AFTER cedula_profesional,
  ADD COLUMN IF NOT EXISTS cedula_especialidad VARCHAR(20)  NULL AFTER especialidad,
  ADD COLUMN IF NOT EXISTS cedula_estado       ENUM('pendiente','verificada','no_coincide','no_encontrada') NULL AFTER cedula_especialidad,
  ADD COLUMN IF NOT EXISTS cedula_motivo       VARCHAR(255) NULL AFTER cedula_estado,  -- resultado del último intento
  ADD COLUMN IF NOT EXISTS cedula_titulo       VARCHAR(255) NULL AFTER cedula_motivo,  -- título e institución según la SEP
  ADD COLUMN IF NOT EXISTS cedula_revisada_en  DATETIME     NULL AFTER cedula_titulo,
  ADD COLUMN IF NOT EXISTS registro_origen     ENUM('fundador','codigo','invitacion') NULL,  -- vacío = cuenta anterior a las instituciones
  ADD UNIQUE INDEX IF NOT EXISTS uq_usuarios_cedula (cedula_profesional),
  ADD INDEX IF NOT EXISTS idx_usuarios_institucion (institucion_id, activo),
  ADD CONSTRAINT fk_usuarios_institucion FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- Datos que se aíslan por institución (cada consulta se acota a la institución del usuario).
-- Las filas anteriores (institucion_id vacío) pasan a la primera institución que registre
-- un administrador de antes de las instituciones (app/servicios/cuenta/instituciones.php).
ALTER TABLE pacientes
  ADD COLUMN IF NOT EXISTS institucion_id INT UNSIGNED NULL AFTER id,
  DROP INDEX IF EXISTS curp,
  ADD UNIQUE INDEX IF NOT EXISTS uq_pacientes_curp (institucion_id, curp),     -- la CURP no se repite dentro de una institución
  DROP INDEX IF EXISTS idx_pacientes_nombre,
  ADD INDEX IF NOT EXISTS idx_pacientes_institucion_nombre (institucion_id, primer_apellido, segundo_apellido, nombre),
  ADD INDEX IF NOT EXISTS idx_pacientes_institucion (institucion_id, eliminado_en, creado_en),
  ADD CONSTRAINT fk_pacientes_institucion FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

ALTER TABLE historial_clinico
  ADD COLUMN IF NOT EXISTS institucion_id INT UNSIGNED NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_historial_institucion (institucion_id, fecha),
  ADD CONSTRAINT fk_historial_institucion FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

ALTER TABLE paginas
  ADD COLUMN IF NOT EXISTS institucion_id INT UNSIGNED NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_paginas_institucion (institucion_id, orden, id),
  ADD CONSTRAINT fk_paginas_institucion FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

ALTER TABLE citas
  ADD COLUMN IF NOT EXISTS institucion_id INT UNSIGNED NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_citas_institucion (institucion_id, inicio),
  ADD CONSTRAINT fk_citas_institucion FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

ALTER TABLE conversiones
  ADD COLUMN IF NOT EXISTS institucion_id INT UNSIGNED NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_conversiones_institucion (institucion_id, creado_en),
  ADD CONSTRAINT fk_conversiones_institucion FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

ALTER TABLE invitaciones
  ADD COLUMN IF NOT EXISTS institucion_id INT UNSIGNED NULL AFTER id,
  ADD INDEX IF NOT EXISTS idx_invitaciones_institucion (institucion_id, creado_en),
  ADD CONSTRAINT fk_invitaciones_institucion FOREIGN KEY IF NOT EXISTS (institucion_id) REFERENCES instituciones (id);

-- Bitácora: institución de quien hizo el movimiento (consultas de auditoría por institución)
ALTER TABLE bitacora
  ADD COLUMN IF NOT EXISTS institucion_id INT UNSIGNED NULL AFTER usuario_id,
  ADD INDEX IF NOT EXISTS idx_bitacora_institucion (institucion_id, creado_en);

-- Configuración por institución (0 = valores de antes de las instituciones; pasan a la primera
-- institución que registre un administrador de antes, igual que los datos).
ALTER TABLE configuracion
  ADD COLUMN IF NOT EXISTS institucion_id INT UNSIGNED NOT NULL DEFAULT 0 FIRST,
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (institucion_id, clave);

-- Códigos de acceso para incorporar personal o pacientes: XXXX-XXXX-XXXX (base32 de Crockford,
-- 60 bits). Solo se guarda su HMAC-SHA-256; la terminación (4 caracteres) sirve para reconocerlo en la lista.
CREATE TABLE IF NOT EXISTS codigos_acceso (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id  INT UNSIGNED NOT NULL,
  codigo_hash     CHAR(64)     NOT NULL,
  terminacion     CHAR(4)      NOT NULL,
  rol             ENUM('administrador','medico','enfermeria','recepcion','paciente') NOT NULL,
  paciente_id     INT UNSIGNED NULL,                         -- obligatorio para el rol paciente
  usos_maximos    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  usos            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  expira_en       DATETIME     NOT NULL,
  creado_por      INT UNSIGNED NOT NULL,
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_uso_en   DATETIME     NULL,
  revocado_en     DATETIME     NULL,
  revocado_por    INT UNSIGNED NULL,
  UNIQUE KEY uq_codigos_acceso_hash (codigo_hash),
  INDEX idx_codigos_acceso_institucion (institucion_id, creado_en),
  INDEX idx_codigos_acceso_paciente (paciente_id),
  CONSTRAINT fk_codigos_acceso_institucion FOREIGN KEY (institucion_id) REFERENCES instituciones (id),
  CONSTRAINT fk_codigos_acceso_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes (id),
  CONSTRAINT fk_codigos_acceso_autor FOREIGN KEY (creado_por) REFERENCES usuarios (id),
  CONSTRAINT fk_codigos_acceso_revoco FOREIGN KEY (revocado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB;

-- Cada cuenta creada con un código (trazabilidad). Solo se inserta.
CREATE TABLE IF NOT EXISTS codigos_acceso_usos (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_id   INT UNSIGNED NOT NULL,
  usuario_id  INT UNSIGNED NOT NULL,
  ip          VARCHAR(45)  NULL,
  usado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_codigos_usos_codigo (codigo_id, usado_en),
  CONSTRAINT fk_codigos_usos_codigo FOREIGN KEY (codigo_id) REFERENCES codigos_acceso (id),
  CONSTRAINT fk_codigos_usos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

-- ==========================================================================
-- Notificaciones push del sistema (Web Push, app/servicios/cuenta/push.php)
-- Solo con permiso explícito del usuario. La carga nunca lleva datos clínicos ni nombres de pacientes.
-- Las llaves VAPID viven en privado/push_vapid.php (nunca en la base ni en el navegador, salvo la pública).
-- ==========================================================================

-- Dispositivos suscritos de cada usuario. El endpoint y las llaves del navegador se guardan cifrados
-- (AES-256-GCM con privado/llave.bin); se buscan por la huella SHA-256 del endpoint.
CREATE TABLE IF NOT EXISTS push_suscripciones (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id     INT UNSIGNED NOT NULL,
  endpoint       VARCHAR(1500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,   -- cifrado
  endpoint_hash  CHAR(64)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  p256dh         VARCHAR(255)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,   -- cifrado
  auth           VARCHAR(255)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,   -- cifrado
  agente         VARCHAR(80)   NOT NULL DEFAULT '',                              -- "Chrome · Windows"
  creada_en      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_uso_en  DATETIME      NULL,                                             -- último envío aceptado
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  INDEX idx_push_usuario (usuario_id),
  CONSTRAINT fk_push_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Qué avisos recibe cada usuario por push, avisos por umbral y horario de silencio.
CREATE TABLE IF NOT EXISTS push_preferencias (
  usuario_id              INT UNSIGNED PRIMARY KEY,
  cita_recordatorio       TINYINT(1)        NOT NULL DEFAULT 1,
  cita_anticipacion       SMALLINT UNSIGNED NOT NULL DEFAULT 0,     -- minutos; 0 = la de cada cita
  invitacion_aceptada     TINYINT(1)        NOT NULL DEFAULT 1,
  pagina_compartida       TINYINT(1)        NOT NULL DEFAULT 1,
  aviso                   TINYINT(1)        NOT NULL DEFAULT 1,
  umbral_citas_activo     TINYINT(1)        NOT NULL DEFAULT 0,     -- avisar al llegar a N citas pendientes hoy
  umbral_citas_n          SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  umbral_pacientes_activo TINYINT(1)        NOT NULL DEFAULT 0,     -- avisar cada N pacientes nuevos en el día
  umbral_pacientes_n      SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  signos_fuera_rango      TINYINT(1)        NOT NULL DEFAULT 0,
  silencio_activo         TINYINT(1)        NOT NULL DEFAULT 0,
  silencio_inicio         TIME              NOT NULL DEFAULT '22:00:00',
  silencio_fin            TIME              NOT NULL DEFAULT '07:00:00',
  actualizado_en          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_push_preferencias_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Cola de envíos: se llena sin tocar la red y se despacha aparte; sin internet, espera y se reintenta
-- con espera creciente hasta que vence su TTL. La carga es el JSON genérico (título, texto, ruta interna).
CREATE TABLE IF NOT EXISTS push_cola (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  suscripcion_id    INT UNSIGNED  NOT NULL,
  carga             VARCHAR(1000) NOT NULL,
  ttl               INT UNSIGNED  NOT NULL,
  urgencia          ENUM('very-low','low','normal','high') NOT NULL DEFAULT 'normal',
  tema              VARCHAR(32)   CHARACTER SET ascii NULL,         -- Topic (reemplaza el aviso anterior del mismo tema)
  intentos          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  siguiente_intento DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en         DATETIME      NOT NULL,
  tomado_por        CHAR(16)      CHARACTER SET ascii NULL,         -- marca del proceso que lo está enviando
  tomado_en         DATETIME      NULL,
  ultimo_error      VARCHAR(200)  NULL,
  creado_en         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_push_cola_pendientes (siguiente_intento),
  INDEX idx_push_cola_tomado (tomado_por),
  CONSTRAINT fk_push_cola_suscripcion FOREIGN KEY (suscripcion_id) REFERENCES push_suscripciones (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Antirrepetición: cada aviso (recordatorio de una cita, umbral del día, signo de un registro) se reclama
-- una sola vez por usuario. Se depuran a los 45 días.
CREATE TABLE IF NOT EXISTS push_avisos_enviados (
  usuario_id  INT UNSIGNED NOT NULL,
  clave       VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,   -- p. ej. citas_hoy:20261030:10
  creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, clave),
  INDEX idx_push_avisos_fecha (creado_en),
  CONSTRAINT fk_push_avisos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ==========================================================================
-- Sincronización local → servidor central (y central → local)
-- Cada consultorio trabaja con su base local; lo capturado se envía al central cuando hay internet.
--   - uuid: identificador global de cada fila (los id numéricos son de cada instalación).
--   - sinc_modificado_en: marca de la última escritura; la mantienen los triggers y decide
--     qué versión gana (la última escritura gana). El historial clínico solo se agrega.
--   - sincronizacion_cola: cola de salida y diario de cambios. La llenan triggers de MariaDB, así
--     ningún camino de escritura (formularios, API, asistente de IA, importaciones, root) se escapa.
--     Los triggers corren con los privilegios de su DEFINER (root); los genera
--     database/sincronizacion_triggers.php a partir de app/servicios/sincronizacion/tablas.php.
--   - Instituciones: la cola lleva institucion_id; cada token de sincronización es de una institución.
-- Esta sección usa ADD COLUMN IF NOT EXISTS (MariaDB 10.0.2+); repetirla no cambia nada.
-- El relleno de uuid de las filas existentes lo hace el propio ALTER (DEFAULT (UUID()) se evalúa por
-- fila) y se ejecutó una sola vez como root el 2026-10-30: historial_clinico no admite UPDATE para la app.
-- ==========================================================================

CREATE TABLE IF NOT EXISTS sincronizacion_cola (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tabla            VARCHAR(40)  NOT NULL,
  uuid             CHAR(36)     CHARACTER SET ascii NOT NULL,
  institucion_id   INT UNSIGNED NULL,                            -- institución de la fila: cada token sincroniza solo la suya
  operacion        ENUM('alta','cambio','baja') NOT NULL,
  datos            LONGTEXT     NULL,                            -- JSON: estado de la fila (referencias por uuid, personas por correo);
                                                                 -- NULL si la fila superó max_allowed_packet (se avisa, no se envía)
  origen           CHAR(36)     CHARACTER SET ascii NULL,        -- NULL: capturado aquí (se envía); si no, nodo del que llegó
  creado_en        DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  intentos         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  proximo_intento  DATETIME     NULL,                            -- espera creciente después de un fallo
  enviado_en       DATETIME     NULL,                            -- solo con acuse firmado del central
  error            VARCHAR(500) NULL,
  INDEX idx_cola_pendientes (origen, enviado_en, id),
  INDEX idx_cola_registro (tabla, uuid, operacion),
  INDEX idx_cola_fecha (creado_en),
  INDEX idx_cola_institucion (institucion_id, origen, enviado_en, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
-- Migración de la primera versión de la cola (sin institución)
ALTER TABLE sincronizacion_cola
  ADD COLUMN IF NOT EXISTS institucion_id INT UNSIGNED NULL AFTER uuid,
  ADD INDEX IF NOT EXISTS idx_cola_institucion (institucion_id, origen, enviado_en, id),
  MODIFY datos LONGTEXT NULL;

ALTER TABLE pacientes
  ADD COLUMN IF NOT EXISTS uuid CHAR(36) CHARACTER SET ascii NOT NULL DEFAULT (UUID()) AFTER id,
  ADD COLUMN IF NOT EXISTS sinc_modificado_en DATETIME(6) NULL,
  ADD UNIQUE INDEX IF NOT EXISTS uq_pacientes_uuid (uuid);
ALTER TABLE historial_clinico
  ADD COLUMN IF NOT EXISTS uuid CHAR(36) CHARACTER SET ascii NOT NULL DEFAULT (UUID()) AFTER id,
  ADD UNIQUE INDEX IF NOT EXISTS uq_historial_uuid (uuid);
ALTER TABLE citas
  ADD COLUMN IF NOT EXISTS uuid CHAR(36) CHARACTER SET ascii NOT NULL DEFAULT (UUID()) AFTER id,
  ADD COLUMN IF NOT EXISTS sinc_modificado_en DATETIME(6) NULL,
  ADD UNIQUE INDEX IF NOT EXISTS uq_citas_uuid (uuid);
ALTER TABLE paginas
  ADD COLUMN IF NOT EXISTS uuid CHAR(36) CHARACTER SET ascii NOT NULL DEFAULT (UUID()) AFTER id,
  ADD COLUMN IF NOT EXISTS sinc_modificado_en DATETIME(6) NULL,
  ADD UNIQUE INDEX IF NOT EXISTS uq_paginas_uuid (uuid);
ALTER TABLE pagina_filas
  ADD COLUMN IF NOT EXISTS uuid CHAR(36) CHARACTER SET ascii NOT NULL DEFAULT (UUID()) AFTER id,
  ADD COLUMN IF NOT EXISTS sinc_modificado_en DATETIME(6) NULL,
  ADD UNIQUE INDEX IF NOT EXISTS uq_pagina_filas_uuid (uuid);

-- Marca inicial de las filas existentes: su última modificación conocida (actualizado_en = actualizado_en
-- evita que ON UPDATE CURRENT_TIMESTAMP cambie la fecha real). Corre antes de crear los triggers.
UPDATE pacientes    SET sinc_modificado_en = COALESCE(actualizado_en, creado_en), actualizado_en = actualizado_en WHERE sinc_modificado_en IS NULL;
UPDATE citas        SET sinc_modificado_en = COALESCE(actualizado_en, creado_en), actualizado_en = actualizado_en WHERE sinc_modificado_en IS NULL;
UPDATE paginas      SET sinc_modificado_en = actualizado_en WHERE sinc_modificado_en IS NULL;
UPDATE pagina_filas SET sinc_modificado_en = actualizado_en WHERE sinc_modificado_en IS NULL;
-- Generado por database/sincronizacion_triggers.php: no editar a mano.
DELIMITER //

-- pacientes
DROP TRIGGER IF EXISTS sinc_pacientes_ai_antes //
CREATE TRIGGER sinc_pacientes_ai_antes BEFORE INSERT ON pacientes FOR EACH ROW
BEGIN
  IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = UUID(); END IF;
  IF @sinc_origen IS NULL OR NEW.sinc_modificado_en IS NULL THEN SET NEW.sinc_modificado_en = NOW(6); END IF;
END //
DROP TRIGGER IF EXISTS sinc_pacientes_ai //
CREATE TRIGGER sinc_pacientes_ai AFTER INSERT ON pacientes FOR EACH ROW
  INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
  VALUES ('pacientes', NEW.uuid, NEW.institucion_id, 'alta', JSON_OBJECT(
      'folio', NEW.folio,
      'curp', NEW.curp,
      'nombre', NEW.nombre,
      'primer_apellido', NEW.primer_apellido,
      'segundo_apellido', NEW.segundo_apellido,
      'fecha_nacimiento', NEW.fecha_nacimiento,
      'sexo', NEW.sexo,
      'entidad_nacimiento', NEW.entidad_nacimiento,
      'nacionalidad', NEW.nacionalidad,
      'telefono', NEW.telefono,
      'correo', NEW.correo,
      'domicilio', NEW.domicilio,
      'municipio_estado', NEW.municipio_estado,
      'contacto_emergencia', NEW.contacto_emergencia,
      'telefono_emergencia', NEW.telefono_emergencia,
      'alergias', NEW.alergias,
      'grupo_sanguineo', NEW.grupo_sanguineo,
      'padecimientos_cronicos', NEW.padecimientos_cronicos,
      'medicamentos_actuales', NEW.medicamentos_actuales,
      'motivo_consulta', NEW.motivo_consulta,
      'confirmacion_datos', NEW.confirmacion_datos,
      'extras', NEW.extras,
      'creado_en', NEW.creado_en,
      'actualizado_en', NEW.actualizado_en,
      'eliminado_en', NEW.eliminado_en,
      'institucion_id', NEW.institucion_id,
      'sinc_modificado_en', NEW.sinc_modificado_en), @sinc_origen) //
DROP TRIGGER IF EXISTS sinc_pacientes_au_antes //
CREATE TRIGGER sinc_pacientes_au_antes BEFORE UPDATE ON pacientes FOR EACH ROW
BEGIN
  IF OLD.uuid IS NOT NULL AND OLD.uuid <> '' THEN SET NEW.uuid = OLD.uuid; END IF;
  IF @sinc_origen IS NULL AND NOT (NEW.folio <=> OLD.folio AND NEW.curp <=> OLD.curp AND NEW.nombre <=> OLD.nombre AND NEW.primer_apellido <=> OLD.primer_apellido AND NEW.segundo_apellido <=> OLD.segundo_apellido AND NEW.fecha_nacimiento <=> OLD.fecha_nacimiento AND NEW.sexo <=> OLD.sexo AND NEW.entidad_nacimiento <=> OLD.entidad_nacimiento AND NEW.nacionalidad <=> OLD.nacionalidad AND NEW.telefono <=> OLD.telefono AND NEW.correo <=> OLD.correo AND NEW.domicilio <=> OLD.domicilio AND NEW.municipio_estado <=> OLD.municipio_estado AND NEW.contacto_emergencia <=> OLD.contacto_emergencia AND NEW.telefono_emergencia <=> OLD.telefono_emergencia AND NEW.alergias <=> OLD.alergias AND NEW.grupo_sanguineo <=> OLD.grupo_sanguineo AND NEW.padecimientos_cronicos <=> OLD.padecimientos_cronicos AND NEW.medicamentos_actuales <=> OLD.medicamentos_actuales AND NEW.motivo_consulta <=> OLD.motivo_consulta AND NEW.confirmacion_datos <=> OLD.confirmacion_datos AND NEW.extras <=> OLD.extras AND NEW.creado_en <=> OLD.creado_en AND NEW.actualizado_en <=> OLD.actualizado_en AND NEW.eliminado_en <=> OLD.eliminado_en AND NEW.institucion_id <=> OLD.institucion_id) THEN SET NEW.sinc_modificado_en = NOW(6); END IF;
END //
DROP TRIGGER IF EXISTS sinc_pacientes_au //
CREATE TRIGGER sinc_pacientes_au AFTER UPDATE ON pacientes FOR EACH ROW
BEGIN
  IF NOT (NEW.sinc_modificado_en <=> OLD.sinc_modificado_en) THEN
    INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
    VALUES ('pacientes', NEW.uuid, NEW.institucion_id, 'cambio', JSON_OBJECT(
      'folio', NEW.folio,
      'curp', NEW.curp,
      'nombre', NEW.nombre,
      'primer_apellido', NEW.primer_apellido,
      'segundo_apellido', NEW.segundo_apellido,
      'fecha_nacimiento', NEW.fecha_nacimiento,
      'sexo', NEW.sexo,
      'entidad_nacimiento', NEW.entidad_nacimiento,
      'nacionalidad', NEW.nacionalidad,
      'telefono', NEW.telefono,
      'correo', NEW.correo,
      'domicilio', NEW.domicilio,
      'municipio_estado', NEW.municipio_estado,
      'contacto_emergencia', NEW.contacto_emergencia,
      'telefono_emergencia', NEW.telefono_emergencia,
      'alergias', NEW.alergias,
      'grupo_sanguineo', NEW.grupo_sanguineo,
      'padecimientos_cronicos', NEW.padecimientos_cronicos,
      'medicamentos_actuales', NEW.medicamentos_actuales,
      'motivo_consulta', NEW.motivo_consulta,
      'confirmacion_datos', NEW.confirmacion_datos,
      'extras', NEW.extras,
      'creado_en', NEW.creado_en,
      'actualizado_en', NEW.actualizado_en,
      'eliminado_en', NEW.eliminado_en,
      'institucion_id', NEW.institucion_id,
      'sinc_modificado_en', NEW.sinc_modificado_en), @sinc_origen);
  END IF;
  IF NOT (NEW.institucion_id <=> OLD.institucion_id) THEN
    UPDATE sincronizacion_cola SET institucion_id = NEW.institucion_id
     WHERE origen IS NULL AND enviado_en IS NULL AND ((tabla = 'pacientes' AND uuid = NEW.uuid));
  END IF;
END //

-- paginas
DROP TRIGGER IF EXISTS sinc_paginas_ai_antes //
CREATE TRIGGER sinc_paginas_ai_antes BEFORE INSERT ON paginas FOR EACH ROW
BEGIN
  IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = UUID(); END IF;
  IF @sinc_origen IS NULL OR NEW.sinc_modificado_en IS NULL THEN SET NEW.sinc_modificado_en = NOW(6); END IF;
END //
DROP TRIGGER IF EXISTS sinc_paginas_ai //
CREATE TRIGGER sinc_paginas_ai AFTER INSERT ON paginas FOR EACH ROW
  INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
  VALUES ('paginas', NEW.uuid, NEW.institucion_id, 'alta', JSON_OBJECT(
      'tipo', NEW.tipo,
      'titulo', NEW.titulo,
      'portada', NEW.portada,
      'portada_alto', NEW.portada_alto,
      'contenido', NEW.contenido,
      'version', NEW.version,
      'compartida', NEW.compartida,
      'orden', NEW.orden,
      'creado_en', NEW.creado_en,
      'actualizado_en', NEW.actualizado_en,
      'eliminado_en', NEW.eliminado_en,
      'padre_uuid', (SELECT r.uuid FROM paginas r WHERE r.id = NEW.padre_id),
      'paciente_uuid', (SELECT r.uuid FROM pacientes r WHERE r.id = NEW.paciente_id),
      'propietario', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = NEW.propietario_id),
      'actualizado_por_persona', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = NEW.actualizado_por),
      'eliminado_por_persona', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = NEW.eliminado_por),
      'institucion_id', NEW.institucion_id,
      'sinc_modificado_en', NEW.sinc_modificado_en), @sinc_origen) //
DROP TRIGGER IF EXISTS sinc_paginas_au_antes //
CREATE TRIGGER sinc_paginas_au_antes BEFORE UPDATE ON paginas FOR EACH ROW
BEGIN
  IF OLD.uuid IS NOT NULL AND OLD.uuid <> '' THEN SET NEW.uuid = OLD.uuid; END IF;
  IF @sinc_origen IS NULL AND NOT (NEW.tipo <=> OLD.tipo AND NEW.titulo <=> OLD.titulo AND NEW.portada <=> OLD.portada AND NEW.portada_alto <=> OLD.portada_alto AND NEW.contenido <=> OLD.contenido AND NEW.version <=> OLD.version AND NEW.compartida <=> OLD.compartida AND NEW.orden <=> OLD.orden AND NEW.creado_en <=> OLD.creado_en AND NEW.actualizado_en <=> OLD.actualizado_en AND NEW.eliminado_en <=> OLD.eliminado_en AND NEW.padre_id <=> OLD.padre_id AND NEW.paciente_id <=> OLD.paciente_id AND NEW.propietario_id <=> OLD.propietario_id AND NEW.actualizado_por <=> OLD.actualizado_por AND NEW.eliminado_por <=> OLD.eliminado_por AND NEW.institucion_id <=> OLD.institucion_id) THEN SET NEW.sinc_modificado_en = NOW(6); END IF;
END //
DROP TRIGGER IF EXISTS sinc_paginas_au //
CREATE TRIGGER sinc_paginas_au AFTER UPDATE ON paginas FOR EACH ROW
BEGIN
  IF NOT (NEW.sinc_modificado_en <=> OLD.sinc_modificado_en) THEN
    INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
    VALUES ('paginas', NEW.uuid, NEW.institucion_id, 'cambio', JSON_OBJECT(
      'tipo', NEW.tipo,
      'titulo', NEW.titulo,
      'portada', NEW.portada,
      'portada_alto', NEW.portada_alto,
      'contenido', NEW.contenido,
      'version', NEW.version,
      'compartida', NEW.compartida,
      'orden', NEW.orden,
      'creado_en', NEW.creado_en,
      'actualizado_en', NEW.actualizado_en,
      'eliminado_en', NEW.eliminado_en,
      'padre_uuid', (SELECT r.uuid FROM paginas r WHERE r.id = NEW.padre_id),
      'paciente_uuid', (SELECT r.uuid FROM pacientes r WHERE r.id = NEW.paciente_id),
      'propietario', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = NEW.propietario_id),
      'actualizado_por_persona', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = NEW.actualizado_por),
      'eliminado_por_persona', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = NEW.eliminado_por),
      'institucion_id', NEW.institucion_id,
      'sinc_modificado_en', NEW.sinc_modificado_en), @sinc_origen);
  END IF;
  IF NOT (NEW.institucion_id <=> OLD.institucion_id) THEN
    UPDATE sincronizacion_cola SET institucion_id = NEW.institucion_id
     WHERE origen IS NULL AND enviado_en IS NULL AND ((tabla = 'paginas' AND uuid = NEW.uuid)
        OR (tabla = 'pagina_filas' AND uuid IN (SELECT f.uuid FROM pagina_filas f WHERE f.pagina_id = NEW.id)));
  END IF;
END //
DROP TRIGGER IF EXISTS sinc_paginas_ad //
CREATE TRIGGER sinc_paginas_ad AFTER DELETE ON paginas FOR EACH ROW
  INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
  VALUES ('paginas', OLD.uuid, OLD.institucion_id, 'baja', JSON_OBJECT('sinc_modificado_en', COALESCE(@sinc_fecha_baja, NOW(6))), @sinc_origen) //

-- citas
DROP TRIGGER IF EXISTS sinc_citas_ai_antes //
CREATE TRIGGER sinc_citas_ai_antes BEFORE INSERT ON citas FOR EACH ROW
BEGIN
  IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = UUID(); END IF;
  IF @sinc_origen IS NULL OR NEW.sinc_modificado_en IS NULL THEN SET NEW.sinc_modificado_en = NOW(6); END IF;
END //
DROP TRIGGER IF EXISTS sinc_citas_ai //
CREATE TRIGGER sinc_citas_ai AFTER INSERT ON citas FOR EACH ROW
  INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
  VALUES ('citas', NEW.uuid, NEW.institucion_id, 'alta', JSON_OBJECT(
      'inicio', NEW.inicio,
      'fin', NEW.fin,
      'motivo', NEW.motivo,
      'notas', NEW.notas,
      'estado', NEW.estado,
      'recordatorio_min', NEW.recordatorio_min,
      'avisar_paciente', NEW.avisar_paciente,
      'recordatorio_enviado_en', NEW.recordatorio_enviado_en,
      'creado_en', NEW.creado_en,
      'actualizado_en', NEW.actualizado_en,
      'paciente_uuid', (SELECT r.uuid FROM pacientes r WHERE r.id = NEW.paciente_id),
      'profesional', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = NEW.profesional_id),
      'creado_por_persona', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = NEW.creado_por),
      'institucion_id', NEW.institucion_id,
      'sinc_modificado_en', NEW.sinc_modificado_en), @sinc_origen) //
DROP TRIGGER IF EXISTS sinc_citas_au_antes //
CREATE TRIGGER sinc_citas_au_antes BEFORE UPDATE ON citas FOR EACH ROW
BEGIN
  IF OLD.uuid IS NOT NULL AND OLD.uuid <> '' THEN SET NEW.uuid = OLD.uuid; END IF;
  IF @sinc_origen IS NULL AND NOT (NEW.inicio <=> OLD.inicio AND NEW.fin <=> OLD.fin AND NEW.motivo <=> OLD.motivo AND NEW.notas <=> OLD.notas AND NEW.estado <=> OLD.estado AND NEW.recordatorio_min <=> OLD.recordatorio_min AND NEW.avisar_paciente <=> OLD.avisar_paciente AND NEW.recordatorio_enviado_en <=> OLD.recordatorio_enviado_en AND NEW.creado_en <=> OLD.creado_en AND NEW.actualizado_en <=> OLD.actualizado_en AND NEW.paciente_id <=> OLD.paciente_id AND NEW.profesional_id <=> OLD.profesional_id AND NEW.creado_por <=> OLD.creado_por AND NEW.institucion_id <=> OLD.institucion_id) THEN SET NEW.sinc_modificado_en = NOW(6); END IF;
END //
DROP TRIGGER IF EXISTS sinc_citas_au //
CREATE TRIGGER sinc_citas_au AFTER UPDATE ON citas FOR EACH ROW
BEGIN
  IF NOT (NEW.sinc_modificado_en <=> OLD.sinc_modificado_en) THEN
    INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
    VALUES ('citas', NEW.uuid, NEW.institucion_id, 'cambio', JSON_OBJECT(
      'inicio', NEW.inicio,
      'fin', NEW.fin,
      'motivo', NEW.motivo,
      'notas', NEW.notas,
      'estado', NEW.estado,
      'recordatorio_min', NEW.recordatorio_min,
      'avisar_paciente', NEW.avisar_paciente,
      'recordatorio_enviado_en', NEW.recordatorio_enviado_en,
      'creado_en', NEW.creado_en,
      'actualizado_en', NEW.actualizado_en,
      'paciente_uuid', (SELECT r.uuid FROM pacientes r WHERE r.id = NEW.paciente_id),
      'profesional', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = NEW.profesional_id),
      'creado_por_persona', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = NEW.creado_por),
      'institucion_id', NEW.institucion_id,
      'sinc_modificado_en', NEW.sinc_modificado_en), @sinc_origen);
  END IF;
  IF NOT (NEW.institucion_id <=> OLD.institucion_id) THEN
    UPDATE sincronizacion_cola SET institucion_id = NEW.institucion_id
     WHERE origen IS NULL AND enviado_en IS NULL AND ((tabla = 'citas' AND uuid = NEW.uuid));
  END IF;
END //

-- historial_clinico
DROP TRIGGER IF EXISTS sinc_historial_clinico_ai_antes //
CREATE TRIGGER sinc_historial_clinico_ai_antes BEFORE INSERT ON historial_clinico FOR EACH ROW
BEGIN
  IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = UUID(); END IF;
END //
DROP TRIGGER IF EXISTS sinc_historial_clinico_ai //
CREATE TRIGGER sinc_historial_clinico_ai AFTER INSERT ON historial_clinico FOR EACH ROW
  INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
  VALUES ('historial_clinico', NEW.uuid, NEW.institucion_id, 'alta', JSON_OBJECT(
      'tipo', NEW.tipo,
      'fecha', NEW.fecha,
      'titulo', NEW.titulo,
      'detalle', NEW.detalle,
      'autor_nombre', NEW.autor_nombre,
      'autor_cedula', NEW.autor_cedula,
      'creado_en', NEW.creado_en,
      'paciente_uuid', (SELECT r.uuid FROM pacientes r WHERE r.id = NEW.paciente_id),
      'autor', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = NEW.autor_id),
      'institucion_id', NEW.institucion_id), @sinc_origen) //
DROP TRIGGER IF EXISTS sinc_historial_clinico_au //
CREATE TRIGGER sinc_historial_clinico_au AFTER UPDATE ON historial_clinico FOR EACH ROW
BEGIN
  IF NOT (NEW.institucion_id <=> OLD.institucion_id) THEN
    UPDATE sincronizacion_cola SET institucion_id = NEW.institucion_id
     WHERE origen IS NULL AND enviado_en IS NULL AND ((tabla = 'historial_clinico' AND uuid = NEW.uuid));
  END IF;
END //

-- pagina_filas
DROP TRIGGER IF EXISTS sinc_pagina_filas_ai_antes //
CREATE TRIGGER sinc_pagina_filas_ai_antes BEFORE INSERT ON pagina_filas FOR EACH ROW
BEGIN
  IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = UUID(); END IF;
  IF @sinc_origen IS NULL OR NEW.sinc_modificado_en IS NULL THEN SET NEW.sinc_modificado_en = NOW(6); END IF;
END //
DROP TRIGGER IF EXISTS sinc_pagina_filas_ai //
CREATE TRIGGER sinc_pagina_filas_ai AFTER INSERT ON pagina_filas FOR EACH ROW
  INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
  VALUES ('pagina_filas', NEW.uuid, (SELECT pi.institucion_id FROM paginas pi WHERE pi.id = NEW.pagina_id), 'alta', JSON_OBJECT(
      'valores', NEW.valores,
      'orden', NEW.orden,
      'creado_en', NEW.creado_en,
      'actualizado_en', NEW.actualizado_en,
      'pagina_uuid', (SELECT r.uuid FROM paginas r WHERE r.id = NEW.pagina_id),
      'institucion_id', (SELECT pi.institucion_id FROM paginas pi WHERE pi.id = NEW.pagina_id),
      'sinc_modificado_en', NEW.sinc_modificado_en), @sinc_origen) //
DROP TRIGGER IF EXISTS sinc_pagina_filas_au_antes //
CREATE TRIGGER sinc_pagina_filas_au_antes BEFORE UPDATE ON pagina_filas FOR EACH ROW
BEGIN
  IF OLD.uuid IS NOT NULL AND OLD.uuid <> '' THEN SET NEW.uuid = OLD.uuid; END IF;
  IF @sinc_origen IS NULL AND NOT (NEW.valores <=> OLD.valores AND NEW.orden <=> OLD.orden AND NEW.creado_en <=> OLD.creado_en AND NEW.actualizado_en <=> OLD.actualizado_en AND NEW.pagina_id <=> OLD.pagina_id) THEN SET NEW.sinc_modificado_en = NOW(6); END IF;
END //
DROP TRIGGER IF EXISTS sinc_pagina_filas_au //
CREATE TRIGGER sinc_pagina_filas_au AFTER UPDATE ON pagina_filas FOR EACH ROW
BEGIN
  IF NOT (NEW.sinc_modificado_en <=> OLD.sinc_modificado_en) THEN
    INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
    VALUES ('pagina_filas', NEW.uuid, (SELECT pi.institucion_id FROM paginas pi WHERE pi.id = NEW.pagina_id), 'cambio', JSON_OBJECT(
      'valores', NEW.valores,
      'orden', NEW.orden,
      'creado_en', NEW.creado_en,
      'actualizado_en', NEW.actualizado_en,
      'pagina_uuid', (SELECT r.uuid FROM paginas r WHERE r.id = NEW.pagina_id),
      'institucion_id', (SELECT pi.institucion_id FROM paginas pi WHERE pi.id = NEW.pagina_id),
      'sinc_modificado_en', NEW.sinc_modificado_en), @sinc_origen);
  END IF;
END //
DROP TRIGGER IF EXISTS sinc_pagina_filas_ad //
CREATE TRIGGER sinc_pagina_filas_ad AFTER DELETE ON pagina_filas FOR EACH ROW
  INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
  VALUES ('pagina_filas', OLD.uuid, (SELECT pi.institucion_id FROM paginas pi WHERE pi.id = OLD.pagina_id), 'baja', JSON_OBJECT('sinc_modificado_en', COALESCE(@sinc_fecha_baja, NOW(6))), @sinc_origen) //

DELIMITER ;

-- Siembra: filas existentes a la cola (una sola vez; repetirlo no duplica)
INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
SELECT 'pacientes', t.uuid, t.institucion_id, 'alta', JSON_OBJECT(
      'folio', t.folio,
      'curp', t.curp,
      'nombre', t.nombre,
      'primer_apellido', t.primer_apellido,
      'segundo_apellido', t.segundo_apellido,
      'fecha_nacimiento', t.fecha_nacimiento,
      'sexo', t.sexo,
      'entidad_nacimiento', t.entidad_nacimiento,
      'nacionalidad', t.nacionalidad,
      'telefono', t.telefono,
      'correo', t.correo,
      'domicilio', t.domicilio,
      'municipio_estado', t.municipio_estado,
      'contacto_emergencia', t.contacto_emergencia,
      'telefono_emergencia', t.telefono_emergencia,
      'alergias', t.alergias,
      'grupo_sanguineo', t.grupo_sanguineo,
      'padecimientos_cronicos', t.padecimientos_cronicos,
      'medicamentos_actuales', t.medicamentos_actuales,
      'motivo_consulta', t.motivo_consulta,
      'confirmacion_datos', t.confirmacion_datos,
      'extras', t.extras,
      'creado_en', t.creado_en,
      'actualizado_en', t.actualizado_en,
      'eliminado_en', t.eliminado_en,
      'institucion_id', t.institucion_id,
      'sinc_modificado_en', t.sinc_modificado_en), NULL
  FROM pacientes t
 WHERE NOT EXISTS (SELECT 1 FROM sincronizacion_cola c WHERE c.tabla = 'pacientes' AND c.uuid = t.uuid)
 ORDER BY t.id;
INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
SELECT 'paginas', t.uuid, t.institucion_id, 'alta', JSON_OBJECT(
      'tipo', t.tipo,
      'titulo', t.titulo,
      'portada', t.portada,
      'portada_alto', t.portada_alto,
      'contenido', t.contenido,
      'version', t.version,
      'compartida', t.compartida,
      'orden', t.orden,
      'creado_en', t.creado_en,
      'actualizado_en', t.actualizado_en,
      'eliminado_en', t.eliminado_en,
      'padre_uuid', (SELECT r.uuid FROM paginas r WHERE r.id = t.padre_id),
      'paciente_uuid', (SELECT r.uuid FROM pacientes r WHERE r.id = t.paciente_id),
      'propietario', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = t.propietario_id),
      'actualizado_por_persona', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = t.actualizado_por),
      'eliminado_por_persona', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = t.eliminado_por),
      'institucion_id', t.institucion_id,
      'sinc_modificado_en', t.sinc_modificado_en), NULL
  FROM paginas t
 WHERE NOT EXISTS (SELECT 1 FROM sincronizacion_cola c WHERE c.tabla = 'paginas' AND c.uuid = t.uuid)
 ORDER BY t.id;
INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
SELECT 'citas', t.uuid, t.institucion_id, 'alta', JSON_OBJECT(
      'inicio', t.inicio,
      'fin', t.fin,
      'motivo', t.motivo,
      'notas', t.notas,
      'estado', t.estado,
      'recordatorio_min', t.recordatorio_min,
      'avisar_paciente', t.avisar_paciente,
      'recordatorio_enviado_en', t.recordatorio_enviado_en,
      'creado_en', t.creado_en,
      'actualizado_en', t.actualizado_en,
      'paciente_uuid', (SELECT r.uuid FROM pacientes r WHERE r.id = t.paciente_id),
      'profesional', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = t.profesional_id),
      'creado_por_persona', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = t.creado_por),
      'institucion_id', t.institucion_id,
      'sinc_modificado_en', t.sinc_modificado_en), NULL
  FROM citas t
 WHERE NOT EXISTS (SELECT 1 FROM sincronizacion_cola c WHERE c.tabla = 'citas' AND c.uuid = t.uuid)
 ORDER BY t.id;
INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
SELECT 'historial_clinico', t.uuid, t.institucion_id, 'alta', JSON_OBJECT(
      'tipo', t.tipo,
      'fecha', t.fecha,
      'titulo', t.titulo,
      'detalle', t.detalle,
      'autor_nombre', t.autor_nombre,
      'autor_cedula', t.autor_cedula,
      'creado_en', t.creado_en,
      'paciente_uuid', (SELECT r.uuid FROM pacientes r WHERE r.id = t.paciente_id),
      'autor', (SELECT JSON_OBJECT('correo', u.correo, 'nombre', u.nombre, 'cedula', u.cedula_profesional, 'rol', u.rol) FROM usuarios u WHERE u.id = t.autor_id),
      'institucion_id', t.institucion_id), NULL
  FROM historial_clinico t
 WHERE NOT EXISTS (SELECT 1 FROM sincronizacion_cola c WHERE c.tabla = 'historial_clinico' AND c.uuid = t.uuid)
 ORDER BY t.id;
INSERT INTO sincronizacion_cola (tabla, uuid, institucion_id, operacion, datos, origen)
SELECT 'pagina_filas', t.uuid, (SELECT pi.institucion_id FROM paginas pi WHERE pi.id = t.pagina_id), 'alta', JSON_OBJECT(
      'valores', t.valores,
      'orden', t.orden,
      'creado_en', t.creado_en,
      'actualizado_en', t.actualizado_en,
      'pagina_uuid', (SELECT r.uuid FROM paginas r WHERE r.id = t.pagina_id),
      'institucion_id', (SELECT pi.institucion_id FROM paginas pi WHERE pi.id = t.pagina_id),
      'sinc_modificado_en', t.sinc_modificado_en), NULL
  FROM pagina_filas t
 WHERE NOT EXISTS (SELECT 1 FROM sincronizacion_cola c WHERE c.tabla = 'pagina_filas' AND c.uuid = t.uuid)
 ORDER BY t.id;

-- Recuperación de contraseña por correo (enlace de un solo uso, 30 minutos; solo se guarda el hash del token)
CREATE TABLE IF NOT EXISTS recuperaciones_clave (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en   DATETIME NOT NULL,
  usado_en    DATETIME NULL,
  ip          VARCHAR(45) NULL,
  UNIQUE KEY uk_recuperacion_token (token_hash),
  KEY idx_recuperacion_usuario (usuario_id),
  CONSTRAINT fk_recuperacion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
GRANT SELECT, INSERT, UPDATE ON expediente_femeci.recuperaciones_clave TO 'femeci_app'@'localhost';

-- Imágenes médicas (DICOM, JPG/PNG, PDF) por paciente, agrupadas en estudio y serie; archivos en privado/imagenes_medicas
CREATE TABLE IF NOT EXISTS imagenes_medicas (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id  INT UNSIGNED NULL,
  paciente_id     INT UNSIGNED NOT NULL,
  estudio_uid     VARCHAR(80) NOT NULL,
  serie_uid       VARCHAR(80) NOT NULL,
  instancia       INT NOT NULL DEFAULT 0,
  modalidad       VARCHAR(8) NOT NULL DEFAULT 'OT',
  fecha_estudio   DATE NULL,
  descripcion     VARCHAR(160) NULL,
  serie           VARCHAR(160) NULL,
  formato         ENUM('dicom','imagen','pdf') NOT NULL,
  archivo         CHAR(68) NOT NULL,
  nombre_original VARCHAR(180) NOT NULL,
  tamano          INT UNSIGNED NOT NULL,
  sha256          CHAR(64) NOT NULL,
  metadatos       JSON NULL,
  subido_por      INT UNSIGNED NULL,
  creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_imagen_paciente (paciente_id, estudio_uid, serie_uid, instancia),
  KEY idx_imagen_institucion (institucion_id),
  UNIQUE KEY uk_imagen_archivo (paciente_id, sha256),
  CONSTRAINT fk_imagen_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes (id),
  CONSTRAINT fk_imagen_institucion FOREIGN KEY (institucion_id) REFERENCES instituciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
GRANT SELECT, INSERT, DELETE ON expediente_femeci.imagenes_medicas TO 'femeci_app'@'localhost';

-- Notas debajo de las secciones principales (Pacientes, Agenda y cada expediente), como el espacio para
-- escribir bajo una base de datos en Notion. Una nota por sección e institución; no aparecen en el árbol.
-- seccion: 'pacientes', 'agenda' o 'expediente:<paciente_id>'
ALTER TABLE paginas
  ADD COLUMN IF NOT EXISTS seccion VARCHAR(40) NULL AFTER paciente_id,
  ADD UNIQUE KEY IF NOT EXISTS uk_pagina_seccion (institucion_id, seccion);

-- Memoria del asistente: datos que el usuario le pide recordar (preferencias, forma de trabajar, contexto).
-- Por persona; nunca se comparten con otra cuenta. Sin datos clínicos de pacientes (eso vive en el expediente).
CREATE TABLE IF NOT EXISTS asistente_memoria (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id      INT UNSIGNED NOT NULL,
  institucion_id  INT UNSIGNED NULL,
  texto           VARCHAR(500) NOT NULL,
  creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_memoria_usuario (usuario_id),
  CONSTRAINT fk_memoria_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_femeci.asistente_memoria TO 'femeci_app'@'localhost';
