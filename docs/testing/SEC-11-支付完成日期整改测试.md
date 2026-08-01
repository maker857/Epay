# SEC-11 支付完成日期整改测试

## 整改目标

修复支付回调处理完成时间时写错变量导致订单日期未更新的问题，并确保非法或空完成时间不会覆盖已有日期。

## TDD 证据

- 红灯测试提交：`4822acf`（`test: reproduce payment completion date bug`）
- 生产修复提交：`f032721`（`fix: normalize payment completion dates`）

## 验证记录

| 场景 | 验证命令 | 结果 |
|---|---|---|
| 合法完成时间写入 `endtime/date` | `docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PaymentCompletionDateTest.php` | PASS：输出 `Payment completion date tests passed.` |
| 非法、空完成时间不覆盖原日期 | 同上 | PASS |
| 静态检查不再使用错误的 `$date['date']` | 同上 | PASS |
| `Payment.php` PHP 语法 | `docker compose run --rm -v "${PWD}:/var/www/html" app php -l includes/lib/Payment.php` | PASS |
| 支付回调幂等性回归 | `docker compose run --rm app php tests/PaymentCallbackIdempotencyTest.php` | PASS：输出 `Payment callback idempotency test passed.` |
| 支付回调并发回归 | `docker compose run --rm app php tests/PaymentCallbackConcurrencyTest.php` | PASS：输出 `Payment callback concurrency test passed.` |
| 支付回调事务回滚回归 | `docker compose run --rm app php tests/PaymentCallbackRollbackTest.php` | PASS：输出 `Payment callback rollback test passed.` |
| Docker 镜像构建 | `docker compose build app` | PASS |
| Docker 服务健康检查 | `docker compose up -d --force-recreate app; docker compose ps` | PASS：`epay-app-1`、`epay-db-1` 均为 `healthy` |

## 修复保证

`normalizeCompletionTime()` 对空值和 `strtotime()` 失败返回 `null`；只有合法完成时间才会合并到订单更新数据，因此不会以 `1970-01-01` 或空值覆盖原日期。支付回调原有幂等、并发和事务回归测试保持通过。

## 已知范围

项目当前没有统一的 PHPUnit 覆盖率配置；本次使用针对性单元测试、回调回归测试、语法检查和 Docker 健康检查完成验收。
