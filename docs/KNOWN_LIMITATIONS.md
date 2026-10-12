# 未实现功能与 PHP 空间环境限制

以下内容**未实现或降级实现**，均如实记录，不做伪装：

## 1. 上游适配器
- **OAuth / 订阅账号型上游**（原版支持 Anthropic/Claude OAuth、ChatGPT 订阅号等）：依赖常驻进程刷新 Token 与非公开授权流程，虚拟主机无守护进程，且此类接入可能违反上游服务条款。本版仅支持 **API Key 型**上游（DeepSeek 官方、通用 OpenAI 兼容、Anthropic 兼容）。
- **会话粘性 / 账号级并发调度**：多账号会话保持依赖内存/Redis，本版按渠道（非账号）粒度轮询。
- **TLS 指纹 / 代理池**：未实现。

## 2. 协议
- **跨协议转换**：OpenAI 协议请求转发到 OpenAI 兼容渠道、Anthropic 协议转发到 Anthropic 兼容渠道（同协议透传）。不做 OpenAI↔Anthropic 互相转换。
- 其他原版接口（`/v1/complete`、Gemini 原生协议、batch、实时 API）未实现。

## 3. 架构降级
- **Redis** → 数据库分钟窗口限流（rate_counters 表），高并发下精度略低但正确。
- **常驻调度器** → Cron / 外部 HTTP 触发任务中心；无 Cron 时任务需手动触发，不影响 API 主链路。
- **PostgreSQL**（分区表、数组列）→ MySQL 8 / SQLite 等价实现。
- **WebSocket / SSE 服务端推送管理事件** → 前端轮询。

## 4. 功能裁剪
- 支付网关（易支付等）、邮件注册验证、OAuth 登录（GitHub/LinuxDo）、TOTP 两步验证、公告系统、模型广场、渠道监控大盘（V2）、用户属性自定义 → 未实现。
- 兑换码已实现（充值面值）；订阅计划制未实现。

## 5. 环境依赖说明
- SSE 流式转发依赖 Web 服务器不缓冲输出；Apache 一般可用（已发送 X-Accel-Buffering: no），个别主机如有 gzip 输出缓冲需关闭。
- 数据库限流与并发扣费依赖数据库事务；SQLite 已启用 WAL + busy_timeout，并发能力有限（建议并发 ≤ 数十）。
- 请求超时受 PHP max_execution_time 限制，虚拟主机通常 30–120 秒；长流式响应请在后台把渠道请求超时设置为主机允许范围内。

## 6. 与原版行为差异
- 模型价格单位为 USD/1M tokens（输入/输出分开），默认写入 DeepSeek 价格模板，需按官方文档自行维护。
- 无 usage 的响应按字符数 /4 估算 token，并在日志中标 `usage_source=estimated`，不冒充精确计量。
