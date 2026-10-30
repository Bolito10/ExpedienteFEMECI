@echo off
rem Inicia el servicio local de IA del expediente (Whisper + extractor de campos).
rem Debe quedar abierto mientras se use el dictado por voz.
cd /d "%~dp0"
".venv\Scripts\python.exe" -W ignore servicio.py
pause
