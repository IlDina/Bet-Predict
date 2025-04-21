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

// Recupera le statistiche degli utenti
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
        SUM(CASE WHEN failed_login_attempts >= 5 THEN 1 ELSE 0 END) as blocked_users,
        SUM(CASE WHEN last_login >= datetime('now', '-1 day') THEN 1 ELSE 0 END) as daily_active_users
    FROM users
");
$user_stats = $stmt->fetch();

// Recupera le statistiche dei pronostici
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total_predictions,
        SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) as won_predictions,
        SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost_predictions,
        SUM(CASE WHEN type = 'premium' THEN 1 ELSE 0 END) as premium_predictions
    FROM predictions
");
$prediction_stats = $stmt->fetch();

// Calcola le percentuali
$win_rate = $prediction_stats['total_predictions'] > 0 
    ? round(($prediction_stats['won_predictions'] / $prediction_stats['total_predictions']) * 100, 2) 
    : 0;

$premium_rate = $prediction_stats['total_predictions'] > 0 
    ? round(($prediction_stats['premium_predictions'] / $prediction_stats['total_predictions']) * 100, 2) 
    : 0;
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistiche - BetPredict</title>
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background-color: var(--light-bg);
            padding: 1.5rem;
            border-radius: 12px;
            text-align: center;
        }

        .stat-card h3 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            color: var(--secondary-color);
        }

        .stat-card p {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .stat-card .trend {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }

        .trend.up {
            color: var(--success-color);
        }

        .trend.down {
            color: var(--danger-color);
        }

        /* Tables */
        .table-container {
            background-color: var(--light-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .table-title {
            font-size: 1.2rem;
            margin-bottom: 1rem;
            color: var(--text-color);
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

        .percentage {
            color: var(--text-muted);
            font-size: 0.8rem;
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
                        <a href="admin-statistics.php" class="nav-link active">
                            <i class="fas fa-chart-line"></i>
                            Statistiche
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="admin-activities.php" class="nav-link">
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
                <h1 class="page-title">Statistiche</h1>
                <div class="user-info">
                    <img src="admin-avatar.png" alt="Admin Avatar">
                    <form action="admin-login.php" method="post">
                        <button type="submit" name="logout" class="logout-btn">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </form>
                </div>
            </header>

            <!-- User Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><?= $user_stats['total_users'] ?></h3>
                    <p>Utenti Totali</p>
                </div>
                <div class="stat-card">
                    <h3><?= $user_stats['active_users'] ?></h3>
                    <p>Utenti Attivi</p>
                </div>
                <div class="stat-card">
                    <h3><?= $user_stats['blocked_users'] ?></h3>
                    <p>Utenti Bloccati</p>
                </div>
                <div class="stat-card">
                    <h3><?= $user_stats['daily_active_users'] ?></h3>
                    <p>Utenti Attivi Oggi</p>
                </div>
            </div>

            <!-- Prediction Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><?= $prediction_stats['total_predictions'] ?></h3>
                    <p>Pronostici Totali</p>
                </div>
                <div class="stat-card">
                    <h3><?= $prediction_stats['won_predictions'] ?></h3>
                    <p>Pronostici Vinti</p>
                    <div class="trend up">
                        <i class="fas fa-arrow-up"></i>
                        <span><?= $win_rate ?>%</span>
                    </div>
                </div>
                <div class="stat-card">
                    <h3><?= $prediction_stats['lost_predictions'] ?></h3>
                    <p>Pronostici Persi</p>
                </div>
                <div class="stat-card">
                    <h3><?= $prediction_stats['premium_predictions'] ?></h3>
                    <p>Pronostici Premium</p>
                    <div class="trend up">
                        <i class="fas fa-arrow-up"></i>
                        <span><?= $premium_rate ?>%</span>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html> 