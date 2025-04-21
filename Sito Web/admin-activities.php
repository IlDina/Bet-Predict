<?php
session_start();

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

// Crea la tabella admin_actions se non esiste
$conn->exec("
    CREATE TABLE IF NOT EXISTS admin_actions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER NOT NULL,
        action_type TEXT NOT NULL,
        action_details TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (admin_id) REFERENCES users(id)
    )
");

// Crea la tabella predictions_history se non esiste
$conn->exec("
    CREATE TABLE IF NOT EXISTS predictions_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sport TEXT NOT NULL,
        league TEXT NOT NULL,
        match TEXT NOT NULL,
        prediction TEXT NOT NULL,
        odds DECIMAL(4,2) NOT NULL,
        type TEXT NOT NULL,
        status TEXT NOT NULL,
        created_at DATETIME,
        updated_at DATETIME,
        archived_at DATETIME
    )
");

// Funzione per registrare le azioni amministrative
function logAdminAction($conn, $admin_id, $action_type, $action_details) {
    $stmt = $conn->prepare("
        INSERT INTO admin_actions (admin_id, action_type, action_details)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$admin_id, $action_type, $action_details]);
}

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

            $stmt = $conn->prepare("
                INSERT INTO predictions (sport, league, match, prediction, odds, type, status)
                VALUES (:sport, :league, :match, :prediction, :odds, :type, 'pending')
            ");
            
            $stmt->execute([
                ':sport' => $_POST["sport"],
                ':league' => $_POST["league"],
                ':match' => $_POST["match"],
                ':prediction' => $_POST["prediction"],
                ':odds' => $_POST["odds"],
                ':type' => $_POST["type"]
            ]);
            
            // Reindirizza per evitare il re-invio del form
            header("Location: admin-activities.php?success=1");
            exit();
        } catch(Exception $e) {
            $error = $e->getMessage();
        }
    } elseif (isset($_POST["update_status"])) {
        $stmt = $conn->prepare("
            UPDATE predictions 
            SET status = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$_POST["status"], $_POST["prediction_id"]]);
    } elseif (isset($_POST["reset_predictions"])) {
        $stmt = $conn->prepare("
            DELETE FROM predictions 
            WHERE status IN ('won', 'lost')
        ");
        $stmt->execute();
    }
}

// Mostra messaggio di successo se presente
if (isset($_GET['success'])) {
    $success_message = "Pronostico aggiunto con successo!";
}

