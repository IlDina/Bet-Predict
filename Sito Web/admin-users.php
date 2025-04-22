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

// Ottieni statistiche utenti
$stmt = $conn->query("SELECT COUNT(*) as total_users FROM users");
$total_users = $stmt->fetch()['total_users'];

$stmt = $conn->query("SELECT COUNT(*) as active_users FROM users WHERE is_active = 1");
$active_users = $stmt->fetch()['active_users'];

$stmt = $conn->query("SELECT COUNT(*) as blocked_users FROM users WHERE failed_login_attempts >= 3");
$blocked_users = $stmt->fetch()['blocked_users'];
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Utenti - BetPredict</title>
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

        /* Stats Grid */
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
        .stat-icon.blocked { background-color: rgba(231, 76, 60, 0.2); color: var(--danger-color); }

        .stat-info h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .stat-info p {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        /* Table */
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
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .btn-edit {
            background-color: rgba(52, 152, 219, 0.2);
            color: var(--secondary-color);
        }

        .btn-delete {
            background-color: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
        }

        .btn-reset {
            background-color: rgba(241, 196, 15, 0.2);
            color: var(--warning-color);
        }

        .btn-edit:hover {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-delete:hover {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-reset:hover {
            background-color: var(--warning-color);
            color: white;
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

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content {
            background-color: var(--card-bg);
            margin: 15% auto;
            padding: 2rem;
            border-radius: 12px;
            width: 80%;
            max-width: 500px;
            position: relative;
        }

        .close-modal {
            position: absolute;
            right: 1rem;
            top: 1rem;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-muted);
        }

        .modal-title {
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-muted);
        }

        .form-input {
            width: 100%;
            padding: 0.5rem;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background-color: var(--dark-bg);
            color: var(--text-color);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .btn-cancel {
            background-color: var(--light-bg);
            color: var(--text-color);
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
        }

        .btn-save {
            background-color: var(--success-color);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
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
                    <div class="stat-icon blocked">
                        <i class="fas fa-user-lock"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $blocked_users ?></h3>
                        <p>Utenti Bloccati</p>
                    </div>
                </div>
            </div>

            <!-- Users Table -->
            <div class="table-container">
                <div class="table-header">
                    <h2 class="table-title">Lista Utenti</h2>
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
                            <th>Tentativi Falliti</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['username']) ?></td>
                            <td><?= htmlspecialchars($user['email']) ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($user['created_at'])) ?></td>
                            <td>
                                <span class="status-badge <?= $user['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                    <?= $user['is_active'] ? 'Attivo' : 'Inattivo' ?>
                                </span>
                            </td>
                            <td><?= $user['failed_login_attempts'] ?></td>
                            <td>
                                <button class="action-btn btn-edit" onclick="editUser(<?= $user['id'] ?>)">
                                    <i class="fas fa-edit"></i> Modifica
                                </button>
                                <button class="action-btn btn-reset" onclick="resetLoginAttempts(<?= $user['id'] ?>)">
                                    <i class="fas fa-undo"></i> Reset
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
        </main>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('editUserModal')">&times;</span>
            <h2 class="modal-title">Modifica Utente</h2>
            <form id="editUserForm" method="post">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="form-group">
                    <label class="form-label" for="editUsername">Username</label>
                    <input type="text" class="form-input" id="editUsername" name="username" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="editEmail">Email</label>
                    <input type="email" class="form-input" id="editEmail" name="email" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="editStatus">Stato</label>
                    <select class="form-input" id="editStatus" name="status">
                        <option value="1">Attivo</option>
                        <option value="0">Inattivo</option>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('editUserModal')">Annulla</button>
                    <button type="submit" class="btn-save">Salva</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js"></script>
    <script>
        // Search functionality
        document.querySelector('.search-box').addEventListener('input', function(e) {
            const searchText = e.target.value.toLowerCase();
            const table = this.closest('.table-container').querySelector('tbody');
            const rows = table.querySelectorAll('tr');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchText) ? '' : 'none';
            });
        });

        // Export functionality
        document.querySelector('.export-btn').addEventListener('click', function() {
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
            a.setAttribute('download', 'utenti.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        });

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // User management functions
        function editUser(id) {
            // Get user data and populate form
            const user = <?= json_encode($users) ?>.find(u => u.id === id);
            if (user) {
                document.getElementById('editUserId').value = user.id;
                document.getElementById('editUsername').value = user.username;
                document.getElementById('editEmail').value = user.email;
                document.getElementById('editStatus').value = user.is_active ? '1' : '0';
                openModal('editUserModal');
            }
        }

        function resetLoginAttempts(id) {
            if (confirm('Sei sicuro di voler resettare i tentativi di login per questo utente?')) {
                const form = document.createElement('form');
                form.method = 'post';
                form.innerHTML = `
                    <input type="hidden" name="reset_login_attempts" value="1">
                    <input type="hidden" name="user_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function deleteUser(id) {
            if (confirm('Sei sicuro di voler eliminare questo utente?')) {
                // Implement delete user functionality
                console.log('Delete user:', id);
            }
        }

        // Form submission
        document.getElementById('editUserForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('update_user_status', '1');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        });
    </script>
</body>
</html> 