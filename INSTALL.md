# Sub2API-PHP 安装指南

## 一、环境要求

| 项目 | 要求 |
|---|---|
| PHP | 8.2 或更高 |
| 必需扩展 | pdo、curl、openssl、json、mbstring（MySQL 需 pdo_mysql，SQLite 需 pdo_sqlite） |
| 数据库 | MySQL 8.0+（推荐）或 SQLite 3 |
| Web 服务 | Apache（启用 mod_rewrite + AllowOverride All）或 Nginx（配置伪静态） |

## 二、上传与解压

1. 将 `sub2api-php-release.zip` 上传到网站空间（虚拟主机一般通过文件管理器上传）。
2. 解压到 **Web 根目录**（如 `public_html/`、`htdocs/`、`wwwroot/`）。
3. 确保以下目录可写（权限 755 或主机面板授予写权限）：
   - `data/`（配置与 SQLite 数据库存放处）

## 三、运行安装向导

浏览器访问 `http://你的域名/install/`：

1. **环境检测**：全部必需项显示"通过"后继续（缺少扩展请联系主机商）。
2. **数据库**：
   - MySQL：先在主机面板创建空数据库，再填主机/端口/库名/用户名/密码；
   - SQLite：直接选择（将在 `data/` 下创建 `sub2api.db`）。
   - 点击"测试连接并建表"，自动执行版本化迁移。
3. **管理员**：填写邮箱与密码（≥8 位）。管理员初始余额 100 USD，可后台调整。
4. **完成**：系统自动生成应用加密密钥（app_key）与 Cron Token，写入 `data/config.php` 并锁定安装向导。

> 重复访问 `/install/` 会显示"系统已安装"。如需重装：备份数据库后删除 `data/config.php`（会丢失应用密钥，加密存储的上游 Key 需重新录入）。

## 四、Nginx 伪静态（Apache 用户跳过）

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
# 禁止访问敏感文件
location ~* \.(sql|db|sqlite|log|md|lock)$ { deny all; }
location ~* ^/(data|app|docs)/ { deny all; }
```

## 五、安装后配置

1. 访问 `/` 登录管理后台。
2. **上游渠道**：新建渠道
   - DeepSeek：类型选 "DeepSeek 官方"，Base URL 填 `https://api.deepseek.com`，填入 API Key，模型填 `deepseek-chat,deepseek-reasoner`（以官方文档为准，可随时修改）。
   - 点击"测试"验证连通性。
3. **模型价格**：核对模型单价（USD / 每百万 Token，输入价/输出价分开）。安装时已写入 DeepSeek 价格模板，请按官方最新价格调整。
4. **分组倍率**：默认分组倍率 1.0；可为不同用户组设置折扣/加价倍率。
5. **创建用户与 Key**：管理员在"用户管理"创建用户；用户登录后在"我的 Key"创建密钥（AES-256-GCM 加密存储，列表中可随时点击"查看"获取明文）。

## 六、Cron 定时任务（可选但推荐）

任务中心包含渠道健康检测、账务孤儿记录整理、日志清理。

- 有 SSH：`crontab -e` 添加（每 5 分钟）：
  ```
  */5 * * * * php /path/to/sub2api/cron.php 你的cron_token
  ```
- 无 SSH：在任意在线监控/云函数中定时请求：
  ```
  curl "http://你的域名/cron.php?token=你的cron_token"
  ```
  cron_token 在后台"定时任务"页可见。

无 Cron 时系统仍可正常提供 API 转发与计费，仅任务中心需手动触发。

## 七、客户端接入

任何 OpenAI 兼容客户端（ChatGPT-Next-Web、LobeChat、OpenAI SDK 等）：

- Base URL：`http://你的域名/v1`
- API Key：`sk-s2a-...`

Anthropic 兼容客户端：Base URL `http://你的域名`，路径 `/v1/messages`（需配置 Anthropic 兼容渠道）。

## 八、常见问题

| 问题 | 处理 |
|---|---|
| 安装页提示扩展缺失 | 联系主机商开启对应 PHP 扩展 |
| API 返回 404 | Apache 确认 .htaccess 生效（AllowOverride All）；Nginx 配置伪静态 |
| 流式无输出 | 部分主机有输出缓冲，确认未开启 gzip 压缩缓冲；本程序已发送 X-Accel-Buffering: no |
| 模型不可用 | 渠道未启用 / 模型未填入渠道 / 渠道与分组绑定关系不匹配 |
| 扣费为 0 | 未在"模型价格"配置该模型单价 |
