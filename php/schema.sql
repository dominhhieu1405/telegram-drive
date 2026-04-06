CREATE TABLE IF NOT EXISTS api_keys (
  `key` VARCHAR(80) PRIMARY KEY,
  label VARCHAR(120) NOT NULL,
  created_at BIGINT NOT NULL,
  created_by VARCHAR(80) DEFAULT 'admin',
  usage_count INT NOT NULL DEFAULT 0,
  last_used BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usage_stats (
  id VARCHAR(64) PRIMARY KEY,
  uploads INT NOT NULL DEFAULT 0,
  total_bytes BIGINT NOT NULL DEFAULT 0,
  api_uploads INT NOT NULL DEFAULT 0,
  last_upload BIGINT NULL,
  last_file_name VARCHAR(255) NULL,
  last_file_type VARCHAR(120) NULL,
  user_agent TEXT NULL,
  country VARCHAR(8) NULL,
  device VARCHAR(120) NULL,
  browser VARCHAR(255) NULL,
  ip_hash VARCHAR(32) NULL,
  window_start BIGINT NOT NULL,
  window_count INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS uploads (
  encoded_id VARCHAR(255) PRIMARY KEY,
  file_id VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  file_type VARCHAR(120) NOT NULL,
  file_size BIGINT NOT NULL,
  uploaded_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