// Recupera i pronostici
$stmt = $conn->query("
    SELECT * FROM predictions 
    ORDER BY created_at DESC 
    LIMIT 10
");
$predictions = $stmt->fetchAll();

// Recupera le ultime azioni amministrative
$stmt = $conn->query("
    SELECT a.*, u.username as admin_username 
    FROM admin_actions a
    JOIN users u ON a.admin_id = u.id
    ORDER BY a.created_at DESC 
    LIMIT 10
");
$admin_actions = $stmt->fetchAll();
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
        /* Copia tutti gli stili dalla dashboard */
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
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

        /* Table */
        .table-container {
            background-color: var(--light-bg);
            border-radius: 12px;
            padding: 1.5rem;
        }

        .table-title {
            font-size: 1.2rem;
            margin-bottom: 1rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-title i {
            color: var(--secondary-color);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        th {
            color: var(--text-muted);
            font-weight: 500;
            font-size: 0.9rem;
        }

        td {
            color: var(--text-color);
            font-size: 0.9rem;
        }

        .status {
            padding: 0.3rem 0.8rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status.pending {
            background-color: rgba(241, 196, 15, 0.2);
            color: var(--warning-color);
        }

        .status.won {
            background-color: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
        }

        .status.lost {
            background-color: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
        }

        .status.free {
            background-color: rgba(52, 152, 219, 0.2);
            color: var(--secondary-color);
        }

        .status.premium {
            background-color: rgba(241, 196, 15, 0.2);
            color: var(--warning-color);
        }

        .action-btn {
            padding: 0.3rem 0.8rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            color: white;
            transition: all 0.3s ease;
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

        .date {
            color: var(--text-muted);
            font-size: 0.8rem;
        }

        .reset-section {
            background-color: rgba(231, 76, 60, 0.1);
            padding: 1.5rem;
            border-radius: 8px;
            margin-top: 2rem;
        }

        .reset-warning {
            color: var(--danger-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .reset-warning i {
            font-size: 1.2rem;
        }

        .reset-btn {
            background-color: var(--danger-color);
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .reset-btn:hover {
            background-color: #c0392b;
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
                        <a href="admin-statistics.php" class="nav-link">
                            <i class="fas fa-chart-line"></i>
                            Statistiche
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin-activities.php" class="nav-link active">
                            <i class="fas fa-futbol"></i>
                            Attività
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="header">
                <h1 class="page-title">Gestione Pronostici</h1>
                <div class="user-info">
                    <img src="admin-avatar.png" alt="Admin Avatar">
                    <form action="admin-login.php" method="post">
                        <button type="submit" name="logout" class="logout-btn">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </form>
                </div>
            </header>

            <!-- Add Prediction Form -->
            <div class="form-container">
                <h2 class="form-title">
                    <i class="fas fa-plus-circle"></i>
                    Aggiungi Nuovo Pronostico
                </h2>
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success"><?= $success_message ?></div>
                <?php endif; ?>
                <form method="post">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="sport">Sport</label>
                            <input type="text" id="sport" name="sport" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="league">Campionato</label>
                            <input type="text" id="league" name="league" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="match">Partita</label>
                            <input type="text" id="match" name="match" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="prediction">Pronostico</label>
                            <input type="text" id="prediction" name="prediction" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="odds">Quota</label>
                            <input type="number" id="odds" name="odds" class="form-input" step="0.01" min="1.00" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="type">Tipo Pronostico</label>
                            <select id="type" name="type" class="form-input" required>
                                <option value="free">Gratuito</option>
                                <option value="premium">Premium</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="add_prediction" class="submit-btn">
                        <i class="fas fa-save"></i> Salva Pronostico
                    </button>
                </form>
            </div>

            <!-- Reset Predictions Section -->
            <div class="form-container">
                <h2 class="form-title">
                    <i class="fas fa-sync-alt"></i>
                    Gestione Storico
                </h2>
                <div class="reset-section">
                    <p class="reset-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        Attenzione: Il reset dei pronostici sposterà tutti i pronostici completati nell'archivio storico.
                    </p>
                    <form method="post" onsubmit="return confirm('Sei sicuro di voler resettare i pronostici? Questa azione non può essere annullata.');">
                        <button type="submit" name="reset_predictions" class="reset-btn">
                            <i class="fas fa-sync-alt"></i> Reset Pronostici
                        </button>
                    </form>
                </div>
            </div>

            <!-- Predictions Table -->
            <div class="table-container">
                <h2 class="table-title">
                    <i class="fas fa-list"></i>
                    Ultimi Pronostici
                </h2>
                <table>
                    <thead>
                        <tr>
                            <th>Sport</th>
                            <th>Campionato</th>
                            <th>Partita</th>
                            <th>Pronostico</th>
                            <th>Quota</th>
                            <th>Tipo</th>
                            <th>Stato</th>
                            <th>Data</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($predictions as $prediction): ?>
                        <tr>
                            <td><?= htmlspecialchars($prediction['sport']) ?></td>
                            <td><?= htmlspecialchars($prediction['league']) ?></td>
                            <td><?= htmlspecialchars($prediction['match']) ?></td>
                            <td><?= htmlspecialchars($prediction['prediction']) ?></td>
                            <td><?= $prediction['odds'] ?></td>
                            <td>
                                <span class="status <?= $prediction['type'] ?>">
                                    <?= ucfirst($prediction['type']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="status <?= $prediction['status'] ?>">
                                    <?= ucfirst($prediction['status']) ?>
                                </span>
                            </td>
                            <td class="date"><?= date('d/m/Y H:i', strtotime($prediction['created_at'])) ?></td>
                            <td>
                                <?php if ($prediction['status'] == 'pending'): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="prediction_id" value="<?= $prediction['id'] ?>">
                                    <input type="hidden" name="status" value="won">
                                    <button type="submit" name="update_status" class="action-btn won">
                                        <i class="fas fa-check"></i> Vinto
                                    </button>
                                </form>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="prediction_id" value="<?= $prediction['id'] ?>">
                                    <input type="hidden" name="status" value="lost">
                                    <button type="submit" name="update_status" class="action-btn lost">
                                        <i class="fas fa-times"></i> Perso
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html> 