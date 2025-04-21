<?php
session_start();

// Configurazione di sicurezza
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 1);

$error = "";
$success = "";
$current_section = isset($_GET['section']) ? $_GET['section'] : 'login';

// Database connection
$db_file = __DIR__ . "/utentieadminsito.db";

try {
    $conn = new PDO("sqlite:$db_file");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Crea la tabella se non esiste
    $conn->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        email TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login DATETIME,
        is_active BOOLEAN DEFAULT 1,
        failed_login_attempts INTEGER DEFAULT 0,
        last_failed_login DATETIME
    )");
} catch(PDOException $e) {
    $error = "Errore di connessione al database: " . $e->getMessage();
}

// Funzione per pulire e validare l'input
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Funzione per generare un salt unico
function generate_salt() {
    return bin2hex(random_bytes(16));
}

// Funzione per hashare la password con salt
function hash_password($password, $salt) {
    return password_hash($password . $salt, PASSWORD_ARGON2ID);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST["admin_login"])) {
    $admin_username = "admin";
        $admin_password = "BetPredictPagnotsDina!2025";

        $username = clean_input($_POST["username"]);
        $password = clean_input($_POST["password"]);

    if ($username === $admin_username && $password === $admin_password) {
        $_SESSION["admin_logged_in"] = true;
            $_SESSION["last_activity"] = time();
        header("Location: admin-dashboard.php");
        exit();
        } else {
            $error = "Credenziali admin non valide.";
        }
    } elseif (isset($_POST["register"])) {
        $reg_username = clean_input($_POST["reg_username"]);
        $reg_email = clean_input($_POST["reg_email"]);
        $reg_password = clean_input($_POST["reg_password"]);
        $reg_confirm_password = clean_input($_POST["reg_confirm_password"]);

        // Validazione avanzata
        if (empty($reg_username) || empty($reg_email) || empty($reg_password) || empty($reg_confirm_password)) {
            $error = "Tutti i campi sono obbligatori.";
        } elseif (strlen($reg_username) < 3 || strlen($reg_username) > 20) {
            $error = "L'username deve essere tra 3 e 20 caratteri.";
        } elseif (!preg_match("/^[a-zA-Z0-9_]+$/", $reg_username)) {
            $error = "L'username può contenere solo lettere, numeri e underscore.";
        } elseif ($reg_password !== $reg_confirm_password) {
            $error = "Le password non coincidono.";
        } elseif (strlen($reg_password) < 8) {
            $error = "La password deve essere di almeno 8 caratteri.";
        } elseif (!preg_match("/[A-Z]/", $reg_password) || !preg_match("/[a-z]/", $reg_password) || !preg_match("/[0-9]/", $reg_password)) {
            $error = "La password deve contenere almeno una lettera maiuscola, una minuscola e un numero.";
        } elseif (!filter_var($reg_email, FILTER_VALIDATE_EMAIL)) {
            $error = "Inserisci un indirizzo email valido.";
        } else {
            try {
                // Verifica se l'username o l'email esistono già
                $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
                $stmt->execute([$reg_username, $reg_email]);
                if ($stmt->rowCount() > 0) {
                    $error = "Username o email già in uso.";
                } else {
                    // Genera un salt unico
                    $salt = generate_salt();
                    
                    // Hash della password con salt
                    $hashed_password = hash_password($reg_password, $salt);
                    
                    // Inserimento nel database
                    $stmt = $conn->prepare("INSERT INTO users (username, email, password, created_at, is_active) VALUES (?, ?, ?, datetime('now'), 1)");
                    $stmt->execute([$reg_username, $reg_email, $hashed_password]);
                    
                    $success = "Registrazione avvenuta con successo! Puoi ora effettuare il login.";
                    $current_section = 'login';
                }
            } catch(PDOException $e) {
                $error = "Errore durante la registrazione: " . $e->getMessage();
            }
        }
    } else {
        // Login utente normale
        $username = clean_input($_POST["username"]);
        $password = clean_input($_POST["password"]);

        try {
            $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user) {
                // Verifica il numero di tentativi falliti
                if ($user['failed_login_attempts'] >= 5 && 
                    strtotime($user['last_failed_login']) > (time() - 1800)) { // 30 minuti di blocco
                    $error = "Account temporaneamente bloccato. Riprova più tardi.";
                } else {
                    if (password_verify($password, $user['password'])) {
                        // Reset dei tentativi falliti
                        $stmt = $conn->prepare("UPDATE users SET failed_login_attempts = 0, last_login = datetime('now') WHERE id = ?");
                        $stmt->execute([$user['id']]);
                        
                        $_SESSION["user_id"] = $user['id'];
                        $_SESSION["username"] = $user['username'];
                        $_SESSION["last_activity"] = time();
                        header("Location: user-dashboard.php");
                        exit();
                    } else {
                        // Incrementa i tentativi falliti
                        $stmt = $conn->prepare("UPDATE users SET failed_login_attempts = failed_login_attempts + 1, last_failed_login = datetime('now') WHERE id = ?");
                        $stmt->execute([$user['id']]);
                        $error = "Credenziali non valide.";
                    }
                }
    } else {
        $error = "Credenziali non valide.";
    }
        } catch(PDOException $e) {
            $error = "Errore durante il login: " . $e->getMessage();
        }
    }
}

