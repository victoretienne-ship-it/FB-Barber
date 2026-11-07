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
    $data += [
        'notify_email' => '', 'mail_from' => '', 'confirm_client' => true, 'reminders' => true,
        'site_url' => '', 'data_dir' => '', 'timezone' => 'Europe/Paris',
    ];
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
    $data += [
        'name' => 'FB Barber', 'phone' => '', 'address' => '', 'hours' => [], 'closedDates' => [],
        'slotStep' => 15, 'leadTime' => 60, 'daysAhead' => 28, 'cancelUntil' => 2, 'services' => [],
    ];
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

/**
 * Dossier des données : à côté du dossier public (public_html) quand c'est possible, donc
 * inaccessible depuis le web. Le même chemin est trouvé depuis le site et depuis la tâche planifiée.
 */
function fb_data_dir(): string
{
    $cfg = fb_config() ?? [];
    $dir = (string) ($cfg['data_dir'] ?? '');
    if ($dir === '') {
        $site = realpath(FB_ROOT) ?: FB_ROOT;
        $outside = dirname($site) . '/fb-barber-data';
        $dir = (is_dir($outside) || @is_writable(dirname($outside))) ? $outside : FB_ROOT . '/data';
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
    // Mise à jour des bases créées par une version précédente
    $columns = array_column($db->query('PRAGMA table_info(reservations)')->fetchAll(), 'name');
    $added = [
        'jeton' => "TEXT NOT NULL DEFAULT ''",
        'source' => "TEXT NOT NULL DEFAULT 'en_ligne'",
        'annule_par' => "TEXT NOT NULL DEFAULT ''",
        'annule_le' => "TEXT NOT NULL DEFAULT ''",
        'rappel_envoye' => 'INTEGER NOT NULL DEFAULT 0',
    ];
    foreach ($added as $name => $definition) {
        if (!in_array($name, $columns, true)) {
            $db->exec("ALTER TABLE reservations ADD COLUMN {$name} {$definition}");
        }
    }
    $db->exec('CREATE INDEX IF NOT EXISTS idx_reservations_jeton ON reservations (jeton)');
    fb_purge($db);
    return $db;
}

/**
 * Effacement automatique, une fois par jour au plus : rendez-vous passés depuis plus de
 * 12 mois (durée annoncée dans les mentions légales) et anciens essais de connexion.
 */
function fb_purge(PDO $db): void
{
    $marker = fb_data_dir() . '/.dernier-nettoyage';
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    if (is_file($marker) && trim((string) @file_get_contents($marker)) === $today) {
        return;
    }
    $months = max(1, (int) ((fb_config() ?? [])['retention_months'] ?? 12));
    $limit = (new DateTimeImmutable('today'))->modify("-{$months} months")->format('Y-m-d');
    $db->prepare('DELETE FROM reservations WHERE date < ?')->execute([$limit]);
    $db->prepare('DELETE FROM connexions WHERE quand < ?')->execute([(new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s')]);
    @file_put_contents($marker, $today);
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

/** Jeton secret du lien « Gérer mon rendez-vous » (128 bits, impossible à deviner). */
function fb_token(): string
{
    return bin2hex(random_bytes(16));
}

function fb_valid_token(string $token): bool
{
    return (bool) preg_match('/^[a-f0-9]{32}$/', $token);
}

/** Rendez-vous client correspondant à un jeton, ou null. */
function fb_find_by_token(string $token): ?array
{
    if (!fb_valid_token($token)) {
        return null;
    }
    $stmt = fb_db()->prepare("SELECT * FROM reservations WHERE jeton = ? AND type = 'client' LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
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

/** Début d'un rendez-vous, à l'heure du salon. */
function fb_start(array $row): DateTimeImmutable
{
    return (new DateTimeImmutable($row['date'] . ' 00:00:00'))->modify('+' . (int) $row['debut'] . ' minutes');
}

/** Le client peut-il encore annuler ou déplacer ce rendez-vous en ligne ? */
function fb_can_change(array $row): bool
{
    if ($row['type'] !== 'client' || $row['statut'] !== 'confirme') {
        return false;
    }
    $limit = fb_start($row)->modify('-' . max(0, (int) fb_salon()['cancelUntil']) . ' hours');
    return new DateTimeImmutable('now') < $limit;
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

/**
 * Heures de début libres (en minutes) pour une durée donnée.
 * $exceptId : rendez-vous à ignorer (celui qu'un client est en train de déplacer).
 */
function fb_free_slots(string $date, int $duration, ?int $exceptId = null): array
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
    $busy = fb_busy($date, $exceptId);
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

/** Adresse publique du site, pour les liens dans les e-mails (aussi depuis la tâche planifiée). */
function fb_site_url(): string
{
    $cfg = fb_config() ?? [];
    if (!empty($cfg['site_url'])) {
        return rtrim((string) $cfg['site_url'], '/');
    }
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'), 2)), '/');
        return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $base;
    }
    // Hébergement Hostinger : …/domains/<domaine>/public_html
    $site = realpath(FB_ROOT) ?: FB_ROOT;
    $domainDir = dirname($site);
    if (basename(dirname($domainDir)) === 'domains') {
        return 'https://' . basename($domainDir);
    }
    return 'http://localhost';
}

function fb_manage_url(string $token): string
{
    return fb_site_url() . '/rendez-vous.html?ref=' . $token;
}

function fb_calendar_url(string $token): string
{
    return fb_site_url() . '/api/agenda.php?ref=' . $token;
}

/** Envoi d'un e-mail texte en UTF-8. Renvoie false si le serveur refuse. */
function fb_mail(string $to, string $subject, string $body, string $replyTo = ''): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $cfg = fb_config() ?? [];
    $host = preg_replace('/^www\./', '', (string) (parse_url(fb_site_url(), PHP_URL_HOST) ?: 'localhost')) ?? 'localhost';
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

/** Date et heure lisibles : « samedi 7 novembre à 10:00 (fin vers 10:45) » */
function fb_when(array $row): string
{
    return fb_date_fr($row['date']) . ' à ' . fb_hhmm((int) $row['debut']) . ' (fin vers ' . fb_hhmm((int) $row['fin']) . ')';
}

/**
 * E-mails envoyés au client : confirmation, déplacement, annulation, rappel.
 * Renvoie false s'il n'a pas d'adresse ou si l'envoi échoue.
 */
function fb_mail_client(array $row, string $kind): bool
{
    $cfg = fb_config() ?? [];
    if ($row['email'] === '' || empty($cfg['confirm_client'])) {
        return false;
    }
    $salon = fb_salon();
    $name = $salon['name'];
    $phone = $salon['phone'] !== '' ? ' au ' . $salon['phone'] : '';
    $details = ucfirst(fb_when($row)) . "\n" . $row['libelle'] . ' (' . (int) $row['prix'] . " €)\n"
        . ($salon['address'] !== '' ? 'Adresse : ' . $salon['address'] . "\n" : '');
    $hours = (int) $salon['cancelUntil'];
    $manage = "Déplacer ou annuler en ligne (jusqu'à {$hours} h avant) : " . fb_manage_url($row['jeton']) . "\n"
        . 'Ajouter à votre agenda : ' . fb_calendar_url($row['jeton']) . "\n"
        . "Passé ce délai, appelez le salon{$phone}.\n";

    [$subject, $intro, $outro] = match ($kind) {
        'confirmation' => ["Votre rendez-vous chez {$name}", "Votre rendez-vous chez {$name} est enregistré :", $manage],
        'deplacement' => ["Votre rendez-vous chez {$name} est déplacé", "Votre rendez-vous chez {$name} a bien été déplacé. Nouvel horaire :", $manage],
        'rappel' => [
            "Rappel : votre rendez-vous chez {$name}",
            'Petit rappel de votre rendez-vous chez ' . $name . ' ' . (fb_start($row)->format('Y-m-d') === (new DateTimeImmutable('today'))->format('Y-m-d') ? 'aujourd’hui' : 'demain') . ' :',
            $manage,
        ],
        'annulation' => [
            "Votre rendez-vous chez {$name} est annulé",
            'Votre rendez-vous a bien été annulé :',
            'Pour en reprendre un : ' . fb_site_url() . "/#rdv\n",
        ],
    };
    return fb_mail($row['email'], $subject, "Bonjour {$row['nom']},\n\n{$intro}\n\n{$details}\n{$outro}\nÀ bientôt !\n");
}

/** E-mail au salon (si une adresse est configurée). */
function fb_mail_salon(array $row, string $title): bool
{
    $cfg = fb_config() ?? [];
    $body = $title . "\n\n"
        . 'Quand : ' . fb_when($row) . "\n"
        . 'Prestations : ' . $row['libelle'] . ' (' . (int) $row['prix'] . " €)\n"
        . 'Client : ' . $row['nom'] . "\n"
        . 'Téléphone : ' . $row['telephone'] . "\n"
        . ($row['email'] !== '' ? 'E-mail : ' . $row['email'] . "\n" : '')
        . ($row['precision'] !== '' ? 'Précision : ' . $row['precision'] . "\n" : '')
        . "\nAgenda : " . fb_site_url() . '/barber/?jour=' . $row['date'] . "\n";
    return fb_mail((string) ($cfg['notify_email'] ?? ''), $title . ' : ' . fb_date_fr($row['date']) . ' à ' . fb_hhmm((int) $row['debut']), $body, $row['email']);
}
