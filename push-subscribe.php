<?php
/**
 * push-subscribe.php
 * ---------------------------------------------------------------------
 * Reçoit un objet d'abonnement Web Push (envoyé par le navigateur après
 * que l'utilisateur a autorisé les notifications) et le stocke dans un
 * fichier JSON simple sur le serveur.
 *
 * Ces abonnements ne contiennent AUCUNE donnée personnelle identifiante
 * (pas de nom, pas d'email) — uniquement une URL de point de terminaison
 * technique fournie par le navigateur (Chrome, Firefox...) et des clés de
 * chiffrement. C'est pourquoi un simple fichier JSON suffit ici, contrairement
 * à un dossier client qui doit vivre dans un vrai logiciel de gestion.
 * ---------------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://ziegler-alertearnaque.com');

$STORE_FILE = __DIR__ . '/push-subscribers.json';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || empty($data['endpoint']) || empty($data['keys']['p256dh']) || empty($data['keys']['auth'])) {
    http_response_code(400);
    echo json_encode(['error' => "Objet d'abonnement invalide"]);
    exit;
}

$fp = fopen($STORE_FILE, 'c+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['error' => 'Impossible d\'accéder au fichier de stockage']);
    exit;
}

flock($fp, LOCK_EX);
$contents = stream_get_contents($fp);
$subscribers = json_decode($contents, true);
if (!is_array($subscribers)) $subscribers = [];

// Deduplique par endpoint (un meme appareil peut se reabonner avec des cles rafraichies)
$subscribers = array_filter($subscribers, function($s) use ($data) {
    return ($s['endpoint'] ?? null) !== $data['endpoint'];
});
$subscribers = array_values($subscribers);
$subscribers[] = [
    'endpoint' => $data['endpoint'],
    'keys' => ['p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']],
    'subscribed_at' => date('c'),
];

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($subscribers, JSON_PRETTY_PRINT));
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['ok' => true]);
