# SEC-09 可信反向代理 IP 整改测试

## 整改内容

- `real_ip()` 默认只返回 `REMOTE_ADDR`，不再无条件信任 `X-Forwarded-For`、`X-Real-IP`、`CF-Connecting-IP` 或 `Client-IP`。
- 只有当 `REMOTE_ADDR` 命中 `EPAY_TRUSTED_PROXY_IPS`（支持 IP 和 CIDR）时，才解析代理头。
- 转发链中的私有、回环、链路本地和保留地址会被跳过。
- 登录限流、日志、订单和风控继续统一使用 `clientip`，因此共享同一可信解析结果。

## 测试

测试提交：`1acbb7d`（`test: cover trusted proxy IP parsing`）

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/TrustedProxyIpSecurityTest.php
```

结果：通过。

- 未配置可信代理时，伪造转发头不能改变客户端 IP。
- 配置 `127.0.0.1` 或 `127.0.0.0/8` 后，可信代理可以传递公网客户端 IP。
- 私有转发地址会被拒绝。

## aaPanel/Nginx 配置

应用容器或 PHP 进程配置环境变量，例如：

```dotenv
EPAY_TRUSTED_PROXY_IPS=127.0.0.1,::1
```

Nginx 反向代理应覆盖客户端传入的代理头：

```nginx
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
```

如果 aaPanel/Nginx 不在本机，请将 `EPAY_TRUSTED_PROXY_IPS` 改为实际代理服务器 IP 或 CIDR，不要填写任意公网网段。

## Docker 验证

PHP 语法检查、CSRF 回归、SQL 注入回归和 Docker 健康检查均通过。

## 已知限制

Cloudflare 使用时，应将其官方出口 IP 网段维护到 `EPAY_TRUSTED_PROXY_IPS`，并通过 Cloudflare 官方网段更新机制定期更新。
