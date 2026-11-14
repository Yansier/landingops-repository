# LandingOps `landingops-audit` 安装 Runbook（待单独授权）

> 本 Runbook 是未来服务器操作清单，不是本轮执行记录。当前 `real_execution_enabled=false`、`real_execution_eligible=false`。每个写入步骤均须操作者另行明确授权。

## 0. 固定身份与停止原则

- 唯一目标安装根：`/srv/landingops`；v2 不支持其他路径。
- 唯一账号/组：`landingops-audit`，UID/GID 非 0，无附加组。
- 唯一远端命令：`landingops-readonly collect v2`。
- 保留一个已经验证可用的 root/FinalShell 会话，整个准备过程不修改或删除其公钥。
- 不修改网站目录、宝塔站点、Nginx vhost、PHP-FPM、数据库、防火墙或 DNS。
- 任一步出现路径已存在且内容/owner 不符合本文、Host Key 变化、`sshd -t` 失败或边界审计非零，立即停止，不继续 collect。动态来源 IP 是每次会话的审计证据，不是永久登录身份，禁止将变化范围直接扩成宽泛 CIDR 白名单。

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
awk '/^[[:space:]]*(Include|Match)[[:space:]]/ {print FNR ":" $0}' /etc/ssh/sshd_config
find /etc/ssh/sshd_config.d -maxdepth 1 -type f -name '*.conf' -printf '%f\n' | sort
```

预期：当前 root 身份明确；`SSH_CONNECTION` 第一列为本次会话的实际出口 IP；审计账号和安装文件尚不存在；`/srv/landingops` 不存在；`sshd -t` 成功。若出口地址经过 VPN、NAT 或跳板，仍以服务器实际观察值记录本次证据，但 V1 不将它固化为永久认证身份。

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

基线 `a3f6774` 后 Phase 1M-A.2 候选文件哈希如下；最终服务器操作仍必须以届时获批 Commit 重新计算并逐项匹配：

```text
B96F9D787A26CF8CCF578976706AEDE2EAFAFA2BCF3DA825928929AB66E82F40  00-landingops-audit.conf
48A0B4BE2D6A67992F8CA5A7E222969626D340651B34EA85B15CDC0D307FED8C  landingops-readonly-wrapper
77163E7768C7CDC2F02F43CE7D674BA628414FF38060223F41CEE2EBD420C0C7  landingops-readonly-probe-v2
C20DD0FE6F30AF8F4161255D214537744C19B1C2CE94F596F0AF9E8BDD4840C2  landingops-generate-site-summary
0F0D11038BCCE708BB37E9C38DE8EE7BFBF2FCD56851EAB39705E936D040B8E5  landingops-audit-boundary-check
```

```sh
install -d -o root -g root -m 0755 /usr/local/libexec
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

## 5. 安装账号专用 Match 策略并审计动态来源会话

候选文件固定为 `/etc/ssh/sshd_config.d/00-landingops-audit.conf`。名称靠前是为了避免其他更早命中的 Match 为同一账号取得首值，但安装前仍必须核对真实 `Include` 位置、文件词法顺序和既有 Match；不能只根据文件名推断生效。

当前只读基线：主配置第 12 行 Include `sshd_config.d/*.conf`；现有 `50-cloud-init.conf`、`60-cloudimg-settings.conf` 与主配置均无 Match。若未来复核看到不同文件、顺序、owner/mode 或 Match，立即停止并重新评审。

未来写入前先要求目标不存在且目录不是符号链接，再安装：

```sh
test ! -e /etc/ssh/sshd_config.d/00-landingops-audit.conf
test ! -L /etc/ssh/sshd_config.d
namei -om /etc/ssh/sshd_config.d
install -o root -g root -m 0644 00-landingops-audit.conf /etc/ssh/sshd_config.d/00-landingops-audit.conf
```

目标已存在、目录为符号链接、SHA-256 不符或 owner/mode 无法确认时停止，禁止覆盖。

片段仅包含 `Match User landingops-audit`：publickey-only、单一 `AuthorizedKeysFile`、关闭其他 key/CA 入口、关闭全部转发/Agent/X11/TTY/user RC，并把 `ForceCommand` 固定为 wrapper。它不含 `Address`，因此动态出口不会被扩大为 `49.125.*` 或其他宽泛白名单。未来使用固定 VPN 出口增加 Address 限制须另行审批。

未来经单独授权安装候选片段后，先不要 reload，立即运行：

```sh
/usr/sbin/sshd -t
/usr/sbin/sshd -T -C 'user=landingops-audit,host=<本次来源IP>,addr=<本次来源IP>,laddr=<服务器本地地址>,lport=22'
/usr/sbin/sshd -T -C 'user=landingops-audit,host=198.51.100.23,addr=198.51.100.23,laddr=<服务器本地地址>,lport=22'
```

