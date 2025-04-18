<?php
require_once 'config.php';

$messaggio = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = $_POST["email"];
    $password = password_hash($_POST["password"], PASSWORD_DEFAULT);

    $stmt = $db->prepare("INSERT INTO utenti (email, password) VALUES (:email, :password)");
    try {
        $stmt->execute([
            ':email' => $email,
            ':password' => $password
        ]);
        $messaggio = "Registrazione avvenuta con successo!";
    } catch (PDOException $e) {
        $messaggio = "Errore: " . $e->getMessage();
    }
}
?>

<form method="post">
    <h2>Registrati</h2>
    Email: <input type="email" name="email" required><br>
    Password: <input type="password" name="password" required><br>
    <button type="submit">Registrati</button>
</form>
<p><?= $messaggio ?></p>