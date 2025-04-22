import os
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3' 
os.environ['TF_ENABLE_ONEDNN_OPTS'] = '0'
import sys
import sqlite3
import inquirer
import warnings
warnings.filterwarnings("ignore")
import numpy as np
import tensorflow as tf
from sklearn.preprocessing import LabelEncoder
from sklearn.model_selection import train_test_split


DB_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "matches.db")

# =========================
# --- FUNZIONI UTILITY ---
# =========================

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
    conn = sqlite3.connect(DB_PATH)
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
    dates_sorted = sorted(list(set(dates)))
    date_to_weight = {date: (i+1)/len(dates_sorted) for i, date in enumerate(dates_sorted)}
    sample_weights = np.array([date_to_weight[d] for d in dates])
    return np.array(X), np.array(y), le, sample_weights

# =========================
# --- MODELLO NEURALE ---
# =========================

def build_model_with_embedding(num_teams):
    model = tf.keras.Sequential([
        tf.keras.layers.Input(shape=(2,)),
        tf.keras.layers.Embedding(input_dim=num_teams, output_dim=16),
        tf.keras.layers.Flatten(),
        tf.keras.layers.Dense(128, activation='relu'),
        tf.keras.layers.Dropout(0.4),
        tf.keras.layers.Dense(64, activation='relu'),
        tf.keras.layers.Dropout(0.3),
        tf.keras.layers.Dense(32, activation='relu'),
        tf.keras.layers.Dense(16, activation='relu'),
        tf.keras.layers.Dense(3, activation='softmax')
    ])
    model.compile(
        optimizer=tf.keras.optimizers.Adam(learning_rate=0.0007),
        loss='sparse_categorical_crossentropy',
        metrics=['accuracy']
    )
    return model

def train_model(model, X_train, y_train, X_test, y_test, sw_train):
    history = model.fit(
        X_train, y_train,
        epochs=50,
        batch_size=64,
        verbose=1,
        validation_data=(X_test, y_test),
        sample_weight=sw_train
    )
    loss, acc = model.evaluate(X_test, y_test, verbose=0)
    print(f"\nAccuratezza su test: {acc:.2%}")
    return model

# =========================
# --- FUNZIONI PREVISIONE---
# =========================

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
    top2_idx = np.argsort(probabilities)[-2:][::-1]
    top2_results = "".join([result_map[i] for i in top2_idx])
    prob_sum = probabilities[top2_idx[0]] + probabilities[top2_idx[1]]
    print(f"\nDoppia chance per {team1} vs {team2}: {top2_results} (Probabilità combinata: {prob_sum:.2%})")
    print(f"Dettaglio: 1({probabilities[0]:.2%}) X({probabilities[1]:.2%}) 2({probabilities[2]:.2%})")
    return top2_results, prob_sum

def predict_over_2_5(model, team1, team2, label_encoder, soglia=0.5):
    team1_enc = label_encoder.transform([team1])[0]
    team2_enc = label_encoder.transform([team2])[0]
    input_arr = np.array([[team1_enc, team2_enc]])
    probabilities = model.predict(input_arr, verbose=0)[0]
    prob_over = probabilities[0] + probabilities[2]
    if prob_over > soglia:
        risultato = "OVER 2.5"
    else:
        risultato = "UNDER 2.5"
    print(f"Previsione OVER/UNDER per {team1} vs {team2}: {risultato} (Prob. OVER stimata: {prob_over:.2%})")
    return risultato

def get_multigoal_probabilities_from_db(team1, team2, multigoal_ranges):
    conn = sqlite3.connect(DB_PATH)
    cursor = conn.cursor()
    cursor.execute("""
        SELECT result FROM matches
        WHERE (team1=? AND team2=?) OR (team1=? AND team2=?)
    """, (team1, team2, team2, team1))
    results = cursor.fetchall()
    conn.close()
    if not results:
        return {f"Multigol {min_g}-{max_g}": 0.0 for (min_g, max_g) in multigoal_ranges}
    goal_counts = []
    for (result,) in results:
        try:
            score1, score2 = map(int, result.split('-'))
            goal_counts.append(score1 + score2)
        except Exception:
            continue
    total_matches = len(goal_counts)
    multigoal_probs = {}
    for min_g, max_g in multigoal_ranges:
        count = sum(min_g <= g <= max_g for g in goal_counts)
        prob = count / total_matches if total_matches > 0 else 0.0
        multigoal_probs[f"Multigol {min_g}-{max_g}"] = prob
    return multigoal_probs

def predict_multigoal(model, team1, team2, label_encoder, multigoal_ranges=None):
    if multigoal_ranges is None:
        multigoal_ranges = [
            (0, 1), (0, 2), (0, 3), (0, 4), (1, 2), (1, 3), (1, 4), (2, 3), (2, 4), (3, 4),
            (2, 5), (3, 5), (4, 5), (3, 6), (4, 6), (5, 6)
        ]
    multigoal_probs = get_multigoal_probabilities_from_db(team1, team2, multigoal_ranges)
    best_label, best_prob = max(multigoal_probs.items(), key=lambda x: x[1])
    print(f"\n{team1} vs {team2}:")
    print(f"Multigol più probabile: {best_label} - Probabilità: {best_prob:.2%}")
    return multigoal_probs

