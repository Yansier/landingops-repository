# LandingOps Phase 1M-A.3：四项只读阻断排查与最小兼容修复

## 阶段结论

2026-11-16 已在固定 SSH Host Key、既有 root 管理会话和 `landingops-audit` forced-command 下完成只读取证。诊断没有修改服务器文件、没有 reload/restart 服务，也没有创建 `/srv/landingops`。

本地最小修复已经准备，但本文件记录的服务器状态仍是“旧 probe / 旧摘要生成器已安装”。更新两个 root-owned 文件、重新生成短时摘要及重新 collect/verify/plan 均须操作者另行批准。

固定安全状态：

```text
real_execution_enabled=false
real_execution_eligible=false
diagnostic_write_operations_performed=false
server_component_update_performed=false
```

## 四项真实根因

| 阻断项 | 旧探针与真实退出码 | 真实只读证据 | 根本原因 | 最小修复 | 服务器变更 / 授权 |
| --- | --- | --- | --- | --- | --- |
| `php_fpm_versions` | forced-command exit `1`，空输出 | `/www/server/php/84/sbin/php-fpm` 为 `root:root 0755`；审计账号执行 `-v` exit `0`，返回 PHP-FPM 8.4.27 | probe 以 `set -fu` 启动；`-f` 禁用 pathname expansion，而旧实现依赖 `/www/server/php/*/sbin/php-fpm` glob，因此循环收到字面量并认为没有 FPM | 在两个固定根下使用有深度上限的 `find`，随后再按严格路径模式校验候选；不改变账号权限 | 需要更新 root-owned probe；需单独授权 |
| `nginx_vhost_capability` | forced-command exit `2`，空输出 | `/www/server/panel/vhost/nginx` 存在且为 `root:root 0600`；root `test -d` exit `0`，审计账号 `test -d` / `test -x` exit `1` | 受保护目录不可被审计账号遍历；直接 `-d` 不适合作为能力证据。该权限边界是正确的，不应放宽 | root-only 生成器只输出固定目录的存在性与 `owner|group|mode|type`，审计 probe 校验短时摘要 | 需要更新 probe 和生成器并生成新摘要；需单独授权；不修改 vhost ACL |
| `database_runtime_binary` | forced-command exit `4`，空输出 | 进程名 `mariadbd`，PID 可见；审计账号读取 `/proc/<pid>/exe` exit `1`；root 解析为 `/www/server/mysql/bin/mariadbd`，版本 10.11.10 | `/proc` 的进程权限边界阻止受限账号解析运行二进制；旧 allowlist 也遗漏宝塔的 `mariadbd` 路径 | root-only 生成器解析 PID 与固定二进制 allowlist，只公开路径和最长 512 字节的首行版本；probe 不再直接读取 `/proc` | 需要更新 probe 和生成器并生成新摘要；需单独授权；不读取数据库凭据或数据 |
| `baota_version` | forced-command exit `1`，空输出 | 旧路径 `/www/server/panel/data/version.pl` 不存在；当前 `/www/server/panel/class/common.py` 为 `root:root 0600`，严格匹配唯一 `g.version` 得到 8.0.7 | probe 使用了目标版本上不存在的旧文件；正确来源受 root 权限保护 | 按宝塔当前 `public.version()` 的来源固定读取 `class/common.py`；root-only 生成器限制文件非链接、真实路径、最大 1 MiB、唯一严格版本赋值，仅公开版本与固定来源 | 需要更新 probe 和生成器并生成新摘要；需单独授权 |

宝塔版本来源依据为官方 BaoTa 仓库中 [`public.version()`](https://github.com/aaPanel/BaoTa/blob/main/class/public.py#L2080-L2094) 对 `g.version` 的读取。本次同时在真实服务器确认该固定来源只有一个严格版本赋值；没有用历史 8.0.7 结果冒充实时证据。

## 摘要契约与泄露边界

新摘要仍固定写入 `/var/lib/landingops-audit/site-roots.snapshot`，但 schema 升级为 `landingops-environment-summary/2`，生成器身份为 `phase1ma3/1`。除原有站点 root 和完整性计数外，只增加：

- 固定 vhost 目录的存在标记与 `owner|group|mode|type`；
- 严格 allowlist 中实际 MariaDB 二进制路径和有界首行版本；
- 固定宝塔版本来源路径与严格版本号。

摘要仍要求 `root:landingops-audit 0440`、非符号链接、最大 64 KiB、五分钟内生成。schema、生成器身份、owner/mode、大小、时效或字段任一不符，四项 probe 均 fail closed。生成器不读取数据库配置、密码、SSL 私钥、Nginx 正文、网站业务文件或控制面密钥。

## 服务器文件差异与哈希

当前服务器已安装哈希：

```text
0C7623F8E6861195F3A37F679A38FEFEB682AB1760E9EB02A5284DCBF02A1C77  landingops-readonly-probe-v2
C20DD0FE6F30AF8F4161255D214537744C19B1C2CE94F596F0AF9E8BDD4840C2  landingops-generate-site-summary
F6C800D6AB411EE9AD1264243F493A1944AB89836E82C46DA31BE823A81EE841  landingops-readonly-wrapper (不变)
0F0D11038BCCE708BB37E9C38DE8EE7BFBF2FCD56851EAB39705E936D040B8E5  landingops-audit-boundary-check (不变)
```

本地候选哈希在提交前由自动化重新计算，并同步到最新 Runbook。只允许在服务器旧哈希精确匹配时暂存和替换前两个文件；未知内容拒绝覆盖。

本地候选哈希：

```text
009B4070968D144305AB820B5989050902B76A29E997431DF4D9E4C41467DAC9  landingops-readonly-probe-v2
522D86B164261E52482EA51FCFF84E13EFCB79F3225BEACF0AB56B5DCAC8A477  landingops-generate-site-summary
F6C800D6AB411EE9AD1264243F493A1944AB89836E82C46DA31BE823A81EE841  landingops-readonly-wrapper (不变)
0F0D11038BCCE708BB37E9C38DE8EE7BFBF2FCD56851EAB39705E936D040B8E5  landingops-audit-boundary-check (不变)
```

## 经再次授权后的更新与回退计划

1. 重新核对 Host Key、旧文件哈希、owner/group/mode 与目标非符号链接。
2. 将两个候选文件放入 root 私有临时目录，核对候选 SHA-256，执行 `sh -n`。
3. 为两个旧文件创建 root-only、带本次任务身份的备份；原子安装新 probe `0755` 与生成器 `0700`。不 reload SSH/Nginx/PHP-FPM/MariaDB。
4. root 运行一次生成器；核对 v2 schema、owner/mode/大小，不输出完整摘要内容。
5. 再次运行 boundary checker，必须 `boundary_safe=true`；随后执行真实 collect/verify/plan。
6. 任一步失败即停止。回退只恢复哈希匹配的两个旧文件，并恢复与旧 probe 匹配的旧摘要或删除新摘要；不触碰 SSH 片段、账号、公钥、vhost 或网站文件。

## Phase 1M-B 前置条件

- 两个服务器组件更新获得独立批准且更新后哈希、owner/mode、shell 语法均通过；
- `boundary_safe=true`，root/FinalShell 登录未受影响；
- 新短时摘要与真实 collect/verify/plan 在有效期内完成，四项 probe 均 exit `0`；
- 站点清单仍完整、`/srv/landingops` 仍 absent、无路径重叠；
- 安装仍保留原子切换、生产数据库身份、备份恢复、Nginx/TLS 等待写入证据；
- 获得 Phase 1M-B 独立写入授权。

本阶段不会因为四项只读证据可取得而自动开放真实执行。
