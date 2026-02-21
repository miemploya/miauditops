<?php
/**
 * MIAUDITOPS — Paystack Payment Configuration
 * API keys + helpers for computing plan amounts.
 *
 * IMPORTANT: Real keys are loaded from .env.paystack.php (gitignored).
 * Copy config/paystack.env.example.php → config/.env.paystack.php and fill in your keys.
 */

// Load real keys from gitignored file, fallback to test placeholders
if (file_exists(__DIR__ . '/.env.paystack.php')) {
    require_once __DIR__ . '/.env.paystack.php';
}
if (!defined('PAYSTACK_SECRET_KEY')) define('PAYSTACK_SECRET_KEY', 'sk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxx');
if (!defined('PAYSTACK_PUBLIC_KEY')) define('PAYSTACK_PUBLIC_KEY', 'pk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('PAYSTACK_BASE_URL',  'https://api.paystack.co');

// Callback URL — adjust domain for production
define('PAYSTACK_CALLBACK_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''))
    . '/../payment_callback.php');

/**
 * Load dynamic prices from the platform_settings table.
 * Returns an associative array keyed by plan_cycle, e.g. 'professional_monthly' => 25000.
 */
function get_dynamic_prices() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = [
        'professional_monthly'  => 25000,
        'professional_quarterly' => 67500,
        'professional_annual'   => 240000,
        'enterprise_monthly'    => 75000,
        'enterprise_quarterly'  => 202500,
        'enterprise_annual'     => 720000,
    ];

    try {
        global $pdo;
        $rows = $pdo->query("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key LIKE 'price_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $key => $value) {
            $short = str_replace('price_', '', $key);
            if (isset($defaults[$short])) {
                $defaults[$short] = (int)$value;
            }
        }
    } catch (Exception $e) {
        // DB not available — use defaults
    }

    $cache = $defaults;
    return $cache;
}

/**
 * Base monthly prices in Naira (derived from dynamic prices).
 */
function get_plan_prices() {
    $p = get_dynamic_prices();
    return [
        'starter'      => 0,
        'professional' => $p['professional_monthly'],
        'enterprise'   => $p['enterprise_monthly'],
    ];
}

/**
 * Billing cycle multipliers & discounts.
 */
function get_cycle_config() {
    return [
        'monthly'   => ['months' => 1,  'discount' => 0],
        'quarterly' => ['months' => 3,  'discount' => 10],
        'annual'    => ['months' => 12, 'discount' => 20],
    ];
}

/**
 * Calculate the total price in kobo for a plan + billing cycle.
 * Uses dynamic prices from DB — no discount math needed, prices are pre-set.
 * @param string $plan_key   e.g. 'professional', 'enterprise'
 * @param string $cycle_key  e.g. 'monthly', 'quarterly', 'annual'
 * @return int  Amount in kobo (multiply naira by 100)
 */
function calculate_amount_kobo($plan_key, $cycle_key) {
    if ($plan_key === 'starter') return 0;

    $prices = get_dynamic_prices();
    $key = $plan_key . '_' . $cycle_key;
    $naira = $prices[$key] ?? 0;

    return (int) round($naira * 100); // convert to kobo
}

/**
 * Make a request to the Paystack API.
 * @param string $method  'GET' or 'POST'
 * @param string $path    e.g. '/transaction/initialize'
 * @param array  $data    POST body (ignored for GET)
 * @return array  Decoded JSON response
 */
function paystack_request($method, $path, $data = []) {
    $url = PAYSTACK_BASE_URL . $path;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
            'Content-Type: application/json',
            'Cache-Control: no-cache',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['status' => false, 'message' => 'cURL error: ' . $error];
    }

    return json_decode($response, true) ?: ['status' => false, 'message' => 'Invalid response'];
}

/**
 * Calculate the subscription expiry date based on billing cycle.
 * @param string $cycle  'monthly', 'quarterly', 'annual'
 * @return string  Date string (Y-m-d)
 */
function calculate_expiry_date($cycle) {
    $cycles = get_cycle_config();
    $months = $cycles[$cycle]['months'] ?? 1;
    return date('Y-m-d', strtotime("+{$months} months"));
}
?>
