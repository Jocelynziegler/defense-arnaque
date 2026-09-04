<?php
/**
 * blog-review.php
 * ---------------------------------------------------------------------
 * Interface de relecture humaine des brouillons d'articles generes
 * automatiquement par generate-blog-draft.php. Rien n'est jamais publie
 * sans passer par cette page.
 *
 * Acces protege par un jeton simple dans l'URL (meme logique que les
 * crons), pense pour etre mis en favori par le cabinet -- pas de
 * systeme de compte complet, volontairement, vu l'echelle du site.
 * ---------------------------------------------------------------------
 */

$secretFile = __DIR__ . '/blog-review-secret.php';
$SECRET_TOKEN = file_exists($secretFile) ? require $secretFile : null;
$providedToken = $_GET['token'] ?? ($_POST['token'] ?? '');
if (!$SECRET_TOKEN || !hash_equals($SECRET_TOKEN, (string)$providedToken)) {
    http_response_code(403);
    die("Accès refusé. Ajoutez ?token=VOTRE_JETON à l'URL.");
}

$DRAFTS_FILE  = __DIR__ . '/blog-drafts.json';
$BLOG_INDEX   = __DIR__ . '/ziegler-alertearnaque-blog.html';
$INDEX_HOME   = __DIR__ . '/index.html';
$MAIN_HOME    = __DIR__ . '/ziegler-alertearnaque.html';
$SITEMAP_FILE = __DIR__ . '/sitemap.xml';
$SITE_URL     = 'https://ziegler-alertearnaque.com';

