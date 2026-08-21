<?php
/**
 * generate-alert-pages.php
 * ---------------------------------------------------------------------
 * Génère une vraie page HTML par alerte communautaire publiée et
 * validée, dans le dossier /alertes/. Contrairement à la page Alertes
 * classique (dont le contenu est injecté en JavaScript après chargement,
 * donc peu ou mal indexé par Google), chaque page ici contient le texte
 * de l'alerte directement dans le code source HTML.
 *
 * Objectif : que chaque alerte devienne une porte d'entrée indexable sur
 * la requête que tapent réellement les victimes ("<société> avis",
 * "<société> arnaque"), au lieu de dépendre uniquement de la page
 * d'accueil généraliste.
 *
 * APPELÉ AUTOMATIQUEMENT PAR UNE TÂCHE PLANIFIÉE (CRON), une fois par
 * jour, comme update-blacklist.php — voir les instructions de
 * configuration fournies séparément.
 *
 * SÉCURITÉ : récupère les données via l'endpoint public alerts_list de
 * airtable-proxy.php (déjà en place) — le jeton Airtable n'est jamais
 * dupliqué dans ce fichier.
 * ---------------------------------------------------------------------
 */

// Le jeton réel est chargé depuis un fichier NON versionné (cron-secret.php),
// jamais commité dans Git — voir cron-secret.sample.php pour la marche à suivre.
$secretFile = __DIR__ . '/cron-secret.php';
$SECRET_TOKEN = file_exists($secretFile) ? require $secretFile : null;

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    // Accepte le jeton via en-tête HTTP (prioritaire, jamais journalisé dans
    // les logs d'accès) ou via paramètre d'URL (compatibilité, journalisé —
    // préférez l'en-tête quand possible).
    $providedToken = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
    if (!$SECRET_TOKEN || !hash_equals($SECRET_TOKEN, $providedToken)) {
        http_response_code(403);
        die("Accès refusé.\n");
    }
}

// ---------- CONFIGURATION ----------
$SITE_URL       = 'https://ziegler-alertearnaque.com';
$PROXY_URL      = $SITE_URL . '/airtable-proxy.php?action=alerts_list';
$ALERTES_DIR    = __DIR__ . '/alertes';
$MANIFEST_FILE  = __DIR__ . '/assets/alert-pages-manifest.json';
$SITEMAP_FILE   = __DIR__ . '/sitemap.xml';
$LOG_FILE       = __DIR__ . '/assets/alert-pages-update.log';

function logAP($msg) {
    global $LOG_FILE;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    file_put_contents($LOG_FILE, $line, FILE_APPEND);
    echo $line;
}

// ---------- GÉNÉRATION DE SLUG (URL) ----------
// IMPORTANT : cette fonction doit produire EXACTEMENT le même résultat
// que sa jumelle JavaScript dans ziegler-alertearnaque-alertes.html.
// La moindre divergence casserait tous les liens internes (404).
function generateSlug($str) {
    $manual = ['œ'=>'oe','Œ'=>'OE','æ'=>'ae','Æ'=>'AE','ß'=>'ss','ø'=>'o','Ø'=>'O','đ'=>'d','Đ'=>'D'];
    $str = strtr($str, $manual);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
    if ($ascii !== false) $str = $ascii;
    $str = strtolower($str);
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    $str = trim($str, '-');
    if (strlen($str) > 80) {
        $str = substr($str, 0, 80);
        $str = rtrim($str, '-');
    }
    if ($str === '') $str = 'entite';
    return $str;
}

function categorySlugPhp($tag) {
    $t = strtolower($tag ?: '');
    if (strpos($t, 'crypto') !== false) return 'crypto';
    if (strpos($t, 'conseiller') !== false) return 'conseiller';
    if (strpos($t, 'trading') !== false || strpos($t, 'forex') !== false || strpos($t, 'cfd') !== false) return 'trading';
    if (strpos($t, 'placement') !== false) return 'placement';
    if (strpos($t, 'livret') !== false) return 'livret';
    return 'autre';
}

