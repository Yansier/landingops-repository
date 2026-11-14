# Phase 1L 测试与验收报告

## 自动化结果

| 项目 | 结果 |
| --- | --- |
| PHP 自动化 | 125 passed, 0 failed（含 13 项部署工具测试） |
| PHP 语法检查 | 通过 |
| Composer `validate --strict` | 通过 |
| Composer production install | 通过 |
| Vue/Vitest | 30 passed |
| TypeScript | 通过 |
| Vite production build | 通过 |
| Release/manifest/ZIP 冒烟 | 通过 |
| doctor/plan/模拟 init | 通过，真实写入均为 false |
| MariaDB 10.11.10 | 用户执行 `landingops_test` 集成测试通过：23 张表、001–006；Phase 1L 无新迁移 |

## 部署工具覆盖场景

- 切换到另一 SSH 主机配置仍通过相同契约解析。
- 拒绝 root、非法 SSH 主机、路径逃逸、目录重合、测试数据库、明文秘密和模式冲突。
- 缺少 PHP 扩展、Composer/Vue 构建失败、凭据缺失或祖先符号链接时阻断。
- release 只公开最小 Web Root，排除 tests、deploy、Git、开发依赖和凭据。
- manifest 被篡改时在切换前失败。
- 目标目录存在未知内容时拒绝，模拟外部站点保持不变。
- 初始化、更新与回滚保留 shared 持久化数据。
- 数据库迁移失败、指针切换失败、健康检查失败均恢复或保持上一代码版本；不自动回滚数据库。
- 未指定本地模拟根时禁止执行。

## 尚未执行

以下事项按 Phase 1L 边界刻意未执行，不能解释为已经验收：

- 真实 SSH 上传、远端锁、原子符号链接切换和远端健康检查；
- 创建服务器目录、专用 Unix 用户/组或 ACL；
- 新建/修改 Nginx、PHP-FPM、宝塔站点或 TLS；
- 真实生产数据库初始化、备份、恢复或迁移；
- 真实 LandingOps 平台切流和回滚；
- 任何客户网站、Cloudflare DNS 或生产 Worker 操作。

真实安装必须由 Phase 1M 单独授权，并逐项复核目标身份与变更计划。
