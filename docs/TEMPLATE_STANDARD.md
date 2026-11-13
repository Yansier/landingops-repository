# LandingOps Template Standard（LTS）v1.0

## 1. 目的与边界

LTS 定义上传 ZIP 的可验证输入、实例化后的本地配置以及可选原生后台的协作规则。它不定义模板视觉效果，不包含截图/预览字段，也不要求站点在运行时连接 LandingOps。

三个入口概念必须区分：

- ZIP 根目录的 `index.html`：模板提供者提交的**原始落地页内容文件**，固定必需。
- 实例根目录可能出现的 `index.php`：由获准的供应商集成组件装配的 Web 入口，不属于基础模板，也不能与 `index.html` 混为一谈。
- `admin/index.php`：模板声明支持时才可能发布的站点本地管理入口，默认关闭。

## 2. ZIP 目录规范

```text
template.zip
├── landingops.json          # 必需，UTF-8 JSON
├── index.html               # 必需，原始落地页内容
├── config.schema.json       # 声明 configurable 时必需
├── assets/                  # 可选，CSS/JS/字体/图片等相对资源
└── admin/                   # 可选，仅 native_admin.supported=true
    └── index.php            # 可选原生后台入口
```

约束：

- ZIP 解压后的顶层直接包含 `landingops.json` 与 `index.html`，不接受额外包裹目录。
- 路径使用 `/`、区分大小写，并采用相对路径。拒绝绝对路径、盘符、`..`、NUL、符号链接、硬链接和设备文件。
- 单 ZIP 默认上限 30 MiB；解压后默认上限 100 MiB、文件数 2,000、单文件 30 MiB。最终限制可由管理员收紧，不能放宽到无界。
- 文件名必须可规范化且不得发生大小写折叠冲突。禁止提交 `.env`、私钥、凭据、服务器配置覆盖文件和可执行二进制。
- 是否允许模板 PHP 文件由 `runtime.php` 与管理员策略共同决定；基础 `index.html` 始终必需。供应商组件不得偷偷包含在 ZIP 中。
- 相对资源必须能在独立站点目录工作，不得硬编码 LandingOps 地址或数据库连接。

## 3. `landingops.json`

### 3.1 顶层字段

| 字段 | 类型 | 必需 | 说明 |
| --- | --- | --- | --- |
| `standard` | string | 是 | 固定 `landingops-template` |
| `standard_version` | string | 是 | 本版固定 `1.0` |
| `template` | object | 是 | 模板身份与展示元数据 |
| `entry` | object | 是 | 原始内容入口，`source` 固定 `index.html` |
| `runtime` | object | 是 | PHP/组件要求 |
| `capabilities` | object | 是 | 可配置能力声明 |
| `native_admin` | object | 是 | 原生后台声明 |
| `config` | object | 是 | schema 与本地配置位置 |
| `integrity` | object | 否 | 模板方提供的非密钥完整性元数据 |

严格校验：未知顶层字段默认拒绝，避免拼写错误被静默忽略。字段命名使用 `snake_case`；所有文本均为 UTF-8；版本使用 SemVer。

### 3.2 关键对象

`template`：

- `slug`：`[a-z0-9][a-z0-9-]{1,62}`，逻辑模板稳定标识。
- `name`：1–100 字符。
- `version`：SemVer，如 `1.2.0`。
- `description`：最多 500 字符。

`entry`：

- `source`：固定 `index.html`。
- `asset_base`：默认 `./`，V1 仅允许站内相对路径。

`runtime`：

- `php.required`：布尔值。
- `php.constraint`：PHP 版本约束；不需要 PHP 时为 `null`。
- `vendor_component.required`：是否需要管理员已批准的独立供应商组件。
- `vendor_component.compatibility`：兼容版本约束；不得包含密钥或组件源码。

`capabilities`：

- `configurable`：是否存在实例配置。
- `pixel_ids.multiple`：V1 应为 `true`；`max_items` 给出模板上限。
- `events`：可从 `Download`、`AddToCart`、`Purchase` 中声明子集。
- `links.download` / `links.redirect`：是否支持对应链接。
- `custom_config`：是否有 schema 定义的其他字段。

