"""
Genera el corpus de ENTRENAMIENTO del extractor de campos (texto sintético).

Por qué sintético: no existen dictados clínicos reales disponibles y los reales serían datos
sensibles (LFPDPPP). Estas frases se arman con plantillas y catálogos solo para enseñar al modelo
la estructura del dictado; nunca se guardan en la base de datos del expediente ni se muestran
como pacientes.

Versión 2: más formas de presentar al paciente (con y sin palabra disparadora), artículos
("alérgica a la…"), conectores entre cláusulas, motivos compuestos (síntoma + tiempo),
domicilios con avenida/comas y el estilo que entrega Whisper (mayúsculas y puntuación).

Cada ejemplo es una lista de tokens con su etiqueta BIO (B-CAMPO, I-CAMPO u O).
Para medir si el modelo generaliza, los nombres/apellidos de PRUEBA no aparecen en entrenamiento.

Uso:  python generar_datos.py
Salida: datos/entrenamiento.jsonl, datos/validacion.jsonl, datos/prueba.jsonl
"""
import json
import random
import re
from pathlib import Path

SEMILLA = 2026
CANTIDAD = {"entrenamiento": 12000, "validacion": 1000, "prueba": 1000}
CARPETA = Path(__file__).parent / "datos"

CAMPOS = [
    "NOMBRE", "AP_PATERNO", "AP_MATERNO", "FECHA_NAC", "SEXO", "CURP", "TELEFONO", "CORREO",
    "DOMICILIO", "MUNICIPIO", "CONTACTO_EMERG", "TEL_EMERG", "ALERGIAS", "GRUPO_SANG",
    "PADECIMIENTOS", "MEDICAMENTOS", "MOTIVO",
]

# ---------- Catálogos (nombres y apellidos se dividen en grupos disjuntos: entrenamiento / prueba) ----------
NOMBRES = """juan josé luis carlos miguel jorge francisco alejandro fernando ricardo eduardo roberto
javier daniel manuel antonio sergio raúl arturo héctor alberto rafael enrique gerardo óscar pablo
mario andrés diego ángel rodrigo emilio iván adrián gustavo hugo martín salvador ramón víctor
maría guadalupe juana margarita verónica patricia rosa elena laura ana gabriela sofía fernanda
alejandra isabel leticia carmen teresa adriana claudia mónica sandra silvia diana paola andrea
valeria lucía beatriz araceli norma yolanda brenda karla daniela ximena regina natalia samuel
tomás ignacio esteban lorenzo julián matías renata camila victoria abril jimena alondra""".split()

APELLIDOS = """hernández garcía martínez lópez gonzález pérez rodríguez sánchez ramírez cruz flores gómez
morales vázquez jiménez reyes díaz torres gutiérrez ruiz mendoza aguilar ortiz moreno castillo
romero álvarez méndez chávez rivera juárez ramos domínguez herrera medina castro vargas guzmán
velázquez muñoz rojas contreras salazar luna ortega santiago guerrero estrada bautista cortés
soto alvarado espinoza lara ávila ríos cervantes silva delgado vega márquez sandoval carrillo
león mejía solís navarro robles campos cabrera montiel peña aranda beltrán ibarra cordero
fuentes acosta nava zamora orozco valencia trejo miranda pacheco villa rangel serrano""".split()

MESES = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto",
         "septiembre", "octubre", "noviembre", "diciembre"]
ENTIDADES_CURP = ["AS", "BC", "BS", "CC", "CL", "CM", "CS", "CH", "DF", "DG", "GT", "GR", "HG", "JC",
                  "MC", "MN", "MS", "NT", "NL", "OC", "PL", "QT", "QR", "SP", "SL", "SR", "TC", "TS",
                  "TL", "VZ", "YN", "ZS"]
MUNICIPIOS = [("cuernavaca", "morelos"), ("jiutepec", "morelos"), ("cuautla", "morelos"),
              ("puebla", "puebla"), ("cholula", "puebla"), ("toluca", "estado de méxico"),
              ("naucalpan", "estado de méxico"), ("guadalajara", "jalisco"), ("zapopan", "jalisco"),
              ("monterrey", "nuevo león"), ("san nicolás", "nuevo león"), ("mérida", "yucatán"),
              ("querétaro", "querétaro"), ("león", "guanajuato"), ("irapuato", "guanajuato"),
              ("oaxaca de juárez", "oaxaca"), ("xalapa", "veracruz"), ("veracruz", "veracruz"),
              ("iztapalapa", "ciudad de méxico"), ("coyoacán", "ciudad de méxico"), ("tlalpan", "ciudad de méxico"),
              ("pachuca", "hidalgo"), ("morelia", "michoacán"), ("tijuana", "baja california"),
              ("hermosillo", "sonora"), ("culiacán", "sinaloa"), ("chihuahua", "chihuahua"),
              ("saltillo", "coahuila"), ("aguascalientes", "aguascalientes"), ("tuxtla gutiérrez", "chiapas")]
