# LandingOps 首次安装只读计划（Phase 1M-A）

## 已确认安全的前置条件

- 平台部署与客户网站部署安全域已经分离。
- release 构建、SHA-256 manifest、最小 Web Root 和本地模拟回滚已通过自动化测试。
- 真实写入入口仍禁用。
- 可信 SSH 采集器固定 Host Key 和只读命令集合，不接受任意命令。
- 专用 `landingops-audit` 已安装并通过 publickey-only、forced-command、权限边界和真实 OpenSSH 行为验收；root/FinalShell 登录保持不变。
- 真实只读证据已确认 `/srv/landingops` 当时不存在、站点清单完整、无路径重叠、必需 PHP CLI 扩展无缺失；该短时证据现已过期，不能直接复用。

## 需要人工准备

1. 经独立授权后，按 [Phase 1M-A.3](PHASE_1M_A3.md) 和最新 Runbook 更新固定 probe 与 root-only 摘要生成器；更新前后必须核对哈希、owner/mode、语法和回退条件。服务器当前仍运行旧版本，四项 blocker 尚未通过新的真实 collect 消除。
2. 在 Phase 1M-B 前重新生成短时站点摘要并执行新的 `collect/verify/plan`；证据必须在有效期内且与当前 Host Key、账号、路径和会话身份一致。
3. 在仓库外保留真实部署配置，同时记录已核对 SSH 指纹与对应公开 Host Key；不保存密码或私钥，安装根继续固定为 `/srv/landingops`。
4. 对新证据中的 blockers、pending、站点清单变化和过期时间进行人工复核。

## 必须单独授权的服务器修改

- 创建 `landingops-audit`、写入 authorized_keys、安装 wrapper/probe 和生成脱敏站点摘要；
- 创建 `/srv/landingops` 与 releases/current/staging/shared/backups；
- 创建未来专用部署账号并设置 owner/group/ACL；
- 创建生产数据库和最小数据库账号；
- 安装 Nginx vhost、SPA/API 路由和 TLS；
- 上传 release、执行迁移、初始化首个总管理员；
- 原子切换 current 和执行健康检查。

## 仍缺少证据

- 更新后 PHP-FPM 版本矩阵、Nginx vhost 能力、MariaDB 运行二进制与宝塔版本的同一轮短时完整探针证据；
- 未过期且 blockers 已处理的新一轮真实 SSH 证据；
- 原子切换写入实测；
- 数据库备份恢复演练；
- Nginx 配置测试/reload；
- TLS 与管理域名切流；
- 生产数据库身份与权限。

## Phase 1M-B 边界

Phase 1M-B 只有在证据未过期、blockers 已清零、人工变更计划获得单独授权后，才可实现受控写入。Phase 1M-A 的 CLI 没有 init/update/rollback、上传或远端写命令。

`all_steps_execute=false`

`real_execution_enabled=false`

`collector_write_operations_performed=false`
