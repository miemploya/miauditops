<?php
/**
 * MIAUDITOPS — Login Page
 */
session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ../dashboard/index.php');
    exit;
}

require_once '../config/db.php';
require_once '../config/google_config.php';

$error = '';
$show_resend = false;
$resend_email = '';

// Handle resend verification email request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_verification') {
    $resend_email = trim($_POST['resend_email'] ?? '');
    if (!empty($resend_email)) {
        try {
            require_once '../includes/functions.php';
            require_once '../includes/mail_helper.php';
            
            $stmt = $pdo->prepare("
                SELECT u.id, u.first_name, c.code as company_code
                FROM users u
                JOIN companies c ON c.id = u.company_id
                WHERE u.email = ? AND u.deleted_at IS NULL AND u.email_verified_at IS NULL
                ORDER BY u.created_at DESC LIMIT 1
            ");
            $stmt->execute([$resend_email]);
            $unverified = $stmt->fetch();
            
            if ($unverified) {
                $new_token = bin2hex(random_bytes(32));
                $pdo->prepare("UPDATE users SET verification_token = ? WHERE id = ?")->execute([$new_token, $unverified['id']]);
                
                $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
                $verify_link = $base_url . dirname($_SERVER['SCRIPT_NAME']) . '/verify_email.php?token=' . $new_token;
                $verify_link = preg_replace('#(?<!:)//+#', '/', $verify_link);
                $verify_link = str_replace(':/', '://', $verify_link);
                
                send_verification_email($resend_email, $unverified['first_name'], $verify_link, $unverified['company_code']);
            }
            // Always show success (don't reveal if email exists)
            $error = '<span class="text-emerald-400">✓ Verification email sent! Check your inbox (and spam folder).</span>';
        } catch (Exception $e) {
            $error = 'Failed to resend verification email. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'resend_verification')) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $company_code = strtoupper(trim($_POST['company_code'] ?? ''));
    
    if (empty($email) || empty($password)) {
        $error = 'Email and password are required.';
    } else {
        try {
            if (!empty($company_code)) {
                // ── Path A: Company code provided — original flow ──
                $stmt = $pdo->prepare("SELECT id FROM companies WHERE code = ? AND is_active = 1 AND deleted_at IS NULL");
                $stmt->execute([$company_code]);
                $company = $stmt->fetch();
                
                if (!$company) {
                    $error = 'Invalid company code.';
                } else {
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND company_id = ? AND is_active = 1 AND deleted_at IS NULL");
                    $stmt->execute([$email, $company['id']]);
                    $user = $stmt->fetch();
                    
                    if ($user && password_verify($password, $user['password'])) {
                        // Only require verification for self-registered users (those with a pending verification token)
                        if (empty($user['google_id']) && empty($user['email_verified_at']) && !empty($user['verification_token'])) {
                            $error = 'Please verify your email before signing in.';
                            $show_resend = true;
                            $resend_email = $email;
                        } else {
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['company_id'] = $user['company_id'];
                            $_SESSION['user_role'] = $user['role'];
                            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
                            header('Location: ../dashboard/index.php');
                            exit;
                        }
                    } else {
                        $error = 'Invalid email or password.';
                    }
                }
            } else {
                // ── Path B: No company code — auto-detect from email ──
                $stmt = $pdo->prepare(
                    "SELECT u.*, c.code as company_code, c.name as company_name
                     FROM users u
                     JOIN companies c ON c.id = u.company_id AND c.is_active = 1 AND c.deleted_at IS NULL
                     WHERE u.email = ? AND u.is_active = 1 AND u.deleted_at IS NULL"
                );
                $stmt->execute([$email]);
                $matches = $stmt->fetchAll();

                if (count($matches) === 0) {
                    $error = 'Invalid email or password.';
                } elseif (count($matches) === 1) {
                    $user = $matches[0];
                    if (password_verify($password, $user['password'])) {
                        // Only require verification for self-registered users (those with a pending verification token)
                        if (empty($user['google_id']) && empty($user['email_verified_at']) && !empty($user['verification_token'])) {
                            $error = 'Please verify your email before signing in.';
                            $show_resend = true;
                            $resend_email = $email;
                        } else {
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['company_id'] = $user['company_id'];
                            $_SESSION['user_role'] = $user['role'];
                            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
                            header('Location: ../dashboard/index.php');
                            exit;
                        }
                    } else {
                        $error = 'Invalid email or password.';
                    }
                } else {
                    // Multiple companies — need the code to disambiguate
                    $error = 'Your email is linked to multiple companies. Please enter your Company Code to sign in.';
                }
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — MIAUDITOPS</title>
    <meta name="description" content="Sign in to MIAUDITOPS — Operational Intelligence & Financial Control System">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script src="../assets/js/theme-toggle.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        brand: { 50:'#f5f3ff',100:'#ede9fe',200:'#ddd6fe',300:'#c4b5fd',400:'#a78bfa',500:'#8b5cf6',600:'#7c3aed',700:'#6d28d9',800:'#5b21b6',900:'#4c1d95',950:'#2e1065' }
                    }
                }
            }
        }
    </script>
    <style>
        @keyframes float { 0%,100% { transform: translateY(0px); } 50% { transform: translateY(-20px); } }
        @keyframes pulse-glow { 0%,100% { opacity: 0.4; } 50% { opacity: 0.8; } }
        .float-anim { animation: float 6s ease-in-out infinite; }
        .float-anim-delay { animation: float 8s ease-in-out 2s infinite; }
        .pulse-glow { animation: pulse-glow 4s ease-in-out infinite; }

        /* Diagonal floating Miemploya text */
        @keyframes floatDiag {
            0%   { transform: translate(0, -100%) rotate(-25deg); opacity: 0; }
            10%  { opacity: 1; }
            90%  { opacity: 1; }
            100% { transform: translate(-30vw, 120vh) rotate(-25deg); opacity: 0; }
        }
        .float-text-container {
            position: fixed; inset: 0; overflow: hidden; pointer-events: none; z-index: 0;
        }
        .float-text {
            position: absolute;
            font-family: 'Inter', sans-serif;
            font-weight: 900;
            font-size: clamp(1.2rem, 3vw, 2.5rem);
            color: rgba(139, 92, 246, 0.06);
            white-space: nowrap;
            animation: floatDiag linear infinite;
            transform: rotate(-25deg);
            user-select: none;
        }
        .dark .float-text { color: rgba(139, 92, 246, 0.08); }
    </style>
</head>
<body class="font-sans bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-white min-h-screen flex items-center justify-center relative overflow-hidden transition-colors duration-300">

    <!-- Theme Toggle Button -->
    <button class="theme-toggle-btn fixed top-4 right-4 z-50 w-9 h-9 rounded-xl bg-white/80 dark:bg-white/10 border border-slate-200 dark:border-white/10 flex items-center justify-center hover:bg-slate-100 dark:hover:bg-white/20 transition-all shadow-lg backdrop-blur-sm" title="Toggle theme">
        <svg class="icon-sun w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
        <svg class="icon-moon w-5 h-5 text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:none"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
    </button>

    <!-- Background Decorations -->
    <div class="absolute top-20 left-20 w-72 h-72 bg-violet-300/15 dark:bg-violet-600/20 rounded-full blur-3xl float-anim"></div>
    <div class="absolute bottom-20 right-20 w-96 h-96 bg-blue-300/15 dark:bg-blue-600/15 rounded-full blur-3xl float-anim-delay"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-purple-300/10 dark:bg-purple-600/10 rounded-full blur-3xl pulse-glow"></div>

    <!-- Floating Miemploya Background Text -->
    <div class="float-text-container" id="floatingTextBg"></div>

    <!-- Login Card -->
    <div class="relative z-10 w-full max-w-md mx-4">
        <!-- Form Card -->
        <div class="bg-white/80 dark:bg-white/5 backdrop-blur-xl rounded-2xl border border-slate-200 dark:border-white/10 shadow-2xl p-5 relative">
            <!-- Logo top-right -->
            <a href="../index.php" class="absolute top-3 right-3 h-[52px] w-[187px] overflow-hidden block">
                <img src="../assets/images/logo.png" alt="MiAuditOps" class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 h-[180%] object-contain dark:hidden">
                <img src="../assets/images/logo-dark.png" alt="MiAuditOps Dark" class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 h-[180%] object-contain hidden dark:block">
            </a>
            <h2 class="text-base font-bold text-slate-800 dark:text-white mb-0.5">Welcome Back</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">Sign in to your operations hub</p>

            <?php if ($error): ?>
                <div class="mb-4 p-3 rounded-xl <?= $show_resend ? 'bg-amber-500/10 border-amber-500/20' : 'bg-red-500/10 border-red-500/20' ?> border text-sm">
                    <div class="flex items-center gap-2 <?= $show_resend ? 'text-amber-400' : 'text-red-400' ?>">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?php echo $error; ?></span>
                    </div>
                    <?php if ($show_resend && $resend_email): ?>
                        <form method="POST" class="mt-2">
                            <input type="hidden" name="action" value="resend_verification">
                            <input type="hidden" name="resend_email" value="<?= htmlspecialchars($resend_email) ?>">
                            <button type="submit" class="w-full px-3 py-2 text-xs font-bold bg-amber-500/20 hover:bg-amber-500/30 border border-amber-500/30 rounded-lg text-amber-300 transition-all flex items-center justify-center gap-2">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                Resend Verification Email
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-2">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-0.5">Company Code <span class="font-normal text-slate-400">(optional)</span></label>
                    <input type="text" name="company_code" placeholder="Leave blank to auto-detect"
                           value="<?php echo htmlspecialchars($_POST['company_code'] ?? ''); ?>"
                           class="w-full px-3 py-1.5 bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-lg text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-violet-500 focus:ring-1 focus:ring-violet-500/20 transition-all uppercase tracking-wider text-sm font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-0.5">Email Address</label>
                    <input type="email" name="email" placeholder="your@email.com" required
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                           class="w-full px-3 py-1.5 bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-lg text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-violet-500 focus:ring-1 focus:ring-violet-500/20 transition-all text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-0.5">Password</label>
                    <div class="relative">
                        <input type="password" name="password" id="login-password" placeholder="••••••••" required
                               class="w-full px-3 py-1.5 bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-lg text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-violet-500 focus:ring-1 focus:ring-violet-500/20 transition-all text-sm pr-8">
                        <button type="button" onclick="togglePassword('login-password',this)" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                </div>

                <div class="text-right">
                    <a href="forgot_password.php" class="text-xs text-violet-600 dark:text-violet-400 hover:text-violet-500 dark:hover:text-violet-300 font-semibold transition-colors">Forgot Password?</a>
                </div>

                <button type="submit" 
                        class="w-full py-2 bg-gradient-to-r from-violet-600 to-purple-600 text-white font-bold rounded-xl shadow-lg shadow-violet-500/30 hover:shadow-violet-500/50 hover:scale-[1.02] transition-all duration-200 text-sm">
                    Sign In
                </button>
            </form>

                <!-- Divider -->
                <div class="flex items-center gap-3 my-3">
                    <div class="flex-1 h-px bg-slate-200 dark:bg-white/10"></div>
                    <span class="text-xs text-slate-400 dark:text-slate-500 font-medium">OR</span>
                    <div class="flex-1 h-px bg-slate-200 dark:bg-white/10"></div>
                </div>

                <!-- Google Sign-In Button (native render) -->
                <div id="google-login-container" class="flex justify-center"></div>

            <p class="text-center text-sm text-slate-500 dark:text-slate-400 mt-4">
                Don't have an account? <a href="signup.php" class="text-violet-600 dark:text-violet-400 hover:text-violet-500 dark:hover:text-violet-300 font-semibold">Register Company</a>
            </p>
        </div>

        <a href="../index.php" class="block text-center text-xs text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 mt-4 transition-colors">← Back to Home</a>
        <p class="text-center text-xs text-slate-400 dark:text-slate-500 mt-2">&copy; <?php echo date('Y'); ?> Miemploya. All rights reserved.</p>
    </div>
</body>

<script>
function togglePassword(id, btn) {
    const input = document.getElementById(id);
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    btn.innerHTML = isPassword
        ? '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>'
        : '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
}

function handleGoogleLogin(response) {
    // Show inline loading
    const container = document.getElementById('google-login-container');
    container.innerHTML = '<p class="text-xs text-slate-400 animate-pulse">Signing in...</p>';
    
    const fd = new FormData();
    fd.append('id_token', response.credential);
    fd.append('mode', 'login');
    
    fetch('google_callback.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = '../dashboard/index.php';
            } else {
                let errDiv = document.querySelector('.google-err');
                if (!errDiv) {
                    errDiv = document.createElement('div');
                    errDiv.className = 'google-err mb-3 p-2 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-xs';
                    container.before(errDiv);
                }
                errDiv.textContent = data.message;
                // Re-render the button
                container.innerHTML = '';
                google.accounts.id.renderButton(container, { theme: 'filled_black', size: 'large', width: 320, text: 'signin_with', shape: 'pill' });
            }
        })
        .catch(() => {
            alert('Sign-in failed. Please try again.');
            container.innerHTML = '';
            google.accounts.id.renderButton(container, { theme: 'filled_black', size: 'large', width: 320, text: 'signin_with', shape: 'pill' });
        });
}