TIPOS_VIA = ["calle", "avenida", "privada", "andador", "calzada", "boulevard"]
CALLES = ["hidalgo", "morelos", "juárez", "reforma", "insurgentes", "madero", "zaragoza", "allende",
          "independencia", "benito juárez", "emiliano zapata", "álvaro obregón", "las flores", "del sol",
          "revolución", "constitución", "cinco de mayo", "nogal", "los pinos", "vicente guerrero"]
COLONIAS = ["centro", "la paz", "san juan", "las palmas", "jardines", "vista hermosa", "el carmen",
            "santa cruz", "lomas", "del valle", "san miguel", "la esperanza", "las águilas", "bellavista",
            "el mirador", "los álamos", "san rafael", "providencia"]
PARENTESCOS_F = ["esposa", "hija", "madre", "hermana", "tía", "abuela"]
PARENTESCOS_M = ["esposo", "hijo", "padre", "hermano", "tío", "abuelo"]
GRUPOS = ["o positivo", "o negativo", "a positivo", "a negativo", "b positivo", "b negativo",
          "ab positivo", "ab negativo", "o+", "a+", "b-", "ab+"]
# Alergias con su artículo natural (el artículo NO forma parte del campo)
ALERGIAS = [("la", "penicilina"), ("las", "sulfas"), ("el", "metamizol"), ("la", "aspirina"),
            ("el", "ibuprofeno"), ("el", "látex"), ("los", "mariscos"), ("el", "polen"), ("la", "nuez"),
            ("la", "amoxicilina"), ("los", "antiinflamatorios no esteroideos"), ("el", "yodo"),
            ("el", "naproxeno"), ("la", "cefalexina"), ("el", "huevo"), ("los", "cacahuates"),
            ("el", "diclofenaco"), ("la", "lactosa")]
PADECIMIENTOS = ["diabetes", "diabetes mellitus tipo 2", "hipertensión arterial", "hipertensión", "asma",
                 "hipotiroidismo", "enfermedad renal crónica", "insuficiencia cardiaca", "epilepsia",
                 "artritis reumatoide", "migraña", "dislipidemia", "obesidad", "gastritis crónica",
                 "depresión", "ansiedad", "colitis", "cardiopatía isquémica", "cáncer de mama", "vih"]
MEDICAMENTOS = ["metformina", "metformina de 850 miligramos", "losartán", "losartán de 50 miligramos",
                "levotiroxina", "salbutamol", "enalapril", "omeprazol", "atorvastatina", "insulina glargina",
                "paracetamol", "carvedilol", "furosemida", "sertralina", "ácido valproico", "glibenclamida",
                "amlodipino", "aspirina protect", "fluoxetina", "prednisona", "clonazepam", "pantoprazol"]
SINTOMAS = ["dolor de cabeza", "dolor abdominal", "dolor de oído", "dolor lumbar", "fiebre", "tos",
            "tos seca", "mareo", "dolor de garganta", "ardor al orinar", "dolor en el pecho", "náuseas",
            "vómito", "diarrea", "erupción en la piel", "dificultad para respirar", "dolor de rodilla",
            "dolor de muelas", "comezón en los ojos", "sangrado nasal", "dolor de espalda", "cansancio"]
DURACIONES = ["desde ayer", "desde hace dos días", "desde hace tres días", "desde hace una semana",
              "desde hace un mes", "desde el lunes", "desde el fin de semana", "de tres días de evolución",
              "de una semana de evolución", "desde la mañana", "desde anoche"]
OTROS_MOTIVOS = ["control de presión arterial", "revisión de laboratorios", "seguimiento de diabetes",
                 "control prenatal", "chequeo general", "renovar su receta", "certificado médico",
                 "revisión de una herida", "aplicación de vacuna"]
DOMINIOS = ["gmail.com", "hotmail.com", "outlook.com", "yahoo.com.mx", "correo.mx", "icloud.com"]
MULETILLAS = ["eh", "este", "bueno", "a ver", "mmm", "ok", "muy bien"]
CONECTORES = ["y", "además", "también", "aparte"]


