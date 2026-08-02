# Database Logic Remediation Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** 修复审计报告 `DB-01` 至 `DB-09`，保证支付、余额和转账数据库操作具备严格事务一致性，并恢复 PostgreSQL 下的完整业务兼容性。

**Architecture:** 先强化 `PdoHelper` 的事务和错误传播，再让余额与支付回调失败即抛异常。转账改成持久化预留、事务外调用平台、短事务完成或退款的状态机；SQL 方言、表前缀和旧库升级分别通过兼容测试和幂等迁移修复。

**Tech Stack:** PHP 7.4+、PDO、PostgreSQL 17、Docker Compose、项目现有单文件 PHP 回归测试。

---

### Task 1: 修复 `PdoHelper` 错误状态与事务结果传播（DB-09）

**Files:**

- Create: `tests/PdoHelperTransactionStateTest.php`
- Modify: `includes/lib/PdoHelper.php:283-348`
- Modify: `includes/lib/PdoHelper.php:442-531`

**Step 1: 写失败测试**

测试以下行为：

- 一条失败 SQL 后执行成功 SQL，`error()` 必须变为 `null`。
- 事务中的 SQL 失败后，`transaction()` 不能返回业务成功。
- 回滚后事务深度恢复为调用前的值。
- 嵌套保存点失败不会破坏外层事务深度。

**Step 2: 运行测试并确认失败**

Run:

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PdoHelperTransactionStateTest.php
```

Expected: FAIL，旧错误仍被保留，或提交失败未传播。

**Step 3: 实现最小修复**

- 在 `exec()`、`query()` 开始时清空 `$errorInfo`。
- 为事务开始、提交、回滚和保存点失败保存当前 PDO 错误。
- `transaction()` 检查 `commit()` 和 `rollBack()`，失败时抛出 `RuntimeException`。
- 保持现有嵌套事务深度语义。

**Step 4: 运行测试和现有 PDO 测试**

Run:

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PdoHelperTransactionStateTest.php
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PdoHelperPostgresInsertTest.php
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/SqlParameterBindingPostgresTest.php
```

Expected: PASS。

**Step 5: 提交**

```powershell
git add includes/lib/PdoHelper.php tests/PdoHelperTransactionStateTest.php
git commit -m "fix: propagate database transaction failures"
```

### Task 2: 让余额和流水成为原子操作（DB-02）

**Files:**

- Create: `tests/UserMoneyTransactionTest.php`
- Modify: `includes/functions.php:847-881`
- Modify: `tests/PaymentCallbackRollbackTest.php`

**Step 1: 写失败测试**

覆盖：

- 不存在的 UID 必须抛出异常且不产生流水。
- 强制资金流水插入失败时，用户余额保持不变。
- `changeUserMoney()` 嵌套在外层事务时只回滚自己的保存点。
- `changeUserMoney2()` 在用户更新或流水插入失败时抛出异常。
- 正常加款和扣款同时更新余额及流水。

**Step 2: 运行测试并确认失败**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/UserMoneyTransactionTest.php
```

Expected: FAIL，当前函数忽略商户不存在、流水失败或提交失败。

**Step 3: 实现最小修复**

- 记录进入函数前的事务深度。
- 检查事务开始、用户查询、余额更新、流水插入和提交结果。
- 余额更新必须影响一行。
- 任意失败回滚到原事务深度并抛出 `RuntimeException`。
- `changeUserMoney2()` 要求调用方已开启事务并持有有效旧余额。
- 金额小于等于零返回 `true`。

**Step 4: 运行余额和支付回滚测试**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/UserMoneyTransactionTest.php
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PaymentCallbackRollbackTest.php
```

Expected: PASS。

**Step 5: 提交**

```powershell
git add includes/functions.php tests/UserMoneyTransactionTest.php tests/PaymentCallbackRollbackTest.php
git commit -m "fix: make balance updates atomic"
```

### Task 3: 严格传播支付回调数据库错误（DB-03）

**Files:**

- Create: `tests/PaymentCallbackWriteFailureTest.php`
- Modify: `includes/lib/Payment.php:354-415`
- Modify: `includes/functions.php:650-842`
- Modify: `tests/PaymentCallbackRollbackTest.php`

**Step 1: 写失败测试**

分别强制以下操作失败并断言订单回滚到未支付：

- 订单完成字段更新失败。
- 余额或资金流水写入失败。
- 分账订单插入失败。
- 最终提交失败。

测试必须确认没有残留余额、流水或分账记录。

**Step 2: 运行测试并确认失败**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PaymentCallbackWriteFailureTest.php
```

Expected: FAIL，当前部分写入结果未被检查。

**Step 3: 实现最小修复**

- 检查 `Payment::processOrder()` 内所有 `$DB->update()` 结果。
- 让全局 `processOrder()` 的关键数据库写入失败时抛出异常。
- 错误信息包含订单号和处理阶段。
- 保持重复回调和并发回调逻辑不变。

**Step 4: 运行支付回调测试组**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PaymentCallbackWriteFailureTest.php
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PaymentCallbackRollbackTest.php
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PaymentCallbackIdempotencyTest.php
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PaymentCallbackConcurrencyTest.php
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PaymentCompletionDateTest.php
```

