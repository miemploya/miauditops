<?php
/**
 * MIAUDITOPS — Subscription Plan Definitions
 * Defines tier limits, allowed modules, and feature flags per plan.
 *
 * IMPORTANT: plan keys ('starter', 'professional', 'enterprise') must match
 * the plan_name stored in company_subscriptions.
 */

$SUBSCRIPTION_PLANS = [

    'starter' => [
        'label'       => 'Starter',
        'tag'         => 'Free Forever',
        'icon'        => 'rocket',
        'color'       => 'slate',

        // ── Limits ──
        'max_users'       => 2,
        'max_clients'     => 1,
        'max_outlets'     => 2,
        'max_products'    => 20,
        'max_departments' => 1,
        'data_retention_days' => 90,   // 90 days of history

        // ── Allowed modules (permission keys) ──
        'modules' => [
            'dashboard',
            'company_setup',
            'audit',          // Sales Entry tab only (tab-locked)
            'main_store',     // Products + Stock In only (tab-locked)
            'department_store',
            'settings',       // Company Profile only (tab-locked)
            'trash',
        ],

        // ── Tab-level restrictions inside allowed modules ──
        'tab_locks' => [
            'audit'      => ['sales_entry'],                         // only this tab allowed
            'main_store' => ['products', 'stock_in'],                // only these tabs allowed
            'settings'   => ['company'],                             // only this tab allowed
        ],

        // ── Feature flags ──
        'pdf_export'     => false,
        'viewer_role'    => false,
        'audit_export'   => false,
        'station_audit'  => false,
    ],

    'professional' => [
        'label'       => 'Professional',
        'tag'         => 'Most Popular',
        'icon'        => 'briefcase',
        'color'       => 'violet',

        // ── Limits ──
        'max_users'       => 4,
        'max_clients'     => 3,
        'max_outlets'     => 10,
        'max_products'    => 0,    // 0 = unlimited
        'max_departments' => 10,
        'data_retention_days' => 365,  // 1 year

        // ── Allowed modules ──
        'modules' => [
            'dashboard',
            'company_setup',
            'audit',           // all tabs
            'stock',
            'main_store',      // all tabs
            'department_store',
            'finance',         // Revenue + Expenses only (tab-locked)
            'requisitions',
            'reports',         // Sales + Stock only (tab-locked)
            'settings',        // all tabs
            'trash',
        ],

        // ── Tab-level restrictions ──
        'tab_locks' => [
            'finance' => ['revenue', 'expenses'],              // P&L + Cost Centers locked
            'reports' => ['sales', 'stock'],                   // Only sales + stock reports
        ],

        // ── Feature flags ──
        'pdf_export'     => true,
        'viewer_role'    => false,
        'audit_export'   => false,
        'station_audit'  => false,
    ],

    'enterprise' => [
        'label'       => 'Enterprise',
        'tag'         => 'Full Power',
        'icon'        => 'crown',
        'color'       => 'amber',

        // ── Limits ──
        'max_users'       => 0,    // 0 = unlimited
        'max_clients'     => 0,
        'max_outlets'     => 0,
        'max_products'    => 0,
        'max_departments' => 0,
        'data_retention_days' => 0,    // 0 = unlimited

        // ── Allowed modules (everything) ──
        'modules' => [
            'dashboard',
            'company_setup',
            'audit',
            'stock',
            'main_store',
            'department_store',
            'finance',
            'requisitions',
            'reports',
            'settings',
            'trash',
            'station_audit',
        ],

        // ── No tab restrictions ──
        'tab_locks' => [],

        // ── Feature flags ──
        'pdf_export'     => true,
        'viewer_role'    => true,
        'audit_export'   => true,
        'station_audit'  => true,
    ],
];

/**
 * Billing cycle discount multipliers.
 * Applied to the base monthly price.
 */
$BILLING_CYCLES = [
    'monthly'   => ['label' => 'Monthly',   'months' => 1,  'discount' => 0],
    'quarterly' => ['label' => 'Quarterly',  'months' => 3,  'discount' => 10],  // 10% off
    'annual'    => ['label' => 'Annual',     'months' => 12, 'discount' => 20],  // 20% off
];

// ── Helper functions ──

/**
 * Get a plan config by key.
 * @param string $plan_key  e.g. 'starter', 'professional', 'enterprise'
 * @return array
 */
function get_plan_config($plan_key) {
    global $SUBSCRIPTION_PLANS;
    return $SUBSCRIPTION_PLANS[$plan_key] ?? $SUBSCRIPTION_PLANS['starter'];
}

/**
 * Get all plan configs.
 * @return array
 */
function get_all_plans() {
    global $SUBSCRIPTION_PLANS;
    return $SUBSCRIPTION_PLANS;
}

/**
 * Get billing cycle config.
 * @return array
 */
function get_billing_cycles() {
    global $BILLING_CYCLES;
    return $BILLING_CYCLES;
}

/**
 * Check if a module is included in a plan.
 * @param string $plan_key
 * @param string $module_key  e.g. 'finance', 'reports'
 * @return bool
 */
function plan_includes_module($plan_key, $module_key) {
    $plan = get_plan_config($plan_key);
    return in_array($module_key, $plan['modules']);
}

/**
 * Check if a specific tab is allowed within a module for a plan.
 * If no tab_locks are defined for the module, all tabs are allowed.
 * @param string $plan_key
 * @param string $module_key
 * @param string $tab_key
 * @return bool
 */
function plan_allows_tab($plan_key, $module_key, $tab_key) {
    $plan = get_plan_config($plan_key);
    // If no tab restrictions for this module, all tabs allowed
    if (!isset($plan['tab_locks'][$module_key])) {
        return true;
    }
    return in_array($tab_key, $plan['tab_locks'][$module_key]);
}

/**
 * Get the limit value for a resource in a plan.
 * Returns 0 for unlimited.
 * @param string $plan_key
 * @param string $limit_key  e.g. 'max_users', 'max_clients'
 * @return int
 */
function get_plan_limit($plan_key, $limit_key) {
    $plan = get_plan_config($plan_key);
    return $plan[$limit_key] ?? 0;
}

/**
 * Get the data retention cutoff date for a plan.
 * Returns null if unlimited (enterprise).
 * @param string $plan_key
 * @return string|null  Date string (Y-m-d) or null
 */
function get_retention_cutoff($plan_key) {
    $plan = get_plan_config($plan_key);
    $days = $plan['data_retention_days'] ?? 0;
    if ($days <= 0) return null; // unlimited
    return date('Y-m-d', strtotime("-{$days} days"));
}
?>
