<?php
/**
 * update-bic-directory.php
 * ---------------------------------------------------------------------
 * Récupère automatiquement l'annuaire des établissements bancaires
 * français (nom, ville, code BIC/SWIFT) depuis une source ouverte et
 * structurée, et le sauvegarde en JSON sur ce serveur.
 *
 * Source : https://github.com/franckverrot/codes-bic-france
 * (données dérivées des référentiels bancaires publics français)
 *
 * Le site web (ziegler-alertearnaque.html) lit ensuite ce fichier JSON
 * en local pour permettre d'identifier une banque à partir d'un code
 * BIC, sans jamais faire quitter le site à l'internaute.
 *
 * APPELÉ AUTOMATIQUEMENT PAR UNE TÂCHE PLANIFIÉE (CRON JOB) —
 * voir les instructions de configuration fournies séparément.
 * ---------------------------------------------------------------------
 */

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

$sourceUrl  = 'https://raw.githubusercontent.com/franckverrot/codes-bic-france/main/codes-bic-france.csv';
$outputFile = __DIR__ . '/assets/bic-directory.json';
$logFile    = __DIR__ . '/assets/bic-directory-update.log';

function logMessageBic($msg) {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

$context = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: Mozilla/5.0 (compatible; ZieglerAlerteArnaqueBot/1.0)\r\n",
        'timeout' => 30,
    ],
]);

$csvContent = @file_get_contents($sourceUrl, false, $context);

if ($csvContent === false || strlen($csvContent) < 1000) {
    logMessageBic("ÉCHEC: impossible de récupérer le CSV depuis $sourceUrl. Fichier existant conservé.");
    exit(1);
}

$lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
$rows = array_map('str_getcsv', $lines);
$header = array_shift($rows); // name, city, swift_code

$entries = [];
foreach ($rows as $row) {
    if (count($row) < 3) continue;
    $name = trim($row[0]);
    $city = trim($row[1]);
    $bic  = strtoupper(trim($row[2]));
    if ($bic === '') continue;
    $entries[] = ['name' => $name, 'city' => $city, 'bic' => $bic];
}

if (count($entries) < 500) {
    logMessageBic("AVERTISSEMENT: seulement " . count($entries) . " entrées trouvées, en dessous du seuil de sécurité (500). Fichier existant conservé.");
    exit(1);
}

$output = [
    'source'      => $sourceUrl,
    'updated_at'  => date('Y-m-d H:i:s'),
    'entry_count' => count($entries),
    'entries'     => $entries,
];

$json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (@file_put_contents($outputFile, $json) === false) {
    logMessageBic("ÉCHEC: impossible d'écrire le fichier $outputFile (vérifiez les droits d'écriture).");
    exit(1);
}

logMessageBic("SUCCÈS: " . count($entries) . " établissements sauvegardés dans assets/bic-directory.json.");
exit(0);
