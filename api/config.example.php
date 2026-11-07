<?php
/**
 * Réglages privés du serveur de réservation.
 *
 * 1. Copiez ce fichier sous le nom config.php, dans le même dossier (api/).
 * 2. Remplissez les valeurs ci-dessous.
 *
 * config.php n'est jamais envoyé sur GitHub (voir .gitignore) : il reste sur l'hébergement.
 * Tant qu'il n'existe pas, la réservation en ligne est désactivée et le site le dit.
 */
return [
    // Mot de passe de l'espace barbier (adresse-du-site/barber/). 10 caractères minimum.
    'admin_password' => '',

    // Facultatif : adresse du salon qui reçoit un e-mail à chaque nouveau rendez-vous.
    // Vide = aucun e-mail au salon : les rendez-vous se consultent dans l'espace barbier.
    'notify_email' => '',

    // Expéditeur des e-mails (confirmation au client, alerte au salon) : une adresse de votre
    // domaine créée dans hPanel > E-mails, ex. 'reservation@votre-domaine.fr'.
    // Fortement conseillé, sinon les e-mails risquent d'arriver en indésirables.
    // Vide = reservation@<votre domaine>.
    'mail_from' => '',

    // Envoyer un e-mail de confirmation au client quand il a indiqué son adresse.
    'confirm_client' => true,

    // Rappel par e-mail dans les 24 heures avant le rendez-vous (clients ayant donné leur e-mail).
    // Nécessite la tâche planifiée « php …/public_html/api/rappels.php » toutes les heures.
    'reminders' => true,

    // Adresse du site utilisée dans les liens des e-mails. Vide = détectée automatiquement.
    'site_url' => '',

    // Dossier de la base de données. Vide = automatique : à côté du dossier public_html
    // (fb-barber-data), donc inaccessible depuis le web.
    'data_dir' => '',

    // Fuseau horaire du salon.
    'timezone' => 'Europe/Paris',
];
