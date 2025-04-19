import sys
import tensorflow as tf
import numpy as np
import sqlite3
from sklearn.preprocessing import LabelEncoder
from sklearn.model_selection import train_test_split

# --- FUNZIONI DI UTILITÀ E PREPROCESSING ---

def convert_result_to_label(result):
    score1, score2 = map(int, result.split('-'))
    if score1 > score2:
        return 0
    elif score1 == score2:
        return 1
    else:
        return 2

def is_over_2_5(result):
    try:
        score1, score2 = map(int, result.split('-'))
        return int((score1 + score2) > 2)
    except Exception:
        return 0

def prepare_data():
    conn = sqlite3.connect("matches.db")
    cursor = conn.cursor()
    cursor.execute("SELECT team1, team2, result, date FROM matches")
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
    dates = []
    for match in data:
        team1_enc = le.transform([match[0]])[0]
        team2_enc = le.transform([match[1]])[0]
        X.append([team1_enc, team2_enc])
        y.append(convert_result_to_label(match[2]))
        dates.append(match[3])
    # Calcola i pesi: più recente = peso maggiore
    dates_sorted = sorted(list(set(dates)))
    date_to_weight = {date: (i+1)/len(dates_sorted) for i, date in enumerate(dates_sorted)}
    sample_weights = np.array([date_to_weight[d] for d in dates])
    return np.array(X), np.array(y), le, sample_weights

# --- FUNZIONI MODELLO ---

def build_model_with_embedding(num_teams):
    model = tf.keras.Sequential([
        tf.keras.layers.Input(shape=(2,)),
        tf.keras.layers.Embedding(input_dim=num_teams, output_dim=4, input_length=2),
        tf.keras.layers.Flatten(),
        tf.keras.layers.Dense(32, activation='relu'),
        tf.keras.layers.Dense(3, activation='softmax')
    ])
    model.compile(optimizer='adam', loss='sparse_categorical_crossentropy', metrics=['accuracy'])
    return model

# --- FUNZIONI DI PREVISIONE ---

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

def predict_result_top2(model, team1, team2, label_encoder):
    team1_enc = label_encoder.transform([team1])[0]
    team2_enc = label_encoder.transform([team2])[0]
    input_arr = np.array([[team1_enc, team2_enc]])
    probabilities = model.predict(input_arr, verbose=0)[0]
    result_map = {0: "1", 1: "X", 2: "2"}
    # Ordina le probabilità e prendi le due più alte
    top2_idx = np.argsort(probabilities)[-2:][::-1]
    top2_results = "".join([result_map[i] for i in top2_idx])
    print(f"\nPredizione per {team1} vs {team2}:")
    print(f"Risultati più probabili: {top2_results}")
    print(f"Probabilità: 1({probabilities[0]:.2%}) X({probabilities[1]:.2%}) 2({probabilities[2]:.2%})")
    return top2_results

def predict_over_2_5(model, team1, team2, label_encoder, soglia=0.5):
    """
    Prevede se la partita sarà OVER 2.5 in base alle probabilità del modello.
    Se la somma delle probabilità di risultati diversi da X è sopra la soglia, predice OVER.
    """
    team1_enc = label_encoder.transform([team1])[0]
    team2_enc = label_encoder.transform([team2])[0]
    input_arr = np.array([[team1_enc, team2_enc]])
    probabilities = model.predict(input_arr, verbose=0)[0]
    prob_over = probabilities[0] + probabilities[2]  # 1 + 2
    if prob_over > soglia:
        risultato = "OVER 2.5"
    else:
        risultato = "UNDER 2.5"
    print(f"Previsione OVER/UNDER per {team1} vs {team2}: {risultato} (Prob. OVER stimata: {prob_over:.2%})")
    return risultato

