# LandingOps Phase 1M-A.2：真实 SSH 策略适配与安装准备

## 阶段边界

本阶段先完成本地代码、SSH 策略候选、自动化测试与 Runbook；随后在操作者单独授权下，于 2026-11-15 安装了专用 `landingops-audit` 只读审计组件并完成真实 SSH 验收。该安装没有部署 LandingOps 平台、创建客户网站或修改 Nginx/PHP-FPM、数据库、DNS、SSL 与网站文件。

固定结果仍为：

```text
real_execution_enabled=false
real_execution_eligible=false
collector_write_operations_performed=false
```

审计组件安装本身包含受权的账号、固定文件、账号专用 SSH 片段、一次 `systemctl reload ssh` 和短时站点摘要写入；因此不能把整个安装过程表述为零写入。`write_operations_performed=false` 仅属于固定只读 Collector 生成的证据。

## 专用 Match User 策略

候选文件为 `deploy/ssh/00-landingops-audit.conf`。它只匹配 `landingops-audit`，不改变 root、FinalShell 或其他账号的全局策略。候选策略要求：

- publickey-only，禁用 password 与 keyboard-interactive；
- `AuthenticationMethods publickey`；
- 唯一 `.ssh/authorized_keys`，禁用外部 key command、CA 和 principals 文件；
- `ForceCommand`、受限 authorized_keys 和 login shell 三重固定 wrapper；
- `DisableForwarding yes`，并显式关闭 Agent/TCP/StreamLocal/X11/TTY/user RC/tunnel；
- Hostbased/GSSAPI 关闭；目标 Ubuntu 24.04/OpenSSH 9.6 不允许在 `Match` 内声明 `PermitUserEnvironment`，因此片段不覆盖该字段，安装前边界审计仍强制其继承后的有效值必须为 `no`，否则阻断且不得 reload。

