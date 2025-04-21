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

// Gestione delle azioni
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST["update_user_status"])) {
        $stmt = $conn->prepare("
            UPDATE users 
            SET is_active = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$_POST["status"], $_POST["user_id"]]);
    } elseif (isset($_POST["reset_login_attempts"])) {
        $stmt = $conn->prepare("
            UPDATE users 
            SET failed_login_attempts = 0, last_failed_login = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$_POST["user_id"]]);
    }
}

// Recupera tutti gli utenti
$stmt = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Utenti - BetPredict</title>
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

        /* Table */
        .table-container {
            background-color: var(--light-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
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

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-active {
            background-color: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
        }

        .status-inactive {
            background-color: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
        }

        .action-btn {
            padding: 0.3rem 0.8rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.8rem;
            margin-right: 0.5rem;
        }

        .btn-edit {
            background-color: rgba(52, 152, 219, 0.2);
            color: var(--secondary-color);
        }

        .btn-delete {
            background-color: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
        }

        .btn-edit:hover {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-delete:hover {
            background-color: var(--danger-color);
            color: white;
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
                        <a href="admin-users.php" class="nav-link active">
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
                <h1 class="page-title">Gestione Utenti</h1>
                <div class="user-info">
                    <img src="admin-avatar.png" alt="Admin Avatar">
                    <form action="admin-login.php" method="post">
                        <button type="submit" name="logout" class="logout-btn">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </form>
                </div>
            </header>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Data Registrazione</th>
                            <th>Ultimo Accesso</th>
                            <th>Tentativi Falliti</th>
                            <th>Stato</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['username']) ?></td>
                            <td><?= htmlspecialchars($user['email']) ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($user['created_at'])) ?></td>
                            <td><?= $user['last_login'] ? date('d/m/Y H:i', strtotime($user['last_login'])) : 'Mai' ?></td>
                            <td><?= $user['failed_login_attempts'] ?></td>
                            <td>
                                <span class="status-badge <?= $user['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                    <?= $user['is_active'] ? 'Attivo' : 'Inattivo' ?>
                                </span>
                            </td>
                            <td>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $user['is_active'] ? '0' : '1' ?>">
                                    <button type="submit" name="update_user_status" class="action-btn btn-edit">
                                        <?= $user['is_active'] ? 'Disattiva' : 'Attiva' ?>
                                    </button>
                                </form>
                                <?php if ($user['failed_login_attempts'] > 0): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" name="reset_login_attempts" class="action-btn btn-edit">
                                        Sblocca
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