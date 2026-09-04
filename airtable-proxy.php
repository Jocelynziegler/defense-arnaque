<?php
/**
 * airtable-proxy.php
 * ---------------------------------------------------------------------
 * Relais EN LECTURE SEULE vers Airtable : le jeton API Airtable ne
 * quitte jamais ce fichier (jamais visible cote navigateur). Aucune
 * ecriture n'est effectuee ici -- toutes les soumissions du site
 * (nouvelle alerte, message de forum) passent par send-mail.php, pour
 * validation manuelle avant publication.
 *
 * Actions supportees (?action=...) :
 *   - alerts_list : alertes communautaires validees ("Publié" coche),
 *                   les plus recentes en premier
 *   - ticker      : meme source, limitee pour le bandeau defilant
 *   - forum_threads / forum_replies : le forum n'a pas encore de table
 *                   dediee -- renvoie une liste vide (le frontend bascule
 *                   deja proprement sur ses exemples de repli dans ce cas)
 * ---------------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://ziegler-alertearnaque.com');

$configFile = __DIR__ . '/airtable-config.php';
if (!file_exists($configFile)) {
    http_response_code(503);
    echo json_encode(['error' => 'Configuration manquante. Voir airtable-config.sample.php.']);
    exit;
}
require $configFile;

if (!defined('AIRTABLE_TOKEN') || !defined('AIRTABLE_BASE_ID') || AIRTABLE_TOKEN === 'VOTRE_JETON_ICI') {
    http_response_code(503);
    echo json_encode(['error' => 'Configuration Airtable incomplete.']);
    exit;
}

const TABLE_ALERTES = 'Défense Arnaque';

function airtableGet($path, $params = []) {
    $url = 'https://api.airtable.com/v0/' . AIRTABLE_BASE_ID . '/' . rawurlencode($path);
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . AIRTABLE_TOKEN],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError || $httpCode !== 200) {
        error_log("airtable-proxy.php: echec requete Airtable ($path) HTTP $httpCode $curlError");
        return null;
    }
    return json_decode($response, true);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'alerts_list':
        $data = airtableGet(TABLE_ALERTES, [
            'filterByFormula' => '{Publié}=TRUE()',
            'sort[0][field]' => 'Date',
            'sort[0][direction]' => 'desc',
        ]);
        if ($data === null) {
            http_response_code(502);
            echo json_encode(['error' => 'Service momentanement indisponible.']);
            exit;
        }
        echo json_encode(['records' => $data['records'] ?? []]);
        break;

    case 'ticker':
        $data = airtableGet(TABLE_ALERTES, [
            'filterByFormula' => '{Publié}=TRUE()',
            'sort[0][field]' => 'Date',
            'sort[0][direction]' => 'desc',
            'maxRecords' => 8,
        ]);
        if ($data === null) {
            http_response_code(502);
            echo json_encode(['error' => 'Service momentanement indisponible.']);
            exit;
        }
        echo json_encode(['records' => $data['records'] ?? []]);
        break;

    case 'forum_threads':
    case 'forum_replies':
        // Pas encore de table dediee au forum -- reponse vide, dans le meme
        // format qu'Airtable, pour que le frontend (deja prevu pour ce cas)
        // bascule proprement sur ses exemples de repli.
        echo json_encode(['records' => []]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action inconnue.']);
}
