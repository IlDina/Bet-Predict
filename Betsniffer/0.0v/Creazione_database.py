import sqlite3
import os

# Verifica se il database esiste
script_dir = os.path.dirname(os.path.abspath(__file__))
db_path = os.path.join(script_dir, "matches.db")
db_exists = os.path.exists(db_path)

# Creazione della connessione e del database
conn = sqlite3.connect(db_path)
cursor = conn.cursor()

if not db_exists:
    print("Creazione del database matches.db...")

# Creazione della tabella per le partite
cursor.execute("""
CREATE TABLE IF NOT EXISTS matches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team1 TEXT,
    team2 TEXT,
    result TEXT,
    date TEXT
)
""")

# Creazione della tabella per le squadre
cursor.execute("""
CREATE TABLE IF NOT EXISTS teams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE
)
""")

# Creazione della tabella per gli scontri diretti
cursor.execute("""
CREATE TABLE IF NOT EXISTS head_to_head (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team1 TEXT,
    team2 TEXT,
    result TEXT,
    date TEXT,
    FOREIGN KEY (team1) REFERENCES teams(name),
    FOREIGN KEY (team2) REFERENCES teams(name)
)
""")

# --- NUOVO BLOCCO: Importazione dati da datapartite.txt ---
def importa_partite_da_file(filepath):
    with open(filepath, encoding="utf-8") as f:
        lines = f.readlines()
    partite = []
    squadre = set()
    for line in lines[1:]:  # Salta intestazione
        campi = line.strip().split(",")
        if len(campi) < 6:
            continue  # Salta righe non valide
        giornata, data, orario, squadra1, squadra2, risultato = campi[:6]
        # Normalizza la data (es: 17-08-2024 -> 2024-08-17)
        try:
            giorno, mese, anno = data.split("-")
            data_sql = f"{anno}-{mese.zfill(2)}-{giorno.zfill(2)}"
        except Exception:
            data_sql = data  # fallback se già in formato corretto
        partite.append((squadra1, squadra2, risultato, data_sql))
        squadre.add(squadra1)
        squadre.add(squadra2)
    return partite, squadre

# --- Importazione dati dal file ---
datafile_path = os.path.join(script_dir, "datapartite.txt")
matches, squadre = importa_partite_da_file(datafile_path)
teams = [(team,) for team in squadre]

# Inserimento squadre nel database
cursor.executemany("INSERT OR IGNORE INTO teams (name) VALUES (?)", teams)

# Inserimento delle partite nel database
cursor.executemany("INSERT INTO matches (team1, team2, result, date) VALUES (?, ?, ?, ?)", matches)

# Salvataggio e chiusura della connessione
conn.commit()
conn.close()

print("Dati inseriti nel database matches.db.")
print("Database matches.db creato con successo.")