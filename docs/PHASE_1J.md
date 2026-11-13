# LandingOps Phase 1J：配置向导完善与真实只读环境验收

## 交付范围

本阶段收紧 LTS v1.0 动态配置表单的前后端契约，并提供可重复、默认不运行的宝塔与 Cloudflare 真实只读诊断入口。没有新增实例创建、DNS 写入、宝塔写操作、SSL、生产目录访问或发布 Worker。

## 配置表单语义

- 可选字段初始值为 `undefined`，构造 canonical 配置时完全省略；用户必须显式选择“填写此字段”。
- nullable 字段初始为 `null`，不会把 nullable number/integer 变为 `0`；填写后的 nullable URL 可显式恢复为 `null`。
- integer 只接受完整 JSON 整数字面量和安全整数，拒绝 `1.2`、`12abc` 及溢出值，不使用 `parseInt`。
- number 使用完整 JSON 数字语法并要求有限值；array 根据 Schema `items.type` 分别转换 string、number、integer、boolean。
- 必填字段不可省略；后端仍以数据库保存的 Schema 和 `ConfigValidator` 为最终边界。
- 表单生成器只支持显式 `properties` 对象、标量叶子、标量数组，以及“一个 null 分支 + 一个受支持分支”的 nullable `oneOf`。对象数组、动态对象、组合 Schema、tuple 等未支持结构返回 `422 config_form_schema_unsupported`，不会静默丢字段。
- Dry Run 响应只返回校验摘要和字段数量，不回显 canonical 值；private 字段仍不能进入公开 JavaScript 投影。

## 真实只读诊断

入口：`php bin/landingops-readonly-diagnostic baota|cloudflare`。所有密钥只从进程私有环境变量读取，不能作为命令参数，也不会出现在报告中。

宝塔诊断使用现有固定只读动作白名单，读取系统摘要、最多 3 页/200 个站点、最多 20 次绑定域名详情及 PHP 版本摘要。它不读取网站文件；根目录仅检查列表响应是否提供字段。分页、详情、根目录字段或能力不完整时返回 `needs_attention`。

```powershell
$env:LANDINGOPS_BAOTA_ALLOWED_BASE_URLS='https://panel.example.test:8888'
$env:LANDINGOPS_DIAGNOSTIC_BAOTA_BASE_URL='https://panel.example.test:8888'
$env:LANDINGOPS_DIAGNOSTIC_BAOTA_API_KEY='<通过本地安全输入设置>'
.tools/php/php.exe bin/landingops-readonly-diagnostic baota
Remove-Item Env:LANDINGOPS_DIAGNOSTIC_BAOTA_API_KEY
```

Cloudflare 诊断固定访问官方 API origin，验证 Token、明确 Zone ID/名称、可选 Account ID、候选 FQDN 精确记录、Zone 通配符精确名称和 apex NS 摘要。报告只含数量与类型，不含 Token 或记录 content。该查询不能全面证明上级/子级委派、通配符继承或 CNAME 最终目标安全。

```powershell
$env:LANDINGOPS_DIAGNOSTIC_CF_TOKEN='<通过本地安全输入设置>'
$env:LANDINGOPS_DIAGNOSTIC_CF_ZONE_ID='<明确的测试 Zone ID>'
$env:LANDINGOPS_DIAGNOSTIC_CF_ZONE_NAME='example.test'
$env:LANDINGOPS_DIAGNOSTIC_CF_ACCOUNT_ID='<预期 Account ID>'
$env:LANDINGOPS_DIAGNOSTIC_CF_HOSTNAME='candidate.example.test'
.tools/php/php.exe bin/landingops-readonly-diagnostic cloudflare
Remove-Item Env:LANDINGOPS_DIAGNOSTIC_CF_TOKEN
```

两种真实诊断都必须由操作者另行明确授权并提供受限凭据。本阶段没有在仓库、日志或文档中保存凭据。

## Dry Run 往返与数据库隔离

`tests/mysql-integration.php` 现在通过真实 `ControlPlaneKernel`、Cookie Session、CSRF、PDO 仓储执行配置表单 GET 和 Dry Run POST。测试对比执行前后的 `instances`、`domain_reservations`、`deployment_jobs`、`job_steps` 数量，并在事务回滚后确认 22 张业务表没有测试数据；不调用任何外部适配器。

## 测试命令

```powershell
.tools/php/php.exe tests/run.php
.tools/php/php.exe -l src/ControlPlane/Service/ConfigFormSchemaBuilder.php
.tools/php/php.exe .tools/composer.phar validate --strict
cd frontend
npm test
npm run typecheck
npm run build
```

真实 MariaDB 仍通过 `127.0.0.1:13306` SSH 隧道和本地隐藏密码输入运行：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\.tools\ssh\Run-MySqlIntegrationInteractive.ps1
```

## 真实联调状态与剩余风险

- 宝塔真实只读联调：**SKIP**，本轮没有获得面板 origin/API Key 的明确授权输入；模拟测试覆盖预算、不完整数据和脱敏。目标面板版本及响应字段仍待确认。
- Cloudflare 真实只读联调：**SKIP**，本轮没有获得受限测试 Token、Zone 与 Account 的明确授权输入；模拟测试覆盖正常、401、403、429、超时、Zone 身份及 DNS 查询路径。
- MariaDB：**PASS**。通过 SSH 隧道 `127.0.0.1:13306` 验证 MariaDB `10.11.10-MariaDB-log`；`DATABASE()` 为 `landingops_test`，账号为 `landingops_test@localhost` 且权限范围检查通过。001–005、重复/失败迁移恢复、FK/CHECK/UNIQUE、凭据上下文与多 Zone 失效、Cookie Session/CSRF、负责人转交、模板与域名授权、宝塔密文及单例约束均通过。
- 新增真实 PDO API 往返：配置表单 GET 与 Dry Run POST 返回成功，配置通过 Schema 校验，执行前后 `instances`、`domain_reservations`、`deployment_jobs`、`job_steps` 数量一致；事务回滚后 22 张业务表无测试数据，正式 schema 与迁移账本保留。
- 即使两项只读诊断通过，也不构成 DNS 写入、建站、目录访问或正式部署许可。

## 最终测试结果

- PHP 自动化：`90 passed, 0 failed`；
- Vue/Vitest：`7 files / 30 tests passed`；
- PHP 语法：100 个文件通过；Composer `validate --strict` 通过；
- Vue TypeScript 与 Vite 生产构建通过；
- 只读诊断 CLI 无参数冒烟测试按契约返回 usage/exit 2；模拟适配器报告不含测试 Token/API Key；
- 真实 MariaDB 集成：PASS，且只操作 `landingops_test`。
