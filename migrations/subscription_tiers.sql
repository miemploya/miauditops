-- =====================================================
-- SUBSCRIPTION TIERS — Database Migration
-- Extends company_subscriptions with tier-specific limits
-- =====================================================

-- Add new columns to existing company_subscriptions table
ALTER TABLE company_subscriptions
    ADD COLUMN IF NOT EXISTS max_products INT DEFAULT 20 AFTER max_outlets,
    ADD COLUMN IF NOT EXISTS max_departments INT DEFAULT 1 AFTER max_products,
    ADD COLUMN IF NOT EXISTS max_clients INT DEFAULT 1 AFTER max_departments,
    ADD COLUMN IF NOT EXISTS data_retention_days INT DEFAULT 90 AFTER max_clients,
    ADD COLUMN IF NOT EXISTS billing_cycle ENUM('monthly','quarterly','annual') DEFAULT 'monthly' AFTER data_retention_days,
    ADD COLUMN IF NOT EXISTS pdf_export TINYINT(1) DEFAULT 0 AFTER billing_cycle,
    ADD COLUMN IF NOT EXISTS viewer_role TINYINT(1) DEFAULT 0 AFTER pdf_export,
    ADD COLUMN IF NOT EXISTS station_audit TINYINT(1) DEFAULT 0 AFTER viewer_role;

-- Modify plan_name to use standardized tier keys
ALTER TABLE company_subscriptions
    MODIFY COLUMN plan_name VARCHAR(100) DEFAULT 'starter';

-- Update any existing 'free' plans to 'starter'
UPDATE company_subscriptions SET plan_name = 'starter' WHERE plan_name = 'free';

-- Set proper defaults for existing 'starter' subscriptions
UPDATE company_subscriptions
SET max_users = 2,
    max_outlets = 2,
    max_products = 20,
    max_departments = 1,
    max_clients = 1,
    data_retention_days = 90,
    pdf_export = 0,
    viewer_role = 0,
    station_audit = 0
WHERE plan_name = 'starter';

-- =====================================================
-- Auto-provisioning: ensure every company has a subscription
-- Companies without a subscription get Starter by default
-- =====================================================
INSERT INTO company_subscriptions (company_id, plan_name, status, started_at, max_users, max_outlets, max_products, max_departments, max_clients, data_retention_days)
SELECT c.id, 'starter', 'active', CURDATE(), 2, 2, 20, 1, 1, 90
FROM companies c
LEFT JOIN company_subscriptions cs ON cs.company_id = c.id
WHERE cs.id IS NULL AND c.deleted_at IS NULL;
