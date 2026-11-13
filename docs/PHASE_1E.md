# LandingOps Phase 1E：模板管理与团队授权

## 1. 交付范围

Phase 1E 将 Phase 1B 的 LTS v1.0 离线验证器接入 PHP 控制面，并在 Vue 管理后台开放模板元数据管理：

- 总管理员和团队管理员上传单个完整 ZIP；
- 路径穿越、链接、危险文件、非法 Manifest/Schema、大小与数量限制继续由同一验证器执行；
- 验证成功后创建逻辑模板和不可变 `template_versions` 记录；
- 团队管理员上传的版本默认授权给登录用户所属团队；
- 总管理员查看全部模板，并授权、撤销或重新授权其他团队；
- 员工和团队管理员只看当前团队拥有有效 grant 的版本；
- Vue 页面展示版本、SHA-256、上传者、所属团队、验证结果与授权状态，不提供截图、预览或部署按钮。

本阶段沿用已有 `templates`、`template_versions`、`template_grants` 表，无数据库结构变化，也没有修改历史迁移。

## 2. 私有上传与失败恢复

`POST /api/v1/templates/uploads` 只接受一个名为 `template_zip` 的 multipart 文件。服务端不接受客户端声明的 `owner_team_id`：团队管理员的归属从 Session 用户和数据库团队记录得出；总管理员新建的平台模板归属为空。

上传顺序：

1. 校验角色、团队状态、文件数量、`.zip` 扩展名和 30 MiB 压缩包上限；
2. 复制到 Web Root 外的随机 intake 文件，目录/文件权限基准为 `0700/0600`；
3. Phase 1B 验证器创建私有 SHA-256 快照并完成 LTS v1.0 全量检查；
4. 校验哈希和 `slug + version` 冲突；
5. 把已验证快照复制为 `versions/{hash-prefix}/{version-id}.zip`，再次校验 SHA-256；
6. 在事务中创建模板/版本、默认授权和审计；
7. 无论成功失败都清理 intake；数据库事务失败时同时删除新版本文件。

上传 ZIP 不会被解压到 Web Root，也不会被 include 或作为 PHP 执行。API 只返回相对安全元数据，不返回绝对存储路径或 `zip_storage_key`。验证报告会移除绝对路径。

默认私有目录为 `var/private/templates`，已被 Git 忽略。生产建议设置：

```text
LANDINGOPS_TEMPLATE_STORAGE_DIR=/srv/landingops-private/templates
```

该路径必须是绝对路径且不能位于 `public/` 内；生产文件系统应额外设置 `noexec`，Nginx 不得配置映射。

## 3. PHP 上传配置

应用限制保持 30 MiB。PHP-FPM 或本地 CLI Server 的配置必须略高于协议限制，以容纳 multipart 开销，例如：

```ini
upload_max_filesize = 31M
post_max_size = 32M
max_file_uploads = 1
```

调整 PHP 配置不会放宽应用层、ZIP 条目、解压总量或单文件限制。生产修改后需要重启对应 PHP-FPM，仅在正式变更流程中执行；本阶段未修改宝塔或生产 PHP 配置。

## 4. API

所有路径使用 `/api/v1`、Cookie Session、CSRF 和统一错误封装：

| 方法与路径 | 权限 | 状态 |
| --- | --- | --- |
| `GET /templates` | 全部角色 | 总管理员看全部；其他角色只看本团队有效授权 |
| `POST /templates/uploads` | 总/团队管理员 | 单 ZIP、同步验证并创建不可变版本 |
| `GET /template-versions/{id}/validation` | 有权查看版本者 | 返回脱敏验证报告 |
| `POST /template-versions/{id}/grants` | 总管理员 | 授权或重新授权 `team_id` |
| `DELETE /template-versions/{id}/grants/{team_id}` | 总管理员 | 撤销未来使用权 |

主要错误码：`template_upload_count`、`template_zip_too_large`、`template_validation_failed`、`template_hash_conflict`、`template_version_immutable`、`template_not_available`、`template_grant_not_active`。跨团队模板 slug 冲突统一返回不可用，不暴露所属团队或版本 ID。

## 5. Phase 1D 遗留修复

- 实例负责人转交收到 409 后先刷新实例列表，立即关闭包含旧 `selected.version` 的弹窗，并显示新版本号；用户必须重新打开弹窗和确认，旧版本不能直接重试。
- 退出请求因网络/API 失败时，Pinia 仍清空本地身份，布局无条件跳回登录页，并明确提示仅本地状态已清除。

## 6. localhost 安全 Cookie 验证

2026-11-13 在目标内置浏览器中通过 `http://localhost:5173 → Vite Proxy → 127.0.0.1:8080 PHP` 完成真实浏览器往返：

1. PHP 返回 `Secure + HttpOnly + SameSite=Strict + Path=/api/v1` Cookie；
2. 浏览器在后续 `GET /api/v1/me` 中自动携带 Cookie，身份恢复成功；
3. `/me` 返回的新 CSRF Token 被前端仅存内存；
4. `POST /api/v1/logout` 同时携带 Cookie 和新 CSRF，PHP 接受并清除 Cookie；
5. 页面回到登录路由。

验证没有移除 `Secure`、`HttpOnly` 或 `SameSite=Strict`。仓库中的 `tests/browser-cookie-probe.php` 只接受回环地址，用于复现该浏览器契约，不连接数据库。

## 7. 本地命令

```powershell
# PHP 回归
.\.tools\php\php.exe tests\run.php

# 前端测试与构建
cd frontend
npm test
npm run build
npm audit --audit-level=moderate

# PHP 语法及 Composer
cd ..
.\.tools\php\php.exe .\.tools\composer.phar validate --strict
```

真实 MySQL 集成测试仍使用 Phase 1C 的受限 `landingops_test` 流程。本阶段未增加迁移；没有活动 SSH 隧道或本地安全凭据时不得自行连接。

本轮最终结果：PHP `58 passed, 0 failed`；Vitest `6 files / 20 tests passed`；Vue TypeScript 与 Vite 生产构建通过；Composer 严格校验通过；全部 PHP 文件语法检查通过；`npm audit` 为 0 个已知漏洞。检查时本机没有监听 `127.0.0.1:13306` 的 SSH 隧道，也没有 `.env.mysql-test.local`，因此没有连接或改动 MySQL；现有表结构与历史迁移均未修改。

## 8. 保留边界

- 未实现实例创建，因此模板授权目前只形成可查询、可供后续实例服务调用的真实授权边界；
- 未实现模板截图、视觉预览、生产发布或模板执行；
- 未连接宝塔、Cloudflare、DNS、SSL 或任何现有网站；
- 未实现异步上传队列；Phase 1E 在单次请求内同步完成最多 30 MiB ZIP 的验证，后续如改为任务化必须保持同一私有快照和失败清理语义。
