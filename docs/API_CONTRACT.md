# LandingOps 控制面 API 契约草案

## 1. 目的

本文固定 V1 的资源、授权、并发和错误语义，也标明当前 PHP 控制面子集。Phase 1G 只通过固定 Cloudflare 官方 API origin 执行 Token、Zone 和 DNS Read；Phase 1H 只通过运维白名单中的单一宝塔 HTTPS origin 执行文档确认的只读动作，均不执行第三方写操作。

建议前缀 `/api/v1`，JSON 使用 UTF-8、`snake_case`；时间为 UTC RFC 3339；资源 ID 为不可枚举 UUID/ULID。生产只允许 HTTPS。

## 2. 通用约定

### 2.1 认证与授权

- 管理端使用服务端 Cookie Session；Cookie 为 `Secure + HttpOnly + SameSite=Strict`，所有已登录写操作必须同时提交 `X-CSRF-Token`。Phase 1D 前端只在运行内存保存 Token，不写入 Local Storage、Session Storage 或 Cookie。
- `GET /me` 在鉴权成功后轮换 Session 的 CSRF Token，并在响应中返回新值，供页面刷新后恢复。新值生效后旧 Token 立即失效；同一 Session 的并行标签页需要重新调用 `/me` 获取当前 Token。
- 每个端点在后端依次校验：身份状态、角色、团队范围、资源所有权/负责人、资源状态和操作权限。
- Cloudflare Token、宝塔 API Key、后台密码哈希、供应商密钥永不进入响应。
- 资源不存在与无权访问可统一返回 404，降低跨团队枚举风险；安全审计保留真实原因。

### 2.2 请求关联与幂等

- 客户端可传 `X-Request-Id`，服务端验证格式或自行生成并在响应中返回。
- 创建实例、批次和重试等写操作要求 `Idempotency-Key`。同一操作者、端点和 key 在有效期内：相同请求摘要返回原结果；不同摘要返回 409。
- `PUT/PATCH` 的可变资源使用 `version` 或 `expected_revision` 做乐观锁，不接受无条件最后写入获胜。

### 2.3 分页与过滤

列表使用不透明游标：`?limit=50&after=<cursor>`，默认 50、最大 100。响应：

```json
{
  "data": [],
  "page": {"next_cursor": null, "has_more": false},
  "request_id": "request-example"
}
```

过滤字段采用白名单；排序字段由端点定义，不能把任意列名直接拼入 SQL。

### 2.4 错误格式

```json
{
  "error": {
    "code": "config_revision_conflict",
    "message": "配置已被其他来源更新，请重新加载。",
    "details": {"current_revision": 8},
    "retryable": false
  },
  "request_id": "request-example"
}
```

常用状态：400 请求格式、401 未认证、403 无权限、404 不存在/隐藏资源、409 冲突、422 业务/schema 校验、429 限流、503 暂不可用。错误不返回堆栈、文件路径、SQL、Token 或第三方原始敏感响应。

## 3. 身份、团队与用户

| 方法与路径 | 权限 | 语义 |
| --- | --- | --- |
| `GET /me` | 已登录 | 返回本人、角色、团队和安全能力摘要 |
| `GET /teams` | 总管理员 | 分页列出团队 |
| `POST /teams` | 总管理员 | 创建团队 |
| `PATCH /teams/{id}` | 总管理员 | 按 `version` 修改状态/名称 |
| `GET /users` | 总管理员/团队管理员 | 全部或本团队范围 |
| `POST /users` | 总管理员/团队管理员 | 创建范围内用户；团队管理员只能创建本团队员工 |
| `PATCH /users/{id}` | 范围内管理员 | 修改资料/状态，不允许越权提升角色 |

角色不是客户端可自由提交的信任字段。团队管理员请求中的 `team_id` 应由服务端绑定或强制等于其团队。

## 4. 模板与授权

| 方法与路径 | 权限 | 语义 |
| --- | --- | --- |
| `GET /templates` | 所有角色 | 只返回有权查看的元数据与能力；无视觉预览字段 |
| `POST /templates/uploads` | 总/团队管理员 | multipart 单文件上传 ZIP，先进入私有不可执行 intake，再同步完成 Phase 1E 校验与不可变版本登记；默认压缩包 30 MiB、解压 100 MiB、2,000 文件、单文件 30 MiB |
| `GET /template-versions/{id}/validation` | 有权查看者 | 返回脱敏验证报告 |
| `POST /template-versions/{id}/grants` | 总管理员 | 授权一个团队 |
| `DELETE /template-versions/{id}/grants/{team_id}` | 总管理员 | 撤销未来使用权，不操作实例 |

