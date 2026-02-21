<?php
/**
 * MIAUDITOPS — Forgot Password
 * User enters email + company code → receives a reset link via email.
 */
session_start();
if (isset($_SESSION['user_id'])) { header('Location: ../dashboard/index.php'); exit; }

require_once '../config/db.php';
require_once '../includes/mail_helper.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $company_code = strtoupper(trim($_POST['company_code'] ?? ''));

    if (empty($email) || empty($company_code)) {
        $error = 'Email and company code are required.';
    } else {
        // Find the user
        $stmt = $pdo->prepare("
            SELECT u.id, u.first_name, c.id as company_id
            FROM users u
            JOIN companies c ON c.id = u.company_id
            WHERE u.email = ? AND c.code = ? AND u.is_active = 1 AND u.deleted_at IS NULL AND c.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$email, $company_code]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Invalidate old tokens for this user
            $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL")->execute([$user['id']]);

            // Generate new token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $pdo->prepare("INSERT INTO password_reset_tokens (user_id, company_id, token, expires_at) VALUES (?,?,?,?)")
                ->execute([$user['id'], $user['company_id'], $token, $expires]);

            // Build the reset link
            $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST']
                . str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
            $reset_link = $base . '/reset_password.php?token=' . $token;

            // Send the reset email
            $mail_result = send_password_reset_email($email, $user['first_name'], $reset_link);
            
            if ($mail_result['success']) {
                $success = 'A password reset link has been sent to <strong>' . htmlspecialchars($email) . '</strong>. Please check your inbox (and spam folder). The link expires in 1 hour.';
            } else {
                $error = 'We could not send the reset email. Please try again or contact your administrator.';
            }
        } else {
            // Show generic success to prevent email enumeration
            $success = 'If an account with that email and company code exists, a reset link has been sent.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — MIAUDITOPS</title>
    <meta name="description" content="Reset your MIAUDITOPS password">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="../assets/js/theme-toggle.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        }
    </script>
    <style>
        @keyframes float { 0%,100% { transform: translateY(0px); } 50% { transform: translateY(-20px); } }
        @keyframes pulse-glow { 0%,100% { opacity: 0.4; } 50% { opacity: 0.8; } }
        .float-anim { animation: float 6s ease-in-out infinite; }
        .float-anim-delay { animation: float 8s ease-in-out 2s infinite; }
        .pulse-glow { animation: pulse-glow 4s ease-in-out infinite; }
    </style>
</head>
<body class="font-sans bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-white min-h-screen flex items-center justify-center relative overflow-hidden transition-colors duration-300">

    <!-- Theme Toggle -->
    <button class="theme-toggle-btn fixed top-4 right-4 z-50 w-9 h-9 rounded-xl bg-white/80 dark:bg-white/10 border border-slate-200 dark:border-white/10 flex items-center justify-center hover:bg-slate-100 dark:hover:bg-white/20 transition-all shadow-lg backdrop-blur-sm" title="Toggle theme">
        <svg class="icon-sun w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
        <svg class="icon-moon w-5 h-5 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:none"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
    </button>

    <!-- Background -->
    <div class="absolute top-20 left-20 w-72 h-72 bg-violet-300/15 dark:bg-violet-600/20 rounded-full blur-3xl float-anim"></div>
    <div class="absolute bottom-20 right-20 w-96 h-96 bg-blue-300/15 dark:bg-blue-600/15 rounded-full blur-3xl float-anim-delay"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-purple-300/10 dark:bg-purple-600/10 rounded-full blur-3xl pulse-glow"></div>

    <div class="relative z-10 w-full max-w-md mx-4">
        <div class="text-center mb-3">
            <img src="../assets/images/logo.png" alt="MiAuditOps" class="h-12 mx-auto mb-1" style="mix-blend-mode:screen">
            <p class="text-xs text-slate-500 dark:text-slate-400">Password Recovery</p>
        </div>

        <div class="bg-white/80 dark:bg-white/5 backdrop-blur-xl rounded-2xl border border-slate-200 dark:border-white/10 shadow-2xl p-5">
            <h2 class="text-base font-bold text-slate-800 dark:text-white mb-0.5">Forgot Password?</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">Enter your details to get a reset link</p>

            <?php if ($error): ?>
                <div class="mb-4 p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-500 dark:text-red-400 text-sm flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="mb-4 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 dark:text-emerald-400 text-sm">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        <span class="font-bold">Check Your Email</span>
                    </div>
                    <p><?php echo $success; ?></p>
                </div>
            <?php else: ?>
                <form method="POST" class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-0.5">Company Code</label>
                        <input type="text" name="company_code" placeholder="e.g. ACME123" required
                               value="<?php echo htmlspecialchars($_POST['company_code'] ?? ''); ?>"
                               class="w-full px-3 py-1.5 bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-lg text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-violet-500 focus:ring-1 focus:ring-violet-500/20 transition-all uppercase tracking-wider text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-0.5">Email Address</label>
                        <input type="email" name="email" placeholder="your@email.com" required
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                               class="w-full px-3 py-1.5 bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-lg text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-violet-500 focus:ring-1 focus:ring-violet-500/20 transition-all text-sm">
                    </div>
                    <button type="submit"
                            class="w-full py-2 bg-gradient-to-r from-violet-600 to-purple-600 text-white font-bold rounded-xl shadow-lg shadow-violet-500/30 hover:shadow-violet-500/50 hover:scale-[1.02] transition-all duration-200 text-sm">
                        Send Reset Link
                    </button>
                </form>
            <?php endif; ?>

            <p class="text-center text-sm text-slate-500 dark:text-slate-400 mt-4">
                Remember your password? <a href="login.php" class="text-violet-600 dark:text-violet-400 hover:text-violet-500 dark:hover:text-violet-300 font-semibold">Sign In</a>
            </p>
        </div>

        <a href="../index.php" class="block text-center text-xs text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 mt-4 transition-colors">← Back to Home</a>
        <p class="text-center text-xs text-slate-400 dark:text-slate-500 mt-2">&copy; <?php echo date('Y'); ?> Miemploya. All rights reserved.</p>
    </div>
</body>
</html>
