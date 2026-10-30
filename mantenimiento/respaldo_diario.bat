@echo off
rem Respaldo diario de la base expediente_femeci para el Programador de tareas de Windows.
rem Si la app ya hizo el respaldo de hoy, no repite. Instrucciones: docs\respaldos.md
rem Deja constancia de cada ejecucion en privado\registros\respaldos.log
cd /d "%~dp0"
set RESPALDO_MOTIVO=programado
"C:\xampp\php\php.exe" -f "%~dp0respaldar.php" >> "%~dp0..\privado\registros\respaldos.log" 2>&1
exit /b %ERRORLEVEL%
