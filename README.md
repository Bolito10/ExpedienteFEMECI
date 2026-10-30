# Expedientes Clínicos

Expediente clínico electrónico para establecimientos de salud en México, basado en la
NOM-004-SSA3-2012 (expediente clínico), la NOM-024-SSA3-2012 (sistemas de información en salud)
y la LFPDPPP (protección de datos personales). PHP 8.2 + MySQL/MariaDB (XAMPP) + JavaScript sin frameworks.

Todo vive en **una sola base de datos**: `expediente_femeci`.

## Estructura

```
index.php                Entrada: lleva a Pacientes
acceso/                  Iniciar sesión (correo → contraseña → código de 10 dígitos por correo),
                         crear cuenta en 3 pasos (también desde una invitación) y cerrar sesión
pacientes/               Tabla de pacientes, registro/edición, perfil, nuevos registros clínicos
                         (nuevo_registro.php), guardado del historial y documento completo (evento.php)
agenda/                  Calendario de citas (día, semana, mes, lista) con recordatorios
paginas/                 Espacio de trabajo: carpetas, notas (editor por bloques) y bases de datos
datos/                   Importar y exportar (CSV, Excel, JSON, XML, SQL, SQLite, HL7, FHIR) con bitácora
asistente/               Asistente de IA a pantalla completa
configuracion/           Personas y permisos (solo administrador)
api/                     Endpoints JSON que usa el navegador
  pacientes/             Búsqueda, edición en línea, tarjeta, foto, páginas del paciente, dictado por voz
  paginas/               Árbol, contenido, compartir, actividad, bases de datos, imágenes de notas
  agenda/                Citas, búsqueda y recordatorios
  asistente/             Conversación (streaming), configuración del proveedor, imágenes del chat
  datos/                 Importar y exportar
  configuracion/         Personal e invitaciones, textos editables, foto de la clínica y de perfil
  sincronizacion/        Receptor del servidor central, diario de cambios (central → local) y "Sincronizar ahora"
  notificaciones.php     Bandeja de notificaciones
  portadas.php           Portadas de secciones y páginas
  presencia.php          Quién está en cada sección (burbujas de perfil)
app/                     Código interno (bloqueado al navegador)
  nucleo/                Arranque, seguridad (sesión, cabeceras, límites, roles), utilidades
  servicios/
    clinica/             Agenda, resumen clínico derivado del historial, mapa por entidad
    cuenta/              Acceso, invitaciones y notificaciones
    espacio/             Páginas del espacio de trabajo y páginas ligadas a pacientes
    ia/                  Asistente (proveedores), herramientas, herramientas extra y web (búsqueda, imágenes)
    conversion/          Lectores y escritores por formato, mapeo de columnas y registro de conversiones
  vistas/
    plantilla/           Encabezado, pie, iconos y plantillas de acceso
    componentes/         Panel del asistente, dictado, tarjeta del paciente, detalle de registros clínicos
  datos/                 Catálogos (entidades, sexo, grupo sanguíneo, registros clínicos) y trazos del mapa
config/                  config.php (constantes) y db.php (conexión PDO)
database/                esquema.sql (todas las tablas) y usuario_aplicacion.sql (permisos MySQL)
docs/                    Investigación normativa (también la usa el asistente) y requisitos
assets/
  css/estilos.css        Estilos base; css/modulos/*.css se cargan solos en cada página
  js/                    app, espacio, gráficas, markdown, razonamiento; js/modulos/*.js se cargan solos
  img/                   Fondos y portadas
  subidas/               Foto de la clínica e imágenes de notas (estas solo se entregan con sesión)
  vendor/aurora/         Motor WebGL de la bolita del asistente
ia/                      Servicio local de voz (Whisper) y extractor de campos (PyTorch); bloqueado al navegador
privado/                 Llave de cifrado, credenciales de MySQL, fotos de pacientes, caché y registros (bloqueado)
vendor/                  SDK oficial de Anthropic (Composer; bloqueado)
```

## Puesta en marcha

