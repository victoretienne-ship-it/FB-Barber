<?php
/**
 * POST api/reserver.php (JSON)
 * { prestations: ["coupe-homme", "barbe"], date: "2026-11-07", heure: "09:30",
 *   nom, telephone, precision, site_web (champ piège, doit rester vide) }
 * Enregistre le rendez-vous si le créneau est toujours libre, puis prévient le salon par e-mail
 * (si une adresse est configurée dans config.php).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

fb_boot();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fb_json(['ok' => false, 'error' => 'methode', 'message' => 'Méthode non autorisée.'], 405);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    fb_json(['ok' => false, 'error' => 'format', 'message' => 'Demande illisible.'], 400);
}

// Champ piège invisible : seuls les robots le remplissent
if (trim((string) ($input['site_web'] ?? '')) !== '') {
    fb_json(['ok' => false, 'error' => 'refuse', 'message' => 'Demande refusée.'], 400);
}

$salon = fb_salon();
$cfg = fb_config();

// Prestations : durée, prix et libellé recalculés côté serveur
$ids = array_values(array_unique(array_map('strval', (array) ($input['prestations'] ?? []))));
$catalog = array_column($salon['services'], null, 'id');
$chosen = [];
foreach ($ids as $id) {
    if (!isset($catalog[$id])) {
        fb_json(['ok' => false, 'error' => 'prestation', 'message' => 'Prestation inconnue. Rechargez la page et recommencez.'], 422);
    }
    $chosen[] = $catalog[$id];
}
if (!$chosen) {
    fb_json(['ok' => false, 'error' => 'prestation', 'message' => 'Choisissez au moins une prestation.'], 422);
}
$duration = array_sum(array_column($chosen, 'duration'));
$price = array_sum(array_column($chosen, 'price'));
$label = implode(' + ', array_column($chosen, 'name'));

// Date et heure
$date = (string) ($input['date'] ?? '');
$time = (string) ($input['heure'] ?? '');
if (!fb_valid_date($date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
    fb_json(['ok' => false, 'error' => 'date', 'message' => 'Date ou heure invalide.'], 422);
}
if (!in_array($date, fb_bookable_days(), true)) {
    fb_json(['ok' => false, 'error' => 'date', 'message' => 'Cette date n’est pas ouverte à la réservation.'], 422);
}
$start = fb_min($time);

// Coordonnées
$name = fb_clean($input['nom'] ?? '', 80);
$phone = fb_clean($input['telephone'] ?? '', 30);
$note = fb_clean($input['precision'] ?? '', 300);
$errors = [];
if (mb_strlen($name) < 2) {
    $errors['nom'] = 'Indiquez votre prénom et votre nom.';
}
if (!fb_valid_phone($phone)) {
    $errors['telephone'] = 'Indiquez un numéro valide, par exemple 06 12 34 56 78.';
}
if ($errors) {
    fb_json(['ok' => false, 'error' => 'champs', 'message' => reset($errors), 'champs' => $errors], 422);
}

$db = fb_db();
$ipHash = fb_ip_hash();

// Limite : 6 rendez-vous par heure depuis une même connexion
$since = (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
$count = $db->prepare("SELECT COUNT(*) FROM reservations WHERE ip_hash = ? AND cree_le > ? AND type = 'client'");
$count->execute([$ipHash, $since]);
if ((int) $count->fetchColumn() >= 6) {
    fb_json(['ok' => false, 'error' => 'limite', 'message' => 'Trop de réservations depuis cette connexion. Réessayez plus tard ou appelez le salon.'], 429);
}

// Vérification du créneau et enregistrement dans la même transaction : pas de double réservation
$db->exec('BEGIN IMMEDIATE');
try {
    if (!in_array($start, fb_free_slots($date, $duration), true)) {
        $db->exec('ROLLBACK');
        fb_json(['ok' => false, 'error' => 'creneau_pris', 'message' => 'Ce créneau n’est plus disponible. Choisissez-en un autre.'], 409);
    }
    $insert = $db->prepare(
        'INSERT INTO reservations (date, debut, fin, type, statut, prestations, libelle, prix, nom, telephone, email, precision, cree_le, ip_hash)
         VALUES (?, ?, ?, \'client\', \'confirme\', ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $date, $start, $start + $duration, json_encode($ids), $label, $price,
        $name, $phone, '', $note, (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), $ipHash,
    ]);
    $id = (int) $db->lastInsertId();
    $db->exec('COMMIT');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->exec('ROLLBACK');
    }
    fb_json(['ok' => false, 'error' => 'serveur', 'message' => 'Le rendez-vous n’a pas pu être enregistré. Réessayez ou appelez le salon.'], 500);
}

// E-mail au salon (après l'enregistrement : un échec d'envoi n'annule pas le rendez-vous)
$when = fb_date_fr($date) . ' à ' . $time . ' (fin vers ' . fb_hhmm($start + $duration) . ')';
$agenda = fb_site_url() . '/barber/?jour=' . $date;
$body = "Nouveau rendez-vous\n\n"
    . 'Quand : ' . $when . "\n"
    . 'Prestations : ' . $label . ' (' . $price . " €)\n"
    . 'Client : ' . $name . "\n"
    . 'Téléphone : ' . $phone . "\n"
    . ($note !== '' ? 'Précision : ' . $note . "\n" : '')
    . "\nAgenda : " . $agenda . "\n";
fb_mail((string) $cfg['notify_email'], 'Nouveau rendez-vous : ' . fb_date_fr($date) . ' à ' . $time, $body);

fb_json([
    'ok' => true,
    'reservation' => [
        'id' => $id,
        'date' => $date,
        'heure' => $time,
        'fin' => fb_hhmm($start + $duration),
        'prestations' => $label,
        'prix' => $price,
    ],
]);
