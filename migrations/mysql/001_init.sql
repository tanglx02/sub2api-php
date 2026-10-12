-- Sub2API-PHP v1 初始化（MySQL 8.0+）
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    balance DECIMAL(20,8) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    concurrency INT NOT NULL DEFAULT 5,
    total_spent DECIMAL(20,8) NOT NULL DEFAULT 0,
    request_count BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    rate_multiplier DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(40) NOT NULL DEFAULT 'openai_compatible',
    base_url VARCHAR(500) NOT NULL,
    api_key_enc TEXT NOT NULL,
    models JSON NULL,
    model_mapping JSON NULL,
    priority INT NOT NULL DEFAULT 50,
    weight INT NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    connect_timeout INT NOT NULL DEFAULT 10,
    request_timeout INT NOT NULL DEFAULT 300,
    last_error TEXT NULL,
    last_used_at DATETIME NULL,
    last_test_at DATETIME NULL,
    last_test_ok TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_channels_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channel_groups (
    channel_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (channel_id, group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    key_hash CHAR(64) NOT NULL UNIQUE,
    key_prefix VARCHAR(16) NOT NULL,
    name VARCHAR(100) NOT NULL,
    group_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    expires_at DATETIME NULL,
    quota_limit DECIMAL(20,8) NOT NULL DEFAULT 0,
    quota_used DECIMAL(20,8) NOT NULL DEFAULT 0,
    rate_limit_rpm INT NOT NULL DEFAULT 0,
    request_count BIGINT NOT NULL DEFAULT 0,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_keys_user (user_id),
    INDEX idx_api_keys_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usage_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    api_key_id BIGINT UNSIGNED NOT NULL,
    channel_id BIGINT UNSIGNED NULL,
    model VARCHAR(100) NOT NULL,
    upstream_model VARCHAR(100) NULL,
    input_tokens INT NOT NULL DEFAULT 0,
    output_tokens INT NOT NULL DEFAULT 0,
    total_tokens INT NOT NULL DEFAULT 0,
    input_cost DECIMAL(20,10) NOT NULL DEFAULT 0,
    output_cost DECIMAL(20,10) NOT NULL DEFAULT 0,
    total_cost DECIMAL(20,10) NOT NULL DEFAULT 0,
    actual_cost DECIMAL(20,10) NOT NULL DEFAULT 0,
    group_multiplier DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    stream TINYINT NOT NULL DEFAULT 0,
    duration_ms INT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    billing_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    usage_source VARCHAR(20) NOT NULL DEFAULT 'upstream',
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usage_request (request_id),
    INDEX idx_usage_user_created (user_id, created_at),
    INDEX idx_usage_model (model),
    INDEX idx_usage_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS balance_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(20) NOT NULL,
    amount DECIMAL(20,8) NOT NULL,
    balance_after DECIMAL(20,8) NOT NULL,
    ref_request_id VARCHAR(64) NULL,
    note VARCHAR(255) NULL,
    operator_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tx_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_prices (
    model VARCHAR(100) PRIMARY KEY,
    input_price DECIMAL(20,10) NOT NULL DEFAULT 0,
    output_price DECIMAL(20,10) NOT NULL DEFAULT 0,
    enabled TINYINT NOT NULL DEFAULT 1,
    note VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    skey VARCHAR(100) PRIMARY KEY,
    svalue TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redeem_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL UNIQUE,
    value DECIMAL(20,8) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'unused',
    used_by BIGINT UNSIGNED NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50) NULL,
    target_id BIGINT UNSIGNED NULL,
    detail TEXT NULL,
    ip VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(64) NOT NULL,
    email VARCHAR(190) NOT NULL,
    success TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempt (ip, email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    handler VARCHAR(100) NOT NULL,
    enabled TINYINT NOT NULL DEFAULT 1,
    interval_minutes INT NOT NULL DEFAULT 60,
    last_run_at DATETIME NULL,
    next_run_at DATETIME NULL,
    last_status VARCHAR(20) NULL,
    last_error TEXT NULL,
    run_count INT NOT NULL DEFAULT 0,
    fail_count INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL,
    message TEXT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
