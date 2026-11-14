# LandingOps 首次安装只读计划（Phase 1M-A）

## 已确认安全的前置条件

- 平台部署与客户网站部署安全域已经分离。
- release 构建、SHA-256 manifest、最小 Web Root 和本地模拟回滚已通过自动化测试。
- 真实写入入口仍禁用。
- 可信 SSH 采集器固定 Host Key 和只读命令集合，不接受任意命令。

## 需要人工准备

1. 审批并创建非 root 专用只读 SSH 账号，按 Phase 1M-A 文档授予最小读取权限。
2. 在仓库外准备真实部署配置，记录已核对 SSH 指纹，不保存任何密码或私钥。
3. 运行一次真实 `collect`，确认 `/srv/landingops` 状态、26 个外部站点根路径、PHP/Nginx、磁盘和数据库二进制。
4. 对只读证据中的 blockers、pending 和过期时间进行人工复核。

## 必须单独授权的服务器修改

- 创建 `/srv/landingops` 与 releases/current/staging/shared/backups；
- 创建未来专用部署账号并设置 owner/group/ACL；
- 创建生产数据库和最小数据库账号；
- 安装 Nginx vhost、SPA/API 路由和 TLS；
- 上传 release、执行迁移、初始化首个总管理员；
- 原子切换 current 和执行健康检查。

## 仍缺少证据

- 真实 SSH 采集结果；
- 原子切换写入实测；
- 数据库备份恢复演练；
- Nginx 配置测试/reload；
- TLS 与管理域名切流；
- 生产数据库身份与权限。

## Phase 1M-B 边界

Phase 1M-B 只有在证据未过期、blockers 已清零、人工变更计划获得单独授权后，才可实现受控写入。Phase 1M-A 的 CLI 没有 init/update/rollback、上传或远端写命令。

`all_steps_execute=false`

`real_execution_enabled=false`

`write_operations_performed=false`
