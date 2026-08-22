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
$MAX_SUBSCRIBERS = 50000; // plafond de securite contre une croissance non bornee du fichier

// Un vrai objet d'abonnement push fait quelques centaines d'octets — rejette
// toute charge anormalement grande avant meme de la parser (protection contre
// un flot de requetes visant a faire grossir le fichier de stockage).
if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 8192) {
    http_response_code(413);
    echo json_encode(['error' => 'Charge utile trop volumineuse']);
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 8192);
$data = json_decode($raw, true);

if (!is_array($data) || empty($data['endpoint']) || empty($data['keys']['p256dh']) || empty($data['keys']['auth'])) {
    http_response_code(400);
    echo json_encode(['error' => "Objet d'abonnement invalide"]);
    exit;
}

// L'URL de point de terminaison doit ressembler a une vraie URL de service
// push (toujours HTTPS chez tous les navigateurs).
if (!preg_match('#^https://#i', $data['endpoint']) || strlen($data['endpoint']) > 512) {
    http_response_code(400);
    echo json_encode(['error' => "Point de terminaison invalide"]);
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

if (count($subscribers) >= $MAX_SUBSCRIBERS) {
    flock($fp, LOCK_UN);
    fclose($fp);
    http_response_code(503);
    echo json_encode(['error' => 'Capacité maximale atteinte, contactez le cabinet']);
    exit;
}

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
