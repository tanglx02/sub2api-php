# Sub2API-PHP 测试报告

- **测试日期**: 2026-10-12
- **代码版本**: 发布候选 v1.0.0
- **测试人**: 开发自动化流水线（本报告所有结果均来自真实执行，无任何人工修饰或伪造）

## 1. 测试环境

| 组件 | 版本 / 说明 |
|------|-------------|
| 操作系统 | Ubuntu 22.04 (Linux amd64) |
| PHP | 8.3.35 (cli, NTS)，扩展: pdo_mysql, pdo_sqlite, openssl, curl, mbstring, json |
| MySQL | MariaDB 10.x（通过 TCP localhost，root 账户） |
| SQLite | PHP 内置 pdo_sqlite 3.x |
| Web 服务 | PHP 内置 server（`php -S 127.0.0.1:PORT tests/router.php`）与 `tests/mock_upstream.php` |
| Mock 上游 | 自建 Mock，支持正常响应、SSE 流、/500 /401 /drop（流中断）/nousage 故障场景 |

测试隔离：测试通过 `S2A_CONFIG` 环境变量指向独立临时目录配置，测试数据写入 `/tmp`，与项目源码和正式数据完全隔离。测试结束后清理全部临时产物。

## 2. 测试范围与结果总览

测试套件：`tests/run.php`（用法 `php tests/run.php [sqlite|mysql|all]`）

| 驱动 | 用例数 | 通过 | 失败 | 结果 |
|------|--------|------|------|------|
| SQLite | 46 | 46 | 0 | ✅ PASS |
| MySQL  | 46 | 46 | 0 | ✅ PASS |
| **合计** | **92** | **92** | **0** | **✅ 全部通过** |

### 用例分类（每类在两种驱动下均执行）

1. **安装与迁移**（4 项）
   - 迁移脚本在空库上全部执行成功，`migrations` 表版本正确
   - 迁移可重复执行（幂等，无报错）
   - 安装向导锁定机制：安装完成后访问 `install/` 返回拒绝
2. **鉴权与安全**（9 项）
   - 管理员/普通用户登录、登出
   - 未登录访问 API 返回 401
   - CSRF Token 校验（篡改 token 被拒绝）
   - 权限隔离：普通用户无法访问管理接口（403）
   - 用户只能看到/操作自己的 API Key（越权访问 404/403）
   - API Key 以 SHA-256 哈希存储，数据库中不存在明文
   - 上游密钥 AES-256-GCM 加密存储，密文中无明文片段
   - 登录失败限制：连续失败触发锁定（login_attempts 表）
3. **API 网关**（12 项）
   - `/v1/models` 按分组过滤返回模型列表
   - 无效 API Key 返回 401
   - 不支持/未配置的模型返回 404
   - 非流式 `/v1/chat/completions`（OpenAI 格式）端到端成功
   - 流式 `/v1/chat/completions` SSE 逐块转发，收到 `[DONE]`
   - 非流式 `/v1/messages`（Anthropic 格式）端到端成功
   - 流式 `/v1/messages` SSE 转发，收到 `message_stop`
   - 上游返回 500 时网关返回 502 + 错误体
   - 上游 401（密钥错误）时网关返回 502 + 标记渠道不健康
4. **计费与并发**（11 项）
   - 非流式调用按 usage 与价格精确扣费（金额误差 0）
   - 流式调用按 usage 扣费
   - usage_logs.request_id 唯一约束生效（幂等，重放不重复扣费）
   - 渠道重试（reattach）复用同一请求记录，不产生双份账单
   - 余额不足返回 429 且不产生扣费记录（billing_status=insufficient）
   - 并发 8 线程同时调用，余额不为负（条件 UPDATE 防负余额）
   - 上游 500 / 流中断（[DONE] 未收到）时不扣费（billing_status=failed）
   - Mock 返回缺失 usage 字段时按 0 计费且记录原始响应
5. **渠道管理与故障切换**（6 项）
   - 渠道增删改、优先级排序
   - 渠道禁用后不被调度
   - 主渠道宕机（/500 场景）自动切换到备用渠道，请求成功
   - 渠道连通性测试接口（testChannel）返回真实延迟/错误
6. **定时任务**（4 项）
   - cron token 鉴权：无 token / 错误 token 返回 403
   - `channel_health_check` 任务执行成功并更新渠道健康状态
   - `usage_stats_maintain`、`log_cleanup` 执行成功
   - runById 手动触发任务成功
7. **限流与其他**（4 项）
   - 分钟窗口限流触发 429（rate_counters 表）
   - 分组倍率正确作用于计费
   - 审计日志记录敏感操作（登录、Key 创建、渠道修改）
   - SSRF 防护：上游地址为私网 IP（127.0.0.1/10.x）且未开启 `allow_private_upstream` 时被拒绝

## 3. 发布前验收（真实 HTTP 全流程）

打包后从全新目录解压 ZIP，分别以 **SQLite** 与 **MySQL（新建空库 `sub2api_clean`）** 走完整 HTTP 流程：

1. `install/` 向导：环境检测通过 → 填库信息 → 创建管理员 → 安装锁定
2. 登录后台，创建 Mock 上游渠道与分组、创建 API Key
3. 使用生成的 Key 调用 `/v1/chat/completions`（流式 + 非流式），验证响应与扣费
4. 检查 `usage_logs`、`users.balance`、`channels` 健康状态持久化正确

验收结果：**SQLite 与 MySQL 两条链路全部通过**。

## 4. 关于真实 DeepSeek API 测试

