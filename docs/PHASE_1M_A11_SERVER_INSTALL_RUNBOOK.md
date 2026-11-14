# LandingOps `landingops-audit` 安装 Runbook（待单独授权）

> 本 Runbook 是未来服务器操作清单，不是本轮执行记录。当前 `real_execution_enabled=false`、`real_execution_eligible=false`。每个写入步骤均须操作者另行明确授权。

## 0. 固定身份与停止原则

- 唯一目标安装根：`/srv/landingops`；v2 不支持其他路径。
- 唯一账号/组：`landingops-audit`，UID/GID 非 0，无附加组。
- 唯一远端命令：`landingops-readonly collect v2`。
- 保留一个已经验证可用的 root/FinalShell 会话，整个准备过程不修改或删除其公钥。
- 不修改网站目录、宝塔站点、Nginx vhost、PHP-FPM、数据库、防火墙或 DNS。
- 任一步出现路径已存在且内容/owner 不符合本文、Host Key 变化、来源 IP 不确定、`sshd -t` 失败或边界审计非零，立即停止，不继续 collect。

## 1. 写入前只读预检查

未来由操作者在现有 root 会话中逐项确认：

```sh
id
printf '%s\n' "$SSH_CONNECTION"
getent passwd landingops-audit || true
getent group landingops-audit || true
stat -Lc '%F|%U|%G|%a|%n' /srv /usr/local/libexec /var/lib /etc/landingops 2>/dev/null || true
test ! -e /srv/landingops
/usr/sbin/sshd -t
/usr/sbin/sshd -V 2>&1 | head -n 1
```

预期：当前 root 身份明确；`SSH_CONNECTION` 第一列为实际 Windows/公司出口 IP；审计账号和安装文件尚不存在；`/srv/landingops` 不存在；`sshd -t` 成功。若出口地址经过 VPN、NAT 或跳板，必须以服务器实际观察到的地址为准，不能填写 `127.0.0.1` 或猜测值。

同时核对待安装仓库 Commit、四个 shell 文件 SHA-256、服务器 SSH Host Key 指纹。任一不一致即停止。

## 2. Windows 生成专用 Ed25519 身份

在仓库外预先建立私有目录，然后运行：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass `
  -File .\scripts\New-LandingOpsAuditKey.ps1 `
  -OutputDirectory C:\private\landingops-audit `
  -AddToAgent
```

工具要求交互输入 passphrase，只输出公钥指纹。停止条件：输出目录位于仓库内、存在 reparse point、目标文件已存在、ACL 收紧失败或指纹无法生成。私钥不得上传服务器；服务器只接收 `.pub` 内容。

## 3. 安装 root-owned 固定文件

将已核对的四个文件暂存到 root 私有目录后，先逐文件比较 SHA-256，再执行未来经批准的安装：

```sh
install -o root -g root -m 0755 landingops-readonly-wrapper /usr/local/libexec/landingops-readonly-wrapper
install -o root -g root -m 0755 landingops-readonly-probe-v2 /usr/local/libexec/landingops-readonly-probe-v2
install -o root -g root -m 0700 landingops-generate-site-summary /usr/local/libexec/landingops-generate-site-summary
install -o root -g root -m 0700 landingops-audit-boundary-check /usr/local/libexec/landingops-audit-boundary-check
```

再检查：

```sh
stat -Lc '%U|%G|%a|%n' /usr/local/libexec/landingops-*
sh -n /usr/local/libexec/landingops-readonly-wrapper
sh -n /usr/local/libexec/landingops-readonly-probe-v2
```

停止条件：任何目标是符号链接、已存在未知文件、owner/group/mode 不符或语法检查失败。禁止覆盖未知内容。

## 4. 创建受限账号与唯一公钥

wrapper 必须先安装，随后才允许把它设为 login shell：

```sh
groupadd --system landingops-audit
useradd --system --gid landingops-audit --no-create-home \
  --home-dir /var/lib/landingops-audit \
  --shell /usr/local/libexec/landingops-readonly-wrapper landingops-audit
usermod --lock landingops-audit
install -d -o root -g root -m 0755 /var/lib/landingops-audit /var/lib/landingops-audit/.ssh
```

`authorized_keys` 只能含一条：

```text
restrict,command="/usr/local/libexec/landingops-readonly-wrapper" ssh-ed25519 <PUBLIC_KEY> landingops-audit@landingops
```

文件安装后必须为 `root:root 0644`。检查：

```sh
id landingops-audit
passwd -S landingops-audit
getent passwd landingops-audit
awk 'NF && $1 !~ /^#/' /var/lib/landingops-audit/.ssh/authorized_keys | wc -l
stat -Lc '%U|%G|%a|%n' /var/lib/landingops-audit /var/lib/landingops-audit/.ssh /var/lib/landingops-audit/.ssh/authorized_keys
```

预期：仅同名主组；密码状态 `L`；shell 为 wrapper；非注释授权行恰好一条。否则停止并先移除该公钥。

## 5. 绑定真实连接来源并审计有效 SSH 策略

