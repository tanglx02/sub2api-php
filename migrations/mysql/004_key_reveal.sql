-- 004: API Key 支持随时查看（加密存储明文）
ALTER TABLE api_keys ADD COLUMN key_enc TEXT NULL;
