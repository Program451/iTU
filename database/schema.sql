-- Minimal baseline for a NEW empty database; not a production data export.
-- Remaining tables are created by the PHP application and schema helpers.
CREATE TABLE IF NOT EXISTS users (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(40) NOT NULL,
  password VARCHAR(255) NOT NULL,
  biz_name VARCHAR(180) NOT NULL,
  biz_category VARCHAR(60) NOT NULL DEFAULT 'services',
  location VARCHAR(100) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY user_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS business_catalog (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  item_name VARCHAR(180) NOT NULL,
  item_price DECIMAL(15,2) NOT NULL,
  item_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
  units_sold INT NOT NULL DEFAULT 0,
  category VARCHAR(80) NOT NULL DEFAULT '',
  INDEX owner_catalog (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
