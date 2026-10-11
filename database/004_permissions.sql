CREATE TABLE IF NOT EXISTS treasury_user_permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  can_view TINYINT(1) NOT NULL DEFAULT 1,
  can_transact TINYINT(1) NOT NULL DEFAULT 0,
  can_manage TINYINT(1) NOT NULL DEFAULT 0,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_treasury_user_permission(company_id,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO treasury_user_permissions(company_id,user_id,can_view,can_transact,can_manage,updated_by)
SELECT company_id,user_id,1,IF(is_admin=1 OR is_owner=1,1,0),IF(is_admin=1 OR is_owner=1,1,0),user_id
FROM company_user WHERE company_id=2
ON DUPLICATE KEY UPDATE can_view=VALUES(can_view),can_transact=VALUES(can_transact),can_manage=VALUES(can_manage);
