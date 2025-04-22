<?php
session_start();

// Set timezone to Europe/Rome
ini_set('date.timezone', 'Europe/Rome');
date_default_timezone_set('Europe/Rome');

// Verifica se l'utente è loggato come admin
if (!isset($_SESSION["admin_logged_in"]) || $_SESSION["admin_logged_in"] !== true) {
    header("Location: admin-login.php");
    exit();
}

// Database connection
$db_file = __DIR__ . "/utentieadminsito.db";

try {
    $conn = new PDO("sqlite:$db_file");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Errore di connessione al database: " . $e->getMessage());
}

// Crea la tabella predictions se non esiste
$conn->exec("
    CREATE TABLE IF NOT EXISTS predictions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sport TEXT NOT NULL,
        league TEXT NOT NULL,
        match TEXT NOT NULL,
        prediction TEXT NOT NULL,
        odds DECIMAL(4,2) NOT NULL,
        type TEXT NOT NULL DEFAULT 'free',
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

// Gestione delle azioni
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST["add_prediction"])) {
        try {
            // Verifica che tutti i campi richiesti siano presenti
            $required_fields = ['sport', 'league', 'match', 'prediction', 'odds', 'type'];
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Il campo " . ucfirst($field) . " è obbligatorio");
                }
            }

            // Verifica che la quota sia un numero valido
            if (!is_numeric($_POST['odds']) || $_POST['odds'] <= 0) {
                throw new Exception("La quota deve essere un numero maggiore di 0");
            }

            // Sanitize input
            $sport = ucfirst(htmlspecialchars(trim($_POST["sport"])));
            $league = htmlspecialchars(trim($_POST["league"]));
            $match = htmlspecialchars(trim($_POST["match"]));
            $prediction = htmlspecialchars(trim($_POST["prediction"]));
            $odds = floatval($_POST["odds"]);
            $type = htmlspecialchars(trim($_POST["type"]));

            // Verifica che il tipo sia valido
            if (!in_array($type, ['free', 'premium'])) {
                throw new Exception("Tipo di pronostico non valido");
            }

            // Inserisci il nuovo pronostico
            $stmt = $conn->prepare("
                INSERT INTO predictions (sport, league, match, prediction, odds, type, status)
                VALUES (:sport, :league, :match, :prediction, :odds, :type, 'pending')
            ");
            
            $result = $stmt->execute([
                ':sport' => $sport,
                ':league' => $league,
                ':match' => $match,
                ':prediction' => $prediction,
                ':odds' => $odds,
                ':type' => $type
            ]);

            if (!$result) {
                throw new Exception("Errore durante l'inserimento del pronostico");
            }
            
            // Reindirizza per evitare il re-invio del form
            header("Location: admin-activities.php?success=1");
            exit();
        } catch(Exception $e) {
            $error = $e->getMessage();
        }
    } elseif (isset($_POST["update_status"])) {
        $stmt = $conn->prepare("
            UPDATE predictions 
            SET status = :status
            WHERE id = :id
        ");
        $stmt->execute([
            ':status' => $_POST["status"],
            ':id' => $_POST["prediction_id"]
        ]);
        
        header("Location: admin-activities.php");
        exit();
    } elseif (isset($_POST["reset_active"])) {
        try {
            $stmt = $conn->prepare("DELETE FROM predictions WHERE status = 'pending'");
            $stmt->execute();
            header("Location: admin-activities.php?reset=active");
            exit();
        } catch(PDOException $e) {
            $error = "Errore durante il reset dei pronostici attivi";
        }
    } elseif (isset($_POST["reset_completed"])) {
        try {
            $stmt = $conn->prepare("DELETE FROM predictions WHERE status IN ('won', 'lost')");
            $stmt->execute();
            header("Location: admin-activities.php?reset=completed");
            exit();
        } catch(PDOException $e) {
            $error = "Errore durante il reset dei pronostici completati";
        }
    }
}

// Recupera i pronostici attivi
$stmt = $conn->prepare("SELECT * FROM predictions WHERE status = 'pending' ORDER BY created_at DESC");
$stmt->execute();
$active_predictions = $stmt->fetchAll();