def predict_top5_probabilities(model, partite, label_encoder):
    risultati = []
    for team1, team2 in partite:
        team1_enc = label_encoder.transform([team1])[0]
        team2_enc = label_encoder.transform([team2])[0]
        input_arr = np.array([[team1_enc, team2_enc]])
        probabilities = model.predict(input_arr, verbose=0)[0]
        result_map = {0: "1", 1: "X", 2: "2"}
        for idx, prob in enumerate(probabilities):
            risultati.append({
                "match": f"{team1} vs {team2}",
                "esito": result_map[idx],
                "prob": prob,
                "tipo": "1X2"
            })
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
        top2_idx = np.argsort(probabilities)[-2:][::-1]
        top2_results = "".join([result_map[i] for i in top2_idx])
        prob_top2 = probabilities[top2_idx[0]] + probabilities[top2_idx[1]]
        risultati.append({
            "match": f"{team1} vs {team2}",
            "esito": f"Doppia chance {top2_results}",
            "prob": prob_top2,
            "tipo": "DOPPIA CHANCE"
        })
        multigoal_ranges = [
            (0, 1), (0, 2), (0, 3), (0, 4), (1, 2), (1, 3), (1, 4), (2, 3), (2, 4), (3, 4),
            (2, 5), (3, 5), (4, 5), (3, 6), (4, 6), (5, 6)
        ]
        multigoal_probs = get_multigoal_probabilities_from_db(team1, team2, multigoal_ranges)
        if multigoal_probs:
            best_label, best_prob = max(multigoal_probs.items(), key=lambda x: x[1])
            risultati.append({
                "match": f"{team1} vs {team2}",
                "esito": best_label,
                "prob": best_prob,
                "tipo": "MULTIGOL"
            })
    risultati_ordinati = sorted(risultati, key=lambda x: x["prob"], reverse=True)[:5]
    print("\n--- TOP 5 QUOTE PIÙ PROBABILI TRA TUTTE LE FUNZIONI ---")
    for r in risultati_ordinati:
        print(f"{r['match']} - Esito: {r['esito']} - Probabilità: {r['prob']:.2%}")

# =============
# --- MAIN ---
# =============

if __name__ == "__main__":
    X, y, label_encoder, sample_weights = prepare_data()
    X_train, X_test, y_train, y_test, sw_train, sw_test = train_test_split(
        X, y, sample_weights, test_size=0.2, random_state=42
    )

    num_teams = len(label_encoder.classes_)
    model = build_model_with_embedding(num_teams)
    model = train_model(model, X_train, y_train, X_test, y_test, sw_train)

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
            scelta = inquirer.list_input(
                "MENU PREVISIONI",
                choices=[
                    "Top 5 quote più probabili",
                    "Risultato finale",
                    "Doppia chance",
                    "Over/Under 2.5",
                    "Multigol",
                    "Esci"
                ],
                default=None
            )
            if scelta == "Esci":
                print("Uscita dal programma.")
                break
            elif scelta == "Top 5 quote più probabili":
                predict_top5_probabilities(model, partite, label_encoder)
            else:
                partita_labels = [f"{team1} vs {team2}" for team1, team2 in partite]
                partita_labels.insert(0, "Visualizza tutti")
                partita_labels.append("Torna indietro")
                partita_scelta = inquirer.list_input(
                    "Scegli la partita da analizzare",
                    choices=partita_labels
                )
                if partita_scelta == "Torna indietro":
                    continue
                if partita_scelta == "Visualizza tutti":
                    print(f"\nTutte le previsioni per: {scelta}\n")
                    for team1, team2 in partite:
                        if scelta == "Risultato finale":
                            predict_result(model, team1, team2, label_encoder)
                        elif scelta == "Doppia chance":
                            predict_result_top2(model, team1, team2, label_encoder)
                        elif scelta == "Over/Under 2.5":
                            predict_over_2_5(model, team1, team2, label_encoder)
                        elif scelta == "Multigol":
                            predict_multigoal(model, team1, team2, label_encoder)
                else:
                    idx = partita_labels.index(partita_scelta) - 1  # -1 perché "Visualizza tutti" è in posizione 0
                    team1, team2 = partite[idx]
                    if scelta == "Risultato finale":
                        predict_result(model, team1, team2, label_encoder)
                    elif scelta == "Doppia chance":
                        predict_result_top2(model, team1, team2, label_encoder)
                    elif scelta == "Over/Under 2.5":
                        predict_over_2_5(model, team1, team2, label_encoder)
                    elif scelta == "Multigol":
                        predict_multigoal(model, team1, team2, label_encoder)

    menu_previsioni(model, label_encoder)