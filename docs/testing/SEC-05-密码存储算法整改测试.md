# SEC-05 密码存储算法整改测试记录

## 整改内容

- 新密码使用 Argon2id（环境不支持时回退 bcrypt），不再保存明文或固定 MD5。
- 商户 `pre_user.pwd` 从 `varchar(32)` 扩展为 `varchar(255)`。
- 旧管理员明文密码和旧商户 MD5 密码仍可验证，首次成功登录后自动升级为自适应哈希。
- PostgreSQL Docker 初始化直接写入 Argon2id；已有 2055 数据库启动时升级到 2056，并迁移管理员密码。
- 商户登录令牌指纹绑定当前密码哈希，改密码后旧令牌失效。
- 管理员支付密码验证改为哈希校验，后台会话不再保存支付密码明文。

## TDD 证据

| 阶段 | 测试 | 结果 |
| --- | --- | --- |
| RED | `tests/PasswordHashingTest.php` | 失败，密码哈希器尚不存在 |
| RED | `tests/PasswordStorageRegressionTest.php` | 失败，旧代码缺少哈希调用且密码列仍为 32 位 |
| GREEN | `tests/PasswordHashingTest.php` | 通过：现代哈希验证、旧管理员明文、旧商户 MD5 兼容、密码变化使令牌指纹变化 |
| GREEN | `tests/PasswordStorageRegressionTest.php` | 通过：新写入路径和 255 位字段约束存在 |
| 回归 | SQL 注入、支付回调幂等和 Docker 健康检查 | 通过 |
| 迁移 | `docker compose logs app` | `PostgreSQL schema upgraded from 2055 to 2056` |
| 数据确认 | PostgreSQL 检查管理员哈希前缀及 `pay_user.pwd` 长度 | 管理员密码为 Argon2 哈希，商户密码列长度为 255 |

## 提交证据

- RED：`e4e7b59`
- GREEN：`7255fe1`

## 已知限制

旧商户 MD5 哈希无法在不知道原密码的情况下批量转换，因此采用成功登录后升级；未再次登录的旧记录仍保留旧哈希格式，但不能被直接当作新密码使用。
