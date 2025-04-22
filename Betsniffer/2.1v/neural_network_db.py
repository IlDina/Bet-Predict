import os
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3'
os.environ['TF_ENABLE_ONEDNN_OPTS'] = '0'
import sqlite3
import warnings
import requests
warnings.filterwarnings("ignore")
import numpy as np
import tensorflow as tf
from datetime import datetime
from scipy.stats import poisson
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import LabelEncoder, StandardScaler
import inquirer


# --- CONFIGURAZIONE ---
API_KEY = "c07e985e576e40cb9c9946cd0df41abc"
HEADERS = {"X-Auth-Token": API_KEY}
DB_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "matches.db")
COMPETITIONS = [
    "SerieA", "ChampionsLeague", "PremierLeague", "Bundesliga",
    "LaLiga", "Ligue1", "Eredivisie", "PrimeiraLiga"
]
SEASONS = [2023, 2024]
N_LAST = 5

# --- FUNZIONI DATABASE E FEATURE ENGINEERING ---

def get_matches_from_db(comps, seasons):
    conn = sqlite3.connect(DB_PATH)
    c = conn.cursor()
    all_rows = []
    for comp in comps:
        for season in seasons:
            table = f"{comp}_matches_{season}"
            try:
                c.execute(f"""
                    SELECT homeTeam, awayTeam, homeScore, awayScore, matchday
                    FROM {table}
                    WHERE homeScore IS NOT NULL AND awayScore IS NOT NULL
                    ORDER BY matchday ASC
                """)
                rows = c.fetchall()
                for row in rows:
                    if row[2] is None or row[3] is None:
                        continue
                    all_rows.append((comp, season) + row)
            except sqlite3.OperationalError:
                continue
    conn.close()
    return all_rows

def get_standings_from_db(comp, season):
    conn = sqlite3.connect(DB_PATH)
    c = conn.cursor()
    table = f"{comp}_standings_{season}"
    standings = {}
    try:
        c.execute(f"""
            SELECT team_name, position
            FROM {table}
        """)
        for team_name, position in c.fetchall():
            standings[team_name] = position
    except sqlite3.OperationalError:
        pass
    conn.close()
    return standings

def build_team_stats(matches, n_last=N_LAST):
    stats = {}
    for i, (comp, season, home, away, hs, as_, md) in enumerate(matches):
        for team, gf, ga in [(home, hs, as_), (away, as_, hs)]:
            key = (comp, season, team)
            if key not in stats:
                stats[key] = {"gf": [], "ga": [], "win": []}
            stats[key]["gf"].append(gf)
            stats[key]["ga"].append(ga)
            stats[key]["win"].append(1 if gf > ga else (0.5 if gf == ga else 0))
    features = []
    for i, (comp, season, home, away, hs, as_, md) in enumerate(matches):
        home_key = (comp, season, home)
        away_key = (comp, season, away)
        home_gf = stats[home_key]["gf"][max(0, i-n_last):i]
        home_ga = stats[home_key]["ga"][max(0, i-n_last):i]
        away_gf = stats[away_key]["gf"][max(0, i-n_last):i]
        away_ga = stats[away_key]["ga"][max(0, i-n_last):i]
        home_win = stats[home_key]["win"][max(0, i-n_last):i]
        away_win = stats[away_key]["win"][max(0, i-n_last):i]
        features.append([
            comp,
            season,
            home,
            away,
            np.mean(home_gf) if home_gf else 0,
            np.mean(home_ga) if home_ga else 0,
            np.mean(away_gf) if away_gf else 0,
            np.mean(away_ga) if away_ga else 0,
            (np.mean(home_gf) if home_gf else 0) - (np.mean(home_ga) if home_ga else 0),
            (np.mean(away_gf) if away_gf else 0) - (np.mean(away_ga) if away_ga else 0),
            np.mean(home_win) if home_win else 0,
            np.mean(away_win) if away_win else 0,
            md
        ])
    return features

def convert_result_to_label(hs, as_):
    if hs > as_:
        return 0
    elif hs == as_:
        return 1
    else:
        return 2

def safe_float(x, default=0.0):
    try:
        if x is None:
            return default
        return float(x)
    except Exception:
        return default