Expected: PASS。

**Step 5: 提交**

```powershell
git add includes/lib/Payment.php includes/functions.php tests/PaymentCallbackWriteFailureTest.php tests/PaymentCallbackRollbackTest.php
git commit -m "fix: fail payment callbacks on database write errors"
```

### Task 4: 将普通转账改为可对账状态机（DB-01）

**Files:**

- Create: `tests/TransferTransactionStateTest.php`
- Modify: `includes/lib/Transfer.php:35-180`
- Modify: `includes/lib/Transfer.php:149-225`
- Modify: `admin/ajax_transfer.php`
- Modify: `user/ajax2.php`

**Step 1: 写失败测试**

通过可注入的外部提交 callable 或最小测试替身覆盖：

- 外部调用前已经存在 `status=0` 转账记录并完成一次扣款。
- 同一 `out_biz_no + uid` 重试不会再次扣款或调用平台。
- 外部明确失败时在同一事务中标记失败并退回一次余额。
- 外部成功但本地完成写入失败时保留 `status=0` 记录。
- 主动查询恢复成功后不会重复退款或扣款。
- 外部调用期间数据库不处于事务中。

**Step 2: 运行测试并确认失败**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/TransferTransactionStateTest.php
```

Expected: FAIL，当前外部调用发生在事务内且记录在外部成功后才创建。

**Step 3: 实现本地预留阶段**

- 开启短事务并锁定商户。
- 校验余额和代付权限。
- 插入 `status=0` 转账记录。
- 使用 `changeUserMoney2()` 扣款并提交。
- 提交成功后才调用外部平台。

**Step 4: 实现完成、失败和不确定结果处理**

- 成功或处理中结果使用新短事务更新记录。
- 明确失败使用新短事务锁定记录、标记失败并退款。
- 外部异常或本地完成失败保持 `status=0`，在 `result` 或错误日志中记录待查询原因。
- 查询恢复路径使用条件状态更新，保证只退款或完成一次。

**Step 5: 运行测试**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/TransferTransactionStateTest.php
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/UserMoneyTransactionTest.php
```

Expected: PASS。

**Step 6: 提交**

```powershell
git add includes/lib/Transfer.php admin/ajax_transfer.php user/ajax2.php tests/TransferTransactionStateTest.php
git commit -m "fix: persist transfers before external submission"
```

### Task 5: 修复红包转账与转账查询事务（DB-01）

**Files:**

- Extend: `tests/TransferTransactionStateTest.php`
- Modify: `includes/lib/Transfer.php:260-382`
- Modify: `paypage/wxtrans.php`

**Step 1: 写失败测试**

覆盖红包创建、领取和查询恢复中的事务开始、记录更新、退款及提交失败，确认不会返回虚假成功。

**Step 2: 运行测试并确认失败**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/TransferTransactionStateTest.php
```

Expected: FAIL。

**Step 3: 实现最小修复**

- 红包创建先原子插入并扣款，再提交。
- 红包领取外部调用不持有数据库长事务。
- 查询状态更新与退款采用条件更新和严格事务结果检查。

**Step 4: 运行测试并提交**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/TransferTransactionStateTest.php
git add includes/lib/Transfer.php paypage/wxtrans.php tests/TransferTransactionStateTest.php
git commit -m "fix: make red packet transfers recoverable"
```

### Task 6: 修复 PostgreSQL 业务 SQL 兼容问题（DB-04、DB-06、DB-07）

**Files:**

- Create: `tests/PostgresBusinessSqlCompatibilityTest.php`
- Modify: `cron.php:192-258`
- Modify: `includes/lib/Channel.php:284`
- Modify: `admin/ajax_user.php:47-50`
- Modify: `admin/ajax_order.php:181-196`
- Modify: `admin/ajax_profitsharing.php:348-357`

**Step 1: 写失败测试**

使用临时表或事务验证：

- 回调重试时间范围查询可执行并选中正确订单。
- 随机通道兜底可执行。
- 不活跃商户时间筛选可执行。
- 后台订单和分账状态更新可执行且报告真实影响行数。

