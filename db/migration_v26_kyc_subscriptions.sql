-- v26: KYC + Subscription Plans
CREATE TABLE IF NOT EXISTS reporter_kyc (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  reporter_uid VARCHAR(128) NOT NULL UNIQUE,
  pan_number VARCHAR(10) NOT NULL,
  pan_name VARCHAR(200) NOT NULL,
  pan_doc_url VARCHAR(500) NOT NULL,
  account_number_masked VARCHAR(20) NOT NULL,
  account_number_encrypted TEXT NOT NULL,
  ifsc_code VARCHAR(11) NOT NULL,
  bank_name VARCHAR(200) NOT NULL,
  account_holder VARCHAR(200) NOT NULL,
  kyc_status ENUM('pending','approved','rejected') DEFAULT 'pending',
  rejection_reason TEXT NULL,
  approved_by VARCHAR(128) NULL,
  approved_at TIMESTAMP NULL,
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  withdrawal_limit DECIMAL(12,2) DEFAULT 50000.00,
  kyc_verified_limit DECIMAL(12,2) DEFAULT 500000.00,
  INDEX idx_reporter (reporter_uid),
  INDEX idx_status (kyc_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscription_plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  name_hi VARCHAR(100) NOT NULL,
  price_monthly DECIMAL(10,2) NOT NULL,
  price_yearly DECIMAL(10,2) NOT NULL,
  features JSON NOT NULL,
  razorpay_plan_monthly VARCHAR(100) NULL,
  razorpay_plan_yearly VARCHAR(100) NULL,
  is_active TINYINT DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO subscription_plans (id, name, name_hi, price_monthly, price_yearly, features, is_active) VALUES
(1, 'Premium Reader', 'प्रीमियम पाठक', 29.00, 299.00,
 '["Ad-free reading","Exclusive content","Early access breaking news","Download articles offline","Priority customer support"]',
 1);

CREATE TABLE IF NOT EXISTS user_subscriptions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_uid VARCHAR(128) NOT NULL,
  plan_id INT NOT NULL,
  billing ENUM('monthly','yearly') NOT NULL,
  razorpay_subscription_id VARCHAR(100) NOT NULL UNIQUE,
  status ENUM('created','authenticated','active','paused','cancelled','halted','expired') DEFAULT 'created',
  short_url VARCHAR(500) NULL,
  current_start TIMESTAMP NULL,
  current_end TIMESTAMP NULL,
  paid_count INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user (user_uid),
  INDEX idx_rzp (razorpay_subscription_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_subscribers (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(15) NOT NULL UNIQUE,
  name VARCHAR(200) NULL,
  city VARCHAR(100) NULL,
  subscribed_categories JSON NULL,
  is_active TINYINT DEFAULT 1,
  subscribed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_phone (phone),
  INDEX idx_city (city, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
