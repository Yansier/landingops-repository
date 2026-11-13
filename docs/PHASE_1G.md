# LandingOps Phase 1G：Cloudflare 只读验证与 DNS 预检查

## 1. 交付范围

Phase 1G 在不写入 Cloudflare 的前提下，加入真实只读适配器、连接/Zone 验证、DNS 精确名称预检查和 Vue 操作界面。固定调用 Cloudflare 官方 API origin，外部输入只能提供经过格式校验的 Zone ID 与域名，不能提供 URL。未实现任何 DNS 创建、修改、删除、SSL、站点或部署操作。

## 2. 状态与并发

迁移 `003_split_cloudflare_admin_verification_state.sql` 为连接和 Zone 分离：

- `admin_state=enabled|disabled`：仅由总管理员显式启用/停用；
- `verification_status=unverified|valid|invalid`：只读验证结果；
- Zone 的 `cloudflare_status` 保存官方返回的实际状态；
- `version` 用于乐观锁，停用、启用、Token 轮换、验证提交都拒绝陈旧版本；
- Token 轮换只重置验证状态，绝不改变 `admin_state`；重新启用有独立操作和审计。

旧 `status` 暂时作为兼容投影保留。003 只新增列、回填和 CHECK，不修改 001、002，不删表或业务数据，并由 `schema_migrations` 保证重复执行跳过。

Phase 1G.1 新增 004 修正信任证据：连接以 `credential_context_version` 标识当前 Token 与 Account ID 组合，Zone 以 `verified_credential_context_version` 记录验证所依据的上下文。轮换 Token 或修改 Account ID 时，服务在同一事务中递增上下文版本，并将该连接下所有 Zone 改为 `unverified`、清除官方状态和绑定版本、递增 Zone 乐观锁；各资源的 `admin_state` 原样保留。重新验证 Zone A 只恢复 A，不会恢复 Zone B。

003 从旧 `status=active` 推断 `valid` 没有足够的可审计证据。004 因此保守地把所有未绑定上下文的旧 active/valid 连接和 Zone 重置为 `unverified`；空库执行无数据影响，已执行 004 由迁移账本跳过，不修改 001、002、003。

## 3. 只读验证

总管理员选择已登记 Zone 后触发验证。后端在受控范围内解密 Token，依次验证 Token 状态、读取 Zone 详情并执行一次精确 Zone 名的 DNS 列表请求，以证明 Zone Read 与 DNS Read 权限。只有三步全部成功且 Zone ID、规范化名称、可选账号 ID 均匹配，才以事务提交受信任状态和审计。

HTTP 适配器固定 `https://api.cloudflare.com/client/v4`，禁止重定向，只允许三个路径形状；单次超时 5 秒，最多两次尝试，仅网络错误、429 和 5xx 重试一次，响应上限 1 MiB。响应、日志、审计和 API 均不包含 Token、密文或 Cloudflare 原始敏感响应。401、403、429、超时、无效响应和资源不匹配使用明确错误码。

## 4. DNS 预检查

`POST /api/v1/primary-domains/{id}/dns-prechecks` 仅允许总管理员对所选主域名范围内的完整候选域名执行精确名称查询。结果为：

- `clear`：没有同名记录；
- `compatible`：全部精确同名记录完整满足 A、管理员确认 IPv4、`proxied=false`、`ttl=1`；
- `needs_configuration`：存在记录，但未配置管理员确认的公网 IPv4，不能判断；
- `conflict`：存在 CNAME/AAAA/其他类型或 A 地址不一致，需要人工确认。

公网 IPv4 只能通过私有运行环境 `LANDINGOPS_CONFIRMED_PUBLIC_IPV4` 明确配置，系统不会探测或猜测。所有分类均为只读结论，不产生 DNS 写入。

Phase 1G.1 收紧 `compatible`：每条精确同名记录必须同时满足 `type=A`、地址等于确认 IPv4、`proxied=false`、`ttl=1`。同 IP 但代理开启、TTL 不符、AAAA/CNAME/其他记录均不兼容；响应缺少 proxied/TTL 或尚无确认 IP 时返回 `needs_configuration`。`clear` 只说明没有精确同名记录，不能排除通配符和 NS 委派；正式 DNS 写入阶段必须重新检查这些规则，本轮没有新增写入能力。

## 5. API 与前端

- `POST /api/v1/cloudflare-connections/{id}/verifications`：CSRF + 总管理员；提交 `zone_id`、连接和 Zone 预期版本。
- `POST /api/v1/primary-domains/{id}/dns-prechecks`：CSRF + 总管理员；提交 `hostname`。
- 连接页显示管理员状态、API 验证状态、最近结果，并提供验证、轮换、停用和显式重新启用。
- 连接页验证操作要求管理员明确选择同一连接下的一个 Zone，并同时展示每个 Zone 的管理员状态、验证状态、官方状态和证据上下文；不再默认使用第一个 Zone。
- 域名页显示 Zone 实际状态，并提供 DNS 只读检查及记录冲突说明；没有 DNS 写入按钮。

## 6. 验证与保留边界

自动化覆盖成功、401、403、429、超时、失效 Token、错误 Zone、SSRF 路径拒绝、DNS clear/compatible/conflict、停用后轮换、并发陈旧版本及显式启用。真实 MariaDB 脚本继续强制回环 SSH 隧道、数据库名 `landingops_test` 与最小授权检查；缺少活动隧道或本地安全凭据时明确 SKIP。

真实 Cloudflare 联调不属于默认测试：必须由操作者明确授权并注入权限受限的测试 Token。本阶段不调用宝塔，不创建站点、DNS、SSL，不开发 Worker、多服务器、截图/预览或审核规避逻辑。

本轮验收结果：PHP `72 passed, 0 failed`；83 个 PHP 文件语法检查无错误；Composer 严格校验通过；Vitest `6 files / 22 tests passed`；Vue TypeScript 与 Vite 构建通过；npm audit 为 0 个已知漏洞；本地浏览器已核验多 Zone 验证对话框没有默认选择，并能同时展示各 Zone 的管理员状态、验证状态、官方状态及证据上下文。本地 known_hosts 中的 ED25519 指纹已与操作者提供值核对一致，但验收时隧道状态仍为 `starting`、`127.0.0.1:13306` 没有活动监听且未取得本地 MySQL 安全凭据，真实 MariaDB 测试明确 SKIP，未连接任何数据库。未取得受限测试 Token 的显式授权，因此没有执行真实 Cloudflare 请求，所有第三方响应测试均为模拟。
