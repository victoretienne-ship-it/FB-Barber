<?php
/**
 * FB Barber : fonctions communes du serveur de réservation.
 * Les horaires et les prestations viennent de assets/js/salon.js (même fichier que le site).
 * Les réglages privés (mot de passe, e-mails) viennent de api/config.php.
 */
declare(strict_types=1);

const FB_ROOT = __DIR__ . '/..';
const FB_DAYS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
const FB_MONTHS = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/** Réglages privés. Renvoie null tant que config.php n'existe pas ou que le mot de passe est trop court. */
function fb_config(): ?array
{
    static $cfg = false;
    if ($cfg !== false) {
        return $cfg;
    }
    $file = __DIR__ . '/config.php';
    $data = is_file($file) ? require $file : null;
    if (!is_array($data) || mb_strlen((string) ($data['admin_password'] ?? '')) < 10) {
        return $cfg = null;
    }
    $data += ['notify_email' => '', 'mail_from' => '', 'confirm_client' => true, 'data_dir' => '', 'timezone' => 'Europe/Paris'];
    return $cfg = $data;
}

/** Configuration publique du salon, lue dans assets/js/salon.js (JSON avec lignes de commentaire). */
function fb_salon(): array
{
    static $salon = null;
    if ($salon !== null) {
        return $salon;
    }
    $raw = (string) @file_get_contents(FB_ROOT . '/assets/js/salon.js');
    $lines = array_filter(explode("\n", $raw), static fn ($l) => !str_starts_with(ltrim($l), '//'));
    $text = implode("\n", $lines);
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    $data = ($start !== false && $end !== false) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
    if (!is_array($data)) {
        fb_json(['ok' => false, 'error' => 'config_salon', 'message' => 'La configuration du salon est illisible (assets/js/salon.js).'], 500);
    }
    $data += ['name' => 'FB Barber', 'phone' => '', 'hours' => [], 'closedDates' => [], 'slotStep' => 15, 'leadTime' => 60, 'daysAhead' => 28, 'services' => []];
    return $salon = $data;
}

/** Démarrage commun aux points d'entrée publics. */
function fb_boot(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $cfg = fb_config();
    date_default_timezone_set($cfg['timezone'] ?? 'Europe/Paris');
    if ($cfg === null) {
        fb_json([
            'ok' => false,
            'error' => 'non_configure',
            'message' => 'La réservation en ligne n’est pas encore activée.',
        ], 503);
    }
}

function fb_json(array $data, int $status = 200): never
{
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Dossier des données : en dehors du dossier public quand c'est possible. */
function fb_data_dir(): string
{
    $cfg = fb_config() ?? [];
    $dir = (string) ($cfg['data_dir'] ?? '');
    if ($dir === '') {
        $docroot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $outside = $docroot !== '' ? dirname($docroot) . '/fb-barber-data' : '';
        $dir = ($outside !== '' && (is_dir($outside) || @is_writable(dirname($outside)))) ? $outside : FB_ROOT . '/data';
    }
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        fb_json(['ok' => false, 'error' => 'stockage', 'message' => 'Impossible de créer le dossier des réservations.'], 500);
    }
    // Si le dossier se trouve dans le site, on interdit son accès depuis le web
    $real = realpath($dir);
    $root = realpath(FB_ROOT);
    if ($real && $root && str_starts_with($real, $root) && !is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

function fb_db(): PDO
{
    static $db = null;
    if ($db) {
        return $db;
    }
    $db = new PDO('sqlite:' . fb_data_dir() . '/reservations.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 10000');
    $db->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS reservations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT NOT NULL,
            debut INTEGER NOT NULL,
            fin INTEGER NOT NULL,
            type TEXT NOT NULL DEFAULT 'client',
            statut TEXT NOT NULL DEFAULT 'confirme',
            prestations TEXT NOT NULL DEFAULT '[]',
            libelle TEXT NOT NULL DEFAULT '',
            prix INTEGER NOT NULL DEFAULT 0,
            nom TEXT NOT NULL DEFAULT '',
            telephone TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            precision TEXT NOT NULL DEFAULT '',
            cree_le TEXT NOT NULL,
            ip_hash TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_reservations_date ON reservations (date, statut);
        CREATE TABLE IF NOT EXISTS connexions (
            ip_hash TEXT NOT NULL,
            quand TEXT NOT NULL
        );
        SQL);
    return $db;
}

/** Empreinte de l'adresse IP (on ne stocke jamais l'adresse elle-même). */
function fb_ip_hash(): string
{
    $saltFile = fb_data_dir() . '/.sel';
    if (!is_file($saltFile)) {
        @file_put_contents($saltFile, bin2hex(random_bytes(16)));
    }
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string) @file_get_contents($saltFile));
}

