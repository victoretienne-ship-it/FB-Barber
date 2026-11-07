<?php
/**
 * Rappels par e-mail, lancés par une tâche planifiée (cron) toutes les heures :
 *   php /chemin/vers/public_html/api/rappels.php
 * Chaque client qui a donné son e-mail reçoit un rappel dans les 24 heures qui précèdent son
 * rendez-vous. Pas de rappel pour un rendez-vous pris moins de 12 heures avant.
 * Inaccessible depuis le web.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/lib.php';

$cfg = fb_config();
if ($cfg === null || empty($cfg['reminders'])) {
    exit(0);
}
date_default_timezone_set($cfg['timezone'] ?: 'Europe/Paris');

$db = fb_db();
$now = new DateTimeImmutable('now');
$stmt = $db->prepare(
    "SELECT * FROM reservations
     WHERE type = 'client' AND statut = 'confirme' AND rappel_envoye = 0 AND email <> '' AND date BETWEEN ? AND ?"
);
$stmt->execute([$now->format('Y-m-d'), $now->modify('+2 days')->format('Y-m-d')]);
$mark = $db->prepare('UPDATE reservations SET rappel_envoye = 1 WHERE id = ?');

$sent = 0;
foreach ($stmt->fetchAll() as $row) {
    $start = fb_start($row);
    $hoursLeft = ($start->getTimestamp() - $now->getTimestamp()) / 3600;
    if ($hoursLeft <= 0 || $hoursLeft > 24) {
        continue;
    }
    $bookedAhead = ($start->getTimestamp() - (new DateTimeImmutable($row['cree_le']))->getTimestamp()) / 3600;
    if ($bookedAhead >= 12 && fb_mail_client($row, 'rappel')) {
        $sent++;
    }
    $mark->execute([(int) $row['id']]); // un seul essai par rendez-vous
}

echo date('Y-m-d H:i') . " : {$sent} rappel(s) envoyé(s)\n";
