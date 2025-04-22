import os
import sqlite3
import requests
import time

#aggiugere quote
API_KEY = "c07e985e576e40cb9c9946cd0df41abc"
HEADERS = {"X-Auth-Token": API_KEY}
DB_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "matches.db")

COMPETITIONS = {
    "CL": "ChampionsLeague",
    "SA": "SerieA",
    "PL": "PremierLeague",
    "BL1": "Bundesliga",
    "PD": "LaLiga",
    "FL1": "Ligue1",
    "DED": "Eredivisie",
    "PPL": "PrimeiraLiga"
}

SEASONS = [2023, 2024]

def fetch_api(url, max_retries=5):
    for attempt in range(max_retries):
        response = requests.get(url, headers=HEADERS)
        if response.status_code == 429:
            print("Limite richieste raggiunto. Attendo 60 secondi...")
            time.sleep(60)
            continue
        if response.status_code == 403:
            print(f"Accesso negato (403) per questa risorsa: {url}")
            return None
        if response.status_code == 404:
            print(f"Risorsa non trovata (404) per questa risorsa: {url}")
            return None
        response.raise_for_status()
        return response.json()
    raise Exception("Troppe richieste: riprova più tardi.")

def create_db():
    conn = sqlite3.connect(DB_PATH)
    c = conn.cursor()
    for year in SEASONS:
        for comp in COMPETITIONS.values():
            # Matches
            c.execute(f"""
                CREATE TABLE IF NOT EXISTS {comp}_matches_{year} (
                    id INTEGER PRIMARY KEY,
                    utcDate TEXT,
                    matchday INTEGER,
                    status TEXT,
                    homeTeam TEXT,
                    awayTeam TEXT,
                    homeScore INTEGER,
                    awayScore INTEGER,
                    winner TEXT
                )
            """)
            # Standings
            c.execute(f"""
                CREATE TABLE IF NOT EXISTS {comp}_standings_{year} (
                    position INTEGER,
                    team_id INTEGER,
                    team_name TEXT,
                    playedGames INTEGER,
                    won INTEGER,
                    draw INTEGER,
                    lost INTEGER,
                    points INTEGER,
                    goalsFor INTEGER,
                    goalsAgainst INTEGER,
                    goalDifference INTEGER,
                    PRIMARY KEY (position, team_id)
                )
            """)
            # Teams
            c.execute(f"""
                CREATE TABLE IF NOT EXISTS {comp}_teams_{year} (
                    id INTEGER PRIMARY KEY,
                    name TEXT,
                    tla TEXT,
                    shortName TEXT,
                    areaName TEXT,
                    venue TEXT
                )
            """)
            # Scorers
            c.execute(f"""
                CREATE TABLE IF NOT EXISTS {comp}_scorers_{year} (
                    player_id INTEGER,
                    player_name TEXT,
                    team_id INTEGER,
                    team_name TEXT,
                    goals INTEGER,
                    assists INTEGER,
                    playedMatches INTEGER,
                    PRIMARY KEY (player_id, team_id)
                )
            """)
            # Competition Info
            c.execute(f"""
                CREATE TABLE IF NOT EXISTS {comp}_info_{year} (
                    id INTEGER PRIMARY KEY,
                    name TEXT,
                    code TEXT,
                    areaName TEXT,
                    plan TEXT,
                    numberOfAvailableSeasons INTEGER,
                    lastUpdated TEXT
                )
            """)
    conn.commit()
    conn.close()

def insert_matches(matches, comp, year):
    conn = sqlite3.connect(DB_PATH)
    c = conn.cursor()
    for m in matches:
        match_id = m["id"]
        utcDate = m["utcDate"]
        matchday = m.get("matchday")
        status = m.get("status")
        homeTeam = m["homeTeam"]["name"]
        awayTeam = m["awayTeam"]["name"]
        score = m.get("score", {})
        fullTime = score.get("fullTime", {})
        homeScore = fullTime.get("home")
        awayScore = fullTime.get("away")
        winner = score.get("winner")
        c.execute(f"""
            INSERT OR REPLACE INTO {comp}_matches_{year}
            (id, utcDate, matchday, status, homeTeam, awayTeam, homeScore, awayScore, winner)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        """, (match_id, utcDate, matchday, status, homeTeam, awayTeam, homeScore, awayScore, winner))
    conn.commit()
    conn.close()

