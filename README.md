# Sub2API-PHP

Sub2API-PHP 是 [Wei-Shaw/sub2api](https://github.com/Wei-Shaw/sub2api)（Go + PostgreSQL + Redis）核心功能的 **PHP 重新实现版**，专为普通 PHP 虚拟主机设计：

- 无需 VPS / root / Docker / Redis / Node.js / Composer
- 上传解压 → 访问安装向导 → 3 分钟完成部署
- PHP 8.2+，MySQL 8（推荐）或 SQLite 3
- 零 Composer 运行时依赖（纯 PHP 标准库）

## 核心功能

| 模块 | 说明 |
|---|---|
| API 网关 | `GET /v1/models`、`POST /v1/chat/completions`（OpenAI 兼容）、`POST /v1/messages`（Anthropic 兼容），流式 SSE 真实逐块转发 |
| 上游渠道 | DeepSeek 官方 / 通用 OpenAI 兼容 / Anthropic 兼容；模型映射、优先级轮询、故障切换、连通性测试 |
| 计费 | 按上游真实 usage × 模型价格 × 分组倍率扣费；事务保护、幂等结算、防负余额、防重复扣费 |
| 用户系统 | 管理员/普通用户、余额、兑换码、消费流水 |
| API Key | 创建时一次性展示、仅存哈希、配额/限流/有效期/轮换 |
| 管理后台 | 仪表盘、用户、Key、渠道、分组、模型价格、请求日志、流水、任务、审计、环境状态（全中文 SPA） |
| 定时任务 | 渠道检测 / 账务整理 / 日志清理；Cron 或外部 HTTP 触发（token 鉴权） |

## 快速开始

1. 上传 `sub2api-php-release.zip` 到网站空间并解压到 Web 根目录
2. 浏览器访问 `http://你的域名/install/`
3. 按向导完成：环境检测 → 数据库 → 管理员 → 完成
4. 登录后台，配置 DeepSeek 渠道（Base URL `https://api.deepseek.com` + API Key）
5. 创建 API Key，客户端接入：

```bash
curl http://你的域名/v1/chat/completions \
  -H "Authorization: Bearer sk-s2a-你的Key" \
  -H "Content-Type: application/json" \
  -d '{"model":"deepseek-chat","messages":[{"role":"user","content":"你好"}]}'
```

详细步骤见 [INSTALL.md](INSTALL.md)；升级见 [UPGRADE.md](UPGRADE.md)；备份恢复见 [BACKUP_RESTORE.md](BACKUP_RESTORE.md)。

## 兼容性说明

原版基于 OAuth 订阅账号与常驻进程调度，本版本实现的是 **API Key 型上游**的完整闭环（DeepSeek / OpenAI 兼容 / Anthropic 兼容）。OAuth 订阅适配、PostgreSQL、Redis 限流等功能不做伪装实现，详见 [docs/KNOWN_LIMITATIONS.md](docs/KNOWN_LIMITATIONS.md) 与 [docs/UPSTREAM_FEATURE_AUDIT.md](docs/UPSTREAM_FEATURE_AUDIT.md)。

## 安全特性

- PDO 参数化查询；密码 bcrypt；上游 Key AES-256-GCM 加密；用户 Key 仅存 SHA-256 哈希
- Session HttpOnly + SameSite=Lax；管理接口 CSRF 校验；登录失败限制
- 上游 Base URL SSRF 防护（默认拒绝私网地址）；管理操作审计日志；敏感目录 .htaccess 保护

## 许可

- 本项目遵循 LGPL-3.0 兼容方式发布，上游版权声明见 [LICENSE.THIRD_PARTY](LICENSE.THIRD_PARTY)
- 上游项目 Sub2API：Copyright (c) Wei-Shaw and contributors，LGPL-3.0