// Recupera i pronostici completati
$stmt = $conn->prepare("SELECT * FROM predictions WHERE status IN ('won', 'lost') ORDER BY created_at DESC LIMIT 10");
$stmt->execute();
$completed_predictions = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attività - BetPredict</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #2ecc71;
            --danger-color: #e74c3c;
            --warning-color: #f1c40f;
            --dark-bg: #1a1a1a;
            --light-bg: #2d2d2d;
            --text-color: #ffffff;
            --text-muted: #b3b3b3;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: var(--dark-bg);
            color: var(--text-color);
            min-height: 100vh;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 250px;
            background-color: var(--light-bg);
            padding: 1.5rem;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
        }

        .sidebar-header img {
            width: 40px;
            margin-right: 1rem;
        }

        .sidebar-header h1 {
            font-size: 1.2rem;
            color: var(--text-color);
        }

        .nav-menu {
            list-style: none;
        }

        .nav-item {
            margin-bottom: 0.5rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.8rem 1rem;
            color: var(--text-muted);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .nav-link:hover, .nav-link.active {
            background-color: var(--primary-color);
            color: var(--text-color);
        }

        .nav-link i {
            margin-right: 1rem;
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .logout-btn {
            background-color: var(--danger-color);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background-color: #c0392b;
        }

        /* Form */
        .form-container {
            background-color: var(--light-bg);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .form-title {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-title i {
            color: var(--secondary-color);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .form-input {
            width: 100%;
            padding: 0.8rem;
            background-color: var(--dark-bg);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            color: var(--text-color);
            font-size: 0.95rem;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        .submit-btn {
            background-color: var(--success-color);
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .submit-btn:hover {
            background-color: #27ae60;
        }

        /* Prediction Cards */
        .predictions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .prediction-card {
            position: relative;
            background: linear-gradient(145deg, #2d2d2d, #1a1a1a);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .prediction-card::after {
            content: '';
            position: absolute;
            top: -20px;
            right: -20px;
            width: 40px;
            height: 40px;
            background: transparent;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .prediction-card.won::after {
            background: #2ecc71;
        }

        .prediction-card.lost::after {
            background: #e74c3c;
        }

        .prediction-header {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .prediction-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-color);
        }

        .prediction-meta {
            display: flex;
            gap: 0.5rem;
        }

        .prediction-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .badge-free {
            background-color: rgba(52, 152, 219, 0.2);
            color: var(--secondary-color);
        }

        .badge-premium {
            background-color: rgba(241, 196, 15, 0.2);
            color: var(--warning-color);
        }

        .status-indicator {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 1.5rem;
        }

        .status-indicator.won {
            color: var(--success-color);
        }

        .status-indicator.lost {
            color: var(--danger-color);
        }

        .prediction-details {
            position: relative;
            z-index: 1;
            margin-bottom: 1rem;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            padding: 0.5rem;
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: 6px;
        }

        .detail-label {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .detail-value {
            color: var(--text-color);
            font-weight: 500;
        }

        .prediction-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .action-btn {
            flex: 1;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            color: white;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .action-btn.won {
            background-color: var(--success-color);
        }

        .action-btn.lost {
            background-color: var(--danger-color);
        }

        .action-btn:hover {
            opacity: 0.9;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: var(--secondary-color);
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--secondary-color);
        }

        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-danger {
            background-color: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
        }

        .alert-success {
            background-color: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }

        .alert i {
            font-size: 1.2rem;
        }

        .info-icon {
            position: absolute;
            top: 1.5rem;
            left: 50%;
            transform: translateX(-50%);
            color: var(--secondary-color);
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1.2rem;
            z-index: 2;
        }

        .info-icon:hover {
            transform: translateX(-50%) scale(1.1);
            color: var(--text-color);
        }

        .time-info {
            position: absolute;
            top: 3.5rem;
            right: 1.5rem;
            display: none;
            flex-direction: column;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: var(--text-muted);
            background: rgba(0, 0, 0, 0.9);
            padding: 0.8rem;
            border-radius: 8px;
            z-index: 3;
            min-width: 200px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .time-info.show {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .time-info i {
            margin-right: 0.5rem;
            color: var(--secondary-color);
            width: 16px;
            text-align: center;
        }

        .time-info .update-time {
            color: var(--text-color);
            font-weight: 500;
            margin-top: 0.3rem;
            padding-top: 0.3rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sport-selector {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .sport-option {
            flex: 1;
            padding: 0.5rem;
            background: var(--light-bg);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            border: 2px solid transparent;
        }

        .sport-option:hover {
            background: var(--primary-color);
        }

        .sport-option.active {
            background: var(--primary-color);
            border-color: var(--secondary-color);
        }

        .sport-option i {
            font-size: 1.5rem;
            margin-bottom: 0.25rem;
            color: var(--secondary-color);
        }

        .prediction-form {
            display: none;
        }

        .prediction-form.active {
            display: block;
        }

        .form-section {
            background: var(--light-bg);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .form-section-title {
            font-size: 1rem;
            color: var(--text-color);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-section-title i {
            font-size: 1rem;
            color: var(--secondary-color);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-label {
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .form-input {
            padding: 0.5rem;
            font-size: 0.9rem;
        }

        .submit-btn {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }

        .reset-section {
            background: var(--light-bg);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .reset-title {
            font-size: 1.1rem;
            color: var(--text-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .reset-title i {
            color: var(--danger-color);
        }

        .reset-buttons {
            display: flex;
            gap: 1rem;
        }

        .reset-btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .reset-btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .reset-btn-danger:hover {
            background: #c0392b;
        }

        .reset-btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .reset-btn-warning:hover {
            background: #f39c12;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <img src="betpredictlogo.png" alt="BetPredict Logo">
                <h1>Pannello Admin</h1>
            </div>
            <nav>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="admin-dashboard.php" class="nav-link">
                            <i class="fas fa-home"></i>
                            Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin-users.php" class="nav-link">
                            <i class="fas fa-users"></i>
                            Utenti
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin-activities.php" class="nav-link active">
                            <i class="fas fa-futbol"></i>
                            Attività
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="predictions-history.php" class="nav-link">
                            <i class="fas fa-history"></i>
                            Storico Pronostici
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="header">
                <h1 class="page-title">Gestione Pronostici</h1>
                    <form action="admin-login.php" method="post">
                        <button type="submit" name="logout" class="logout-btn">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </form>
            </header>

            <!-- Add Prediction Form -->
            <div class="form-container">
                <h2 class="form-title">
                    <i class="fas fa-plus-circle"></i>
                    Aggiungi Nuovo Pronostico
                </h2>
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        Pronostico aggiunto con successo!
                    </div>
                <?php endif; ?>

                <div class="sport-selector">
                    <div class="sport-option active" data-sport="football">
                        <i class="fas fa-futbol"></i>
                        <div>Calcio</div>
                    </div>
                    <div class="sport-option" data-sport="tennis">
                        <i class="fas fa-baseball-ball"></i>
                        <div>Tennis</div>
                    </div>
                </div>

                <!-- Football Form -->
                <form method="post" action="" class="prediction-form active" id="football-form">
                    <input type="hidden" name="sport" value="football">
                    
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-trophy"></i>
                            Informazioni Campionato
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="league">Campionato</label>
                                <input type="text" id="league" name="league" class="form-input" required 
                                    value="<?= isset($_POST['league']) ? htmlspecialchars($_POST['league']) : '' ?>"
                                    placeholder="Es: Serie A, Premier League">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="match">Partita</label>
                                <input type="text" id="match" name="match" class="form-input" required 
                                    value="<?= isset($_POST['match']) ? htmlspecialchars($_POST['match']) : '' ?>"
                                    placeholder="Es: Juventus - Milan">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-bullseye"></i>
                            Pronostico
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="prediction">Pronostico</label>
                                <input type="text" id="prediction" name="prediction" class="form-input" required 
                                    value="<?= isset($_POST['prediction']) ? htmlspecialchars($_POST['prediction']) : '' ?>"
                                    placeholder="Es: 1, X, 2, Over 2.5">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="odds">Quota</label>
                                <input type="number" id="odds" name="odds" class="form-input" step="0.01" min="1.00" required 
                                    value="<?= isset($_POST['odds']) ? htmlspecialchars($_POST['odds']) : '' ?>"
                                    placeholder="Es: 1.85">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-crown"></i>
                            Tipo Pronostico
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="type">Tipo</label>
                                <select id="type" name="type" class="form-input" required>
                                    <option value="free" <?= (isset($_POST['type']) && $_POST['type'] == 'free') ? 'selected' : '' ?>>Gratuito</option>
                                    <option value="premium" <?= (isset($_POST['type']) && $_POST['type'] == 'premium') ? 'selected' : '' ?>>Premium</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="add_prediction" class="submit-btn">
                        <i class="fas fa-save"></i> Salva Pronostico
                    </button>
                </form>

                <!-- Tennis Form -->
                <form method="post" action="" class="prediction-form" id="tennis-form">
                    <input type="hidden" name="sport" value="tennis">
                    
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-trophy"></i>
                            Informazioni Competizione
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="tennis_league">Competizione</label>
                                <input type="text" id="tennis_league" name="league" class="form-input" required 
                                    value="<?= isset($_POST['league']) ? htmlspecialchars($_POST['league']) : '' ?>"
                                    placeholder="Es: Wimbledon, Australian Open">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="tennis_match">Match</label>
                                <input type="text" id="tennis_match" name="match" class="form-input" required 
                                    value="<?= isset($_POST['match']) ? htmlspecialchars($_POST['match']) : '' ?>"
                                    placeholder="Es: Djokovic - Nadal">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-bullseye"></i>
                            Pronostico
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="tennis_prediction">Pronostico</label>
                                <input type="text" id="tennis_prediction" name="prediction" class="form-input" required 
                                    value="<?= isset($_POST['prediction']) ? htmlspecialchars($_POST['prediction']) : '' ?>"
                                    placeholder="Es: Djokovic, Over 20.5 games">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="tennis_odds">Quota</label>
                                <input type="number" id="tennis_odds" name="odds" class="form-input" step="0.01" min="1.00" required 
                                    value="<?= isset($_POST['odds']) ? htmlspecialchars($_POST['odds']) : '' ?>"
                                    placeholder="Es: 1.85">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-crown"></i>
                            Tipo Pronostico
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="tennis_type">Tipo</label>
                                <select id="tennis_type" name="type" class="form-input" required>
                                    <option value="free" <?= (isset($_POST['type']) && $_POST['type'] == 'free') ? 'selected' : '' ?>>Gratuito</option>
                                    <option value="premium" <?= (isset($_POST['type']) && $_POST['type'] == 'premium') ? 'selected' : '' ?>>Premium</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="add_prediction" class="submit-btn">
                        <i class="fas fa-save"></i> Salva Pronostico
                    </button>
                </form>
            </div>

            <!-- Active Predictions Section -->
            <div class="form-container">
                <h2 class="section-title">
                    <i class="fas fa-clock"></i>
                    Pronostici Attivi
                </h2>
                <?php if (empty($active_predictions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-futbol"></i>
                        <p>Nessun pronostico attivo al momento</p>
                </div>
                <?php else: ?>
                    <div class="predictions-grid">
                        <?php foreach ($active_predictions as $prediction): ?>
                            <div class="prediction-card <?= $prediction['status'] ?? '' ?>">
                                <i class="fas fa-info-circle info-icon"></i>
                                <div class="prediction-header">
                                    <h3 class="prediction-title"><?= ucfirst(htmlspecialchars($prediction['sport'])) ?></h3>
                                    <div class="prediction-meta">
                                        <span class="prediction-badge badge-<?= $prediction['type'] ?>">
                                    <?= ucfirst($prediction['type']) ?>
                                </span>
                                    </div>
                                </div>
                                <div class="prediction-details">
                                    <div class="detail-row">
                                        <span class="detail-label">Campionato</span>
                                        <span class="detail-value"><?= htmlspecialchars($prediction['league']) ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Partita</span>
                                        <span class="detail-value"><?= htmlspecialchars($prediction['match']) ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Pronostico</span>
                                        <span class="detail-value"><?= htmlspecialchars($prediction['prediction']) ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Quota</span>
                                        <span class="detail-value"><?= $prediction['odds'] ?></span>
                                    </div>
                                </div>
                                <div class="prediction-actions">
                                    <form method="post" style="flex: 1;">
                                    <input type="hidden" name="prediction_id" value="<?= $prediction['id'] ?>">
                                    <input type="hidden" name="status" value="won">
                                    <button type="submit" name="update_status" class="action-btn won">
                                        <i class="fas fa-check"></i> Vinto
                                    </button>
                                </form>
                                    <form method="post" style="flex: 1;">
                                    <input type="hidden" name="prediction_id" value="<?= $prediction['id'] ?>">
                                    <input type="hidden" name="status" value="lost">
                                    <button type="submit" name="update_status" class="action-btn lost">
                                        <i class="fas fa-times"></i> Perso
                                    </button>
                                </form>
                                </div>
                                <div class="time-info">
                                    <?php
                                        $created = new DateTime($prediction['created_at'], new DateTimeZone('UTC'));
                                        $created->setTimezone(new DateTimeZone('Europe/Rome'));
                                        $updated = new DateTime($prediction['updated_at'] ?? $prediction['created_at'], new DateTimeZone('UTC'));
                                        $updated->setTimezone(new DateTimeZone('Europe/Rome'));
                                    ?>
                                    <div>
                                        <i class="far fa-clock"></i>
                                        <span>Creato: <?= $created->format('d/m/Y H:i') ?></span>
                                    </div>
                                    <?php if (isset($prediction['status']) && $prediction['status'] != 'pending'): ?>
                                    <div class="update-time">
                                        <i class="fas fa-sync-alt"></i>
                                        <span><?= ucfirst($prediction['status']) ?>: <?= $updated->format('d/m/Y H:i') ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                                <?php endif; ?>
            </div>

            <!-- Completed Predictions Section -->
            <div class="form-container">
                <h2 class="section-title">
                    <i class="fas fa-history"></i>
                    Ultimi Pronostici Completati
                </h2>
                <?php if (empty($completed_predictions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>Nessun pronostico completato</p>
                    </div>
                <?php else: ?>
                    <div class="predictions-grid">
                        <?php foreach ($completed_predictions as $prediction): ?>
                            <div class="prediction-card <?= $prediction['status'] ?>">
                                <i class="fas fa-info-circle info-icon"></i>
                                <div class="prediction-header">
                                    <h3 class="prediction-title"><?= ucfirst(htmlspecialchars($prediction['sport'])) ?></h3>
                                    <div class="prediction-meta">
                                        <span class="prediction-badge badge-<?= $prediction['type'] ?>">
                                            <?= ucfirst($prediction['type']) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="prediction-details">
                                    <div class="detail-row">
                                        <span class="detail-label">Campionato</span>
                                        <span class="detail-value"><?= htmlspecialchars($prediction['league']) ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Partita</span>
                                        <span class="detail-value"><?= htmlspecialchars($prediction['match']) ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Pronostico</span>
                                        <span class="detail-value"><?= htmlspecialchars($prediction['prediction']) ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Quota</span>
                                        <span class="detail-value"><?= $prediction['odds'] ?></span>
                                    </div>
                                </div>
                                <div class="time-info">
                                    <?php
                                        $created = new DateTime($prediction['created_at'], new DateTimeZone('UTC'));
                                        $created->setTimezone(new DateTimeZone('Europe/Rome'));
                                        $updated = new DateTime($prediction['updated_at'] ?? $prediction['created_at'], new DateTimeZone('UTC'));
                                        $updated->setTimezone(new DateTimeZone('Europe/Rome'));
                                    ?>
                                    <div>
                                        <i class="far fa-clock"></i>
                                        <span>Creato: <?= $created->format('d/m/Y H:i') ?></span>
                                    </div>
                                    <div class="update-time">
                                        <i class="fas fa-sync-alt"></i>
                                        <span><?= ucfirst($prediction['status']) ?>: <?= $updated->format('d/m/Y H:i') ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Reset Section -->
            <div class="reset-section">
                <h3 class="reset-title">
                    <i class="fas fa-trash-alt"></i>
                    Reset Pronostici
                </h3>
                <div class="reset-buttons">
                    <form method="post" onsubmit="return confirm('Sei sicuro di voler eliminare tutti i pronostici attivi?');">
                        <button type="submit" name="reset_active" class="reset-btn reset-btn-danger">
                            <i class="fas fa-times-circle"></i>
                            Elimina Pronostici Attivi
                        </button>
                    </form>
                    <form method="post" onsubmit="return confirm('Sei sicuro di voler eliminare tutti i pronostici completati?');">
                        <button type="submit" name="reset_completed" class="reset-btn reset-btn-warning">
                            <i class="fas fa-history"></i>
                            Elimina Pronostici Completati
                        </button>
                    </form>
                </div>
            </div>

            <!-- Success Reset Message -->
            <?php if (isset($_GET['reset'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= $_GET['reset'] == 'active' ? 'Pronostici attivi eliminati con successo!' : 'Pronostici completati eliminati con successo!' ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Add this JavaScript before the closing body tag -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const sportOptions = document.querySelectorAll('.sport-option');
        const forms = document.querySelectorAll('.prediction-form');

        sportOptions.forEach(option => {
            option.addEventListener('click', function() {
                // Remove active class from all options and forms
                sportOptions.forEach(opt => opt.classList.remove('active'));
                forms.forEach(form => form.classList.remove('active'));

                // Add active class to clicked option and corresponding form
                this.classList.add('active');
                const sport = this.dataset.sport;
                document.getElementById(`${sport}-form`).classList.add('active');
            });
        });

        document.querySelectorAll('.info-icon').forEach(icon => {
            icon.addEventListener('click', function(e) {
                e.stopPropagation();
                const card = this.closest('.prediction-card');
                const timeInfo = card.querySelector('.time-info');
                timeInfo.classList.toggle('show');
            });
        });

        // Close time info when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.time-info') && !e.target.closest('.info-icon')) {
                document.querySelectorAll('.time-info').forEach(info => {
                    info.classList.remove('show');
                });
            }
        });
    });
    </script>
</body>
</html> 