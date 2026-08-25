<?php
/**
 * ai-verification.php
 *
 * Outil de verification IA : recherche sur le web ce qui se dit en ce moment
 * sur une societe/plateforme d'investissement (avis, forums, articles), et
 * renvoie une analyse prudente au visiteur.
 *
 * SECURITE : la cle API Anthropic ne quitte jamais ce fichier. Elle vit dans
 * ai-config.php (non versionne, jamais sur GitHub -- voir ai-config.sample.php
 * pour le gabarit).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://ziegler-alertearnaque.com');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Methode non autorisee']);
    exit;
}

$configFile = __DIR__ . '/ai-config.php';
if (!file_exists($configFile)) {
    http_response_code(503);
    echo json_encode(['error' => "Configuration manquante. Voir ai-config.sample.php."]);
    exit;
}
require $configFile;

if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === 'VOTRE_CLE_API_ICI') {
    http_response_code(503);
    echo json_encode(['error' => 'Cle API non configuree.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || empty($data['nom']) || !is_string($data['nom'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Nom de societe manquant']);
    exit;
}

$nom = trim($data['nom']);
if (mb_strlen($nom) < 2 || mb_strlen($nom) > 150) {
    http_response_code(400);
    echo json_encode(['error' => 'Nom invalide']);
    exit;
}

// ---------- Limitation de debit : 3 verifications par IP par jour ----------
// Fenetre glissante de 24h (pas juste "aujourd'hui" calendaire), meme
// mecanisme eprouve que send-mail.php mais adapte a une fenetre plus longue
// et un plafond plus bas, vu que chaque appel a ici un cout reel en argent.
$RATE_LIMIT_MAX = 3;
$RATE_LIMIT_WINDOW = 86400; // 24 heures
$rateLimitFile = __DIR__ . '/ai-verification-rate-limit.json';
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'inconnu';

$rlFp = fopen($rateLimitFile, 'c+');
if ($rlFp) {
    flock($rlFp, LOCK_EX);
    $rlContents = stream_get_contents($rlFp);
    $rlData = json_decode($rlContents, true);
    if (!is_array($rlData)) $rlData = [];

    $now = time();
    foreach ($rlData as $ip => $timestamps) {
        $rlData[$ip] = array_values(array_filter($timestamps, fn($t) => $now - $t < $RATE_LIMIT_WINDOW));
        if (empty($rlData[$ip])) unset($rlData[$ip]);
    }

    $recentCount = count($rlData[$clientIp] ?? []);
    if ($recentCount >= $RATE_LIMIT_MAX) {
        flock($rlFp, LOCK_UN);
        fclose($rlFp);
        http_response_code(429);
        echo json_encode(['error' => 'Limite quotidienne atteinte (3 verifications par jour). Reessayez demain, ou contactez-nous directement.']);
        exit;
    }

    $rlData[$clientIp][] = $now;
    ftruncate($rlFp, 0);
    rewind($rlFp);
    fwrite($rlFp, json_encode($rlData));
    flock($rlFp, LOCK_UN);
    fclose($rlFp);
}
// Si le fichier ne peut pas s'ouvrir, on laisse passer plutot que de casser
// le service -- meme choix que send-mail.php.

// ---------- Plafond mensuel global (tous visiteurs confondus) ----------
// La limite par IP ci-dessus protege contre UN visiteur qui abuse, mais ne
// plafonne pas le volume total du site : 200 visiteurs differents faisant
// chacun 1 verification le meme jour ne declenchent jamais la limite par IP.
// Ce second plafond, independant, coupe l'outil pour tout le monde si le
// volume mensuel total depasse ce qui a ete budgete -- filet de securite
// contre une facture surprise, quelle que soit la repartition du trafic.
$MONTHLY_CAP = 1500; // ajustable selon le trafic reel observe (Analytics/Clarity)
$monthlyFile = __DIR__ . '/ai-verification-monthly-count.json';
$currentMonth = date('Y-m');

$mFp = fopen($monthlyFile, 'c+');
if ($mFp) {
    flock($mFp, LOCK_EX);
    $mContents = stream_get_contents($mFp);
    $mData = json_decode($mContents, true);
    if (!is_array($mData) || ($mData['mois'] ?? null) !== $currentMonth) {
        // Nouveau mois (ou fichier absent/corrompu) : on repart de zero.
        $mData = ['mois' => $currentMonth, 'total' => 0];
    }

    if ($mData['total'] >= $MONTHLY_CAP) {
        flock($mFp, LOCK_UN);
        fclose($mFp);
        http_response_code(429);
        echo json_encode(['error' => "L'outil de verification IA a atteint son volume mensuel prevu. Il sera de nouveau disponible le mois prochain, ou contactez-nous directement."]);
        exit;
    }

    $mData['total']++;
    ftruncate($mFp, 0);
    rewind($mFp);
    fwrite($mFp, json_encode($mData));
    flock($mFp, LOCK_UN);
    fclose($mFp);
}
// Meme choix qu'ailleurs : si le fichier ne peut pas s'ouvrir, on laisse
// passer plutot que de casser le service pour un probleme de permissions.

// ---------- Appel a l'API Anthropic ----------
$systemPrompt = <<<'PROMPT'
Tu verifies des societes/plateformes d'investissement pour un cabinet d'avocats francais specialise dans la defense des victimes d'escroqueries financieres.

DISTINCTION CRITIQUE A FAIRE AVANT TOUT AUTRE CHOSE :
Pour chaque information trouvee, classe-la mentalement dans une de ces 3 categories :
A) La societe/plateforme ELLE-MEME semble tromper ses propres clients/investisseurs (retraits bloques, rendements fictifs, absence de regulation pour CETTE activite d'investissement precise, plaintes de clients de CETTE plateforme)
B) Des escrocs USURPENT le nom ou se font passer pour cette societe (typiquement une vraie banque/entreprise connue dont le nom est utilise frauduleusement par des tiers) -- CECI N'EST PAS UN SIGNAL CONTRE LA SOCIETE, c'est un signal contre les usurpateurs
C) Information non liee a une escroquerie en cours (actualite generale, historique reglementaire ancien, litige isole sans rapport avec une arnaque active)

SEULE la categorie A justifie verdict = "signaux_trouves". Si tu ne trouves que des elements B et/ou C, verdict = "rien_trouve". Si tu trouves des elements de categorie B specifiquement, mentionne-le a part dans resume_pour_avocat (ex: "le nom de [societe] semble usurpe par des escrocs, verifiez l'identite reelle de votre interlocuteur").

CAS PARTICULIER FREQUENT AVEC LES BANQUES ETABLIES : des decisions de justice condamnant une banque pour "defaut de vigilance" ou refus de remboursement, dans des dossiers ou UN TIERS a arnaque le client (phishing, faux conseiller, virement frauduleux), relevent de la categorie B, PAS A -- la banque n'est pas l'auteure de l'arnaque, sa responsabilite CIVILE eventuelle pour ne pas avoir bloque une operation suspecte est un sujet juridique distinct, pas un signal que la banque elle-meme est malhonnete envers ses clients. Classe ces cas en categorie B et note-les dans resume_pour_avocat comme piste juridique eventuelle (responsabilite bancaire), sans declencher "signaux_trouves".

PRUDENCE RENFORCEE pour les grandes institutions etablies (banques nationales, assureurs connus, societes cotees) : verdict "signaux_trouves" UNIQUEMENT si tu trouves des preuves specifiques et recentes que CETTE INSTITUTION escroque activement des investisseurs sur un produit precis -- jamais sur la base d'un historique reglementaire general, d'une amende ancienne, ou d'un litige de responsabilite face a une fraude commise par un tiers.

AUTRES REGLES :
- Formulations prudentes uniquement : "signaux qui meritent verification", jamais "c'est une arnaque".
- Base-toi uniquement sur ce que tu trouves reellement via la recherche web.
- Maximum 3 recherches web.
- N'inclus JAMAIS de balises de citation, de markup, ou de syntaxe speciale (pas de <cite>, pas de crochets de reference) -- uniquement du texte brut lisible.
- Une fois tes recherches terminees, appelle l'outil submit_verdict avec ta conclusion. N'ecris PAS ton analyse en texte libre avant -- appelle l'outil directement.
PROMPT;

$body = [
    'model' => 'claude-haiku-4-5-20251001',
    'max_tokens' => 800,
    'system' => $systemPrompt,
    'messages' => [['role' => 'user', 'content' => 'Societe a verifier : ' . $nom]],
    'tools' => [
        ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3],
        [
            'name' => 'submit_verdict',
            'description' => 'Soumets ta conclusion finale sur la societe verifiee, une fois tes recherches terminees.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'verdict' => ['type' => 'string', 'enum' => ['signaux_trouves', 'rien_trouve']],
                    'signaux' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'UNIQUEMENT si verdict=signaux_trouves : signaux courts (moins de 15 mots chacun) de categorie A, texte brut. Si verdict=rien_trouve, tableau VIDE -- toute note sur une usurpation (categorie B) va dans resume_pour_avocat, pas ici.'],
                    'resume_pour_avocat' => ['type' => 'string', 'description' => 'OBLIGATOIRE et toujours non vide, 1-2 phrases, texte brut. Resume la situation pour l\'avocat qui recontactera le visiteur, y compris les eventuelles notes de categorie B ou C.'],
                ],
                'required' => ['verdict', 'signaux', 'resume_pour_avocat'],
            ],
        ],
    ],
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_TIMEOUT => 30,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode !== 200) {
    error_log("ai-verification.php: echec API Anthropic (HTTP $httpCode) $curlError");
    http_response_code(502);
    echo json_encode(['error' => "Le service de verification est momentanement indisponible."]);
    exit;
}

$apiResult = json_decode($response, true);

// L'outil submit_verdict est fourni par l'IA sous forme d'un appel d'outil
// structure (garanti par l'API, contrairement a du JSON en texte libre que
// le modele peut parfois entourer de commentaires malgre les instructions).
$verdictCall = null;
foreach (($apiResult['content'] ?? []) as $block) {
    if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'submit_verdict') {
        $verdictCall = $block['input'] ?? null;
        break;
    }
}

if (!is_array($verdictCall) || !isset($verdictCall['verdict'])) {
    error_log("ai-verification.php: aucun appel a submit_verdict trouve. Contenu recu : " . substr($response, 0, 500));
    http_response_code(502);
    echo json_encode(['error' => "Analyse impossible pour le moment, reessayez."]);
    exit;
}

// Filet de securite : retire toute balise de citation residuelle malgre les instructions.
$cleanText = fn($s) => is_string($s) ? preg_replace('/<cite[^>]*>|<\/cite>/', '', $s) : '';

$resume = $cleanText($verdictCall['resume_pour_avocat'] ?? '');
$signauxPropres = array_slice(array_map($cleanText, array_values(array_filter((array)($verdictCall['signaux'] ?? []), 'is_string'))), 0, 8);

// Filet de securite : si l'IA n'a exceptionnellement pas rempli le resume
// malgre la consigne, on en construit un minimal a partir des signaux plutot
// que de renvoyer un champ vide au visiteur.
if (trim($resume) === '') {
    $resume = !empty($signauxPropres)
        ? 'Signaux releves : ' . implode(' ; ', array_slice($signauxPropres, 0, 3)) . '.'
        : "Aucun element specifique trouve lors de cette verification.";
}

echo json_encode([
    'verdict' => $verdictCall['verdict'] === 'signaux_trouves' ? 'signaux_trouves' : 'rien_trouve',
    'signaux' => $signauxPropres,
    'resume' => $resume,
]);
