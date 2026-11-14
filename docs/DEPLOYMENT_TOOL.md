# LandingOps 平台部署工具操作手册

## 1. 适用范围

本手册只适用于部署 LandingOps 管理平台自身。它不创建客户网站，不执行 Cloudflare DNS 写入，也不调用宝塔写入 API。

Phase 1L 提供的执行器只能写入操作者明确指定的本地模拟目录。真实 SSH 传输和服务器切换尚未启用。

## 2. 配置准备

复制 `deploy/examples/deployment.example.json` 到仓库外的本地私有位置，再填写目标服务器的非敏感信息。用 `deploy/schema/deployment-config.schema.json` 校验。

配置允许保存主机、端口、非 root 用户、SSH 指纹、宝塔 HTTPS origin、管理域名、路径、PHP/数据库类型、TLS 和发布策略。以下值只能用环境变量名称引用：

- SSH 私钥或安全代理引用；
- 数据库密码；
- 应用密钥环；
- 当前应用密钥版本。

配置会拒绝 root、URL 形式的 SSH 主机、路径逃逸、重合的关键目录、`*_test` 数据库、安装/数据库模式不一致及疑似明文秘密。

宝塔地址必须是规范化 HTTPS Origin。userinfo、query、fragment、非根路径、非法/非规范化主机、异常端口和反斜线混淆都会被拒绝；该校验不会扩大既有目标白名单。

## 3. Windows 安全入口

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\Invoke-LandingOpsDeploy.ps1 `
  -Command doctor `
  -Config C:\private\landingops-deployment.json `
  -Facts C:\private\landingops-doctor-facts.json `
  -PromptSecrets
```

`-PromptSecrets` 使用隐藏输入，将值仅放入当前脚本进程的临时环境并在结束时清除。秘密不会作为 CLI 参数或写入配置文件。生产环境优先使用受控凭据代理/CI secret store，而不是持久化明文文件。

## 4. 授权本地模拟沙箱

先在项目目录之外创建一个专用空目录，再执行一次明确授权：

```powershell
New-Item -ItemType Directory -Path D:\private\landingops-sandbox
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\Invoke-LandingOpsDeploy.ps1 `
  -Command authorize-sandbox `
  -Config C:\private\landingops-deployment.json `
  -SimulateRoot D:\private\landingops-sandbox
```

授权只写入本地 `.landingops-simulation-root.json`，绑定安装 ID、随机沙箱 ID 和真实路径哈希。目录必须预先存在且为空，不能是文件系统根、项目源码、生产安装目录或其重叠路径。复制标记到其他目录不会获得授权。

每次执行和递归清理前都会重验授权标记、路径真实边界及整个受管树的符号链接。发现符号链接、目录联接、路径别名或越界解析时停止，不清理外部内容。

## 5. Doctor 与 Plan

Doctor 使用经可信只读收集器获得的脱敏事实；示例事实只用于本地演示，不是服务器证据：

```powershell
.\.tools\php\php.exe bin\landingops-deploy doctor `
  --config deploy\examples\deployment.example.json `
  --facts deploy\examples\doctor-facts.example.json
```

手工 JSON 必须声明 `evidence.source=manual_json` 和 `trust_level=simulation_only`。只有所有必要证据满足时，结果才是 `ready_for_simulation`；手工填写的 `true` 永远不能授予真实写入权限。它仍会明确返回：

```json
{
  "real_execution_enabled": false,
  "real_execution_eligible": false,
  "phase_1m_reverification_required": true,
  "write_operations_performed": false
}
```

生成计划：

```powershell
.\.tools\php\php.exe bin\landingops-deploy plan `
  --config deploy\examples\deployment.example.json `
  --facts deploy\examples\doctor-facts.example.json `
  --operation init `
  --version v1.0.0
```

计划中的每一步均为 `execute=false`；需 root/面板权限的事项只作为人工审批项列出。

Phase 1M 必须由可信的只读远端采集器重新收集 SSH、路径、权限、运行环境和数据库证据，不能直接提升 Phase 1L 手工事实的信任级别。

## 6. 生产构建

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\Build-ProductionRelease.ps1 `
  -Config C:\private\landingops-deployment.json `
  -Version v1.0.0 `
  -OutputDirectory D:\private\landingops-releases
```

脚本依次执行 Composer 严格校验和生产安装、全部 PHP 测试、`npm ci`、Vue 测试、TypeScript、Vite build，再创建 release。输出包含：

- `landingops-<version>/`；
- `release-manifest.json`（逐文件 SHA-256、大小、源码 commit）；
- `landingops-<version>.zip`；
- ZIP 的 `.sha256` 文件。

若依赖、测试、前端构建或 manifest 任一步失败，不生成可用发布包。正式构建应在干净且固定 commit 的源码树/CI 工作区运行。

public 文件集合采用白名单：三个明确入口加带哈希的 Vue `assets/`。`.map`、`.env`、私钥、备份、源码、私有配置、压缩包以及非白名单根文件均会使 manifest 验证失败。

## 7. 本地隔离模拟

```powershell
.\.tools\php\php.exe bin\landingops-deploy init `
  --config C:\private\landingops-deployment.json `
  --facts C:\private\landingops-doctor-facts.json `
  --version v1.0.0 `
  --release-dir D:\private\landingops-releases\landingops-v1.0.0 `
  --simulate-root D:\private\landingops-sandbox
```

`update` 参数相同；`rollback` 需要目标 `--version` 和 `--simulate-root`，无需 release-dir。模拟执行器验证：

- 目标路径只能映射到模拟根；
- 未知目录内容拒绝；
- installation marker 必须匹配；
- release 不可变且 manifest 完整；
- migration/switch/health 故障不覆盖上一已知良好代码；
- shared 持久化数据和模拟根中的外部站点不受影响。

省略 `--simulate-root` 会硬性返回 `real_execution_disabled`。

## 8. 新服务器与迁移检查单

全新安装前：确认专用账号、路径所有权、同文件系统原子切换、独立数据库、TLS、Web Root 和备份恢复能力。数据库必须为空或仅包含合法 LandingOps 初始化状态。

已有系统迁移还必须备份并验证：数据库、模板私有 ZIP、密钥环/密钥版本映射及其他 shared 资产。新服务器恢复验证完成前不得切换生产流量，旧服务器不得删除。

## 9. 回滚规则

- 保留至少两个不可变 release。
- 健康检查失败只允许把代码指针恢复到上一正常 release。
- 数据库迁移绝不自动逆向执行。
- 如果新代码已执行不可向后兼容迁移，必须停止回滚并按专门恢复计划处理。
- 无可靠原子切换能力时直接阻断，不逐文件覆盖。

## 10. Phase 1L 禁止项

禁止真实 SSH 写入、宝塔写入 API、DNS/SSL 修改、客户站点操作、生产数据库测试、全局服务重启以及删除未知文件。真实服务器首次安装必须等待 Phase 1M 的单独授权。

Phase 1M-A 新增的真实 SSH 只读采集、签名证据和首次安装计划见 [Phase 1M-A 文档](PHASE_1M_A.md)。该入口与本页的手工 `doctor-facts` 不同，仍不提供任何远端写入能力。
