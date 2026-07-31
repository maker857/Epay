# SEC-02 SQL 注入整改测试记录

## 整改范围

已将用户输入改为 PDO 参数绑定或白名单字段的文件：

- `submit2.php`
- `cashier.php`
- `getshop.php`
- `api.php`
- `includes/lib/api/Pay.php`
- `admin/ajax_user.php`
- `user/ajax2.php`
- `admin/ajax_order.php`
- `admin/ajax_transfer.php`
- `admin/ajax_profitsharing.php`
- `admin/ajax_pay.php`
- `admin/ajax_settle.php`
- `admin/download.php`
- `admin/invitecode.php`
- `user/download.php`
- `includes/lib/api/Merchant.php`

## 已执行验证

| 验证 | 命令 | 结果 |
| --- | --- | --- |
| 静态危险片段扫描 | `docker compose run --rm app php tests/SqlInjectionRegressionTest.php` | 通过 |
| PostgreSQL 参数绑定 | `docker compose run --rm app php tests/SqlParameterBindingPostgresTest.php` | 通过：`OR 1=1`、注释符和单引号载荷未扩大结果集 |
| 目标文件语法检查 | `docker compose run --rm app php -l ...` | 通过 |
| SEC-01 回归 | `docker compose run --rm app php tests/PaymentCallbackIdempotencyTest.php` 等四项 | 全部通过（在包含 `install.lock` 的新镜像中执行） |
| HTTP 恶意订单号 | `getshop.php?trade_no=missing%27%20OR%201%3D1%20--` | 与随机不存在订单返回一致，未命中任意订单 |

## 全仓扫描结论

- `rg -n '\{\$_(GET|POST|REQUEST)' --glob '*.php' --glob '!tests/**'` 未发现请求参数直接插入 SQL 的路径。
- 动态字段名和排序字段均来自固定白名单映射；分页和时间间隔片段在拼接前转换为整数。
- `plugins/alipaycode/server.php` 的剩余插值来自服务端加载的支付通道 `subid` 配置，不是请求参数；该路径保留为低风险后续加固项。

## 验收结论

SEC-02 涉及的已识别用户输入 SQL 拼接路径已完成参数化或白名单约束，静态回归和 PostgreSQL 实际绑定测试通过，可标记为已修复。SEC-01 回调回归也已在包含 `install.lock` 的新 Docker 镜像中全部通过。
