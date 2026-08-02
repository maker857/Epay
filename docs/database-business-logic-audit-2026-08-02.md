# 数据库与业务逻辑审计报告

> 审计日期：2026-08-02  
> 审计背景：项目经过 Docker 化及 MySQL 到 PostgreSQL 的重构，需要检查原有支付业务逻辑、事务处理和 SQL 兼容性是否仍然成立。  
> 当前状态：DB-01 至 DB-09 已完成整改并通过回归验证。

## 结论

本轮共确认 9 类数据库相关问题，其中 1 项为 P0、5 项为 P1、2 项为 P2、1 项为 P3。

主要风险集中在以下三个方面：

1. 外部支付或转账已经成功，但本地订单、余额或流水可能没有正确落库。
2. PostgreSQL 迁移只处理了部分 MySQL SQL 方言，导致回调重试、通道选择和后台操作失效。
3. PostgreSQL 旧库升级逻辑不完整，可能出现版本号已经升级但数据库结构仍然缺失的情况。

## 问题总览

| 编号 | 严重级别 | 问题 | 主要影响 | 状态 |
| --- | --- | --- | --- | --- |
| DB-01 | P0 | 转账外部成功后，本地事务可能失败 | 重复付款、漏记转账、余额未扣除 | 已修复 |
| DB-02 | P1 | 余额修改函数掩盖数据库失败 | 支付、退款、结算和代付账务不一致 | 已修复 |
| DB-03 | P1 | 支付回调不检查中间数据库操作 | 外部已支付但订单仍未支付 | 已修复 |
| DB-04 | P1 | 商户回调重试 SQL 不兼容 PostgreSQL | 首次通知失败后不再重试 | 已修复 |
| DB-05 | P1 | PostgreSQL 旧库升级逻辑不完整 | 缺表、缺字段、运行时 SQL 错误 | 已修复 |
| DB-06 | P1 | 随机通道兜底使用 MySQL `RAND()` | 无用户组配置时无法选择通道 | 已修复 |
| DB-07 | P2 | 后台使用 PostgreSQL 不支持的 SQL | 页面提示成功但数据未修改 | 已修复 |
| DB-08 | P2 | 自定义数据库表前缀未被完整支持 | 非默认前缀访问错误数据表 | 已修复 |
| DB-09 | P3 | 数据库错误状态不会在成功后清除 | 日志和异常信息可能显示旧错误 | 已修复 |

## 详细问题

### DB-01：转账外部成功后，本地事务可能失败

**严重级别：P0**

相关位置：

- `includes/lib/Transfer.php:91`
- `includes/lib/Transfer.php:119`
- `includes/lib/Transfer.php:128`
- `includes/lib/Transfer.php:145`
- `includes/lib/Transfer.php:307`
- `includes/lib/Transfer.php:343`

转账代码先开启数据库事务，然后在事务内部调用外部转账平台。外部平台返回成功后，代码没有完整检查以下操作：

- 数据库事务是否成功开启。
- 转账记录是否成功插入。
- 商户余额及资金流水是否成功写入。
- 最终事务是否成功提交。

即使本地数据库提交失败，函数仍可能把外部平台的成功结果返回给调用方。此时会出现平台已经付款，但本地没有转账记录或没有扣除余额的情况。

红包转账流程存在相同模式。

**整改要求：**

- 外部转账前先完成必要的本地订单占位和幂等状态更新。
- 所有数据库写入必须检查返回值，失败时立即抛出异常并回滚。
- 必须检查 `beginTransaction()` 和 `commit()` 的执行结果。
- 外部成功、本地失败时需要记录明确的待对账状态，不能直接返回完整成功。
- 增加外部成功但本地插入、扣款或提交失败的测试。

### DB-02：余额修改函数掩盖数据库失败

**严重级别：P1**

相关位置：

- `includes/functions.php:847`
- `includes/functions.php:854`
- `includes/functions.php:855`
- `includes/functions.php:863`
- `includes/functions.php:864`
- `includes/functions.php:865`
- `includes/functions.php:869`