上传响应必须包含版本 ID、SHA-256 和验证状态。相同内容返回 `409 template_hash_conflict`；相同 `slug + version` 不同哈希返回 `409 template_version_immutable`；并发首次创建相同 slug 返回 `409 template_slug_conflict`；LTS 校验失败返回 `422 template_validation_failed` 及脱敏报告。响应永不包含私有绝对路径或 `zip_storage_key`。

团队管理员上传的版本落库后，服务端依据会话用户、版本上传者和模板数据库归属，为上传团队创建默认 `active` grant。后续选用模板只检查服务端 `template_grants.status=active`；客户端不得提交 `owner_team_id` 作为授权依据。总管理员可撤销或再次授权，模板所有权不能绕过已撤销 grant。撤销只阻止该团队用此版本创建新实例，不修改已经绑定该版本的实例、release 或运行文件；总管理员仍拥有所有已存在模板版本的最高访问权限。

## 5. 连接、Zone 与主域名

| 方法与路径 | 权限 | 语义 |
| --- | --- | --- |
| `GET/PUT /settings/baota` | 总管理员 | 读取脱敏状态/创建或轮换唯一配置；不得创建第二条 |
| `GET /cloudflare-connections` | 总管理员 | 返回 label、token_hint、状态，不返回密文 |
| `POST /cloudflare-connections` | 总管理员 | 使用环境主密钥认证加密 Token；新连接固定为 `unverified` |
| `PATCH /cloudflare-connections/{id}` | 总管理员 | 修改元数据、轮换 Token 或显式启停；轮换仅重置验证状态 |
| `GET /cloudflare-zones` | 总管理员 | 列出 Zone 与其唯一连接；不返回 Token |
| `POST /cloudflare-zones` | 总管理员 | 人工登记明确 Zone；初始验证状态为 `unverified` |
| `POST /cloudflare-connections/{id}/verifications` | 总管理员 | 只读验证 Token、指定 Zone 与 DNS Read；提交连接/Zone 乐观锁版本 |
| `PATCH /cloudflare-zones/{id}` | 总管理员 | 仅显式启停；不能由客户端写入验证成功 |
| `GET /primary-domains` | 所有角色 | 自动限制到有权使用的主域名 |
| `POST /primary-domains` | 总管理员 | 在明确 Zone 下登记规范化主域名，不创建 DNS |
| `PATCH /primary-domains/{id}` | 总管理员 | 启用/停用登记资源，不修改 DNS |
| `POST /primary-domains/{id}/grants` | 总管理员 | 授权团队 |
| `DELETE /primary-domains/{id}/grants/{team_id}` | 总管理员 | 撤销未来创建权限，不改 DNS/实例 |
| `POST /primary-domains/{id}/dns-prechecks` | 总管理员 | 精确查询候选域名记录并分类；绝不写 DNS |

连接和 Zone 的 `admin_state` 与 `verification_status` 独立；Token 轮换不得改变管理员启停状态。连接的 `credential_context_version` 在 Token 轮换或 Account ID 变更时递增，并使全部关联 Zone 的旧验证证据失效；Zone 只有 `verified_credential_context_version` 等于连接当前版本才可用。PATCH 必须提交 `expected_version`，陈旧写入返回 409。只读验证只在 Token active、Zone ID/名称/账号匹配且 DNS Read 成功后更新所选 Zone 的可信状态。

预检查结果为 `clear|compatible|needs_configuration|conflict`。`compatible` 仅表示所有精确同名记录完整符合 `type=A`、管理员确认 IPv4、`proxied=false`、`ttl=1`；同 IP 但代理开启、TTL 不符、存在其他类型均为冲突，缺少必要元数据或确认 IP 为无法安全确认。`clear` 仅表示未发现精确同名记录，不代表已排除通配符记录、NS 委派或其他 DNS 风险。

