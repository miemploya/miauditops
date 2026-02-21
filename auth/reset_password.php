<?php
/**
 * MIAUDITOPS — Reset Password
 * User arrives here from the reset link with ?token=xxx
 */
session_start();
if (isset($_SESSION['user_id'])) { header('Location: ../dashboard/index.php'); exit; }

require_once '../config/db.php';

$error = '';
$success = '';
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$valid_token = false;

if (empty($token)) {
    $error = 'Invalid or missing reset token.';
} else {
    // Validate token
    $stmt = $pdo->prepare("SELECT * FROM password_reset_tokens WHERE token = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reset) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    } else {
        $valid_token = true;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password'] ?? '';
            $confirm  = $_POST['confirm_password'] ?? '';

            if (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters.';
            } elseif ($password !== $confirm) {
                $error = 'Passwords do not match.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $reset['user_id']]);
                $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?")->execute([$reset['id']]);
                $success = 'Your password has been reset successfully! You can now sign in with your new password.';
                $valid_token = false; // Hide the form
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — MIAUDITOPS</title>
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

    <button class="theme-toggle-btn fixed top-4 right-4 z-50 w-9 h-9 rounded-xl bg-white/80 dark:bg-white/10 border border-slate-200 dark:border-white/10 flex items-center justify-center hover:bg-slate-100 dark:hover:bg-white/20 transition-all shadow-lg backdrop-blur-sm" title="Toggle theme">
        <svg class="icon-sun w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
        <svg class="icon-moon w-5 h-5 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:none"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
    </button>

    <div class="absolute top-20 left-20 w-72 h-72 bg-emerald-300/15 dark:bg-emerald-600/20 rounded-full blur-3xl float-anim"></div>
    <div class="absolute bottom-20 right-20 w-96 h-96 bg-violet-300/15 dark:bg-violet-600/15 rounded-full blur-3xl float-anim-delay"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-blue-300/10 dark:bg-blue-600/10 rounded-full blur-3xl pulse-glow"></div>

    <div class="relative z-10 w-full max-w-md mx-4">
        <div class="text-center mb-3">
            <img src="../assets/images/logo.png" alt="MiAuditOps" class="h-12 mx-auto mb-1" style="mix-blend-mode:screen">
            <p class="text-xs text-slate-500 dark:text-slate-400">Set New Password</p>
        </div>

        <div class="bg-white/80 dark:bg-white/5 backdrop-blur-xl rounded-2xl border border-slate-200 dark:border-white/10 shadow-2xl p-5">
            <h2 class="text-base font-bold text-slate-800 dark:text-white mb-0.5">Reset Password</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">Create a new secure password</p>

            <?php if ($error): ?>
                <div class="mb-4 p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-500 dark:text-red-400 text-sm flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="mb-4 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 dark:text-emerald-400 text-sm">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span class="font-bold">Password Reset!</span>
                    </div>
                    <p><?php echo $success; ?></p>
                    <a href="login.php" class="inline-block mt-3 px-4 py-2 bg-emerald-500/20 border border-emerald-500/30 rounded-lg text-emerald-400 text-sm font-semibold hover:bg-emerald-500/30 transition-all">
                        Go to Login →
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($valid_token): ?>
                <form method="POST" class="space-y-3">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-0.5">New Password</label>
                        <div class="relative">
                            <input type="password" name="password" id="new-password" placeholder="Min. 6 characters" required
                                   class="w-full px-3 py-1.5 bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-lg text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-violet-500 focus:ring-1 focus:ring-violet-500/20 transition-all text-sm pr-8">
                            <button type="button" onclick="togglePwd('new-password',this)" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-0.5">Confirm Password</label>
                        <div class="relative">
                            <input type="password" name="confirm_password" id="confirm-password" placeholder="Re-enter password" required
                                   class="w-full px-3 py-1.5 bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-lg text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-violet-500 focus:ring-1 focus:ring-violet-500/20 transition-all text-sm pr-8">
                            <button type="button" onclick="togglePwd('confirm-password',this)" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>
                    </div>
                    <button type="submit"
                            class="w-full py-2 bg-gradient-to-r from-violet-600 to-purple-600 text-white font-bold rounded-xl shadow-lg shadow-violet-500/30 hover:shadow-violet-500/50 hover:scale-[1.02] transition-all duration-200 text-sm">
                        Reset Password
                    </button>
                </form>
            <?php endif; ?>

            <?php if (!$valid_token && !$success): ?>
                <p class="text-center text-sm text-slate-500 dark:text-slate-400 mt-2">
                    <a href="forgot_password.php" class="text-violet-600 dark:text-violet-400 hover:text-violet-500 font-semibold">Request a new reset link</a>
                </p>
            <?php endif; ?>
        </div>

        <a href="login.php" class="block text-center text-xs text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 mt-4 transition-colors">← Back to Login</a>
        <p class="text-center text-xs text-slate-400 dark:text-slate-500 mt-2">&copy; <?php echo date('Y'); ?> Miemploya. All rights reserved.</p>
    </div>

<script>
function togglePwd(id, btn) {
    const input = document.getElementById(id);
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    btn.innerHTML = isPassword
        ? '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>'
        : '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
}
</script>
</body>
</html>