function fb_min(string $hhmm): int
{
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return $h * 60 + $m;
}

function fb_hhmm(int $min): string
{
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

/** « 09:00 » → « 9h », « 19:30 » → « 19h30 » */
function fb_hour_fr(string $hhmm): string
{
    [$h, $m] = explode(':', $hhmm);
    return (int) $h . 'h' . ($m === '00' ? '' : $m);
}

function fb_valid_date(string $date): bool
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

/** « samedi 7 novembre » */
function fb_date_fr(string $date): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return FB_DAYS[(int) $d->format('w')] . ' ' . (int) $d->format('j') . ' ' . FB_MONTHS[(int) $d->format('n')];
}

/** Plages d'ouverture d'une date, fermetures exceptionnelles comprises. */
function fb_ranges(string $date): array
{
    $salon = fb_salon();
    if (in_array($date, $salon['closedDates'], true)) {
        return [];
    }
    $weekday = (string) DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('w');
    return $salon['hours'][$weekday] ?? [];
}

/** Intervalles occupés d'une journée (rendez-vous et blocages actifs). */
function fb_busy(string $date, ?int $exceptId = null): array
{
    $stmt = fb_db()->prepare("SELECT id, debut, fin FROM reservations WHERE date = ? AND statut = 'confirme'");
    $stmt->execute([$date]);
    $busy = [];
    foreach ($stmt as $row) {
        if ($exceptId !== null && (int) $row['id'] === $exceptId) {
            continue;
        }
        $busy[] = [(int) $row['debut'], (int) $row['fin']];
    }
    return $busy;
}

/** Jours ouverts à la réservation, à partir d'aujourd'hui. */
function fb_bookable_days(): array
{
    $salon = fb_salon();
    $today = new DateTimeImmutable('today');
    $days = [];
    for ($i = 0; $i < max(1, (int) $salon['daysAhead']); $i++) {
        $days[] = $today->modify("+{$i} day")->format('Y-m-d');
    }
    return $days;
}

/** Heures de début libres (en minutes) pour une durée donnée. */
function fb_free_slots(string $date, int $duration): array
{
    $salon = fb_salon();
    $now = new DateTimeImmutable('now');
    $today = $now->format('Y-m-d');
    if ($date < $today || $duration <= 0) {
        return [];
    }
    $ranges = fb_ranges($date);
    if (!$ranges) {
        return [];
    }
    $step = max(5, (int) $salon['slotStep']);
    $earliest = $date === $today ? ((int) $now->format('G')) * 60 + (int) $now->format('i') + (int) $salon['leadTime'] : -1;
    $busy = fb_busy($date);
    $slots = [];
    foreach ($ranges as [$open, $close]) {
        for ($t = fb_min($open); $t + $duration <= fb_min($close); $t += $step) {
            if ($t < $earliest) {
                continue;
            }
            foreach ($busy as [$start, $end]) {
                if ($t < $end && $t + $duration > $start) {
                    continue 2;
                }
            }
            $slots[] = $t;
        }
    }
    return $slots;
}

/** Nettoie une saisie : espaces, caractères de contrôle, longueur maximale. */
function fb_clean(mixed $value, int $max): string
{
    $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? '');
    return mb_substr($text, 0, $max);
}

function fb_valid_phone(string $phone): bool
{
    $digits = preg_replace('/[\s.\-()]/', '', $phone) ?? '';
    return (bool) preg_match('/^(?:(?:\+|00)33|0)[1-9]\d{8}$/', $digits) || (bool) preg_match('/^\+\d{8,15}$/', $digits);
}

/** Envoi d'un e-mail texte en UTF-8. Renvoie false si le serveur refuse. */
function fb_mail(string $to, string $subject, string $body, string $replyTo = ''): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $cfg = fb_config() ?? [];
    $host = preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?? 'localhost';
    $from = filter_var($cfg['mail_from'] ?? '', FILTER_VALIDATE_EMAIL) ? $cfg['mail_from'] : 'reservation@' . $host;
    $name = mb_encode_mimeheader(fb_salon()['name'], 'UTF-8');
    $headers = [
        'From: ' . $name . ' <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    return @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, implode("\r\n", $headers));
}

/** Adresse publique du site, pour les liens dans les e-mails. */
function fb_site_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'), 2)), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base;
}
