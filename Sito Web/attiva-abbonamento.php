<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION["utente_id"])) {
    header("Location: login.php");
    exit();
}

$oggi = date("Y-m-d");
$scadenza = date("Y-m-d", strtotime("+30 days"));

// Aggiorna abbonamento
$stmt = $db->prepare("UPDATE utenti SET abbonato = 1, scadenza_abbonamento = :scadenza WHERE id = :id");
$stmt->execute([
    ":scadenza" => $scadenza,
    ":id" => $_SESSION["utente_id"]
]);

$_SESSION["abbonato"] = 1;
$_SESSION["scadenza_abbonamento"] = $scadenza;

header("Location: area-utente.php");
exit();