1. Importar `database/esquema.sql` en MySQL (crea `expediente_femeci`).
2. Crear el usuario de la aplicación con `database/usuario_aplicacion.sql` y guardar sus credenciales en
   `privado/bd.php` (`return ['usuario' => 'femeci_app', 'clave' => '…'];`). Sin ese archivo la app usa root.
3. Abrir `http://localhost/ExpedienteFEMECI/`. La primera cuenta que se registra es la del administrador.
4. Correo: en desarrollo (`MAIL_MODO = 'archivo'`) los correos se guardan en `C:/xampp/mailoutput`.
   En producción, `MAIL_MODO = 'mail'` con SMTP configurado y `URL_ORIGEN` con el dominio real.
5. Voz: `ia\iniciar_servicio.bat` inicia Whisper y el extractor en `127.0.0.1:8765`.
6. Asistente: el administrador pega la API del proveedor en ⚙ (se guarda cifrada con AES-256-GCM).

## Sincronización con un servidor central

Cada consultorio trabaja con su propia base, también sin internet. Lo capturado se envía a un servidor
central (otra instalación de este mismo código) cuando hay conexión.

- Cada fila de pacientes, historial clínico, citas, páginas y filas de bases de datos tiene un `uuid` global.
- Triggers de MariaDB anotan cada alta, cambio y baja en `sincronizacion_cola` (ningún camino de escritura
  se escapa). Se generan con `php database/sincronizacion_triggers.php` desde `app/servicios/sincronizacion/tablas.php`.
- El navegador dispara el envío cada pocos minutos con conexión; el administrador puede forzarlo en
  ⚙ Configuración → Sincronización, donde también pone la dirección del central y el token compartido.
- Lotes firmados con HMAC-SHA256, marca de tiempo y nonce; solo se marca como enviado lo que el central
  confirma con un acuse firmado; los fallos se reintentan con espera creciente (30 s … 1 h).
- El central aplica cada lote en una transacción, idempotente por uuid; la última escritura gana y el
  historial clínico solo se agrega. Después el consultorio baja lo que cambiaron los demás.
- Cada token pertenece a una institución: el central solo escribe y entrega datos de esa institución.
- El token vive en `privado/sincronizacion.php`; el central lo configura igual, con la dirección vacía.

## Personal y permisos

- El administrador invita por correo con un rol (enlace de un solo uso, vence en 7 días).
- Sin invitación, la persona elige su rol y su cuenta espera la aprobación de un administrador.
- Roles: Médico (cédula obligatoria), Enfermería, Recepción y Administrador. La matriz de permisos
  está en `app/nucleo/seguridad.php` y se muestra en Personas y permisos.

## Seguridad

- Contraseñas con bcrypt; segundo factor de 10 dígitos por correo (guardado como hash, 5 intentos, 10 min).
- Límites de intentos por IP y por correo; mensajes que no revelan qué correos existen.
- Sesión con cookie HttpOnly/SameSite, regeneración periódica, caducidad por inactividad (30 min) y máxima (12 h).
- CSRF en todos los formularios y peticiones; CSP con nonce; X-Frame-Options; sin errores visibles.
- Consultas preparadas (PDO) en todo; comodines de LIKE escapados.
- Usuario de MySQL con permisos mínimos: la bitácora y el historial clínico solo admiten INSERT (inmutables).
- Bitácora de cada alta, cambio, consulta de expediente y envío de datos al asistente de IA.
- Subidas validadas por su contenido real, con nombre aleatorio y sin ejecución de PHP.

## Respaldos y trabajo sin conexión

- Respaldo diario automático de `expediente_femeci` en `privado/respaldos` (comprimido, con suma SHA-256,
  se conservan 14). Configuración → Respaldos: descargar, respaldar ahora, verificar y `CHECK TABLE`.
  Para programarlo con Windows: `mantenimiento/respaldo_diario.bat`. Detalle en `docs/respaldos.md`.
- MySQL con escritura segura ante apagones (`innodb_flush_log_at_trx_commit=1`, doublewrite) y
  escrituras de varias sentencias en transacciones; archivos importantes con escritura atómica.
- Funciona en la red local sin internet; las funciones que sí lo necesitan lo avisan. Service worker,
  indicador "Sin conexión", cola de cambios pendientes sin duplicados y borradores de formularios largos.
