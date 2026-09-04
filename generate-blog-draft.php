<?php
/**
 * generate-blog-draft.php
 * ---------------------------------------------------------------------
 * Cherche chaque jour, dans la liste noire officielle AMF (catégorie
 * crypto-actifs uniquement), une plateforme pas encore traitée, verifie
 * si des victimes françaises documentées existent reellement (etape 1),
 * et si oui redige un brouillon d'article de blog (etape 2).
 *
 * IMPORTANT : ne publie JAMAIS automatiquement. Le brouillon est stocke
 * en attente de relecture humaine, une notification email est envoyee,
 * et c'est via blog-review.php qu'un brouillon est valide ou rejete.
 *
 * APPELE AUTOMATIQUEMENT PAR UNE TACHE PLANIFIEE (CRON), une fois par
 * jour -- meme mecanisme que update-blacklist.php et
 * generate-alert-pages.php deja en place.
 * ---------------------------------------------------------------------
 */

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

$configFile = __DIR__ . '/ai-config.php';
if (!file_exists($configFile)) {
    die("Configuration IA manquante (ai-config.php). Voir ai-config.sample.php.\n");
}
require $configFile;

$CSV_URL         = 'https://www.data.gouv.fr/api/1/datasets/r/d2d9df6d-1cd2-41a8-96f5-684cb3057ecb'; // Source officielle AMF via data.gouv.fr (producteur direct, mise a jour quotidienne -- verifie plus fiable que le relais ABE-InfoService utilise par update-blacklist.php)
$COVERED_FILE    = __DIR__ . '/blog-covered-platforms.json';
$DRAFTS_FILE     = __DIR__ . '/blog-drafts.json';
$LOG_FILE        = __DIR__ . '/assets/blog-draft-generation.log';
$MAX_CANDIDATS_PAR_EXECUTION = 5; // evite un temps d'execution trop long si plusieurs candidats de suite n'ont pas de preuves suffisantes

function logBD($msg) {
    global $LOG_FILE;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    file_put_contents($LOG_FILE, $line, FILE_APPEND);
    echo $line;
}

// ---------- 1. RECUPERATION ET FILTRAGE DE LA LISTE NOIRE (CRYPTO UNIQUEMENT) ----------
$context = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: Mozilla/5.0 (compatible; ZieglerAlerteArnaqueBot/1.0)\r\n",
        'timeout' => 30,
    ],
]);
$csvContent = @file_get_contents($CSV_URL, false, $context);
if ($csvContent === false || strlen($csvContent) < 100) {
    logBD("ÉCHEC: impossible de récupérer le CSV source ($CSV_URL).");
    exit(1);
}
$csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent);
$lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
$rows = array_map(fn($l) => str_getcsv($l, ';'), $lines); // point-virgule : format reel du CSV AMF (data.gouv.fr), different du relais ABE-InfoService qui utilise la virgule
if (count($rows) < 2) {
    logBD("ÉCHEC: CSV vide ou mal formé.");
    exit(1);
}
array_shift($rows); // en-tête : Nom, Categorie, Date d'inscription (format data.gouv.fr)

$candidats = [];
foreach ($rows as $row) {
    if (empty($row[0])) continue;
    $categorie = strtolower($row[1] ?? ''); // format data.gouv.fr : Nom, Categorie, Date (3 colonnes, pas 4)
    if (strpos($categorie, 'crypto') === false) continue; // filtre : categorie crypto-actifs uniquement, sur demande explicite
    $candidats[] = [
        'nom'       => trim($row[0]),
        'categorie' => trim($row[1] ?? ''),
        'date_ajout' => trim($row[2] ?? ''),
    ];
}
logBD(count($candidats) . " candidats trouves dans la categorie crypto-actifs.");

if (empty($candidats)) {
    logBD("Aucun candidat crypto-actifs dans la liste noire actuelle. Arrêt.");
    exit(0);
}

// ---------- 2. EXCLUSION DES PLATEFORMES DEJA TRAITEES ----------
$covered = [];
if (file_exists($COVERED_FILE)) {
    $covered = json_decode(file_get_contents($COVERED_FILE), true) ?: [];
}
$coveredNames = array_column($covered, 'nom');

$aTraiter = array_values(array_filter($candidats, fn($c) => !in_array($c['nom'], $coveredNames, true)));
logBD(count($aTraiter) . " candidats pas encore traites.");

if (empty($aTraiter)) {
    logBD("Tous les candidats crypto-actifs connus ont deja ete traites. Arrêt.");
    exit(0);
}

