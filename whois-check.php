<?php
/**
 * whois-check.php
 * ---------------------------------------------------------------------
 * Renvoie l'age d'enregistrement (WHOIS) d'un nom de domaine, via l'API
 * WhoisJSON. Utilise par l'outil "Verifier un nom de domaine" et par la
 * verification de societe (recherche unique) sur l'accueil.
 *
 * Appel attendu par le frontend : GET /whois-check.php?domain=exemple.com
 * Reponse attendue par le frontend :
 *   { "ageDays": <nombre>, "created": "<date ISO>", "isNewlyRegistered": <bool>, "registrar": "<nom>" }
 *   ou { "error": "..." } en cas d'echec (le frontend masque alors le bloc plutot
 *   que d'afficher une erreur technique -- ne jamais casser la page).
 *
 * SEUIL_JOURS_RECENT : un domaine cree il y a moins de ce nombre de jours
 * est considere "recent" (signal d'alerte frequent) -- 90 jours, coherent
 * avec le reste du site (llms.txt).
 * ---------------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://ziegler-alertearnaque.com');

$SEUIL_JOURS_RECENT = 90;

$configFile = __DIR__ . '/whois-config.php';
if (!file_exists($configFile)) {
    http_response_code(503);
    echo json_encode(['error' => 'Configuration manquante. Voir whois-config.sample.php.']);
    exit;
}
require $configFile;

if (!defined('WHOISJSON_API_KEY') || WHOISJSON_API_KEY === 'VOTRE_CLE_API_ICI') {
    http_response_code(503);
    echo json_encode(['error' => 'Cle API non configuree.']);
    exit;
}

$domain = trim($_GET['domain'] ?? '');
// Nettoyage minimal : retire un eventuel protocole/chemin, garde juste le nom d'hote.
$domain = preg_replace('#^https?://#i', '', $domain);
$domain = explode('/', $domain)[0];
$domain = preg_replace('/^www\./i', '', $domain);

if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
    http_response_code(400);
    echo json_encode(['error' => 'Nom de domaine invalide']);
    exit;
}

$ch = curl_init('https://whoisjson.com/api/v1/whois?domain=' . urlencode($domain));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: TOKEN=' . WHOISJSON_API_KEY],
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode !== 200) {
    error_log("whois-check.php: echec API WhoisJSON pour '$domain' (HTTP $httpCode) $curlError");
    http_response_code(502);
    echo json_encode(['error' => 'Service WHOIS momentanement indisponible.']);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data) || empty($data['created'])) {
    // Domaine non trouve / non enregistre / champ absent pour ce TLD -- pas une
    // erreur technique, juste une absence d'information exploitable.
    echo json_encode(['ageDays' => null, 'created' => null, 'isNewlyRegistered' => false]);
    exit;
}

$createdTimestamp = strtotime($data['created']);
if ($createdTimestamp === false) {
    echo json_encode(['ageDays' => null, 'created' => null, 'isNewlyRegistered' => false]);
    exit;
}

$ageDays = (int) floor((time() - $createdTimestamp) / 86400);
$registrarName = $data['registrar']['name'] ?? null;

echo json_encode([
    'ageDays' => $ageDays,
    'created' => $data['created'],
    'isNewlyRegistered' => $ageDays >= 0 && $ageDays < $SEUIL_JOURS_RECENT,
    'registrar' => $registrarName,
]);