创建 root-only `/etc/landingops/ssh-audit-context.conf`：

```text
schema_version=landingops-ssh-audit-context/1
source_address=<服务器从 SSH_CONNECTION 观察到的真实来源 IP>
source_host=<经正向解析确认包含来源 IP 的来源主机名；未解析且 sshd 使用数值地址时填来源 IP>
local_address=<SSH_CONNECTION 中服务器侧本地地址>
local_port=<SSH_CONNECTION 中服务器侧端口>
```

要求 `/etc/landingops` 为 `root:root 0700`，文件为 `root:root 0600`，不是符号链接。边界检查还会要求这些值与当前审批会话的 `SSH_CONNECTION` 一致，并验证 `source_host` 正向解析包含 `source_address`；若 root 会话来自不同跳板或出口，必须停止并从预期来源重新建立审批会话。随后运行：

```sh
/usr/local/libexec/landingops-audit-boundary-check
```

只有退出码 0 且 `boundary_safe=true` 才能继续。审计对真实来源上下文应用 `sshd -T -C`，要求：

- `PasswordAuthentication no`；
- `KbdInteractiveAuthentication no`；
- `PubkeyAuthentication yes`；
- `AuthenticationMethods publickey`；
- `AuthorizedKeysFile .ssh/authorized_keys` 且没有第二路径；
- `AuthorizedKeysCommand none`；
- `TrustedUserCAKeys none`；
- `ForceCommand none`（forced-command 由唯一公钥和 login shell 双重实施）；
- `PermitUserEnvironment no`；
- Hostbased 与 GSSAPI 认证关闭；
- authorized_keys 恰好一个受限 Ed25519 入口。

如果现有全局/Match 配置产生 `any`、密码/键盘交互开启、第二 AuthorizedKeysFile、外部 key command 或 CA 等结果，必须停止。不得在本 Runbook 下修改 `sshd_config`；应另开变更审批，评审最小 `Match User ... Address ...` 策略并先通过 `sshd -t` 和独立回退演练。

## 6. 生成短时脱敏站点摘要

边界审计通过后，由 root 在 collect 前立即执行：

```sh
/usr/local/libexec/landingops-generate-site-summary
stat -Lc '%U|%G|%a|%n' /var/lib/landingops-audit/site-roots.snapshot
```

预期：`root:landingops-audit 0440`，schema 为 `landingops-site-roots/1`，生成时间不超过五分钟。生成器只提取 root 路径和完整性计数，不复制 vhost 内容。任何不可读文件、符号链接或异常计数将使后续证据 fail closed。

## 7. 强制认证行为测试

从已登记来源、使用专用私钥测试：

1. 精确命令返回 v2 握手；
2. 无命令的交互 shell 失败；
3. 命令后缀、换行和其他版本失败；
4. SFTP、SCP、端口转发失败；
5. `ssh -A` 不把 Agent socket 传入 wrapper；
6. 恶意 stdin 不产生服务器文件；
7. 密码/键盘交互认证失败；
8. 账号无法写 wrapper、probe、authorized_keys。

任一失败都先移除审计公钥并停止。不要通过放宽权限、开启密码或修改现有网站解决失败。

## 8. 首次 collect / verify / plan

回到 Windows 项目目录，确保配置 `paths.install_root` 精确为 `/srv/landingops`，然后依次运行：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\Invoke-RemoteEnvironmentDiagnostic.ps1 `
  -Command collect -Config C:\private\landingops-production.json `
  -Output C:\private\landingops-remote-evidence.json

powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\Invoke-RemoteEnvironmentDiagnostic.ps1 `
  -Command verify -Config C:\private\landingops-production.json `
  -Evidence C:\private\landingops-remote-evidence.json

powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\Invoke-RemoteEnvironmentDiagnostic.ps1 `
  -Command plan -Config C:\private\landingops-production.json `
  -Evidence C:\private\landingops-remote-evidence.json `
  -Output C:\private\landingops-install-plan.json
```

证据必须包含 `checked_install_root=/srv/landingops`、`probe_scope_version=landingops-install-scope/1`，并继续返回 `real_execution_enabled=false`。证据过期、路径不一致、任何 blocker 或清单不完整均停止。

## 9. 分步回退

- 公钥/账号步骤失败：先删除唯一审计公钥，终止该 UID 会话；确认 home 仅含本任务文件后删除账号、空 home 和同名组。
- context 步骤失败：删除 `/etc/landingops/ssh-audit-context.conf`；仅当目录为空且由本任务创建时删除 `/etc/landingops`。
- 摘要步骤失败：仅删除固定 `site-roots.snapshot`，不触碰宝塔 vhost。
- 文件安装失败：仅删除已核对 SHA-256 且由本任务安装的四个 `/usr/local/libexec/landingops-*` 文件；未知内容一律人工处理。
- SSH 行为测试失败：先撤销审计公钥，不修改 root 登录，不 reload SSH。

整个回退不修改 root/FinalShell authorized_keys，不删除网站文件，不 reload Nginx/PHP-FPM，不操作数据库、DNS 或防火墙。