def predict_top5_probabilities(model, partite, label_encoder):
    """
    Mostra le 5 previsioni con la probabilità più alta tra tutte le partite,
    includendo anche la probabilità di OVER/UNDER 2.5 e la doppia chance (somma delle due più probabili).
    """
    risultati = []
    for team1, team2 in partite:
        team1_enc = label_encoder.transform([team1])[0]
        team2_enc = label_encoder.transform([team2])[0]
        input_arr = np.array([[team1_enc, team2_enc]])
        probabilities = model.predict(input_arr, verbose=0)[0]
        result_map = {0: "1", 1: "X", 2: "2"}
        # Quote classiche 1/X/2
        for idx, prob in enumerate(probabilities):
            risultati.append({
                "match": f"{team1} vs {team2}",
                "esito": result_map[idx],
                "prob": prob,
                "tipo": "1X2"
            })
        # Quote OVER/UNDER 2.5 (stimata: OVER = 1+2, UNDER = X)
        prob_over = probabilities[0] + probabilities[2]
        prob_under = probabilities[1]
        risultati.append({
            "match": f"{team1} vs {team2}",
            "esito": "OVER 2.5",
            "prob": prob_over,
            "tipo": "OVER/UNDER"
        })
        risultati.append({
            "match": f"{team1} vs {team2}",
            "esito": "UNDER 2.5",
            "prob": prob_under,
            "tipo": "OVER/UNDER"
        })
        # Doppia chance (somma delle due probabilità più alte)
        top2_idx = np.argsort(probabilities)[-2:][::-1]
        top2_results = "".join([result_map[i] for i in top2_idx])
        prob_top2 = probabilities[top2_idx[0]] + probabilities[top2_idx[1]]
        risultati.append({
            "match": f"{team1} vs {team2}",
            "esito": f"Doppia chance {top2_results}",
            "prob": prob_top2,
            "tipo": "DOPPIA CHANCE"
        })
    # Ordina per probabilità decrescente e mostra i primi 5
    risultati_ordinati = sorted(risultati, key=lambda x: x["prob"], reverse=True)[:5]
    print("\n--- TOP 5 QUOTE PIÙ PROBABILI ---")
    for r in risultati_ordinati:
        print(f"{r['match']} - Esito: {r['esito']} ({r['tipo']}) - Probabilità: {r['prob']:.2%}")

# --- MENU UI ---

def menu_previsioni(model, label_encoder):
    partite = [
        ("Lecce", "Como"),
        ("Monza", "Napoli"),
        ("Roma", "Verona"),
        ("Empoli", "Venezia"),
        ("Bologna", "Inter"),
        ("Milan", "Atalanta"),
        ("Torino", "Udinese"),
        ("Cagliari", "Fiorentina"),
        ("Genoa", "Lazio"),
        ("Parma", "Juventus"),
    ]
    while True:
        print("\n--- MENU PREVISIONI ---")
        print("1) Risultato finale")
        print("2) Doppia chance (due risultati più probabili)")
        print("3) Over/Under 2.5")
        print("4) Top 5 quote più probabili")
        print("0) Esci")
        scelta = input("Scegli un'opzione: ")
        if scelta == "1":
            print("\n--- Previsioni RISULTATO FINALE ---")
            for team1, team2 in partite:
                predict_result(model, team1, team2, label_encoder)
        elif scelta == "2":
            print("\n--- Previsioni DOPPIA CHANCE ---")
            for team1, team2 in partite:
                predict_result_top2(model, team1, team2, label_encoder)
        elif scelta == "3":
            print("\n--- Previsioni OVER/UNDER 2.5 ---")
            for team1, team2 in partite:
                predict_over_2_5(model, team1, team2, label_encoder)
        elif scelta == "4":
            predict_top5_probabilities(model, partite, label_encoder)
        elif scelta == "0":
            print("Uscita dal programma.")
            break
        else:
            print("Scelta non valida. Riprova.")

# --- MAIN ---

if __name__ == "__main__":
    X, y, label_encoder, sample_weights = prepare_data()
    X_train, X_test, y_train, y_test, sw_train, sw_test = train_test_split(
        X, y, sample_weights, test_size=0.2, random_state=42
    )

    num_teams = len(label_encoder.classes_)
    model = build_model_with_embedding(num_teams)
    model.fit(X_train, y_train, epochs=100, batch_size=128, verbose=1, validation_data=(X_test, y_test), sample_weight=sw_train)

    loss, acc = model.evaluate(X_test, y_test, verbose=0)
    print(f"\nAccuratezza su test: {acc:.2%}")

    menu_previsioni(model, label_encoder)