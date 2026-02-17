<?php
/**
 * MIAUDITOPS — Company Registration (Signup)
 */
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: ../dashboard/index.php');
    exit;
}

require_once '../config/db.php';
require_once '../config/google_config.php';
require_once '../includes/functions.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = trim($_POST['company_name'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if (empty($company_name) || empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        $error = 'All fields are required.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'An account with this email already exists.';
            } else {
                $result = register_company_and_user($company_name, $email, $password, $first_name, $last_name);
                $success = 'Company registered successfully! Your company code is: <strong>' . $result['code'] . '</strong>. Please save this code — you will need it to login.';
            }
        } catch (Exception $e) {
            $error = 'Registration failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — MIAUDITOPS</title>
    <meta name="description" content="Register your company on MIAUDITOPS — Operational Intelligence & Financial Control System">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script src="../assets/js/theme-toggle.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] }, colors: { brand: { 50:'#f5f3ff',100:'#ede9fe',200:'#ddd6fe',300:'#c4b5fd',400:'#a78bfa',500:'#8b5cf6',600:'#7c3aed',700:'#6d28d9',800:'#5b21b6',900:'#4c1d95',950:'#2e1065' } } } }
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

    <!-- Theme Toggle Button -->
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
            <p class="text-xs text-slate-500 dark:text-slate-400">Register Your Business</p>
        </div>

        <div class="bg-white/80 dark:bg-white/5 backdrop-blur-xl rounded-2xl border border-slate-200 dark:border-white/10 shadow-2xl p-5">
            <h2 class="text-base font-bold text-slate-800 dark:text-white mb-0.5">Create Account</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">Set up your operational control system</p>

            <?php if ($error): ?>
                <div class="mb-4 p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="mb-4 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span class="font-bold">Registration Successful!</span>
                    </div>
                    <p><?php echo $success; ?></p>
                    <a href="login.php" class="inline-block mt-3 px-4 py-2 bg-emerald-500/20 border border-emerald-500/30 rounded-lg text-emerald-300 text-sm font-semibold hover:bg-emerald-500/30 transition-all">
                        Go to Login →
                    </a>
                </div>
            <?php else: ?>
                <form method="POST" class="space-y-2">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-0.5">Company / Business Name</label>
                        <input type="text" name="company_name" placeholder="Your Business Name" required
                               value="<?php echo htmlspecialchars($_POST['company_name'] ?? ''); ?>"
                               class="w-full px-3 py-1.5 bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-lg text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all text-sm">
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-0.5">First Name</label>
                            <input type="text" name="first_name" placeholder="John" required
                                   value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>"
                                   class="w-full px-3 py-1.5 bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-lg text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-0.5">Last Name</label>
                            <input type="text" name="last_name" placeholder="Doe" required
                                   value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"
                                   class="w-full px-3 py-1.5 bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-lg text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-0.5">Email Address</label>
                        <input type="email" name="email" placeholder="admin@company.com" required
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                               class="w-full px-3 py-1.5 bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-lg text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all text-sm">
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-0.5">Password</label>
                            <div class="relative">
                                <input type="password" name="password" id="password" placeholder="Min. 6 chars" required
                                       class="w-full px-3 py-1.5 bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-lg text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all text-sm pr-8">
                                <button type="button" onclick="togglePassword('password',this)" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-0.5">Confirm Password</label>
                            <div class="relative">
                                <input type="password" name="confirm_password" id="confirm_password" placeholder="Re-enter" required
                                       class="w-full px-3 py-1.5 bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-lg text-slate-800 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all text-sm pr-8">
                                <button type="button" onclick="togglePassword('confirm_password',this)" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <button type="submit" 
                            class="w-full py-2 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl shadow-lg shadow-emerald-500/30 hover:shadow-emerald-500/50 hover:scale-[1.02] transition-all duration-200 text-sm">
                        Register Company
                    </button>
                </form>

                <!-- Divider -->
                <div class="flex items-center gap-3 my-3">
                    <div class="flex-1 h-px bg-slate-200 dark:bg-white/10"></div>
                    <span class="text-xs text-slate-400 dark:text-slate-500 font-medium">OR</span>
                    <div class="flex-1 h-px bg-slate-200 dark:bg-white/10"></div>
                </div>

                <!-- Google Sign-Up Button (native render) -->
                <div id="google-signup-container" class="flex justify-center"></div>
            <?php endif; ?>

            <p class="text-center text-sm text-slate-500 dark:text-slate-400 mt-4">
                Already registered? <a href="login.php" class="text-violet-600 dark:text-violet-400 hover:text-violet-500 dark:hover:text-violet-300 font-semibold">Sign In</a>
            </p>
        </div>

        <a href="../index.php" class="block text-center text-xs text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 mt-4 transition-colors">← Back to Home</a>
        <p class="text-center text-xs text-slate-400 dark:text-slate-500 mt-2">&copy; <?php echo date('Y'); ?> Miemploya. All rights reserved.</p>
    </div>
</body>

<!-- Google Company Name Modal -->
<div id="google-company-modal" style="display:none" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-slate-900 border border-white/10 rounded-2xl p-8 w-full max-w-sm mx-4 shadow-2xl">
        <h3 class="text-lg font-bold text-white mb-1">Almost There!</h3>
        <p class="text-sm text-slate-400 mb-5">Enter your company name to complete registration.</p>
        <div id="google-user-info" class="flex items-center gap-3 mb-5 p-3 rounded-xl bg-white/5 border border-white/10">
            <img id="google-avatar" src="" class="w-10 h-10 rounded-full" alt="">
            <div>
                <p id="google-name" class="text-sm font-semibold text-white"></p>
                <p id="google-email" class="text-xs text-slate-400"></p>
            </div>
        </div>
        <input type="text" id="google-company-name" placeholder="Your Business Name"
               class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all text-sm mb-4">
        <div class="flex gap-3">
            <button onclick="document.getElementById('google-company-modal').style.display='none'" class="flex-1 py-2.5 bg-white/5 border border-white/10 rounded-xl text-slate-400 text-sm font-semibold hover:bg-white/10 transition-all">Cancel</button>
            <button id="google-complete-btn" class="flex-1 py-2.5 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl shadow-lg hover:shadow-emerald-500/30 transition-all text-sm">Register</button>
        </div>
        <p id="google-error" class="text-red-400 text-xs mt-3" style="display:none"></p>
    </div>
</div>

<script>
function togglePassword(id, btn) {
    const input = document.getElementById(id);
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    btn.innerHTML = isPassword
        ? '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>'
        : '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
}

let pendingGoogleToken = null;
let pendingGooglePayload = null;

function handleGoogleSignup(response) {
    const payload = JSON.parse(atob(response.credential.split('.')[1]));
    pendingGoogleToken = response.credential;
    pendingGooglePayload = payload;
    
    document.getElementById('google-avatar').src = payload.picture || '';
    document.getElementById('google-name').textContent = (payload.given_name || '') + ' ' + (payload.family_name || '');
    document.getElementById('google-email').textContent = payload.email || '';
    document.getElementById('google-company-modal').style.display = 'flex';
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
                callback: handleGoogleSignup
            });
            google.accounts.id.renderButton(
                document.getElementById('google-signup-container'),
                { theme: isDark ? 'filled_black' : 'outline', size: 'large', width: 320, text: 'signup_with', shape: 'pill' }
            );
        } else if (attempts > 50) {
            clearInterval(waitForGoogle);
            document.getElementById('google-signup-container').innerHTML = '<p class="text-xs text-slate-400">Google Sign-Up unavailable</p>';
        }
    }, 100);
    
    // Complete registration
    document.getElementById('google-complete-btn')?.addEventListener('click', async function() {
        const companyName = document.getElementById('google-company-name').value.trim();
        const errorEl = document.getElementById('google-error');
        if (!companyName) {
            errorEl.textContent = 'Please enter a company name';
            errorEl.style.display = 'block';
            return;
        }
        
        this.disabled = true;
        this.textContent = 'Registering...';
        errorEl.style.display = 'none';
        
        try {
            const fd = new FormData();
            fd.append('id_token', pendingGoogleToken);
            fd.append('mode', 'signup');
            fd.append('company_name', companyName);
            
            const res = await fetch('google_callback.php', { method: 'POST', body: fd });
            const data = await res.json();
            
            if (data.success) {
                document.getElementById('google-company-modal').style.display = 'none';
                document.querySelector('.bg-white\\/5.backdrop-blur-xl').innerHTML = `
                    <div class="text-center py-6">
                        <div class="w-16 h-16 rounded-full bg-emerald-500/20 border border-emerald-500/30 flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <h2 class="text-xl font-bold text-white mb-2">Registration Successful!</h2>
                        <p class="text-slate-400 text-sm mb-4">${data.message}</p>
                        <p class="text-amber-400 text-lg font-black mb-6">Company Code: ${data.company_code}</p>
                        <a href="../dashboard/index.php" class="inline-block px-6 py-3 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl shadow-lg hover:shadow-emerald-500/30 transition-all text-sm">Go to Dashboard →</a>
                    </div>`;
            } else {
                errorEl.textContent = data.message;
                errorEl.style.display = 'block';
            }
        } catch (e) {
            errorEl.textContent = 'Registration failed. Please try again.';
            errorEl.style.display = 'block';
        }
        
        this.disabled = false;
        this.textContent = 'Register';
    });
});
</script>
</html>
