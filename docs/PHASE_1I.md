# LandingOps Phase 1I：单站创建预演与部署计划

## 交付范围

本阶段提供一个可运行的 Vue 四步向导和 PHP Dry Run 服务：选择当前有权使用的不可变模板版本；选择当前有权使用且验证上下文有效的主域名并生成规范化 FQDN；从模板 LTS v1.0 Schema 生成配置表单；展示结构化部署计划、阻断项和风险。没有实现正式实例创建、域名预留、部署任务、宝塔/DNS/SSL 写入或生产文件发布。

## 服务端信任边界

Dry Run 只接受模板版本 ID、主域名 ID、子域名前缀与 canonical 配置。服务端重新验证：

- 当前账号为 active，非总管理员只能使用本团队当前有效授权；
- 模板及版本为 active/valid，撤销授权立即阻止新预演；
- 主域名、Zone、Cloudflare 连接均启用且 Zone 验证证据绑定当前 credential context；
- FQDN 不与现有实例或 held/committed 预留冲突；
- 配置只含 Schema 允许字段，并满足 URL、数值、数组、布尔、必填和其他 Schema 约束；
- PHP runtime 模板在隔离证据不足时保持阻断。

响应不回显提交的配置值，尤其不返回 private 字段。公开 JavaScript 仍只能由 Phase 1B `ConfigProjector` 根据显式 public 白名单生成。表单契约标记每个字段的 visibility，便于 UI 明确提示私有边界。

## 宝塔读取预算

站点清单每页 100，单次最多 3 个分页请求并最多保留 200 条；绑定域名详情最多 20 次。站点 PHP 不在清单页面逐条查询。达到预算、分页失败、详情失败或字段缺失都会在 `inventory` 中标记不完整；预检查不会把这类结果解释为无冲突。真实宝塔仍未在本阶段联调。

## API 与页面

- `GET /api/v1/template-versions/{id}/config-form`：返回授权后的 Schema 表单契约；
- `POST /api/v1/deployment-dry-runs`：返回零写入部署预演；
- `GET /api/v1/settings/baota/sites`：返回 `{sites, inventory}` 完整性契约；
- Vue `/instances/new`：四步桌面优先并兼容手机的单站预演向导。

计划中的 DNS、宝塔、构建、SSL 与发布步骤均为 `execute=false`。报告明确说明预演不构成创建许可，正式提交必须再次验证全部条件。

## 测试与本地命令

```powershell
.tools/php/php.exe tests/run.php
.tools/php/php.exe tests/mysql-integration.php
.tools/php/php.exe -l src/ControlPlane/Service/DeploymentDryRunService.php
.tools/php/php.exe .tools/composer.phar validate --strict
cd frontend
npm test
npm run typecheck
npm run build
```

自动化覆盖三级角色、跨团队访问、模板撤销、过期凭据上下文、重复 FQDN、活动预留、非法 URL、配置值不回显、结构化计划、宝塔详情预算与不完整结果。MariaDB 测试额外断言 Dry Run 前后 `instances`、`domain_reservations`、`deployment_jobs` 和 `job_steps` 数量不变，并在事务回滚后保持 22 张业务表无测试数据。

## 遗留风险

- 真实宝塔版本、分页与字段仍待单独授权的只读联调；本轮未连接宝塔。
- 正式提交前仍需补全 DNS 通配符/委派检查、目录观察、Unix 用户/FPM/Nginx 隔离、资源预留、任务幂等和失败补偿。
- PHP 模板、原生后台和第三方 PHP 组件继续禁用，直至执行隔离证据通过验收。

## 最终验收结果

- PHP 自动化测试：`86 passed, 0 failed`；
- Vue/Vitest：`7 files / 25 tests passed`；
- Vue TypeScript、Vite 生产构建、Composer 严格校验及全部 PHP 语法检查通过；
- 真实数据库：MariaDB `10.11.10-MariaDB-log`，当前数据库严格为 `landingops_test`，账号为 `landingops_test@localhost` 且权限检查确认没有测试库以外的显式权限；
- 真实 PDO 流程验证 22 张业务表、001–005 迁移与重复/失败恢复、FK/CHECK/UNIQUE、Session、负责人转交、模板上传授权、Cloudflare 密文与多 Zone 失效、域名授权、单宝塔约束，以及 Phase 1I Dry Run；
- Dry Run 前后 `instances`、`domain_reservations`、`deployment_jobs`、`job_steps` 数量一致；测试结束后事务回滚，22 张业务表无测试数据，正式迁移记录保留；
- 首次重跑发现一个由中断连接遗留的同账号测试库休眠连接。安全辅助脚本仅关闭该专用账号、`landingops_test`、休眠超过 60 秒的连接；未授予 `PROCESS`，未访问其他数据库。
