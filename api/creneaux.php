<?php
/**
 * GET api/creneaux.php?duree=75[&sauf=JETON]
 * Renvoie, pour chaque jour ouvert à la réservation, les heures de début encore libres
 * pour une durée donnée (en minutes). « sauf » : jeton d'un rendez-vous en cours de
 * déplacement, dont le créneau actuel compte comme libre.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

fb_boot();

$duration = (int) ($_GET['duree'] ?? 0);
if ($duration < 5 || $duration > 600) {
    fb_json(['ok' => false, 'error' => 'duree', 'message' => 'Durée invalide.'], 422);
}

$except = null;
$exceptDate = null;
if (!empty($_GET['sauf'])) {
    $own = fb_find_by_token((string) $_GET['sauf']);
    if ($own && $own['statut'] === 'confirme') {
        $except = (int) $own['id'];
        $exceptDate = $own['date'];
    }
}

$days = [];
foreach (fb_bookable_days() as $date) {
    $days[$date] = array_map('fb_hhmm', fb_free_slots($date, $duration, $date === $exceptDate ? $except : null));
}

fb_json([
    'ok' => true,
    'duree' => $duration,
    'genere_le' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
    'jours' => $days,
]);
