<?php
try {
    $db = new PDO("sqlite:DB/pronostici.db"); // Percorso corretto alla tua cartella
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Errore di connessione al database: " . $e->getMessage();
    exit();
}
?>