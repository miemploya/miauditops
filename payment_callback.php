<?php
/**
 * MIAUDITOPS — Paystack Payment Callback
 * Paystack redirects here after payment with ?reference=xxx
 * Verifies the transaction, activates the subscription, and redirects to dashboard.
 */
session_start();
require_once 'config/db.php';
require_once 'config/paystack.php';
require_once 'config/subscription_plans.php';
require_once 'includes/functions.php';

$reference = trim($_GET['reference'] ?? $_GET['trxref'] ?? '');

if (empty($reference)) {
    set_flash_message('error', 'No payment reference provided.');
    header('Location: dashboard/index.php');
    exit;
}

// ── 1. Look up the pending payment ──
$stmt = $pdo->prepare("SELECT * FROM payments WHERE reference = ? LIMIT 1");
$stmt->execute([$reference]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    set_flash_message('error', 'Payment record not found.');
    header('Location: dashboard/index.php');
    exit;
}

// Already verified?
if ($payment['status'] === 'success') {
    set_flash_message('success', 'Payment already verified. Your subscription is active!');
    header('Location: dashboard/index.php');
    exit;
}

// ── 2. Verify with Paystack ──
$result = paystack_request('GET', '/transaction/verify/' . rawurlencode($reference));

if (empty($result['status']) || $result['status'] !== true || empty($result['data'])) {
    // Update payment as failed
    $pdo->prepare("UPDATE payments SET status = 'failed', paystack_response = ? WHERE id = ?")
        ->execute([json_encode($result), $payment['id']]);
    set_flash_message('error', 'Payment verification failed: ' . ($result['message'] ?? 'Unknown error'));
    header('Location: dashboard/index.php');
    exit;
}

$tx = $result['data'];

if ($tx['status'] !== 'success') {
    $pdo->prepare("UPDATE payments SET status = 'failed', paystack_response = ? WHERE id = ?")
        ->execute([json_encode($result), $payment['id']]);
    set_flash_message('error', 'Payment was not successful. Status: ' . $tx['status']);
    header('Location: dashboard/index.php');
    exit;
}

// ── 3. Payment confirmed — update records ──
$pdo->beginTransaction();

try {
    // Update payment record
    $pdo->prepare("UPDATE payments SET status = 'success', paystack_response = ?, verified_at = NOW() WHERE id = ?")
        ->execute([json_encode($result), $payment['id']]);

    // Load plan config
    $plan_key   = $payment['plan_name'];
    $cycle_key  = $payment['billing_cycle'];
    $company_id = (int)$payment['company_id'];
    $plan_cfg   = get_plan_config($plan_key);

    $expires_at = calculate_expiry_date($cycle_key);

    // Build subscription fields
    $sub_fields = [
        'plan_name'          => $plan_key,
        'status'             => 'active',
        'billing_cycle'      => $cycle_key,
        'expires_at'         => $expires_at,
        'started_at'         => date('Y-m-d'),
        'max_users'          => $plan_cfg['max_users'],
        'max_outlets'        => $plan_cfg['max_outlets'],
        'max_products'       => $plan_cfg['max_products'],
        'max_departments'    => $plan_cfg['max_departments'],
        'max_clients'        => $plan_cfg['max_clients'],
        'data_retention_days'=> $plan_cfg['data_retention_days'],
        'pdf_export'         => $plan_cfg['pdf_export'] ? 1 : 0,
        'viewer_role'        => $plan_cfg['viewer_role'] ? 1 : 0,
        'station_audit'      => $plan_cfg['station_audit'] ? 1 : 0,
        'notes'              => 'Paystack payment: ' . $reference,
    ];

    // Upsert company_subscriptions
    $existing = $pdo->prepare("SELECT id FROM company_subscriptions WHERE company_id = ?");
    $existing->execute([$company_id]);

    if ($existing->fetch()) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($sub_fields)));
        $pdo->prepare("UPDATE company_subscriptions SET $set WHERE company_id = ?")
            ->execute([...array_values($sub_fields), $company_id]);
    } else {
        $sub_fields['company_id'] = $company_id;
        $cols = implode(', ', array_keys($sub_fields));
        $placeholders = implode(', ', array_fill(0, count($sub_fields), '?'));
        $pdo->prepare("INSERT INTO company_subscriptions ($cols) VALUES ($placeholders)")
            ->execute(array_values($sub_fields));
    }

    // Mark linked billing invoice as paid (if any)
    try {
        $inv_stmt = $pdo->prepare("UPDATE billing_invoices SET status = 'paid', paid_at = NOW(), payment_reference = ? WHERE company_id = ? AND payment_reference = ? AND status != 'paid'");
        $inv_stmt->execute([$reference, $company_id, $reference]);
    } catch (Exception $e) {
        // billing_invoices table might not exist yet — ignore
    }

    // Log the upgrade
    log_audit($company_id, (int)$payment['user_id'], 'subscription_upgraded', 'billing',
        $payment['id'], "Upgraded to $plan_key ($cycle_key) via Paystack. Ref: $reference");

    $pdo->commit();

    // Clear cached subscription
    // (The static cache in get_company_subscription will reset on next page load)

    $plan_label = $plan_cfg['label'] ?? ucfirst($plan_key);
    set_flash_message('success', "Payment successful! Your {$plan_label} plan is now active until {$expires_at}.");

} catch (Exception $e) {
    $pdo->rollBack();
    set_flash_message('error', 'Payment received but subscription activation failed. Please contact support. Ref: ' . $reference);
}

header('Location: dashboard/index.php');
exit;
?>
