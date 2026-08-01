# SEC-12 依赖与容器权限整改测试

## 整改目标

锁定 Composer 依赖版本，确保镜像构建使用锁文件；将应用容器设置为只读根文件系统、最小 Linux capabilities 和临时可写目录，同时保留上传与插件日志的必要写入能力。

## TDD 证据

- 红灯测试提交：`b3dd196`（缺少 `includes/composer.lock` 时测试失败）
- 生产修复提交：`ed0f9a9`（`fix: harden dependency installation and container runtime`）

## 验证记录

| 场景 | 验证命令 | 结果 |
|---|---|---|
| 锁文件和容器加固静态检查 | `docker compose run --rm -v "${PWD}:/var/www/html" app php tests/Sec12ContainerHardeningTest.php` | PASS |
| Composer 配置校验 | `docker run --rm -v "${PWD}/includes:/app" -w /app composer:2.8 validate --no-check-publish` | PASS |
| 构建时按锁文件安装 | `docker compose build app` | PASS |
| 依赖漏洞扫描 | `docker run --rm -v "${PWD}/includes:/app" -w /app composer:2.8 audit --format=json --no-interaction` | 已执行；发现 `mdanter/ecc` 的 CVE-2024-33851 及 critical 侧信道公告，已记录残余风险 |
| 只读根文件系统与 capabilities | `docker inspect epay-app-1 --format ...` | PASS：只读根、`no-new-privileges:true`、`CapDrop=ALL`，仅添加 `CHOWN` 和 `NET_BIND_SERVICE` |
| Docker 服务健康检查 | `docker compose up -d --force-recreate app; docker compose ps` | PASS：应用和 PostgreSQL 均为 `healthy` |
| 支付回调回归 | `docker compose run --rm app php tests/PaymentCallbackIdempotencyTest.php` 等三项 | PASS |
| 安全响应头 | `Invoke-WebRequest http://127.0.0.1:8090/index.php` | PASS：包含 CSP、X-Content-Type-Options、Referrer-Policy、Permissions-Policy |

## 修复保证

- Docker 构建通过 `composer install --no-dev` 从 `includes/composer.lock` 安装依赖。
- 应用容器启用只读根文件系统、`no-new-privileges` 和 capability 白名单。
- `/tmp`、Apache 运行目录和日志目录使用受限 tmpfs；上传和插件日志使用独立 Docker volume。
- Apache 添加 CSP、MIME 嗅探、来源和权限策略响应头。

## 残余风险

`lpilp/guomi` 依赖已 abandoned 的 `mdanter/ecc`，Composer 审计报告 CVE-2024-33851 及 critical 侧信道公告。由于当前多个支付插件直接依赖 `Rtgm` SM2/SM4 API，贸然替换为不兼容库会破坏支付功能；后续应在替代库完成兼容性验证后单独升级。