def telefono(r):
    # Formatos que entrega Whisper: agrupado, en un solo bloque, por pares o con guiones
    d = f"{r.randint(22, 99)}{r.randint(1000, 9999)}{r.randint(1000, 9999)}"
    return r.choice([f"{d[:2]} {d[2:6]} {d[6:]}", f"{d[:2]} {d[2:6]} {d[6:]}", d,
                     f"{d[:5]} {d[5:7]} {d[7:]}", " ".join(d[i:i + 2] for i in range(0, 10, 2)),
                     f"{d[:2]}-{d[2:6]}-{d[6:]}", f"{d[:3]} {d[3:6]} {d[6:]}"])


def fecha(r):
    d, m, a = r.randint(1, 28), r.randint(1, 12), r.randint(1940, 2024)
    return r.choice([f"{d} de {MESES[m - 1]} de {a}", f"{d:02d}/{m:02d}/{a}", f"{d} {MESES[m - 1]} {a}",
                     f"{d}/{m}/{a}"])


def curp(r, nombre, ap, am, femenino):
    letras = "ABCDEFGHIJKLMNOPQRSTUVWXYZ"
    quitar = str.maketrans("ÁÉÍÓÚÑ", "AEIOUX")
    base = (ap[0] + r.choice("AEIOU") + (am[0] if am else "X") + nombre[0]).upper().translate(quitar)
    fecha_c = f"{r.randint(40, 99):02d}{r.randint(1, 12):02d}{r.randint(1, 28):02d}"
    return (base + fecha_c + ("M" if femenino else "H") + r.choice(ENTIDADES_CURP)
            + "".join(r.choice(letras) for _ in range(3)) + r.choice(letras) + str(r.randint(0, 9)))


def motivo(r):
    if r.random() < 0.25:
        return r.choice(OTROS_MOTIVOS)
    s = r.choice(SINTOMAS)
    if s.startswith("dolor") and r.random() < 0.3:
        s = "un " + s
    if s == "fiebre" and r.random() < 0.4:
        s += f" de {r.randint(37, 40)} grados"
    return s + (" " + r.choice(DURACIONES) if r.random() < 0.6 else "")


def lista(r, catalogo, prob=0.3):
    texto = r.choice(catalogo)
    return texto + (" y " + r.choice(catalogo) if r.random() < prob else "")


