import sys
import platform

# --- AMBIENTE E DIPENDENZE ---
def check_env():
    if sys.maxsize <= 2**32:
        print("ERRORE: TensorFlow richiede Python a 64 bit.")
        sys.exit(1)
    major, minor = sys.version_info[:2]
    if not (major == 3 and 8 <= minor <= 11):
        print("ERRORE: TensorFlow NON funziona con Python", sys.version.split()[0])
        print("Installa Python 3.8, 3.9, 3.10 o 3.11 a 64 bit da https://www.python.org/downloads/")
        sys.exit(1)
    if platform.system() not in ("Windows", "Linux", "Darwin"):
        print("ERRORE: Sistema operativo non supportato da TensorFlow.")
        sys.exit(1)

def check_dependencies():
    missing = []
    try:
        import tensorflow as tf
    except ImportError:
        missing.append("tensorflow")
    try:
        import numpy as np
    except ImportError:
        missing.append("numpy")
    try:
        import sklearn
    except ImportError:
        missing.append("scikit-learn")
    if missing:
        print("\nATTENZIONE: Devi installare questi pacchetti prima di eseguire lo script:")
        print("pip install " + " ".join(missing))
        sys.exit(1)

check_env()
check_dependencies()

import sqlite3
import numpy as np
from sklearn.preprocessing import LabelEncoder
from sklearn.model_selection import train_test_split
import tensorflow as tf

# --- FUNZIONI UTILI ---
def convert_result_to_label(result):
    score1, score2 = map(int, result.split('-'))
    if score1 > score2:
        return 0  # "1"
    elif score1 == score2:
        return 1  # "X"
    else:
        return 2  # "2"

def prepare_data():
    conn = sqlite3.connect("matches.db")
    cursor = conn.cursor()
    cursor.execute("SELECT team1, team2, result FROM matches UNION ALL SELECT team1, team2, result FROM head_to_head")
    data = cursor.fetchall()
    conn.close()
    if not data:
        print("Nessun dato trovato nel database.")
        sys.exit(1)
    le = LabelEncoder()
    teams = list(set([team for match in data for team in [match[0], match[1]]]))
    le.fit(teams)
    X = []
    y = []
    for match in data:
        team1_enc = le.transform([match[0]])[0]
        team2_enc = le.transform([match[1]])[0]
        X.append([team1_enc, team2_enc])
        y.append(convert_result_to_label(match[2]))
    return np.array(X), np.array(y), le

def build_model(input_dim):
    model = tf.keras.Sequential([
        tf.keras.layers.Input(shape=(input_dim,)),
        tf.keras.layers.Dense(128, activation='relu'),
        tf.keras.layers.Dense(64, activation='relu'),
        tf.keras.layers.Dense(32, activation='relu'),
        tf.keras.layers.Dense(3, activation='softmax')
    ])
    model.compile(optimizer='adam', loss='sparse_categorical_crossentropy', metrics=['accuracy'])
    return model

def predict_result(model, team1, team2, label_encoder):
    team1_enc = label_encoder.transform([team1])[0]
    team2_enc = label_encoder.transform([team2])[0]
    input_arr = np.array([[team1_enc, team2_enc]])
    probabilities = model.predict(input_arr, verbose=0)[0]
    prediction = np.argmax(probabilities)
    result_map = {0: "1", 1: "X", 2: "2"}
    result = result_map[prediction]
    print(f"\nPredizione per {team1} vs {team2}:")
    print(f"Risultato più probabile: {result}")
    print(f"Probabilità: 1({probabilities[0]:.2%}) X({probabilities[1]:.2%}) 2({probabilities[2]:.2%})")
    return result

# --- MAIN ---
X, y, label_encoder = prepare_data()
X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)

model = build_model(input_dim=2)
model.fit(X_train, y_train, epochs=150, batch_size=8, verbose=1, validation_data=(X_test, y_test))

loss, acc = model.evaluate(X_test, y_test, verbose=0)
print(f"\nAccuratezza su test: {acc:.2%}")

# Esempio predizione
predict_result(model, "Roma", "Juventus", label_encoder)
predict_result(model, "Inter", "Milan", label_encoder)