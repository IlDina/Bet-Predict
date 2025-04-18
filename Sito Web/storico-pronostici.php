<?php
require_once 'config.php';

$stmtVinti = $db->query("SELECT * FROM pronostici WHERE stato = 'vinto' ORDER BY data_evento DESC");
$stmtPersi = $db->query("SELECT * FROM pronostici WHERE stato = 'perso' ORDER BY data_evento DESC");

$vinti = $stmtVinti->fetchAll(PDO::FETCH_ASSOC);
$persi = $stmtPersi->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Storico Pronostici</title>
</head>
<body>
    <h1>Storico Pronostici</h1>

    <h2>✅ Pronostici Vinti</h2>
    <?php foreach ($vinti as $p): ?>
        <div style="border:1px solid green; padding:10px; margin:10px 0;">
            <strong><?= $p['evento'] ?></strong> - <?= $p['pronostico'] ?> @<?= $p['quota'] ?>
        </div>
    <?php endforeach; ?>

    <h2>❌ Pronostici Persi</h2>
    <?php foreach ($persi as $p): ?>
        <div style="border:1px solid red; padding:10px; margin:10px 0;">
            <strong><?= $p['evento'] ?></strong> - <?= $p['pronostico'] ?> @<?= $p['quota'] ?>
        </div>
    <?php endforeach; ?>
</body>
</html>