`native_admin`：

- `supported`：是否支持；默认语义为 `false`。
- `entry`：支持时为 `admin/index.php`。
- `auth`：V1 固定 `local_password`。
- `default_enabled`：必须为 `false`。
- `config_contract`：固定 `lts-local-config/1.0`。

`config`：

- `schema`：可配置时固定或指向 `config.schema.json`。
- `local_path`：固定建议 `.landingops/config.json`，不得在 Web 中直接下载。
- `schema_version`：模板配置 schema 的版本，如 `1.0.0`。

完整示例见 [`examples/landingops.json`](examples/landingops.json)。明确不存在 `screenshot`、`thumbnail`、`preview_url`、`image` 等展示字段。

## 4. `config.schema.json`

使用 JSON Schema Draft 2020-12 的受限子集。根类型必须是 object，必须设置 `additionalProperties: false`。不允许远程 `$ref`、运行时代码、动态表达式或密钥默认值。

标准配置逻辑模型：

```json
{
  "pixel_ids": ["123456789012345"],
  "events": {
    "download": {"enabled": true},
    "add_to_cart": {"enabled": false, "value": 1, "currency": "USD"},
    "purchase": {"enabled": false, "value": 1, "currency": "USD"}
  },
  "links": {
    "download_url": "https://downloads.example.test/app",
    "redirect_url": null
  },
  "custom": {}
}
```

语义规则：

- `pixel_ids` 去重并保序；每项按目标像素平台允许的字符/长度校验，不能作为任意脚本注入点。
- `Download` 只能绑定实际下载点击/开始；`AddToCart` 只能绑定实际加入购物车；`Purchase` 只能在真实购买完成时触发。
- 涉及金额的事件默认 `value=1`、`currency=USD`，但实例可修改；金额使用十进制定点语义，拒绝负值和非有限数。
- URL 默认只允许 `https`，是否允许 `http` 仅限明确的开发环境策略；拒绝 `javascript:`、`data:` 和协议相对 URL。
- 页面浏览不得伪装成购买或加购事件。

完整 schema 示例见 [`examples/config.schema.json`](examples/config.schema.json)。

## 5. 实例本地配置契约

实例实际文件 `.landingops/config.json` 包含信封和 payload：

```json
{
  "contract": "lts-local-config/1.0",
  "schema_version": "1.0.0",
  "revision": 7,
  "content_hash": "sha256:<64-hex>",
  "updated_at": "2026-11-13T00:00:00Z",
  "updated_by": {"type": "landingops", "id": "user-uuid"},
  "native_admin": {"enabled": false, "password_hash": null},
  "config": {}
}
```

- `content_hash` 对规范化后的 `config` 与非密钥运行设置计算，不把 `content_hash` 自身纳入。
- `revision` 每次成功写入加一，不允许回退或复用。
- 时间统一存 UTC RFC 3339；展示时再转换时区。
- `password_hash` 只能是 PHP 支持的现代单向哈希，不得存明文；读取接口永不返回该字段值。
- Web 服务器应禁止直接访问 `/.landingops/`；PHP 运行代码通过受控本地文件路径读取。
- 站点不得从 LandingOps API/数据库补齐配置。文件缺失/损坏时使用同目录最近已知良好备份或显示安全维护页，不能跨网查询控制面。

## 6. 原生后台接口契约

### 6.1 身份与范围

- 原生后台只管理当前站点，不具备 LandingOps 用户、团队、模板、域名或其他实例权限。
- 使用本站独立密码建立安全会话；密码哈希位于受保护的本地配置/认证文件。
- 知道密码者即可按该后台能力修改本站，独立于 LandingOps 当前负责人。
- 默认关闭。关闭时 `/admin` 应返回 404/403 或入口文件不发布，不能仅隐藏导航。

### 6.2 站点内 HTTP 契约

若模板提供后台，建议同源实现以下语义（具体 PHP 文件路由可由模板映射）：

