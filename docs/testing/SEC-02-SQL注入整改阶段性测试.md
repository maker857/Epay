# SEC-02 SQL 注入整改阶段性测试

## 本阶段范围

已将用户输入改为 PDO 参数绑定或白名单字段的文件：

- `submit2.php`
- `cashier.php`
- `getshop.php`
- `api.php`
- `includes/lib/api/Pay.php`
- `admin/ajax_user.php`
- `user/ajax2.php`

## 已执行验证

| 验证 | 命令 | 结果 |
| --- | --- | --- |
| 静态危险片段扫描 | `docker compose run --rm app php tests/SqlInjectionRegressionTest.php` | 通过 |
| PostgreSQL 参数绑定 | `docker compose run --rm app php tests/SqlParameterBindingPostgresTest.php` | 通过：`OR 1=1`、注释符和单引号载荷未扩大结果集 |
| 目标文件语法检查 | `docker compose run --rm app php -l ...` | 通过 |
| SEC-01 回归 | 幂等、并发、异常回滚和 PostgreSQL 插入测试 | 全部通过 |
| HTTP 恶意订单号 | `getshop.php?trade_no=missing%27%20OR%201%3D1%20--` | 与随机不存在订单返回一致，未命中任意订单 |

## 未完成范围

全仓扫描仍发现 `admin/ajax_order.php`、`admin/ajax_transfer.php`、`admin/ajax_profitsharing.php`、`admin/download.php`、`user/download.php`、`includes/lib/api/Merchant.php` 等路径存在动态 SQL 片段，因此 SEC-02 当前只能标记为“进行中”，不能作为最终验收完成。
