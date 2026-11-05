<?php
/**
 * GET api/creneaux.php?duree=75
 * Renvoie, pour chaque jour ouvert à la réservation, les heures de début encore libres
 * pour une durée donnée (en minutes).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

fb_boot();

$duration = (int) ($_GET['duree'] ?? 0);
if ($duration < 5 || $duration > 600) {
    fb_json(['ok' => false, 'error' => 'duree', 'message' => 'Durée invalide.'], 422);
}

$days = [];
foreach (fb_bookable_days() as $date) {
    $days[$date] = array_map('fb_hhmm', fb_free_slots($date, $duration));
}

fb_json([
    'ok' => true,
    'duree' => $duration,
    'genere_le' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
    'jours' => $days,
]);