def prepare_data(comps, seasons):
    matches = get_matches_from_db(comps, seasons)
    features = build_team_stats(matches)
    X, y, teams, comps_list = [], [], set(), set()
    for i, (comp, season, home, away, home_gf, home_ga, away_gf, away_ga, home_diff, away_diff, home_win, away_win, md) in enumerate(features):
        hs, as_ = matches[i][4], matches[i][5]
        standings_home = get_standings_from_db(comp, season)
        pos_home = standings_home.get(home, 21)
        pos_away = standings_home.get(away, 21)
        X.append([
            comp, season, home, away,
            safe_float(home_gf), safe_float(home_ga), safe_float(away_gf), safe_float(away_ga),
            safe_float(home_diff), safe_float(away_diff),
            safe_float(home_win), safe_float(away_win),
            safe_float(md), safe_float(pos_home), safe_float(pos_away)
        ])
        y.append(convert_result_to_label(hs, as_))
        teams.add(home)
        teams.add(away)
        comps_list.add(comp)
    le_team = LabelEncoder()
    le_team.fit(list(teams))
    le_comp = LabelEncoder()
    le_comp.fit(list(comps_list))
    X_enc = []
    for row in X:
        comp_enc = le_comp.transform([row[0]])[0]
        home_enc = le_team.transform([row[2]])[0]
        away_enc = le_team.transform([row[3]])[0]
        X_enc.append([comp_enc, home_enc, away_enc] + [float(x) for x in row[4:]])
    X_enc = np.array(X_enc, dtype=np.float32)
    y = np.array(y, dtype=np.int32)
    scaler = StandardScaler()
    X_enc[:, 3:] = scaler.fit_transform(X_enc[:, 3:])
    return X_enc, y, le_team, le_comp, scaler

# --- RETE NEURALE ---

def build_model(num_comps, num_teams, input_dim):
    # Modello più compatto: meno layer e meno neuroni
    input_comp = tf.keras.Input(shape=(1,))
    input_teams = tf.keras.Input(shape=(2,))
    input_stats = tf.keras.Input(shape=(input_dim-3,))

    emb_comp = tf.keras.layers.Embedding(input_dim=num_comps, output_dim=8)(input_comp)
    emb_comp = tf.keras.layers.Flatten()(emb_comp)
    emb_teams = tf.keras.layers.Embedding(input_dim=num_teams, output_dim=16)(input_teams)
    emb_teams = tf.keras.layers.Flatten()(emb_teams)

    x = tf.keras.layers.Concatenate()([emb_comp, emb_teams, input_stats])
    x = tf.keras.layers.Dense(128, activation='relu')(x)
    x = tf.keras.layers.BatchNormalization()(x)
    x = tf.keras.layers.Dropout(0.3)(x)
    x = tf.keras.layers.Dense(64, activation='relu')(x)
    x = tf.keras.layers.BatchNormalization()(x)
    x = tf.keras.layers.Dropout(0.2)(x)
    x = tf.keras.layers.Dense(32, activation='relu')(x)
    output = tf.keras.layers.Dense(3, activation='softmax')(x)

    model = tf.keras.Model(inputs=[input_comp, input_teams, input_stats], outputs=output)
    model.compile(
        optimizer=tf.keras.optimizers.Adam(learning_rate=0.0002),
        loss='sparse_categorical_crossentropy',
        metrics=['accuracy']
    )
    return model

# --- FUNZIONE DI ADDESTRAMENTO ---

def train_and_evaluate():
    X, y, le_team, le_comp, scaler = prepare_data(COMPETITIONS, SEASONS)
    X_comp = X[:, 0:1]
    X_teams = X[:, 1:3]
    X_stats = X[:, 3:]
    X_train_comp, X_test_comp, X_train_teams, X_test_teams, X_train_stats, X_test_stats, y_train, y_test = train_test_split(
        X_comp, X_teams, X_stats, y, test_size=0.2, random_state=42
    )
    model = build_model(len(le_comp.classes_), len(le_team.classes_), X.shape[1])
    early_stop = tf.keras.callbacks.EarlyStopping(monitor='val_loss', patience=8, restore_best_weights=True)
    model.fit([X_train_comp, X_train_teams, X_train_stats], y_train, epochs=50, batch_size=32, shuffle=True,
              validation_data=([X_test_comp, X_test_teams, X_test_stats], y_test), verbose=1, callbacks=[early_stop])
    loss, acc = model.evaluate([X_test_comp, X_test_teams, X_test_stats], y_test, verbose=0)
    print(f"\nAccuratezza su test: {acc:.2%}")
    return model, le_team, le_comp, scaler

