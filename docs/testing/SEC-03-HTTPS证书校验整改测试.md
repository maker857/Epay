# SEC-03 HTTPS 证书校验整改测试记录

## 整改内容

- 将项目公共 cURL 方法和所有随项目发布的支付、短信、实名、OCR SDK 请求中的 `CURLOPT_SSL_VERIFYPEER` 设置为 `true`。
- 将 `CURLOPT_SSL_VERIFYHOST` 设置为 `2`，启用主机名校验。
- Docker 镜像显式安装 `ca-certificates`，使用系统 CA 证书包。

## TDD 证据

| 阶段 | 命令 | 结果 |
| --- | --- | --- |
| RED | `docker compose run --rm -v "${PWD}:/var/www/html" app php tests/TlsVerificationRegressionTest.php` | 失败，发现公共函数、支付插件和内置 SDK 共计多处关闭校验 |
| GREEN 静态回归 | 同上 | `TLS verification regression checks passed.` |
| GREEN 集成回归 | `docker compose run --rm -v "${PWD}:/var/www/html" app php tests/TlsCertificateValidationTest.php` | `TLS certificate validation integration test passed.`；`https://example.com` 成功，自签名证书地址被拒绝 |
| PHP 语法检查 | 对全部修改 PHP 文件执行 `php -l` | 全部通过 |
| Docker 验证 | `docker compose build app`、`docker compose up -d --force-recreate app`、`docker compose ps` | app 与 db 均 healthy |

## 扫描结论

`rg -n "CURLOPT_SSL_VERIFYPEER\\s*,\\s*false|CURLOPT_SSL_VERIFYHOST\\s*,\\s*(false|0|1)\\b" --glob '*.php'` 未发现剩余关闭或弱化 TLS 校验的代码。

## 已知限制

集成测试依赖外部 `example.com` 和 `self-signed.badssl.com`，用于验证容器网络和系统 CA 行为；支付机构专用证书/双向 TLS 如有需求，应在对应插件配置中单独提供受控 CA 或客户端证书，不得重新关闭主机名和证书校验。
