# Sub2API 上游功能审计（基于 Wei-Shaw/sub2api，LGPL-3.0，2026-10 检出）

上游架构：Go 1.27 后端 + Vue3/TS 前端 + PostgreSQL 15 + Redis 7 + Docker。

## 数据模型（migrations/001_init.sql + ent/schema）
核心实体：users、groups（费率倍率）、accounts（上游账号，platform/type/credentials 加密 JSONB）、
api_keys、account_groups（调度多对多）、usage_logs（4 类 token + 各项成本 + 实际扣除）、
redeem_codes、settings、announcements、proxies。
→ PHP 版本沿用 users/groups/channels(≈accounts)/api_keys/usage_logs/settings/redeem_codes/audit_logs 模型，改为 MySQL/SQLite 双方言。

## API 协议
- OpenAI 兼容：GET /v1/models、POST /v1/chat/completions（流式/非流式）。
- Anthropic：POST /v1/messages（流式/非流式）。
- 认证：Bearer sk-xxx → 用户 API Key。
→ PHP 完整实现；每协议独立适配器。

## 上游适配器
上游支持 anthropic/openai/gemini 平台的 oauth 与 apikey 两种账号类型，含会话粘性、代理、指纹、批量任务、Claude Code 检测等。
→ PHP 第一版支持 API Key 型 DeepSeek / 通用 OpenAI 兼容 / Anthropic 兼容渠道。
→ OAuth/订阅账号类适配器依赖常驻进程刷新 Token 与非公开授权流程，PHP 虚拟主机无守护进程，不做伪装实现（见 KNOWN_LIMITATIONS）。

## 计费
上游按 usage 真实字段 × 模型价格 × 分组倍率计费，缓存 token 单独计价。
→ PHP 同思路：模型价格表（input/output 单价）× 分组倍率，优先上游 usage；无 usage 时估算并标记 source=estimated。

## PHP 版本不支持/降级的项（全部如实记录于 KNOWN_LIMITATIONS）
- Redis 缓存/限流 → 改为数据库限流（窗口计数），精度受并发影响。
- 常驻调度器 → Cron/外部触发 + 任务中心。
- OAuth 上游账号、TLS 指纹、批量图像任务、支付网关、邮件注册验证等 → 暂不支持。
- PostgreSQL 特性（分区、数组列）→ MySQL/SQLite 等价实现。

## 许可
上游 LGPL-3.0。本项目为重新实现（不复制其源码），遵循 LGPL-3.0 兼容方式发布，保留原项目版权声明于 LICENSE.THIRD_PARTY。
