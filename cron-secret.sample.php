<?php
/**
 * GABARIT — à copier en "cron-secret.php" sur le serveur (jamais dans Git).
 *
 * 1. Copiez ce fichier : cp cron-secret.sample.php cron-secret.php
 * 2. Générez un jeton aléatoire, par exemple avec :
 *      openssl rand -hex 24
 * 3. Remplacez la valeur ci-dessous par ce jeton dans VOTRE copie
 *    "cron-secret.php" (pas dans ce fichier gabarit).
 *
 * cron-secret.php est volontairement exclu du dépôt Git (voir .gitignore)
 * pour que ce jeton ne soit JAMAIS visible publiquement, y compris dans
 * l'historique des commits.
 */
return 'REMPLACEZ_MOI_PAR_UN_JETON_ALEATOIRE_GENERE_AVEC_OPENSSL';
