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

// Recupera i pronostici completati
$stmt = $conn->query("
    SELECT * FROM predictions 
    WHERE status IN ('won', 'lost')
    ORDER BY created_at DESC
");
$completed_predictions = $stmt->fetchAll();

// Calcola le statistiche
$total_predictions = count($completed_predictions);
$won_predictions = count(array_filter($completed_predictions, fn($p) => $p['status'] === 'won'));
$win_rate = $total_predictions > 0 ? round(($won_predictions / $total_predictions) * 100, 2) : 0;

// Add this after the PHP session check
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["reset_history"])) {
    try {
        $stmt = $conn->prepare("DELETE FROM predictions WHERE status IN ('won', 'lost')");
        $stmt->execute();
        header("Location: predictions-history.php?reset=success");
        exit();
    } catch(PDOException $e) {
        $error = "Errore durante il reset dello storico";
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Storico Pronostici - BetPredict</title>
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
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background-color: var(--light-bg);
            padding: 1.5rem;
            border-radius: 12px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
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

        /* Predictions Grid */
        .predictions-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
        }

        .prediction-card {
            background: linear-gradient(145deg, #2d2d2d, #1a1a1a);
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s ease;
            aspect-ratio: 1.2;
            display: flex;
            flex-direction: column;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .prediction-card:hover {
            transform: translateY(-3px);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .prediction-header {
            padding: 0.7rem 1rem;
            background: #1a1a1a;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .prediction-header h3 {
            font-size: 0.85rem;
            color: #3498db;
            margin: 0;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .prediction-meta {
            color: #ffffff;
            font-size: 0.75rem;
            background: rgba(52, 152, 219, 0.2);
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            font-weight: 500;
            letter-spacing: 0.2px;
        }

        .prediction-content {
            padding: 0.8rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background: #2d2d2d;
            gap: 0.8rem;
        }

        .prediction-section {
            margin-bottom: 0;
        }

        .section-title {
            font-size: 0.75rem;
            color: #3498db;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.4rem;
            padding-left: 0.5rem;
            font-weight: 700;
            position: relative;
        }

        .section-title::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 12px;
            background: #3498db;
            border-radius: 2px;
        }

        .prediction-details {
            margin: 0;
            background: #1a1a1a;
            border-radius: 8px;
            padding: 0.6rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .prediction-details p {
            margin: 0;
            padding: 0.4rem 0.6rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            transition: background-color 0.2s ease;
        }

        .prediction-details p:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .prediction-details p:last-child {
            border-bottom: none;
        }

        .prediction-details strong {
            color: #3498db;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.2px;
            min-width: 80px;
        }

        .prediction-details span {
            color: #ffffff;
            font-weight: 500;
            text-align: right;
            max-width: 70%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 0.8rem;
        }

        .prediction-odds {
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.85rem;
            border: 1px solid rgba(52, 152, 219, 0.4);
            min-width: 60px;
            text-align: center;
        }

        .prediction-footer {
            padding: 0.8rem 1rem;
            background: #1a1a1a;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            min-height: 50px;
            margin-top: auto;
        }

        .type-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            min-width: 100px;
            justify-content: center;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        }

        .type-free {
            background: #2980b9;
            color: #ffffff;
            border: 1px solid #3498db;
        }

        .type-premium {
            background: #f39c12;
            color: #ffffff;
            border: 1px solid #f1c40f;
        }

        .status-icon {
            font-size: 1.1rem;
            padding: 0.4rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(255, 255, 255, 0.2);
            width: 36px;
            height: 36px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .status-won {
            background: #27ae60;
            color: #ffffff;
            border-color: #2ecc71;
        }

        .status-lost {
            background: #c0392b;
            color: #ffffff;
            border-color: #e74c3c;
        }

        .date {
            color: var(--text-muted);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .date i {
            color: var(--secondary-color);
        }

        @media (max-width: 1200px) {
            .stats-grid,
            .predictions-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .admin-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }

            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .stats-grid,
            .predictions-grid {
                grid-template-columns: 1fr;
            }
        }

        .predictions-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 0.75rem;
            margin: 1rem 0;
        }

        .predictions-table th {
            background: var(--primary-color);
            color: var(--text-color);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            border-radius: 8px 8px 0 0;
        }

        .predictions-table td {
            padding: 1rem;
            background: var(--light-bg);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .predictions-table tr {
            margin-bottom: 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .predictions-table tr:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .predictions-table tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-won {
            background-color: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
        }

        .status-lost {
            background-color: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
        }

        .type-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            display: inline-block;
        }

        .type-free {
            background-color: rgba(52, 152, 219, 0.2);
            color: var(--secondary-color);
        }

        .type-premium {
            background-color: rgba(241, 196, 15, 0.2);
            color: var(--warning-color);
        }

        .table-container {
            background: var(--dark-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .table-title {
            font-size: 1.5rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-title i {
            color: var(--secondary-color);
        }

        .table-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .reset-history-btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            background: var(--danger-color);
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .reset-history-btn:hover {
            background: #c0392b;
        }

        .filter-btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            background: var(--light-bg);
            color: var(--text-color);
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-btn:hover {
            background: var(--primary-color);
        }

        .filter-btn.active {
            background: var(--primary-color);
            border: 1px solid var(--secondary-color);
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
                        <a href="admin-activities.php" class="nav-link">
                            <i class="fas fa-futbol"></i>
                            Attività
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="predictions-history.php" class="nav-link active">
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
                <h1 class="page-title">Storico Pronostici</h1>
                <div class="user-info">
                    <img src="admin-avatar.png" alt="Admin Avatar">
                    <form action="admin-login.php" method="post">
                        <button type="submit" name="logout" class="logout-btn">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </form>
                </div>
            </header>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><?= $total_predictions ?></h3>
                    <p>Pronostici Totali</p>
                </div>
                <div class="stat-card">
                    <h3><?= $won_predictions ?></h3>
                    <p>Pronostici Vinti</p>
                </div>
                <div class="stat-card">
                    <h3><?= $win_rate ?>%</h3>
                    <p>Percentuale Successo</p>
                </div>
            </div>

            <!-- Predictions Grid -->
            <div class="predictions-grid">
                <?php foreach ($completed_predictions as $prediction): ?>
                <div class="prediction-card">
                    <div class="prediction-header">
                        <h3><?= htmlspecialchars($prediction['sport']) ?></h3>
                        <span class="prediction-meta">
                            <?= date('d/m/Y', strtotime($prediction['created_at'])) ?>
                        </span>
                    </div>
                    <div class="prediction-content">
                        <div class="prediction-section">
                            <div class="section-title">Partita</div>
                            <div class="prediction-details">
                                <p>
                                    <strong>Lega:</strong>
                                    <span><?= htmlspecialchars($prediction['league']) ?></span>
                                </p>
                                <p>
                                    <strong>Match:</strong>
                                    <span><?= htmlspecialchars($prediction['match']) ?></span>
                                </p>
                            </div>
                        </div>
                        <div class="prediction-section">
                            <div class="section-title">Pronostico</div>
                            <div class="prediction-details">
                                <p>
                                    <strong>Tipologia:</strong>
                                    <span><?= htmlspecialchars($prediction['prediction']) ?></span>
                                </p>
                                <p>
                                    <strong>Quota:</strong>
                                    <span class="prediction-odds"><?= $prediction['odds'] ?></span>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="prediction-footer">
                        <span class="type-badge type-<?= $prediction['type'] ?>">
                            <i class="fas <?= $prediction['type'] === 'premium' ? 'fa-crown' : 'fa-unlock' ?>"></i>
                            <?= ucfirst($prediction['type']) ?>
                        </span>
                        <span class="status-icon status-<?= $prediction['status'] ?>">
                            <i class="fas <?= $prediction['status'] === 'won' ? 'fa-check' : 'fa-times' ?>"></i>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Update the table structure -->
            <div class="table-container">
                <div class="table-header">
                    <h2 class="table-title">
                        <i class="fas fa-history"></i>
                        Storico Pronostici
                    </h2>
                    <div class="table-actions">
                        <button class="filter-btn active" data-filter="all">
                            <i class="fas fa-list"></i>
                            Tutti
                        </button>
                        <button class="filter-btn" data-filter="won">
                            <i class="fas fa-check"></i>
                            Vinti
                        </button>
                        <button class="filter-btn" data-filter="lost">
                            <i class="fas fa-times"></i>
                            Persi
                        </button>
                        <form method="post" onsubmit="return confirm('Sei sicuro di voler eliminare tutti i pronostici dallo storico?');">
                            <button type="submit" name="reset_history" class="reset-history-btn">
                                <i class="fas fa-trash-alt"></i>
                                Reset Storico
                            </button>
                        </form>
                    </div>
                </div>
                <table class="predictions-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Sport</th>
                            <th>Competizione</th>
                            <th>Match</th>
                            <th>Pronostico</th>
                            <th>Quota</th>
                            <th>Tipo</th>
                            <th>Risultato</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($completed_predictions as $prediction): ?>
                            <tr>
                                <td><?= date('d/m/Y H:i', strtotime($prediction['created_at'])) ?></td>
                                <td><?= ucfirst(htmlspecialchars($prediction['sport'])) ?></td>
                                <td><?= htmlspecialchars($prediction['league']) ?></td>
                                <td><?= htmlspecialchars($prediction['match']) ?></td>
                                <td><?= htmlspecialchars($prediction['prediction']) ?></td>
                                <td><?= $prediction['odds'] ?></td>
                                <td>
                                    <span class="type-badge type-<?= $prediction['type'] ?>">
                                        <?= ucfirst($prediction['type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $prediction['status'] ?>">
                                        <?= ucfirst($prediction['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Add this JavaScript before the closing body tag -->
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const filterButtons = document.querySelectorAll('.filter-btn');
                const tableRows = document.querySelectorAll('.predictions-table tbody tr');

                filterButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        // Remove active class from all buttons
                        filterButtons.forEach(btn => btn.classList.remove('active'));
                        // Add active class to clicked button
                        this.classList.add('active');

                        const filter = this.dataset.filter;
                        
                        tableRows.forEach(row => {
                            if (filter === 'all') {
                                row.style.display = '';
                            } else {
                                const status = row.querySelector('.status-badge').classList.contains(`status-${filter}`);
                                row.style.display = status ? '' : 'none';
                            }
                        });
                    });
                });
            });
            </script>

            <!-- Add this after the error/success alerts -->
            <?php if (isset($_GET['reset']) && $_GET['reset'] == 'success'): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Storico pronostici eliminato con successo!
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html> 