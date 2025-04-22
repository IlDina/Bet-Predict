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

// Ottieni statistiche utenti
$stmt = $conn->query("SELECT COUNT(*) as total_users FROM users");
$total_users = $stmt->fetch()['total_users'];

$stmt = $conn->query("SELECT COUNT(*) as active_users FROM users WHERE is_active = 1");
$active_users = $stmt->fetch()['active_users'];

$stmt = $conn->query("SELECT COUNT(*) as new_users FROM users WHERE created_at >= datetime('now', '-7 days')");
$new_users = $stmt->fetch()['new_users'];

// Ottieni ultimi utenti registrati
$stmt = $conn->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 5");
$recent_users = $stmt->fetchAll();

// Ottieni utenti con più tentativi falliti
$stmt = $conn->query("SELECT * FROM users WHERE failed_login_attempts > 0 ORDER BY failed_login_attempts DESC LIMIT 5");
$failed_logins = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - BetPredict</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.css" rel="stylesheet">
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
            --card-bg: #2d2d2d;
            --border-color: #404040;
            --hover-bg: #404040;
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
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
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
            transition: all 0.3s ease;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
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
            object-fit: cover;
        }

        .logout-btn {
            background-color: var(--danger-color);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background-color: #c0392b;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background-color: var(--card-bg);
            padding: 1.5rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: transform 0.3s ease;
            border: 1px solid var(--border-color);
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.users { background-color: rgba(46, 204, 113, 0.2); color: var(--success-color); }
        .stat-icon.active { background-color: rgba(52, 152, 219, 0.2); color: var(--secondary-color); }
        .stat-icon.new { background-color: rgba(241, 196, 15, 0.2); color: var(--warning-color); }

        .stat-info h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .stat-info p {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        /* Tables */
        .table-container {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .table-title {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .table-actions {
            display: flex;
            gap: 1rem;
        }

        .search-box {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background-color: var(--dark-bg);
            color: var(--text-color);
            width: 250px;
        }

        .export-btn {
            background-color: var(--secondary-color);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .export-btn:hover {
            background-color: #2980b9;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
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

        /* Charts */
        .chart-container {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }

        .chart-title {
            font-size: 1.2rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                width: 0;
                padding: 0;
                overflow: hidden;
            }

            .sidebar.active {
                width: 250px;
                padding: 1.5rem;
            }

            .main-content {
                margin-left: 0;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .table-actions {
                flex-direction: column;
            }

            .search-box {
                width: 100%;
            }
        }

        /* Notifications */
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: var(--danger-color);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
        }

        /* Loading Spinner */
        .loading-spinner {
            display: none;
            width: 40px;
            height: 40px;
            border: 4px solid var(--border-color);
            border-top: 4px solid var(--secondary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 2rem auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
                        <a href="admin-dashboard.php" class="nav-link active">
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
                <h1 class="page-title">Dashboard</h1>
                <div class="user-info">
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
                    <div class="stat-icon users">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $total_users ?></h3>
                        <p>Utenti Totali</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon active">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $active_users ?></h3>
                        <p>Utenti Attivi</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon new">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $new_users ?></h3>
                        <p>Nuovi Utenti (7 giorni)</p>
                    </div>
                </div>
            </div>

            <!-- Recent Users Table -->
            <div class="table-container">
                <div class="table-header">
                    <h2 class="table-title">Ultimi Utenti Registrati</h2>
                    <div class="table-actions">
                        <input type="text" class="search-box" placeholder="Cerca utenti...">
                        <button class="export-btn">
                            <i class="fas fa-download"></i> Esporta
                        </button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Data Registrazione</th>
                            <th>Stato</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_users as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['username']) ?></td>
                            <td><?= htmlspecialchars($user['email']) ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($user['created_at'])) ?></td>
                            <td>
                                <span class="status-badge <?= $user['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                    <?= $user['is_active'] ? 'Attivo' : 'Inattivo' ?>
                                </span>
                            </td>
                            <td>
                                <button class="action-btn btn-edit" onclick="editUser(<?= $user['id'] ?>)">
                                    <i class="fas fa-edit"></i> Modifica
                                </button>
                                <button class="action-btn btn-delete" onclick="deleteUser(<?= $user['id'] ?>)">
                                    <i class="fas fa-trash"></i> Elimina
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Failed Logins Table -->
            <div class="table-container">
                <div class="table-header">
                    <h2 class="table-title">Tentativi di Login Falliti</h2>
                    <div class="table-actions">
                        <input type="text" class="search-box" placeholder="Cerca utenti...">
                        <button class="export-btn">
                            <i class="fas fa-download"></i> Esporta
                        </button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Tentativi Falliti</th>
                            <th>Ultimo Tentativo</th>
                            <th>Stato</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($failed_logins as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['username']) ?></td>
                            <td><?= $user['failed_login_attempts'] ?></td>
                            <td><?= $user['last_failed_login'] ? date('d/m/Y H:i', strtotime($user['last_failed_login'])) : 'N/A' ?></td>
                            <td>
                                <span class="status-badge <?= $user['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                    <?= $user['is_active'] ? 'Attivo' : 'Inattivo' ?>
                                </span>
                            </td>
                            <td>
                                <button class="action-btn btn-edit" onclick="unlockUser(<?= $user['id'] ?>)">
                                    <i class="fas fa-lock-open"></i> Sblocca
                                </button>
                                <button class="action-btn btn-delete" onclick="deactivateUser(<?= $user['id'] ?>)">
                                    <i class="fas fa-user-slash"></i> Disattiva
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js"></script>
    <script>
        // Search functionality
        document.querySelectorAll('.search-box').forEach(searchBox => {
            searchBox.addEventListener('input', function(e) {
                const searchText = e.target.value.toLowerCase();
                const table = this.closest('.table-container').querySelector('tbody');
                const rows = table.querySelectorAll('tr');

                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(searchText) ? '' : 'none';
                });
            });
        });

        // Export functionality
        document.querySelectorAll('.export-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const table = this.closest('.table-container').querySelector('table');
                const rows = table.querySelectorAll('tr');
                let csv = [];

                // Get headers
                const headers = Array.from(table.querySelectorAll('th')).map(th => th.textContent);
                csv.push(headers.join(','));

                // Get data
                rows.forEach(row => {
                    if (row.style.display !== 'none') {
                        const cells = Array.from(row.querySelectorAll('td')).map(td => {
                            // Remove action buttons
                            if (td.querySelector('.action-btn')) {
                                return '';
                            }
                            return td.textContent;
                        });
                        csv.push(cells.join(','));
                    }
                });

                // Create and download file
                const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.setAttribute('hidden', '');
                a.setAttribute('href', url);
                a.setAttribute('download', 'export.csv');
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            });
        });

        // User management functions
        function editUser(id) {
            // Implement edit user functionality
            console.log('Edit user:', id);
        }

        function deleteUser(id) {
            if (confirm('Sei sicuro di voler eliminare questo utente?')) {
                // Implement delete user functionality
                console.log('Delete user:', id);
            }
        }

        function unlockUser(id) {
            // Implement unlock user functionality
            console.log('Unlock user:', id);
        }

        function deactivateUser(id) {
            if (confirm('Sei sicuro di voler disattivare questo utente?')) {
                // Implement deactivate user functionality
                console.log('Deactivate user:', id);
            }
        }
    </script>
</body>
</html>