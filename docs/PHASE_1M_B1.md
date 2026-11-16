# Phase 1M-B1 首次上线环境准备

## 结论

2026-11-16 在已固定 SSH Host Key 的宝塔服务器完成 LandingOps 平台自身的首次上线环境准备。最终脱敏验收分类为 `environment_prepared`，但平台程序仍未上传或启动：

- `real_execution_enabled=false`；
- 未执行 001–006 生产迁移；
- 未初始化超级管理员；
- 未创建 `current` 指针；
- 未修改或 reload Nginx、SSH、PHP-FPM、MariaDB；
- 未修改 DNS、SSL、宝塔网站或客户网站文件。

本阶段只准备平台专属身份、目录、空数据库、私有配置和初始备份。Phase 1M-B2 仍需独立授权。

## 写入前证据

写入前重新生成了短时 root 脱敏摘要并执行真实 `collect → verify → plan`：

- `collect=readonly_verified_with_pending_writes`；
- `verify=verified_read_only_evidence`；
- `plan=readonly_ready_for_manual_preparation`；
- blockers 为 0；
- 站点配置 95 个、去重站点根 31 个、清单完整；
- `/srv/landingops` 不存在；
- 与既有站点路径重叠为 0；
- `boundary_safe=true`。

MariaDB 管理员凭据通过固定 Host Key SSH 在服务器本机的 Unix Socket 和 `127.0.0.1:3306` 分别执行只读 `SELECT 1`，两者均认证成功。正式准备事务优先使用 TCP `127.0.0.1:3306`，不使用 Windows `13306` 隧道。密码仅保存在仓库外的 Windows DPAPI 文件和服务器 root-only 私有文件中。

## 实际服务器写入

仅新增以下资源：

1. system group `landingops-deploy`；
2. system user `landingops-deploy`，UID 997、GID 987；
3. root-owned home `/var/lib/landingops-deploy`；
4. `/srv/landingops` 专属目录树及所有权标记；
5. root-only `application.env` 与 `database-client.cnf`；
6. 空生产数据库 `landingops`；
7. 数据库身份 `landingops_app@localhost`；
8. 初始空库 dump、私有配置备份与哈希 manifest。

没有写入 `/www/wwwroot`、现有 vhost、现有数据库或 `landingops_test`。

## 部署身份边界

`landingops-deploy` 当前边界：

- shell 为 `/usr/sbin/nologin`；
- 密码状态为 locked；
- 只属于 `landingops-deploy` 组；
- 没有 `authorized_keys`；
- 没有 sudoers 条目；
- 没有运行进程；
- 无法读取 root-only 私有配置；
- 无法写入 `/srv/landingops`、`shared`、`private`、`backups` 或 `template-zips`；
- 只对 `releases`、`staging`、`shared/locks` 具有计划内写权限；
- 对既有 `/www/wwwroot` 没有可写路径。

因此 B1 没有为该身份开放 SSH 登录或通用命令执行。B2 如需传输，必须另行安装固定协议、固定公钥和强制命令，不能把 nologin 改成普通交互 shell。

## 目录权限

| 路径 | owner:group | mode | 用途 |
| --- | --- | --- | --- |
| `/srv/landingops` | `root:root` | `0755` | 安装根；部署账号不能替换顶层指针 |
| `releases` | `landingops-deploy:landingops-deploy` | `0750` | 不可变 release 目标 |
| `staging` | `landingops-deploy:landingops-deploy` | `0700` | 私有暂存 |
| `shared` | `root:root` | `0755` | 持久化父目录 |
| `shared/locks` | `landingops-deploy:landingops-deploy` | `0700` | B2 发布互斥锁 |
| `shared/private` | `root:root` | `0700` | 应用及数据库私有配置 |
| `shared/template-zips` | `root:root` | `0700` | 私有模板库存储；运行时身份尚未授权 |
| `shared/backups` | `root:root` | `0700` | 初始备份及未来受控备份 |

`.landingops-installation.json` 为 `root:root 0444`，状态是 `environment_prepared`。目录树无符号链接，`current` 不存在，受管文件共 6 个。

