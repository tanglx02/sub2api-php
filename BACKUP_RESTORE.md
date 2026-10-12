# Sub2API-PHP 备份与恢复指南

## 需要备份的内容

| 内容 | 位置 | 说明 |
|---|---|---|
| 配置 | `data/config.php` | **最重要**：app_key 是上游密钥的解密依据，丢失后所有渠道 Key 无法解密 |
| 数据库 | MySQL 库 或 `data/sub2api.db` | 用户、渠道、日志、账务全在此 |
| 可选 | `data/` 下其他文件 | 日志等可重建 |

## MySQL 备份

```bash
# 备份
mysqldump -h 主机 -u 用户 -p 数据库名 > backup_$(date +%F).sql

# 恢复
mysql -h 主机 -u 用户 -p 数据库名 < backup_2026-01-01.sql
```

无 SSH 的虚拟主机：使用 phpMyAdmin（主机面板一般内置）导出/导入。

## SQLite 备份

```bash
cp data/sub2api.db backup_$(date +%F).db
# 恢复：停止访问后覆盖回原位置
```

phpMyAdmin 不适用 SQLite，可用主机文件管理器直接下载 `data/sub2api.db`（WAL 模式下建议同时下载 `sub2api.db-wal`，或先在后台停用服务再备份）。

## 完整恢复步骤

1. 恢复 `data/config.php`（含 app_key）。
2. 恢复数据库。
3. 确认文件权限可写。
4. 访问后台"环境状态"页确认数据库连接与迁移版本。

## 安全提醒

- 备份文件包含**加密后的上游密钥与用户数据**，切勿放在 Web 可下载目录；下载到本地保管。
- `data/config.php` 与数据库分开存放时，仅凭数据库无法还原上游密钥。
