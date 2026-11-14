# LandingOps Phase 1M-A.1：forced-command 安全兼容改造

## 1. 本轮结果与边界

本轮只完成本地源码、模拟测试、文档和发布准备。未连接真实 SSH，未创建 `landingops-audit`，未安装 wrapper/probe，未生成生产服务器摘要，也未修改 `sshd_config`、现有 root/FinalShell 登录、网站目录、Nginx、数据库或 ACL。

固定状态仍为：

```text
real_execution_enabled=false
real_execution_eligible=false
write_operations_performed=false
```

## 2. 版本化固定协议

客户端只向 OpenSSH 传递一个精确远端命令：

```text
landingops-readonly collect v2
```

客户端不再通过 `sh -s`、stdin、SCP、SFTP 或任何动态脚本传输探针。stdin 立即关闭。服务端 wrapper 仅对 `SSH_ORIGINAL_COMMAND` 做字节级精确匹配，不执行、不拼接、不 `eval` 该值；拒绝 TTY，并将 stdin 重定向到 `/dev/null` 后，以清空环境、固定 PATH、45 秒超时和零参数启动 root-owned 固定探针。

探针响应必须先包含 `protocol_version=landingops-ssh-readonly/2`、`command_policy_id=landingops-linux-readonly-v2` 和 `write_operations_performed=false`。Transport 与 Collector 双层校验；缺失、失败、不同版本或被修改均以 `ssh_probe_handshake_mismatch` 终止，不接受降级。

## 3. 未来服务器安装变更清单（本轮未执行）

| 对象 | 未来 owner/group | mode | 用途 |
| --- | --- | --- | --- |
| 组 `landingops-audit` | 系统组 | — | 仅作为专用账号主组 |
| 账号 `landingops-audit` | 非 root，密码锁定，login shell=wrapper | — | 仅持有 forced-command 公钥，无附加组 |
| `/var/lib/landingops-audit` | `root:root` | `0755` | 不允许账号写入的 home/证据边界 |
| `.ssh` | `root:root` | `0755` | root 管理公钥 |
| `authorized_keys` | `root:root` | `0644` | 单一 Ed25519 公钥与固定限制 |
| `/usr/local/libexec/landingops-readonly-wrapper` | `root:root` | `0755` | 精确 forced-command 门禁 |
| `/usr/local/libexec/landingops-readonly-probe-v2` | `root:root` | `0755` | 固定零参数只读探针 |
| `/usr/local/libexec/landingops-generate-site-summary` | `root:root` | `0700` | 经单独人工批准生成脱敏站点根摘要 |
| `/usr/local/libexec/landingops-audit-boundary-check` | `root:root` | `0700` | 安装后只读权限审计 |
| `site-roots.snapshot` | `root:landingops-audit` | `0440` | 最长 5 分钟有效的脱敏摘要 |

`authorized_keys` 的唯一允许前缀为：

```text
restrict,command="/usr/local/libexec/landingops-readonly-wrapper"
```

OpenSSH `restrict` 同时禁用端口转发、Agent 转发、X11、PTY 和 `~/.ssh/rc`。账号 login shell 也固定为同一个 root-owned wrapper；即使未来误开启非公钥认证，没有精确 `SSH_ORIGINAL_COMMAND` 仍会立即拒绝，不会得到通用 shell。未来安装不修改 `sshd_config`，不 reload SSH，不替换或删除现有 root 公钥，不影响 FinalShell。账号不加入 `sudo`、`docker`、`www`、宝塔或任何网站组，不新增网站目录 ACL。

## 4. 受保护 Nginx 信息

`landingops-audit` 不直接获得宝塔 Nginx 配置目录的广泛读取权。未来由 root 明确运行固定的 `landingops-generate-site-summary`：它只扫描固定 `/www/server/panel/vhost/nginx`，只提取 root 路径和完整性计数，原子写入固定摘要文件；不复制 vhost 内容、域名证书、私钥或凭据。摘要 owner/mode、schema 和 5 分钟时效任何一项不符，探针都拒绝使用。

