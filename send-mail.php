<?php
/**
 * send-mail.php
 * ---------------------------------------------------------------------
 * Remplace FormSubmit.co : envoie directement les emails du site (formulaire
 * de signalement, newsletter, chatbot, popup de sortie) via le serveur SMTP
 * du cabinet, sans qu'aucun service tiers ne voie passer les données.
 *
 * Accepte exactement le même format JSON que celui déjà utilisé côté client
 * (un champ `_subject` pour l'objet du message, puis des paires clé/valeur
 * arbitraires affichées dans le corps de l'email). Les champs commençant
 * par un underscore sont des instructions de contrôle, jamais affichées.
 * ---------------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://ziegler-alertearnaque.com');

require __DIR__ . '/lib/PHPMailer/Exception.php';
require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// ---------- Garde-fous contre l'abus ----------
if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 65536) {
    http_response_code(413);
    echo json_encode(['error' => 'Charge utile trop volumineuse']);
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 65536);
$data = json_decode($raw, true);

if (!is_array($data) || empty($data['_subject'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Requête invalide : sujet manquant']);
    exit;
}
if (count($data) > 40) {
    http_response_code(400);
    echo json_encode(['error' => 'Trop de champs dans la requête']);
    exit;
}

// ---------- Configuration SMTP ----------
$config = require __DIR__ . '/mail-config.php';

// ---------- Construction du corps de l'email (tableau HTML simple) ----------
function escapeForEmail($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

$rows = '';
foreach ($data as $key => $value) {
    if ($key === '' || $key[0] === '_') continue; // champs de controle, jamais affiches
    if ($value === '' || $value === null) continue; // champs vides, pas la peine de les afficher
    $rows .= '<tr><td style="padding:6px 12px; border:1px solid #ddd; font-weight:bold; background:#f6f3fa;">'
        . escapeForEmail($key) . '</td><td style="padding:6px 12px; border:1px solid #ddd;">'
        . nl2br(escapeForEmail($value)) . '</td></tr>';
}

$subject = (string)$data['_subject'];
$htmlBody = '<html><body style="font-family:sans-serif; font-size:14px; color:#1A1330;">'
    . '<h2 style="color:#8A4CB8;">' . escapeForEmail($subject) . '</h2>'
    . '<table style="border-collapse:collapse; width:100%; max-width:640px;">' . $rows . '</table>'
    . '<p style="color:#8B8699; font-size:12px; margin-top:20px;">Envoyé automatiquement depuis ziegler-alertearnaque.com</p>'
    . '</body></html>';

// ---------- Envoi via SMTP ----------
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = $config['smtp_host'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['smtp_user'];
    $mail->Password = $config['smtp_pass'];
    $mail->SMTPSecure = $config['smtp_secure'] === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = $config['smtp_port'];
    $mail->CharSet = 'UTF-8';

    $mail->setFrom($config['from_email'], $config['from_name']);
    $mail->addAddress($config['to_email'], $config['to_name']);

    // Permet une reponse directe a l'expediteur si son email a ete fourni,
    // sans jamais l'utiliser comme adresse d'envoi (protection anti-usurpation).
    if (!empty($data['Email']) && filter_var($data['Email'], FILTER_VALIDATE_EMAIL)) {
        $mail->addReplyTo($data['Email']);
    }

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    $mail->AltBody = strip_tags(str_replace('</tr>', "\n", $rows));

    $mail->send();
    echo json_encode(['success' => true]);
} catch (PHPMailerException $e) {
    http_response_code(502);
    error_log('send-mail.php: echec envoi SMTP - ' . $mail->ErrorInfo);
    echo json_encode(['error' => "Échec de l'envoi", 'success' => false]);
}