根据 [OpenSSH sshd_config(5)](https://man.openbsd.org/sshd_config.5)，Include 通配符按词法顺序展开，命中的多个 Match 对同一字段只采用首次取得的值。因此文件名本身不能证明生效。服务器安装时必须先核对实际 Include/Match 顺序，安装片段后在 reload 前同时通过 `sshd -t` 和两组不同来源地址的 `sshd -T -C` 字段对比；任何差异立即撤回片段。

2026-11-14 真实只读检查确认：主配置第 12 行是 `Include /etc/ssh/sshd_config.d/*.conf`；目录中只有 `50-cloud-init.conf`（`root:root 0600`）与 `60-cloudimg-settings.conf`（`root:root 0644`），主配置和这两个片段均没有 Match 指令。实际来源与代表性另一来源的 `sshd -T -C` 安全字段完全相同。因而 `00-landingops-audit.conf` 在当前词法顺序上没有更早的 Match 冲突；这仍不是写入许可，安装后的 `sshd -t` 与双上下文复核不可省略。

2026-11-15 首次真实候选兼容诊断确认：该服务器对 `Match` 内的 `PermitUserEnvironment` 返回“不允许”并以 255 拒绝配置；候选片段已在同一受控会话中删除，原配置再次通过 `sshd -t`，未 reload。兼容修订删除片段内该行，但没有降低边界：`landingops-audit-boundary-check`、双来源 `sshd -T -C` 和安装器仍要求最终有效值精确为 `permituserenvironment no`。

## 动态来源模型

V1 不把变化的公网出口当作永久身份，也不接受 `49.125.*` 一类宽泛来源范围。`ssh-audit-context/2` 只声明：

```text
source_policy=session-observed
```

边界检查器从当前 `SSH_CONNECTION` 派生实际 source/local endpoint，并计算 context hash。固定 probe 对每次 collect 输出同一组会话字段；Collector 校验 IP、端口及 hash 一致性后写入有签名、短时效的证据。Host Key、唯一 Ed25519 公钥、forced-command、root-owned 文件及账号隔离仍是登录安全边界。未来固定 VPN 出口可在独立审批中增加 Address 限制。

### wrapper 环境边界

wrapper 仍以 `env -i` 建立空环境，并继续固定 PATH、LANG、HOME、命令、probe 路径、超时和 stdin `/dev/null`。在清空前只读取 OpenSSH 提供的 `SSH_CONNECTION`，要求它恰好由四个单空格分隔字段组成；两个地址必须能被 `ipaddress` 解析且不是 unspecified，两个端口必须是无前导零的 1–65535 十进制数。缺失、制表符/多空格、额外字段、非法 IP 或端口均以退出码 68 fail closed。

验证成功后只把原始 `SSH_CONNECTION` 加入 probe 环境。`SSH_ORIGINAL_COMMAND`、Agent、DISPLAY、客户端自定义环境及其他变量不会被传递。probe 的 `environment_contract` 进一步验证运行环境只有固定白名单变量及 Ubuntu `/bin/sh` 在 `env -i` 后自行建立的 `PWD`、`SHLVL`、`_`；真实预检查已确认后两者由 shell 产生，其他名称仍全部拒绝。

## Host Key 获取与固定

部署配置新增非敏感 `ssh_host_public_key`，并要求其 SHA-256 与 `ssh_host_fingerprint` 精确一致。Transport 最多执行三次 `ssh-keyscan`：

1. 任一次返回非空密钥但指纹不匹配，立即 fail closed；
2. 空结果、超时或失败只允许有界重试；
3. 全部扫描无结果时，使用管理员预置公钥建立临时 known_hosts；
4. 最终连接始终保持 `StrictHostKeyChecking=yes`，不会接受陌生首见密钥。

## 服务器写入前停止条件

- 实际 Include/Match 顺序未确认，或已有更早 Match 会取得同一字段；
- 账号、组、目标目录或固定文件出现未知同名内容；
- `sshd -t` 非零；
- 不同来源上下文的安全字段不同；
- boundary checker 不是 `boundary_safe=true`；
- root/FinalShell 第二会话验证失败；
- 任一文件 SHA-256 与本提交不一致。

## 验收结果

- 真实安装与 SSH 验收：Host Key 已固定；`sshd -t`、双来源 Match 策略、root 策略不变、第二 root 登录及 `boundary_safe=true` 均通过；只执行一次 `systemctl reload ssh`，未 restart。
- 行为测试：精确 fixed-command、会话 context hash、清洁环境、恶意 stdin、Shell/命令注入、SFTP/SCP、TCP 转发、Agent 转发、密码认证及固定文件不可写测试全部通过。
- 真实 Collector：`verified_read_only_evidence` 已生成并完成 verify；安装根固定为 `/srv/landingops`，当时状态为 absent；站点清单完整且未发现与目标路径重叠。
- 环境摘要：Ubuntu 24.04.3 LTS / x86_64；PHP CLI 8.4.27 且必需扩展无缺失；Nginx 1.30.6；MariaDB 客户端为 10.11.14，服务器二进制为 10.11.10。
- 保守阻断：`php_fpm_versions`、`nginx_vhost_capability`、`database_runtime_binary`、`baota_version` 探针 unavailable，因此证据分类为 `readonly_blocked`、计划为 `blocked`；历史宝塔 8.0.7 事实没有被冒充为本次 SSH 探针结果。
- PHP 自动化：164 passed，0 failed。
- PHP lint：123 个文件通过；Composer strict validation 与优化 autoload 检查通过。
- Vue/Vitest：30 passed；TypeScript 与 Vite production build 通过。
- Host Key 回归覆盖：扫描超时/空结果、有界重试、管理员固定 key 回退、非空错误 key fail closed。
- 动态来源回归覆盖：每会话字段和 context hash 一致性、签名证据篡改拒绝、账号专用策略不含 Address/CIDR。
- 隔离真实 OpenSSH harness 已覆盖 wrapper → probe 真实执行、合法/非法 `SSH_CONNECTION`、context hash、恶意 stdin 和环境清理；本机未安装 Docker 时必须以退出码 77 明确 `SKIP`，不能用 Mock 结果替代。
- MariaDB：本阶段没有数据库或迁移变更，未连接生产数据库。
- 实际提交：`0fd227f` 补全 OpenSSH 私钥选择；`41e2e82` 增加脱敏协议结构诊断；`a79ba71` 修复空探针正文尾部分隔符解析。

本次签名证据为短时证据，观察时间 `2026-11-15T05:44:19Z`、失效时间 `2026-11-15T06:14:19Z`。它证明当时真实验收完成，但失效后不得用于 Phase 1M-B 写入许可；未来必须重新 collect/verify。

完整安装、reload、功能测试与逐步回退见 `PHASE_1M_A11_SERVER_INSTALL_RUNBOOK.md`。所有 Linux 写入继续需要单独授权。
