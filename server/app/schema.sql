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

-- ===== تحليل العوائق =====
-- مربعات OSM التي جُلبت مبانيها (0.02° ≈ 2 km)
CREATE TABLE IF NOT EXISTS osm_tiles (
  tile        VARCHAR(24)  NOT NULL PRIMARY KEY,
  fetched_at  DATETIME     NOT NULL,
  buildings   INT UNSIGNED NOT NULL DEFAULT 0,
  status      VARCHAR(16)  NOT NULL DEFAULT 'ok'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS buildings (
  id         BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  tile       VARCHAR(24) NOT NULL,
  min_lat    DOUBLE NOT NULL,
  min_lon    DOUBLE NOT NULL,
  max_lat    DOUBLE NOT NULL,
  max_lon    DOUBLE NOT NULL,
  height_m   FLOAT  NOT NULL,
  height_src VARCHAR(10) NOT NULL,
  poly       MEDIUMTEXT NOT NULL,
  KEY k_tile (tile),
  KEY k_lat (min_lat, max_lat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- نتيجة تحليل المسار لكل قراءة (dB). excess = الفقد الزائد عن أفضل مسار مقاس لهذه الخلية،
-- موزّعاً على: terrain + buildings + indoor + residual
CREATE TABLE IF NOT EXISTS sample_loss (
  sample_id   BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  cell_ref    INT UNSIGNED NOT NULL,
  dist_m      FLOAT NOT NULL,
  excess      FLOAT NOT NULL,
  terrain     FLOAT NOT NULL,
  buildings   FLOAT NOT NULL,
  indoor      FLOAT NOT NULL,
  residual    FLOAT NOT NULL,
  n_buildings SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  terrain_ok  TINYINT(1) NOT NULL DEFAULT 0,
  KEY k_cell (cell_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
;

-- ===== تنبيهات تدهور الإشارة (منطقة × مشغّل: آخر 7 أيام مقابل 28 يوماً قبلها) =====
CREATE TABLE IF NOT EXISTS alerts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  akey         VARCHAR(64)  NOT NULL,
  mcc          SMALLINT UNSIGNED NULL,
  mnc          SMALLINT UNSIGNED NULL,
  lat          DOUBLE NOT NULL,
  lon          DOUBLE NOT NULL,
  size_m       SMALLINT UNSIGNED NOT NULL,
  severity     VARCHAR(8)   NOT NULL,
  reason       VARCHAR(255) NOT NULL,
  base_rsrp    FLOAT NULL,
  recent_rsrp  FLOAT NULL,
  delta_rsrp   FLOAT NULL,
  base_sinr    FLOAT NULL,
  recent_sinr  FLOAT NULL,
  base_nosvc   FLOAT NULL,
  recent_nosvc FLOAT NULL,
  n_base       INT UNSIGNED NOT NULL,
  n_recent     INT UNSIGNED NOT NULL,
  status       VARCHAR(12)  NOT NULL DEFAULT 'open',
  first_seen   DATETIME NOT NULL,
  last_seen    DATETIME NOT NULL,
  resolved_at  DATETIME NULL,
  ack_at       DATETIME NULL,
  ack_by       VARCHAR(64) NULL,
  UNIQUE KEY uq_key (akey),
  KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
;

-- ===== عزل التشويش: نتيجة تحليل كل قراءة LTE/5G (يُعاد بناؤه تلقائياً، لا يُنسخ احتياطياً) =====
-- deg = تراجع SINR عن المتوقع بسبب التشويش (dB). i/nb/ext بوحدة dBm/MHz:
-- التشويش الكلي، والمتوقع من الخلايا المجاورة على التردد نفسه، وما لا تفسّره (خارجي محتمل)
CREATE TABLE IF NOT EXISTS sample_intf (
  sample_id  BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  cls        VARCHAR(12) NOT NULL,
  src        CHAR(1) NOT NULL,
  sinr_meas  FLOAT NOT NULL,
  sinr_exp   FLOAT NOT NULL,
  deg        FLOAT NOT NULL,
  i_dbm      FLOAT NULL,
  nb_dbm     FLOAT NULL,
  ext_dbm    FLOAT NULL,
  n_cochan   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  n_close    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  agg_pci    SMALLINT UNSIGNED NULL,
  agg_gap    FLOAT NULL,
  agg_mod3   TINYINT(1) NOT NULL DEFAULT 0,
  KEY k_cls (cls)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
;

-- ===== مركز إدارة البوابة: المدير ونائبه والمشرفون، والتفرعات، والإعلانات، والإحصاءات، وسجل العمليات =====
CREATE TABLE IF NOT EXISTS portal_users (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(120) NOT NULL,
  name        VARCHAR(120) NULL,
  role        VARCHAR(12)  NOT NULL,
  depts       TEXT NULL,
  note        VARCHAR(200) NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  added_by    VARCHAR(120) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login  DATETIME NULL,
  UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_blocked (
  email       VARCHAR(120) NOT NULL PRIMARY KEY,
  reason      VARCHAR(200) NULL,
  added_by    VARCHAR(120) NULL,
  created_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_sections (
  id          VARCHAR(40) NOT NULL PRIMARY KEY,
  system      VARCHAR(10) NOT NULL,
  name        VARCHAR(100) NOT NULL,
  url         VARCHAR(250) NOT NULL,
  match_re    VARCHAR(250) NOT NULL DEFAULT '',
  selector    VARCHAR(100) NOT NULL DEFAULT '',
  status      VARCHAR(12) NOT NULL DEFAULT 'visible',
  message     VARCHAR(250) NULL,
  depts_off   TEXT NULL,
  sort        INT NOT NULL DEFAULT 100,
  builtin     TINYINT(1) NOT NULL DEFAULT 0,
  updated_by  VARCHAR(120) NULL,
  updated_at  DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_news (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(150) NOT NULL,
  body        TEXT NULL,
  level       VARCHAR(8) NOT NULL DEFAULT 'info',
  systems     VARCHAR(8) NOT NULL DEFAULT 'all',
  depts       TEXT NULL,
  pinned      TINYINT(1) NOT NULL DEFAULT 0,
  starts      DATETIME NULL,
  ends        DATETIME NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_by  VARCHAR(120) NOT NULL,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  KEY k_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_audit (
  id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  at      DATETIME NOT NULL,
  email   VARCHAR(120) NOT NULL,
  action  VARCHAR(24) NOT NULL,
  target  VARCHAR(120) NULL,
  detail  VARCHAR(500) NULL,
  ip      VARCHAR(45) NULL,
  KEY k_at (at),
  KEY k_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_hits (
  day      DATE NOT NULL,
  section  VARCHAR(40) NOT NULL,
  dept     VARCHAR(40) NOT NULL DEFAULT '',
  n        INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, section, dept)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_hit_seen (
  k    CHAR(40) NOT NULL PRIMARY KEY,
  day  DATE NOT NULL,
  KEY k_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
