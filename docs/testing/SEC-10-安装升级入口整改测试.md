# SEC-10 安装升级入口整改测试

## 整改内容

- 新增 `install/.htaccess`，由 Apache 对整个 `/install/` 目录返回 403。
- Docker 镜像启用 `AllowOverride All`，确保阻断规则生效。
- 传统 `install/update.php` 不再作为公网升级入口。
- PostgreSQL schema 升级继续由 `docker/epay-entrypoint.sh` 调用 `docker/init-postgres.php` 执行。

测试/修复提交：`3663213`（`fix: block public install and legacy upgrade endpoints`）

## 自动化测试

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/InstallEndpointSecurityTest.php
```

结果：通过。

## Docker 验证

```powershell
docker compose build app
docker compose up -d --force-recreate app
docker compose ps
curl.exe -sS -o NUL -w "INSTALL_STATUS=%{http_code}\n" http://127.0.0.1:8090/install/
curl.exe -sS -o NUL -w "UPDATE_STATUS=%{http_code}\n" http://127.0.0.1:8090/install/update.php
```

结果：

- `epay-app-1` 和 `epay-db-1` 均为 healthy。
- `/install/` 返回 403。
- `/install/update.php` 返回 403。
- 容器 PostgreSQL 迁移入口保持可用，重复启动不会重复执行已完成迁移。

## 已知限制

源码目录中的旧升级脚本仍保留用于历史兼容，但在 Docker/Apache 部署中不可通过 HTTP 访问；后续可在发布流程中进一步移除旧脚本。