`changeUserMoney()` 和 `changeUserMoney2()` 是多个资金流程共用的底层函数，但当前没有可靠传播数据库错误。

主要问题：

- 没有确认目标商户是否存在。
- 没有确认余额查询是否成功。
- 没有确认余额更新是否成功。
- 没有确认资金流水插入是否成功。
- 没有确认事务提交是否成功。
- 失败后没有可靠回滚。
- 函数可能只返回余额更新结果，而忽略流水插入或提交失败。

这与线上支付回调出现的 `pay_record.oldmoney=NULL` 错误属于同一调用链。当订单中的 UID 没有对应商户时，旧余额为 `NULL`，随后流水插入失败并使 PostgreSQL 事务中止。

该函数被支付入账、退款、结算、提现、代付、返佣及后台余额修改等流程使用，影响范围较大。

**整改要求：**

- 商户不存在或余额查询失败时立即抛出异常。
- 余额更新和流水插入必须作为不可分割的原子操作。
- 每个数据库写操作都必须检查结果。
- 独立调用时负责开启、提交和回滚事务；嵌套调用时正确使用现有保存点。
- 返回成功必须代表余额和流水均已提交成功。

### DB-03：支付回调不检查中间数据库操作

**严重级别：P1**

相关位置：

- `includes/lib/Payment.php:354`
- `includes/lib/Payment.php:370`
- `includes/lib/Payment.php:389`
- `includes/lib/Payment.php:391`
- `includes/lib/Payment.php:399`
- `includes/lib/Payment.php:407`

支付回调已经增加订单锁、状态认领和最终提交检查，但订单补充字段更新，以及全局 `processOrder()` 内部执行的余额、流水、返佣和分账操作没有逐项检查结果。

PostgreSQL 在事务中遇到第一条失败 SQL 后会把事务标记为中止。后续 SQL 仍然继续执行，直到最终 `commit()` 时才统一报错，因此日志经常只显示提交失败，真正的第一条错误容易被掩盖。

**整改要求：**

- 将订单处理涉及的数据库写入统一改为失败即抛出异常。
- `processOrder()` 应返回明确结果，不能依赖无返回值的副作用。
- 保留当前第一条失败 SQL 的事务日志，同时在业务层记录订单号和失败阶段。
- 增加订单字段更新、余额更新、流水插入、返佣和分账分别失败时的回滚测试。

### DB-04：商户回调重试 SQL 不兼容 PostgreSQL

**严重级别：P1**

相关位置：

- `cron.php:195`
- `cron.php:248`

当前 SQL 使用 MySQL 函数：

```sql
TO_DAYS(NOW()) - TO_DAYS(endtime)
```

PostgreSQL 不提供 `TO_DAYS()`，`PdoHelper::dealSql()` 也没有转换该函数。直接验证会得到 `function to_days(...) does not exist`。

因此以下流程会失败：

- 首次商户通知失败后的定时重试。
- `notify=-1` 订单的人工或定时恢复通知。

这会造成支付平台回调已经完成、本站订单已经入账，但下游商户永远收不到成功通知。

**整改要求：**

- 使用 PostgreSQL 和 MySQL 均可表达的时间范围条件，或在 SQL 转换层明确转换。
- 查询失败不能被当作“没有待重试订单”。
- 为首次失败、分阶段重试、最终失败和恢复通知增加集成测试。

### DB-05：PostgreSQL 旧库升级逻辑不完整

**严重级别：P1**

相关位置：

- `docker/init-postgres.php:84`
- `docker/init-postgres.php:90`
- `docker/init-postgres.php:111`
- `install/update2.sql:1`
- `install/update3.sql:1`

`upgrade_schema()` 只补充少量字段和字段类型，随后直接把数据库版本设置成 `2056`。与 `install/update2.sql` 和 `install/update3.sql` 对比，仍缺少大量历史升级内容，例如：

