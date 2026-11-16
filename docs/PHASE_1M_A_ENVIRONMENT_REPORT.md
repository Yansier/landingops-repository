# Phase 1M-A 脱敏环境验收报告

## 报告状态

`classification: verified_read_only_evidence_at_observation_time`

`collector_write_operations_performed: false`

`real_execution_enabled: false`

`real_execution_eligible: false`

## 已有历史只读证据

以下事实来自此前已完成的独立只读联调，不是本轮新 SSH 会话伪造的结果：

| 项目 | 已知值 |
| --- | --- |
| 宝塔版本 | 8.0.7 |
| 操作系统 | Ubuntu 24.04.3 LTS |
| 外部网站 | 26 个，全部默认视为 LandingOps 外部资源 |
| MariaDB | 10.11.10；仅 `landingops_test` 已完成集成验收 |

## 本轮真实 SSH 状态

2026-11-15 经单独授权安装了非 root `landingops-audit` 专用身份、root-owned fixed-command 文件、唯一受限 Ed25519 公钥和账号专用 Match 片段。安装后 `sshd -t`、双来源 `sshd -T -C`、root 策略不变、第二 root 登录及 `boundary_safe=true` 全部通过。只执行一次 `systemctl reload ssh`，未 restart，未修改 root/FinalShell 公钥或全局认证策略。

真实行为测试通过：

- 精确 `landingops-readonly collect v2` 与协议握手；
- 动态来源 context hash 与清洁环境；
- 恶意 stdin 不执行；
- 交互 Shell、命令后缀、SFTP、SCP、TCP 转发与密码认证均被拒绝；
- Agent socket 未进入 probe；
- 受限账号不能写 wrapper、probe 或 authorized_keys。

真实签名证据观察时间为 `2026-11-15T05:44:19Z`，失效时间为 `2026-11-15T06:14:19Z`。当时确认：

| 项目 | 真实只读结果 |
| --- | --- |
| SSH 身份 | `landingops-audit`，UID 999 / GID 988，非 root，配置身份匹配 |
| 系统 | Ubuntu 24.04.3 LTS，x86_64 |
| 安装根 | `/srv/landingops`，状态 absent；无祖先符号链接 |
| 磁盘 | 总计约 362.9 GB，可用约 355.6 GB |
| PHP CLI | 8.4.27；`json`、`openssl`、`pdo`、`pdo_mysql`、`zip` 无缺失 |
| Nginx | 1.30.6 |
| 站点证据 | 86 个 vhost 配置、28 个去重 root；清单完整，目标路径重叠数 0 |
| MariaDB | 客户端 10.11.14；服务器二进制 10.11.10 |

以下探针未能取得完整证据并保持阻断：`php_fpm_versions`、`nginx_vhost_capability`、`database_runtime_binary`、`baota_version`。因此原始证据分类为 `readonly_blocked`，blocker 为 `readonly_probe_incomplete`，安装计划分类为 `blocked`。历史只读 API 已知的宝塔 8.0.7 不会替代本次 SSH 探针证据。

## 2026-11-16 四项阻断只读复核

Phase 1M-A.3 已证明：PHP-FPM 8.4.27 可由审计账号执行，旧失败来自禁用 glob 后仍使用 glob；Nginx vhost 目录为受保护的 `root:root 0600`；运行中的 MariaDB 是 `/www/server/mysql/bin/mariadbd` 10.11.10，但审计账号不能解析 `/proc/<pid>/exe`；宝塔 8.0.7 的当前固定来源是受保护的 `/www/server/panel/class/common.py`，旧 `data/version.pl` 不存在。详细退出码和证据见 [Phase 1M-A.3](PHASE_1M_A3.md)。

上述段落记录更新前的根因调查状态。随后服务器两组件已在独立授权下完成更新；最终结果见文末“Phase 1M-A.3 最终真实验收”，不得用本段历史失败覆盖新的短时证据，也不得反向修改历史记录。

该证据现已过期，只能作为本次验收记录，不能直接授权 Phase 1M-B。任何未来写入前必须重新 collect/verify，并继续保持 `real_execution_enabled=false` 直至获得独立审批。

## 模拟验收结论

模拟 Transport 已覆盖：错误指纹、错误目标、root/非 root、目录缺失/重叠、祖先链接、扩展缺失、Nginx 未知、磁盘不足、数据库身份不一致、过期/篡改证据、超时/部分失败、协议不匹配、命令注入、SCP/SFTP/TTY/转发边界、权限隔离及零写入。

模拟成功仅证明采集器和证据契约行为，不代表真实服务器环境已经通过。

## Phase 1M-A.3 最终真实验收

2026-11-16 经独立授权，仅原子更新 `landingops-readonly-probe-v2` 和 `landingops-generate-site-summary`。更新前后的哈希、权限及 root-only 备份均已验证；未修改 wrapper、boundary checker、SSH 配置、账号、公钥或任何服务配置，未 reload/restart 服务。

- `boundary_safe=true`；新的 root SSH 登录成功。
- 四项修复 probe 及其余全部 probe 均 exit 0。
- `/srv/landingops` 仍 absent；站点清单完整，31 个去重 root 与目标路径重叠为 0。
- collect：`readonly_verified_with_pending_writes`，0 blockers。
- verify：`verified_read_only_evidence`。
- plan：`readonly_ready_for_manual_preparation`，0 blockers。
- `real_execution_enabled=false`、`real_execution_eligible=false`。

本次证据观察时间为 `2026-11-16T13:54:42Z`，失效时间为 `2026-11-16T14:24:42Z`。该结果完成 Phase 1M-A.3 环境验收，但不授权 Phase 1M-B 写入；失效后必须重新采集。
