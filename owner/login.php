<?php
/**
 * MIAUDITOPS — Owner Portal Login
 * Separate login for platform owners (no company code required)
 */
session_start();

// If already logged in as owner, go to owner dashboard
if (isset($_SESSION['owner_id'])) {
    header('Location: index.php');
    exit;
}

require_once '../config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Username and password are required.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM platform_owners WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $owner = $stmt->fetch();

        if ($owner && password_verify($password, $owner['password'])) {
            $_SESSION['owner_id'] = $owner['id'];
            $_SESSION['owner_name'] = $owner['name'];
            $_SESSION['is_platform_owner'] = true;

            // Update last login
            $pdo->prepare("UPDATE platform_owners SET last_login = NOW() WHERE id = ?")->execute([$owner['id']]);

            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Portal — MIAUDITOPS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        }
    </script>
    <style>
        @keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-20px); } }
        @keyframes pulse-glow { 0%,100% { opacity: 0.3; } 50% { opacity: 0.7; } }
        .float-1 { animation: float 6s ease-in-out infinite; }
        .float-2 { animation: float 8s ease-in-out 2s infinite; }
        .glow { animation: pulse-glow 4s ease-in-out infinite; }
    </style>
</head>
<body class="font-sans bg-slate-950 text-white min-h-screen flex items-center justify-center relative overflow-hidden">

    <!-- Background -->
    <div class="absolute top-20 left-10 w-80 h-80 bg-red-600/15 rounded-full blur-3xl float-1"></div>
    <div class="absolute bottom-10 right-10 w-96 h-96 bg-orange-500/10 rounded-full blur-3xl float-2"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[500px] h-[500px] bg-rose-700/8 rounded-full blur-3xl glow"></div>

    <div class="relative z-10 w-full max-w-md mx-4">
        <!-- Brand -->
        <div class="text-center mb-6">
            <div class="w-16 h-16 mx-auto mb-3 rounded-2xl bg-gradient-to-br from-red-600 to-orange-600 flex items-center justify-center shadow-2xl shadow-red-600/40">
                <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-black tracking-tight">Owner Portal</h1>
            <p class="text-sm text-slate-400 mt-1">Platform Administration</p>
        </div>

        <!-- Login Card -->
        <div class="bg-white/5 backdrop-blur-xl rounded-2xl border border-white/10 shadow-2xl p-6">
            <h2 class="text-lg font-bold mb-1">Platform Sign In</h2>
            <p class="text-xs text-slate-400 mb-5">Access the owner management console</p>

            <?php if ($error): ?>
                <div class="mb-4 p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Username</label>
                    <input type="text" name="username" placeholder="admin" required autocomplete="username"
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                           class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-red-500 focus:ring-1 focus:ring-red-500/30 transition-all text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Password</label>
                    <div class="relative">
                        <input type="password" name="password" id="owner-password" placeholder="••••••••" required autocomplete="current-password"
                               class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-red-500 focus:ring-1 focus:ring-red-500/30 transition-all text-sm pr-10">
                        <button type="button" onclick="let i=document.getElementById('owner-password');i.type=i.type==='password'?'text':'password'" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="w-full py-2.5 bg-gradient-to-r from-red-600 to-orange-600 text-white font-bold rounded-xl shadow-lg shadow-red-600/30 hover:shadow-red-600/50 hover:scale-[1.02] transition-all text-sm">
                    Sign In
                </button>
            </form>
        </div>

        <a href="../index.php" class="block text-center text-xs text-slate-500 hover:text-slate-300 mt-4 transition-colors">← Back to Home</a>
        <p class="text-center text-xs text-slate-500 mt-2">&copy; <?php echo date('Y'); ?> Miemploya. Platform Administrator.</p>
    </div>
</body>
</html>
