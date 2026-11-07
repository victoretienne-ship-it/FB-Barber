<?php
/**
 * POST api/annuler.php (JSON) { ref: JETON }
 * Annulation par le client, possible jusqu'au délai fixé dans assets/js/salon.js (cancelUntil).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

fb_boot();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fb_json(['ok' => false, 'error' => 'methode', 'message' => 'Méthode non autorisée.'], 405);
}
$input = json_decode((string) file_get_contents('php://input'), true);
$row = fb_find_by_token((string) (is_array($input) ? ($input['ref'] ?? '') : ''));
if (!$row) {
    fb_json(['ok' => false, 'error' => 'introuvable', 'message' => 'Ce lien ne correspond à aucun rendez-vous.'], 404);
}
if ($row['statut'] === 'annule') {
    fb_json(['ok' => false, 'error' => 'deja_annule', 'message' => 'Ce rendez-vous est déjà annulé.'], 409);
}
if (!fb_can_change($row)) {
    $phone = fb_salon()['phone'];
    fb_json(['ok' => false, 'error' => 'trop_tard', 'message' => 'Il est trop tard pour annuler en ligne.' . ($phone !== '' ? ' Appelez le salon au ' . $phone . '.' : '')], 409);
}

fb_db()->prepare("UPDATE reservations SET statut = 'annule', annule_par = 'client', annule_le = ? WHERE id = ?")
    ->execute([(new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), (int) $row['id']]);

fb_mail_salon($row, 'Rendez-vous annulé par le client');
fb_mail_client($row, 'annulation');

fb_json(['ok' => true]);
