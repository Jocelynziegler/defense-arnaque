<?php
/**
 * update-blacklist.php
 * ---------------------------------------------------------------------
 * Récupère automatiquement la liste noire officielle Banque de France /
 * ACPR / AMF (sites et emails frauduleux ou usurpant l'identité de
 * professionnels autorisés) et la sauvegarde en JSON sur ce serveur.
 *
 * Le site web (ziegler-alertearnaque.html) lit ensuite ce fichier JSON
 * en local (même domaine, donc pas de blocage CORS) au lieu de la petite
 * liste figée qui était codée en dur auparavant.
 *
 * CE FICHIER EST FAIT POUR ÊTRE APPELÉ AUTOMATIQUEMENT PAR UNE TÂCHE
 * PLANIFIÉE (CRON JOB) SUR HOSTINGER — voir les instructions fournies
 * séparément pour configurer le cron dans hPanel.
 * ---------------------------------------------------------------------
 */

// ---------- SÉCURITÉ : protège contre un déclenchement abusif via URL ----------
// Le jeton réel est chargé depuis un fichier NON versionné (cron-secret.php),
// jamais commité dans Git — voir cron-secret.sample.php pour la marche à suivre.
$secretFile = __DIR__ . '/cron-secret.php';
$SECRET_TOKEN = file_exists($secretFile) ? require $secretFile : null;

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $providedToken = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
    if (!$SECRET_TOKEN || !hash_equals($SECRET_TOKEN, $providedToken)) {
        http_response_code(403);
        die("Accès refusé.\n");
    }
}

// ---------- CONFIGURATION ----------
$sourceUrl   = 'https://www.abe-infoservice.fr/fr/abeis-liste-noire.csv';
$outputFile  = __DIR__ . '/assets/blacklist.json';
$logFile     = __DIR__ . '/assets/blacklist-update.log';

function logMessage($msg) {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

// ---------- 1. TÉLÉCHARGEMENT DU CSV OFFICIEL ----------
$context = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: Mozilla/5.0 (compatible; ZieglerAlerteArnaqueBot/1.0)\r\n",
        'timeout' => 30,
    ],
]);

$csvContent = @file_get_contents($sourceUrl, false, $context);

if ($csvContent === false || strlen($csvContent) < 100) {
    logMessage("ÉCHEC: impossible de récupérer le CSV depuis $sourceUrl. Fichier existant conservé.");
    exit(1);
}

// ---------- 2. ANALYSE DU CSV ----------
// Le CSV a un BOM UTF-8 en tête ; on le retire avant de parser.
$csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent);

$lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
$rows = array_map('str_getcsv', $lines);

if (count($rows) < 2) {
    logMessage("ÉCHEC: le CSV récupéré semble vide ou mal formé (" . count($rows) . " lignes). Fichier existant conservé.");
    exit(1);
}

array_shift($rows); // retire l'en-tête ("URL / Mails", Dénomination, Catégorie(s), Date d'inscription)

$entries = [];
foreach ($rows as $row) {
    if (empty($row[0])) continue;
    $entry = strtolower(trim($row[0]));
    if ($entry !== '') {
        $entries[$entry] = true; // clé = déduplication automatique
    }
}

$uniqueEntries = array_keys($entries);
sort($uniqueEntries);

if (count($uniqueEntries) < 50) {
    // Garde-fou : si on récupère anormalement peu d'entrées, quelque chose a
    // probablement changé dans le format du site source — on ne remplace pas
    // le fichier existant pour éviter d'écraser une bonne liste par une vide.
    logMessage("AVERTISSEMENT: seulement " . count($uniqueEntries) . " entrées trouvées, en dessous du seuil de sécurité (50). Fichier existant conservé.");
    exit(1);
}

// ---------- 3. SAUVEGARDE EN JSON ----------
$output = [
    'source'       => $sourceUrl,
    'updated_at'   => date('Y-m-d H:i:s'),
    'entry_count'  => count($uniqueEntries),
    'entries'      => $uniqueEntries,
];

$json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (@file_put_contents($outputFile, $json) === false) {
    logMessage("ÉCHEC: impossible d'écrire le fichier $outputFile (vérifiez les droits d'écriture).");
    exit(1);
}

logMessage("SUCCÈS: " . count($uniqueEntries) . " entrées sauvegardées dans assets/blacklist.json.");
exit(0);