- 子通道及订单 `subchannel` 字段。
- 分账接收方、分账订单和子订单相关结构。
- 转账、退款及结算相关表和字段。
- 商户表新增的结算、通知、分账和安全字段。
- 支付通道和插件的新增配置字段。

旧数据库可能出现两种结果：

1. 必要表不存在，初始化容器升级时直接失败。
2. 少量升级 SQL 成功，版本被设置为 `2056`，但其他字段仍然缺失。

全新安装使用完整的 `install.sql`，受影响相对较小；从旧版本迁移的数据库风险最高。

**整改要求：**

- 建立可重复执行、按版本递增的 PostgreSQL migration。
- 每个版本只在对应结构全部创建成功后更新版本号。
- 使用 `IF EXISTS`、`IF NOT EXISTS` 和字段结构检查保证幂等性。
- 使用至少一份旧版本数据库快照进行完整升级测试。
- 升级后比较实际表、字段、索引和约束与全新安装结果。

### DB-06：随机通道兜底使用 MySQL `RAND()`

**严重级别：P1**

相关位置：

- `includes/lib/Channel.php:284`

未设置有效用户组时，通道选择使用：

```sql
ORDER BY rand()
```

PostgreSQL 对应函数为 `random()`。当前转换器没有处理 `RAND()`，查询会失败，最终表现为没有可用支付通道。

**整改要求：**

- 根据数据库驱动生成正确的随机函数，或统一使用可移植的通道选择逻辑。
- 查询失败与确实没有通道必须返回不同错误。
- 增加无用户组、无轮询组和多通道随机选择测试。

### DB-07：后台使用 PostgreSQL 不支持的 SQL

**严重级别：P2**

相关位置：

- `admin/ajax_order.php:193`
- `admin/ajax_profitsharing.php:354`
- `admin/ajax_user.php:49`

后台批量修改订单和分账状态时使用：

```sql
UPDATE ... LIMIT 1
```

PostgreSQL 不支持 `UPDATE LIMIT`。代码没有检查执行结果，却仍然累加成功数量并返回修改成功。

后台“不活跃商户”筛选使用：

```sql
NOW() - INTERVAL 30 DAY
```

该语法同样不适用于 PostgreSQL。

**整改要求：**

- 移除已经由唯一订单号或主键保证范围的 `LIMIT 1`。
- 修正 PostgreSQL 时间间隔语法。
- 批量操作必须以实际受影响行数统计成功数量。
- 任意一条失败时返回明确的失败记录和数据库错误。

### DB-08：自定义数据库表前缀未被完整支持

**严重级别：P2**

相关位置：

- `cron.php:96`
- `admin/ajax_user.php:313`
- `admin/ajax_user.php:317`
- `install/update.php:20`

项目支持通过 `DB_PREFIX` 配置数据表前缀，但部分 SQL 直接使用：

- `pay_wxkflog`
- `pay_order`
- `pay_blacklist`
- `pay_config`

这些表名不会经过 `pre_` 占位符替换。默认前缀 `pay` 时暂时正常，改用其他前缀后会访问错误的数据表。

**整改要求：**

- 运行时代码统一使用 `pre_` 逻辑前缀或结构化数据访问方法。
- 安装和升级代码统一使用已经验证过的实际前缀。
- 增加非默认 `DB_PREFIX` 的初始化和主要业务流程测试。

### DB-09：数据库错误状态不会在成功后清除

**严重级别：P3**

相关位置：

- `includes/lib/PdoHelper.php:283`
- `includes/lib/PdoHelper.php:321`
- `includes/lib/PdoHelper.php:442`

`PdoHelper` 仅在 SQL 失败时写入 `$errorInfo`，成功执行后不会清空该属性。因此一条失败查询之后，即使下一条查询成功，调用 `$DB->error()` 仍可能返回上一条 SQL 的错误。

这会导致日志、异常信息和诊断结果与当前操作不一致。

**整改要求：**

- 每次数据库操作开始前或成功后清空旧错误。
- 事务提交、回滚和保存点操作失败时保存对应 PDO 错误。
- 增加“失败查询后执行成功查询”的回归测试。

