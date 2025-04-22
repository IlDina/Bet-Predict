import os
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3'
os.environ['TF_ENABLE_ONEDNN_OPTS'] = '0'
import sys
import warnings
warnings.filterwarnings("ignore")
import numpy as np
import tensorflow as tf
import requests
from sklearn.preprocessing import LabelEncoder
from sklearn.model_selection import train_test_split

API_KEY = "c07e985e576e40cb9c9946cd0df41abc"
API_URL = "https://api.football-data.org/v4/competitions/SA/matches?season=2024"
HEADERS = {"X-Auth-Token": API_KEY}

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

def fetch_api_matches():
    url = API_URL
    response = requests.get(url, headers=HEADERS)
    response.raise_for_status()
    return response.json()["matches"]

def get_partite_from_api():
    matches = fetch_api_matches()
    giornate = [match.get("matchday") for match in matches if match.get("matchday") is not None]
    if not giornate:
        print("Nessuna giornata disponibile nei dati.")
        return []
    giornata_corrente = max(giornate)
    partite = []
    for match in matches:
        if match.get("matchday") == giornata_corrente:
            team1 = match["homeTeam"]["name"]
            team2 = match["awayTeam"]["name"]
            partite.append((team1, team2))
    if not partite:
        print("Nessuna partita trovata per la giornata corrente.")
    return partite

