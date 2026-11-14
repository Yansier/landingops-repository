# Phase 1M-A 脱敏环境验收报告

## 报告状态

`classification: implementation_verified_real_ssh_pending`

`write_operations_performed: false`

## 已有历史只读证据

以下事实来自此前已完成的独立只读联调，不是本轮新 SSH 会话伪造的结果：

| 项目 | 已知值 |
| --- | --- |
| 宝塔版本 | 8.0.7 |
| 操作系统 | Ubuntu 24.04.3 LTS |
| 外部网站 | 26 个，全部默认视为 LandingOps 外部资源 |
| MariaDB | 10.11.10；仅 `landingops_test` 已完成集成验收 |

## 本轮真实 SSH 状态

`SKIP: dedicated_readonly_ssh_identity_not_provisioned`

原因：当前已知管理员连接是 root。Phase 1M-A 新契约要求专用非 root 只读身份；仓库及本地私有配置中没有经操作者授权的该身份或凭据。为了避免把 root 固化为自动部署权限，本轮没有建立真实 SSH 会话。

未重新确认的项目包括：

- `/srv/landingops` 及 `/srv` 当前真实状态、owner/group/mode/ACL；
- 祖先符号链接、挂载点、文件系统类型和可用空间；
- PHP CLI/FPM 的实际版本矩阵及扩展；
- Nginx vhost 只读能力；
- 26 个站点根路径与 `/srv/landingops` 的实际重叠检查；
- MariaDB 运行二进制版本与生产数据库规划身份。

以上项目在可信 SSH 证据产生前均不得标记为通过。

Phase 1M-A.1 已在本地完成 v2 forced-command、root-owned wrapper/probe 模板、Windows Ed25519 工具及认证边界审计，但按授权未创建账号、未安装文件、未生成摘要、未连接服务器，因此不改变本报告的真实环境结论。

## 模拟验收结论

模拟 Transport 已覆盖：错误指纹、错误目标、root/非 root、目录缺失/重叠、祖先链接、扩展缺失、Nginx 未知、磁盘不足、数据库身份不一致、过期/篡改证据、超时/部分失败、协议不匹配、命令注入、SCP/SFTP/TTY/转发边界、权限隔离及零写入。

模拟成功仅证明采集器和证据契约行为，不代表真实服务器环境已经通过。
