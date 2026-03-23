<?php
/**
 * MIAUDITOPS — Mail Diagnostic Test
 * Visit: https://miauditops.ng/mail_test.php
 * DELETE THIS FILE AFTER TESTING!
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>📧 MIAUDITOPS Mail Diagnostic</h2><pre style='background:#1e293b;color:#e2e8f0;padding:20px;border-radius:12px;font-size:13px;'>";

// 1. Check mail.php config
echo "═══ Step 1: Config Check ═══\n";
if (!file_exists(__DIR__ . '/config/mail.php')) {
    echo "❌ config/mail.php NOT FOUND\n";
    die("</pre>");
}
require_once __DIR__ . '/config/mail.php';
echo "✅ config/mail.php loaded\n";
echo "   MAIL_HOST:       " . MAIL_HOST . "\n";
echo "   MAIL_PORT:       " . MAIL_PORT . "\n";
echo "   MAIL_USERNAME:   " . MAIL_USERNAME . "\n";
echo "   MAIL_FROM_EMAIL: " . MAIL_FROM_EMAIL . "\n";
echo "   MAIL_FROM_NAME:  " . MAIL_FROM_NAME . "\n";
echo "   MAIL_ENCRYPTION: " . (MAIL_ENCRYPTION ?: '(none)') . "\n";
echo "   MAIL_PASSWORD:   " . str_repeat('*', max(0, strlen(MAIL_PASSWORD) - 3)) . substr(MAIL_PASSWORD, -3) . "\n\n";

// 2. Check PHPMailer exists
echo "═══ Step 2: PHPMailer Check ═══\n";
$phpmailer_path = __DIR__ . '/vendor/PHPMailer/PHPMailer.php';
$smtp_path = __DIR__ . '/vendor/PHPMailer/SMTP.php';
$exception_path = __DIR__ . '/vendor/PHPMailer/Exception.php';

if (!file_exists($phpmailer_path)) { echo "❌ PHPMailer.php NOT FOUND at: $phpmailer_path\n"; die("</pre>"); }
if (!file_exists($smtp_path)) { echo "❌ SMTP.php NOT FOUND at: $smtp_path\n"; die("</pre>"); }
if (!file_exists($exception_path)) { echo "❌ Exception.php NOT FOUND at: $exception_path\n"; die("</pre>"); }
echo "✅ All PHPMailer files found\n\n";

require_once $exception_path;
require_once $phpmailer_path;
require_once $smtp_path;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 3. Check mail_helper.php
echo "═══ Step 3: mail_helper.php Check ═══\n";
$helper_path = __DIR__ . '/includes/mail_helper.php';
if (!file_exists($helper_path)) {
    echo "❌ includes/mail_helper.php NOT FOUND\n";
} else {
    $helper_content = file_get_contents($helper_path);
    if (strpos($helper_content, 'SMTPAutoTLS') !== false) {
        echo "✅ mail_helper.php has localhost detection (updated version)\n";
    } else {
        echo "⚠️  mail_helper.php does NOT have localhost detection (OLD version)\n";
        echo "   Run 'git pull' to get the latest version!\n";
    }
}
echo "\n";

// 4. Test SMTP connection
echo "═══ Step 4: SMTP Connection Test ═══\n";
$mail = new PHPMailer(true);
$mail->SMTPDebug = 2; // Verbose output
$mail->Debugoutput = function($str, $level) { echo "   [SMTP] $str"; };

try {
    $mail->isSMTP();
    $mail->Host = MAIL_HOST;
    $mail->Port = MAIL_PORT;
    $mail->Timeout = 15;

    if (MAIL_HOST === 'localhost' || MAIL_HOST === '127.0.0.1') {
        echo "→ Using LOCAL MTA (no auth)\n";
        $mail->SMTPAuth = false;
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
    } else {
        echo "→ Using EXTERNAL SMTP (with auth)\n";
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_PASSWORD;
        if (!empty(MAIL_ENCRYPTION)) {
            $mail->SMTPSecure = MAIL_ENCRYPTION;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }
    }

    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ];

    $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
    $mail->addAddress(MAIL_USERNAME, 'Test Recipient');

    $mail->isHTML(true);
    $mail->Subject = 'MIAUDITOPS Mail Test — ' . date('H:i:s');
    $mail->Body = '<div style="font-family:Arial;padding:20px;"><h2 style="color:#7c3aed;">✅ Mail Test Successful!</h2><p>This is a test email from MIAUDITOPS at ' . date('Y-m-d H:i:s') . '</p><p>Server: ' . ($_SERVER['HTTP_HOST'] ?? 'unknown') . '</p></div>';
    $mail->AltBody = 'MIAUDITOPS Mail Test — ' . date('Y-m-d H:i:s');

    echo "\n→ Sending test email to " . MAIL_USERNAME . "...\n\n";
    $mail->send();
    echo "\n✅ ✅ ✅ EMAIL SENT SUCCESSFULLY! ✅ ✅ ✅\n";
    echo "Check inbox of " . MAIL_USERNAME . "\n";

} catch (Exception $e) {
    echo "\n❌ SEND FAILED: " . $mail->ErrorInfo . "\n";
}

// 5. Alternative: test PHP mail() function
echo "\n═══ Step 5: PHP mail() Function Test ═══\n";
$php_mail_result = @mail(
    MAIL_USERNAME,
    'MIAUDITOPS mail() Test — ' . date('H:i:s'),
    'Test from PHP mail() at ' . date('Y-m-d H:i:s'),
    'From: ' . MAIL_FROM_EMAIL . "\r\n" . 'Reply-To: ' . MAIL_FROM_EMAIL
);
echo $php_mail_result ? "✅ PHP mail() returned true (queued)\n" : "❌ PHP mail() returned false\n";

// 6. Server info
echo "\n═══ Step 6: Server Info ═══\n";
echo "PHP Version:  " . PHP_VERSION . "\n";
echo "Server:       " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n";
echo "Hostname:     " . ($_SERVER['HTTP_HOST'] ?? 'unknown') . "\n";
echo "OpenSSL:      " . (extension_loaded('openssl') ? 'YES (' . OPENSSL_VERSION_TEXT . ')' : 'NO') . "\n";
echo "Sockets:      " . (extension_loaded('sockets') ? 'YES' : 'NO') . "\n";

echo "\n⚠️  DELETE THIS FILE AFTER TESTING!\n";
echo "</pre>";
