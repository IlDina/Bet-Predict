<?php
session_start();
if (!isset($_SESSION["admin_logged_in"])) {
    header("Location: admin-login.php");
    exit();
}
require_once 'config.php';

$success = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $sport = $_POST["sport"];
    $evento = $_POST["evento"];
    $data_evento = $_POST["data_evento"];
    $pronostico = $_POST["pronostico"];
    $quota = $_POST["quota"];
    $note = $_POST["note"];
    $categoria = $_POST["categoria"];

    $stmt = $db->prepare("INSERT INTO pronostici (sport, evento, data_evento, pronostico, quota, note, categoria)
                          VALUES (:sport, :evento, :data_evento, :pronostico, :quota, :note, :categoria)");
    $stmt->execute([
        ':sport' => $sport,
        ':evento' => $evento,
        ':data_evento' => $data_evento,
        ':pronostico' => $pronostico,
        ':quota' => $quota,
        ':note' => $note,
        ':categoria' => $categoria
    ]);

    $success = "Pronostico inserito con successo!";
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Nuovo Pronostico</title>
</head>
<body>
    <h2>Nuovo Pronostico</h2>
    <form method="post">
        <label>Sport:</label><br>
        <input type="text" name="sport" required><br><br>

        <label>Evento (es. Juventus vs Milan):</label><br>
        <input type="text" name="evento" required><br><br>

        <label>Data e ora evento:</label><br>
        <input type="datetime-local" name="data_evento" required><br><br>

        <label>Pronostico (es. 1X2, Over 2.5, ecc.):</label><br>
        <input type="text" name="pronostico" required><br><br>

        <label>Quota:</label><br>
        <input type="number" step="0.01" name="quota"><br><br>

        <label>Note / Analisi (facoltativo):</label><br>
        <textarea name="note"></textarea><br><br>

        <label>Categoria:</label><br>
        <select name="categoria" required>
            <option value="gratis">Gratis</option>
            <option value="abbonamento">Abbonamento</option>
        </select><br><br>

        <input type="submit" value="Pubblica Pronostico">
    </form>

    <?php if ($success): ?>
        <p style="color:green;"><?= $success ?></p>
    <?php endif; ?>
</body>
</html>