## 补充观察

MySQL 的 `pre_roll` 表通过 `AUTO_INCREMENT=101` 让轮询组 ID 从 101 开始。PostgreSQL 初始化后该表会从 1 开始。目前没有发现业务代码以 `ID >= 101` 判断轮询组，因此暂不认定为业务故障，但建议在迁移规范中明确是否需要保留原始编号范围。

## 整改结果

| 编号 | 主要整改 | 提交 | 核心验证 |
| --- | --- | --- | --- |
| DB-01 | 转账先持久化和预留余额，平台调用移出长事务；仅明确失败才退款，后台操作统一走状态机 | `b5d06f9`, `06f13a5`, `2aaf52e` | `TransferTransactionStateTest.php` |
| DB-02 | 余额、流水、提交和回滚统一进行严格结果检查 | `9bcffb2` | `UserMoneyTransactionTest.php` |
| DB-03 | 支付回调关键写入失败立即抛错，完整事务回滚并保留首个失败阶段 | `02bd3af` | `PaymentCallbackWriteFailureTest.php`, `PaymentCallbackRollbackTest.php` |
| DB-04 | 回调重试改用绑定的一日时间边界，查询失败不再解释为空结果 | `906b7c7` | `PostgresBusinessSqlCompatibilityTest.php` |
| DB-05 | 新增幂等 PostgreSQL 结构协调器；保留 `wxid` 历史数据、补齐字段扩容，并用轻量探针避免无条件 DDL | `d5163fe`, `f3fd48d`, `bf8366b` | `PostgresSchemaUpgradeTest.php` |
| DB-06 | 根据数据库驱动选择 `RAND()` 或 `RANDOM()` | `906b7c7` | `PostgresBusinessSqlCompatibilityTest.php` |
| DB-07 | 移除不兼容的 `UPDATE ... LIMIT`，修正时间筛选并按真实结果报告 | `906b7c7` | `PostgresBusinessSqlCompatibilityTest.php` |
| DB-08 | 运行时及升级入口统一使用逻辑表前缀 | `6322916` | `DatabasePrefixCompatibilityTest.php` |
| DB-09 | 清除陈旧错误并严格传播事务、提交、回滚和保存点失败 | `0b83bbb` | `PdoHelperTransactionStateTest.php` |

最终验证结果：31 个自动化测试全部通过，全部 PHP 文件无语法错误；独立 Compose 项目使用空 PostgreSQL 数据卷完成全新初始化，`app` 和 `db` 均达到 `healthy`。详细命令和结果见 `docs/testing/DB-01-09-数据库逻辑整改测试.md`。

剩余风险：本轮验证以 PostgreSQL 为主，生产升级前仍应对数据库做完整备份，并在生产数据副本上执行一次迁移演练。支付平台真实网络、签名和商户配置仍需使用平台沙箱或小额实付单独验收。

## 建议整改顺序

1. 修复转账流程的外部副作用与本地事务一致性。
2. 重构 `changeUserMoney()` 和 `changeUserMoney2()` 的错误传播与事务边界。
3. 让支付回调中的所有数据库写入失败即中止并回滚。
4. 修复商户回调重试 SQL，并补充重试集成测试。
5. 建立完整、可重复执行的 PostgreSQL 版本迁移机制。
6. 修复通道选择、后台操作和自定义表前缀兼容问题。
7. 清理 `PdoHelper` 的错误状态管理并完善数据库兼容测试矩阵。

## 验收原则

- 任何资金操作只有在余额、流水、订单状态全部提交成功后才能返回成功。
- 外部支付平台成功、本地数据库失败时必须存在可查询、可对账、可补偿的中间状态。
- PostgreSQL 查询失败不能被业务代码解释为“没有数据”。
- 全新安装和旧库升级后的表、字段、索引及约束必须一致。
- MySQL 与 PostgreSQL 的关键支付、回调、退款、结算和转账测试必须得到一致结果。
