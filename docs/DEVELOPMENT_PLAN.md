# Sub2API-PHP 开发计划

## 技术决策
- PHP 8.2+，零 Composer 运行时依赖（纯标准库：PDO/cURL/OpenSSL/JSON/mbstring），生产包无需 composer install。
- 数据库：MySQL 8（推荐）与 SQLite 3（可选），双套版本化迁移（migrations/mysql、migrations/sqlite），migrator 记录版本。
- 前端：原生 JavaScript SPA（hash 路由）+ CSS，无需 npm build，静态资源直接打包。
- 路由：单入口 index.php；Apache .htaccess 重写；不支持重写的主机回退到 PATH_INFO / index.php?r=。
- 会话：PHP Session（HttpOnly/SameSite=Lax），CSRF Token 校验；登录失败限制存数据库。
- 密钥：上游 Key AES-256-GCM 加密存储；用户 API Key 仅存 SHA-256 哈希+前缀。
- 计费：事务内 `UPDATE ... WHERE balance >= cost` 防负余额；usage_logs.request_id 唯一约束防重复扣费；请求级账务状态字段 billing_status。

## 模块清单与验收标准
| 模块 | 验收 |
|---|---|
| 安装向导 install/ | 环境检测→建库→管理员→密钥→锁定，重复安装被拒 |
| 用户/权限 | 登录、CSRF、角色隔离、失败限制，集成测试通过 |
| API Key | 创建/启停/删除/轮换/有效期/配额，哈希存储 |
| 渠道管理 | DeepSeek + OpenAI 兼容，加密存储、连通性测试、轮询/故障切换 |
| 网关 /v1/models, /v1/chat/completions, /v1/messages | 非流式+真实 SSE 转发，Mock 上游测试 |
| 计费 | usage 落库、余额扣减、并发/重复请求事务测试 |
| 管理后台 | 仪表盘/用户/Key/渠道/分组/模型价格/日志/设置/审计/任务，全部真实接口 |
| 定时任务 | cron.php 鉴权触发 + 后台手动执行 + 历史记录 |
| 测试 | tests/run.php：迁移、权限、网关、计费、Mock 上游，P0 全通过 |
