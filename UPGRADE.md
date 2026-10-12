# Sub2API-PHP 升级指南

## 升级原理

- 数据库结构通过**版本化迁移**管理（`migrations/mysql/`、`migrations/sqlite/`），`migrations` 表记录已应用版本。
- 应用启动时**不自动改表**，升级需手动触发迁移（见下）。

## 标准升级步骤

1. **备份**（必做）：见 BACKUP_RESTORE.md。
2. 下载新版 ZIP，解压覆盖旧文件（**不要覆盖 `data/` 目录**——其中是配置与数据库）。
3. 执行迁移（二选一）：
   - CLI：`php bin/migrate.php`
   - 或临时访问 `http://你的域名/install/upgrade.php`（需输入 data/config.php 中的 cron_token），完成后该文件自动失效。
4. 访问后台"环境状态"页核对已应用迁移版本。
5. 如新版引入新设置项，在"系统设置"页按需调整。

## 回滚

1. 恢复备份的文件与数据库（见 BACKUP_RESTORE.md）。
2. 迁移仅前进不回退；如需回退数据库结构，请用升级前的完整备份恢复。
