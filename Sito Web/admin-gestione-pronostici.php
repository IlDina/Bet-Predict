<?php
session_start();
if (!isset($_SESSION["admin_logged_in"])) {
    header("Location: admin-login.php");
    exit();
}
require_once 'config.php';

// Se admin clicca "vinto" o "perso"
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["id"], $_POST["azione"])) {
    $id = $_POST["id"];
    $azione = $_POST["azione"];
    if (in_array($azione, ["vinto", "perso"])) {
        $stmt = $db->prepare("UPDATE pronostici SET stato = :stato WHERE id = :id");
        $stmt->execute([
            ":stato" => $azione,
            ":id" => $id
        ]);
    }
}

// Recupera tutti i pronostici in attesa
$stmt = $db->query("SELECT * FROM pronostici WHERE stato = 'in attesa' ORDER BY data_evento ASC");
$pronostici = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Gestione Pronostici</title>
</head>
<body>
    <h1>Gestione Pronostici</h1>
    <p><a href="admin-dashboard.php">Torna alla Dashboard</a></p>

    <?php if (count($pronostici) === 0): ?>
        <p>Nessun pronostico da gestire.</p>
    <?php else: ?>
        <?php foreach ($pronostici as $p): ?>
            <div style="border:1px solid #aaa; padding:10px; margin:10px 0;">
                <strong><?= $p['evento'] ?></strong> - <?= $p['sport'] ?><br>
                Data evento: <?= $p['data_evento'] ?><br>
                Pronostico: <?= $p['pronostico'] ?> @<?= $p['quota'] ?><br>
                Note: <?= nl2br($p['note']) ?><br><br>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <input type="hidden" name="azione" value="vinto">
                    <button type="submit">✅ Vinto</button>
                </form>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <input type="hidden" name="azione" value="perso">
                    <button type="submit">❌ Perso</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>