function loadDrafts() {
    global $DRAFTS_FILE;
    if (!file_exists($DRAFTS_FILE)) return [];
    return json_decode(file_get_contents($DRAFTS_FILE), true) ?: [];
}
function saveDrafts($drafts) {
    global $DRAFTS_FILE;
    file_put_contents($DRAFTS_FILE, json_encode($drafts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}
function slugify($str) {
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
    if ($ascii !== false) $str = $ascii;
    $str = strtolower($str);
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    return trim($str, '-');
}
function escHtml($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---------- ACTIONS (publier / rejeter) ----------
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $draftId = $_POST['draft_id'] ?? '';
    $drafts = loadDrafts();
    $idx = null;
    foreach ($drafts as $i => $d) if ($d['id'] === $draftId) { $idx = $i; break; }

    if ($idx === null) {
        $message = "Brouillon introuvable.";
    } elseif ($action === 'rejeter') {
        $drafts[$idx]['statut'] = 'rejete';
        saveDrafts($drafts);
        $message = "Brouillon rejeté.";
    } elseif ($action === 'publier') {
        $draft = $drafts[$idx];
        $article = $draft['article'];
        $slug = 'ziegler-alertearnaque-blog-' . slugify($draft['plateforme']);
        $pageFile = __DIR__ . '/' . $slug . '.html';
        $pageUrl = $SITE_URL . '/' . $slug . '.html';

        if (!file_exists($BLOG_INDEX)) {
            $message = "Erreur : page d'index du blog introuvable, publication annulée.";
        } else {
            $indexContent = file_get_contents($BLOG_INDEX);

            // ---------- Extraction du gabarit a jour (en-tete + pied de page) ----------
            $heroStart = strpos($indexContent, '<section class="hero">');
            $mainEnd = strpos($indexContent, '</main>') + strlen('</main>');
            if ($heroStart === false || $mainEnd === false) {
                $message = "Erreur : structure de la page d'index inattendue, publication annulée.";
            } else {
                $headBoilerplate = substr($indexContent, 0, $heroStart);
                $footBoilerplate = substr($indexContent, $mainEnd);

                // Ajuste le titre/description/canonical/JSON-LD (bases sur la page blog) pour ce nouvel article
                $titre = $article['titre'];
                $metaDesc = $article['meta_description'];
                $fullTitle = $titre . ' | Ziegler Alerte Arnaque';

                $headBoilerplate = preg_replace('/<title>.*?<\/title>/s', '<title>' . escHtml($fullTitle) . '</title>', $headBoilerplate, 1);
                $headBoilerplate = preg_replace('/(<meta name="description" content=")[^"]*(")/', '${1}' . escHtml($metaDesc) . '${2}', $headBoilerplate, 1);
                $headBoilerplate = preg_replace('/(<meta property="og:title" content=")[^"]*(")/', '${1}' . escHtml($fullTitle) . '${2}', $headBoilerplate, 1);
                $headBoilerplate = preg_replace('/(<meta name="twitter:title" content=")[^"]*(")/', '${1}' . escHtml($fullTitle) . '${2}', $headBoilerplate, 1);
                $headBoilerplate = str_replace($SITE_URL . '/ziegler-alertearnaque-blog.html', $pageUrl, $headBoilerplate);

                // ---------- Construction du hero + contenu de l'article ----------
                $sectionsHtml = '';
                foreach ($article['sections'] as $section) {
                    $sectionsHtml .= '<h2>' . escHtml($section['titre']) . "</h2>\n";
                    foreach (preg_split('/\n\n+/', trim($section['contenu'])) as $para) {
                        $sectionsHtml .= '<p>' . nl2br(escHtml(trim($para))) . "</p>\n\n";
                    }
                }

                $dateAffichee = date('d/m/Y');
                $newHeroMain = '<section class="hero">' . "\n"
                    . '  <div class="wrap">' . "\n"
                    . '    <span class="eyebrow">Blog — ' . escHtml($draft['categorie']) . '</span>' . "\n"
                    . '    <h1>' . escHtml($titre) . '</h1>' . "\n"
                    . '    <p>' . escHtml($article['intro']) . '</p>' . "\n"
                    . '    <button type="button" id="quickCallbackLink" style="background:none; border:none; padding:0; margin-top:16px; color:var(--purple-light); font-size:13px; font-weight:600; cursor:pointer; text-decoration:underline; text-underline-offset:3px;">Besoin d\'être rappelé(e) rapidement, sans remplir de formulaire détaillé ? →</button>' . "\n"
                    . '  </div>' . "\n"
                    . '</section>' . "\n\n"
                    . '<main>' . "\n"
                    . '  <div class="wrap" style="max-width:900px;">' . "\n"
                    . '    <div class="intro-content">' . "\n"
                    . '      <p style="font-size:12px; color:var(--ink-soft-2);">Publié le ' . $dateAffichee . ' par le Cabinet d\'Avocats Ziegler &amp; Associés</p>' . "\n"
                    . $sectionsHtml
                    . '    </div>' . "\n"
                    . '    <div class="cta-band">' . "\n"
                    . '      <h2>Vous êtes concerné par une situation similaire ?</h2>' . "\n"
                    . '      <p>Décrivez votre situation au cabinet en toute confidentialité.</p>' . "\n"
                    . '      <a class="btn gold" href="/#signaler">Décrire ma situation →</a>' . "\n"
                    . '    </div>' . "\n"
                    . '  </div>' . "\n"
                    . '</main>';

                $newPage = $headBoilerplate . $newHeroMain . $footBoilerplate;
                file_put_contents($pageFile, $newPage);

                // ---------- Mise a jour de l'index du blog ----------
                $entryHtml = '        <a href="' . escHtml($slug) . '.html" style="display:block; padding:24px 0; border-bottom:1px solid var(--rule); text-decoration:none; color:inherit;">' . "\n"
                    . '          <div style="font-size:12px; color:var(--ink-soft-2); margin-bottom:6px;">' . $dateAffichee . '</div>' . "\n"
                    . '          <h2 style="margin:0 0 8px;">' . escHtml($titre) . '</h2>' . "\n"
                    . '          <p style="color:var(--ink-soft); margin:0;">' . escHtml($article['intro']) . '</p>' . "\n"
                    . '        </a>' . "\n";

                if (strpos($indexContent, 'Les premiers articles seront bientôt publiés ici.') !== false) {
                    $indexContent = str_replace(
                        '<p style="color:var(--ink-soft); padding:24px 0;">Les premiers articles seront bientôt publiés ici.</p>',
                        rtrim($entryHtml),
                        $indexContent
                    );
                } else {
                    $indexContent = preg_replace(
                        '/(<div id="blogPostList"[^>]*>)/',
                        '$1' . "\n" . $entryHtml,
                        $indexContent,
                        1
                    );
                }
                file_put_contents($BLOG_INDEX, $indexContent);
                if (file_exists($INDEX_HOME)) {
                    // index.html et ziegler-alertearnaque.html restent synchronises entre eux
                    // separement -- rien a faire ici, cette page n'affecte pas l'accueil.
                }

                // ---------- Mise a jour du sitemap ----------
                if (file_exists($SITEMAP_FILE)) {
                    $sitemap = file_get_contents($SITEMAP_FILE);
                    $newUrlEntry = "  <url>\n    <loc>" . $pageUrl . "</loc>\n    <lastmod>" . date('Y-m-d') . "</lastmod>\n    <changefreq>monthly</changefreq>\n    <priority>0.7</priority>\n  </url>\n</urlset>";
                    $sitemap = str_replace('</urlset>', $newUrlEntry, $sitemap);
                    file_put_contents($SITEMAP_FILE, $sitemap);
                }

                $drafts[$idx]['statut'] = 'publie';
                $drafts[$idx]['url_publiee'] = $pageUrl;
                saveDrafts($drafts);
                $message = "Article publié : <a href=\"$pageUrl\" target=\"_blank\">$pageUrl</a>";
            }
        }
    }
}

// ---------- AFFICHAGE ----------
$drafts = loadDrafts();
$enAttente = array_filter($drafts, fn($d) => $d['statut'] === 'en_attente');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Relecture des brouillons — Blog</title>
<style>
  body{font-family:-apple-system,'Inter',sans-serif; max-width:800px; margin:40px auto; padding:0 20px; color:#1D0D33; background:#F6F3FA;}
  h1{font-size:22px;}
  .msg{background:#F3F7EE; border-left:3px solid #3B6D11; padding:10px 14px; margin-bottom:20px; font-size:14px;}
  .draft{background:#fff; border-radius:12px; padding:24px; margin-bottom:20px; border:1px solid #eee;}
  .draft h2{margin-top:0; font-size:18px;}
  .draft .meta{font-size:12px; color:#767081; margin-bottom:14px;}
  .draft .verif{background:#FBF2F1; padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:16px;}
  .draft .article-body{font-size:14px; line-height:1.6;}
  .draft .article-body h3{font-size:15px; margin-bottom:4px;}
  .actions{margin-top:20px; display:flex; gap:10px;}
  button{padding:10px 18px; border-radius:8px; border:none; font-weight:600; cursor:pointer; font-size:13px;}
  .btn-publier{background:#8A4CB8; color:#fff;}
  .btn-rejeter{background:#fff; border:1px solid #9A382F; color:#9A382F;}
  .empty{color:#767081; font-style:italic;}
</style>
</head>
<body>
  <h1>Relecture des brouillons d'articles</h1>
  <?php if ($message): ?><div class="msg"><?= $message ?></div><?php endif; ?>

  <?php if (empty($enAttente)): ?>
    <p class="empty">Aucun brouillon en attente de relecture pour le moment.</p>
  <?php else: foreach ($enAttente as $d): $a = $d['article']; ?>
    <div class="draft">
      <div class="meta">Plateforme : <strong><?= escHtml($d['plateforme']) ?></strong> — <?= escHtml($d['categorie']) ?> — généré le <?= escHtml($d['genere_le']) ?></div>
      <div class="verif"><strong>Vérification préalable :</strong> <?= escHtml($d['verification_resume']) ?></div>
      <h2><?= escHtml($a['titre']) ?></h2>
      <div class="article-body">
        <p><em><?= escHtml($a['intro']) ?></em></p>
        <?php foreach ($a['sections'] as $s): ?>
          <h3><?= escHtml($s['titre']) ?></h3>
          <p><?= nl2br(escHtml($s['contenu'])) ?></p>
        <?php endforeach; ?>
      </div>
      <form method="post" class="actions">
        <input type="hidden" name="token" value="<?= escHtml($providedToken) ?>">
        <input type="hidden" name="draft_id" value="<?= escHtml($d['id']) ?>">
        <button type="submit" name="action" value="publier" class="btn-publier">Publier cet article</button>
        <button type="submit" name="action" value="rejeter" class="btn-rejeter">Rejeter</button>
      </form>
    </div>
  <?php endforeach; endif; ?>
</body>
</html>
