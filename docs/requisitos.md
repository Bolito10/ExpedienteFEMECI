# Recopilación de lo solicitado

Estado de cada petición hecha durante el desarrollo. ✅ hecho · ⏳ pendiente · 👤 requiere una acción tuya.

## Reglas generales
- ✅ Una sola base de datos MySQL (`expediente_femeci`).
- ✅ Estilo minimalista oscuro de las referencias; sin tarjetas con sombra, degradados, insignias ni emojis en la interfaz.
- ✅ Sin datos falsos: estados vacíos reales; se quitó el proveedor de IA simulado.
- ✅ Notificaciones y componentes con el estilo de las referencias; animaciones sutiles.
- ✅ Tipografía de la app de Notion; pestañas y ruta a 13 px como la barra lateral; textos informativos pequeños agrandados.
- ✅ Nombre "Expedientes Clínicos"; sin el logo de la "E".

## Acceso, registro y permisos
- ✅ Inicio de sesión en tres pasos: correo → contraseña → código de 10 dígitos (5–5) que llega por correo.
- ✅ Invitaciones por correo con rol (enlace de un solo uso, 7 días).
- ✅ Registro en tres pasos con el diseño de las referencias (01 / 03): datos y foto de perfil, rol (cédula obligatoria para médico), contraseña y permisos del rol.
- ✅ Personas y permisos: aprobar, rechazar, cambiar rol, desactivar, reenviar/cancelar invitaciones, matriz de permisos.
- 👤 Correo real: configurar SMTP (`MAIL_MODO = 'mail'`) y `URL_ORIGEN` con el dominio. Hoy los correos se guardan en `C:/xampp/mailoutput`.
- 👤 Aviso de privacidad (LFPDPPP): falta el texto legal del establecimiento.
- ⏳ "Continuar con Google": aparece solo cuando haya credenciales OAuth (`GOOGLE_CLIENT_ID`).

## Barra lateral
- ✅ Estilo Arc plano con el contenido como tarjeta redondeada; secciones plegables (Favoritos, Expediente, Privado, Compartido, Pestañas abiertas) sin huecos al plegar.
- ✅ Foto de la clínica arriba, antes del buscador y de Inicio (la cambia el administrador).
- ✅ Buscador "Buscar o preguntar Ctrl+K", Inicio y acceso al asistente.
- ✅ Árbol carpeta → carpeta → nota o base de datos, arrastrar y soltar, menú "…" y "+".
- ✅ Historial, cerradas recientemente y papelera.
- ✅ Registrar paciente ya no está en la barra lateral: se hace desde la base de datos con "Nuevo".

## Pacientes
- ✅ Base de datos editable como Notion con todas las columnas del formulario de registro, agrupadas por sección.
- ✅ Botón azul "Nuevo" para registrar; lupa con paleta de búsqueda.
- ✅ Mapa de México con estados reales detrás del botón "i" (se despliega al pulsarlo).
- ✅ Perfil: propiedades, mini mapa, mapa de calor de actividad, gráficas pequeñas (barras y línea/área), historial clínico desplegable y barra de acciones que se expande.
- ✅ Dictado por voz local (Whisper) y modelo PyTorch que llena el formulario.

## Espacio de trabajo (notas)
- ✅ Notas con editor por bloques, comandos "/", atajos de Markdown, plantillas NOM-004, anotador con IA, importar texto.
- ✅ Bases de datos con propiedades tipadas y opciones de colores.
- ✅ Compartir (invitar, niveles de acceso, acceso general, copiar enlace), actividad al pasar el ratón y panel de Notificaciones/Estadísticas.
- ✅ Burbujas de perfil con "··· +N".
- ✅ Portadas: agregar, cambiar (galería o subir imagen) y quitar en notas y en toda sección con portada.
- 👤 Imágenes de banners: guardarlas en `assets/img/banners/` (aparecen en el registro y en la galería de portadas).
- ⏳ "Formulario" (creador de formularios de Notion) y "Reposicionar" la portada: no se construyeron.

## Asistente de IA
- ✅ Panel lateral y página completa con el diseño de las referencias y las mismas sugerencias en ambos.
- ✅ Razonamiento visible, respuestas en Markdown seguro con espacio entre párrafos.
- ✅ Herramientas (tool calling): crear notas, carpetas y bases de datos; escribir y editar notas; agregar columnas y filas; renombrar; con acceso al expediente también buscar y leer páginas y buscar pacientes. Todo con los permisos del usuario y en la bitácora.
- 👤 Pegar la API real (p. ej. OpenRouter) en ⚙ Configuración; sin ella el asistente no responde (no hay respuestas simuladas).

## Seguridad
- ✅ Usuario de MySQL con permisos mínimos; bitácora e historial inmutables.
- ✅ CSRF, CSP con nonce, sesión endurecida, límites de intentos, sin enumeración de cuentas, subidas validadas, carpetas internas bloqueadas.
- ✅ Auditoría de seguridad y correcciones: mover/eliminar páginas, ciclos, roles por endpoint, bloqueo de cuentas, carreras en el registro.
- 👤 Producción: contraseña de root de MySQL/phpMyAdmin, HTTPS y `ServerTokens Prod` en Apache.