// Verifica timeout della sessione
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) { // 30 minuti
    session_unset();
    session_destroy();
    header("Location: admin-login.php");
    exit();
}
$_SESSION['last_activity'] = time();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $current_section === 'login' ? 'Login' : 'Registrazione' ?> - BetPredict</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #000;
            color: #fff;
            font-family: 'Inter', sans-serif;
            margin: 0;
            min-height: 100vh;
        }

        .login-container {
            max-width: 1200px;
            margin: 1rem auto;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: calc(100vh - 80px);
        }

        .auth-box {
            background: rgba(255, 255, 255, 0.05);
            padding: 1.5rem;
            border-radius: 8px;
            width: 100%;
            max-width: 320px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .section-switch {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .section-btn {
            padding: 0.5rem 1.2rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            font-size: 0.8rem;
            text-decoration: none;
        }

        .section-btn.active {
            background: #fff;
            color: #000;
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .form-group label {
            color: #fff;
            font-weight: 500;
            font-size: 0.8rem;
        }

        .form-group input {
            padding: 0.5rem 0.7rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 4px;
            font-size: 0.8rem;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
        }

        .form-group input:focus {
            outline: none;
            border-color: #fff;
            background: rgba(255, 255, 255, 0.1);
        }

        .login-btn {
            background: #fff;
            color: #000;
            padding: 0.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-top: 0.8rem;
            text-transform: uppercase;
        }

        .login-btn:hover {
            background: rgba(255, 255, 255, 0.9);
        }

        .error-message {
            color: #ff6b6b;
            text-align: center;
            margin-top: 0.8rem;
            padding: 0.5rem;
            background: rgba(255, 107, 107, 0.1);
            border-radius: 4px;
            font-size: 0.8rem;
        }

        .success-message {
            color: #51cf66;
            text-align: center;
            margin-top: 0.8rem;
            padding: 0.5rem;
            background: rgba(81, 207, 102, 0.1);
            border-radius: 4px;
            font-size: 0.8rem;
        }

        .login-header {
            text-align: center;
            margin-bottom: 1.2rem;
        }

        .login-header img {
            width: 80px;
            margin-bottom: 0.6rem;
        }

        .login-header h1 {
            color: #fff;
            margin-bottom: 0.2rem;
            font-size: 1.2rem;
            font-weight: 700;
        }

        .login-header p {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.8rem;
        }

        .login-type {
            display: flex;
            justify-content: center;
            gap: 0.4rem;
            margin-bottom: 1rem;
            background: rgba(255, 255, 255, 0.05);
            padding: 0.3rem;
            border-radius: 4px;
        }

        .login-type-btn {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            background: transparent;
            color: #fff;
            font-size: 0.8rem;
            flex: 1;
        }

        .login-type-btn.active {
            background: #fff;
            color: #000;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <div class="logo-container">
                <img src="betpredictlogo.png" alt="BetPredict Logo" class="logo">
                <h1 class="site-title">BetPredict</h1>
            </div>
            <div class="nav-links">
                <a href="home.html" class="nav-link">Home</a>
                <a href="#" class="nav-link">Abbonamenti</a>
                <a href="#" class="nav-link">Pronostici</a>
                <a href="#" class="nav-link">Pronostici Free</a>
                <a href="#" class="nav-link">Bet Sniffer</a>
                <a href="#" class="nav-link">Storico</a>
                <a href="#" class="nav-link">Contatti</a>
            </div>
        </div>
    </nav>

    <div class="login-container">
        <div class="section-switch">
            <a href="?section=login" class="section-btn <?= $current_section === 'login' ? 'active' : '' ?>">Login</a>
            <a href="?section=register" class="section-btn <?= $current_section === 'register' ? 'active' : '' ?>">Registrazione</a>
        </div>

        <?php if ($current_section === 'login'): ?>
            <div class="auth-box">
                <div class="login-header">
                    <img src="betpredictlogo.png" alt="BetPredict Logo">
                    <h1>Accedi</h1>
                    <p>Benvenuto su BetPredict</p>
                </div>

                <div class="login-type">
                    <button class="login-type-btn active" onclick="showAdminLogin()">Admin</button>
                    <button class="login-type-btn" onclick="showUserLogin()">Utente</button>
                </div>

                <form method="post" class="login-form" id="adminLoginForm">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <input type="hidden" name="admin_login" value="1">
                    <button type="submit" class="login-btn">Accedi come Admin</button>
                    <?php if ($error): ?>
                        <p class="error-message"><?= $error ?></p>
                    <?php endif; ?>
                </form>

                <form method="post" class="login-form" id="userLoginForm" style="display: none;">
                    <div class="form-group">
                        <label for="user_username">Username</label>
                        <input type="text" id="user_username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="user_password">Password</label>
                        <input type="password" id="user_password" name="password" required>
                    </div>
                    <button type="submit" class="login-btn">Accedi come Utente</button>
                </form>
            </div>
        <?php else: ?>
            <div class="auth-box">
                <div class="login-header">
                    <img src="betpredictlogo.png" alt="BetPredict Logo">
                    <h1>Registrati</h1>
                    <p>Crea il tuo account BetPredict</p>
                </div>

                <form method="post" class="login-form">
                    <div class="form-group">
                        <label for="reg_username">Username</label>
                        <input type="text" id="reg_username" name="reg_username" required>
                    </div>
                    <div class="form-group">
                        <label for="reg_email">Email</label>
                        <input type="email" id="reg_email" name="reg_email" required>
                    </div>
                    <div class="form-group">
                        <label for="reg_password">Password</label>
                        <input type="password" id="reg_password" name="reg_password" required>
                    </div>
                    <div class="form-group">
                        <label for="reg_confirm_password">Conferma Password</label>
                        <input type="password" id="reg_confirm_password" name="reg_confirm_password" required>
                    </div>
                    <input type="hidden" name="register" value="1">
                    <button type="submit" class="login-btn">Registrati</button>
                    <?php if ($success): ?>
                        <p class="success-message"><?= $success ?></p>
                    <?php endif; ?>
    </form>
            </div>
    <?php endif; ?>
    </div>

    <script>
        function showAdminLogin() {
            document.getElementById('adminLoginForm').style.display = 'flex';
            document.getElementById('userLoginForm').style.display = 'none';
            document.querySelectorAll('.login-type-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector('.login-type-btn:first-child').classList.add('active');
        }

        function showUserLogin() {
            document.getElementById('adminLoginForm').style.display = 'none';
            document.getElementById('userLoginForm').style.display = 'flex';
            document.querySelectorAll('.login-type-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector('.login-type-btn:last-child').classList.add('active');
        }
    </script>
</body>
</html>