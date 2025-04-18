<?php
session_start();
require_once 'config.php';

$errore = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = $_POST["email"];
    $password = $_POST["password"];

    $stmt = $db->prepare("SELECT * FROM utenti WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $utente = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($utente && password_verify($password, $utente["password"])) {
        $_SESSION["utente_id"] = $utente["id"];
        $_SESSION["email"] = $utente["email"];
        $_SESSION["abbonato"] = $utente["abbonato"];
        header("Location: area-utente.php");
        exit();
    } else {
        $errore = "Credenziali non valide.";
    }
}
if ($utente && password_verify($password, $utente["password"])) {
    $oggi = date("Y-m-d");
    $scad = $utente["scadenza_abbonamento"];

    $ancora_valido = ($utente["abbonato"] == 1 && $scad && $oggi <= $scad);

    $_SESSION["utente_id"] = $utente["id"];
    $_SESSION["email"] = $utente["email"];
    $_SESSION["abbonato"] = $ancora_valido ? 1 : 0;
    $_SESSION["scadenza_abbonamento"] = $scad;

    if (!$ancora_valido) {
        // scaduto → aggiorna anche nel DB
        $update = $db->prepare("UPDATE utenti SET abbonato = 0 WHERE id = :id");
        $update->execute([":id" => $utente["id"]]);
    }

    header("Location: area-utente.php");
    exit();
}
    



?>

<form method="post">
    <h2>Login Utente</h2>
    Email: <input type="email" name="email" required><br>
    Password: <input type="password" name="password" required><br>
    <button type="submit">Login</button>
</form>
<p style="color:red;"><?= $errore ?></p>