`api_token` 只在创建/轮换请求体中出现；响应、普通日志和审计不得包含明文或密文。未配置环境密钥时 Token 写入返回 `503 secret_key_unavailable`，而不是明文降级。总管理员看到全部资源；团队管理员看到本团队有效授权（包括明确标记的不可用状态）；员工只看到授权、管理员启用且连接/Zone 验证有效的资源。只读适配器固定官方 origin、禁止重定向和任意路径，并设置超时、有限重试、响应上限与脱敏错误。员工不能提交 connection ID 来改变 Zone 路由。

V1 主域名策略默认值为 `A → 已确认公网 IPv4`、`proxied=false`、TTL Auto，不自动创建 AAAA/CNAME/通配符；这些值只能由总管理员在真实环境确认后按主域名显式调整。

## 6. 实例与部署

### 6.1 创建单站

`POST /instances`，要求 `Idempotency-Key`。示例请求仅使用保留示例域名：

```json
{
  "team_id": "team-example",
  "template_version_id": "template-version-example",
  "primary_domain_id": "domain-example",
  "subdomain_prefix": "campaign-a",
  "owner_user_id": "user-example",
  "native_admin": {"enabled": false},
  "config": {
    "pixel_ids": [],
    "events": {
      "download": {"enabled": true},
      "add_to_cart": {"enabled": false, "value": 1, "currency": "USD"},
      "purchase": {"enabled": false, "value": 1, "currency": "USD"}
    },
    "links": {"download_url": "https://downloads.example.test/app", "redirect_url": null},
    "custom": {}
  }
}
```

服务端从主域名计算并规范化 FQDN，不能接受客户端提交最终路径、宝塔地址或 Cloudflare 连接。成功返回 202，包括 `instance_id`、`job_id`、`status=queued`；冲突/校验失败前不产生残留预留。

原生后台默认关闭。若显式启用，请求必须额外包含用户设置的 `native_admin.password`（V1 默认至少 12 字符，允许长口令）；该字段只在 TLS 请求内短暂处理，必须从访问日志、任务 payload、幂等请求缓存和审计中删去，站点只保存现代密码哈希。服务端不生成或回显可恢复的明文密码。

### 6.2 批量

- `POST /deployment-batches/validate`：纯验证，逐行给出规范化结果和错误，不持有域名。
- `POST /deployment-batches`：要求幂等 key，为有效行各建一个实例/任务；响应 202。
- `GET /deployment-batches/{id}`：聚合统计和子任务游标。

验证结果可能在确认前过期；正式提交必须重新做权限和唯一预留，不能依赖前端预检。

### 6.3 查询与任务操作

| 方法与路径 | 权限 | 语义 |
| --- | --- | --- |
| `GET /instances` | 角色范围 | 总管理员全部、团队管理员本团队、员工本人负责 |
| `GET /instances/{id}` | 资源范围 | 返回实例、配置摘要和当前任务，不返回密钥 |
| `GET /jobs/{id}` | 资源范围 | 返回步骤、尝试、脱敏错误与人工提示 |
| `POST /jobs/{id}/retry` | 资源范围+安全状态 | 创建/唤醒重试，不重置成功步骤证据 |
| `POST /jobs/{id}/cancel` | 资源范围+安全状态 | 请求协作取消；返回 202，不承诺即时完成 |
| `POST /instances/{id}/verify` | 有权管理者 | 创建验证任务，不同步长轮询第三方 |

重试/取消请求不得直接让 Web 进程调用外部 API。

## 7. 配置与负责人

### 7.1 配置

- `GET /instances/{id}/config`：返回 schema 允许的安全配置、`revision`、私有 `content_hash`、公开 `public_hash`、active `release_id` 和 `source`。
- `PUT /instances/{id}/config`：请求包含 `expected_revision`、完整 `config`、可选 `reason`；统一返回 202 和配置发布任务 ID。Web 请求不直接写生产目录。
- `POST /instances/{id}/config-restores`：包含 `source_revision`、`expected_revision`、`reason`，复制历史 payload 并排队创建更高 revision；不能直接激活旧 revision。

版本不符返回 409 和当前 revision；不能自动合并事件/链接或覆盖原生后台更新。发布任务通过同一 `publish_config` 库生成私有 canonical 文档、白名单公开 JS 和完整 release，再原子切换 `current`。响应不包含后台密码哈希或公开字段白名单之外的私有值。

