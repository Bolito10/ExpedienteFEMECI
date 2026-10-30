-- ==========================================================================
-- Usuario de MySQL con privilegios mínimos para la aplicación (misma base: expediente_femeci).
-- Ejecutar como root y guardar la contraseña en privado/bd.php:
--   <?php return ['usuario' => 'femeci_app', 'clave' => 'LA_CONTRASEÑA'];
-- La bitácora y el historial clínico solo admiten INSERT: no se pueden modificar ni borrar.
-- ==========================================================================

CREATE USER IF NOT EXISTS 'femeci_app'@'localhost' IDENTIFIED BY 'CAMBIA_ESTA_CONTRASEÑA';
CREATE USER IF NOT EXISTS 'femeci_app'@'127.0.0.1' IDENTIFIED BY 'CAMBIA_ESTA_CONTRASEÑA';

-- Datos que se crean y actualizan
GRANT SELECT, INSERT, UPDATE ON expediente_femeci.pacientes             TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON expediente_femeci.usuarios              TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON expediente_femeci.codigos_verificacion  TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON expediente_femeci.configuracion         TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON expediente_femeci.dictados_calificacion TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON expediente_femeci.invitaciones          TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';

-- Inmutables (NOM-004 5.10 y trazabilidad NOM-024)
GRANT SELECT, INSERT ON expediente_femeci.bitacora          TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT ON expediente_femeci.historial_clinico TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';

-- Espacio de trabajo, límites de intentos y presencia (se pueden borrar)
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_femeci.limites           TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_femeci.paginas           TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_femeci.pagina_filas      TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_femeci.paginas_favoritas TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_femeci.pagina_permisos   TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_femeci.presencia         TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';

FLUSH PRIVILEGES;

-- Agenda (las citas se cancelan, no se borran)
GRANT SELECT, INSERT, UPDATE ON expediente_femeci.citas             TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';

-- Notificaciones (se marcan como leídas o se archivan; no se borran)
GRANT SELECT, INSERT, UPDATE ON expediente_femeci.notificaciones    TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';

-- Conversiones de datos (importar/exportar): registro inmutable, solo INSERT
GRANT SELECT, INSERT ON expediente_femeci.conversiones TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';

-- Peticiones idempotentes de la cola sin conexión (se borran solas a los 7 días)
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_femeci.peticiones_idempotentes TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';

-- Sincronización con el servidor central: la cola la llenan triggers (corren con los privilegios de root,
-- su DEFINER); la app marca acuses, reintentos y borra lo enviado hace más de 30 días.
-- Sin DELETE en pacientes, citas ni historial: allí no hay bajas físicas que aplicar.
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_femeci.sincronizacion_cola TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';

-- ==========================================================================
-- Respaldos (app/servicios/sistema/respaldos.php): usuario aparte, solo para mysqldump y para restaurar.
-- La aplicación normal nunca lo usa. Contraseña en privado/respaldo_bd.php:
--   <?php return ['usuario' => 'femeci_respaldo', 'clave' => 'LA_CONTRASEÑA'];
-- Restaurar recrea las tablas, por eso necesita CREATE/DROP/ALTER sobre esta base (y solo esta).
-- ==========================================================================
CREATE USER IF NOT EXISTS 'femeci_respaldo'@'localhost' IDENTIFIED BY 'CAMBIA_ESTA_CONTRASEÑA';
CREATE USER IF NOT EXISTS 'femeci_respaldo'@'127.0.0.1' IDENTIFIED BY 'CAMBIA_ESTA_CONTRASEÑA';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, LOCK TABLES, SHOW VIEW, CREATE VIEW, TRIGGER, EVENT, CREATE ROUTINE, ALTER ROUTINE, EXECUTE
  ON expediente_femeci.* TO 'femeci_respaldo'@'localhost', 'femeci_respaldo'@'127.0.0.1';
FLUSH PRIVILEGES;

-- Instituciones y códigos de acceso (database/esquema.sql, sección "Varias instituciones").
-- El historial clínico y las conversiones siguen siendo de solo inserción: solo se permite
-- asignar institucion_id (las filas de antes pasan a la institución que las adopta).
GRANT SELECT, INSERT, UPDATE ON expediente_femeci.instituciones       TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON expediente_femeci.codigos_acceso      TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT         ON expediente_femeci.codigos_acceso_usos TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT UPDATE (institucion_id) ON expediente_femeci.historial_clinico  TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT UPDATE (institucion_id) ON expediente_femeci.conversiones       TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
FLUSH PRIVILEGES;

-- Notificaciones push (database/esquema.sql, sección "Notificaciones push del sistema"):
-- suscripciones, preferencias, cola de envíos y antirrepetición; se crean, cambian y borran.
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_femeci.push_suscripciones   TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_femeci.push_preferencias    TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_femeci.push_cola            TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_femeci.push_avisos_enviados TO 'femeci_app'@'localhost', 'femeci_app'@'127.0.0.1';
FLUSH PRIVILEGES;
