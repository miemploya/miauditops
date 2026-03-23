<?php
/**
 * MIAUDITOPS — Mail Diagnostic Test
 * Visit: https://miauditops.ng/mail_test.php
 * DELETE THIS FILE AFTER TESTING!
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>📧 MIAUDITOPS Mail Diagnostic</h2><pre style='background:#1e293b;color:#e2e8f0;padding:20px;border-radius:12px;font-size:13px;'>";

// 1. Check config
echo "═══ Step 1: Config Check ═══\n";
$config_path = __DIR__ . '/config/mail.php';
if (!file_exists($config_path)) { echo "❌ config/mail.php NOT FOUND\n"; die("</pre>"); }
echo "✅ config/mail.php found (" . filesize($config_path) . " bytes)\n";
require_once $config_path;
if (!defined('SMTP_HOST')) { echo "❌ SMTP_HOST not defined! Check file.\n"; die("</pre>"); }
echo "   HOST: " . SMTP_HOST . ":" . SMTP_PORT . " | FROM: " . SMTP_FROM_EMAIL . " | ENC: " . (SMTP_ENCRYPTION ?: 'none') . "\n\n";

// 2. PHPMailer check
echo "═══ Step 2: PHPMailer ═══\n";
require_once __DIR__ . '/vendor/PHPMailer/Exception.php';
require_once __DIR__ . '/vendor/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/vendor/PHPMailer/SMTP.php';
echo "✅ PHPMailer loaded\n\n";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 3. Send test
echo "═══ Step 3: Send Test ═══\n";
$mail = new PHPMailer(true);
$mail->SMTPDebug = 2;
$mail->Debugoutput = function($str, $level) { echo "   [SMTP] $str"; };

try {
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->Port = SMTP_PORT;
    $mail->Timeout = 15;

    if (SMTP_HOST === 'localhost' || SMTP_HOST === '127.0.0.1') {
        echo "→ LOCAL MTA (no auth)\n";
        $mail->SMTPAuth = false;
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
    } else {
        echo "→ EXTERNAL SMTP (with auth)\n";
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = !empty(SMTP_ENCRYPTION) ? SMTP_ENCRYPTION : false;
        if (empty(SMTP_ENCRYPTION)) $mail->SMTPAutoTLS = false;
    }

    $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress(SMTP_USERNAME, 'Test');
    $mail->isHTML(true);
    $mail->Subject = 'MIAUDITOPS Test — ' . date('H:i:s');
    $mail->Body = '<h2 style="color:#7c3aed;">✅ Mail Works!</h2><p>' . date('Y-m-d H:i:s') . '</p>';

    echo "→ Sending to " . SMTP_USERNAME . "...\n\n";
    $mail->send();
    echo "\n✅ ✅ ✅ EMAIL SENT! ✅ ✅ ✅\n";
} catch (Exception $e) {
    echo "\n❌ FAILED: " . $mail->ErrorInfo . "\n";
}

echo "\n═══ Server ═══\nPHP: " . PHP_VERSION . " | Host: " . ($_SERVER['HTTP_HOST'] ?? '?') . "\n";
echo "\n⚠️  DELETE THIS FILE AFTER TESTING!\n</pre>";
