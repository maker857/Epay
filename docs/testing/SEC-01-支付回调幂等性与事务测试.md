# SEC-01 支付回调幂等性与事务测试

## 修复范围

- `includes/lib/Payment.php`
  - 回调开始时开启事务并按订单号 `FOR UPDATE` 锁定、重新读取订单。
  - 仅允许状态 `0` 或 `4` 的订单进入入账流程。
  - 使用 `status IN (0,4)` 的条件更新抢占处理权。
  - 资金变更、流水和订单状态纳入同一事务；异常时回滚本次新增的事务层级。
- `includes/lib/PdoHelper.php`
  - 增加嵌套事务保存点支持。
  - PostgreSQL 插入 ID 按实际主键序列读取，兼容 `uid` 等非 `id` 主键表。

## 测试记录

| 测试 | 命令 | 结果 |
| --- | --- | --- |
| PHP 语法：PdoHelper | `docker compose run --rm app php -l includes/lib/PdoHelper.php` | 通过 |
| PHP 语法：Payment | `docker compose run --rm app php -l includes/lib/Payment.php` | 通过 |
| 顺序重复回调 | `docker compose run --rm app php tests/PaymentCallbackIdempotencyTest.php` | 通过：余额只增加一次、只生成一条流水 |
| 并发重复回调 | `docker compose run --rm app php tests/PaymentCallbackConcurrencyTest.php` | 通过：两个独立进程只完成一次入账 |
| 异常回滚 | `docker compose run --rm app php tests/PaymentCallbackRollbackTest.php` | 通过：订单状态恢复为 0，未留下流水 |
| PostgreSQL 插入回归 | `docker compose run --rm app php tests/PdoHelperPostgresInsertTest.php` | 通过：无序列主键表和 `id` 序列表均正常 |
| Docker 健康检查 | `docker compose ps` | 通过：app 与 db 均 healthy |

## 结论

SEC-01 的代码修复及回归验证已完成。后续若调整订单状态或资金流水逻辑，应重新执行上述测试。