## 数据库隔离

- `landingops` 存在且表数量为 0；
- `landingops_app@localhost` 存在；
- global privilege 数为 0；
- 其他 schema privilege 数为 0；
- 目标 schema privilege 数为 19；
- 使用 root-only client config 连接时 `DATABASE()=landingops`；
- 生产迁移未执行。

数据库管理员只用于本阶段建库、建用户和授权，不写入应用配置。应用密码由服务器端随机生成，不出现在本地报告或命令参数中。

## 备份状态

`shared/backups` 中包含：

- `initial-empty-database.sql`；
- `initial-private-config.backup`；
- `initial-backup-manifest.txt`。

当前验收哈希：

- empty database dump：`9F3DFB47675FDE9D94524D7370347902AAF51945EEE20B76ADBE06B30713DF07`；
- private config backup：`648F678B27276758FECDDAAFE8B7421DEEC6EB7F69A001A493320EEEC626BA20`。

manifest 与实际文件哈希一致，全部为 `root:root 0600`。该备份位于同一服务器，只证明初始备份生成与完整性；独立恢复演练和离机备份仍为 B2 前置条件，不能标记为已验证恢复能力。

## 域名、TLS 与 Nginx 只读结果

- `landingops.vqemaro.com` 当前解析到 Cloudflare 地址；
- 公网 HTTPS 返回 200；
- 宝塔 vhost 中没有该精确管理域名；
- 服务器未准备该域名专属证书文件；
- 使用正确 Host/SNI 固定直连源站时证书身份不匹配；
- `nginx -t` 通过。

因此公网 200 不能视为 LandingOps 已上线。独立 origin vhost、正确证书和受控 reload 均需 B2 单独授权。

## 故障与恢复证据

准备期间发现并修复了两个仅限本地编排器的问题：

1. Windows PowerShell stdin 的 CRLF 导致认证成功后远端 shell 返回 2；修复为 LF-only payload，并以注释吸收末尾换行。
2. GRANT 格式字符串中的反引号处于 shell 双引号内，被解释为命令替换；固定安全数据库标识符改为 `landingops.*` 后，数据库专用 DDL 诊断的 `CREATE DATABASE / CREATE USER / GRANT` 全部通过并完整回退。

两次失败准备事务均执行精确回退；最终正式事务成功。旧错误 DPAPI 凭据已删除，最终凭据在保存前完成 socket/TCP 认证。

## Phase 1M-B2 精确前置计划

B2 不得直接使用 `SimulatedDeploymentExecutor`。开始前必须单独批准并完成：

1. 扩展真实证据契约，识别 `environment_prepared` marker；当前 fresh 配置不能把非空安装根误判为可直接覆盖。
2. 为 `landingops-deploy` 安装受限公钥与固定传输协议；继续禁止交互 shell、SFTP/SCP 任意路径和端口转发。
3. 构建版本化 release，验证 ZIP、manifest、逐文件 SHA-256 和公共 Web Root 白名单。
4. 只向 `staging` 传输；在 `shared/locks` 获取实例锁；验证无符号链接和同文件系统条件后提交到 `releases`。
5. 设计 root-owned 固定切换器或人工 root 切换步骤。部署账号不能直接写安装根，不能自行创建/替换 `current`。
6. 确定独立 PHP-FPM/runtime 身份及其对 `shared/private`、`template-zips` 的最小读取/写入权限，不能把 root-only 配置开放给部署账号。
7. 在迁移前完成数据库恢复演练和离机备份；代码回滚不得自动回滚数据库。
8. 单独批准并安装独立 Nginx vhost、SPA/API 路由和正确 TLS 证书；执行 `nginx -t` 后才允许一次 reload，禁止 restart。
9. 执行 001–006 迁移、初始化首个超级管理员、原子切换、健康检查和失败回滚均需 B2 明确授权。

当前阻断项是：真实生产传输/切换器尚未实现、运行时身份未准备、恢复演练未完成、独立 vhost/TLS 未准备。平台仍未安装或运行。