# --- FUNZIONI DI PREVISIONE E MENU ---

def predict_result(model, le_team, le_comp, scaler, comp, home, away, stats, season, n_last=N_LAST, pos_home=21, pos_away=21):
    try:
        comp_enc = le_comp.transform([comp])[0]
        home_enc = le_team.transform([home])[0]
        away_enc = le_team.transform([away])[0]
    except:
        print(f"Squadra o campionato non trovato: {home}, {away}, {comp}")
        return None
    home_gf = np.mean([s[4] for s in stats if s[0] == comp and s[2] == home][-n_last:] or [0])
    home_ga = np.mean([s[5] for s in stats if s[0] == comp and s[2] == home][-n_last:] or [0])
    away_gf = np.mean([s[6] for s in stats if s[0] == comp and s[3] == away][-n_last:] or [0])
    away_ga = np.mean([s[7] for s in stats if s[0] == comp and s[3] == away][-n_last:] or [0])
    home_diff = home_gf - home_ga
    away_diff = away_gf - away_ga
    home_win = np.mean([s[10] for s in stats if s[0] == comp and s[2] == home][-n_last:] or [0])
    away_win = np.mean([s[11] for s in stats if s[0] == comp and s[3] == away][-n_last:] or [0])
    matchday = max([s[12] for s in stats if s[0] == comp], default=1) + 1
    stats_arr = np.array([[home_gf, home_ga, away_gf, away_ga, home_diff, away_diff, home_win, away_win, matchday, pos_home, pos_away]], dtype=np.float32)
    stats_arr[:, :] = scaler.transform(stats_arr)
    comp_arr = np.array([[comp_enc]], dtype=np.int32)
    teams_arr = np.array([[home_enc, away_enc]], dtype=np.int32)
    prob = model.predict([comp_arr, teams_arr, stats_arr], verbose=0)[0]
    result_map = {0: "1", 1: "X", 2: "2"}
    print(f"\n{comp}: {home} vs {away}:")
    for idx in range(3):
        print(f"  {result_map[idx]}: {prob[idx]:.2%}")
    return prob

def predict_double_chance(prob):
    idx = np.argsort(prob)[-2:][::-1]
    result_map = {0: "1", 1: "X", 2: "2"}
    res = "".join([result_map[i] for i in idx])
    prob_sum = prob[idx[0]] + prob[idx[1]]
    print(f"\nDoppia chance: {res} ({prob_sum:.2%})\n")

def predict_over_under(prob, soglia=0.5):
    prob_over = prob[0] + prob[2]
    risultato = "OVER 2.5" if prob_over > soglia else "UNDER 2.5"
    print(f"Over/Under 2.5: {risultato} ({prob_over:.2%})")

def predict_multigoal(prob, home_gf=1.3, away_gf=1.1):
    # Stima la probabilità multigol 2-4 usando Poisson
    goal_media = home_gf + away_gf
    prob_2 = poisson.pmf(2, goal_media)
    prob_3 = poisson.pmf(3, goal_media)
    prob_4 = poisson.pmf(4, goal_media)
    prob_multigol_24 = prob_2 + prob_3 + prob_4
    print(f"Multigol 2-4 stimato: ({prob_multigol_24:.2%})")

def predict_multigol_range(home_gf, away_gf, min_goal, max_goal):
    goal_media = home_gf + away_gf
    prob = sum(poisson.pmf(k, goal_media) for k in range(min_goal, max_goal + 1))
    return prob

