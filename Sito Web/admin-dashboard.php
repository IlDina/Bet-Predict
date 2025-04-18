<?php
session_start();
if (!isset($_SESSION["admin_logged_in"])) {
    header("Location: admin-login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Pannello Admin</title>
</head>
<body>
    <h1>Benvenuto nel Pannello Admin</h1>
    <p><a href="logout.php">Esci</a></p>
    <!-- Qui poi aggiungiamo le funzioni che vuoi: pubblicazione pronostici, statistiche ecc -->
</body>
</html>