<?php
/**
 * MIAUDITOPS — Google OAuth Callback
 * Handles both Google Sign-Up and Google Sign-In
 */
session_start();
require_once '../config/db.php';
require_once '../config/google_config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

$id_token = $_POST['id_token'] ?? '';
$mode = $_POST['mode'] ?? 'login'; // 'signup' or 'login'
$company_name = trim($_POST['company_name'] ?? '');

if (empty($id_token)) {
    echo json_encode(['success' => false, 'message' => 'Missing Google token']);
    exit;
}

// Verify the Google ID token via Google's tokeninfo endpoint (using cURL for reliability)
$ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($id_token));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false, // Required for XAMPP local dev without CA bundle
    CURLOPT_SSL_VERIFYHOST => 0,
]);
$token_info = curl_exec($ch);
$curl_error = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($token_info === false || $http_code !== 200) {
    echo json_encode(['success' => false, 'message' => 'Failed to verify Google token: ' . ($curl_error ?: "HTTP $http_code")]);
    exit;
}

$payload = json_decode($token_info, true);
if (!$payload || !isset($payload['sub'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid Google token']);
    exit;
}

// Verify audience matches our client ID
if (($payload['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
    echo json_encode(['success' => false, 'message' => 'Token audience mismatch']);
    exit;
}

// Extract user info from Google
$google_id = $payload['sub'];
$email = $payload['email'] ?? '';
$first_name = $payload['given_name'] ?? '';
$last_name = $payload['family_name'] ?? '';
$avatar = $payload['picture'] ?? '';

if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Google account has no email']);
    exit;
}

// Check if this Google account is already registered
$stmt = $pdo->prepare("SELECT u.*, c.code AS company_code FROM users u JOIN companies c ON c.id = u.company_id WHERE u.google_id = ? AND u.deleted_at IS NULL LIMIT 1");
$stmt->execute([$google_id]);
$existing_user = $stmt->fetch();

if ($mode === 'signup') {
    // ============= SIGN UP =============
    if ($existing_user) {
        echo json_encode(['success' => false, 'message' => 'This Google account is already registered. Please sign in instead.']);
        exit;
    }
    
    // Also check if email is already used (with password-based account)
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists. Please sign in with your password.']);
        exit;
    }
    
    if (empty($company_name)) {
        echo json_encode(['success' => false, 'message' => 'Company name is required for registration']);
        exit;
    }
    
    try {
        $result = register_company_and_user_google($company_name, $email, $google_id, $first_name, $last_name, $avatar);
        
        // Auto-login after signup
        $_SESSION['user_id'] = $result['user_id'];
        $_SESSION['company_id'] = $result['company_id'];
        $_SESSION['user_role'] = 'business_owner';
        $_SESSION['user_name'] = trim($first_name . ' ' . $last_name);
        
        echo json_encode([
            'success' => true,
            'mode' => 'signup',
            'company_code' => $result['code'],
            'message' => "Company registered! Your code is: {$result['code']}. Save this — you'll need it to login."
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()]);
    }
    
} else {
    // ============= SIGN IN =============
    if ($existing_user) {
        // Google account found — log in
        $_SESSION['user_id'] = $existing_user['id'];
        $_SESSION['company_id'] = $existing_user['company_id'];
        $_SESSION['user_role'] = $existing_user['role'];
        $_SESSION['user_name'] = trim($existing_user['first_name'] . ' ' . $existing_user['last_name']);
        
        // Update last login
        $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$existing_user['id']]);
        
        echo json_encode([
            'success' => true,
            'mode' => 'login',
            'company_code' => $existing_user['company_code'],
            'message' => 'Signed in successfully'
        ]);
    } else {
        // Check if email exists but without google_id (password-based account)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND google_id IS NULL AND deleted_at IS NULL");
        $stmt->execute([$email]);
        $email_user = $stmt->fetch();
        
        if ($email_user) {
            // Link Google account to existing user
            $stmt = $pdo->prepare("UPDATE users SET google_id = ?, avatar_url = COALESCE(avatar_url, ?) WHERE id = ?");
            $stmt->execute([$google_id, $avatar, $email_user['id']]);
            
            // Now fetch full user data and log in
            $stmt = $pdo->prepare("SELECT u.*, c.code AS company_code FROM users u JOIN companies c ON c.id = u.company_id WHERE u.id = ?");
            $stmt->execute([$email_user['id']]);
            $user = $stmt->fetch();
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['company_id'] = $user['company_id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
            
            $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            echo json_encode([
                'success' => true,
                'mode' => 'login',
                'company_code' => $user['company_code'],
                'message' => 'Google account linked and signed in'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No account found for this Google account. Please sign up first.']);
        }
    }
}