def prepare_data():
    data = []
    for match in fetch_api_matches():
        score = match.get("score", {}).get("fullTime", {})
        home_score = score.get("home")
        away_score = score.get("away")
        if home_score is not None and away_score is not None:
            result = f"{home_score}-{away_score}"
            team1 = match["homeTeam"]["name"]
            team2 = match["awayTeam"]["name"]
            date = match.get("utcDate", "")
            data.append((team1, team2, result, date))
    if not data:
        print("Nessun dato valido trovato nell'API.")
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
    model.fit(
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
    try:
        team1_enc = label_encoder.transform([team1])[0]
        team2_enc = label_encoder.transform([team2])[0]
    except Exception:
        print(f"Squadra non trovata: {team1} o {team2}")
        return None
    input_arr = np.array([[team1_enc, team2_enc]])
    probabilities = model.predict(input_arr, verbose=0)[0]
    prediction = np.argmax(probabilities)
    result_map = {0: "1", 1: "X", 2: "2"}
    result = result_map[prediction]
    print(f"\n{team1} vs {team2}: {result} ({probabilities[prediction]:.2%})\n")
    return result

def predict_result_top2(model, team1, team2, label_encoder):
    try:
        team1_enc = label_encoder.transform([team1])[0]
        team2_enc = label_encoder.transform([team2])[0]
    except Exception:
        print(f"Squadra non trovata: {team1} o {team2}")
        return None
    input_arr = np.array([[team1_enc, team2_enc]])
    probabilities = model.predict(input_arr, verbose=0)[0]
    result_map = {0: "1", 1: "X", 2: "2"}
    top2_idx = np.argsort(probabilities)[-2:][::-1]
    top2_results = "".join([result_map[i] for i in top2_idx])
    prob_sum = probabilities[top2_idx[0]] + probabilities[top2_idx[1]]
    print(f"\n{team1} vs {team2}: doppia chance {top2_results} ({prob_sum:.2%})\n")
    return top2_results, prob_sum

def predict_over_2_5(model, team1, team2, label_encoder, soglia=0.5):
    try:
        team1_enc = label_encoder.transform([team1])[0]
        team2_enc = label_encoder.transform([team2])[0]
    except Exception:
        print(f"Squadra non trovata: {team1} o {team2}")
        return None
    input_arr = np.array([[team1_enc, team2_enc]])
    probabilities = model.predict(input_arr, verbose=0)[0]
    prob_over = probabilities[0] + probabilities[2]
    risultato = "OVER 2.5" if prob_over > soglia else "UNDER 2.5"
    print(f"\n{team1} vs {team2}: {risultato} ({prob_over:.2%})\n")
    return risultato

def predict_multigoal(model, team1, team2, label_encoder):
    try:
        team1_enc = label_encoder.transform([team1])[0]
        team2_enc = label_encoder.transform([team2])[0]
    except Exception:
        print(f"Squadra non trovata: {team1} o {team2}")
        return None
    input_arr = np.array([[team1_enc, team2_enc]])
    probabilities = model.predict(input_arr, verbose=0)[0]
    prob_multigol_24 = probabilities[0] + probabilities[1] + probabilities[2]
    print(f"\n{team1} vs {team2}: Multigol 2-4 stimato ({prob_multigol_24:.2%})\n")
    return prob_multigol_24

def predict_top5_probabilities(model, partite, label_encoder):
    risultati = []
    for team1, team2 in partite:
        try:
            team1_enc = label_encoder.transform([team1])[0]
            team2_enc = label_encoder.transform([team2])[0]
        except Exception:
            continue
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
        risultati.append({
            "match": f"{team1} vs {team2}",
            "esito": "OVER 2.5",
            "prob": prob_over,
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
        prob_multigol_24 = probabilities[0] + probabilities[1] + probabilities[2]
        risultati.append({
            "match": f"{team1} vs {team2}",
            "esito": "Multigol 2-4 (stimato)",
            "prob": prob_multigol_24,
            "tipo": "MULTIGOL"
        })
    risultati_ordinati = sorted(risultati, key=lambda x: x["prob"], reverse=True)[:5]
    print("\n--- TOP 5 QUOTE PIÙ PROBABILI ---")
    for r in risultati_ordinati:
        print(f"{r['match']} - {r['esito']} ({r['prob']:.2%})")

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

    # Previsioni solo per le partite richieste (nomi esatti API)
    partite_api = [
        ("US Lecce", "Como 1907"),
        ("AC Monza", "SSC Napoli"),
        ("AS Roma", "Hellas Verona FC"),
        ("Empoli FC", "Venezia FC"),
        ("Bologna FC 1909", "FC Internazionale Milano"),
        ("AC Milan", "Atalanta BC"),
        ("Torino FC", "Udinese Calcio"),
        ("Cagliari Calcio", "ACF Fiorentina"),
        ("Genoa CFC", "SS Lazio"),
        ("Parma Calcio 1913", "Juventus FC"),
        ("Como 1907", "Genoa CFC"),
    ]

    # MENU TESTUALE SEMPLICE
    if not partite_api:
        print("Nessuna partita disponibile per la giornata corrente.")
        sys.exit(0)

    menu_voci = [
        "Top 5 quote più probabili",
        "Risultato finale",
        "Doppia chance",
        "Over/Under 2.5",
        "Multigol",
        "Esci"
    ]

    while True:
        print("\nMENU PREVISIONI")
        for i, voce in enumerate(menu_voci, 1):
            print(f"{i}) {voce}")
        try:
            scelta_idx = int(input("Scegli un'opzione: "))
            scelta = menu_voci[scelta_idx - 1]
        except (ValueError, IndexError):
            print("Scelta non valida.")
            continue

        if scelta == "Esci":
            print("Uscita dal programma.")
            break
        elif scelta == "Top 5 quote più probabili":
            predict_top5_probabilities(model, partite_api, label_encoder)
        else:
            partita_labels = [f"{team1} vs {team2}" for team1, team2 in partite_api]
            partita_labels.insert(0, "Visualizza tutti")
            partita_labels.append("Torna indietro")
            print("\nScegli la partita da analizzare:")
            for i, label in enumerate(partita_labels, 1):
                print(f"{i}) {label}")
            try:
                partita_idx = int(input("Numero partita: "))
                partita_scelta = partita_labels[partita_idx - 1]
            except (ValueError, IndexError):
                print("Scelta non valida.")
                continue
            if partita_scelta == "Torna indietro":
                continue
            if partita_scelta == "Visualizza tutti":
                print(f"\nTutte le previsioni per: {scelta}\n")
                for team1, team2 in partite_api:
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
                team1, team2 = partite_api[idx]
                if scelta == "Risultato finale":
                    predict_result(model, team1, team2, label_encoder)
                elif scelta == "Doppia chance":
                    predict_result_top2(model, team1, team2, label_encoder)
                elif scelta == "Over/Under 2.5":
                    predict_over_2_5(model, team1, team2, label_encoder)
                elif scelta == "Multigol":
                    predict_multigoal(model, team1, team2, label_encoder)