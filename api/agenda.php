<?php
/**
 * GET api/agenda.php?ref=JETON
 * Fichier calendrier (.ics) du rendez-vous, pour l'ajouter à l'agenda du téléphone.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$cfg = fb_config();
date_default_timezone_set($cfg['timezone'] ?? 'Europe/Paris');
$row = $cfg ? fb_find_by_token((string) ($_GET['ref'] ?? '')) : null;
if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Rendez-vous introuvable.');
}

$salon = fb_salon();
$utc = new DateTimeZone('UTC');
$format = static fn (DateTimeImmutable $d) => $d->setTimezone($utc)->format('Ymd\THis\Z');
$escape = static fn (string $s) => str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $s);
$start = fb_start($row);
$end = $start->modify('+' . ((int) $row['fin'] - (int) $row['debut']) . ' minutes');

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//FB Barber//Reservation//FR',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:' . $row['jeton'] . '@fb-barber',
    'DTSTAMP:' . $format(new DateTimeImmutable('now')),
    'DTSTART:' . $format($start),
    'DTEND:' . $format($end),
    'SUMMARY:' . $escape($salon['name'] . ' : ' . $row['libelle']),
    'LOCATION:' . $escape((string) $salon['address']),
    'DESCRIPTION:' . $escape($row['libelle'] . ' (' . (int) $row['prix'] . " €)\nDéplacer ou annuler : " . fb_manage_url($row['jeton'])
        . ($salon['phone'] !== '' ? "\nSalon : " . $salon['phone'] : '')),
    'STATUS:' . ($row['statut'] === 'annule' ? 'CANCELLED' : 'CONFIRMED'),
    'BEGIN:VALARM',
    'TRIGGER:-PT1H',
    'ACTION:DISPLAY',
    'DESCRIPTION:' . $escape('Rendez-vous ' . $salon['name'] . ' dans 1 heure'),
    'END:VALARM',
    'END:VEVENT',
    'END:VCALENDAR',
];

// Lignes de 75 octets au plus, comme l'exige le format iCalendar
$folded = array_map(static function (string $line): string {
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--; // ne pas couper un caractère accentué en deux
        }
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line;
}, $lines);

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="rendez-vous-fb-barber.ics"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo implode("\r\n", $folded) . "\r\n";