def predict_best_multigol(prob, home_gf, away_gf):
    ranges = [
        (0, 1), (0, 2), (1, 2), (1, 3), (2, 3), (2, 4), (3, 4), (2, 5), (3, 5), (4, 6)
    ]
    best_range = None
    best_prob = 0
    for min_g, max_g in ranges:
        p = predict_multigol_range(home_gf, away_gf, min_g, max_g)
        if p > best_prob:
            best_prob = p
            best_range = (min_g, max_g)
    print(f"Multigol {best_range[0]}-{best_range[1]} più probabile: ({best_prob:.2%})")

def predict_top5_probabilities(model, le_team, le_comp, scaler, matches, stats):
    risultati = []
    for comp, home, away, season in matches:
        try:
            comp_enc = le_comp.transform([comp])[0]
            home_enc = le_team.transform([home])[0]
            away_enc = le_team.transform([away])[0]
        except:
            continue
        home_gf = np.mean([s[4] for s in stats if s[0] == comp and s[2] == home][-N_LAST:] or [0])
        home_ga = np.mean([s[5] for s in stats if s[0] == comp and s[2] == home][-N_LAST:] or [0])
        away_gf = np.mean([s[6] for s in stats if s[0] == comp and s[3] == away][-N_LAST:] or [0])
        away_ga = np.mean([s[7] for s in stats if s[0] == comp and s[3] == away][-N_LAST:] or [0])
        home_diff = home_gf - home_ga
        away_diff = away_gf - away_ga
        home_win = np.mean([s[10] for s in stats if s[0] == comp and s[2] == home][-N_LAST:] or [0])
        away_win = np.mean([s[11] for s in stats if s[0] == comp and s[3] == away][-N_LAST:] or [0])
        matchday = max([s[12] for s in stats if s[0] == comp], default=1) + 1
        standings = get_standings_from_db(comp, season)
        pos_home = standings.get(home, 21)
        pos_away = standings.get(away, 21)
        stats_arr = np.array([[home_gf, home_ga, away_gf, away_ga, home_diff, away_diff, home_win, away_win, matchday, pos_home, pos_away]], dtype=np.float32)
        stats_arr[:, :] = scaler.transform(stats_arr)
        comp_arr = np.array([[comp_enc]], dtype=np.int32)
        teams_arr = np.array([[home_enc, away_enc]], dtype=np.int32)
        prob = model.predict([comp_arr, teams_arr, stats_arr], verbose=0)[0]
        result_map = {0: "1", 1: "X", 2: "2"}
        for idx, p in enumerate(prob):
            risultati.append({
                "match": f"{home} vs {away}",
                "esito": result_map[idx],
                "prob": p,
                "tipo": "1X2"
            })
        prob_over = prob[0] + prob[2]
        risultati.append({
            "match": f"{home} vs {away}",
            "esito": "OVER 2.5",
            "prob": prob_over,
            "tipo": "OVER/UNDER"
        })
        top2_idx = np.argsort(prob)[-2:][::-1]
        top2_results = "".join([result_map[i] for i in top2_idx])
        prob_top2 = prob[top2_idx[0]] + prob[top2_idx[1]]
        risultati.append({
            "match": f"{home} vs {away}",
            "esito": f"Doppia chance {top2_results}",
            "prob": prob_top2,
            "tipo": "DOPPIA CHANCE"
        })
        prob_multigol_24 = prob[0] + prob[1] + prob[2]
        risultati.append({
            "match": f"{home} vs {away}",
            "esito": "Multigol 2-4 (stimato)",
            "prob": prob_multigol_24,
            "tipo": "MULTIGOL"
        })
    risultati_ordinati = sorted(risultati, key=lambda x: x["prob"], reverse=True)[:5]
    print("\n--- TOP 5 QUOTE PIÙ PROBABILI ---")
    for r in risultati_ordinati:
        print(f"{r['match']} - {r['esito']} ({r['prob']:.2%})")

