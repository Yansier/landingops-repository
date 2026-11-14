# LandingOps Phase 1M-A：可信 SSH 只读环境诊断

## 1. 阶段边界

Phase 1M-A 只建立和验证 LandingOps 平台自身的 Linux 只读环境采集链。它不安装 LandingOps、不上传 release、不创建目录/账号/数据库，不修改 Nginx、PHP-FPM、TLS、宝塔站点、DNS 或文件权限。

即使所有只读检查通过，证据和计划仍固定返回：

```json
{
  "real_execution_eligible": false,
  "phase_1m_b_authorization_required": true,
  "real_execution_enabled": false,
  "write_operations_performed": false
}
```

## 2. 固定只读 SSH 采集器

入口：`bin/landingops-remote-doctor`；Windows 安全入口：`scripts/Invoke-RemoteEnvironmentDiagnostic.ps1`。

采集器只提供 `collect`、`verify` 和 `plan`，没有任意命令参数。OpenSSH Transport 内部固定以下探针类别：

- `id`、`uname`、`/etc/os-release`；
- PHP CLI 扩展、宝塔/系统 PHP 与 PHP-FPM 版本矩阵；
- Nginx 版本和 vhost 目录是否存在；
- `df`、`findmnt`、`stat`、目标路径状态、祖先符号链接和只读 ACL 摘要；
- 从宝塔 Nginx `.conf` 中仅提取绝对 `root` 路径，记录配置文件数、root 声明数和不可读文件数；
- MariaDB/MySQL 客户端与服务端二进制版本；
- 宝塔版本文件。

探针不运行 `sudo`，不执行 `nginx -t/reload`，不连接数据库，不读取网站文件内容，也不支持上传或远端写入。

## 3. 主机身份保护

1. `ssh-keyscan` 仅获取目标主机公开密钥。
2. 每条密钥用 `ssh-keygen -E sha256` 计算指纹。
3. 只有与部署配置中已核对指纹完全一致的单条密钥进入临时 `known_hosts`。
4. 正式 SSH 会话使用 `StrictHostKeyChecking=yes`、禁用 DNS HostKey、更新 HostKey、ProxyCommand 和用户 SSH config。
5. 未知或变化的 Host Key 直接停止，不自动接受或替换。

SSH 私钥不作为命令行参数；优先使用系统 SSH Agent。密码认证由系统 OpenSSH 在本地控制台交互处理，不进入 PHP、仓库、JSON 或日志。

## 4. 可信证据契约

Schema：`deploy/schema/remote-environment-evidence.schema.json`。

证据包含：

- `source=trusted_ssh_readonly_collector`；
- `trust_level=verified_read_only_session`；
- 目标主机、端口、配置账号、观察账号、UID/GID、已验证指纹和随机 session ID；
- UTC 观察时间与 30 分钟失效时间；
- 采集器版本及固定命令策略 ID；
- 已确认、pending、unsupported、blockers 和脱敏错误；
- HMAC-SHA256 签名。

临时 HMAC 密钥通过隐藏输入进入当前进程环境，至少 32 字节，结束后清除。修改 JSON、换目标、换数据库规划或超过时效都会验证失败。Phase 1L 的 `simulation_only` JSON 不符合该契约，不能提升信任等级。

签名只防止采集后被本地字段编辑，不替代 Phase 1M-B 的重新采集、操作者授权或服务器变更审批。

## 5. Windows 运行方式

准备仓库外的真实非敏感部署配置，`ssh_user` 必须是专用非 root 账号：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass `
  -File .\scripts\Invoke-RemoteEnvironmentDiagnostic.ps1 `
  -Command collect `
  -Config C:\private\landingops-production.json `
  -Output C:\private\landingops-remote-evidence.json
```

脚本先隐藏输入临时证据签名密钥。若 SSH Agent 未提供身份，系统 OpenSSH 可在控制台进行自身的交互认证。凭据不会成为 CLI 参数。

验证和生成计划必须再次使用同一临时证据密钥：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass `
  -File .\scripts\Invoke-RemoteEnvironmentDiagnostic.ps1 `
  -Command plan `
  -Config C:\private\landingops-production.json `
  -Evidence C:\private\landingops-remote-evidence.json `
  -Output C:\private\landingops-install-plan.json
```

## 6. 专用只读账号最小方案

当前历史管理员连接为 root，不能作为默认自动化身份。创建账号属于服务器修改，本阶段不执行。建议由操作者另行审批：

- 独立账号，例如 `landingops-audit`，UID/GID 非 0，无 sudo、无 Docker、无面板管理组权限；
- 仅允许 SSH Agent/专用公钥或一次性交互密码；禁止端口、Agent、X11 转发；
- 只需执行本文固定的系统元数据命令；
- 只读访问 `/etc/os-release`、PHP/Nginx/MariaDB 版本二进制、`/srv` 元数据以及宝塔 Nginx vhost 配置和版本文件；
- 不授予网站内容、数据库数据、密钥、日志或写目录权限；
- 若宝塔配置当前不可读，优先用精确 ACL/只读组授权所需配置目录，不授予通配 sudo；变更前单独审查实际路径。

创建账号、修改 ACL/sshd/authorized_keys 均留待操作者明确授权，不能由诊断工具执行。

## 7. 只读无法证明的能力

下列项目始终保持 pending：

- 真实原子符号链接切换和同任务并发锁；
- 数据库备份与恢复演练；
- 生产数据库身份、最小授权及迁移；
- 目录创建、owner/group/ACL 设置；
- Nginx vhost 写入、配置测试、reload；
- TLS 安装；
- release 上传、健康检查和切流。

这些项目只能在 Phase 1M-B 单独授权后执行或验证。

## 8. 自动化验收

- PHP：144 项通过，0 失败；其中 Phase 1M-A 新增 14 项固定 SSH Transport 与证据契约测试。
- PHP 语法：121 个文件通过。
- Composer `validate --strict`：通过。
- Vue/Vitest：30 项通过；TypeScript 和 Vite production build 通过。
- Release ZIP 与逐文件 SHA-256 manifest：通过本地构建验证。
- JSON Schema 与 Windows PowerShell 语法：通过。
- MariaDB：本阶段无迁移或数据库代码变更，未连接真实数据库；保留此前 `landingops_test` MariaDB 10.11.10 集成通过结论。
- 真实 SSH：因未提供经授权的专用非 root 只读身份而明确 SKIP，详见环境报告。
