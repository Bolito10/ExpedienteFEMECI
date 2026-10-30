# Respaldos, durabilidad y trabajo sin conexión

## Respaldos automáticos

- **Qué se respalda:** la base `expediente_femeci` completa (todas las tablas, sin `CREATE DATABASE`), con
  `C:/xampp/mysql/bin/mysqldump --single-transaction` (no bloquea a quien está capturando).
- **Dónde:** `privado/respaldos/respaldo-AAAAMMDD-HHMMSS-motivo.sql.gz` y, al lado, su suma
  `…sql.gz.sha256` (formato de `sha256sum -c`). `privado/` está bloqueado a la web.
- **Cuántos:** se conservan los 14 más recientes (`RESPALDOS_CONSERVAR` en `app/servicios/sistema/respaldos.php`).
- **Cuándo:** la primera petición con sesión de cada día lanza `mantenimiento/respaldar.php` en segundo plano
  (no hace esperar a nadie). Un candado (`flock` sobre `privado/respaldos/.candado`) evita respaldos
  simultáneos y `privado/respaldos/ultimo.json` evita repetirlo el mismo día. Si falla, se reintenta a la hora.
- **Desde la app:** Configuración → Respaldos (solo administración): lista, descargar, *Respaldar ahora*,
  *Verificar archivo* (suma SHA-256 y volcado completo) y *Verificar integridad* (`CHECK TABLE` de todas las tablas).
  Con varias instituciones, solo las cuentas de `ADMINISTRADORES_SERVIDOR` (`config/config.php`) ven esta
  sección, porque un respaldo trae los datos de todas.

### Respaldo programado con Windows (opcional, recomendado)

El respaldo perezoso depende de que alguien abra la app ese día. Para tenerlo aunque nadie entre, programa
`mantenimiento/respaldo_diario.bat` (no repite si la app ya respaldó ese día; anota cada ejecución en
`privado/registros/respaldos.log`):

1. Abre **Programador de tareas** → *Crear tarea básica…*
2. Nombre: `Expedientes Clínicos - respaldo diario`. Desencadenador: *Diariamente*, por ejemplo 23:30.
3. Acción: *Iniciar un programa* → `C:\xampp\htdocs\ExpedienteFEMECI\mantenimiento\respaldo_diario.bat`.
4. En *Propiedades*: marca *Ejecutar tanto si el usuario inició sesión como si no* y
   *Ejecutar la tarea lo antes posible si no se pudo a la hora programada*.

O desde una consola de administrador:

```
schtasks /Create /TN "Expedientes Clinicos - respaldo diario" /TR "C:\xampp\htdocs\ExpedienteFEMECI\mantenimiento\respaldo_diario.bat" /SC DAILY /ST 23:30
```

MySQL debe estar encendido a esa hora. Para forzar un respaldo a mano: `C:\xampp\php\php.exe mantenimiento\respaldar.php --forzar`.

### Restaurar

Es destructivo: reemplaza toda la base por la del respaldo. Solo el administrador, escribiendo `RESTAURAR`,
y siempre se hace antes un respaldo automático del estado actual (`…-antes_de_restaurar.sql.gz`).

La app normal (`femeci_app`) no puede borrar ni crear tablas, así que restaurar usa un usuario aparte. Para
activarlo, como root (una sola vez), ejecuta la sección *Respaldos* de `database/usuario_aplicacion.sql` con
una contraseña propia y guárdala en `privado/respaldo_bd.php`:

```php
<?php return ['usuario' => 'femeci_respaldo', 'clave' => 'LA_CONTRASEÑA'];
```

Mientras no exista, la opción *Restaurar…* lo indica en su menú. Con ese usuario los respaldos también
incluyen los triggers de la cola de sincronización; con el de la app no se pueden leer, así que después de
restaurar se regeneran con `database/sincronizacion_triggers.php`. Restaurar regresa la base local a un punto
anterior: revisa después la sincronización con el servidor central. Restauración manual equivalente:

```
C:\xampp\mysql\bin\mysql -u root expediente_femeci < respaldo.sql   (después de descomprimir el .gz)
```

## Durabilidad de MySQL (`C:/xampp/mysql/bin/my.ini`)

Respaldo del original: `my.ini.respaldo-20261030`. Valores en `[mysqld]`:

| Opción | Valor | Para qué |
| --- | --- | --- |
| `innodb_flush_log_at_trx_commit` | 1 | cada COMMIT llega al disco (ya estaba) |
| `innodb_doublewrite` | 1 | evita páginas a medias si se va la luz |
| `innodb_file_per_table` | 1 | un archivo por tabla |
| `innodb_checksum_algorithm` | crc32 | detecta páginas dañadas |
| `sync_binlog` | 1 | solo si algún día se activa `log_bin` |
| `max_allowed_packet` | 16M | notas grandes y restauración (antes 1M) |

Se aplican al **reiniciar MySQL** desde el panel de XAMPP (detener e iniciar, con la app sin uso).

## Archivos

`escribirArchivoAtomico()` (`app/nucleo/funciones.php`) escribe a un temporal en la misma carpeta, hace
`fsync` y lo renombra: tras un apagón queda el archivo anterior o el nuevo, nunca uno truncado. Lo usan la
llave de cifrado, las fotos subidas (`guardarSubida()`), portadas, imágenes de notas, cachés de `privado/`,
la importación de datos y los respaldos.

## Sin conexión

- Todas las páginas usan solo recursos locales (sin CDNs ni fuentes externas; la CSP lo impone).
  El asistente en la nube, la búsqueda en internet y las imágenes remotas avisan "Sin conexión a internet".
- `sw.js` (alcance: toda la app) guarda CSS, JS e imágenes de `assets/`; nunca páginas, `/api/` ni
  `assets/subidas`. Si el servidor no responde, muestra `assets/sin_conexion.html`. Necesita contexto seguro:
  funciona en `http://localhost`; en otros equipos de la red, solo con HTTPS.
- Indicador "Sin conexión" en la barra superior, con los cambios pendientes.
- Cada POST JSON a `/api/` lleva `X-Idempotencia`; el servidor guarda la respuesta 7 días
  (`peticiones_idempotentes`) y no repite una escritura. Los cambios puntuales (campo de paciente, celda de
  base de datos, título, favorito, mover o cambiar estado de una cita, notificaciones) se guardan en IndexedDB
  si la red falla y se envían al volver, con la sesión y el token de ese momento. Lo demás avisa que no se guardó.
- Notas: se guardan solas en el servidor; sin conexión quedan en este equipo y se envían al volver.
- Formularios largos (`form[data-borrador]`: registro de paciente y registro clínico) guardan un borrador
  local mientras se escribe y lo restauran al volver; se borra al guardar con éxito. Además, cada envío lleva
  `clave_envio` para que un doble clic o un reenvío no duplique al paciente ni la entrada del historial.
