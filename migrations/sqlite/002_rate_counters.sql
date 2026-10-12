CREATE TABLE IF NOT EXISTS rate_counters (
    key_id INTEGER NOT NULL,
    window_key TEXT NOT NULL,
    cnt INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (key_id, window_key)
);