**Step 2: 运行测试并确认失败**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PostgresBusinessSqlCompatibilityTest.php
```

Expected: FAIL，分别出现 `TO_DAYS`、`RAND`、interval 或 `UPDATE LIMIT` 错误。

**Step 3: 实现最小修复**

- 回调重试改为 `endtime >= CURRENT_TIMESTAMP - INTERVAL '1 day'` 的可移植表达方式或绑定起始时间。
- 随机函数根据数据库驱动选择。
- 不活跃筛选绑定 PHP 计算后的起始日期。
- 移除 `UPDATE LIMIT` 并检查实际返回值。
- 定时查询失败时记录错误并停止，不把失败解释为空结果。

**Step 4: 运行测试并提交**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PostgresBusinessSqlCompatibilityTest.php
git add cron.php includes/lib/Channel.php admin/ajax_user.php admin/ajax_order.php admin/ajax_profitsharing.php tests/PostgresBusinessSqlCompatibilityTest.php
git commit -m "fix: restore postgres business query compatibility"
```

### Task 7: 修复自定义表前缀（DB-08）

**Files:**

- Create: `tests/DatabasePrefixCompatibilityTest.php`
- Modify: `cron.php:96`
- Modify: `admin/ajax_user.php:311-317`
- Modify: `install/update.php:20`

**Step 1: 写失败测试**

创建随机非默认前缀表，验证相关 SQL 不再访问 `pay_order`、`pay_blacklist`、`pay_wxkflog` 或 `pay_config`。

**Step 2: 运行测试并确认失败**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/DatabasePrefixCompatibilityTest.php
```

Expected: FAIL，静态扫描或实际查询发现硬编码前缀。

**Step 3: 实现最小修复**

- 运行时代码改为 `pre_` 逻辑前缀。
- 升级入口使用配置中的实际前缀。
- 保留默认 `pay` 前缀行为。

**Step 4: 运行测试并提交**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/DatabasePrefixCompatibilityTest.php
git add cron.php admin/ajax_user.php install/update.php tests/DatabasePrefixCompatibilityTest.php
git commit -m "fix: honor custom database prefixes"
```

### Task 8: 建立完整幂等 PostgreSQL 旧库升级（DB-05）

**Files:**

- Create: `docker/PostgresMigrations.php`
- Create: `tests/PostgresSchemaUpgradeTest.php`
- Modify: `docker/init-postgres.php:84-118`
- Reference: `install/install.sql`
- Reference: `install/update2.sql`
- Reference: `install/update3.sql`

**Step 1: 写失败测试**

测试创建代表旧版本的最小数据库结构，执行升级后验证关键业务表、字段、索引和版本号。再次执行升级必须无错误且结构不重复。

至少验证：

- `subchannel`、`suborder`。
- `transfer`、`refundorder`。
- `psreceiver`、`psorder`。
- 订单、商户、通道和插件的关键新增字段。
- 任一步失败时版本号不会更新到 `2056`。

**Step 2: 运行测试并确认失败**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PostgresSchemaUpgradeTest.php
```

Expected: FAIL，当前升级后缺少表或字段。

**Step 3: 实现版本化迁移类**

- 把 PostgreSQL 升级步骤从初始化入口提取到 `PostgresMigrations`。
- 按历史版本组织迁移，所有步骤可重复执行。
- 每个版本在独立事务中执行并最后更新版本号。
- 使用结构查询补齐字段、表、索引和约束。

**Step 4: 比较升级结构和全新结构**

测试从 `install.sql` 转换得到的全新结构与旧库升级后的关键结构一致。

**Step 5: 运行测试并提交**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PostgresSchemaUpgradeTest.php
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/PostgresUserIdentityTest.php
git add docker/init-postgres.php docker/PostgresMigrations.php tests/PostgresSchemaUpgradeTest.php
git commit -m "fix: add complete postgres schema migrations"
```

### Task 9: 完整回归、文档状态和部署检查

**Files:**

- Modify: `docs/database-business-logic-audit-2026-08-02.md`
- Create or Modify: `docs/testing/DB-01-09-数据库逻辑整改测试.md`

**Step 1: 运行 PHP 语法检查**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app sh -lc 'find . -path ./includes/vendor -prune -o -path "*/vendor" -prune -o -name "*.php" -print0 | xargs -0 -n1 php -l'
```

Expected: 所有文件 `No syntax errors detected`。

**Step 2: 运行全部测试**

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app sh -lc 'set -e; for test in tests/*Test.php; do php "$test"; done'
```

Expected: 所有数据库和业务测试通过。若 TLS 外部网络测试仍受环境影响，必须单独记录真实输出，不能计为数据库整改通过依据。

**Step 3: 构建和健康检查**

```powershell
docker compose up -d --build
docker compose ps
docker compose logs --since=5m app db
```

Expected: `app` 和 `db` 正常运行，无初始化或迁移错误。

**Step 4: 更新审计和测试文档**

- 逐项把 `DB-01` 至 `DB-09` 标记为已修复或明确记录剩余风险。
- 记录测试命令、结果、提交号和部署注意事项。

**Step 5: 最终提交**

```powershell
git add docs/database-business-logic-audit-2026-08-02.md docs/testing/DB-01-09-数据库逻辑整改测试.md
git commit -m "docs: record database remediation verification"
```
