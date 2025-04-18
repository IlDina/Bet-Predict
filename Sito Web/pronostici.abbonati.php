<?php
session_start();
if (!isset($_SESSION["utente_id"]) || $_SESSION["abbonato"] != 1) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

$stmt = $db->query("SELECT * FROM pronostici WHERE categoria = 'abbonamento' AND stato = 'in attesa' ORDER BY data_evento ASC");
$pronostici = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h2>Pronostici Riservati agli Abbonati</h2>

<?php foreach ($pronostici as $p): ?>
    <div style="border:1px solid #ccc; padding:10px; margin:10px 0;">
        <strong><?= htmlspecialchars($p['evento']) ?></strong><br>
        Data: <?= $p['data_evento'] ?><br>
        Pronostico: <?= $p['pronostico'] ?> @<?= $p['quota'] ?>
    </div>
<?php endforeach; ?>