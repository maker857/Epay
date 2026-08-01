# SEC-06 PayPal Webhook SSRF 整改测试

## 整改范围

- 新增 `PayPalWebhookSecurity`，集中校验 PayPal Webhook 证书地址。
- 只允许 `https://api-m.paypal.com/v1/notifications/certs/` 和 `https://api-m.sandbox.paypal.com/v1/notifications/certs/` 路径前缀。
- 拒绝非 HTTPS、非官方域名、端口注入、用户名密码注入、非证书路径和私有/保留 IP。
- 拉取证书时禁用重定向，开启 TLS 证书与主机名校验，限制连接超时、总超时和响应大小。
- DNS 解析后要求所有 A/AAAA 地址均为公网地址，并通过 `CURLOPT_RESOLVE` 固定本次连接 IP，降低 DNS rebinding 风险。
- Webhook 签名使用严格 `base64_decode(..., true)`，证书下载和解析异常统一返回签名验证失败，避免泄露内部错误。

## RED 测试

提交：`88bb180`（`test: reproduce PayPal webhook SSRF`）

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PayPalWebhookSecurityTest.php
```

结果：失败，原因是 `PayPalWebhookSecurity` 类尚不存在，复现 PayPal Webhook 证书 URL 缺少安全校验的问题。

## GREEN 测试

提交：`1c8f2ac`（`fix: restrict PayPal webhook certificate URL`）

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PayPalWebhookSecurityTest.php
```

结果：通过，官方 PayPal 证书 URL 被允许，HTTP、非官方域名、后缀欺骗、非 443 端口、用户信息注入、非证书路径、回环地址和私有/保留 IP 被拒绝。

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php -l plugins/paypal/paypal_plugin.php
```

结果：通过，`plugins/paypal/paypal_plugin.php` 无 PHP 语法错误。

## 回归测试

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/SqlInjectionRegressionTest.php
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/TlsVerificationRegressionTest.php
```

结果：均通过。

## Docker 验证

```powershell
docker compose build app
docker compose up -d --force-recreate app
docker compose ps
docker compose logs --no-color app --tail 80
```

结果：镜像构建成功，`epay-app-1` 与 `epay-db-1` 均为 healthy，应用继续监听 `127.0.0.1:8090->80/tcp`。

## 已知限制

- 当前没有真实 PayPal 生产或沙箱 Webhook 签名样本，因此未执行 PayPal 端到端签名回调验证。
- 本次测试覆盖 URL 白名单、私有/保留 IP 拒绝、证书拉取实现约束和既有安全回归；上线后建议使用 PayPal 沙箱 Webhook 事件再做一次真实回调验证。