function formatDateFr($iso) {
    $d = DateTime::createFromFormat('Y-m-d', substr($iso, 0, 10));
    if (!$d) return $iso;
    $mois = ['01'=>'janvier','02'=>'février','03'=>'mars','04'=>'avril','05'=>'mai','06'=>'juin',
              '07'=>'juillet','08'=>'août','09'=>'septembre','10'=>'octobre','11'=>'novembre','12'=>'décembre'];
    return intval($d->format('d')) . ' ' . $mois[$d->format('m')] . ' ' . $d->format('Y');
}

// ---------- 1. RÉCUPÉRATION DES ALERTES PUBLIÉES ----------
$context = stream_context_create(['http' => ['timeout' => 20]]);
$response = @file_get_contents($PROXY_URL, false, $context);

if ($response === false) {
    logAP("ÉCHEC: impossible de contacter $PROXY_URL. Aucune page modifiée ou supprimée.");
    exit(1);
}

$data = json_decode($response, true);
if (!isset($data['records']) || !is_array($data['records'])) {
    logAP("ÉCHEC: réponse du relais invalide. Aucune page modifiée ou supprimée.");
    exit(1);
}

$records = $data['records'];

// ---------- 2. CONSTRUCTION DE LA LISTE DES ALERTES AVEC SLUGS UNIQUES ----------
$alerts = [];
$usedSlugs = [];
foreach ($records as $r) {
    $fields = $r['fields'] ?? [];
    $entity = trim($fields['Entité concernée'] ?? '');
    if ($entity === '') continue; // pas de nom exploitable, page impossible à générer utilement

    $baseSlug = generateSlug($entity);
    $slug = $baseSlug;
    $suffix = 2;
    while (in_array($slug, $usedSlugs, true)) {
        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    }
    $usedSlugs[] = $slug;

    $alerts[] = [
        'slug' => $slug,
        'entity' => $entity,
        'type' => $fields['Type'] ?? 'Autre',
        'text' => trim($fields['Texte de l\'alerte'] ?? ''),
        'date' => $fields['Date'] ?? date('Y-m-d'),
        'initials' => $fields['Initiales'] ?? '—',
        'count' => intval($fields['Signalements similaires'] ?? 1),
    ];
}

// ---------- 3. GARDE-FOU : ne jamais supprimer massivement sur un incident ----------
if (!is_dir($ALERTES_DIR)) mkdir($ALERTES_DIR, 0755, true);

$previousSlugs = [];
if (file_exists($MANIFEST_FILE)) {
    $manifestData = json_decode(file_get_contents($MANIFEST_FILE), true);
    $previousSlugs = $manifestData['slugs'] ?? [];
}

$currentSlugs = array_column($alerts, 'slug');
$previousCount = count($previousSlugs);
$currentCount = count($currentSlugs);

$safeToDelete = true;
if ($previousCount > 0 && $currentCount < $previousCount * 0.5) {
    $safeToDelete = false;
    logAP("AVERTISSEMENT: chute de " . $previousCount . " à " . $currentCount . " alertes (>50%). Suppression désactivée par sécurité — vérifiez la connexion Airtable/relais.");
}

