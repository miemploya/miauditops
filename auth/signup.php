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
<body class="font-sans bg-slate-950 text-white min-h-screen flex items-center justify-center relative overflow-hidden">

    <div class="absolute top-20 left-20 w-72 h-72 bg-emerald-600/20 rounded-full blur-3xl float-anim"></div>
    <div class="absolute bottom-20 right-20 w-96 h-96 bg-violet-600/15 rounded-full blur-3xl float-anim-delay"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-blue-600/10 rounded-full blur-3xl pulse-glow"></div>

    <div class="relative z-10 w-full max-w-md mx-4">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 shadow-2xl shadow-emerald-500/40 mb-4">
                <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
            </div>
            <h1 class="text-3xl font-black tracking-tight bg-gradient-to-r from-emerald-400 via-teal-400 to-blue-400 bg-clip-text text-transparent">MIAUDITOPS</h1>
            <p class="text-sm text-slate-400 mt-1">Register Your Business</p>
        </div>

        <div class="bg-white/5 backdrop-blur-xl rounded-2xl border border-white/10 shadow-2xl p-8">
            <h2 class="text-xl font-bold text-white mb-1">Create Account</h2>
            <p class="text-sm text-slate-400 mb-6">Set up your operational control system</p>

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
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-1.5">Company / Business Name</label>
                        <input type="text" name="company_name" placeholder="Your Business Name" required
                               value="<?php echo htmlspecialchars($_POST['company_name'] ?? ''); ?>"
                               class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all text-sm">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold text-slate-300 mb-1.5">First Name</label>
                            <input type="text" name="first_name" placeholder="John" required
                                   value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>"
                                   class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-300 mb-1.5">Last Name</label>
                            <input type="text" name="last_name" placeholder="Doe" required
                                   value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"
                                   class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-1.5">Email Address</label>
                        <input type="email" name="email" placeholder="admin@company.com" required
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                               class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-1.5">Password</label>
                        <input type="password" name="password" placeholder="Min. 6 characters" required
                               class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-300 mb-1.5">Confirm Password</label>
                        <input type="password" name="confirm_password" placeholder="Re-enter password" required
                               class="w-full px-4 py-3 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all text-sm">
                    </div>

                    <button type="submit" 
                            class="w-full py-3 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl shadow-lg shadow-emerald-500/30 hover:shadow-emerald-500/50 hover:scale-[1.02] transition-all duration-200 text-sm">
                        Register Company
                    </button>
                </form>
            <?php endif; ?>

            <p class="text-center text-sm text-slate-400 mt-6">
                Already registered? <a href="login.php" class="text-violet-400 hover:text-violet-300 font-semibold">Sign In</a>
            </p>
        </div>

        <p class="text-center text-xs text-slate-500 mt-6">&copy; <?php echo date('Y'); ?> Miemploya. All rights reserved.</p>
    </div>
</body>
</html>