// ---------- 3. APPEL API : VERIFICATION PUIS REDACTION ----------
function callAnthropic($systemPrompt, $userMessage, $toolName, $toolDescription, $toolSchema, $maxSearches, $maxTokens) {
    $tools = [];
    if ($maxSearches > 0) {
        $tools[] = ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => $maxSearches];
    }
    $tools[] = ['name' => $toolName, 'description' => $toolDescription, 'input_schema' => $toolSchema];

    $body = [
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => $maxTokens,
        'system' => $systemPrompt,
        'messages' => [['role' => 'user', 'content' => $userMessage]],
        'tools' => $tools,
    ];
    // Sans recherche web, on force l'appel direct de l'outil (pas de preambule
    // en texte libre) -- economise des tokens en plus d'economiser la recherche.
    if ($maxSearches === 0) {
        $body['tool_choice'] = ['type' => 'tool', 'name' => $toolName];
    }
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
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return null;
    $result = json_decode($response, true);
    foreach (($result['content'] ?? []) as $block) {
        if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $toolName) {
            return $block['input'] ?? null;
        }
    }
    return null;
}

$VERIF_SYSTEM_PROMPT = <<<'PROMPT'
Tu travailles pour un cabinet d'avocats francais specialise dans la defense des victimes d'escroqueries financieres, qui publie un article de blog quotidien sur une plateforme de la liste noire AMF -- mais UNIQUEMENT si elle a reellement fait des victimes francaises documentees en investissement ou crypto-actifs.

MISSION : verifier si des preuves solides existent que des victimes FRANCAISES ont perdu de l'argent en essayant d'investir (argent classique ou crypto-actifs) via cette plateforme precise.

REGLE CRITIQUE : etre inscrit sur la liste noire AMF signifie seulement "non autorise a operer en France" -- ca NE PROUVE PAS qu'il y a des victimes. Certaines plateformes blacklistees sont de grands acteurs internationaux legitimes ailleurs, juste non enregistres en France. D'autres sont de pures coquilles vides creees pour arnaquer, avec des temoignages de victimes.

Fais une recherche web reelle pour trouver des preuves concretes : temoignages de victimes francaises, plaintes, articles de presse francaise, forums de victimes, procedures en cours. L'absence de ces preuves doit conduire a "preuves_insuffisantes", meme si la plateforme est bien sur liste noire.

Maximum 3 recherches web. Le champ "resume" est OBLIGATOIRE et ne doit JAMAIS etre vide, meme si le verdict est preuves_insuffisantes -- explique toujours ce que tu as trouve ou pas trouve. N'inclus jamais de balises de citation ou de markup.
PROMPT;

$VERIF_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'verdict' => ['type' => 'string', 'enum' => ['victimes_confirmees', 'preuves_insuffisantes']],
        'resume' => ['type' => 'string', 'description' => 'OBLIGATOIRE, jamais vide. 2-4 phrases: ce qui a ete trouve (ou l\'absence de preuve), avec details concrets si victimes_confirmees.'],
    ],
    'required' => ['verdict', 'resume'],
];

$ARTICLE_SYSTEM_PROMPT = <<<'PROMPT'
Tu rediges un article de blog pour le Cabinet d'Avocats Ziegler & Associes, sur une plateforme crypto confirmee comme ayant fait des victimes francaises, deja verifiee au prealable.

REGLES STRICTES :
- Utilise le nom EXACT de la plateforme fourni, sans le deformer.
- Formulations prudentes uniquement : jamais "c'est une arnaque" au sens juridique, mais "signaux qui meritent verification", "plateforme presentee comme..." etc.
- Ne mentionne ni ne recommande JAMAIS un autre avocat ou cabinet d'avocats que le Cabinet Ziegler & Associes. Si une autre structure est mentionnee dans tes sources, tu peux noter son existence generiquement sans jamais la nommer precisement si elle pourrait s'apparenter a un cabinet concurrent.
- Texte brut uniquement, pas de markdown, pas de balises de citation.
- Base-toi UNIQUEMENT sur le resume de verification deja fourni -- ne fais AUCUNE recherche supplementaire, redige directement a partir de ces elements (deja le fruit d'une recherche reelle a l'etape precedente).
- Une fois termine, appelle l'outil submit_article.
PROMPT;

$ARTICLE_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'titre' => ['type' => 'string', 'description' => 'Titre de l\'article, 50-65 caracteres, mentionne le nom EXACT de la plateforme.'],
        'meta_description' => ['type' => 'string', 'description' => '130-155 caracteres.'],
        'intro' => ['type' => 'string', 'description' => '1 paragraphe d\'introduction.'],
        'sections' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'titre' => ['type' => 'string'],
                    'contenu' => ['type' => 'string', 'description' => '1-3 paragraphes.'],
                ],
                'required' => ['titre', 'contenu'],
            ],
            'description' => '3-4 sections : comment fonctionne l\'arnaque, les signaux d\'alerte, que faire si concerne.',
        ],
    ],
    'required' => ['titre', 'meta_description', 'intro', 'sections'],
];

