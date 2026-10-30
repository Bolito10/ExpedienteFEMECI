"""
Entrena el extractor de campos y lo evalúa con nombres nunca vistos.

Uso:     python entrenar.py
Salida:  modelos/extractor.pt (pesos + vocabularios), modelos/metricas.json, modelos/registro.txt
"""
import json
import random
import time
from collections import Counter
from pathlib import Path

import torch
import torch.nn as nn

from modelo import PAD, UNK, ExtractorCampos, normalizar

SEMILLA = 2026
HIPERPARAMETROS = {
    "epocas": 8,
    "lote": 32,
    "tasa_aprendizaje": 2e-3,
    "abandono": 0.3,
    "abandono_palabra": 0.1,     # sustituye palabras por <unk> al entrenar para generalizar a nombres nuevos
    "dim_palabra": 100,
    "dim_caracter": 30,
    "filtros_caracter": 50,
    "oculto": 128,
    "capas": 2,
    "max_letras": 20,
    "recorte_gradiente": 5.0,
}

RAIZ = Path(__file__).parent
DATOS, MODELOS = RAIZ / "datos", RAIZ / "modelos"


def leer(division):
    with open(DATOS / f"{division}.jsonl", encoding="utf-8") as f:
        return [json.loads(l) for l in f]


def vocabularios(ejemplos):
    palabras = Counter(normalizar(t) for e in ejemplos for t in e["tokens"])
    letras = Counter(c for e in ejemplos for t in e["tokens"] for c in t)
    v_pal = {PAD: 0, UNK: 1, **{p: i + 2 for i, p in enumerate(sorted(palabras))}}
    v_let = {PAD: 0, UNK: 1, **{c: i + 2 for i, c in enumerate(sorted(letras))}}
    etiquetas = sorted({et for e in ejemplos for et in e["etiquetas"]}, key=lambda x: (x != "O", x[2:], x))
    v_et = {e: i for i, e in enumerate(etiquetas)}
    return v_pal, v_let, v_et


def tensores(lote, v_pal, v_let, v_et, max_letras, abandono_palabra=0.0, rnd=None):
    largo = max(len(e["tokens"]) for e in lote)
    P = torch.zeros(len(lote), largo, dtype=torch.long)
    C = torch.zeros(len(lote), largo, max_letras, dtype=torch.long)
    Y = torch.full((len(lote), largo), -100, dtype=torch.long)     # -100 = relleno, no cuenta en la pérdida
    for i, e in enumerate(lote):
        for j, (t, et) in enumerate(zip(e["tokens"], e["etiquetas"])):
            idx = v_pal.get(normalizar(t), 1)
            if abandono_palabra and rnd.random() < abandono_palabra:
                idx = 1
            P[i, j] = idx
            for k, c in enumerate(t[:max_letras]):
                C[i, j, k] = v_let.get(c, 1)
            Y[i, j] = v_et[et]
    return P, C, Y


def entidades(etiquetas):
    """Convierte etiquetas BIO en conjunto de (campo, inicio, fin)."""
    resultado, actual = set(), None
    for i, et in enumerate(etiquetas + ["O"]):
        if et.startswith("B-") or et == "O" or (et.startswith("I-") and (not actual or actual[0] != et[2:])):
            if actual:
                resultado.add((actual[0], actual[1], i))
            actual = (et[2:], i) if et != "O" else None
    return resultado


def evaluar(modelo, ejemplos, v_pal, v_let, v_et, hp):
    inverso = {i: e for e, i in v_et.items()}
    modelo.eval()
    aciertos = total = 0
    por_campo = {}
    with torch.no_grad():
        for i in range(0, len(ejemplos), 64):
            lote = ejemplos[i:i + 64]
            P, C, Y = tensores(lote, v_pal, v_let, v_et, hp["max_letras"])
            pred = modelo(P, C).argmax(-1)
            for k, e in enumerate(lote):
                n = len(e["tokens"])
                p = [inverso[int(x)] for x in pred[k, :n]]
                aciertos += sum(a == b for a, b in zip(p, e["etiquetas"]))
                total += n
                real, predicho = entidades(e["etiquetas"]), entidades(p)
                for campo in {c for c, _, _ in real | predicho}:
                    d = por_campo.setdefault(campo, {"vp": 0, "fp": 0, "fn": 0})
                    r_c = {x for x in real if x[0] == campo}
                    p_c = {x for x in predicho if x[0] == campo}
                    d["vp"] += len(r_c & p_c); d["fp"] += len(p_c - r_c); d["fn"] += len(r_c - p_c)
    vp = sum(d["vp"] for d in por_campo.values())
    fp = sum(d["fp"] for d in por_campo.values())
    fn = sum(d["fn"] for d in por_campo.values())
    f1 = lambda a, b, c: 2 * a / (2 * a + b + c) if a else 0.0
    return {
        "exactitud_tokens": aciertos / total,
        "f1_entidades": f1(vp, fp, fn),
        "precision": vp / (vp + fp) if vp + fp else 0.0,
        "exhaustividad": vp / (vp + fn) if vp + fn else 0.0,
        "f1_por_campo": {c: round(f1(d["vp"], d["fp"], d["fn"]), 4) for c, d in sorted(por_campo.items())},
    }


