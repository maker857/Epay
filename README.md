# 彩虹易支付系统

**彩虹易支付系统** 由郑州追梦网络科技有限公司开发，是一款开源的免签约支付产品，能够帮助开发者一站式接入支付宝、微信、财付通、QQ钱包等多种支付方式，实现高效的支付集成。

---

## 功能特色

- **多渠道支付集成**：支持支付宝、微信、财付通、QQ钱包、微信WAP、银联等多种支付方式  
- **便捷的支付解决方案**：简化支付流程，支持快速集成和上线，提供完整的 API 接口  
- **后台管理和数据统计**：提供支付统计、代付统计、利润分析等多种后台管理功能  
- **安全可靠**：采用 RSA 公私钥验证，支持风控检测和黑名单管理  
- **插件扩展**：支持丰富的支付插件，可根据需求灵活扩展  
- **移动端优化**：全新的手机版支付页面，支持各种移动端支付场景  

---

## 更新日志

### 2026/02/28
1. 新增 H5 跳转微信小程序客服支付  

### 2026/02/23
1. 新增抖音支付  
2. 部分间连支付分账规则支持选择实时和延迟分账  
3. 新增发起支付地区屏蔽设置  

### 2026/01/28
1. 后台登录增加 TOTP 二次验证  
2. 新增校验扫码 IP 所在地与下单 IP 所在地是否一致功能  
3. 非官方微信支付插件可开启扫码支付前快捷登录，用于判断黑名单  
4. 支付宝当面付支付前快捷登录已支持所有非官方支付插件  
5. 增加获取微信小程序用户标识功能  
6. 用户组增加更多配置项  
7. 中转代理增加代理 API 的方式  
8. 优化随机增减金额逻辑  
9. 修改获取银行卡信息接口  

---

## 推荐插件

推荐使用 **Bepusdt** 插件进行 USDT（TRC20）收款。  
Bepusdt 是适用于彩虹易支付系统的 USDT 收款插件，收到的货币直接转入商户钱包，不经过任何第三方。

