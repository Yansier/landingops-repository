# Phase 1K / 1K.1：通配符复用与 Preflight 证据闭环

## 已实现

- `WildcardDnsPolicyEvaluator` 按 Cloudflare 多层通配符语义，从最具体父级到 Zone 根枚举候选；检查精确覆盖、祖先 TXT/A/AAAA/CNAME 等具体节点、NS 委派、empty non-terminal、名称服务器模式、确认 IPv4、代理、TTL、完整性和五分钟新鲜度。更具体通配符存在时不得退回较宽记录。
- DNS 预检查查询候选 FQDN、全部通配符候选、祖先精确记录及后缀记录。标准名称服务器允许较宽通配符跨 empty non-terminal；Advanced Nameservers 阻断；模式未知且影响结论时 fail closed。结果带证据来源、观察/失效时间、资源身份和凭据上下文。
- Dry Run 沿用既有四步向导，DNS 计划固定为零创建、零更新、零删除。`ready_for_test_deployment` 还必须逐项满足静态无后台模板、团队授权、配置、DNS、完整宝塔快照、目录明确不存在、父目录授权、Nginx 隔离及原子静态 release 能力；该状态仍不是执行授权。
- 总管理员可按 Cloudflare 连接自动发现 Zone；最多读取 50 个 Zone 元数据、筛选前 20 个通配符。发现结果不入库、不授权团队，无 Zone List 权限时继续手动模式。
- 宝塔站点详情每批最多 20 项。首批创建数据库服务端证据快照，后续必须提交 `snapshot_id + snapshot_version`；站点 ID、名称、路径形成 `inventory_fingerprint`。26 个站点可由 20+6 两批形成一个 complete 快照。任一批失败、快照过期、配置版本或站点集合变化会永久使该快照失败，不能由后一批成功覆盖。Preflight 只消费完整、未过期且现场清单身份仍一致的服务端证据；所有站点仍默认为 external。
- Vue 域名页显示 Zone 适用性和通配符证据，宝塔页可继续读取下一批详情；没有创建 DNS、建站或部署按钮。

## 首个隔离静态测试站点计划

1. 使用已授权且验证有效的 LTS v1.0 最小静态模板，固定不可变模板版本 ID。
2. 选择团队获授权的主域名和未被实例、预留、宝塔任一绑定占用的候选 FQDN。
3. 重新取得 `wildcard_covered` 证据；DNS 写入数必须全部为 0。
4. 使用独立候选根目录；目录存在、符号链接、未知内容或路径逃逸均阻断。不得复用 26 个外部站点的根目录。
5. 未来正式阶段才允许宝塔创建独立站点、离线构建静态 release、原子切换和 HTTPS 验证。PHP、原生后台及第三方 PHP 继续禁用。
6. 失败时不触碰 DNS 通配符和既有站点；未切换 release 只清理任务私有 staging，切换后仅回到本站 known-good release。

## Phase 1K.1 回归证据

- PHP：112 项通过，新增固定 exact/endswith 只读路径白名单、根级通配符覆盖多层名称、更具体通配符优先、TXT/A/CNAME 中间节点、NS 委派、标准/Advanced/未知名称服务器的 empty non-terminal、20+6 服务端快照、首批失败、批次间清单变化、过期证据、未知目录和完整静态就绪证据。
- Vue/Vitest：30 项通过；TypeScript 和 Vite production build 通过。
- PHP 语法：103 个 PHP 文件通过；Composer strict validation 通过。
- MariaDB：真实 `landingops_test` / MariaDB 10.11.10 验收通过，账号权限范围检查成功；23 张业务表、002–006、006 重复迁移、快照 CHECK/FK/乐观锁、模板/域名/多 Zone、PDO Session、负责人事务、Dry Run 零写入、失败迁移恢复及测试数据清理全部通过，退出码 0。正式 schema 与迁移记录保留，测试业务数据已清理。

## 仍未满足的执行前置条件

- 未重新使用真实 Cloudflare Token；此前 Token 已销毁，已知 `zarnuvo.com` 通配符存在但其 content 没有留在脱敏证据中，因此不能判为 matched_server。
- 宝塔真实只读联调已知 26 站点，但 1K.1 不使用真实外部凭据；模拟适配器证明服务端 20+6 快照契约。真实环境下仍须重新采集完整且未过期的快照。
- 目录/ACL/Unix 用户、Nginx private 边界、独立 PHP-FPM pool、同文件系统原子切换、磁盘空间与 SSL 签发仍未真实验收。静态测试站点也未创建。
- `write_operations_performed=false`；没有 DNS、宝塔、文件、SSL 或生产资源写入。