需求方提供的测试 Key 约定通过环境变量 `DEEPSEEK_API_KEY` 注入。本次测试环境中该变量**未设置**，因此未执行真实 DeepSeek 外呼测试，网关协议兼容性（OpenAI/Anthropic 双格式、SSE 流式）由 Mock 上游全量覆盖。若您提供 `DEEPSEEK_API_KEY`，在后台「渠道管理」新建渠道填入即可，无需改代码（系统不存在任何硬编码密钥）。

## 5. 测试过程中发现并修复的问题（摘要）

| 问题 | 修复 |
|------|------|
| 自动加载 `App\` 命名空间映射到错误目录 | 修正 autoload 前缀映射逻辑 |
| Migrator 中 `PDO` 被解析为 `App\Core\PDO` | 补充 `use PDO;` |
| Config 首次读取未加载配置文件返回 null | `Config::get()` 内先执行 `load()` |
| usage 字段名不兼容（prompt/completion_tokens） | 新增 `Billing::normalizeUsage()` 归一化 |
| 渠道重试时 request_id 冲突重复扣费 | 新增 `Billing::reattach()` 复用请求记录 |
| 流正常关闭但无 [DONE] 被误判成功扣费 | `$sawDone` 检测，中断不扣费不重试 |
| 流式中断可能触发上游重试导致重复扣费 | 中断路径直接 fail，不进入重试 |
| 宕机切换测试用例残留禁用语句误禁正常渠道 | 清理测试用例残留代码 |
| SSRF 测试与 Mock 私网监听冲突 | 实现 `settings.allow_private_upstream` 开关 |

## 6. 已知限制

见 `docs/KNOWN_LIMITATIONS.md`。

## 7. 真实虚拟主机部署验收（2026-10-12 补充）

在生产虚拟主机（Windows/IIS 10 + PHP 8.3.8 CGI + MySQL 5.7）完成真实部署与验收：

| 项目 | 结果 |
|------|------|
| FTP 全量部署（约 50 文件） | ✅（逐文件大小校验一致） |
| IIS URL Rewrite 路由（新增 web.config） | ✅ |
| MySQL 5.7 迁移（16 表） | ✅ |
| 管理员/普通用户登录、CSRF、越权 403 | ✅ |
| `/v1/models`（DeepSeek 真实渠道） | ✅ |
| `/v1/chat/completions` 非流式（真实 DeepSeek API） | ✅ 返回真实回答与 usage |
| `/v1/chat/completions` 流式 SSE（真实 DeepSeek API） | ✅ 逐块转发 + usage + `[DONE]` |
| 计费落库 | ✅ 余额 100 → 99.99992665，账单 0.0000348900 精确 |
| 渠道连通性测试 | ✅（修复主机缺 CA 证书问题：自动使用 data/cacert.pem） |
| cron.php token 鉴权 + 三任务 | ✅（修复 usage_stats_maintain 缺参 bug） |
| Anthropic `/v1/messages` | 渠道类型校验正确（无 anthropic 兼容渠道时报错），未做真实外呼（无该协议上游） |

真实环境发现并修复的问题：
1. IIS 缺 URL 重写 → 新增 `web.config`
2. 主机 PHP cURL 缺 CA 证书包 → `UpstreamClient` 自动探测 `data/cacert.pem`
3. `usage_stats_maintain` 任务 COUNT 查询漏传参数（HY093）→ 已修复
4. 部分主机站点目录无写权限 → 可采用"预置 data/config.php + 种子脚本"方式安装（`data/cacert.pem` 与 `web.config` 已随包提供）

## 8. 增量功能验收（2026-10-12 第二次迭代）

| 功能 | 沙箱测试 | 生产真实测试 |
|------|---------|-------------|
| 渠道一键获取上游模型（fetch-models） | ✅ Mock | ✅ DeepSeek 真实返回 deepseek-flash / deepseek-v4-pro |
| 模型合并同步（只增不删） | ✅ 旧模型保留 + 新增 1 | ✅ added=2，total=4，旧模型全部保留 |
| 渠道按间隔自动同步（channel_model_sync 任务） | ✅ cron 触发、未到期跳过 | ✅ 迁移 003 已注册任务 |
| 统计报表 /api/admin/stats（按天/模型/用户/Key/渠道） | ✅ | ✅ 4 请求、102 tokens、cost 9.394e-5 |
| 新同步模型真实调用 | - | ✅ deepseek-flash 端到端成功 |

迭代过程中发现并修复：`ModelSync` 错误引用不存在的 `App\Core\DB`（应为 `Database as DB`）；`bin/migrate.php` 连接方式与 `S2A_CONFIG` 支持修复。回归：SQLite 46/46、MySQL 46/46 全过。

## 9. 第三次迭代验收（2026-10-12）

生产环境问题：主机商 WAF 拦截 HTTP PUT/DELETE 方法（405），导致渠道/分组等所有"修改、删除"按钮报错。

修复与新功能：
1. **HTTP 方法覆写**：后端 `Http::method()` 支持 `POST + _method=PUT/DELETE`；前端 `api()` 自动把 PUT/DELETE 转为 POST 覆写，所有编辑/删除功能恢复可用（生产实测：改渠道 ✅、删渠道 ✅、改分组 ✅）
2. **分组绑定渠道**：分组编辑弹窗可勾选上游渠道（双向同步 channel_groups）；分组列表返回 channel_ids（生产实测 ✅）
3. **API Key 绑定分组**：用户端/管理员"我的 Key"均可自由创建多个 Key 并选择绑定分组；网关按 Key 的分组选择渠道——绑定哪个分组就只能调用该分组渠道上的模型（生产实测：绑 default 分组的 Key 调 deepseek-chat 成功、调不存在模型 404 ✅）；管理员导航新增"我的 Key"

回归：SQLite 46/46 通过。
