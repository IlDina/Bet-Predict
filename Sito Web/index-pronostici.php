<?php
require_once 'config.php';

// Carico tutti i pronostici "gratis" ancora in attesa
$stmt = $db->query("SELECT * FROM pronostici WHERE categoria = 'gratis' AND stato = 'in attesa' ORDER BY data_evento ASC");
$pronostici = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Pronostici Gratis</title>
</head>
<body>
    <h1>Pronostici Gratuiti</h1>

    <?php if (count($pronostici) === 0): ?>
        <p>Nessun pronostico disponibile al momento.</p>
    <?php else: ?>
        <?php foreach ($pronostici as $p): ?>
            <div style="border:1px solid #ccc; padding:10px; margin:10px 0;">
                <strong><?= htmlspecialchars($p['evento']) ?></strong> - <?= htmlspecialchars($p['sport']) ?><br>
                Data evento: <?= $p['data_evento'] ?><br>
                Pronostico: <?= $p['pronostico'] ?> @<?= $p['quota'] ?><br>
                <?= nl2br(htmlspecialchars($p['note'])) ?><br>
                Categoria: <?= $p['categoria'] ?><br>
                <em>Pubblicato il: <?= $p['data_pubblicazione'] ?></em>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>