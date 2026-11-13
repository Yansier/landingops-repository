# LandingOps Phase 1F：Cloudflare 连接、主域名与团队授权

## 1. 交付范围

本阶段在既有 22 表控制面上开放以下可运行能力：

- 总管理员登记、查看、修改、轮换 Token 和停用多个 Cloudflare Connection；
- 总管理员在明确连接下登记 Zone，在 Zone 下登记规范化主域名；
- 总管理员授权、撤销和重新授权团队使用主域名；
- 团队管理员查看本团队获授权资源及其不可用原因；员工只看到当前真正可用的授权资源；
- Vue 后台开放“域名管理”和总管理员专属“Cloudflare 连接”页面；
- 修复 Phase 1E 并发首次创建相同模板 slug 的 500，以及暂存复制失败的部分文件残留。

已有 `cloudflare_connections`、`cloudflare_zones`、`primary_domains`、`domain_grants` 足以表达本阶段契约，因此没有新增迁移，也没有修改 001、002。

## 2. Token 密钥与密文

Token 使用 OpenSSL AES-256-GCM：每次加密生成 12 字节随机 nonce、16 字节认证标签，并以 `landingops:cloudflare-token:{connection_id}` 作为 AAD，避免密文跨记录替换。`secret_key_version` 允许密钥轮换；数据库只保存二进制密文、版本和末四位提示。

运行环境必须私下配置：

```text
LANDINGOPS_SECRET_ACTIVE_VERSION=v1
LANDINGOPS_SECRET_KEYS_JSON={"v1":"<32 字节随机密钥的 Base64>"}
```

密钥不得写入 `.env` 受版本控制文件、数据库、命令参数、前端或日志。生产建议由 systemd/PHP-FPM secret 环境或等价密钥注入设施提供，并把密钥文件权限限制为控制面进程可读。缺少密钥时列表仍可读取脱敏元数据，但创建或轮换 Token 返回 `503 secret_key_unavailable`，绝不降级为明文。

轮换主密钥时先把新旧版本同时加入密钥环并切换活动版本，再通过 Token 轮换写入新版本。删除旧密钥前必须确认没有数据库记录仍引用旧 `secret_key_version`。

## 3. 状态与授权语义

- 新连接和 Zone 一律为 `unverified`；没有真实 Cloudflare 验证 API，因此本阶段接口只能改为 `unverified` 或 `disabled`，不能写出虚假的 `active`。
- 主域名登记状态为 `active/disabled`，但 `available` 只有在连接、Zone、主域名都为 active 时才为真；上游未验证会明确显示不可用。
- Zone 名称和主域名使用小写、去除末尾点的 ASCII 规范化；主域名必须等于 Zone 或位于其标签边界下。规范化 Zone 和主域名均拒绝重复。
- 总管理员可见全部；团队管理员仅见本团队活动 grant；员工还要满足完整资源链 active。
- 授权、撤销、重新授权以及连接/Zone/域名状态变更均写审计。撤销不改 DNS、不删除实例，也不影响已部署网站运行。

## 4. API

所有写请求继续使用 Cookie Session、`X-CSRF-Token` 和统一错误格式：

| 路径 | 权限 | 行为 |
| --- | --- | --- |
| `GET/POST /api/v1/cloudflare-connections` | 总管理员 | 脱敏列表 / 加密登记 |
| `PATCH /api/v1/cloudflare-connections/{id}` | 总管理员 | 元数据、Token 轮换、停用 |
| `GET/POST /api/v1/cloudflare-zones` | 总管理员 | 列表 / 未验证登记 |
| `PATCH /api/v1/cloudflare-zones/{id}` | 总管理员 | 未验证或停用状态 |
| `GET /api/v1/primary-domains` | 所有角色 | 服务端按角色和团队过滤 |
| `POST /api/v1/primary-domains` | 总管理员 | 在 Zone 下登记，不创建 DNS |
| `PATCH /api/v1/primary-domains/{id}` | 总管理员 | 启用/停用登记资源 |
| `POST /api/v1/primary-domains/{id}/grants` | 总管理员 | 授权或重新授权团队 |
| `DELETE /api/v1/primary-domains/{id}/grants/{team_id}` | 总管理员 | 撤销未来使用权 |

响应不含 `token_ciphertext`、`secret_key_version` 或原始 Token。前端密码输入框不做持久化，也没有 DNS、SSL、部署或“验证成功”按钮。

## 5. Phase 1E 补强

- 上传前查询与事务提交之间若另一个请求先创建同 slug，唯一约束失败会在回滚后识别为 `409 template_slug_conflict`，持久化 ZIP 同时删除。
- `PrivateTemplateStorage::stage()` 将复制包在失败清理中；即使底层复制器先写出部分目标再抛错，随机 intake 文件也会删除。
- `tests/mysql-integration.php` 已增加真实 PDO 模板 ZIP 上传、版本入库、默认授权、列表、撤销和重新授权，以及 Cloudflare 密文/Zone/主域名/团队授权覆盖。脚本仍强制数据库名为 `landingops_test`、只接受回环隧道，并检查账号权限范围。

## 6. 本地验证

```powershell
.\.tools\php\php.exe tests\run.php
.\.tools\php\php.exe tests\mysql-integration.php  # 未配置私有变量时只输出 SKIP
.\.tools\php\php.exe .\.tools\composer.phar validate --strict

cd frontend
npm test -- --run
npm run build
npm audit --audit-level=moderate
```

真实 MariaDB 测试只能在操作者显式建立 `127.0.0.1:13306` SSH 隧道并通过本地安全环境变量提供测试库凭据后执行。本阶段开发时没有活动隧道或私有配置，因此未主动连接。

本轮最终本地结果：PHP `65 passed, 0 failed`；Vitest `6 files / 21 tests passed`；Vue TypeScript 与 Vite 生产构建通过；75 个 PHP 文件语法检查通过；Composer 严格校验通过；`npm audit` 为 0 个已知漏洞。本地目标浏览器已检查移动宽度下的新导航、域名/连接空状态和 Token 登记弹窗。MySQL 脚本因缺少隧道和私有凭据明确输出 `SKIP`，未连接数据库，也未将 SQLite 结果冒充 MariaDB。

## 7. 明确保留边界

- 不调用 Cloudflare API，不读取、创建、修改或删除 DNS；`last_verified_at/last_synced_at` 不伪造。
- 不连接宝塔，不创建站点、证书或生产 Worker。
- 不支持多服务器、模板截图/预览或审核规避分流。
- 仍不实现实例创建；主域名授权作为后续实例服务的真实后端边界保留。
