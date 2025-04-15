import sqlite3
import os

# Verifica se il database esiste
db_exists = os.path.exists("matches.db")

# Creazione della connessione e del database
conn = sqlite3.connect("matches.db")
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

# Dati delle partite
matches = [
    ("Roma", "Lecce", "1-0", "2025-03-30"),
    ("Empoli", "Roma", "0-1", "2025-03-16"),
    ("Roma", "Cagliari", "1-0", "2025-03-09"),
    ("Parma", "Roma", "0-1", "2025-03-02"),
    ("Roma", "Como", "2-1", "2025-02-23"),
    ("Juventus", "Inter", "2-2", "2025-03-29"),
    ("Milan", "Juventus", "0-1", "2025-03-15"),
    ("Juventus", "Bologna", "3-1", "2025-03-08"),
    ("Sampdoria", "Juventus", "0-0", "2025-03-01"),
    ("Juventus", "Genoa", "2-0", "2025-02-22")
]

head_to_head = [
    ("Roma", "Juventus", "1-1", "2024-05-05"),
    ("Juventus", "Roma", "1-0", "2023-12-30"),
    ("Roma", "Juventus", "1-0", "2023-03-05")
]

# Estrazione delle squadre uniche
temp_teams = set()
for match in matches + head_to_head:
    temp_teams.add(match[0])
    temp_teams.add(match[1])
teams = [(team,) for team in temp_teams]

# Inserimento squadre nel database
cursor.executemany("INSERT OR IGNORE INTO teams (name) VALUES (?)", teams)

# Inserimento delle partite nel database
cursor.executemany("INSERT INTO matches (team1, team2, result, date) VALUES (?, ?, ?, ?)", matches)

# Inserimento degli scontri diretti
cursor.executemany("INSERT INTO head_to_head (team1, team2, result, date) VALUES (?, ?, ?, ?)", head_to_head)

# Salvataggio e chiusura della connessione
conn.commit()
conn.close()

print("Dati inseriti nel database matches.db.")
print("Database matches.db creato con successo.")