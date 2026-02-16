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

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $company_code = strtoupper(trim($_POST['company_code'] ?? ''));
    
    if (empty($email) || empty($password) || empty($company_code)) {
        $error = 'All fields are required.';
    } else {
        try {
            // Find company by code
            $stmt = $pdo->prepare("SELECT id FROM companies WHERE code = ? AND is_active = 1 AND deleted_at IS NULL");
            $stmt->execute([$company_code]);
            $company = $stmt->fetch();
            
            if (!$company) {
                $error = 'Invalid company code.';
            } else {
                // Find user
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND company_id = ? AND is_active = 1 AND deleted_at IS NULL");
                $stmt->execute([$email, $company['id']]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password'])) {
                    // Set session
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['company_id'] = $user['company_id'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                    
                    // Update last login
                    $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $stmt->execute([$user['id']]);
                    
                    header('Location: ../dashboard/index.php');
                    exit;
                } else {
                    $error = 'Invalid email or password.';
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
    </style>
</head>
<body class="font-sans bg-slate-950 text-white min-h-screen flex items-center justify-center relative overflow-hidden">

    <!-- Background Decorations -->
    <div class="absolute top-20 left-20 w-72 h-72 bg-violet-600/20 rounded-full blur-3xl float-anim"></div>
    <div class="absolute bottom-20 right-20 w-96 h-96 bg-blue-600/15 rounded-full blur-3xl float-anim-delay"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-purple-600/10 rounded-full blur-3xl pulse-glow"></div>

    <!-- Login Card -->
    <div class="relative z-10 w-full max-w-md mx-4">
        <!-- Brand -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-violet-500 to-purple-600 shadow-2xl shadow-violet-500/40 mb-4">
                <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            </div>
            <h1 class="text-3xl font-black tracking-tight bg-gradient-to-r from-violet-400 via-purple-400 to-blue-400 bg-clip-text text-transparent">MIAUDITOPS</h1>
            <p class="text-sm text-slate-400 mt-1">Operational Intelligence & Financial Control</p>
        </div>

        <!-- Form Card -->
        <div class="bg-white/5 backdrop-blur-xl rounded-2xl border border-white/10 shadow-2xl p-8">
            <h2 class="text-xl font-bold text-white mb-1">Welcome Back</h2>
            <p class="text-sm text-slate-400 mb-6">Sign in to your operations hub</p>

            <?php if ($error): ?>
                <div class="mb-4 p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-slate-300 mb-1.5">Company Code</label>
                    <input type="text" name="company_code" placeholder="e.g. ACME123" required
                           value="<?php echo htmlspecialchars($_POST['company_code'] ?? ''); ?>"
                           class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 transition-all uppercase tracking-wider text-sm font-mono">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-300 mb-1.5">Email Address</label>
                    <input type="email" name="email" placeholder="your@email.com" required
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                           class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 transition-all text-sm">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-300 mb-1.5">Password</label>
                    <input type="password" name="password" placeholder="••••••••" required
                           class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 transition-all text-sm">
                </div>

                <button type="submit" 
                        class="w-full py-3 bg-gradient-to-r from-violet-600 to-purple-600 text-white font-bold rounded-xl shadow-lg shadow-violet-500/30 hover:shadow-violet-500/50 hover:scale-[1.02] transition-all duration-200 text-sm">
                    Sign In
                </button>
            </form>

            <p class="text-center text-sm text-slate-400 mt-6">
                Don't have an account? <a href="signup.php" class="text-violet-400 hover:text-violet-300 font-semibold">Register Company</a>
            </p>
        </div>

        <p class="text-center text-xs text-slate-500 mt-6">&copy; <?php echo date('Y'); ?> Miemploya. All rights reserved.</p>
    </div>
</body>
</html>
