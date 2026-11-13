# LandingOps Phase 1H：宝塔只读连接与站点隔离预检查

## 1. 交付范围

本阶段实现单台宝塔连接的安全登记、密钥轮换、显式启用/停用、只读连接检查、站点发现、候选 FQDN/目录冲突预检查和隔离能力报告。所有操作均属于控制面读取或本地数据库状态更新；没有创建、删除、修改或认领宝塔站点，没有读取生产站点文件，也没有修改 Nginx、PHP-FPM、ACL、SSL 或 DNS。

迁移 `005_baota_readonly_precheck.sql` 在既有 `baota_config` 单例上增加管理员状态、验证状态、脱敏提示、最近结果、能力摘要和乐观锁版本；实例增加外部站点 ID、根目录和 ownership marker 哈希的全有或全无证据组。001–004 保持不变。

## 2. 官方只读能力白名单

实现仅依据宝塔官方 API 文档当前可确认的动作：

| 路径 | action | 用途 |
| --- | --- | --- |
| `/system` | `GetSystemTotal` | 鉴权、面板版本和系统摘要 |
| `/data` | `getData`，固定 `table=sites` | 分页读取站点 ID、名称、路径和状态 |
| `/site` | `GetSiteDomains` | 按站点 ID 读取绑定域名 |
| `/site` | `GetPHPVersion` | 读取 PHP 版本清单 |
| `/site` | `GetSitePHPVersion` | 按站点名称读取 PHP 版本 |

签名使用官方双 MD5 约定，POST body 为 form-urlencoded。适配器没有任意 URL、路径、action 或 table 参数入口；重定向关闭，单次超时 5 秒，响应上限 1 MiB，网络错误、429 和 5xx 最多重试一次。站点分页每页 100、最多 20 页，业务发现再限制为最多 200 个站点，避免一次管理员请求形成无界负载。

官方参考：<https://docs.bt.cn/api/>、<https://docs.bt.cn/api/system/actions/>、<https://docs.bt.cn/api/data/getdata>、<https://docs.bt.cn/api/site/getsitedomains>、<https://docs.bt.cn/api/site/getphpversion>、<https://docs.bt.cn/api/site/getsitephpversion>。文档与面板版本可能变化，因此真实环境仍必须单独验证；不存在的能力标记为 `unsupported`，不会猜测端点。

## 3. 地址、密钥和状态

- `baota_config` 由数据库 `singleton_key=1` 与服务层共同保证只有一条。
- 仅总管理员可读写。API Key 使用现有 AES-256-GCM 密钥环加密，AAD 固定为 `landingops:baota-api-key:<config-id>`，与 Cloudflare 的 AAD 命名空间分离。
- API 仅返回脱敏面板 origin 和 Key 末四位提示，不返回完整地址、Key 明文、密文或签名参数；审计只记录动作、状态和非敏感摘要。
- `admin_state=enabled|disabled` 只能由管理员显式改变。地址或 Key 轮换使 `verification_status` 失效，但绝不自动重新启用停用连接。
- 更新与验证结果写回均使用 `version` 乐观锁，防止并发管理员覆盖。
- 保存地址只做格式校验。真实请求还要求 origin 精确存在于运行环境 `LANDINGOPS_BAOTA_ALLOWED_BASE_URLS`，未配置时默认拒绝，避免 SSRF 和意外内网访问。

## 4. 既有网站保护与冲突分类

站点发现结果默认是 `external`。只有外部站点 ID、实例 FQDN 与全部绑定信息、规范化根目录、可靠 ownership marker 和数据库实例记录全部严格一致时，评估器才可能返回 `managed`。实时适配器不会读取生产文件中的 marker，因此本阶段发现的既有站点不会自动满足认领条件。

候选完整域名预检查同时检查宝塔绑定、宝塔根目录、LandingOps 实例、活动域名预留和实例登记根目录。路径必须是规范化 POSIX 绝对路径，拒绝根目录、NUL、反斜杠和 `..`。结果语义：

- `clear`：已知元数据无冲突且受控目录观察明确为 absent；仍不是建站许可；
- `conflict`：发现 FQDN/目录占用、符号链接或未知内容；
- `needs_attention`：目录信息未知、目录空但来源不明，或 API 无法确认文件系统；
- `unsupported`：面板版本没有提供完成站点/绑定检查所需字段。