| 操作 | 语义 | 核心要求 |
| --- | --- | --- |
| `POST /admin/session` | 登录 | 限速、会话轮换、Secure/HttpOnly/SameSite Cookie、统一错误 |
| `DELETE /admin/session` | 登出 | 服务端失效会话 |
| `GET /admin/config` | 读取本站配置 | 返回 schema 允许字段、`revision`、`content_hash`；不返回密码哈希 |
| `PUT /admin/config` | 替换本站配置 | 必须带 `expected_revision`，schema/语义校验，原子写 |
| `PUT /admin/password` | 修改本站后台密码 | 验证当前密码、强度限制、重新哈希并撤销旧会话 |

成功更新返回新 `revision` 和 `content_hash`；版本不一致返回 `409 config_revision_conflict`；校验错误返回 `422 config_invalid`。错误体不泄露路径、堆栈或密钥。

### 6.3 LandingOps 文件适配器契约

LandingOps 更新本站时优先通过服务器本地文件适配器，而非依赖原生后台在线：

```text
read(instance_path) -> {revision, content_hash, safe_config}
compare(expected_revision) -> ok | conflict(current_revision)
write_atomic(expected_revision, validated_document) -> {revision, content_hash}
restore(last_known_good_revision) -> result
```

适配器必须取得实例级锁、重新读取、写同目录临时文件、校验、原子重命名并保留恢复副本。数据库写入失败时，本地新版本仍是运行时真相；下次协调按哈希修复控制面记录，不能用旧数据库值反向覆盖。

## 7. 实例化与资源替换

- 构建器将模板版本复制到新实例暂存目录，按受控映射注入配置；禁止字符串全盘替换未知 HTML。
- 模板 JS/PHP 从本地配置读取值并在正确上下文转义。
- 若使用独立供应商组件，按管理员批准的组件版本装配 `index.php/core.php`；记录组件版本与哈希，不修改核心文件。
- 模板源 `index.html` 应保留为审计输入。最终 Web 入口由构建计划决定，但不能改变三个入口概念的归属。
- 当前普通备用 HTML 是独立版本化资产。模板可声明合法维护页挂载点，但不得把备用页当截图或审核分流素材。

## 8. 兼容性与升级

- LTS `standard_version` 遵循主版本兼容：验证器支持同一主版本的已知字段；未知必需能力直接拒绝。
- 模板 `version` 发布后不可变，相同 `slug + version` 不允许上传不同哈希。
- `schema_version` 改变时，模板新版本可提供声明式迁移说明；V1 不自动迁移已部署实例。
- 实例升级必须显式选择目标模板版本，先验证配置兼容、生成备份、暂存、验收后切换；失败保留旧站点文件。
- 撤销模板团队授权不改变任何已部署实例。

## 9. Phase 1B 标准验证步骤与通过标准

1. **ZIP 安全扫描**：恶意路径、链接、超限包、重名路径全部拒绝且无目录逃逸。
2. **必需文件**：根目录两个必需文件存在、UTF-8、JSON 可解析；缺一即拒绝。
3. **Manifest 校验**：版本、slug、入口、PHP/组件、能力和后台声明符合 schema；未知字段按策略拒绝。
4. **配置 schema 校验**：根对象闭合、无远程引用；默认实例配置可通过验证。
5. **能力一致性**：声明后台但无入口、声明 configurable 但无 schema、声明事件但无对应配置定义时拒绝。
6. **静态依赖检查**：相对资源存在；不得引用 LandingOps 私有地址/凭据；外链按管理员策略报告或阻止。
7. **安全内容检查**：发现 `.env`、私钥、可疑凭据、供应商核心文件误入包时阻止并人工复核。
8. **离线实例化测试**：在临时目录生成实例，断开 LandingOps 后页面仍能从本地配置加载。
9. **配置并发测试**：两个写者使用同一 revision 时仅一个成功，另一个得到 409；进程中断仍保留有效旧文件。
10. **后台开关测试**：默认关闭且 `/admin` 真正不可访问；仅显式启用后可登录并只能管理本站。

通过标准：所有阻断级检查为通过，警告得到有权限管理员确认，产物哈希与验证报告持久化，且不存在截图/预览或运行时控制面依赖。

