"""
Prueba de robustez: dictados redactados a mano, con frases distintas a las plantillas de entrenamiento.

Mide qué tan bien generaliza el modelo fuera del estilo con que se generó el corpus.
Cada caso indica los campos que DEBERÍA extraer; se compara campo por campo (texto exacto,
sin distinguir mayúsculas). Los textos son ejemplos de prueba, no pacientes reales.

Uso:  python evaluar_dictados.py
"""
import json
from pathlib import Path

from predecir import extraer

CASOS = [
    ("paciente roberto salinas mora de 52 años alérgico a la penicilina acude por dolor de rodilla",
     {"nombre": "roberto", "primer_apellido": "salinas", "segundo_apellido": "mora",
      "alergias": "penicilina", "motivo_consulta": "dolor de rodilla"}),
    ("se llama claudia ríos treviño nació el 14 de febrero de 1987 sexo femenino teléfono 55 4821 9930",
     {"nombre": "claudia", "primer_apellido": "ríos", "segundo_apellido": "treviño",
      "fecha_nacimiento": "14 de febrero de 1987", "sexo": "femenino", "telefono": "55 4821 9930"}),
    ("la paciente maría elena zúñiga padece hipertensión arterial y toma losartán de 50 miligramos",
     {"nombre": "maría elena", "primer_apellido": "zúñiga", "padecimientos_cronicos": "hipertensión arterial",
      "medicamentos_actuales": "losartán de 50 miligramos"}),
    ("nombre tomás apellido paterno quiroga apellido materno lara tipo de sangre o positivo sin alergias conocidas",
     {"nombre": "tomás", "primer_apellido": "quiroga", "segundo_apellido": "lara",
      "grupo_sanguineo": "o positivo", "alergias": "sin alergias conocidas"}),
    ("viene porque presenta fiebre y tos desde ayer el paciente es iker fonseca correo iker.fonseca7@gmail.com",
     {"motivo_consulta": "fiebre y tos desde ayer", "nombre": "iker", "primer_apellido": "fonseca",
      "correo": "iker.fonseca7@gmail.com"}),
    ("paciente lourdes peralta ochoa vive en calle juárez número 12 colonia centro en cuernavaca morelos",
     {"nombre": "lourdes", "primer_apellido": "peralta", "segundo_apellido": "ochoa",
      "domicilio": "calle juárez número 12 colonia centro", "municipio_estado": "cuernavaca morelos"}),
    # Redacción más libre, sin las frases exactas de las plantillas
    ("buenas tardes atiendo a fabiola urrutia garza, refiere que es alérgica a las sulfas y que tiene asma",
     {"nombre": "fabiola", "primer_apellido": "urrutia", "segundo_apellido": "garza",
      "alergias": "sulfas", "padecimientos_cronicos": "asma"}),
    ("el señor octavio rendón llega con dolor abdominal de tres días, su esposa lucía rendón está localizable al 33 1200 4545",
     {"nombre": "octavio", "primer_apellido": "rendón", "motivo_consulta": "dolor abdominal de tres días",
      "telefono_emergencia": "33 1200 4545"}),
    ("paciente femenina de 30 años, irene blanco solís, actualmente usa salbutamol, sangre a negativo",
     {"nombre": "irene", "primer_apellido": "blanco", "segundo_apellido": "solís",
      "medicamentos_actuales": "salbutamol", "grupo_sanguineo": "a negativo"}),
    ("registro de ernesto villalobos, con curp VIER850312HDFLRN07 y celular 81 3344 5566",
     {"nombre": "ernesto", "primer_apellido": "villalobos", "curp": "VIER850312HDFLRN07",
      "telefono": "81 3344 5566"}),
]


