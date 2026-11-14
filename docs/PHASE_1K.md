# Phase 1K：通配符复用与首次建站前置验收

## 已实现

- `WildcardDnsPolicyEvaluator` 对精确记录、实际适用通配符、祖先 NS 委派、确认 IPv4、代理、TTL、完整性和五分钟新鲜度执行纯只读、fail-closed 判断。
- DNS 预检查按候选 FQDN 查询精确名称、直接父级通配符和 Zone 内祖先委派名称；结果带证据来源、观察/失效时间、资源身份和凭据上下文。
- Dry Run 沿用既有四步向导，DNS 计划固定为零创建、零更新、零删除。仅 `wildcard_covered` 且宝塔检查为 `clear` 时返回 `ready_for_test_deployment`；该状态仍不是执行授权。
- 总管理员可按 Cloudflare 连接自动发现 Zone；最多读取 50 个 Zone 元数据、筛选前 20 个通配符。发现结果不入库、不授权团队，无 Zone List 权限时继续手动模式。
- 宝塔站点详情新增每批最多 20 项的游标式继续读取。26 个站点可由 20+6 两批完成；失败批保留相同 offset 供重试，所有站点仍默认为 external。
- Vue 域名页显示 Zone 适用性和通配符证据，宝塔页可继续读取下一批详情；没有创建 DNS、建站或部署按钮。

## 首个隔离静态测试站点计划

1. 使用已授权且验证有效的 LTS v1.0 最小静态模板，固定不可变模板版本 ID。
2. 选择团队获授权的主域名和未被实例、预留、宝塔任一绑定占用的候选 FQDN。
3. 重新取得 `wildcard_covered` 证据；DNS 写入数必须全部为 0。
4. 使用独立候选根目录；目录存在、符号链接、未知内容或路径逃逸均阻断。不得复用 26 个外部站点的根目录。
5. 未来正式阶段才允许宝塔创建独立站点、离线构建静态 release、原子切换和 HTTPS 验证。PHP、原生后台及第三方 PHP 继续禁用。
6. 失败时不触碰 DNS 通配符和既有站点；未切换 release 只清理任务私有 staging，切换后仅回到本站 known-good release。

## 测试证据

- PHP：103 项通过，包含深层通配符、IP 匹配/不匹配、精确覆盖、AAAA/CNAME、NS 委派、代理、TTL、过期/不完整证据及宝塔 26 站点分批。
- Vue/Vitest：30 项通过；TypeScript 和 Vite production build 通过。
- PHP 语法：103 个文件通过；Composer strict validation 通过。
- MariaDB：真实 `landingops_test` 在 MariaDB 10.11.10 上通过，连接账号限定测试库；22 张既有业务表、002–005 重复迁移、FK/CHECK/UNIQUE、PDO Session、负责人事务、单宝塔约束、模板/域名/多 Zone 凭据上下文、Dry Run 零写入、失败迁移恢复与测试数据清理均通过。退出码为 0。本轮没有新增迁移或 schema 变更。

## 仍未满足的执行前置条件

- 未重新使用真实 Cloudflare Token；此前 Token 已销毁，已知 `zarnuvo.com` 通配符存在但其 content 没有留在脱敏证据中，因此不能判为 matched_server。
- 宝塔只读联调已知 26 站点，但本轮没有获得新的真实授权去补读剩余 6 项；代码和模拟测试证明批次契约。
- 目录/ACL/Unix 用户、Nginx private 边界、独立 PHP-FPM pool、同文件系统原子切换、磁盘空间与 SSL 签发仍未真实验收。静态测试站点也未创建。
- `write_operations_performed=false`；没有 DNS、宝塔、文件、SSL 或生产资源写入。
