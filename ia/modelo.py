"""
Extractor de campos del dictado: BiLSTM sobre embeddings de palabra + caracteres.

Entrada:  tokens del texto transcrito.
Salida:   una etiqueta BIO por token (B-NOMBRE, I-NOMBRE, ..., O).

- Embedding de palabra: representa el vocabulario conocido.
- CNN de caracteres: ayuda con palabras que el modelo nunca vio (nombres, CURP, correos, números).
- BiLSTM de 2 capas: lee el contexto en ambas direcciones ("alérgico a ..." define lo que sigue).
- Capa lineal: puntuación de cada etiqueta por token.
"""
import re

import torch
import torch.nn as nn

TOKEN = re.compile(r"[\w.+-]+@[\w-]+(?:\.[\w-]+)+|\w+|[^\w\s]", re.UNICODE)
PAD, UNK = "<pad>", "<unk>"


def tokenizar(texto):
    return TOKEN.findall(texto)


def normalizar(token):
    """Forma de la palabra para el vocabulario: minúsculas y dígitos como 0 (el valor exacto no importa)."""
    return re.sub(r"\d", "0", token.lower())


class ExtractorCampos(nn.Module):
    def __init__(self, n_palabras, n_caracteres, n_etiquetas,
                 dim_palabra=100, dim_caracter=30, filtros_caracter=50,
                 oculto=128, capas=2, abandono=0.3):
        super().__init__()
        self.emb_palabra = nn.Embedding(n_palabras, dim_palabra, padding_idx=0)
        self.emb_caracter = nn.Embedding(n_caracteres, dim_caracter, padding_idx=0)
        self.cnn_caracter = nn.Conv1d(dim_caracter, filtros_caracter, kernel_size=3, padding=1)
        self.lstm = nn.LSTM(dim_palabra + filtros_caracter, oculto, num_layers=capas,
                            batch_first=True, bidirectional=True, dropout=abandono)
        self.abandono = nn.Dropout(abandono)
        self.salida = nn.Linear(oculto * 2, n_etiquetas)

    def forward(self, palabras, caracteres):
        # palabras: [lote, tokens]   caracteres: [lote, tokens, letras]
        lote, n_tokens, n_letras = caracteres.shape
        c = self.emb_caracter(caracteres.view(-1, n_letras)).transpose(1, 2)   # [lote*tokens, dim, letras]
        c = torch.relu(self.cnn_caracter(c)).max(dim=2).values                # [lote*tokens, filtros]
        c = c.view(lote, n_tokens, -1)
        x = torch.cat([self.emb_palabra(palabras), c], dim=-1)
        x, _ = self.lstm(self.abandono(x))
        return self.salida(self.abandono(x))                                   # [lote, tokens, etiquetas]
