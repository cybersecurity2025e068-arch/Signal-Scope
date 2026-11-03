-- SignalScope — مخطط قاعدة البيانات
-- متوافق مع MySQL 5.7+ و MariaDB 10.3+ (استضافة cPanel المشتركة)
-- الاستيراد: phpMyAdmin ← اختر قاعدة البيانات ← Import ← هذا الملف

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username    VARCHAR(64)  NOT NULL,
  pass_hash   VARCHAR(255) NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_fail (
  ip  VARCHAR(45) NOT NULL,
  ts  INT UNSIGNED NOT NULL,
  KEY k_ip_ts (ip, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS devices (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  model         VARCHAR(100) NULL,
  android       VARCHAR(20)  NULL,
  app_version   VARCHAR(20)  NULL,
  token_hash    CHAR(64)     NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen     DATETIME     NULL,
  last_ip       VARCHAR(45)  NULL,
  revoked       TINYINT(1)   NOT NULL DEFAULT 0,
  UNIQUE KEY uq_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- الأوقات بالمللي ثانية منذ 1970 (UTC) كما يرسلها الهاتف
CREATE TABLE IF NOT EXISTS sessions (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id     INT UNSIGNED NOT NULL,
  uuid          CHAR(36)     NOT NULL,
  name          VARCHAR(120) NULL,
  started_at    BIGINT       NOT NULL,
  ended_at      BIGINT       NULL,
  sample_count  INT UNSIGNED NOT NULL DEFAULT 0,
  first_ts      BIGINT       NULL,
  last_ts       BIGINT       NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dev_uuid (device_id, uuid),
  KEY k_dev_start (device_id, started_at),
  KEY k_start (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- الخلايا (معرّف الخلية فريد داخل المشغّل والتقنية)
CREATE TABLE IF NOT EXISTS cells (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tech          VARCHAR(8)   NOT NULL,
  mcc           SMALLINT UNSIGNED NOT NULL,
  mnc           SMALLINT UNSIGNED NOT NULL,
  tac           INT UNSIGNED NULL,
  cid           BIGINT UNSIGNED NOT NULL,
  pci           SMALLINT UNSIGNED NULL,
  arfcn         INT UNSIGNED NULL,
  first_seen    BIGINT       NULL,
  last_seen     BIGINT       NULL,
  sample_count  INT UNSIGNED NOT NULL DEFAULT 0,
  avg_rsrp      FLOAT        NULL,
  dirty         TINYINT(1)   NOT NULL DEFAULT 1,
  est_lat       DOUBLE       NULL,
  est_lon       DOUBLE       NULL,
  est_radius_m  FLOAT        NULL,
  est_n         INT UNSIGNED NULL,
  est_method    VARCHAR(20)  NULL,
  est_exponent  FLOAT        NULL,
  est_updated   DATETIME     NULL,
  ocid_lat      DOUBLE       NULL,
  ocid_lon      DOUBLE       NULL,
  ocid_range    INT UNSIGNED NULL,
  ocid_checked  DATETIME     NULL,
  UNIQUE KEY uq_cell (mcc, mnc, tech, cid),
  KEY k_dirty (dirty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS samples (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   INT UNSIGNED NOT NULL,
  session_id  INT UNSIGNED NOT NULL,
  local_id    BIGINT       NOT NULL,
  ts          BIGINT       NOT NULL,
  lat         DOUBLE       NULL,
  lon         DOUBLE       NULL,
  acc         FLOAT        NULL,
  alt         FLOAT        NULL,
  speed       FLOAT        NULL,
  tech        VARCHAR(8)   NULL,
  mcc         SMALLINT UNSIGNED NULL,
  mnc         SMALLINT UNSIGNED NULL,
  tac         INT UNSIGNED NULL,
  cid         BIGINT UNSIGNED NULL,
  pci         SMALLINT UNSIGNED NULL,
  arfcn       INT UNSIGNED NULL,
  rsrp        SMALLINT     NULL,
  rsrq        SMALLINT     NULL,
  sinr        FLOAT        NULL,
  rssi        SMALLINT     NULL,
  ta          SMALLINT     NULL,
  cell_ref    INT UNSIGNED NULL,
  neighbors   TEXT         NULL,
  UNIQUE KEY uq_dev_local (device_id, local_id),
  KEY k_session_ts (session_id, ts),
  KEY k_cell (cell_ref),
  KEY k_ts (ts),
  KEY k_latlon (lat, lon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
