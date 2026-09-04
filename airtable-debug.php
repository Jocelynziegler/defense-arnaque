<?php
/**
 * airtable-debug.php — TEMPORAIRE, a supprimer une fois le diagnostic termine.
 * Affiche le detail complet de la reponse Airtable (code HTTP, erreur curl,
 * corps de la reponse) pour diagnostiquer un echec, sans avoir besoin
 * d'acceder aux journaux du serveur.
 */

header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/airtable-config.php';
if (!file_exists($configFile)) {
    echo json_encode(['error' => 'Configuration manquante.']);
    exit;
}
require $configFile;

$table = 'tbl9my7nEOMQkNSJL'; // Table "Alertes" (l'ancien nom "Défense Arnaque" était celui de la base, pas de la table)
$url = 'https://api.airtable.com/v0/' . AIRTABLE_BASE_ID . '/' . rawurlencode($table)
     . '?' . http_build_query(['maxRecords' => 1]);

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

echo json_encode([
    'url_appelee' => $url,
    'token_longueur' => strlen(AIRTABLE_TOKEN),
    'token_debut' => substr(AIRTABLE_TOKEN, 0, 8) . '...',
    'base_id' => AIRTABLE_BASE_ID,
    'http_code' => $httpCode,
    'curl_error' => $curlError ?: null,
    'reponse_brute' => $response,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
