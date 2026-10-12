CREATE TABLE IF NOT EXISTS rate_counters (
    key_id BIGINT UNSIGNED NOT NULL,
    window_key VARCHAR(20) NOT NULL,
    cnt BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (key_id, window_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