def main():
    random.seed(SEMILLA); torch.manual_seed(SEMILLA)
    rnd = random.Random(SEMILLA)
    hp = HIPERPARAMETROS
    entrenamiento, validacion, prueba = leer("entrenamiento"), leer("validacion"), leer("prueba")
    v_pal, v_let, v_et = vocabularios(entrenamiento)

    modelo = ExtractorCampos(len(v_pal), len(v_let), len(v_et),
                             hp["dim_palabra"], hp["dim_caracter"], hp["filtros_caracter"],
                             hp["oculto"], hp["capas"], hp["abandono"])
    parametros = sum(p.numel() for p in modelo.parameters())
    optimizador = torch.optim.Adam(modelo.parameters(), lr=hp["tasa_aprendizaje"])
    perdida_fn = nn.CrossEntropyLoss(ignore_index=-100)

    MODELOS.mkdir(exist_ok=True)
    registro = open(MODELOS / "registro.txt", "w", encoding="utf-8")
    def log(texto):
        print(texto, flush=True); registro.write(texto + "\n"); registro.flush()

    log(f"Vocabulario: {len(v_pal)} palabras, {len(v_let)} caracteres, {len(v_et)} etiquetas")
    log(f"Parámetros entrenables: {parametros:,}")
    log(f"Ejemplos: {len(entrenamiento)} entrenamiento / {len(validacion)} validación / {len(prueba)} prueba")

    historial, mejor_f1, inicio = [], -1.0, time.time()
    for epoca in range(1, hp["epocas"] + 1):
        modelo.train()
        rnd.shuffle(entrenamiento)
        suma, lotes = 0.0, 0
        for i in range(0, len(entrenamiento), hp["lote"]):
            P, C, Y = tensores(entrenamiento[i:i + hp["lote"]], v_pal, v_let, v_et,
                               hp["max_letras"], hp["abandono_palabra"], rnd)
            optimizador.zero_grad()
            salida = modelo(P, C)
            perdida = perdida_fn(salida.view(-1, salida.size(-1)), Y.view(-1))
            perdida.backward()
            nn.utils.clip_grad_norm_(modelo.parameters(), hp["recorte_gradiente"])
            optimizador.step()
            suma += perdida.item(); lotes += 1
        val = evaluar(modelo, validacion, v_pal, v_let, v_et, hp)
        historial.append({"epoca": epoca, "perdida": round(suma / lotes, 4),
                          "f1_validacion": round(val["f1_entidades"], 4)})
        log(f"Época {epoca:2d}  pérdida {suma / lotes:.4f}  F1 validación {val['f1_entidades']:.4f}  "
            f"exactitud tokens {val['exactitud_tokens']:.4f}  ({time.time() - inicio:.0f} s)")
        if val["f1_entidades"] > mejor_f1:
            mejor_f1 = val["f1_entidades"]
            torch.save({"pesos": modelo.state_dict(), "v_pal": v_pal, "v_let": v_let, "v_et": v_et,
                        "hiperparametros": hp}, MODELOS / "extractor.pt")

    # Evaluación final con el mejor modelo, sobre nombres que nunca vio
    guardado = torch.load(MODELOS / "extractor.pt", weights_only=False)
    modelo.load_state_dict(guardado["pesos"])
    final = evaluar(modelo, prueba, v_pal, v_let, v_et, hp)
    log(f"PRUEBA (nombres no vistos): F1 entidades {final['f1_entidades']:.4f}  "
        f"precisión {final['precision']:.4f}  exhaustividad {final['exhaustividad']:.4f}  "
        f"exactitud tokens {final['exactitud_tokens']:.4f}")
    for campo, f1 in final["f1_por_campo"].items():
        log(f"  {campo:<15} F1 {f1:.4f}")

    with open(MODELOS / "metricas.json", "w", encoding="utf-8") as f:
        json.dump({"parametros": parametros, "hiperparametros": hp, "historial": historial,
                   "prueba": final, "segundos": round(time.time() - inicio)}, f, ensure_ascii=False, indent=2)
    registro.close()


if __name__ == "__main__":
    main()