# ---------- Cláusulas: cada una es una lista de (texto, campo|None) ----------
def clausulas(r, nombres, apellidos):
    femenino = r.random() < 0.5
    n = r.choice(nombres) + (" " + r.choice(nombres) if r.random() < 0.2 else "")
    ap, am = r.choice(apellidos), r.choice(apellidos)
    usa_am = r.random() < 0.85      # en México lo habitual es nombre + dos apellidos
    persona = [(n, "NOMBRE"), (ap, "AP_PATERNO")] + ([(am, "AP_MATERNO")] if usa_am else [])

    articulo = "la" if femenino else "el"
    presentaciones = [
        "paciente", f"{articulo} paciente", "se llama", "recibo a", "atiendo a", "atendemos a",
        "se trata de", f"se trata de {articulo} {'señora' if femenino else 'señor'}",
        f"{articulo} {'señora' if femenino else 'señor'}", f"{articulo} {'joven' if r.random() < .5 else 'niña' if femenino else 'niño'}",
        "hoy consulta", f"viene {articulo} {'paciente' if r.random() < .5 else 'joven'}", "registro de",
        f"paciente {'femenina' if femenino else 'masculino'} ,", "ingresa", "llega",
    ]
    c = {}
    eleccion = r.random()
    if eleccion < 0.12:
        c["NOMBRE"] = persona                                     # sin palabra disparadora
    elif eleccion < 0.24:
        c["NOMBRE"] = [("nombre", None), (":", None)] + persona
    elif eleccion < 0.30:
        c["NOMBRE"] = [("nombre", None), (n, "NOMBRE"), ("apellido paterno", None), (ap, "AP_PATERNO"),
                       ("apellido materno", None), (am, "AP_MATERNO")]
    else:
        c["NOMBRE"] = [(r.choice(presentaciones), None)] + persona
    if r.random() < 0.35:
        c["NOMBRE"] += [(r.choice(["de", ","]), None), (str(r.randint(1, 95)), None), ("años", None)]

    c["FECHA_NAC"] = [(r.choice(["nació el", "nacida el" if femenino else "nacido el", "fecha de nacimiento",
                                 "fecha de nacimiento :", "con fecha de nacimiento", "nacimiento el"]), None),
                      (fecha(r), "FECHA_NAC")]
    sexo = r.choice(["femenino", "mujer"] if femenino else ["masculino", "hombre"])
    c["SEXO"] = r.choice([
        [("sexo", None), (sexo, "SEXO")],
        [("sexo :", None), (sexo, "SEXO")],
        [("es", None), ("una" if femenino else "un", None), ("mujer" if femenino else "hombre", "SEXO"),
         ("de", None), (str(r.randint(1, 95)), None), ("años", None)],
        [("paciente", None), ("femenina" if femenino else "masculino", "SEXO")],
    ])
    c["CURP"] = [(r.choice(["su curp es", "curp", "con curp", "la curp es", "curp :"]), None),
                 (curp(r, n, ap, am, femenino), "CURP")]
    c["TELEFONO"] = [(r.choice(["teléfono", "su teléfono es", "su número es", "celular", "teléfono de contacto",
                                "su celular es", "número", "tel ."]), None), (telefono(r), "TELEFONO")]
    usuario = f"{n.split()[0]}{r.choice(['.', '_', ''])}{ap}{r.choice(['', str(r.randint(1, 99))])}"
    usuario = usuario.translate(str.maketrans("áéíóúñ", "aeioun"))
    c["CORREO"] = [(r.choice(["correo", "su correo es", "su correo electrónico es", "email", "correo electrónico"]), None),
                   (f"{usuario}@{r.choice(DOMINIOS)}", "CORREO")]
    numero = str(r.randint(1, 999))
    via = f"{r.choice(TIPOS_VIA)} {r.choice(CALLES)} {r.choice(['número ' + numero, numero, 'número ' + numero])}"
    col = f"colonia {r.choice(COLONIAS)}"
    c["DOMICILIO"] = [(r.choice(["vive en", "domicilio", "con domicilio en", "su domicilio es", "domicilio :"]), None),
                      (f"{via} , {col}" if r.random() < 0.5 else f"{via} {col}", "DOMICILIO")]
    mun, edo = r.choice(MUNICIPIOS)
    c["MUNICIPIO"] = [(r.choice(["en", "municipio de", "radica en", "en el municipio de", "vive en"]), None),
                      (f"{mun} , {edo}", "MUNICIPIO")]
    parentesco = r.choice(PARENTESCOS_F + PARENTESCOS_M)
    contacto = f"{r.choice(nombres)} {r.choice(apellidos)}"
    c["CONTACTO_EMERG"] = r.choice([
        [(r.choice(["en caso de emergencia avisar a", "contacto de emergencia", "en caso de emergencia llamar a"]), None),
         (contacto, "CONTACTO_EMERG"), ("su", None), (parentesco, None)],
        [(r.choice(["en caso de emergencia llamar a su", "avisar a su", "su"]), None), (parentesco, None),
         (contacto, "CONTACTO_EMERG")],
    ])
    c["TEL_EMERG"] = [(r.choice(["al", "al teléfono", "teléfono de emergencia", "localizable al", "está localizable al"]), None),
                      (telefono(r), "TEL_EMERG")]

    def alergias():
        art, a = r.choice(ALERGIAS)
        ad = "alérgica" if femenino else "alérgico"
        prep = {"el": "al", "la": "a la", "los": "a los", "las": "a las"}[art]
        valor = a
        if r.random() < 0.25:
            art2, a2 = r.choice(ALERGIAS)
            valor += " y " + a2
        return r.choice([
            [(f"{ad} {prep}", None), (valor, "ALERGIAS")],
            [(f"es {ad} {prep}", None), (valor, "ALERGIAS")],
            [(f"refiere que es {ad} {prep}", None), (valor, "ALERGIAS")],
            [(f"alergia {prep}", None), (valor, "ALERGIAS")],
            [("alergias", None), (r.choice([":", ""]), None), (valor, "ALERGIAS")],
            [(f"{ad} a", None), (valor, "ALERGIAS")],
            [("sin alergias conocidas", "ALERGIAS")],
            [("niega alergias", None)],
        ])
    c["ALERGIAS"] = alergias()
    c["GRUPO_SANG"] = [(r.choice(["tipo de sangre", "grupo sanguíneo", "sangre", "es tipo de sangre",
                                  "su tipo de sangre es", "tipo"]), None), (r.choice(GRUPOS), "GRUPO_SANG")]
    c["PADECIMIENTOS"] = [(r.choice(["padece", "padece de", "tiene", "antecedente de", "tiene antecedente de",
                                     "con antecedente de", "con diagnóstico previo de", "conocido con",
                                     "diagnosticado con" if not femenino else "diagnosticada con", "vive con"]), None),
                          (lista(r, PADECIMIENTOS), "PADECIMIENTOS")]
    c["MEDICAMENTOS"] = [(r.choice(["toma", "en tratamiento con", "está en tratamiento con", "medicamentos actuales",
                                    "usa", "actualmente usa", "actualmente toma", "se le indicó", "tomando"]), None),
                         (lista(r, MEDICAMENTOS), "MEDICAMENTOS")]
    c["MOTIVO"] = [(r.choice(["acude por", "motivo de consulta", "motivo de consulta :", "viene por",
                              "viene porque presenta", "consulta por", "consulta porque tiene", "llega con",
                              "porque tiene", "presenta", "refiere", "por"]), None), (motivo(r), "MOTIVO")]
    return c


