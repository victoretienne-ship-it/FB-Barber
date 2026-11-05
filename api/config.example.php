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

    // Facultatif : adresse qui reçoit un e-mail à chaque nouveau rendez-vous.
    // Vide = aucun e-mail : les rendez-vous se consultent dans l'espace barbier.
    'notify_email' => '',

    // Facultatif, utile seulement avec notify_email : expéditeur des e-mails, une adresse
    // de votre domaine créée dans hPanel > E-mails. Vide = reservation@<votre domaine>.
    'mail_from' => '',

    // Dossier de la base de données. Vide = automatique : à côté du dossier public_html
    // (fb-barber-data), donc inaccessible depuis le web.
    'data_dir' => '',

    // Fuseau horaire du salon.
    'timezone' => 'Europe/Paris',
];