def insert_standings(standings, comp, year):
    conn = sqlite3.connect(DB_PATH)
    c = conn.cursor()
    c.execute(f"DELETE FROM {comp}_standings_{year}")
    for table in standings:
        if table["type"] != "TOTAL":
            continue
        for entry in table["table"]:
            position = entry["position"]
            team_id = entry["team"]["id"]
            team_name = entry["team"]["name"]
            playedGames = entry["playedGames"]
            won = entry["won"]
            draw = entry["draw"]
            lost = entry["lost"]
            points = entry["points"]
            goalsFor = entry["goalsFor"]
            goalsAgainst = entry["goalsAgainst"]
            goalDifference = entry["goalDifference"]
            c.execute(f"""
                INSERT OR REPLACE INTO {comp}_standings_{year}
                (position, team_id, team_name, playedGames, won, draw, lost, points, goalsFor, goalsAgainst, goalDifference)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, (position, team_id, team_name, playedGames, won, draw, lost, points, goalsFor, goalsAgainst, goalDifference))
    conn.commit()
    conn.close()

def insert_teams(teams, comp, year):
    conn = sqlite3.connect(DB_PATH)
    c = conn.cursor()
    for t in teams:
        c.execute(f"""
            INSERT OR REPLACE INTO {comp}_teams_{year}
            (id, name, tla, shortName, areaName, venue)
            VALUES (?, ?, ?, ?, ?, ?)
        """, (
            t["id"], t["name"], t.get("tla"), t.get("shortName"),
            t["area"]["name"] if "area" in t else None, t.get("venue")
        ))
    conn.commit()
    conn.close()

def insert_scorers(scorers, comp, year):
    conn = sqlite3.connect(DB_PATH)
    c = conn.cursor()
    for s in scorers:
        player = s["player"]
        team = s["team"]
        c.execute(f"""
            INSERT OR REPLACE INTO {comp}_scorers_{year}
            (player_id, player_name, team_id, team_name, goals, assists, playedMatches)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        """, (
            player["id"], player["name"], team["id"], team["name"],
            s.get("goals"), s.get("assists"), s.get("playedMatches")
        ))
    conn.commit()
    conn.close()

def insert_competition_info(info, comp, year):
    conn = sqlite3.connect(DB_PATH)
    c = conn.cursor()
    c.execute(f"""
        INSERT OR REPLACE INTO {comp}_info_{year}
        (id, name, code, areaName, plan, numberOfAvailableSeasons, lastUpdated)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    """, (
        info["id"], info["name"], info.get("code"),
        info["area"]["name"] if "area" in info else None,
        info.get("plan"), info.get("numberOfAvailableSeasons"), info.get("lastUpdated")
    ))
    conn.commit()
    conn.close()

if __name__ == "__main__":
    create_db()
    for year in SEASONS:
        for code, comp in COMPETITIONS.items():
            print(f"\n--- {comp} {year} ---")
            url = f"https://api.football-data.org/v4/competitions/{code}/matches?season={year}"
            data = fetch_api(url)
            matches = data.get("matches", []) if data else []
            print(f"Partite trovate: {len(matches)}")
            if matches:
                insert_matches(matches, comp, year)
            if code != "CL":
                url = f"https://api.football-data.org/v4/competitions/{code}/standings?season={year}"
                data = fetch_api(url)
                standings = data.get("standings", []) if data else []
                print(f"Standings trovate: {len(standings)}")
                if standings:
                    insert_standings(standings, comp, year)
            # Teams
            url = f"https://api.football-data.org/v4/competitions/{code}/teams?season={year}"
            data = fetch_api(url)
            teams = data.get("teams", []) if data else []
            print(f"Squadre trovate: {len(teams)}")
            if teams:
                insert_teams(teams, comp, year)
            # Scorers
            url = f"https://api.football-data.org/v4/competitions/{code}/scorers?season={year}"
            data = fetch_api(url)
            scorers = data.get("scorers", []) if data else []
            print(f"Marcatori trovati: {len(scorers)}")
            if scorers:
                insert_scorers(scorers, comp, year)
            # Competition Info
            url = f"https://api.football-data.org/v4/competitions/{code}"
            info = fetch_api(url)
            if info:
                print("Info competizione salvate.")
                insert_competition_info(info, comp, year)
    print(f"\nFatto! Tutti i dati sono stati salvati in {DB_PATH}")