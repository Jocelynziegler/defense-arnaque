<?php
/**
 * GABARIT — à copier en "mail-config.php" sur le serveur (jamais dans Git).
 *
 * 1. Copiez ce fichier : cp mail-config.sample.php mail-config.php
 * 2. Renseignez vos vrais identifiants SMTP dans VOTRE copie "mail-config.php"
 *    (pas dans ce fichier gabarit).
 *
 * mail-config.php est volontairement exclu du dépôt Git (voir .gitignore)
 * pour que le mot de passe SMTP ne soit JAMAIS visible publiquement, y
 * compris dans l'historique des commits.
 */
return [
    'smtp_host' => 'smtp.hostinger.com',
    'smtp_port' => 465,
    'smtp_secure' => 'ssl', // ssl = port 465, tls = port 587
    'smtp_user' => 'REMPLACEZ_PAR_VOTRE_ADRESSE_EMAIL',
    'smtp_pass' => 'REMPLACEZ_PAR_VOTRE_MOT_DE_PASSE',
    'from_email' => 'REMPLACEZ_PAR_VOTRE_ADRESSE_EMAIL',
    'from_name' => 'Ziegler Alerte Arnaque',
    'to_email' => 'juridique@ziegler-associes.com',
    'to_name' => 'Secrétariat juridique',
];