def menu_previsioni(model, le_team, le_comp, scaler, next_matches, stats):
    menu_voci = [
        "Top 5 quote più probabili",
        "Risultato finale",
        "Doppia chance",
        "Over/Under 2.5",
        "Multigol migliore",
        "Esci"
    ]
    while True:
        print("\nMENU PREVISIONI")
        domanda = [
            inquirer.List(
                "scelta",
                message="Scegli un'opzione",
                choices=menu_voci
            )
        ]
        risposta = inquirer.prompt(domanda)
        if risposta is None or risposta["scelta"] == "Esci":
            print("Uscita dal programma.")
            break
        scelta = risposta["scelta"]
        if scelta == "Top 5 quote più probabili":
            predict_top5_probabilities(model, le_team, le_comp, scaler, next_matches, stats)
        else:
            partita_labels = [f"{comp} - {team1} vs {team2}" for comp, team1, team2, season in next_matches]
            partita_labels.insert(0, "Visualizza tutti")
            partita_labels.append("Torna indietro")
            domanda_partita = [
                inquirer.List(
                    "partita",
                    message="Scegli la partita da analizzare",
                    choices=partita_labels
                )
            ]
            risposta_partita = inquirer.prompt(domanda_partita)
            if risposta_partita is None or risposta_partita["partita"] == "Torna indietro":
                continue
            partita_scelta = risposta_partita["partita"]
            if partita_scelta == "Visualizza tutti":
                print(f"\nTutte le previsioni per: {scelta}\n")
                for comp, team1, team2, season in next_matches:
                    standings = get_standings_from_db(comp, season)
                    pos_home = standings.get(team1, 21)
                    pos_away = standings.get(team2, 21)
                    prob = predict_result(model, le_team, le_comp, scaler, comp, team1, team2, stats, season, pos_home=pos_home, pos_away=pos_away)
                    if prob is not None:
                        home_gf = np.mean([s[4] for s in stats if s[0] == comp and s[2] == team1][-N_LAST:] or [0])
                        away_gf = np.mean([s[6] for s in stats if s[0] == comp and s[3] == team2][-N_LAST:] or [0])
                        if scelta == "Risultato finale":
                            result_map = {0: "1", 1: "X", 2: "2"}
                            pred = np.argmax(prob)
                            print(f"\nPredizione per {team1} vs {team2}:")
                            print(f"Risultato più probabile: {result_map[pred]}")
                            print(f"Probabilità: 1({prob[0]*100:.2f}%) X({prob[1]*100:.2f}%) 2({prob[2]*100:.2f}%)\n")
                        elif scelta == "Doppia chance":
                            idx = np.argsort(prob)[-2:][::-1]
                            result_map = {0: "1", 1: "X", 2: "2"}
                            res = "".join([result_map[i] for i in idx])
                            prob_sum = prob[idx[0]] + prob[idx[1]]
                            print(f"\nDoppia chance per {team1} vs {team2}: {res} (Probabilità combinata: {prob_sum*100:.2f}%)")
                            print(f"Dettaglio: 1({prob[0]*100:.2f}%) X({prob[1]*100:.2f}%) 2({prob[2]*100:.2f}%)\n")
                        elif scelta == "Over/Under 2.5":
                            prob_over = prob[0] + prob[2]
                            risultato = "OVER 2.5" if prob_over > 0.5 else "UNDER 2.5"
                            print(f"\nPrevisione OVER/UNDER per {team1} vs {team2}: {risultato} (Prob. OVER stimata: {prob_over*100:.2f}%)\n")
                        elif scelta == "Multigol migliore":
                            ranges = [
                                (0, 1), (0, 2), (1, 2), (1, 3), (2, 3), (2, 4), (3, 4), (2, 5), (3, 5), (4, 6)
                            ]
                            best_range = None
                            best_prob = 0
                            for min_g, max_g in ranges:
                                p = predict_multigol_range(home_gf, away_gf, min_g, max_g)
                                if p > best_prob:
                                    best_prob = p
                                    best_range = (min_g, max_g)
                            print(f"\n{team1} vs {team2}:")
                            print(f"Multigol più probabile: {best_range[0]}-{best_range[1]} (Probabilità: {best_prob*100:.2f}%)\n")
            else:
                idx = partita_labels.index(partita_scelta) - 1
                comp, team1, team2, season = next_matches[idx]
                standings = get_standings_from_db(comp, season)
                pos_home = standings.get(team1, 21)
                pos_away = standings.get(team2, 21)
                prob = predict_result(model, le_team, le_comp, scaler, comp, team1, team2, stats, season, pos_home=pos_home, pos_away=pos_away)
                if prob is not None:
                    home_gf = np.mean([s[4] for s in stats if s[0] == comp and s[2] == team1][-N_LAST:] or [0])
                    away_gf = np.mean([s[6] for s in stats if s[0] == comp and s[3] == team2][-N_LAST:] or [0])
                    if scelta == "Risultato finale":
                        result_map = {0: "1", 1: "X", 2: "2"}
                        pred = np.argmax(prob)
                        print(f"\nPredizione per {team1} vs {team2}:")
                        print(f"Risultato più probabile: {result_map[pred]}")
                        print(f"Probabilità: 1({prob[0]*100:.2f}%) X({prob[1]*100:.2f}%) 2({prob[2]*100:.2f}%)\n")
                    elif scelta == "Doppia chance":
                        idx = np.argsort(prob)[-2:][::-1]
                        result_map = {0: "1", 1: "X", 2: "2"}
                        res = "".join([result_map[i] for i in idx])
                        prob_sum = prob[idx[0]] + prob[idx[1]]
                        print(f"\nDoppia chance per {team1} vs {team2}: {res} (Probabilità combinata: {prob_sum*100:.2f}%)")
                        print(f"Dettaglio: 1({prob[0]*100:.2f}%) X({prob[1]*100:.2f}%) 2({prob[2]*100:.2f}%)\n")
                    elif scelta == "Over/Under 2.5":
                        prob_over = prob[0] + prob[2]
                        risultato = "OVER 2.5" if prob_over > 0.5 else "UNDER 2.5"
                        print(f"\nPrevisione OVER/UNDER per {team1} vs {team2}: {risultato} (Prob. OVER stimata: {prob_over*100:.2f}%)\n")
                    elif scelta == "Multigol migliore":
                        ranges = [
                            (0, 1), (0, 2), (1, 2), (1, 3), (2, 3), (2, 4), (3, 4), (2, 5), (3, 5), (4, 6)
                        ]
                        best_range = None
                        best_prob = 0
                        for min_g, max_g in ranges:
                            p = predict_multigol_range(home_gf, away_gf, min_g, max_g)
                            if p > best_prob:
                                best_prob = p
                                best_range = (min_g, max_g)
                        print(f"\n{team1} vs {team2}:")
                        print(f"Multigol più probabile: {best_range[0]}-{best_range[1]} (Probabilità: {best_prob*100:.2f}%)\n")