两组输出中的认证和隔离字段必须完全一致。语法或有效值任一不符，立即删除本任务刚安装且 SHA-256 一致的片段，再次执行 `sshd -t`，不得 reload。

随后创建 root-only `/etc/landingops/ssh-audit-context.conf`：

```text
schema_version=landingops-ssh-audit-context/2
source_policy=session-observed
```

要求 `/etc/landingops` 为 `root:root 0700`，文件为 `root:root 0600`，不是符号链接。边界检查从当前 `SSH_CONNECTION` 派生本次来源、本地端点和 SHA-256 context hash；来源只作为本次会话证据。随后运行：

```sh
/usr/local/libexec/landingops-audit-boundary-check
```

只有退出码 0 且 `boundary_safe=true` 才能继续。有效策略必须满足：

- `PasswordAuthentication no`、`KbdInteractiveAuthentication no`；
- `PubkeyAuthentication yes`、`AuthenticationMethods publickey`；
- `AuthorizedKeysFile .ssh/authorized_keys` 且没有第二路径；
- `AuthorizedKeysCommand none`、`TrustedUserCAKeys none`、`AuthorizedPrincipalsFile none`；
- `ForceCommand /usr/local/libexec/landingops-readonly-wrapper`，并继续保留唯一公钥及 login shell 边界；
- `PermitUserEnvironment no`，Hostbased/GSSAPI 关闭；
- `DisableForwarding yes`，Agent/TCP/StreamLocal/X11/TTY/user RC/tunnel 均关闭；
- authorized_keys 恰好一个受限 Ed25519 入口。

实际 Include/Match 顺序导致片段未生效或任何字段未知时必须停止，不得转而修改全局认证策略。

全部验证通过后才允许 `systemctl reload ssh`，禁止 restart。必须保持旧 root/FinalShell 会话不断开，并从第二个新窗口确认 root 仍可登录；失败时用旧会话撤回片段并 reload。片段写入、reload 和撤回均属于单独授权的服务器写入。

## 6. 生成短时脱敏站点摘要

边界审计通过后，由 root 在 collect 前立即执行：

```sh
/usr/local/libexec/landingops-generate-site-summary
stat -Lc '%U|%G|%a|%n' /var/lib/landingops-audit/site-roots.snapshot
```

预期：`root:landingops-audit 0440`，schema 为 `landingops-site-roots/1`，生成时间不超过五分钟。生成器只提取 root 路径和完整性计数，不复制 vhost 内容。任何不可读文件、符号链接或异常计数将使后续证据 fail closed。

## 7. 强制认证行为测试

从当前实际网络来源、使用专用私钥测试；每次连接都必须在证据中记录服务器观察到的 source/local endpoint 与 context hash：

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

回到 Windows 项目目录，确保配置 `paths.install_root` 精确为 `/srv/landingops`，并同时保存管理员已核对的 `ssh_host_fingerprint` 与对应 `ssh_host_public_key`（均为公开材料），然后依次运行：

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

证据必须包含 `checked_install_root=/srv/landingops`、`probe_scope_version=landingops-install-scope/1`、`facts.ssh_session.source_policy=session_observed` 及匹配本次连接的 context hash，并继续返回 `real_execution_enabled=false`。证据过期、路径/会话不一致、任何 blocker 或清单不完整均停止。

## 9. 分步回退

- 公钥/账号步骤失败：先删除唯一审计公钥，终止该 UID 会话；确认 home 仅含本任务文件后删除账号、空 home 和同名组。
- context 步骤失败：删除 `/etc/landingops/ssh-audit-context.conf`；仅当目录为空且由本任务创建时删除 `/etc/landingops`。
- Match 片段步骤失败：保持旧 root 会话；只删除 SHA-256 与本任务候选一致的 `/etc/ssh/sshd_config.d/00-landingops-audit.conf`，执行 `sshd -t`，仅在此前已经 reload 时再执行一次 `systemctl reload ssh`；不得 restart。
- 摘要步骤失败：仅删除固定 `site-roots.snapshot`，不触碰宝塔 vhost。
- 文件安装失败：仅删除已核对 SHA-256 且由本任务安装的四个 `/usr/local/libexec/landingops-*` 文件；未知内容一律人工处理。
- SSH 行为测试失败：先撤销审计公钥，不修改 root 登录，不 reload SSH。

整个回退不修改 root/FinalShell authorized_keys，不删除网站文件，不 reload Nginx/PHP-FPM，不操作数据库、DNS 或防火墙。