该生成器不是 forced-command 可调用目标，也不由只读账号运行。是否安装或调度它留待下一轮服务器授权。

## 5. Windows Ed25519 工具

`scripts/New-LandingOpsAuditKey.ps1` 只在操作者未来手工运行时生成专用 Ed25519 密钥：

- 目标目录必须预先存在、位于仓库外、不是文件系统根且祖先不能是 reparse point；
- 不覆盖已有密钥；
- 使用 `ssh-keygen -t ed25519 -a 100 -o`，由 `ssh-keygen` 交互隐藏读取 passphrase；
- 不把 passphrase 放入参数、环境、文件或日志；
- 私钥关闭继承，仅授权当前 Windows SID；
- 只输出公钥指纹，不输出私钥；可选显式加入当前 SSH Agent。

本轮没有运行该工具，也没有生成任何生产密钥。

## 6. 认证边界与攻击面

本地审计同时验证：账号非 root、无附加组、密码锁定、login shell 固定为 wrapper，home/.ssh/authorized_keys/wrapper/probe 均 root-owned 且不可被组/其他用户写入，authorized_keys 只含精确限制和 Ed25519 公钥。未来 root 只读边界检查还通过 `sshd -T` 读取该用户的有效公钥、authorized_keys 路径、密码及键盘交互设置摘要，但不修改配置或输出完整配置。

明确拒绝：命令后缀/前缀、空白差异、换行注入、协议 v1/v3、shell、SCP、SFTP、TTY，以及通过 stdin 传脚本。即使客户端发送任意 stdin，wrapper 在探针启动前将其丢弃。

应用层白名单不被描述为 Linux 强隔离本身；真正边界由 root-owned forced-command、不可写公钥文件、锁定密码、无附加组和 OpenSSH `restrict` 共同组成。

## 7. 精确回退方案（仅未来安装后）

1. 先由 root 将 `/var/lib/landingops-audit/.ssh/authorized_keys` 移出该目录或删除其中唯一审计公钥，立即阻止新诊断会话；不要改 root 的 authorized_keys。
2. 仅终止账号 `landingops-audit` 的残留 SSH 会话，确认无该 UID 进程。
3. 删除固定安装文件：wrapper、v2 probe、摘要生成器、边界检查器和 `site-roots.snapshot`。
4. 删除 `/var/lib/landingops-audit/.ssh` 与空 home；再删除 `landingops-audit` 用户及同名组。
5. 复核 root/FinalShell 登录、26 个网站、Nginx、PHP-FPM 和数据库未发生变化。

因为方案不修改 `sshd_config`、网站 ACL、Nginx、PHP、数据库或防火墙，所以回退不需要 SSH reload、服务重启、网站恢复或数据库回滚。若任一步发现文件 owner/path 不符合本表，停止自动清理并人工核对，不递归删除未知内容。

## 8. 下一轮服务器授权前置

下一轮必须单独批准：创建账号/组、安装上述文件、写入公钥、生成一次摘要、运行边界检查和建立首次真实只读会话。任何实际命令仍需先展示变更计划。Phase 1M-B 安装权限未开放。

## 9. 本地验收结果

- PHP 自动化：151 passed，0 failed；
- PHP 语法：123 个文件通过；
- Composer `validate --strict`：通过；
- PowerShell：9 个脚本语法通过；JSON：15 个文件解析通过；
- Vue/Vitest：30 passed；TypeScript：通过；Vite production build：通过；
- malicious command、空白/换行绕过、SCP/SFTP、TTY、转发限制、协议不匹配、stdin 丢弃、root-owned 权限边界均有回归覆盖；
- 4 个 Linux shell 交付文件通过 Git for Windows POSIX `sh -n` 语法检查，并由 PHP 静态安全契约测试覆盖；真实 Ubuntu 执行留待下一轮明确服务器授权；
- MariaDB：无迁移或数据库代码变更，本轮未连接数据库；
- 真实 SSH 与服务器写入：SKIP（按本轮授权边界）。
