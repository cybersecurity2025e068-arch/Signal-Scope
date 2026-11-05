-- مخطط قاعدة البيانات (MySQL 5.7+ / MariaDB 10.2+). آمن للتكرار.
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  pass_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(120) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  role ENUM('admin','staff','agent') NOT NULL DEFAULT 'agent',
  balance BIGINT NOT NULL DEFAULT 0,
  credit_limit BIGINT NOT NULL DEFAULT 0,
  zone VARCHAR(80) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  speed_down INT UNSIGNED NOT NULL DEFAULT 0,
  speed_up INT UNSIGNED NOT NULL DEFAULT 0,
  duration_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  price BIGINT NOT NULL DEFAULT 0,
  retail_price BIGINT NOT NULL DEFAULT 0,
  quota_gb INT UNSIGNED NOT NULL DEFAULT 0,
  radius_group VARCHAR(64) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscribers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password VARCHAR(64) NOT NULL,
  full_name VARCHAR(120) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  phone2 VARCHAR(30) NOT NULL DEFAULT '',
  address VARCHAR(255) NOT NULL DEFAULT '',
  zone VARCHAR(80) NOT NULL DEFAULT '',
  national_id VARCHAR(40) NOT NULL DEFAULT '',
  mac VARCHAR(17) NOT NULL DEFAULT '',
  static_ip VARCHAR(45) NOT NULL DEFAULT '',
  device VARCHAR(80) NOT NULL DEFAULT '',
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  plan_id INT UNSIGNED NULL,
  agent_id INT UNSIGNED NULL,
  expires_at DATETIME NULL,
  suspended TINYINT(1) NOT NULL DEFAULT 0,
  suspend_reason VARCHAR(255) NOT NULL DEFAULT '',
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_agent (agent_id),
  KEY idx_plan (plan_id),
  KEY idx_exp (expires_at),
  KEY idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- كل تفعيل أو تجديد (مصدر تقارير المبيعات)
CREATE TABLE IF NOT EXISTS activations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscriber_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NULL,
  method ENUM('balance','card','admin','portal') NOT NULL,
  card_id INT UNSIGNED NULL,
  price BIGINT NOT NULL DEFAULT 0,
  retail_price BIGINT NOT NULL DEFAULT 0,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  prev_expires_at DATETIME NULL,
  prev_plan_id INT UNSIGNED NULL,
  cancelled TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_sub (subscriber_id),
  KEY idx_created (created_at),
  KEY idx_agent (agent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- دفتر حسابات الوكلاء: كل حركة على الرصيد
CREATE TABLE IF NOT EXISTS transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('deposit','withdraw','activation','refund','cards','adjust') NOT NULL,
  amount BIGINT NOT NULL,
  balance_after BIGINT NOT NULL,
  subscriber_id INT UNSIGNED NULL,
  activation_id INT UNSIGNED NULL,
  batch_id INT UNSIGNED NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_user (user_id, created_at),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS card_batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NULL,
  qty INT UNSIGNED NOT NULL,
  unit_price BIGINT NOT NULL DEFAULT 0,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id INT UNSIGNED NOT NULL,
  serial VARCHAR(20) NOT NULL UNIQUE,
  pin VARCHAR(20) NOT NULL UNIQUE,
  plan_id INT UNSIGNED NOT NULL,
  agent_id INT UNSIGNED NULL,
  status ENUM('new','used','disabled') NOT NULL DEFAULT 'new',
  subscriber_id INT UNSIGNED NULL,
  used_at DATETIME NULL,
  KEY idx_batch (batch_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  actor VARCHAR(64) NOT NULL DEFAULT '',
  action VARCHAR(40) NOT NULL,
  entity VARCHAR(30) NOT NULL DEFAULT '',
  entity_id INT UNSIGNED NULL,
  details VARCHAR(500) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY idx_created (created_at),
  KEY idx_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(64) NOT NULL DEFAULT '',
  scope VARCHAR(10) NOT NULL DEFAULT 'panel',
  created_at DATETIME NOT NULL,
  KEY idx_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(50) PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
