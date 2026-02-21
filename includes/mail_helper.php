<?php
/**
 * MIAUDITOPS — Mail Helper
 * Sends emails via Gmail SMTP using PHPMailer
 */
require_once dirname(__DIR__) . '/config/mail.php';
require_once dirname(__DIR__) . '/vendor/PHPMailer/Exception.php';
require_once dirname(__DIR__) . '/vendor/PHPMailer/PHPMailer.php';
require_once dirname(__DIR__) . '/vendor/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an email via Gmail SMTP
 * 
 * @param string $to_email    Recipient email
 * @param string $to_name     Recipient name
 * @param string $subject     Email subject
 * @param string $html_body   HTML body
 * @param string $text_body   Plain-text fallback (optional)
 * @return array ['success' => bool, 'message' => string]
 */
function send_mail($to_email, $to_name, $subject, $html_body, $text_body = '') {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port       = MAIL_PORT;
        
        // Sender & Recipient
        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($to_email, $to_name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_body;
        $mail->Body    = $html_body;
        $mail->AltBody = $text_body ?: strip_tags($html_body);
        
        $mail->send();
        return ['success' => true, 'message' => 'Email sent successfully'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Mail error: ' . $mail->ErrorInfo];
    }
}

/**
 * Send a password reset email
 * 
 * @param string $email      User's email
 * @param string $name       User's name
 * @param string $reset_link Full URL to reset password
 * @return array ['success' => bool, 'message' => string]
 */
function send_password_reset_email($email, $name, $reset_link) {
    $subject = 'Reset Your MIAUDITOPS Password';
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
    </head>
    <body style="margin:0; padding:0; background-color:#f8fafc; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">
        <div style="max-width:520px; margin:40px auto; padding:0;">
            <!-- Header -->
            <div style="background: linear-gradient(135deg, #7c3aed, #6d28d9); border-radius:16px 16px 0 0; padding:32px; text-align:center;">
                <div style="width:48px; height:48px; background:rgba(255,255,255,0.2); border-radius:12px; display:inline-flex; align-items:center; justify-content:center; margin-bottom:12px;">
                    <span style="font-size:24px; color:#fff;">🛡️</span>
                </div>
                <h1 style="color:#fff; font-size:22px; font-weight:800; margin:0; letter-spacing:-0.5px;">MIAUDITOPS</h1>
            </div>
            
            <!-- Body -->
            <div style="background:#fff; padding:32px; border-left:1px solid #e2e8f0; border-right:1px solid #e2e8f0;">
                <h2 style="color:#1e293b; font-size:20px; font-weight:700; margin:0 0 8px;">Password Reset Request</h2>
                <p style="color:#64748b; font-size:14px; line-height:1.6; margin:0 0 24px;">
                    Hi <strong style="color:#334155;">' . htmlspecialchars($name) . '</strong>, we received a request to reset your password. Click the button below to create a new one.
                </p>
                
                <!-- Button -->
                <div style="text-align:center; margin:24px 0;">
                    <a href="' . htmlspecialchars($reset_link) . '" style="display:inline-block; padding:14px 32px; background:linear-gradient(135deg, #7c3aed, #6d28d9); color:#fff; font-size:14px; font-weight:700; text-decoration:none; border-radius:12px; box-shadow:0 4px 14px rgba(124,58,237,0.3);">
                        Reset My Password
                    </a>
                </div>
                
                <p style="color:#94a3b8; font-size:12px; line-height:1.6; margin:24px 0 0;">
                    This link expires in <strong>1 hour</strong>. If you didn\'t request this, you can safely ignore this email — your password won\'t change.
                </p>
                
                <!-- Link fallback -->
                <div style="margin-top:20px; padding:12px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
                    <p style="color:#94a3b8; font-size:11px; margin:0 0 4px;">If the button doesn\'t work, copy this link:</p>
                    <p style="color:#6d28d9; font-size:11px; word-break:break-all; margin:0;">' . htmlspecialchars($reset_link) . '</p>
                </div>
            </div>
            
            <!-- Footer -->
            <div style="background:#f8fafc; padding:20px 32px; border-radius:0 0 16px 16px; border:1px solid #e2e8f0; border-top:none; text-align:center;">
                <p style="color:#94a3b8; font-size:11px; margin:0;">
                    &copy; ' . date('Y') . ' MIAUDITOPS by Miemploya. All rights reserved.
                </p>
            </div>
        </div>
    </body>
    </html>';
    
    $text = "Hi $name,\n\nWe received a request to reset your MIAUDITOPS password.\n\nClick here to reset: $reset_link\n\nThis link expires in 1 hour. If you didn't request this, ignore this email.\n\n— MIAUDITOPS by Miemploya";
    
    return send_mail($email, $name, $subject, $html, $text);
}
