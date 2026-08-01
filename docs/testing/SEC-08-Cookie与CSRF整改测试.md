# SEC-08 Cookie 与 CSRF 整改测试

## 整改内容

- Session 启用 strict mode，并设置 `HttpOnly`、`SameSite=Lax`、路径和 HTTPS Secure 属性。
- 管理员和商户认证 Cookie 统一通过 `secure_setcookie()` 设置。
- 新增基于 `random_bytes()` 的 CSRF Token，并使用 `hash_equals()` 校验。
- 管理员登录、TOTP 登录、TOTP 配置、商户登录 AJAX 和插件刷新接入 CSRF 校验。
- 支付插件刷新从 GET 链接改为 POST 表单，GET 请求不再执行刷新。
- 登录成功后继续重新生成 Session ID。

测试提交：`36109f0`（`test: cover CSRF and session security`）

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/CsrfSessionSecurityTest.php
```

结果：通过。随机 Token 可验证，伪造 Token 被拒绝，安全 Cookie 配置和插件 POST/CSRF 约束均通过。

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php -l includes/common.php
docker compose run --rm -v "${PWD}:/var/www/html" app php -l admin/login.php
docker compose run --rm -v "${PWD}:/var/www/html" app php -l admin/set_totp.php
docker compose run --rm -v "${PWD}:/var/www/html" app php -l admin/pay_plugin.php
docker compose run --rm -v "${PWD}:/var/www/html" app php -l user/login.php
docker compose run --rm -v "${PWD}:/var/www/html" app php -l user/ajax.php
```

结果：全部通过。XSS 和 SQL 注入回归测试也通过。

## 已知限制

部分历史页面仍自行生成 CSRF 隐藏字段，后续应逐步统一改为 `csrf_token()`；反向代理可信范围由 SEC-09 单独处理。
