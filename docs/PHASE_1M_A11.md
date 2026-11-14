# LandingOps Phase 1M-A.1.1：服务器安装前最终兼容性修复

## 阶段边界

本轮仅修改本地源码、模拟测试、隔离测试工具和文档。未连接生产服务器，未创建账号/密钥，未安装 wrapper/probe，未读取或修改网站、Nginx、SSH、数据库、DNS 或防火墙。

固定结果继续为：

```text
real_execution_enabled=false
real_execution_eligible=false
write_operations_performed=false
```

## 固定 probe scope

v2 只支持 `/srv/landingops`。OpenSSH Transport 在 `ssh-keyscan` 前检查配置；Collector 在调用 Transport 前再次检查。服务端固定 probe 返回：

```text
probe_scope_version=landingops-install-scope/1
probe_install_root=/srv/landingops
```

两项与协议、策略 ID、零写入声明一起接受双层握手验证，并写入签名证据的 `facts.filesystem` 与 `command_policy`。证据验证器再次比较配置路径、声明路径和实际检查路径。客户端没有 probe 路径参数；任何其他安装根、缺失 scope 或重标记路径均 fail closed。

## SSH 认证边界

未来服务器保存 root-owned `0600` 的 `/etc/landingops/ssh-audit-context.conf`，只含 schema、服务器实际观察到的客户端来源 IP、解析后的来源主机名、本地监听地址和端口。审计要求它们与当前 root 审批会话的 `SSH_CONNECTION` 一致，并要求来源主机名正向解析包含该来源 IP；拒绝 loopback/unspecified 来源，然后使用：

```text
sshd -T -C user=landingops-audit,host=<source_host>,addr=<source_address>,laddr=<local_address>,lport=<local_port>
```

因此相关 `Match User`、`Match Address` 和 `Match Host` 规则会按预期连接上下文解析。只有下列条件同时成立才返回 `boundary_safe=true`：

`-C` 字段语义以 [OpenSSH sshd(8) 官方手册](https://man.openbsd.org/sshd.8) 为依据：`addr` 是来源地址、`host` 是解析后的来源主机名、`laddr/lport` 是服务器本地连接端点。

- 密码和键盘交互关闭，公钥开启，`AuthenticationMethods=publickey`；
- 唯一 `AuthorizedKeysFile=.ssh/authorized_keys`；
- `AuthorizedKeysCommand=none`、`TrustedUserCAKeys=none`；
- `ForceCommand=none`，forced-command 由唯一公钥和 login shell 双边界实施；
- `PermitUserEnvironment=no`，Hostbased/GSSAPI 关闭；
- authorized_keys 仅有一条精确受限 Ed25519 公钥；
- 账号非 root、无附加组、密码锁定；关键文件均为预期 root owner/mode。

审计同时输出转发、Agent、X11、TTY 和 user-rc 的有效摘要，但不输出完整 sshd 配置。任何字段未知或存在其他授权入口即 blocked。脚本不修改 `sshd_config`；若服务器当前全局策略不满足要求，必须停止并另行申请最小 Match 策略变更审批。

## 隔离真实 OpenSSH 测试准备

新增 `tests/openssh/` Docker 测试环境，计划使用临时 Ubuntu 24.04、临时 Ed25519 密钥、随机 localhost 端口和 internal Docker network，验证：

- login shell wrapper 与精确 `SSH_ORIGINAL_COMMAND`；
- 拒绝交互 shell、注入、SCP、SFTP、端口转发；
- Agent socket 不进入 wrapper；
- stdin 不执行；密码失败且公钥成功；
- 受限账号不能修改 wrapper、probe、authorized_keys。

当前 Windows 环境事实：Docker 未安装、WSL 无发行版、Windows sshd 服务不存在。因此真实 OpenSSH 行为测试明确为：

```text
SKIP: isolated_linux_openssh_unavailable (docker.exe not installed)
exit_code=77
```

该 SKIP 不影响静态/模拟测试结果，但真实 OpenSSH 行为仍是服务器安装授权前的待验收项，不能标记为通过。

## 自动化结果

- PHP：156 passed，0 failed；
- Remote SSH 专项：26 passed；
- PHP 语法：123 个文件通过；
- Composer `validate --strict`：通过；
- PowerShell：10 个脚本语法通过；JSON：15 个文件解析通过；
- POSIX shell：5 个文件通过 `sh -n`；
- Vue/Vitest：30 passed；TypeScript 和 Vite production build：通过；
- MariaDB：无迁移或数据库代码变更，本轮未连接；
- 生产 SSH/服务器写入：未执行。

服务器逐步操作、停止条件及精确回退见 [服务器安装 Runbook](PHASE_1M_A11_SERVER_INSTALL_RUNBOOK.md)。
