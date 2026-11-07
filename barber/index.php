<?php
/**
 * Espace barbier : agenda des rendez-vous pris en ligne.
 * Adresse : votre-site/barber/  (non lié depuis le site, protégé par mot de passe)
 */
declare(strict_types=1);
require __DIR__ . '/../api/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$cfg = fb_config();
date_default_timezone_set($cfg['timezone'] ?? 'Europe/Paris');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('fbb_barbier');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => $https]);
session_start();

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function go(string $date = ''): never
{
    header('Location: ./' . ($date !== '' ? '?jour=' . rawurlencode($date) : ''), true, 303);
    exit;
}

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

/** Haut de page commun */
function page_start(string $title): void
{
    ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400&family=Barlow:wght@400&family=Roboto+Mono:wght@400&display=swap">
<link rel="stylesheet" href="../assets/css/site.css">
<style>
  body { min-height: 100vh; }
  .adm { width: min(100%, 760px); margin-inline: auto; padding: max(20px, env(safe-area-inset-top, 0px)) max(16px, env(safe-area-inset-right, 0px)) 48px max(16px, env(safe-area-inset-left, 0px)); display: grid; gap: 24px; }
  .adm__top { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .adm__brand { display: inline-flex; align-items: center; gap: 10px; font-family: var(--font-display); font-size: 18px; text-transform: uppercase; letter-spacing: -0.02em; }
  .adm__brand svg { width: 20px; height: 20px; }
  .adm__brand path { fill: none; stroke: currentColor; stroke-width: 1.2; }
  .adm h1 { font-family: var(--font-display); font-size: clamp(44px, 12vw, 76px); line-height: 0.92; letter-spacing: -0.02em; text-transform: uppercase; }
  .adm h2 { font-family: var(--font-display); font-size: 26px; line-height: 1; letter-spacing: -0.02em; text-transform: uppercase; }
  .muted { color: var(--muted); }
  .ash { color: var(--color-ash); }
  .daynav { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
  .daynav__links { display: flex; gap: 8px; flex-wrap: wrap; }
  .week { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
  .week a { display: grid; justify-items: center; gap: 4px; padding: 10px 0; border: 1px solid var(--hairline); border-radius: 1440px; transition: border-color .3s; }
  .week a:hover { border-color: var(--color-ash); }
  .week a[aria-current="date"] { border-color: var(--color-ember-rust); }
  .week b { font-family: var(--font-display); font-size: 22px; line-height: 1; }
  .week .mono { font-size: 10px; color: var(--color-ash); }
  .week .count { color: var(--color-ember-rust); }
  .stats { display: flex; flex-wrap: wrap; gap: 8px 20px; padding: 14px 0; border-block: 1px solid var(--hairline); }
  .list { display: grid; }
  .rdv { display: grid; grid-template-columns: 76px minmax(0, 1fr); gap: 6px 16px; padding: 18px 0; border-bottom: 1px solid var(--hairline); }
  .rdv__time b { display: block; font-family: var(--font-display); font-size: 30px; line-height: 1; letter-spacing: -0.02em; }
  .rdv__time span { font-family: var(--font-mono); font-size: 11px; color: var(--color-ash); }
  .rdv__main { display: grid; gap: 6px; min-width: 0; }
  .rdv__what { font-family: var(--font-display); font-size: 22px; line-height: 1.05; text-transform: uppercase; letter-spacing: -0.02em; }
  .rdv__who { font-size: 17px; }
  .rdv__contact { display: flex; flex-wrap: wrap; gap: 6px 14px; }
  .rdv__contact a { color: var(--fg); text-decoration: underline; text-decoration-color: var(--color-ember-rust); text-underline-offset: 4px; }
  .rdv__note { color: var(--muted); font-style: normal; }
  .rdv__actions { grid-column: 2; display: flex; gap: 8px; flex-wrap: wrap; padding-top: 6px; }
  .rdv--annule .rdv__time, .rdv--annule .rdv__what, .rdv--annule .rdv__who { opacity: .45; text-decoration: line-through; text-decoration-thickness: 1px; }
  .rdv--blocage .rdv__what { color: var(--color-ash); }
  .tag { display: inline-flex; align-items: center; padding: 4px 10px; border: 1px solid var(--hairline); border-radius: 1440px; font-family: var(--font-mono); font-size: 10px; text-transform: uppercase; color: var(--color-ash); }
  .tag--ember { border-color: var(--color-ember-rust); color: var(--color-ember-rust); }
  .pill--sm { padding: 8px 14px; font-size: 13px; }
  .empty { padding: 28px 0; color: var(--muted); }
  .flash { padding: 14px 16px; border: 1px solid var(--hairline); border-radius: 10px; }
  .flash--ok { border-color: var(--color-ember-rust); }
  .flash--warn { border-color: var(--color-ember-rust); color: var(--color-ember-rust); }
  .box { display: grid; gap: 16px; padding: 22px; background: var(--surface); border: 1px solid var(--hairline); border-radius: 10px; }
  .fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; }
  .fields label { display: grid; gap: 6px; font-family: var(--font-mono); font-size: 11px; text-transform: uppercase; color: var(--color-ash); }
  .fields input, .fields select, .login input { width: 100%; padding: 12px 14px; border: 1px solid var(--color-steel); border-radius: 10px; background: var(--bg); color: var(--fg); font: 400 16px/1.3 var(--font-body); }
  .fields input:focus, .fields select:focus, .login input:focus { outline: 1px solid var(--color-ember-rust); outline-offset: 2px; }
  .svcs { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; border: 0; padding: 0; margin: 0; }
  .svcs legend { margin-bottom: 10px; }
  .svcs label { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; padding: 10px 12px; border: 1px solid var(--hairline); border-radius: 10px; cursor: pointer; }
  .svcs input { accent-color: var(--color-ember-rust); }
  .login { width: min(100%, 420px); margin: 12vh auto 0; display: grid; gap: 18px; }
  .steps { display: grid; gap: 10px; padding-left: 20px; list-style: decimal; color: var(--muted); }
  code { font-family: var(--font-mono); font-size: 13px; color: var(--fg); }
  @media (max-width: 480px) { .rdv { grid-template-columns: 62px minmax(0, 1fr); } .rdv__time b { font-size: 24px; } }
</style>
</head>
<body>
<main class="adm"><?php
}

function page_end(): void
{
    echo "</main>\n</body>\n</html>\n";
}

$brand = '<span class="adm__brand"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 1.5L22.5 12L12 22.5L1.5 12Z"/><path d="M12 1.5V22.5M1.5 12H22.5"/></svg>' . h(fb_salon()['name']) . '</span>';

/* ---------- Pas encore configuré ---------- */
if ($cfg === null) {
    page_start('Espace barbier');
    ?>
    <div class="adm__top"><?= $brand ?></div>
    <h1>Espace barbier</h1>
    <p class="muted">La réservation en ligne n’est pas encore activée. Pour l’activer :</p>
    <ol class="steps">
      <li>Dans le gestionnaire de fichiers Hostinger, ouvrez le dossier <code>api/</code>.</li>
      <li>Copiez <code>config.example.php</code> et nommez la copie <code>config.php</code>.</li>
      <li>Dans <code>config.php</code>, choisissez un mot de passe d’au moins 10 caractères et indiquez l’adresse e-mail qui recevra les nouveaux rendez-vous.</li>
      <li>Rechargez cette page.</li>
    </ol>
    <?php
    page_end();
    exit;
}

$db = fb_db();
$ipHash = fb_ip_hash();
$today = (new DateTimeImmutable('today'))->format('Y-m-d');

/* ---------- Connexion ---------- */
if (($_POST['action'] ?? '') === 'connexion') {
    $since = (new DateTimeImmutable('-15 minutes'))->format('Y-m-d H:i:s');
    $tries = $db->prepare('SELECT COUNT(*) FROM connexions WHERE ip_hash = ? AND quand > ?');
    $tries->execute([$ipHash, $since]);
    if ((int) $tries->fetchColumn() >= 5) {
        flash('Trop d’essais. Réessayez dans 15 minutes.', 'warn');
    } elseif (hash_equals((string) $cfg['admin_password'], (string) ($_POST['mot_de_passe'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['barbier'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        $db->prepare('DELETE FROM connexions WHERE ip_hash = ?')->execute([$ipHash]);
        go();
    } else {
        $db->prepare('INSERT INTO connexions (ip_hash, quand) VALUES (?, ?)')->execute([$ipHash, (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')]);
        sleep(1);
        flash('Mot de passe incorrect.', 'warn');
    }
    go();
}

if (empty($_SESSION['barbier'])) {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    page_start('Connexion, espace barbier');
    ?>
    <form class="login" method="post">
      <?= $brand ?>
      <h1>Espace barbier</h1>
      <?php if ($flash): ?><p class="flash flash--<?= h($flash['type']) ?>" role="alert"><?= h($flash['message']) ?></p><?php endif; ?>
      <div class="fields">
        <label for="mot_de_passe">Mot de passe
          <input id="mot_de_passe" name="mot_de_passe" type="password" autocomplete="current-password" required autofocus>
        </label>
      </div>
      <input type="hidden" name="action" value="connexion">
      <button class="pill" type="submit">Se connecter</button>
    </form>
    <?php
    page_end();
    exit;
}

/* ---------- Actions (protégées contre les requêtes forgées) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Session expirée. Rechargez la page.');
    }
    $action = (string) ($_POST['action'] ?? '');
    $back = fb_valid_date((string) ($_POST['jour'] ?? '')) ? (string) $_POST['jour'] : $today;

    if ($action === 'deconnexion') {
        $_SESSION = [];
        session_destroy();
        go();
    }

    $id = (int) ($_POST['id'] ?? 0);
    $find = $db->prepare('SELECT * FROM reservations WHERE id = ?');
    $find->execute([$id]);
    $row = $find->fetch();

    if ($action === 'annuler' && $row) {
        $db->prepare("UPDATE reservations SET statut = 'annule' WHERE id = ?")->execute([$id]);
        flash($row['type'] === 'blocage'
            ? 'Blocage supprimé : le créneau est de nouveau réservable.'
            : 'Rendez-vous annulé : le créneau est de nouveau réservable. Pensez à prévenir ' . $row['nom'] . '.');
        go($row['date']);
    }

    if ($action === 'retablir' && $row) {
        $overlap = false;
        foreach (fb_busy($row['date'], $id) as [$start, $end]) {
            if ((int) $row['debut'] < $end && (int) $row['fin'] > $start) {
                $overlap = true;
            }
        }
        if ($overlap) {
            flash('Impossible de rétablir : ce créneau a été repris entre-temps.', 'warn');
        } else {
            $db->prepare("UPDATE reservations SET statut = 'confirme' WHERE id = ?")->execute([$id]);
            flash('Rendez-vous rétabli.');
        }
        go($row['date']);
    }

    if ($action === 'ajouter') {
        $date = (string) ($_POST['date'] ?? '');
        $time = (string) ($_POST['heure'] ?? '');
        $name = fb_clean($_POST['nom'] ?? '', 80);
        $phone = fb_clean($_POST['telephone'] ?? '', 30);
        $note = fb_clean($_POST['precision'] ?? '', 300);
        $catalog = array_column(fb_salon()['services'], null, 'id');
        $ids = array_values(array_unique(array_filter(
            array_map('strval', (array) ($_POST['prestations'] ?? [])),
            static fn ($id) => isset($catalog[$id])
        )));
        $validTime = (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
        if (!fb_valid_date($date) || !$validTime || !$ids || mb_strlen($name) < 2 || ($phone !== '' && !fb_valid_phone($phone))) {
            flash('Rendez-vous non enregistré : cochez au moins une prestation, indiquez le jour, l’heure et le nom du client (et un téléphone valide s’il est renseigné).', 'warn');
            go($back);
        }
        $chosen = array_map(static fn ($id) => $catalog[$id], $ids);
        $start = fb_min($time);
        $end = min(24 * 60, $start + array_sum(array_column($chosen, 'duration')));
        $overlap = false;
        foreach (fb_busy($date) as [$s, $e]) {
            if ($start < $e && $end > $s) {
                $overlap = true;
            }
        }
        $db->prepare(
            "INSERT INTO reservations (date, debut, fin, type, statut, prestations, libelle, prix, nom, telephone, precision, cree_le, jeton, source)
             VALUES (?, ?, ?, 'client', 'confirme', ?, ?, ?, ?, ?, ?, ?, ?, 'salon')"
        )->execute([
            $date, $start, $end, json_encode($ids), implode(' + ', array_column($chosen, 'name')),
            array_sum(array_column($chosen, 'price')), $name, $phone, $note,
            (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), fb_token(),
        ]);
        flash($overlap
            ? 'Rendez-vous ajouté. Attention : il chevauche un autre rendez-vous ou un blocage.'
            : 'Rendez-vous ajouté : le créneau n’est plus proposé en ligne.', $overlap ? 'warn' : 'ok');
        go($date);
    }

    if ($action === 'bloquer') {
        $date = (string) ($_POST['date'] ?? '');
        $time = (string) ($_POST['heure'] ?? '');
        $duration = (int) ($_POST['duree'] ?? 0);
        $reason = fb_clean($_POST['motif'] ?? '', 60);
        if (!fb_valid_date($date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) || $duration < 5 || $duration > 720) {
            flash('Blocage non enregistré : vérifiez la date, l’heure et la durée.', 'warn');
            go($back);
        }
        $start = fb_min($time);
        $end = min(24 * 60, $start + $duration);
        $overlap = false;
        foreach (fb_busy($date) as [$s, $e]) {
            if ($start < $e && $end > $s) {
                $overlap = true;
            }
        }
        $db->prepare("INSERT INTO reservations (date, debut, fin, type, statut, libelle, cree_le) VALUES (?, ?, ?, 'blocage', 'confirme', ?, ?)")
            ->execute([$date, $start, $end, $reason !== '' ? $reason : 'Créneau bloqué', (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')]);
        flash($overlap
            ? 'Créneau bloqué. Attention : il chevauche un rendez-vous existant.'
            : 'Créneau bloqué : il n’est plus proposé aux clients.', $overlap ? 'warn' : 'ok');
        go($date);
    }

    go($back);
}

/* ---------- Agenda du jour ---------- */
$day = fb_valid_date((string) ($_GET['jour'] ?? '')) ? (string) $_GET['jour'] : $today;
$dayObj = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
$rows = $db->prepare("SELECT * FROM reservations WHERE date = ? ORDER BY debut, statut = 'annule', id");
$rows->execute([$day]);
$rows = $rows->fetchAll();

$clients = array_filter($rows, static fn ($r) => $r['type'] === 'client' && $r['statut'] === 'confirme');
$revenue = array_sum(array_column($clients, 'prix'));
$ranges = fb_ranges($day);
$hoursText = $ranges ? implode(', ', array_map(static fn ($r) => fb_hour_fr($r[0]) . '–' . fb_hour_fr($r[1]), $ranges)) : 'Fermé';

// Bande de 7 jours autour du jour affiché, avec le nombre de rendez-vous
$weekStart = $dayObj->modify('-3 days');
$weekEnd = $weekStart->modify('+6 days');
$counts = $db->prepare("SELECT date, COUNT(*) AS n FROM reservations WHERE type = 'client' AND statut = 'confirme' AND date BETWEEN ? AND ? GROUP BY date");
$counts->execute([$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')]);
$countByDay = array_column($counts->fetchAll(), 'n', 'date');

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = h($_SESSION['csrf']);
$shortDays = ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'];

page_start('Agenda, ' . fb_date_fr($day));
?>
<div class="adm__top">
  <?= $brand ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="deconnexion">
    <button class="pill pill--sm" type="submit">Se déconnecter</button>
  </form>
</div>

<div>
  <p class="mono ash"><?= $day === $today ? 'Aujourd’hui' : 'Agenda' ?></p>
  <h1><?= h(ucfirst(fb_date_fr($day))) ?></h1>
</div>

<div class="daynav">
  <div class="daynav__links">
    <a class="pill pill--sm" href="?jour=<?= h($dayObj->modify('-1 day')->format('Y-m-d')) ?>">← Veille</a>
    <?php if ($day !== $today): ?><a class="pill pill--sm" href="./">Aujourd’hui</a><?php endif; ?>
    <a class="pill pill--sm" href="?jour=<?= h($dayObj->modify('+1 day')->format('Y-m-d')) ?>">Lendemain →</a>
  </div>
  <form method="get">
    <label class="mono ash" for="aller">Aller au</label>
    <input id="aller" type="date" name="jour" value="<?= h($day) ?>" onchange="this.form.submit()" style="background:transparent;color:inherit;border:1px solid var(--hairline);border-radius:1440px;padding:8px 12px;font:inherit">
  </form>
</div>

<nav class="week" aria-label="Semaine">
  <?php for ($i = 0; $i < 7; $i++):
      $d = $weekStart->modify("+{$i} day");
      $iso = $d->format('Y-m-d');
      $n = (int) ($countByDay[$iso] ?? 0); ?>
    <a href="?jour=<?= h($iso) ?>" <?= $iso === $day ? 'aria-current="date"' : '' ?> aria-label="<?= h(fb_date_fr($iso) . ', ' . $n . ' rendez-vous') ?>">
      <span class="mono"><?= h($shortDays[(int) $d->format('w')]) ?></span>
      <b><?= (int) $d->format('j') ?></b>
      <span class="mono <?= $n ? 'count' : '' ?>"><?= $n ? $n . ' rdv' : '–' ?></span>
    </a>
  <?php endfor; ?>
</nav>

<?php if ($flash): ?><p class="flash flash--<?= h($flash['type']) ?>" role="status"><?= h($flash['message']) ?></p><?php endif; ?>

<div class="stats mono">
  <span><?= count($clients) ?> rendez-vous</span>
  <span><?= (int) $revenue ?> € prévus</span>
  <span class="ash">Horaires : <?= h($hoursText) ?></span>
</div>

<section class="list" aria-label="Rendez-vous du jour">
  <?php if (!$rows): ?>
    <p class="empty"><?= $ranges ? 'Aucun rendez-vous ce jour-là pour l’instant.' : 'Le salon est fermé ce jour-là.' ?></p>
  <?php endif; ?>
  <?php foreach ($rows as $r):
      $cancelled = $r['statut'] === 'annule';
      $block = $r['type'] === 'blocage';
      $fromSalon = ($r['source'] ?? '') === 'salon';
      $tel = preg_replace('/[^\d+]/', '', (string) $r['telephone']); ?>
    <article class="rdv rdv--<?= h($r['statut']) ?> rdv--<?= h($r['type']) ?>">
      <div class="rdv__time">
        <b><?= h(fb_hhmm((int) $r['debut'])) ?></b>
        <span>→ <?= h(fb_hhmm((int) $r['fin'])) ?></span>
      </div>
      <div class="rdv__main">
        <p>
          <?php if ($cancelled): ?><span class="tag"><?= ($r['annule_par'] ?? '') === 'client' ? 'Annulé par le client' : 'Annulé' ?></span><?php endif; ?>
          <?php if ($block): ?><span class="tag">Bloqué</span><?php endif; ?>
          <?php if (!$block && $fromSalon): ?><span class="tag">Pris au salon</span><?php endif; ?>
          <?php if (!$block && !$cancelled && !$fromSalon && strtotime($r['cree_le']) > time() - 86400): ?><span class="tag tag--ember">Nouveau</span><?php endif; ?>
          <?php if (!$cancelled && !empty($r['rappel_envoye'])): ?><span class="tag">Rappel envoyé</span><?php endif; ?>
        </p>
        <p class="rdv__what"><?= h($r['libelle']) ?><?= $block ? '' : ' · ' . (int) $r['prix'] . ' €' ?></p>
        <?php if (!$block): ?>
          <p class="rdv__who"><?= h($r['nom']) ?></p>
          <p class="rdv__contact">
            <?php if ($tel !== ''): ?><a href="tel:<?= h($tel) ?>"><?= h($r['telephone']) ?></a><?php endif; ?>
            <?php if ($r['email'] !== ''): ?><a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a><?php endif; ?>
          </p>
          <?php if ($r['precision'] !== ''): ?><p class="rdv__note">« <?= h($r['precision']) ?> »</p><?php endif; ?>
          <p class="mono ash"><?= $fromSalon ? 'Ajouté depuis l’agenda le' : 'Réservé en ligne le' ?> <?= h(date('d/m à H:i', strtotime($r['cree_le']))) ?></p>
        <?php endif; ?>
      </div>
      <form class="rdv__actions" method="post" <?= $cancelled ? '' : 'onsubmit="return confirm(\'' . ($block ? 'Supprimer ce blocage ?' : 'Annuler ce rendez-vous ? Pensez à prévenir le client.') . '\')"' ?>>
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <input type="hidden" name="jour" value="<?= h($day) ?>">
        <?php if ($cancelled): ?>
          <button class="pill pill--sm" type="submit" name="action" value="retablir">Rétablir</button>
        <?php else: ?>
          <button class="pill pill--sm" type="submit" name="action" value="annuler"><?= $block ? 'Supprimer le blocage' : 'Annuler le rendez-vous' ?></button>
        <?php endif; ?>
      </form>
    </article>
  <?php endforeach; ?>
</section>

<form class="box" method="post">
  <h2>Ajouter un rendez-vous</h2>
  <p class="muted">Rendez-vous pris par téléphone ou au comptoir : il occupe le créneau, qui n’est plus proposé en ligne.</p>
  <fieldset class="svcs">
    <legend class="mono ash">Prestations</legend>
    <?php foreach (fb_salon()['services'] as $s): ?>
      <label><input type="checkbox" name="prestations[]" value="<?= h($s['id']) ?>"> <?= h($s['name']) ?> <span class="mono ash"><?= (int) $s['duration'] ?> min · <?= (int) $s['price'] ?> €</span></label>
    <?php endforeach; ?>
  </fieldset>
  <div class="fields">
    <label for="a-date">Jour<input id="a-date" type="date" name="date" value="<?= h($day) ?>" required></label>
    <label for="a-heure">Heure<input id="a-heure" type="time" name="heure" step="300" value="<?= h($ranges ? $ranges[0][0] : '09:00') ?>" required></label>
    <label for="a-nom">Nom du client<input id="a-nom" type="text" name="nom" maxlength="80" required></label>
    <label for="a-tel">Téléphone (facultatif)<input id="a-tel" type="tel" name="telephone" maxlength="30"></label>
  </div>
  <div class="fields">
    <label for="a-precision">Précision (facultatif)<input id="a-precision" type="text" name="precision" maxlength="300" placeholder="Dégradé bas, barbe courte…"></label>
  </div>
  <input type="hidden" name="csrf" value="<?= $csrf ?>">
  <input type="hidden" name="jour" value="<?= h($day) ?>">
  <input type="hidden" name="action" value="ajouter">
  <button class="pill" type="submit">Ajouter le rendez-vous</button>
</form>

<form class="box" method="post">
  <h2>Bloquer un créneau</h2>
  <p class="muted">Pause, client sans rendez-vous, absence : le créneau n’est plus proposé en ligne.</p>
  <div class="fields">
    <label for="b-date">Jour<input id="b-date" type="date" name="date" value="<?= h($day) ?>" required></label>
    <label for="b-heure">Début<input id="b-heure" type="time" name="heure" step="300" value="<?= h($ranges ? $ranges[0][0] : '09:00') ?>" required></label>
    <label for="b-duree">Durée
      <select id="b-duree" name="duree">
        <?php foreach ([15 => '15 min', 30 => '30 min', 45 => '45 min', 60 => '1 h', 90 => '1 h 30', 120 => '2 h', 180 => '3 h', 240 => '4 h', 720 => 'Toute la journée'] as $v => $t): ?>
          <option value="<?= $v ?>" <?= $v === 30 ? 'selected' : '' ?>><?= h($t) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label for="b-motif">Motif (facultatif)<input id="b-motif" type="text" name="motif" maxlength="60" placeholder="Pause déjeuner"></label>
  </div>
  <input type="hidden" name="csrf" value="<?= $csrf ?>">
  <input type="hidden" name="jour" value="<?= h($day) ?>">
  <input type="hidden" name="action" value="bloquer">
  <button class="pill" type="submit">Bloquer ce créneau</button>
</form>

<p class="mono ash">La page se met à jour toute seule chaque minute.</p>

<script>
  // Actualisation automatique, sauf pendant une saisie
  setInterval(function () {
    var el = document.activeElement;
    var typing = el && /INPUT|SELECT|TEXTAREA/.test(el.tagName);
    if (!document.hidden && !typing) location.reload();
  }, 60000);
</script>
<?php
page_end();