# Conjunto B: escrito ANTES de mejorar el generador (28 oct 2026), con estilo de salida de Whisper
# (mayúsculas y puntuación) y frases distintas a las del conjunto A y a las plantillas.
CASOS_B = [
    ("Paciente Gloria Esquivel Duarte, 61 años. Es alérgica a la aspirina y padece diabetes.",
     {"nombre": "gloria", "primer_apellido": "esquivel", "segundo_apellido": "duarte",
      "alergias": "aspirina", "padecimientos_cronicos": "diabetes"}),
    ("Recibo a Mauricio Benítez Arce, viene por un dolor de oído desde hace una semana.",
     {"nombre": "mauricio", "primer_apellido": "benítez", "segundo_apellido": "arce",
      "motivo_consulta": "un dolor de oído desde hace una semana"}),
    ("Se trata de la señora Rebeca Ponce. Su teléfono es 22 2345 6789 y su correo rebeca.ponce@outlook.com.",
     {"nombre": "rebeca", "primer_apellido": "ponce", "telefono": "22 2345 6789",
      "correo": "rebeca.ponce@outlook.com"}),
    ("Nombre: Saúl Arredondo Pineda. Fecha de nacimiento: 3 de junio de 1975. Sexo masculino.",
     {"nombre": "saúl", "primer_apellido": "arredondo", "segundo_apellido": "pineda",
      "fecha_nacimiento": "3 de junio de 1975", "sexo": "masculino"}),
    ("La paciente Ana Sofía Treviño Galván toma metformina y enalapril, es tipo de sangre B positivo.",
     {"nombre": "ana sofía", "primer_apellido": "treviño", "segundo_apellido": "galván",
      "medicamentos_actuales": "metformina y enalapril", "grupo_sanguineo": "b positivo"}),
    ("Viene el joven Kevin Montaño por fiebre de 39 grados. Niega alergias.",
     {"nombre": "kevin", "primer_apellido": "montaño", "motivo_consulta": "fiebre de 39 grados"}),
    ("Paciente Elvira Mancilla Robledo, vive en avenida Morelos 230, colonia Las Águilas, en Puebla, Puebla.",
     {"nombre": "elvira", "primer_apellido": "mancilla", "segundo_apellido": "robledo",
      "municipio_estado": "puebla, puebla"}),
    ("Atendemos a Jacinto Olvera, en caso de emergencia llamar a su hija Paola Olvera al 55 6677 8899.",
     {"nombre": "jacinto", "primer_apellido": "olvera", "contacto_emergencia": "paola olvera",
      "telefono_emergencia": "55 6677 8899"}),
    ("El paciente Rubén Cisneros Tapia tiene antecedente de epilepsia y está en tratamiento con ácido valproico.",
     {"nombre": "rubén", "primer_apellido": "cisneros", "segundo_apellido": "tapia",
      "padecimientos_cronicos": "epilepsia", "medicamentos_actuales": "ácido valproico"}),
    ("Paciente Dolores Ibáñez Quintero con CURP IAQD700415MJCBNL08, alergia al látex.",
     {"nombre": "dolores", "primer_apellido": "ibáñez", "segundo_apellido": "quintero",
      "curp": "IAQD700415MJCBNL08", "alergias": "látex"}),
    ("Hoy consulta Fernando Aguirre Leyva porque tiene dolor lumbar desde el lunes.",
     {"nombre": "fernando", "primer_apellido": "aguirre", "segundo_apellido": "leyva",
      "motivo_consulta": "dolor lumbar desde el lunes"}),
    ("Paciente femenina, Mariana Oropeza, nacida el 19/08/2001, celular 33 9988 7766.",
     {"nombre": "mariana", "primer_apellido": "oropeza", "fecha_nacimiento": "19/08/2001",
      "telefono": "33 9988 7766"}),
]