TOKEN = re.compile(r"[\w.+-]+@[\w-]+(?:\.[\w-]+)+|\w+|[^\w\s]", re.UNICODE)


def tokenizar(texto):
    return TOKEN.findall(texto)


def estilo_whisper(token, campo, inicio_oracion):
    """Whisper capitaliza nombres propios, siglas del grupo sanguíneo y el inicio de oración."""
    if campo in ("NOMBRE", "AP_PATERNO", "AP_MATERNO", "CONTACTO_EMERG") and token.isalpha():
        return token.capitalize()
    if campo == "MUNICIPIO" and token.isalpha() and token not in ("de", "del", "la"):
        return token.capitalize()
    if campo == "GRUPO_SANG" and token in ("o", "a", "b", "ab"):
        return token.upper()
    if token == "curp":          # Whisper escribe la sigla en mayúsculas
        return "CURP"
    return token.capitalize() if inicio_oracion and token.isalpha() else token


def ejemplo(r, nombres, apellidos):
    c = clausulas(r, nombres, apellidos)
    resto = [k for k in c if k != "NOMBRE"]
    elegidas = ["NOMBRE"] + r.sample(resto, r.randint(1, min(8, len(resto))))
    if r.random() < 0.2:
        r.shuffle(elegidas)
    else:
        otros = elegidas[1:]
        r.shuffle(otros)
        elegidas = ["NOMBRE"] + otros
    # El teléfono de emergencia suele ir pegado al contacto
    if "TEL_EMERG" in elegidas and "CONTACTO_EMERG" in elegidas:
        elegidas.remove("TEL_EMERG")
        elegidas.insert(elegidas.index("CONTACTO_EMERG") + 1, "TEL_EMERG")

    whisper = r.random() < 0.5      # mitad estilo Whisper (mayúsculas, puntuación); mitad minúsculas sin puntuación
    tokens, etiquetas = [], []
    inicio = True

    def agregar(texto, campo):
        nonlocal inicio
        if not whisper:
            texto = texto.replace(" , ", " ").replace(" :", "")
        piezas = tokenizar(texto if campo == "CURP" else texto.lower())
        for j, t in enumerate(piezas):
            if whisper:
                t = estilo_whisper(t, campo, inicio)
            tokens.append(t)
            etiquetas.append("O" if campo is None else ("B-" if j == 0 else "I-") + campo)
            inicio = t in (".",)

    for i, clave in enumerate(elegidas):
        if r.random() < 0.06:
            agregar(r.choice(MULETILLAS), None)
        if i > 0 and r.random() < 0.15 and not (clave == "TEL_EMERG"):
            agregar(r.choice(CONECTORES), None)
        for texto, campo in c[clave]:
            if texto:
                agregar(texto, campo)
        if whisper and i < len(elegidas) - 1 and elegidas[i + 1] != "TEL_EMERG":
            agregar(r.choice([".", ",", ",", "."]), None)
    if whisper:
        agregar(".", None)
    return {"tokens": tokens, "etiquetas": etiquetas}


def main():
    r = random.Random(SEMILLA)
    nombres, apellidos = NOMBRES[:], APELLIDOS[:]
    r.shuffle(nombres); r.shuffle(apellidos)
    corte_n, corte_a = int(len(nombres) * 0.8), int(len(apellidos) * 0.8)
    grupos = {
        "entrenamiento": (nombres[:corte_n], apellidos[:corte_a]),
        "validacion": (nombres[:corte_n], apellidos[:corte_a]),
        "prueba": (nombres[corte_n:], apellidos[corte_a:]),   # nombres nunca vistos
    }
    CARPETA.mkdir(exist_ok=True)
    for division, cantidad in CANTIDAD.items():
        ns, aps = grupos[division]
        with open(CARPETA / f"{division}.jsonl", "w", encoding="utf-8") as f:
            for _ in range(cantidad):
                f.write(json.dumps(ejemplo(r, ns, aps), ensure_ascii=False) + "\n")
        print(f"{division}: {cantidad} ejemplos")
    with open(CARPETA / "campos.json", "w", encoding="utf-8") as f:
        json.dump(CAMPOS, f, ensure_ascii=False)


if __name__ == "__main__":
    main()
