"""
Servicio local de IA del expediente: voz → texto (Whisper) → campos del formulario (extractor PyTorch).

Corre SOLO en 127.0.0.1: ningún dato sale del equipo del establecimiento. PHP le reenvía el audio
grabado en el navegador junto con un token secreto (ia/token.txt, inaccesible desde la web).

Uso:     .venv\\Scripts\\python servicio.py         (o iniciar_servicio.bat)
Rutas:   GET  /estado       → {"listo": true, "modelo": "small", ...}
         POST /transcribir  → cuerpo: audio (webm/ogg/wav); cabecera X-Token
                              respuesta: {"texto": "...", "campos": {...}, "segundos": 3.2}
"""
import json
import os
import secrets
import sys
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from faster_whisper import WhisperModel

from predecir import extraer

# La consola de Windows puede no usar UTF-8: sin esto, el registro con «→» tumba cada petición
for _flujo in (sys.stdout, sys.stderr):
    if hasattr(_flujo, "reconfigure"):
        _flujo.reconfigure(encoding="utf-8", errors="replace")

RAIZ = Path(__file__).parent
HOST, PUERTO = "127.0.0.1", 8765
MODELO_WHISPER = os.environ.get("FEMECI_WHISPER", "small")   # tiny | base | small | medium
MAX_BYTES = 25 * 1024 * 1024
# Orienta a Whisper hacia vocabulario clínico en español de México
PROMPT_INICIAL = ("Dictado clínico en español de México. Paciente, CURP, fecha de nacimiento, teléfono, "
                  "alergias, padecimientos crónicos, medicamentos, tipo de sangre, motivo de consulta. "
                  "Toma losartán, metformina, enalapril, amlodipino, omeprazol, paracetamol, levotiroxina, "
                  "insulina, salbutamol, atorvastatina. Hipertensión arterial, diabetes mellitus.")

# Token compartido con PHP (se crea la primera vez)
ARCHIVO_TOKEN = RAIZ / "token.txt"
if not ARCHIVO_TOKEN.exists():
    ARCHIVO_TOKEN.write_text(secrets.token_hex(32), encoding="utf-8")
TOKEN = ARCHIVO_TOKEN.read_text(encoding="utf-8").strip()

print(f"Cargando Whisper «{MODELO_WHISPER}» (la primera vez se descarga el modelo)…", flush=True)
inicio = time.time()
whisper = WhisperModel(MODELO_WHISPER, device="cpu", compute_type="int8", cpu_threads=os.cpu_count() or 4)
print(f"Whisper listo en {time.time() - inicio:.0f} s", flush=True)
candado = threading.Lock()   # una transcripción a la vez: el modelo usa todos los núcleos


def transcribir(ruta):
    segmentos, info = whisper.transcribe(
        ruta, language="es", beam_size=1, vad_filter=True,
        initial_prompt=PROMPT_INICIAL, condition_on_previous_text=False,
    )
    texto = " ".join(s.text.strip() for s in segmentos).strip()
    return texto, info.duration


class Manejador(BaseHTTPRequestHandler):
    server_version = "FemeciIA/1.0"

    def responder(self, codigo, datos):
        cuerpo = json.dumps(datos, ensure_ascii=False).encode("utf-8")
        self.send_response(codigo)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(cuerpo)))
        self.end_headers()
        self.wfile.write(cuerpo)

    def do_GET(self):
        if self.path == "/estado":
            self.responder(200, {"listo": True, "modelo": MODELO_WHISPER, "extractor": "BiLSTM v3 + regla de nombres"})
        else:
            self.responder(404, {"error": "Ruta no encontrada"})

    def do_POST(self):
        if self.path != "/transcribir":
            return self.responder(404, {"error": "Ruta no encontrada"})
        if not secrets.compare_digest(self.headers.get("X-Token", ""), TOKEN):
            return self.responder(403, {"error": "Token inválido"})
        largo = int(self.headers.get("Content-Length") or 0)
        if not 0 < largo <= MAX_BYTES:
            return self.responder(413, {"error": "Audio vacío o demasiado grande"})

        audio = self.rfile.read(largo)
        sufijo = {"audio/webm": ".webm", "audio/ogg": ".ogg", "audio/wav": ".wav", "audio/x-wav": ".wav",
                  "audio/mp4": ".m4a", "audio/mpeg": ".mp3"}.get(self.headers.get("Content-Type", "").split(";")[0], ".webm")
        archivo = tempfile.NamedTemporaryFile(delete=False, suffix=sufijo)
        try:
            archivo.write(audio)
            archivo.close()
            t0 = time.time()
            with candado:
                texto, duracion = transcribir(archivo.name)
            campos = extraer(texto) if texto else {}
            self.responder(200, {"texto": texto, "campos": campos, "duracion_audio": round(duracion, 1),
                                 "segundos": round(time.time() - t0, 1)})
        except Exception as error:  # audio corrupto o formato no soportado
            self.responder(422, {"error": f"No se pudo procesar el audio: {type(error).__name__}"})
        finally:
            os.unlink(archivo.name)   # el audio no se conserva

    def log_message(self, formato, *args):
        # Solo método, ruta y código: nunca contenido clínico
        print(f"{self.log_date_time_string()} {self.command} {self.path} → {args[1] if len(args) > 1 else ''}", flush=True)


if __name__ == "__main__":
    servidor = ThreadingHTTPServer((HOST, PUERTO), Manejador)
    print(f"Servicio de IA escuchando en http://{HOST}:{PUERTO} (solo este equipo)", flush=True)
    servidor.serve_forever()