# Conjunto C: escrito DESPUÉS de reentrenar y ANTES de ver los resultados del modelo v2.
# Frases nuevas, que no se usaron en el generador ni en los conjuntos A y B.
CASOS_C = [
    ("Buenos días, le tomo datos a Leonor Valdivia Cantú, tiene 47 años y le dan alergia los camarones.",
     {"nombre": "leonor", "primer_apellido": "valdivia", "segundo_apellido": "cantú"}),
    ("Paciente Horacio Lemus Bravo. Hipertenso desde hace diez años, toma amlodipino.",
     {"nombre": "horacio", "primer_apellido": "lemus", "segundo_apellido": "bravo",
      "medicamentos_actuales": "amlodipino"}),
    ("La señora Consuelo Arteaga viene con dolor de pecho desde anoche, su teléfono es 55 3030 1212.",
     {"nombre": "consuelo", "primer_apellido": "arteaga", "motivo_consulta": "dolor de pecho desde anoche",
      "telefono": "55 3030 1212"}),
    ("Nombre completo: Uriel Bermúdez Salgado. Nació el 8 de diciembre de 1999. Es hombre.",
     {"nombre": "uriel", "primer_apellido": "bermúdez", "segundo_apellido": "salgado",
      "fecha_nacimiento": "8 de diciembre de 1999"}),
    ("Ingresa Itzel Carrasco Peralta por vómito desde la mañana, grupo sanguíneo A positivo.",
     {"nombre": "itzel", "primer_apellido": "carrasco", "segundo_apellido": "peralta",
      "motivo_consulta": "vómito desde la mañana", "grupo_sanguineo": "a positivo"}),
    ("Paciente Néstor Gallardo, correo nestor.gallardo@gmail.com, vive en calle Pino Suárez 45, colonia Centro.",
     {"nombre": "néstor", "primer_apellido": "gallardo", "correo": "nestor.gallardo@gmail.com",
      "domicilio": "calle pino suárez 45, colonia centro"}),
    ("Atiendo a Brenda Yáñez Ocampo, es alérgica a la ampicilina y tiene hipotiroidismo.",
     {"nombre": "brenda", "primer_apellido": "yáñez", "segundo_apellido": "ocampo",
      "alergias": "ampicilina", "padecimientos_cronicos": "hipotiroidismo"}),
    ("El niño Emiliano Ruelas Pardo, de 8 años, llega por tos y fiebre desde ayer.",
     {"nombre": "emiliano", "primer_apellido": "ruelas", "segundo_apellido": "pardo",
      "motivo_consulta": "tos y fiebre desde ayer"}),
    ("Paciente Josefina Barragán, contacto de emergencia su hijo Arturo Barragán al 81 4545 2323.",
     {"nombre": "josefina", "primer_apellido": "barragán", "contacto_emergencia": "arturo barragán",
      "telefono_emergencia": "81 4545 2323"}),
    ("Se registra a Gilberto Mena Zaragoza, de Morelia, Michoacán, con CURP MEZG810127HMNNRL05.",
     {"nombre": "gilberto", "primer_apellido": "mena", "segundo_apellido": "zaragoza",
      "curp": "MEZG810127HMNNRL05"}),
    ("Hoy viene Paulina Esparza Cuevas a control prenatal, sin alergias conocidas.",
     {"nombre": "paulina", "primer_apellido": "esparza", "segundo_apellido": "cuevas",
      "motivo_consulta": "control prenatal", "alergias": "sin alergias conocidas"}),
    ("Paciente masculino de 70 años, Efraín Toledo Rocha, padece insuficiencia renal y usa furosemida.",
     {"nombre": "efraín", "primer_apellido": "toledo", "segundo_apellido": "rocha",
      "padecimientos_cronicos": "insuficiencia renal", "medicamentos_actuales": "furosemida"}),
]


def medir(casos):
    aciertos = total = 0
    detalle = []
    for texto, esperado in casos:
        obtenido = extraer(texto)
        for campo, valor in esperado.items():
            ok = obtenido.get(campo, "").lower().rstrip(".,") == valor.lower()
            aciertos += ok
            total += 1
            if not ok:
                detalle.append(f"  ✗ {campo}: esperado «{valor}», obtenido «{obtenido.get(campo, '—')}»")
    return aciertos, total, detalle


def main():
    resultado = {}
    for nombre, casos in (("A", CASOS), ("B", CASOS_B), ("C", CASOS_C)):
        aciertos, total, detalle = medir(casos)
        print(f"Conjunto {nombre}: {aciertos}/{total} campos correctos ({aciertos / total:.1%})")
        print("\n".join(detalle) if detalle else "  (todos correctos)")
        resultado[nombre] = {"aciertos": aciertos, "total": total, "exactitud": aciertos / total, "errores": detalle}
    salida = Path(__file__).parent / "modelos" / "robustez.json"
    salida.write_text(json.dumps(resultado, ensure_ascii=False, indent=2), encoding="utf-8")


if __name__ == "__main__":
    main()