document.addEventListener('DOMContentLoaded', function() {
    // Google GSI loads async — poll until available or timeout after 5s
    let attempts = 0;
    const waitForGoogle = setInterval(function() {
        attempts++;
        if (typeof google !== 'undefined' && google.accounts) {
            clearInterval(waitForGoogle);
            const isDark = document.documentElement.classList.contains('dark');
            google.accounts.id.initialize({
                client_id: '<?= GOOGLE_CLIENT_ID ?>',
                callback: handleGoogleLogin
            });
            google.accounts.id.renderButton(
                document.getElementById('google-login-container'),
                { theme: isDark ? 'filled_black' : 'outline', size: 'large', width: 320, text: 'signin_with', shape: 'pill' }
            );
        } else if (attempts > 50) {
            clearInterval(waitForGoogle);
            document.getElementById('google-login-container').innerHTML = '<p class="text-xs text-slate-400">Google Sign-In unavailable</p>';
        }
    }, 100);
});
</script>
<script>
// Generate floating "Miemploya" background text
(function() {
    const container = document.getElementById('floatingTextBg');
    if (!container) return;
    const COUNT = 18;
    function spawnText(delay) {
        const el = document.createElement('span');
        el.className = 'float-text';
        el.textContent = 'Miemploya';
        const left = Math.random() * 120 + 10; // 10% to 130% so they start off-screen right
        const dur = 12 + Math.random() * 16;   // 12s to 28s
        const size = 0.7 + Math.random() * 1.8; // font scale
        el.style.left = left + '%';
        el.style.top = '-5%';
        el.style.fontSize = size + 'rem';
        el.style.animationDuration = dur + 's';
        el.style.animationDelay = delay + 's';
        container.appendChild(el);
    }
    for (let i = 0; i < COUNT; i++) { spawnText(i * 1.8); }
})();
</script>
</html>