def get_next_matches_from_api(competitions, season):
    # Prende solo le partite di oggi dalle API Football-Data
    next_matches = []
    today = datetime.now().date()
    for comp in competitions:
        code_map = {
            "SerieA": "SA",
            "ChampionsLeague": "CL",
            "PremierLeague": "PL",
            "Bundesliga": "BL1",
            "LaLiga": "PD",
            "Ligue1": "FL1",
            "Eredivisie": "DED",
            "PrimeiraLiga": "PPL"
        }
        code = code_map.get(comp)
        if not code:
            continue
        url = f"https://api.football-data.org/v4/competitions/{code}/matches?season={season}&status=SCHEDULED"
        try:
            resp = requests.get(url, headers=HEADERS, timeout=10)
            if resp.status_code == 200:
                data = resp.json()
                for match in data.get("matches", []):
                    utc_date = match.get("utcDate")
                    if utc_date:
                        match_date = datetime.fromisoformat(utc_date.replace("Z", "+00:00")).date()
                        if match_date == today:
                            home = match["homeTeam"]["name"]
                            away = match["awayTeam"]["name"]
                            next_matches.append((comp, home, away, season))
            else:
                print(f"Errore API {comp}: {resp.status_code}")
        except Exception as e:
            print(f"Errore richiesta API {comp}: {e}")
    return next_matches

# --- MAIN ---

def main():
    model, le_team, le_comp, scaler = train_and_evaluate()
    next_matches = get_next_matches_from_api(COMPETITIONS, 2024)
    if not next_matches:
        print("Nessuna partita trovata dalle API.")
        return
    stats = build_team_stats(get_matches_from_db(COMPETITIONS, SEASONS))
    menu_previsioni(model, le_team, le_comp, scaler, next_matches, stats)

if __name__ == "__main__":
    main()