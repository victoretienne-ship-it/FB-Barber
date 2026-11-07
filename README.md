# FB Barber

Site du salon FB Barber : page d'accueil animée, page galerie, prise de rendez-vous en ligne
avec un vrai agenda (les créneaux pris disparaissent) et un espace privé pour le barbier.

## Contenu

| Dossier / fichier | Rôle |
|---|---|
| `index.html`, `galerie.html`, `mentions-legales.html`, `rendez-vous.html` | Les pages du site (`rendez-vous.html` : « Mon rendez-vous », lien envoyé au client) |
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
- `mail_from` : l'expéditeur des e-mails de confirmation envoyés aux clients, une adresse de votre
  domaine à créer dans hPanel → E-mails (par exemple `reservation@votre-domaine.fr`), pour que ces
  e-mails n'arrivent pas en indésirables ;
- `notify_email` (facultatif) : une adresse du salon qui reçoit un e-mail à chaque nouveau rendez-vous.
  Vide = les rendez-vous se consultent seulement dans l'espace barbier.

Tant que `config.php` n'existe pas, le site indique que la réservation en ligne n'est pas encore activée.

### 3. Vérifier

1. hPanel → Avancé → Configuration PHP : version 8.1 ou plus récente.
2. Sur le site, réservez un rendez-vous de test.
3. Ouvrez `votre-domaine/barber/`, connectez-vous : le rendez-vous apparaît. Annulez-le.

## Réservation en ligne

- Le client choisit ses prestations, puis un créneau libre (raccourci « Le plus tôt »), et laisse
  son nom, son téléphone et, s'il le souhaite, son e-mail. Il peut demander au site de se souvenir
  de ses coordonnées sur son appareil.
- Après la réservation : bouton « Ajouter à mon agenda » (fichier .ics) et lien personnel
  « Gérer mon rendez-vous » (`rendez-vous.html?ref=…`), aussi envoyé par e-mail.
- Depuis ce lien, le client déplace ou annule son rendez-vous jusqu'à `cancelUntil` heures avant
  (2 h par défaut, dans `assets/js/salon.js`). Passé ce délai, il appelle le salon.
- Rappel par e-mail dans les 24 heures avant le rendez-vous, envoyé par la tâche planifiée
  `php …/public_html/api/rappels.php`, à lancer toutes les heures (hPanel → Avancé → Tâches Cron).

## Au quotidien, côté barbier

- **Agenda** : `votre-domaine/barber/`. Sur téléphone, ajoutez la page à l'écran d'accueil.
  Elle se met à jour toute seule chaque minute.
- **Nouveau rendez-vous** : il apparaît dans l'agenda avec le client, le téléphone et les prestations
  (et par e-mail si `notify_email` est renseigné).
- **Annuler** un rendez-vous : le créneau redevient réservable (pensez à prévenir le client).
  « Rétablir » le remet si le créneau est toujours libre.
- **Ajouter un rendez-vous** pris par téléphone ou au comptoir : il occupe le créneau en ligne.
- **Bloquer un créneau** : pause, client sans rendez-vous, absence. Le créneau n'est plus proposé en ligne.
- Les étiquettes indiquent les rendez-vous « Pris au salon », « Annulé par le client » et « Rappel envoyé ».
- **Fermeture exceptionnelle** (jour férié, congés) : ajoutez la date dans `closedDates`
  dans `assets/js/salon.js`.
- **Lien direct vers la réservation** (bio Instagram, Google) : `votre-domaine/#rdv`.

Les rendez-vous sont confirmés tout de suite pour le client. S'il indique son e-mail (facultatif),
il reçoit un e-mail de confirmation avec la date, l'heure, les prestations et le numéro du salon.
Pour annuler ou déplacer, il appelle le salon.

## Modifier les horaires, les prestations ou les prix

Tout se trouve dans `assets/js/salon.js`. Le site et le serveur lisent ce même fichier,
les créneaux proposés et ceux acceptés par le serveur restent donc toujours identiques.
Format JSON : guillemets doubles, pas de virgule après le dernier élément, commentaires
uniquement sur leur propre ligne.

## Mentions légales

`mentions-legales.html` contient des champs marqués « À compléter » (exploitant, forme juridique,
SIRET, immatriculation, TVA, directeur de la publication, e-mail de contact, médiateur de la
consommation). Ils sont obligatoires : remplissez-les avant la mise en ligne.

## Mettre à jour le site

Hostinger demande aux navigateurs de garder les fichiers CSS et JS en cache 7 jours. Après une
modification de `site.css`, `site.js` ou `salon.js`, changez le numéro `?v=…` de leurs liens dans
les quatre pages HTML pour que les visiteurs reçoivent la nouvelle version, puis videz le cache
CDN (hPanel → Performance → CDN → Vider le cache). Envoyez les fichiers modifiés un par un : le
déploiement d'une archive remplace tout le dossier et supprimerait `api/config.php`.

## Données et sécurité

- Les rendez-vous sont dans `reservations.sqlite`, rangé dans `fb-barber-data/` à côté de
  `public_html` : il n'est pas accessible depuis le web. Pour le sauvegarder, téléchargez ce
  fichier depuis le gestionnaire de fichiers.
- Les rendez-vous sont effacés automatiquement 12 mois après leur date, comme l'annoncent les
  mentions légales.
- `api/config.php` (mot de passe, e-mails) n'est jamais envoyé sur GitHub.
- Deux clients ne peuvent pas obtenir le même créneau : le serveur revérifie le créneau au
  moment de l'enregistrement.
- Protections : 5 essais de mot de passe par quart d'heure, 6 réservations par heure et par
  connexion, champ piège contre les robots. Les adresses IP ne sont pas conservées, seulement
  une empreinte.
