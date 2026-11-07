<?php
/**
 * GET api/rendez-vous.php?ref=JETON
 * Détails d'un rendez-vous pour la page « Mon rendez-vous ». Le jeton (secret, reçu par le
 * client sur l'écran de confirmation et dans l'e-mail) est la seule clé d'accès.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

fb_boot();

$row = fb_find_by_token((string) ($_GET['ref'] ?? ''));
if (!$row) {
    fb_json(['ok' => false, 'error' => 'introuvable', 'message' => 'Ce lien ne correspond à aucun rendez-vous.'], 404);
}

$salon = fb_salon();
$start = fb_start($row);
$limit = $start->modify('-' . max(0, (int) $salon['cancelUntil']) . ' hours');

fb_json([
    'ok' => true,
    'rendez_vous' => [
        'ref' => $row['jeton'],
        'date' => $row['date'],
        'date_texte' => fb_date_fr($row['date']),
        'heure' => fb_hhmm((int) $row['debut']),
        'fin' => fb_hhmm((int) $row['fin']),
        'prestations' => json_decode($row['prestations'], true) ?: [],
        'libelle' => $row['libelle'],
        'prix' => (int) $row['prix'],
        'nom' => $row['nom'],
        // Pour préremplir le formulaire en cas de déplacement (le jeton secret est la clé d'accès)
        'telephone' => $row['telephone'],
        'email' => $row['email'],
        'statut' => $row['statut'],
        'annule_par' => $row['annule_par'],
        'passe' => $start <= new DateTimeImmutable('now'),
        'modifiable' => fb_can_change($row),
        'limite' => fb_date_fr($limit->format('Y-m-d')) . ' à ' . $limit->format('H:i'),
    ],
]);