### 7.2 负责人转交

`POST /instances/{id}/owner-transfers`：

```json
{
  "to_user_id": "user-example",
  "expected_instance_version": 4,
  "reason": "项目交接"
}
```

后端校验同团队启用用户，事务内更新负责人、追加历史和审计；创建人不变。员工无权调用。

## 8. 备用页与供应商组件元数据

| 方法与路径 | 权限 | 语义 |
| --- | --- | --- |
| `POST /backup-page-assets` | 总管理员 | 上传普通 HTML，私有保存、校验、不可变版本化 |
| `GET /backup-page-assets` | 总管理员 | 元数据列表，无截图字段 |
| `POST /vendor-component-versions` | 总管理员 | 上传独立组件，安全扫描后登记版本 |
| `GET /vendor-component-versions` | 总管理员 | 只返回版本/哈希/PHP 约束/状态 |

上传疑似凭据时返回阻断错误，错误细节只说明位置类别，不回显内容。

## 9. 审计

`GET /audit-logs` 按角色自动限定范围，支持资源、动作、时间游标过滤。审计为只读、追加写，不提供普通 API 更新/删除。响应对配置值和网络标识做脱敏，且不暴露第三方原始请求/响应。

## 10. 明确不提供的接口

- 任意服务器列表、服务器池、为实例选择宝塔服务器。
- 直接代理站点访客流量或让站点运行时读取控制面配置。
- 接管/覆盖/删除未知宝塔站点或任意现有 DNS 的通用接口。
- 模板截图、预览、缩略图生成或独立预览服务。
- 审核规避、按检测人员身份分流或随机隐藏文件的接口。

## 11. Phase 1H 实现状态

以下路径均位于 `/api/v1`：

| 方法与路径 | 当前状态 |
| --- | --- |
| `POST /login`、`POST /logout`、`GET /me` | 已实现；登录返回安全用户字段、CSRF Token 和过期时间；`GET /me` 返回身份、能力并轮换 CSRF Token |
| `PATCH /me/profile`、`POST /me/password` | 已实现；改密后撤销该用户全部 Session |
| `GET/POST /teams` | 已实现；仅总管理员 |
| `GET/POST /users`、`PATCH /users/{id}` | 已实现查询、创建和停用；团队管理员只能操作本团队员工 |
| `GET /instances` | 已实现授权范围查询；不包含实例创建或部署 |
| `POST /instances/{id}/owner-transfers` | 已实现服务层、授权、乐观锁、历史和审计契约 |
| `GET /templates`、`POST /templates/uploads` | 已实现角色范围查询与单 ZIP 上传；上传沿用 LTS v1.0 验证器 |
| `GET /template-versions/{id}/validation` | 已实现授权范围内的脱敏验证报告 |
| `POST /template-versions/{id}/grants`、`DELETE /template-versions/{id}/grants/{team_id}` | 已实现总管理员授权、撤销、再次授权与审计 |
| Cloudflare 连接、Zone、主域名与团队授权 | 已实现资源登记、加密 Token、状态管理和只读验证；不提供 DNS 写入 |
| `GET/PUT /settings/baota` | 已实现总管理员读取/登记/轮换/显式启停单例；响应仅含脱敏 Key 提示 |
| `POST /settings/baota/verifications` | 已实现固定 origin、固定 action 的只读连接检查；提交 `expected_version`，不接受任意 URL/action |
| `GET /settings/baota/sites` | 已实现站点、绑定域名、根目录和 PHP 元数据发现；全部既有站点默认是外部资源 |
| `POST /settings/baota/prechecks` | 已实现候选 `hostname` 的 FQDN、根目录、实例与预留冲突检查；不写面板或文件系统 |
| `GET /settings/baota/isolation-checks` | 已实现只读隔离能力报告；证据不足时 PHP 部署保持禁用 |
| 部署任务与宝塔/DNS 写入端点 | 明确不提供或返回 `501 not_implemented`，不得返回伪成功 |

当前控制面列表端点暂不实现客户端游标翻页；返回固定 `has_more=false`。宝塔适配器内部按官方 `getData` 分页读取并设置安全上限。所有宝塔写操作仍不属于当前 API。

