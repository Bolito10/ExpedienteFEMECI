"""
Convierte un texto dictado en los campos del formulario de registro.

Uso:  python predecir.py "paciente juan pérez lópez alérgico a la penicilina acude por fiebre"
      echo "texto" | python predecir.py
Salida (JSON): {"nombre": "juan", "primer_apellido": "pérez", ...}

Los nombres de salida coinciden con las columnas de la tabla `pacientes`, para que el
transcriptor pueda rellenar el formulario directamente.
"""
import json
import os
import sys
from pathlib import Path

import torch

from modelo import ExtractorCampos, normalizar, tokenizar

# Etiqueta del modelo -> columna de la base de datos
COLUMNAS = {
    "NOMBRE": "nombre", "AP_PATERNO": "primer_apellido", "AP_MATERNO": "segundo_apellido",
    "FECHA_NAC": "fecha_nacimiento", "SEXO": "sexo", "CURP": "curp", "TELEFONO": "telefono",
    "CORREO": "correo", "DOMICILIO": "domicilio", "MUNICIPIO": "municipio_estado",
    "CONTACTO_EMERG": "contacto_emergencia", "TEL_EMERG": "telefono_emergencia",
    "ALERGIAS": "alergias", "GRUPO_SANG": "grupo_sanguineo", "PADECIMIENTOS": "padecimientos_cronicos",
    "MEDICAMENTOS": "medicamentos_actuales", "MOTIVO": "motivo_consulta",
}

# Nombres de pila comunes en México: sirven para decidir si "maría elena zúñiga" es nombre compuesto
NOMBRES_DE_PILA = set("""juan josé jose luis carlos miguel jorge francisco alejandro fernando ricardo eduardo roberto
javier daniel manuel antonio sergio raúl raul arturo héctor hector alberto rafael enrique gerardo óscar oscar pablo
mario andrés andres diego ángel angel rodrigo emilio iván ivan adrián adrian gustavo hugo martín martin salvador
ramón ramon víctor victor samuel tomás tomas ignacio esteban lorenzo julián julian matías matias pedro jesús jesus
maría maria guadalupe juana margarita verónica veronica patricia rosa elena laura ana gabriela sofía sofia fernanda
alejandra isabel leticia carmen teresa adriana claudia mónica monica sandra silvia diana paola andrea valeria lucía
lucia beatriz araceli norma yolanda brenda karla daniela ximena regina natalia renata camila victoria abril jimena
alondra luz paz eugenia cristina fernando antonieta dolores concepción concepcion del de la los""".split())

_guardado = torch.load(Path(__file__).parent / "modelos" / os.environ.get("FEMECI_EXTRACTOR", "extractor.pt"),
                       weights_only=False)
_hp = _guardado["hiperparametros"]
_v_pal, _v_let, _v_et = _guardado["v_pal"], _guardado["v_let"], _guardado["v_et"]
_inverso = {i: e for e, i in _v_et.items()}
_modelo = ExtractorCampos(len(_v_pal), len(_v_let), len(_v_et), _hp["dim_palabra"], _hp["dim_caracter"],
                          _hp["filtros_caracter"], _hp["oculto"], _hp["capas"], _hp["abandono"])
_modelo.load_state_dict(_guardado["pesos"])
_modelo.eval()


def unir(tokens):
    """Reconstruye el texto de un campo (sin espacio antes de signos)."""
    texto = ""
    for t in tokens:
        texto += t if (not texto or t in ",.;:/)") or texto.endswith(("/", "(")) else " " + t
    return texto


def extraer(texto):
    tokens = tokenizar(texto)
    if not tokens:
        return {}
    P = torch.tensor([[_v_pal.get(normalizar(t), 1) for t in tokens]])
    C = torch.zeros(1, len(tokens), _hp["max_letras"], dtype=torch.long)
    for j, t in enumerate(tokens):
        for k, c in enumerate(t[:_hp["max_letras"]]):
            C[0, j, k] = _v_let.get(c, 1)
    with torch.no_grad():
        etiquetas = [_inverso[int(i)] for i in _modelo(P, C).argmax(-1)[0]]

    campos, actual, piezas = {}, None, []
    for t, et in zip(tokens + [""], etiquetas + ["O"]):
        continua = et.startswith("I-") and actual == et[2:]
        if not continua and actual:
            columna = COLUMNAS[actual]
            campos.setdefault(columna, unir(piezas))   # se conserva la primera mención
            actual, piezas = None, []
        if et.startswith("B-") or (et.startswith("I-") and not continua):
            actual, piezas = et[2:], [t]
        elif continua:
            piezas.append(t)
    return repartir_nombre(campos)


def repartir_nombre(campos):
    """
    Reparte el nombre completo según la convención mexicana (nombre(s) + apellido paterno + materno).
    El modelo identifica bien DÓNDE está el nombre, pero con tres palabras no siempre sabe cuáles son
    nombres y cuáles apellidos; esta regla resuelve esa ambigüedad.
    """
    partes = [campos.get(c, "") for c in ("nombre", "primer_apellido", "segundo_apellido")]
    palabras = " ".join(p for p in partes if p).split()
    if len(palabras) < 2:
        return campos
    if len(palabras) == 2:
        nombre, paterno, materno = palabras[:1], palabras[1], None
    elif len(palabras) == 3:
        # "maría elena zúñiga": la palabra del medio es nombre de pila → nombre compuesto + un apellido
        if palabras[1].lower() in NOMBRES_DE_PILA and palabras[2].lower() not in NOMBRES_DE_PILA:
            nombre, paterno, materno = palabras[:2], palabras[2], None
        else:
            nombre, paterno, materno = palabras[:1], palabras[1], palabras[2]
    else:
        nombre, paterno, materno = palabras[:-2], palabras[-2], palabras[-1]

    campos = {k: v for k, v in campos.items() if k not in ("nombre", "primer_apellido", "segundo_apellido")}
    campos["nombre"] = " ".join(nombre)
    campos["primer_apellido"] = paterno
    if materno:
        campos["segundo_apellido"] = materno
    return campos


if __name__ == "__main__":
    entrada = " ".join(sys.argv[1:]) if len(sys.argv) > 1 else sys.stdin.read()
    print(json.dumps(extraer(entrada), ensure_ascii=False, indent=2))
