<?php
/**
 * POST api/reserver.php (JSON)
 * { prestations: ["coupe-homme", "barbe"], date: "2026-11-07", heure: "09:30",
 *   nom, telephone, email, precision, remplace (facultatif), site_web (champ piège, vide) }
 * Enregistre le rendez-vous si le créneau est toujours libre. Avec « remplace » (jeton d'un
 * rendez-vous existant), l'ancien rendez-vous est annulé dans la même opération : c'est un déplacement.
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
$email = fb_clean($input['email'] ?? '', 120);
$note = fb_clean($input['precision'] ?? '', 300);
$errors = [];
if (mb_strlen($name) < 2) {
    $errors['nom'] = 'Indiquez votre prénom et votre nom.';
}
if (!fb_valid_phone($phone)) {
    $errors['telephone'] = 'Indiquez un numéro valide, par exemple 06 12 34 56 78.';
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Vérifiez l’adresse e-mail.';
}
if ($errors) {
    fb_json(['ok' => false, 'error' => 'champs', 'message' => reset($errors), 'champs' => $errors], 422);
}

// Déplacement : le rendez-vous d'origine doit exister et être encore modifiable
$old = null;
$replace = (string) ($input['remplace'] ?? '');
if ($replace !== '') {
    $old = fb_find_by_token($replace);
    if (!$old) {
        fb_json(['ok' => false, 'error' => 'introuvable', 'message' => 'Le rendez-vous à déplacer est introuvable.'], 404);
    }
    if ($old['statut'] === 'annule') {
        fb_json(['ok' => false, 'error' => 'deja_annule', 'message' => 'Ce rendez-vous est déjà annulé : prenez un nouveau rendez-vous.'], 409);
    }
    if (!fb_can_change($old)) {
        $call = $salon['phone'] !== '' ? ' Appelez le salon au ' . $salon['phone'] . '.' : '';
        fb_json(['ok' => false, 'error' => 'trop_tard', 'message' => 'Ce rendez-vous ne peut plus être déplacé en ligne.' . $call], 409);
    }
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
$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$token = fb_token();
$db->exec('BEGIN IMMEDIATE');
try {
    $except = ($old && $old['date'] === $date) ? (int) $old['id'] : null;
    if (!in_array($start, fb_free_slots($date, $duration, $except), true)) {
        $db->exec('ROLLBACK');
        fb_json(['ok' => false, 'error' => 'creneau_pris', 'message' => 'Ce créneau n’est plus disponible. Choisissez-en un autre.'], 409);
    }
    $insert = $db->prepare(
        'INSERT INTO reservations (date, debut, fin, type, statut, prestations, libelle, prix, nom, telephone, email, precision, cree_le, ip_hash, jeton, source)
         VALUES (?, ?, ?, \'client\', \'confirme\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'en_ligne\')'
    );
    $insert->execute([
        $date, $start, $start + $duration, json_encode($ids), $label, $price,
        $name, $phone, $email, $note, $now, $ipHash, $token,
    ]);
    $id = (int) $db->lastInsertId();
    if ($old) {
        $db->prepare("UPDATE reservations SET statut = 'annule', annule_par = 'client', annule_le = ? WHERE id = ?")
            ->execute([$now, (int) $old['id']]);
    }
    $db->exec('COMMIT');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->exec('ROLLBACK');
    }
    fb_json(['ok' => false, 'error' => 'serveur', 'message' => 'Le rendez-vous n’a pas pu être enregistré. Réessayez ou appelez le salon.'], 500);
}

$row = $db->query('SELECT * FROM reservations WHERE id = ' . $id)->fetch();

// E-mails (après l'enregistrement : un échec d'envoi n'annule pas le rendez-vous)
fb_mail_salon($row, $old ? 'Rendez-vous déplacé (ancien horaire : ' . fb_date_fr($old['date']) . ' à ' . fb_hhmm((int) $old['debut']) . ')' : 'Nouveau rendez-vous');
$clientMailed = fb_mail_client($row, $old ? 'deplacement' : 'confirmation');

fb_json([
    'ok' => true,
    'reservation' => [
        'ref' => $token,
        'date' => $date,
        'heure' => $time,
        'fin' => fb_hhmm($start + $duration),
        'prestations' => $label,
        'prix' => $price,
        'deplace' => (bool) $old,
        'email_envoye' => $clientMailed,
    ],
]);
