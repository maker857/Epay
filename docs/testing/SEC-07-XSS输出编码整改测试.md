# SEC-07 XSS 输出编码整改测试

## 验证结果

- `html_escape()` 统一使用 `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`。
- 收银台、公告、商户资料、子通道和后台公共模板的文本、属性、数字及 URL 输出已分别处理。
- 公告颜色限制为十六进制白名单。
- 管理后台和收银台加入 CSP。

测试提交：`8bc57b7`（`test: reproduce unsafe HTML output encoding`）

```powershell
docker compose run --rm -v "${PWD}:/var/www/html" app php tests/XssOutputEncodingTest.php
```

结果：通过。脚本标签、引号和危险颜色值被正确转义或拒绝。

目标文件 PHP 语法检查均通过，SQL 注入回归测试通过。

## 已知限制

当前测试以单元和静态模板检查为主，未使用真实浏览器执行完整存储型 XSS 流程；上线前建议配合浏览器安全测试。
