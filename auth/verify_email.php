<?php
/**
 * MIAUDITOPS — Email Verification Handler
 * 
 * Validates the verification token from the email link,
 * marks the user as verified, and shows a success/error page.
 */
session_start();

require_once '../config/db.php';

$status = 'invalid'; // invalid | expired | success | already
$error = '';
$company_code = '';

$token = trim($_GET['token'] ?? '');

if (!empty($token) && strlen($token) === 64) {
    try {
        // Find user with this token
        $stmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.email, u.email_verified_at, u.created_at, c.code as company_code
            FROM users u
            JOIN companies c ON c.id = u.company_id
            WHERE u.verification_token = ? AND u.deleted_at IS NULL
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $status = 'invalid';
        } elseif ($user['email_verified_at'] !== null) {
            $status = 'already';
            $company_code = $user['company_code'];
        } else {
            // Check expiry — 24 hours from registration
            $created = strtotime($user['created_at']);
            if (time() - $created > 86400) {
                $status = 'expired';
            } else {
                // Verify the user
                $pdo->prepare("UPDATE users SET email_verified_at = NOW(), verification_token = NULL WHERE id = ?")->execute([$user['id']]);
                $status = 'success';
                $company_code = $user['company_code'];
            }
        }
    } catch (Exception $e) {
        $status = 'invalid';
    }
}