**插件开源地址**：  
🔗 [https://github.com/v03413/bepusdt](https://github.com/v03413/bepusdt)

---

## Docker 部署教程

Docker 版本包含以下服务：

- `app`：PHP 8.3 + Apache，运行易支付程序
- `db`：PostgreSQL 17，存储系统配置、商户和订单数据
- `epay-postgres`：PostgreSQL 数据卷，容器重启或重新构建后数据仍然保留

首次启动时，应用会等待 PostgreSQL 就绪，自动转换并导入 `install/install.sql`，创建数据表、系统密钥和管理员配置。因此不需要手动安装 PostgreSQL、创建数据库或访问 `/install/`。

### 1. 环境要求

- Linux、Windows 或 macOS
- Docker Engine 24+ 或 Docker Desktop
- Docker Compose V2（使用 `docker compose` 命令）
- 建议至少 2 GB 可用内存和 2 GB 磁盘空间

Ubuntu 尚未安装 Docker 或遇到 `Unable to locate package docker-compose-plugin` 时，请参考：[Ubuntu Docker Compose 安装指南](docs/docker-compose-install.md)。

确认 Docker 可用：

```bash
docker --version
docker compose version
```

### 2. 获取项目

```bash
git clone https://github.com/maker857/Epay.git
cd Epay
```

### 3. 配置环境变量

Linux 或 macOS：

```bash
cp .env.example .env
```

Windows PowerShell：

```powershell
Copy-Item .env.example .env
```

编辑 `.env`：

```dotenv
APP_PORT=8090
POSTGRES_DB=epay
POSTGRES_USER=epay
POSTGRES_PASSWORD=请替换为足够长的随机数据库密码
DB_PREFIX=pay
ADMIN_USER=admin
ADMIN_PASSWORD=请替换为高强度后台登录密码
ADMIN_PAY_PASSWORD=请替换为高强度支付操作密码
```

Ubuntu 可使用以下命令生成 64 位十六进制随机密码：

```bash
openssl rand -hex 32
```

建议分别执行三次，将生成结果依次填写到 `POSTGRES_PASSWORD`、`ADMIN_PASSWORD` 和 `ADMIN_PAY_PASSWORD`，不要让三个密码使用相同的值。

变量说明：

| 变量 | 用途 |
| --- | --- |
| `APP_PORT` | 宿主机本地监听端口，默认 `8090` |
| `POSTGRES_DB` | PostgreSQL 数据库名 |
| `POSTGRES_USER` | PostgreSQL 用户名 |
| `POSTGRES_PASSWORD` | PostgreSQL 密码 |
| `DB_PREFIX` | 数据表前缀，默认 `pay` |
| `ADMIN_USER` | 后台管理员账号 |
| `ADMIN_PASSWORD` | 后台登录密码 |
| `ADMIN_PAY_PASSWORD` | 后台支付操作密码 |

`.env` 包含敏感信息并已被 `.gitignore` 排除，不要将它提交到 Git 或发送给其他人。管理员配置只在数据库首次初始化时写入；数据库已经存在后修改 `.env` 不会自动重置后台密码。

### 4. 启动服务

```bash
docker compose up -d --build
```

首次构建需要下载 PHP 和 PostgreSQL 镜像，耗时取决于网络速度。查看运行状态：

```bash
docker compose ps
```

正常情况下，`app` 和 `db` 都会显示为 `Up` 或 `healthy`。

### 5. 访问系统

- 本机网站首页：`http://127.0.0.1:8090/`
- 本机管理后台：`http://127.0.0.1:8090/admin/`

如果修改了 `APP_PORT`，请将地址中的 `8090` 替换成实际端口。Compose 默认将端口绑定到 `127.0.0.1`，公网无法直接访问，需要通过 aaPanel、Nginx 或 Caddy 反向代理。不要在云服务器安全组或防火墙中开放 `8090` 和 `5432`。

### 6. 使用 aaPanel 配置域名和 HTTPS

以下示例假设：

- 域名：`pay.example.com`
- 项目目录：`/www/wwwroot/epay`
- Docker 本地监听地址：`http://127.0.0.1:8090`

#### 6.1 配置域名解析

在域名服务商处添加 A 记录，将 `pay.example.com` 指向服务器公网 IP。等待解析生效后，可使用以下命令确认：

```bash
ping pay.example.com
```

#### 6.2 在服务器启动容器

通过 aaPanel 的终端或 SSH 执行：

```bash
cd /www/wwwroot/epay
cp .env.example .env
```

修改 `.env` 中的数据库密码、管理员密码和支付密码，然后启动：

```bash
docker compose up -d --build
docker compose ps
curl -I http://127.0.0.1:8090/
```

`curl` 返回 `HTTP/1.1 200 OK` 后再配置反向代理。

#### 6.3 在 aaPanel 创建网站

1. 进入 aaPanel 的 `Website` 页面，选择 `Add site`。
2. 域名填写 `pay.example.com`。
3. PHP 版本选择 `Static`，因为 PHP 已在 Docker 容器中运行。
4. 不需要在 aaPanel 中创建 MySQL 或 PostgreSQL 数据库。
5. 保存网站配置。

#### 6.4 添加反向代理

进入刚创建的网站，打开 `Reverse Proxy`，添加代理：

| 配置项 | 值 |
| --- | --- |
| 代理名称 | `epay` |
| 目标 URL | `http://127.0.0.1:8090` |
| 发送域名 | `$host` 或当前域名 |
| 内容替换 | 留空 |

如果 aaPanel 提供自定义代理配置，请确认包含以下请求头：

```nginx
location / {
    proxy_pass http://127.0.0.1:8090;
    proxy_http_version 1.1;

    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    proxy_read_timeout 300s;
    proxy_connect_timeout 60s;
    proxy_send_timeout 300s;

    client_max_body_size 20m;
    proxy_buffering off;
}
```

> aaPanel 的图形化反向代理规则与上面的完整 `location /` 配置二选一即可。不要同时添加两份 `location /`，否则 Nginx 配置检查会因重复定义而失败。支付页面建议关闭 aaPanel 的代理缓存。

`X-Forwarded-Proto` 必须保留，应用会用它识别 aaPanel 前端的 HTTPS 请求并生成正确的支付回调地址。

#### 6.5 配置 SSL

1. 打开网站的 `SSL` 页面。
2. 选择 `Let's Encrypt` 并为 `pay.example.com` 申请证书。
3. 证书部署成功后开启 `Force HTTPS`。
4. 访问 `https://pay.example.com/` 和 `https://pay.example.com/admin/` 验证页面。

如果使用 Cloudflare，应将 SSL/TLS 模式设置为 `Full (strict)`，不要使用 `Flexible`。

#### 6.6 防火墙建议

公网只需开放：

- `80/tcp`：HTTP，用于跳转 HTTPS 和证书验证
- `443/tcp`：HTTPS
- aaPanel 管理端口：建议仅允许管理员固定 IP 访问

不要开放：

- `8090/tcp`：Docker 应用本地端口
- `5432/tcp`：PostgreSQL 端口

配置完成后的请求链路为：

```text
用户 -> HTTPS 443 -> aaPanel Nginx -> 127.0.0.1:8090 -> Docker app -> Docker PostgreSQL
```

### 7. 日常管理

查看全部日志：

```bash
docker compose logs -f
```

分别查看应用和数据库日志：

```bash
docker compose logs -f app
docker compose logs -f db
```

停止并删除容器，但保留数据库：

```bash
docker compose down
```

重新启动：

```bash
docker compose up -d
```

修改 PHP 代码或 Dockerfile 后重新构建：

```bash
docker compose up -d --build
```

### 8. 数据库备份与恢复

创建 PostgreSQL 自定义格式备份：

```bash
docker compose exec -T db pg_dump -U epay -d epay --format=custom --file=/tmp/epay.dump
docker compose cp db:/tmp/epay.dump ./epay.dump
```

如果修改过 `POSTGRES_USER` 或 `POSTGRES_DB`，请替换命令中的用户名和数据库名。

恢复备份前先停止应用，避免恢复过程中继续写入订单：

```bash
docker compose stop app
docker compose cp ./epay.dump db:/tmp/epay.dump
docker compose exec -T db pg_restore -U epay -d epay --clean --if-exists /tmp/epay.dump
docker compose start app
```

### 9. 更新项目

更新前应先备份数据库，然后执行：

```bash
git pull
docker compose up -d --build
```

数据库自动导入只在空数据库首次启动时执行，不会在更新镜像时清空现有数据。如果新版本包含数据库结构更新，请先阅读对应版本说明并确认更新脚本兼容 PostgreSQL。

### 10. 重置整个环境

以下命令会删除容器和 PostgreSQL 数据卷，所有商户、订单及系统配置都会永久丢失：

```bash
docker compose down -v
docker compose up -d --build
```

仅在确认已有备份并确实需要全新安装时使用 `docker compose down -v`。

### 11. 常见问题

端口已被占用：修改 `.env` 中的 `APP_PORT`，例如改为 `8091`，同步修改 aaPanel 的反向代理目标，然后重新运行 `docker compose up -d`。

aaPanel 显示 `502 Bad Gateway`：确认 `docker compose ps` 中 `app` 为健康状态，并在服务器执行 `curl -I http://127.0.0.1:8090/`。如果 `.env` 使用了其他端口，aaPanel 的目标 URL 必须保持一致。

HTTPS 页面仍生成 HTTP 链接：检查反向代理是否传递 `X-Forwarded-Proto $scheme`，然后重新加载 aaPanel 的 Nginx 配置。

应用持续重启：使用 `docker compose logs --tail=100 app` 查看初始化或数据库连接错误，并确认 `.env` 中所有必填密码均已配置。

数据库未自动初始化：确认 `db` 服务状态为 `healthy`，再查看 `docker compose logs app`。初始化成功后 PostgreSQL 中应有 29 张业务表。

无法从宿主机连接 PostgreSQL：这是预期行为。数据库端口没有暴露到宿主机，只允许 `app` 容器通过 Docker 内部网络访问。

---