生产适配器的目录观察固定返回 `unsupported`，系统不会通过 SSH root 补读生产目录。因此通常只能给出 `needs_attention`，不能因站点清单里没找到域名就宣称目录安全。

## 5. 运行隔离结论

只读报告可以确认站点路径是否为可规范化的唯一绝对路径、PHP 版本清单是否可读。下列能力没有官方白名单证据，统一保守为 `unsupported`：独立 Unix 用户/ACL、每站独立 PHP-FPM pool、Nginx root 与 private 边界、release 原子切换同文件系统、宝塔默认文件精确识别。

只要这些证据未全部建立，PHP 模板、原生后台和第三方 PHP 组件的未来部署状态保持 `disabled`。现有 PHP 服务或 PHP 版本信息不等于执行隔离安全。

## 6. API 与 Vue 页面

| 方法与路径 | 权限 | 语义 |
| --- | --- | --- |
| `GET /api/v1/settings/baota` | 总管理员 | 返回单例脱敏配置或 `null` |
| `PUT /api/v1/settings/baota` | 总管理员 + CSRF | 创建、轮换、显式启停；更新要求 `expected_version` |
| `POST /api/v1/settings/baota/verifications` | 总管理员 + CSRF | 对当前启用凭据执行固定只读白名单检查 |
| `GET /api/v1/settings/baota/sites` | 总管理员 | 返回只读发现清单及外部所有权判断 |
| `POST /api/v1/settings/baota/prechecks` | 总管理员 + CSRF | 检查候选 `hostname` 的已知冲突 |
| `GET /api/v1/settings/baota/isolation-checks` | 总管理员 | 返回隔离能力与 PHP 部署禁用结论 |

Vue “宝塔服务器”页面只对总管理员开放，显示脱敏连接、状态、站点只读清单、FQDN 预检查和隔离不足项。页面没有创建/删除站点、修改配置、申请证书或发布按钮。

## 7. 本地配置与测试

```dotenv
LANDINGOPS_BAOTA_ALLOWED_BASE_URLS=https://panel.example.test:8888
LANDINGOPS_BAOTA_SITE_ROOT=/www/wwwroot/landingops
```

运行：

```powershell
.tools/php/php.exe tests/run.php
.tools/php/php.exe tests/mysql-integration.php
cd frontend
npm test
npm run build
```

单元测试使用模拟 transport/gateway，覆盖动作白名单、签名、分页、字段缺失、401/429/超时/异常 JSON、地址白名单、密钥加密轮换、三级角色、外部站点、全部所有权证据、多域名/路径/符号链接冲突和 PHP 隔离禁用。真实 MariaDB 脚本继续强制数据库名 `landingops_test`、回环 SSH 隧道和最小权限检查，并在事务回滚后验证 22 张业务表无测试数据。

## 8. 保留边界

真实宝塔联调尚未获本阶段单独授权，默认不发送请求。目标面板的实际版本、IP 白名单、上述官方动作的返回字段、分页行为、PHP 能力以及目录/用户/FPM/Nginx 隔离均待受控只读联调确认。任何真实联调都必须先核对 origin 白名单、使用受限 API Key，并再次确认全过程无写操作。

本轮验收结果：PHP `78 passed, 0 failed`；95 个 PHP 文件语法检查通过；Composer 严格校验通过；Vitest `6 files / 23 tests passed`；Vue TypeScript 与 Vite 生产构建通过。真实 MariaDB 通过固定主机指纹的回环 SSH 隧道连接，确认服务器为 `10.11.10-MariaDB-log`、当前数据库为 `landingops_test`，账号只有全局 `USAGE` 与测试库权限。集成脚本验证 22 张业务表、001–005、重复与失败迁移恢复、FK/CHECK/UNIQUE、PDO Session、负责人事务、模板上传授权、Cloudflare 密文与多 Zone 失效、域名授权、宝塔 Key AEAD 回读/轮换和单宝塔唯一约束；结束后 22 张业务表全部无测试数据，迁移账本保留 001–005。

本地 Codex 内置预览浏览器无法访问宿主机回环端口，因此没有将其冒充浏览器视觉验收；Vue 交付由 TypeScript、组件请求契约、导航隔离测试和生产构建验证。真实宝塔版本与页面数据仍须在操作者另行明确授权后只读确认。