// Handle resend request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_email'])) {
    $resend_email = trim($_POST['resend_email']);
    if (!empty($resend_email)) {
        try {
            require_once '../includes/functions.php';
            require_once '../includes/mail_helper.php';
            
            $stmt = $pdo->prepare("
                SELECT u.id, u.first_name, u.email_verified_at, c.code as company_code
                FROM users u
                JOIN companies c ON c.id = u.company_id
                WHERE u.email = ? AND u.deleted_at IS NULL AND u.email_verified_at IS NULL
                ORDER BY u.created_at DESC LIMIT 1
            ");
            $stmt->execute([$resend_email]);
            $user = $stmt->fetch();
            
            if ($user) {
                $new_token = bin2hex(random_bytes(32));
                $pdo->prepare("UPDATE users SET verification_token = ?, created_at = NOW() WHERE id = ?")->execute([$new_token, $user['id']]);
                
                $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
                $verify_link = $base_url . dirname($_SERVER['SCRIPT_NAME']) . '/verify_email.php?token=' . $new_token;
                $verify_link = preg_replace('#(?<!:)//+#', '/', $verify_link);
                $verify_link = str_replace(':/', '://', $verify_link);
                
                send_verification_email($resend_email, $user['first_name'], $verify_link, $user['company_code']);
                $status = 'resent';
            } else {
                $status = 'resend_fail';
            }
        } catch (Exception $e) {
            $status = 'resend_fail';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email — MIAUDITOPS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','sans-serif']}}}}</script>
    <style>
        @keyframes float { 0%,100% { transform: translateY(0px); } 50% { transform: translateY(-10px); } }
        .float-anim { animation: float 6s ease-in-out infinite; }
        @keyframes check-draw { to { stroke-dashoffset: 0; } }
        .check-animate path { stroke-dasharray: 50; stroke-dashoffset: 50; animation: check-draw 0.6s ease-out 0.3s forwards; }
    </style>
</head>
<body class="font-sans bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-white min-h-screen flex items-center justify-center relative overflow-hidden">
    <!-- Background decorations -->
    <div class="absolute top-20 left-10 w-72 h-72 bg-violet-300/20 rounded-full blur-3xl float-anim"></div>
    <div class="absolute bottom-20 right-10 w-96 h-96 bg-emerald-300/10 rounded-full blur-3xl float-anim" style="animation-delay:3s"></div>

    <div class="relative z-10 w-full max-w-md mx-4">
        <div class="text-center mb-4">
            <img src="../assets/images/logo.png" alt="MiAuditOps" class="h-12 mx-auto mb-1" style="mix-blend-mode:screen">
        </div>

        <div class="bg-white/80 dark:bg-white/5 backdrop-blur-xl rounded-2xl border border-slate-200 dark:border-white/10 shadow-2xl p-8 text-center">

            <?php if ($status === 'success'): ?>
                <!-- SUCCESS -->
                <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-emerald-400 to-green-600 flex items-center justify-center shadow-lg shadow-emerald-500/30">
                    <svg class="w-8 h-8 text-white check-animate" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-2">Email Verified!</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Your email has been verified successfully. You can now sign in to your account.</p>
                <?php if ($company_code): ?>
                    <div class="mb-4 p-3 rounded-xl bg-violet-50 dark:bg-violet-900/20 border border-violet-200 dark:border-violet-800">
                        <p class="text-[10px] font-bold text-violet-400 uppercase mb-1">Your Company Code</p>
                        <p class="text-2xl font-black text-violet-700 dark:text-violet-300 tracking-widest"><?= htmlspecialchars($company_code) ?></p>
                        <p class="text-[10px] text-slate-400 mt-1">Save this — you'll need it to sign in</p>
                    </div>
                <?php endif; ?>
                <a href="login.php" class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-emerald-500 to-green-600 text-white text-sm font-bold rounded-xl shadow-lg shadow-emerald-500/30 hover:scale-105 transition-all">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                    Go to Login
                </a>

            <?php elseif ($status === 'already'): ?>
                <!-- ALREADY VERIFIED -->
                <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-blue-400 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-500/30">
                    <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-2">Already Verified</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">This email address has already been verified. You can sign in now.</p>
                <a href="login.php" class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-violet-500 to-purple-600 text-white text-sm font-bold rounded-xl shadow-lg hover:scale-105 transition-all">
                    Go to Login →
                </a>

            <?php elseif ($status === 'expired'): ?>
                <!-- EXPIRED -->
                <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-amber-400 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/30">
                    <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-2">Link Expired</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">This verification link has expired (24-hour limit). Enter your email below to receive a new one.</p>
                <form method="POST" class="space-y-3">
                    <input type="email" name="resend_email" placeholder="Enter your email" required class="w-full px-4 py-3 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500 transition-all">
                    <button type="submit" class="w-full px-4 py-3 bg-gradient-to-r from-violet-500 to-purple-600 text-white text-sm font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all">
                        Resend Verification Email
                    </button>
                </form>

            <?php elseif ($status === 'resent'): ?>
                <!-- RESENT -->
                <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-violet-400 to-purple-600 flex items-center justify-center shadow-lg shadow-violet-500/30">
                    <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </div>
                <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-2">Verification Email Sent!</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">A new verification link has been sent to your email. Please check your inbox (and spam folder).</p>
                <a href="login.php" class="text-sm text-violet-500 hover:text-violet-400 font-semibold transition-all">← Back to Login</a>

            <?php else: ?>
                <!-- INVALID -->
                <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-red-400 to-rose-600 flex items-center justify-center shadow-lg shadow-red-500/30">
                    <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                </div>
                <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-2">Invalid Link</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">This verification link is invalid or has already been used. Enter your email below to get a new one.</p>
                <form method="POST" class="space-y-3">
                    <input type="email" name="resend_email" placeholder="Enter your email" required class="w-full px-4 py-3 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500 transition-all">
                    <button type="submit" class="w-full px-4 py-3 bg-gradient-to-r from-violet-500 to-purple-600 text-white text-sm font-bold rounded-xl shadow-lg hover:scale-[1.02] transition-all">
                        Resend Verification Email
                    </button>
                </form>
                <a href="login.php" class="inline-block mt-3 text-sm text-violet-500 hover:text-violet-400 font-semibold transition-all">← Back to Login</a>
            <?php endif; ?>

        </div>
    </div>
</body>
</html>