$brouillonCree = false;
$tentatives = 0;

foreach ($aTraiter as $candidat) {
    if ($tentatives >= $MAX_CANDIDATS_PAR_EXECUTION) {
        logBD("Limite de $MAX_CANDIDATS_PAR_EXECUTION candidats testes atteinte pour cette execution. Arrêt (reprendra demain).");
        break;
    }
    $tentatives++;
    $nom = $candidat['nom'];
    logBD("Verification de : $nom");

    $verif = callAnthropic(
        $VERIF_SYSTEM_PROMPT,
        "Plateforme sur liste noire AMF a evaluer : $nom",
        'submit_verification',
        'Soumets ta conclusion sur l\'existence de victimes francaises documentees.',
        $VERIF_SCHEMA,
        3,
        900
    );

    // Marque ce candidat comme traite, quelle que soit l'issue (evite de le retester chaque jour)
    $covered[] = ['nom' => $nom, 'traite_le' => date('Y-m-d'), 'resultat' => $verif['verdict'] ?? 'erreur'];

    if (!$verif || $verif['verdict'] !== 'victimes_confirmees') {
        logBD("  -> Pas de victimes confirmees ou erreur. Passage au candidat suivant.");
        continue;
    }

    logBD("  -> Victimes confirmees ! Redaction de l'article...");
    $article = callAnthropic(
        $ARTICLE_SYSTEM_PROMPT,
        "Plateforme : $nom\n\nResume de la verification prealable (victimes confirmees) : " . $verif['resume'] . "\n\nRedige l'article complet, sans recherche supplementaire.",
        'submit_article',
        'Soumets l\'article complet redige.',
        $ARTICLE_SCHEMA,
        0,
        2000
    );

    if (!$article) {
        logBD("  -> Echec de la redaction de l'article. Candidat marque traite, on continue.");
        continue;
    }

    // ---------- Sauvegarde du brouillon (jamais publie automatiquement) ----------
    $drafts = [];
    if (file_exists($DRAFTS_FILE)) {
        $drafts = json_decode(file_get_contents($DRAFTS_FILE), true) ?: [];
    }
    $draftId = date('Ymd') . '-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($nom));
    $drafts[] = [
        'id' => $draftId,
        'plateforme' => $nom,
        'categorie' => $candidat['categorie'],
        'genere_le' => date('Y-m-d H:i:s'),
        'verification_resume' => $verif['resume'],
        'article' => $article,
        'statut' => 'en_attente',
    ];
    file_put_contents($DRAFTS_FILE, json_encode($drafts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    logBD("  -> Brouillon sauvegarde (id: $draftId).");
    $brouillonCree = true;
    break; // un seul article par jour, sur demande explicite
}

file_put_contents($COVERED_FILE, json_encode($covered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

// ---------- 4. NOTIFICATION EMAIL SI UN BROUILLON A ETE CREE ----------
if ($brouillonCree) {
    $mailConfigFile = __DIR__ . '/mail-config.php';
    if (file_exists($mailConfigFile)) {
        require __DIR__ . '/lib/PHPMailer/Exception.php';
        require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
        require __DIR__ . '/lib/PHPMailer/SMTP.php';
        $config = require $mailConfigFile;
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $config['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['smtp_user'];
            $mail->Password = $config['smtp_pass'];
            $mail->SMTPSecure = $config['smtp_secure'] === 'tls' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $config['smtp_port'];
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($config['smtp_user'], 'Ziegler Alerte Arnaque — Blog');
            $mail->addAddress($config['to_email'], $config['to_name'] ?? '');
            $mail->Subject = 'Nouveau brouillon d\'article de blog à relire';
            $mail->isHTML(true);
            $mail->Body = '<p>Un nouveau brouillon d\'article a été généré et attend votre relecture.</p><p><a href="https://ziegler-alertearnaque.com/blog-review.php">Consulter et valider →</a></p>';
            $mail->send();
            logBD("Notification email envoyee.");
        } catch (Exception $e) {
            logBD("Echec de l'envoi de la notification email : " . $e->getMessage());
        }
    } else {
        logBD("mail-config.php absent, notification email non envoyee (le brouillon est quand meme sauvegarde).");
    }
}

logBD("Execution terminee.");
