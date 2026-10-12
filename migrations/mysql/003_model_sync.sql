-- 渠道模型自动同步
ALTER TABLE channels ADD COLUMN model_sync_interval INT NOT NULL DEFAULT 0;
ALTER TABLE channels ADD COLUMN last_model_sync_at DATETIME NULL;
INSERT INTO tasks (name, handler, enabled, interval_minutes, next_run_at)
SELECT '渠道模型同步', 'channel_model_sync', 1, 30, UTC_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM tasks WHERE handler = 'channel_model_sync');
