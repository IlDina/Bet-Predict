<?php
session_start();
if (!isset($_SESSION["utente_id"])) {
    header("Location: login.php");
    exit();
}
?>

<h2>Benvenuto, <?= htmlspecialchars($_SESSION["email"]) ?></h2>

<?php if ($_SESSION["abbonato"]): ?>
    <p>✅ Abbonamento attivo fino al <strong><?= $_SESSION["scadenza_abbonamento"] ?></strong></p>
<?php else: ?>
    <p>⚠️ Non sei abbonato o il tuo abbonamento è scaduto.</p>
<?php endif; ?>

<a href="logout.php">Logout</a>