// ---------- 4. GÉNÉRATION DES PAGES HTML ----------
$pageTemplate = <<<'TPL'
<!DOCTYPE html>
<html lang="fr">
<head>
<script async src="https://www.googletagmanager.com/gtag/js?id=G-9BV5B0W23Z"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-9BV5B0W23Z');
</script>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#8A4CB8">
<title>{{TITLE}}</title>
<meta name="description" content="{{META_DESC}}">
<meta name="robots" content="index, follow">
<link rel="canonical" href="{{CANONICAL}}">
<meta property="og:type" content="article">
<meta property="og:site_name" content="Ziegler Alerte Arnaque">
<meta property="og:title" content="{{TITLE}}">
<meta property="og:description" content="{{META_DESC}}">
<meta property="og:url" content="{{CANONICAL}}">
<meta property="og:locale" content="fr_FR">
<meta property="og:image" content="https://ziegler-alertearnaque.com/assets/og-image.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{TITLE}}">
<meta name="twitter:description" content="{{META_DESC}}">
<meta name="twitter:image" content="https://ziegler-alertearnaque.com/assets/og-image.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "BreadcrumbList",
      "itemListElement": [
        { "@type": "ListItem", "position": 1, "name": "Accueil", "item": "https://ziegler-alertearnaque.com/" },
        { "@type": "ListItem", "position": 2, "name": "Alertes de la communauté", "item": "https://ziegler-alertearnaque.com/ziegler-alertearnaque-alertes.html" },
        { "@type": "ListItem", "position": 3, "name": "{{ENTITY}}", "item": "{{CANONICAL}}" }
      ]
    }
  ]
}
</script>
<style>
  :root{
    --ink:#1A1330; --purple:#8A4CB8; --purple-light:#C9A6E8;
    --parchment:#FFFFFF; --paper:#F6F3FA; --rule:#E6E1EE;
    --ink-soft:#58536A; --ink-soft-2:#8B8699;
    --danger:#9A382F; --warn:#B87A24; --ok:#3C6A50;
    --radius:10px; --serif:'Poppins', sans-serif; --sans:'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  }
  *{box-sizing:border-box;}
  body{margin:0; background:var(--parchment); color:var(--ink); font-family:var(--sans); font-size:16px; line-height:1.6; -webkit-font-smoothing:antialiased;}
  h1,h2{font-family:var(--serif); font-weight:700; color:var(--ink); letter-spacing:-0.01em; margin:0;}
  a{color:inherit;}
  .wrap{max-width:760px; margin:0 auto; padding:0 28px;}
  header.nav{background:#fff; border-bottom:1px solid var(--rule); padding:16px 0;}
  .nav-inner{max-width:1120px; margin:0 auto; padding:0 28px; display:flex; align-items:center; justify-content:space-between; gap:16px;}
  .brand{display:flex; align-items:center; gap:12px; text-decoration:none;}
  .brand-mark{width:42px; height:42px; border-radius:12px; background:var(--purple); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:15px; color:#fff; flex-shrink:0;}
  .brand-text strong{display:block; font-size:14.5px; font-weight:700; color:var(--purple); text-transform:uppercase;}
  .brand-text span{display:block; font-size:10.5px; color:var(--ink-soft-2); text-transform:uppercase; margin-top:2px;}
  .back{font-size:13.5px; font-weight:600; color:var(--ink-soft); text-decoration:none;}
  .back:hover{color:var(--purple);}
  main{padding:48px 0 80px;}
  .breadcrumb{font-size:12.5px; color:var(--ink-soft-2); margin-bottom:20px;}
  .breadcrumb a{color:var(--ink-soft-2); text-decoration:underline;}
  .badge{display:inline-block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:5px 12px; border-radius:999px; background:var(--purple); color:#fff; margin-bottom:16px;}
  .badge[data-cat="crypto"]{background:#D97A2E;}
  .badge[data-cat="conseiller"]{background:#C4392E;}
  .badge[data-cat="trading"]{background:#2D6FBF;}
  .badge[data-cat="placement"]{background:#1E8F76;}
  .badge[data-cat="livret"]{background:#B0508A;}
  .badge[data-cat="autre"]{background:#5B5568;}
  h1{font-size:clamp(24px,4vw,32px); line-height:1.25; margin-bottom:10px;}
  .meta-line{font-size:13px; color:var(--ink-soft-2); margin-bottom:32px;}
  .alert-text{font-size:16.5px; line-height:1.75; color:var(--ink); margin-bottom:32px; white-space:pre-line;}
  .cta-band{background:var(--ink); border-radius:16px; padding:32px; text-align:center; color:#fff;}
  .cta-band h2{color:#fff; font-size:19px; margin-bottom:10px;}
  .cta-band p{font-size:14px; color:#C7CCD9; margin-bottom:20px;}
  .btn{padding:13px 24px; border-radius:999px; font-size:14px; font-weight:600; background:var(--purple); color:#fff; text-decoration:none; display:inline-flex; align-items:center; gap:8px;}
  .back-link{display:inline-block; margin-top:36px; font-size:13.5px; color:var(--ink-soft); text-decoration:none;}
  .back-link:hover{color:var(--purple);}
  footer{background:var(--ink); color:#9AA2B6; padding:28px 0; font-size:12.5px; text-align:center;}
  footer a{color:#C7CCD9; text-decoration:underline;}
</style>
</head>
<body>

<header class="nav">
  <div class="nav-inner">
    <a href="/" class="brand">
      <span class="brand-mark">ZA</span>
      <span class="brand-text"><strong>Ziegler Alerte Arnaque</strong><span>Service du cabinet Ziegler &amp; Associés</span></span>
    </a>
    <a class="back" href="/ziegler-alertearnaque-alertes.html">← Toutes les alertes</a>
  </div>
</header>

<main>
  <div class="wrap">
    <p class="breadcrumb"><a href="/">Accueil</a> › <a href="/ziegler-alertearnaque-alertes.html">Alertes de la communauté</a> › {{ENTITY}}</p>

    <span class="badge" data-cat="{{CAT_SLUG}}">{{TYPE}}</span>
    <h1>{{ENTITY}} : que disent les signalements ?</h1>
    <p class="meta-line">Signalement du {{DATE}} — {{COUNT}} signalement{{PLURAL}} similaire{{PLURAL}} reçu{{PLURAL}} par le cabinet</p>

    <div class="alert-text">{{TEXT}}</div>

    <div class="cta-band">
      <h2>Vous vivez une situation similaire ?</h2>
      <p>Décrivez votre situation en toute confidentialité — un avocat du cabinet l'examine.</p>
      <a class="btn" href="/#signaler">Décrire ma situation →</a>
    </div>

    <a class="back-link" href="/ziegler-alertearnaque-alertes.html">← Retour à toutes les alertes de la communauté</a>
  </div>
</main>

<footer>
  <div class="wrap">
    © 2026 Ziegler Alerte Arnaque — un service édité par l'AARPI Cabinet d'Avocats Ziegler &amp; Associés.
    <br><a href="/">Plateforme</a> · <a href="/ziegler-alertearnaque-faq.html">FAQ</a> · <a href="/ziegler-alertearnaque-mentions-legales.html">Mentions légales</a>
  </div>
</footer>

</body>
</html>
TPL;

$generatedFiles = [];
foreach ($alerts as $a) {
    $entityEsc = htmlspecialchars($a['entity'], ENT_QUOTES, 'UTF-8');
    $typeEsc = htmlspecialchars($a['type'], ENT_QUOTES, 'UTF-8');
    $textEsc = nl2br(htmlspecialchars($a['text'], ENT_QUOTES, 'UTF-8'));
    $dateFr = formatDateFr($a['date']);
    $count = $a['count'];
    $plural = $count > 1 ? 's' : '';
    $canonical = "$SITE_URL/alertes/{$a['slug']}.html";
    $title = "$entityEsc : signalements et avis — Ziegler Alerte Arnaque";
    $metaDesc = mb_substr("Signalement communautaire concernant $entityEsc, vérifié par le Cabinet d'Avocats Ziegler & Associés. " . strip_tags($a['text']), 0, 155);

    $html = strtr($pageTemplate, [
        '{{TITLE}}' => $title,
        '{{META_DESC}}' => htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8'),
        '{{CANONICAL}}' => $canonical,
        '{{ENTITY}}' => $entityEsc,
        '{{TYPE}}' => $typeEsc,
        '{{CAT_SLUG}}' => categorySlugPhp($a['type']),
        '{{DATE}}' => $dateFr,
        '{{COUNT}}' => $count,
        '{{PLURAL}}' => $plural,
        '{{TEXT}}' => $textEsc,
    ]);

    file_put_contents($ALERTES_DIR . '/' . $a['slug'] . '.html', $html);
    $generatedFiles[] = $a['slug'] . '.html';
}

// ---------- 5. SUPPRESSION DES PAGES ORPHELINES (si sûr) ----------
$removedCount = 0;
if ($safeToDelete) {
    $orphans = array_diff($previousSlugs, $currentSlugs);
    foreach ($orphans as $orphanSlug) {
        $orphanFile = $ALERTES_DIR . '/' . $orphanSlug . '.html';
        if (file_exists($orphanFile)) {
            unlink($orphanFile);
            $removedCount++;
        }
    }
}

// ---------- 6. INDEX /alertes/index.html ----------
$indexItems = '';
foreach ($alerts as $a) {
    $entityEsc = htmlspecialchars($a['entity'], ENT_QUOTES, 'UTF-8');
    $indexItems .= '<li><a href="/alertes/' . $a['slug'] . '.html">' . $entityEsc . '</a> — ' . formatDateFr($a['date']) . "</li>\n";
}
$indexHtml = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Toutes les alertes signalées — Ziegler Alerte Arnaque</title>
<meta name="description" content="Index de toutes les alertes communautaires validées par le Cabinet d'Avocats Ziegler & Associés.">
<link rel="canonical" href="{$SITE_URL}/alertes/">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<style>body{font-family:sans-serif; max-width:760px; margin:40px auto; padding:0 20px; color:#1A1330;} a{color:#8A4CB8;} h1{font-size:22px;} li{margin-bottom:8px;}</style>
</head>
<body>
<p><a href="/">← Ziegler Alerte Arnaque</a></p>
<h1>Toutes les alertes signalées par la communauté</h1>
<ul>
{$indexItems}</ul>
</body>
</html>
HTML;
file_put_contents($ALERTES_DIR . '/index.html', $indexHtml);

// ---------- 7. MISE À JOUR DU MANIFESTE ----------
file_put_contents($MANIFEST_FILE, json_encode(['updated_at' => date('Y-m-d H:i:s'), 'slugs' => $currentSlugs], JSON_PRETTY_PRINT));

// ---------- 8. MISE À JOUR DU SITEMAP ----------
if (file_exists($SITEMAP_FILE)) {
    $sitemap = file_get_contents($SITEMAP_FILE);
    // Retire les anciennes entrées /alertes/ générées précédemment
    $sitemap = preg_replace('#\s*<url>\s*<loc>' . preg_quote($SITE_URL, '#') . '/alertes/[^<]*</loc>.*?</url>\s*#s', '', $sitemap);
    $newEntries = '';
    // Le sommaire /alertes/ doit lui aussi figurer au sitemap : c'est la page
    // qui recense toutes les alertes, et elle etait jusqu'ici absente.
    $newEntries .= "  <url>\n    <loc>$SITE_URL/alertes/</loc>\n    <lastmod>" . date('Y-m-d') . "</lastmod>\n    <changefreq>daily</changefreq>\n    <priority>0.9</priority>\n  </url>\n";
    foreach ($currentSlugs as $slug) {
        $newEntries .= "  <url>\n    <loc>$SITE_URL/alertes/$slug.html</loc>\n    <lastmod>" . date('Y-m-d') . "</lastmod>\n    <changefreq>monthly</changefreq>\n    <priority>0.6</priority>\n  </url>\n";
    }
    $sitemap = str_replace('</urlset>', $newEntries . '</urlset>', $sitemap);
    file_put_contents($SITEMAP_FILE, $sitemap);
}

logAP("SUCCÈS: " . count($generatedFiles) . " page(s) générée(s), " . $removedCount . " orpheline(s) supprimée(s)." . (!$safeToDelete ? " (suppression désactivée par sécurité)" : ""));
