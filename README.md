# FB Barber

Site du salon FB Barber : page d'accueil animée, page galerie, prise de rendez-vous en ligne
avec un vrai agenda (les créneaux pris disparaissent) et un espace privé pour le barbier.

## Contenu

| Dossier / fichier | Rôle |
|---|---|
| `index.html`, `galerie.html` | Les deux pages du site |
| `assets/js/salon.js` | **Configuration du salon** : coordonnées, horaires, prestations et prix. Lu par le site et par le serveur |
| `assets/` | Styles, scripts et photos |
| `api/` | Serveur de réservation (PHP + base SQLite) |
| `barber/` | Espace barbier : agenda des rendez-vous |

## Mettre en ligne sur Hostinger

Il faut un hébergement web Hostinger (PHP 8.1 ou plus récent, SQLite est inclus).

### 1. Envoyer les fichiers dans `public_html`

**Avec le gestionnaire de fichiers** : hPanel → Sites web → Gérer → Gestionnaire de fichiers →
dossier `public_html` → Importer l'archive `fb-barber-hostinger.zip` → clic droit → Extraire.

**Ou avec Git** (mises à jour en un clic ensuite) : hPanel → Avancé → Git →
dépôt `https://github.com/victoretienne-ship-it/FB-Barber.git`, branche `claude/fb-barber-site`
(ou `main` une fois fusionnée), dossier d'installation vide (= `public_html`, qui doit être vide
au premier déploiement). Si le dépôt est privé, ajoutez la clé SSH affichée par hPanel dans
GitHub → Settings → Deploy keys.

### 2. Activer la réservation

Dans `public_html/api/`, copiez `config.example.php` sous le nom `config.php`, puis renseignez :

- `admin_password` : le mot de passe de l'espace barbier (10 caractères minimum) ;
- `notify_email` (facultatif) : une adresse qui reçoit un e-mail à chaque nouveau rendez-vous.
  Laissez-la vide pour ne recevoir aucun e-mail : les rendez-vous se consultent dans l'espace barbier ;
- `mail_from` (facultatif, seulement avec `notify_email`) : une adresse de votre domaine, créée dans
  hPanel → E-mails, pour que ces e-mails n'arrivent pas en indésirables.

Tant que `config.php` n'existe pas, le site indique que la réservation en ligne n'est pas encore activée.

### 3. Vérifier

1. hPanel → Avancé → Configuration PHP : version 8.1 ou plus récente.
2. Sur le site, réservez un rendez-vous de test.
3. Ouvrez `votre-domaine/barber/`, connectez-vous : le rendez-vous apparaît. Annulez-le.

## Au quotidien, côté barbier

- **Agenda** : `votre-domaine/barber/`. Sur téléphone, ajoutez la page à l'écran d'accueil.
  Elle se met à jour toute seule chaque minute.
- **Nouveau rendez-vous** : il apparaît dans l'agenda avec le client, le téléphone et les prestations
  (et par e-mail si `notify_email` est renseigné).
- **Annuler** un rendez-vous : le créneau redevient réservable (pensez à prévenir le client).
  « Rétablir » le remet si le créneau est toujours libre.
- **Bloquer un créneau** : pause, client sans rendez-vous, absence. Le créneau n'est plus proposé en ligne.
- **Fermeture exceptionnelle** (jour férié, congés) : ajoutez la date dans `closedDates`
  dans `assets/js/salon.js`.
- **Lien direct vers la réservation** (bio Instagram, Google) : `votre-domaine/#rdv`.

Les rendez-vous sont confirmés tout de suite pour le client, qui ne laisse que son nom et son
téléphone. Pour annuler ou déplacer, il appelle le salon.

## Modifier les horaires, les prestations ou les prix

Tout se trouve dans `assets/js/salon.js`. Le site et le serveur lisent ce même fichier,
les créneaux proposés et ceux acceptés par le serveur restent donc toujours identiques.
Format JSON : guillemets doubles, pas de virgule après le dernier élément, commentaires
uniquement sur leur propre ligne.

## Données et sécurité

- Les rendez-vous sont dans `reservations.sqlite`, rangé dans `fb-barber-data/` à côté de
  `public_html` : il n'est pas accessible depuis le web. Pour le sauvegarder, téléchargez ce
  fichier depuis le gestionnaire de fichiers.
- `api/config.php` (mot de passe, e-mails) n'est jamais envoyé sur GitHub.
- Deux clients ne peuvent pas obtenir le même créneau : le serveur revérifie le créneau au
  moment de l'enregistrement.
- Protections : 5 essais de mot de passe par quart d'heure, 6 réservations par heure et par
  connexion, champ piège contre les robots. Les adresses IP ne sont pas conservées, seulement
  une empreinte.
