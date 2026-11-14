# LandingOps Phase 1L：可移植部署基础

## 1. 阶段结论

Phase 1L 已建立独立于管理后台运行状态的部署工具基础，覆盖生产构建、环境诊断、部署计划和本地隔离模拟。所有真实服务器写入在代码层默认禁用；本阶段没有连接宝塔写入 API、没有修改 DNS、网站、Nginx、PHP-FPM 或生产数据库。

Phase 1L.1 进一步修复了真实部署前的安全边界：宝塔地址按规范化 HTTPS Origin 严格解析；模拟执行必须使用带安装身份和路径哈希的独立授权沙箱；public release 采用 Vue 产物与 API 入口白名单，并拒绝 Source Map、源码、备份、私有配置和疑似密钥内容。

平台自身部署与客户网站部署是两个不同的安全域：

- `landingops_platform` 仅管理 LandingOps 自身安装目录、数据库和持久化资产。
- 客户站点仍由既有 Preflight/未来 Worker 管理，Phase 1L 工具不接受客户实例或站点根目录。
- 两者不得复用资源所有权、部署凭据或补偿删除记录。

## 2. 已实现能力

- 严格的 `landingops-deployment/1.0` 非敏感配置契约与 JSON Schema。
- `doctor`：依据脱敏、可信的只读证据检查目标身份、路径、PHP、Nginx、数据库、TLS、构建结果、备份恢复能力和凭据引用。
- `plan`：生成 `init`、`update`、`rollback` 的零执行计划及人工审批项。
- `build`：组合 Composer 生产依赖与 Vue 构建产物，生成不可变 release、逐文件 SHA-256 manifest、ZIP 及 ZIP 校验值。
- `init`、`update`、`rollback`：仅允许在显式本地模拟根目录内执行；未提供模拟根目录时返回 `real_execution_disabled`。
- `authorize-sandbox`：只为预先建立且为空、与源码/生产路径无重叠的本地目录创建独立模拟授权标记。
- Windows 安全入口：凭据通过隐藏输入写入当前 PowerShell 进程环境，子进程结束后清除。
- 最小 Web Root：只包含 Vue 静态资源、`index.php` 和 `health.php`；PHP 源码、迁移、vendor、模板 ZIP、密钥和部署工具均位于 Web Root 外。
- Nginx 模板只将 `/api/` 内部转发给 `index.php`，将 `/healthz` 内部转发给 `health.php`，其他 PHP 路径直接拒绝。

## 3. Release 与持久化结构

```text
/srv/landingops/
  releases/<version>/       # 不可变代码 release
    public/                 # 唯一 Web Root
    src/
    vendor/
    database/migrations/
  current -> releases/...   # 未来 Linux 同文件系统原子符号链接
  staging/                  # 私有构建/传输暂存
  shared/
    template-zips/          # 私有模板库存
  backups/                  # 数据库/持久化资产备份
  .landingops-installation.json
```

本地模拟使用 `current-release.json` 表示活动指针，不冒充 Linux 符号链接行为。真实执行必须在 Phase 1M 重新验证同文件系统、原子重命名、用户/组、ACL 和 Nginx 行为，任何一项未知都阻断。

模拟沙箱标记包含 `installation_id`、随机 `sandbox_id` 和授权路径 SHA-256。每次执行及递归清理前都会重新验证标记、真实路径边界和符号链接；沙箱根、项目源码目录、生产安装目录、路径别名和未授权目录均被拒绝。

## 4. 构建与数据库边界

- 构建安装锁定的 Composer/Node 依赖，运行 PHP、Vue、TypeScript 和 Vite 检查后才打包。
- release 包排除 `.git`、tests、deploy 工具、开发依赖、临时文件和所有私有凭据。
- public 只允许 `index.html`、带内容哈希的 Vue `assets/` 文件以及明确批准的 `index.php`/`health.php`；Source Map、`.env`、私钥、备份、源码、压缩包和私有配置会使构建失败。
- 全新安装仅允许空数据库或合法的 LandingOps 初始化状态，迁移范围固定为 001–006。
- 迁移前要求数据库备份和恢复能力证据；迁移失败不会切换代码指针。
- 代码回滚不会自动回滚数据库。更新后健康检查失败只恢复上一代码 release；数据库兼容性必须由发布计划预先证明。
- 迁移已有平台必须同时迁移数据库、模板私有 ZIP、密钥环和其他持久化资产，并在恢复演练通过前禁止切流。

## 5. 安全约束

- SSH 默认账号必须是非 root 专用部署账号，并固定主机指纹。
- 配置文件只保存环境变量名；私钥、数据库密码和应用密钥不得出现在 JSON、命令行、Git 或日志中。
- 宝塔面板地址只接受规范化 HTTPS Origin，分别拒绝 user、pass、query、fragment、非根路径、异常主机/端口和混淆分隔符。
- 目标目录必须位于登记的安装根，祖先不得含符号链接，关键目录不得重合。
- 已有未知内容、外部站点路径重叠、非预期数据库、缺少扩展或无法原子切换时 fail closed。
- 高权限目录创建、Nginx 站点启用、证书准备和数据库授权只能作为人工审批步骤输出。
- 绝不执行全局服务重启，不删除非本任务创建的文件。

## 6. 验证结果

- PHP 自动化：125 项通过，0 失败，其中 Phase 1L 部署工具 13 项。
- Phase 1L.1 完整回归以本轮最终结果为准，详见 [验收报告](PHASE_1L_ACCEPTANCE.md)。
- Vue：30 项通过；TypeScript 检查和 Vite 生产构建通过。
- Composer：严格校验通过，生产依赖安装通过。
- release 构建、manifest 校验、doctor、plan、模拟 init/update/rollback 冒烟通过。
- MariaDB 10.11.10：用户提供的 `landingops_test` 真实集成结果为 23 张表、001–006、约束及清理全部通过。本阶段未修改数据库迁移。

上述结果仅证明源码和隔离模拟环境能力，不构成真实服务器写入授权。

`doctor` 读取的手工 JSON 必须标记为 `simulation_only`，其中任何 `true` 都只能生成模拟/计划结论。Phase 1M 必须使用可信的只读远端采集器重新验证 SSH 身份、路径、权限、运行环境、数据库与证据时效；Phase 1L.1 不接受手工 JSON 作为真实写入许可。

## 7. Phase 1M 前仍需确认

- 专用部署账号、SSH 强制命令/目录权限及主机指纹策略。
- 目标服务器的实际 PHP CLI/FPM 扩展、Nginx include 位置和每站隔离。
- `/srv/landingops` 的设备号、符号链接与原子切换实测。
- 独立生产数据库身份、最小权限、备份和恢复演练。
- 管理域名证书及切流方案。
- 真实上传/传输机制、远端校验、锁、并发更新和故障恢复实现。

在以上事项通过源码审查与安全预检查前，真实 `init`、`update` 和 `rollback` 保持禁用。
