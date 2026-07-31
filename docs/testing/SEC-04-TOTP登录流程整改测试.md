# SEC-04 TOTP 登录流程整改测试记录

## 整改内容

- 管理员密码验证成功后创建一次性 TOTP 登录挑战，不再允许直接调用 TOTP 接口完成登录。
- 挑战绑定管理员账号和客户端 IP，有效期五分钟。
- 单次挑战最多允许五次错误；同时统计同 IP 十五分钟内的 TOTP 失败记录。
- TOTP 成功后清理挑战并调用 `session_regenerate_id(true)` 轮换会话 ID。
- 密码登录且未启用 TOTP 时同样轮换会话 ID。
- 异常信息不再把 TOTP 库内部错误直接返回给客户端。

## TDD 证据

| 阶段 | 测试 | 结果 |
| --- | --- | --- |
| RED | `tests/AdminTotpSecurityRegressionTest.php` | 失败，确认缺少密码阶段挑战、有效期、失败限制、状态清理和会话轮换 |
| RED | `tests/AdminTotpLoginTest.php` | 失败，登录挑战守卫尚不存在 |
| GREEN | `tests/AdminTotpLoginTest.php` | 通过：账号/IP 绑定、五分钟过期、五次失败和清理后不可复用 |
| GREEN | `tests/AdminTotpSecurityRegressionTest.php` | 通过：登录入口必须经过挑战守卫，并记录失败和轮换会话 |
| 回归 | SEC-01 幂等、并发和异常回滚测试 | 在包含 `install.lock` 的新镜像中全部通过 |
| 容器 | `docker compose ps` | app 与 db 均 healthy |

## 提交证据

- RED：`32838e1`、`1c956b5`
- GREEN：`c5b5a4f`

## 已知限制

未启用生产管理员 TOTP 密钥进行浏览器端真实扫码登录，以避免修改现有管理员安全配置；核心挑战状态机和登录入口约束已由独立测试覆盖。
