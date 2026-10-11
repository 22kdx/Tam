ALTER TABLE treasury_transactions
  ADD COLUMN status ENUM('active','cancelled','deleted') NOT NULL DEFAULT 'active' AFTER transaction_type,
  ADD COLUMN cancelled_at DATETIME NULL,
  ADD COLUMN cancelled_by INT UNSIGNED NULL,
  ADD COLUMN cancellation_reason VARCHAR(500) NULL,
  ADD COLUMN deleted_at DATETIME NULL,
  ADD COLUMN deleted_by INT UNSIGNED NULL,
  ADD COLUMN deletion_reason VARCHAR(500) NULL,
  ADD KEY idx_treasury_status(company_id,status);
INSERT INTO treasury_settings(company_id,setting_key,setting_value,updated_by) VALUES
(2,'reversal_enabled','0','migration'),(2,'cancellation_enabled','0','migration'),(2,'deletion_enabled','0','migration')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
