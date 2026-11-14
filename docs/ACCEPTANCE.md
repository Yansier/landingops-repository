# LandingOps Phase 1A / V1 验收标准

## 1. 验收说明

Phase 1A/1A.1 的直接验收对象是文档与契约的一致性。下列 V1 用例是 Phase 1B 实现和测试的可复现基线；涉及第三方系统的用例必须使用测试账号、测试 Zone 和隔离站点，不得连接现有生产资源。

优先级：P0 为上线阻断；P1 为核心能力；P2 为运维完善。每个用例应保存请求关联 ID、任务/步骤状态和脱敏日志。

## 2. Phase 1A 文档验收

| ID | 操作 | 预期结果 |
| --- | --- | --- |
| DOC-01 P0 | 检查根 README 与 `docs/` 交付物 | 七份要求文档、补充 API 契约及两个示例存在，链接有效 |
| DOC-02 P0 | 全文搜索多服务器池、截图预览、运行时回查等能力 | 只作为排除/禁止项出现，没有被设计为功能 |
| DOC-03 P0 | 对比 PRD、架构、数据库、部署和验收 | 均为一台宝塔、多 Cloudflare 连接、站点本地配置 |
| DOC-04 P0 | 解析两个 JSON 示例 | JSON 语法有效，不含真实凭据、真实生产域名或截图字段 |
| DOC-05 P1 | 核对备用页引用 | 引用实际文件、标题/用途/哈希，不复制或编造源码 |
| DOC-06 P0 | 对比 manifest、schema、模板标准、数据库和部署文档 | 公开配置字段、revision/hash/release 术语与恢复语义一致 |

## 3. 身份与权限

| ID | 前置/操作 | 预期结果 |
| --- | --- | --- |
| AUTH-01 P0 | 员工直接调用 API 读取他人负责实例 | 后端返回 403/不可枚举结果；即使手工构造 ID 也失败 |
| AUTH-02 P0 | 团队管理员访问另一团队实例/用户 | 被拒绝并写安全审计 |
| AUTH-03 P0 | 总管理员管理任意团队授权 | 成功，变更有前后摘要和操作者 |
| AUTH-04 P1 | 停用用户后继续使用旧会话写配置 | 会话失效或写操作被拒绝；站点本身不下线 |
| AUTH-05 P0 | 前端显示受限按钮后直接绕过 UI 调 API | API 独立完成完整授权，不依赖前端隐藏 |

## 4. 模板与授权

| ID | 前置/操作 | 预期结果 |
| --- | --- | --- |
| TPL-01 P0 | 上传缺少根 `index.html` 或 `landingops.json` 的 ZIP | 拒绝并给出定位明确的验证错误 |
| TPL-02 P0 | 上传包含 `../`、绝对路径、符号链接或解压炸弹的 ZIP | 拒绝，无文件写出暂存根 |
| TPL-03 P1 | 团队管理员上传合规模板 | 创建不可变版本并默认授权本团队 |
| TPL-04 P0 | 同 `slug + version` 上传不同哈希 | 拒绝，不能原位覆盖已发布版本 |
| TPL-05 P0 | 撤销团队模板授权 | 后续创建不可选；既有实例保持在线且配置不变 |
| TPL-06 P1 | 查看模板库 | 仅返回规定元数据/能力标签，无截图、缩略图或预览字段 |
| TPL-07 P0 | 模板声明原生后台但缺入口或 default enabled | 验证失败；后台默认必须关闭 |
| TPL-08 P0 | ZIP 包含 `.env`、私钥或供应商核心误入 | 阻止并提示安全复核，日志不输出内容 |
| TPL-09 P0 | manifest 的 `public_paths` 指向未标 public 或不存在的 schema 字段 | 验证拒绝，不能生成公开配置 |
| TPL-10 P0 | 合规模板实例化 | `index.html` 的唯一占位符替换为同 release 的内容寻址 JS；文件中无私有字段 |

## 5. Cloudflare 多连接与域名

| ID | 前置/操作 | 预期结果 |
| --- | --- | --- |
| DNS-01 P0 | 两个不同 Cloudflare 连接分别导入不同 Zone并授权团队 | 实例按 `domain→zone→connection` 调用正确连接，Token 不下发前端 |
| DNS-02 P0 | 从另一连接重复导入相同规范化 Zone 名 | 检测重复并阻止可用状态，要求管理员处理 |
| DNS-03 P0 | 员工选择未授权主域名构造请求 | 后端拒绝，不创建预留或任务 |
| DNS-04 P0 | FQDN 已有 A/AAAA 指向其他服务器 | 任务在写入前进入需处理，不覆盖记录 |
| DNS-05 P0 | FQDN 已有不兼容 CNAME | 阻止，不删除、不改写 |
| DNS-06 P1 | 既有记录已指向本机且策略兼容 | 核验后标记复用；取消任务时不删除该记录 |
| DNS-07 P0 | 匹配到含义不明的通配符记录 | 阻止并显示管理员处置原因 |
| DNS-08 P0 | Cloudflare 查询超时/分页不完整 | 视为未知而非不存在，不进行创建 |
| DNS-09 P1 | 一个连接失效，另一个正常 | 仅对应 Zone 新任务失败/等待；其他连接与既有站点不受影响 |
| DNS-10 P1 | 未设置主域名特例的新部署 | 默认只计划 A/确认 IPv4、DNS only、TTL Auto，不创建 AAAA/CNAME/通配符 |

## 6. 宝塔边界

| ID | 前置/操作 | 预期结果 |
| --- | --- | --- |
| BT-01 P0 | 尝试新增第二个活动宝塔配置 | 数据库/服务层拒绝；无服务器池选择 |
| BT-02 P0 | 目标 FQDN 已在既有宝塔站点 | 阻止，不追加域名、不改配置、不接管 |
| BT-03 P0 | 目标路径已存在未知文件 | 阻止，不清空、不覆盖 |
| BT-04 P1 | 同任务创建调用超时但外部实际成功 | 重试先查询并采纳匹配站点，不重复创建 |
| BT-05 P0 | 宝塔 API 停机 | 新任务暂停/失败可恢复；既有 Nginx/PHP 站点仍可访问 |
| BT-06 P0 | 宝塔创建站点后根目录只有已登记哈希的默认文件 | 文件被整体隔离留证，再建立受管 current；不逐文件覆盖 |
| BT-07 P0 | 宝塔根目录含未知文件、链接或所有者异常 | 进入需处理，目录完全不改动 |
| BT-08 P0 | 环境不支持 symlink 原子替换且无已验证 root include 策略 | 阻止部署，不降级为活动目录逐文件覆盖 |
| BT-09 P0 | 团队管理员或员工调用宝塔配置/发现 API | 后端拒绝；导航隐藏不能替代后端授权 |
| BT-10 P0 | 保存第二条宝塔配置或以陈旧 `version` 更新 | 单例约束或乐观锁拒绝，第一条配置不被覆盖 |
| BT-11 P0 | 轮换宝塔 API Key 时连接已显式停用 | 密钥密文更新、验证失效，但管理员停用状态保持不变 |
| BT-12 P0 | 发现同域名、相似路径或空目录的既有站点 | 仍为 `external/unverified/conflict`；缺少外部 ID、全部绑定、根目录、marker、实例记录任一证据都不可认领 |
| BT-13 P0 | API 未返回完整站点/域名字段，或无法读取目录内容 | 返回 `unsupported/needs_attention`，不得把“未发现”解释为安全 |
| BT-14 P0 | 候选路径包含 `..`、反斜杠、符号链接或未知内容 | 拒绝或明确冲突，不读取/修改生产目录 |
| BT-15 P0 | 仅发现 PHP 版本，未证明独立 Unix 用户、FPM pool 和 private 隔离 | 未来 PHP 站点部署能力保持 `disabled` |

## 7. 单站和批量部署

| ID | 前置/操作 | 预期结果 |
| --- | --- | --- |
| JOB-01 P0 | 合法单站部署 | 每步可追踪，最终 DNS/站点/文件/SSL 验证通过，实例 active |
| JOB-02 P0 | 并发提交同一 FQDN | 只有一个持有活动预留；另一请求明确冲突 |
| JOB-02A P0 | held/committed 预留提交 NULL `active_fqdn`，或提交第二个相同活动 FQDN | CHECK/UNIQUE 拒绝；released/expired 可使用 NULL |
| JOB-03 P1 | 批量 5 行，其中 1 行配置错误、1 行外部失败 | 错误行提交前标识；有效行各自执行，可产生部分成功 |
| JOB-04 P1 | 重试批量失败项 | 只重试失败单站任务，已成功项不重复部署 |
| JOB-05 P0 | worker 在外部创建后、回写前崩溃 | 租约恢复后先查询外部状态，避免重复资源 |
| JOB-06 P1 | API 返回 429 和 Retry-After | 有界退避并按提示重试，步骤记录次数 |
| JOB-07 P0 | 鉴权/权限错误 | 不自动重试，不泄露凭据，转人工处理 |
| JOB-08 P0 | 取消部分完成任务 | 只补偿本任务创建且状态未变的资源；未知资源保留并列清单 |
| JOB-09 P0 | 任务失败且 DNS 为复用记录 | 补偿绝不删除复用记录 |
| JOB-10 P1 | SSL 申请失败 | 不标记 active，展示可重试/人工处置；不影响其他站点证书 |
| JOB-11 P0 | SSL 尚未验证成功 | V1 默认不把实例标为 active，也不自动开放临时 HTTP |

## 8. 配置、事件与原生后台

| ID | 前置/操作 | 预期结果 |
| --- | --- | --- |
| CFG-01 P0 | 两个客户端以相同 revision 并发写 | 一个成功；另一个 409，旧值不覆盖新值 |
| CFG-02 P0 | staging release 完成前进程中断 | `current` 仍指向完整旧 release，公开 JS 与私有配置保持配套，站点继续运行 |
| CFG-03 P0 | 原生后台写 revision 8，LandingOps 仍持 revision 7 | LandingOps 识别并登记本地变更，不覆盖 revision 8 |
| CFG-04 P1 | 恢复 revision 5 的内容 | 写成新的更高 revision，而不是将计数回退 |
| CFG-05 P0 | 多个实例使用同一模板，修改其中一个 | 只有目标实例本地配置变化，其他实例哈希不变 |
| CFG-06 P0 | 将页面浏览绑定为 Purchase | schema/业务规则拒绝，事件必须对应真实操作 |
| CFG-07 P1 | 新建金额事件不改默认值 | 值为 1、币种 USD；允许合法修改 |
| CFG-08 P0 | 模板支持后台但创建时未启用 | `/admin` 被 Nginx/文件发布真实阻断，不只是隐藏链接 |
| CFG-09 P0 | 启用原生后台并登录 | 使用本站独立密码，只能读写本站 schema 允许配置 |
| CFG-10 P0 | API/页面读取后台认证设置 | 永不返回明文或 password_hash；日志无敏感值 |
| CFG-11 P0 | 私有配置含非 public 字段后发布 | 公开 JS 只包含 manifest 白名单，私有 hash 与 public hash 分离 |
| CFG-12 P0 | 浏览器缓存旧 JS 后发布新配置 | 新 HTML 引用新 revision/hash 文件；旧 immutable JS 不被改写，页面不混用版本 |
| CFG-13 P0 | release 切换前中断 | active current/revision 不变；staging 可安全隔离 |
| CFG-14 P0 | 切换后健康检查失败 | current 原子回到上一 known-good；失败 revision 不成为 active |
| CFG-15 P0 | 恢复与当前 content_hash 相同的历史内容 | 创建更高 revision 成功；数据库非唯一 hash 索引不冲突，并记录来源版本 |
| CFG-15A P0 | active release 提交 NULL/错配 `active_instance_id`，或同实例提交第二个 active release | CHECK/UNIQUE 拒绝；非 active release 可使用 NULL |
| CFG-16 P1 | 显式启用原生后台 | 要求至少 12 字符用户密码；明文不进入日志、任务或响应，只保存现代哈希 |

## 9. 负责人转交与审计

| ID | 前置/操作 | 预期结果 |
| --- | --- | --- |
| OWN-01 P0 | 团队管理员转交给同团队启用员工 | 当前负责人改变；创建人保持；历史追加 |
| OWN-02 P0 | 转交给另一团队或停用员工 | 后端拒绝，实例不变 |
| OWN-03 P1 | 转交完成后旧负责人尝试修改 | 被拒绝；新负责人可操作 |
| OWN-04 P1 | 转交实例有启用的原生后台 | 后台密码不自动共享/轮换，站点运行不变 |
| AUD-01 P0 | 执行模板授权、部署、转交、配置写入 | 审计含操作者/目标/时间/结果/关联任务，无 Token/密码 |

## 10. 站点运行独立性

| ID | 前置/操作 | 预期结果 |
| --- | --- | --- |
| ISO-01 P0 | 部署后停止 LandingOps API 与 worker | 前台页面、资源和站点自身 PHP 功能继续工作 |
| ISO-02 P0 | 同时阻断实例到 LandingOps DB/API | 浏览器网络与服务器日志均无运行时回查，站点继续工作 |
| ISO-03 P0 | LandingOps MySQL 故障 | 既有站点不受影响；仅管理操作/任务暂停 |
| ISO-04 P0 | LandingOps 离线时从启用的原生后台修改配置 | 修改成功、revision 增长、页面生效；恢复后不被旧值覆盖 |
| ISO-05 P1 | 本地配置损坏 | 使用已知良好副本或安全维护页，不查询 LandingOps |

## 11. 备用页与外部组件

| ID | 前置/操作 | 预期结果 |
| --- | --- | --- |
| AST-01 P1 | 管理员导入当前参考 HTML | 保存不可变版本、大小和 SHA-256；不生成截图 |
| AST-02 P0 | 上传新的备用 HTML 并设默认 | 新部署可选择新版本；既有实例不被静默改写 |
| CMP-01 P0 | 上传含疑似密钥的供应商组件 | 阻止发布并提示安全迁移；日志不显示密钥 |
| CMP-02 P1 | 组装组件 | `index.php/core.php` 与模板 ZIP 分开记录版本；原始 `index.html` 保留 |
| CMP-03 P0 | 尝试配置按审核人员身份分流 | 产品/API 无此能力，拒绝未知配置字段 |

## 12. 文件权限隔离

| ID | 前置/操作 | 预期结果 |
| --- | --- | --- |
| PERM-01 P0 | 以控制面 Web 用户尝试写实例 current/release | OS 权限拒绝；只能提交任务和写 intake |
| PERM-02 P0 | 将上传 ZIP 当 PHP/include 或通过 URL 访问 | noexec/Web root 外策略阻止；文件只能由 worker 扫描 |
| PERM-03 P0 | 站点 PHP 尝试读取平台密钥、模板库或另一实例 private | Unix 权限/ACL/FPM pool 拒绝并记录安全事件 |
| PERM-04 P0 | worker 执行实例 A 任务时尝试写实例 B | 实例级路径校验和 ACL 拒绝 |
| PERM-05 P0 | 宝塔无法提供独立 PHP 用户/FPM pool或等价隔离 | 只允许静态站点；原生后台和供应商 PHP 保持禁用 |
| PERM-06 P0 | 构造 current 指向本站外、跨文件系统或未知 symlink | realpath/lstat、marker 和设备号校验拒绝，活动指针不变 |

## 13. Phase 1J 配置与只读诊断

| 编号 | 场景 | 预期 |
| --- | --- | --- |
| CFG-01 P0 | nullable number 未填写或 URL 填写后恢复为空 | canonical 值为 `null`，不变成 `0` 或空字符串 |
| CFG-02 P0 | integer 输入 `1.2`、`12abc` 或超安全范围 | 前端拒绝且不截断；后端 Schema 仍是最终校验边界 |
| CFG-03 P0 | 可选字段未启用 | 字段从请求配置中完全省略；必填字段不可省略 |
| CFG-04 P0 | string/number/integer/boolean 数组 | 每项严格按 `items.type` 转换，非法项目明确报错 |
| CFG-05 P0 | Schema 含对象数组、动态对象或未支持组合 | 返回 `422 config_form_schema_unsupported`，不得静默遗漏 |
| CFG-06 P0 | Dry Run 含 private 值 | API 只返回摘要，公开投影与响应都不出现该值 |
| DIAG-01 P0 | 宝塔清单或详情超预算/失败/缺根目录字段 | 报告 `needs_attention`，不得声称兼容或无冲突 |
| DIAG-02 P0 | Cloudflare Token/Zone/Account 不匹配或 401/403/429/超时 | 返回脱敏明确错误，不执行 DNS 写入 |
| DIAG-03 P0 | 运行真实诊断 | 凭据仅从私有环境输入，输出不含 Token、Key、签名、密文或 DNS content |
| DRY-01 P0 | 真实 MariaDB API 往返执行配置表单与 Dry Run | Cookie/CSRF/PDO 成功；实例、预留、任务和步骤计数不变，测试数据回滚 |

## 14. 验收退出条件

- 所有 P0 通过；P1 无未评估的高风险失败。
- 任务幂等、DNS/宝塔保护、配置冲突和独立性必须有自动化或隔离集成测试证据。
- 待联调接口均被目标环境实测替换为版本化适配器结论。
- 安全审查确认无真实凭据、无跨团队越权、无 ZIP 路径逃逸、无运行时 LandingOps 依赖。

## Phase 1K 通配符与首次建站前置验收

- 深层候选只检查实际适用通配符；`*.example.com` 不得直接证明 `offer.pages.example.com` 覆盖。
- 精确 A/AAAA/CNAME、Zone 内祖先 NS 委派、其他服务器 IP、代理开启、TTL/元数据缺失、过期或不完整结果均不得返回 `wildcard_covered`。
- 成功复用计划必须返回 `dns_strategy=reuse_existing_wildcard`、create/update/delete 均为 0、`dns_write_required=false`、`execute=false`。
- Zone 自动发现按连接隔离，不跨 Token 权限；发现不写 Zone/主域名/授权。缺少 Zone List 权限仍可手动登记。
- 26 个宝塔站点详情可在单批最多 20 项的限制下分批完成；任何失败或未查询站点阻止 clear，既有站点全部保持 external。
- Dry Run 不写实例、预留、任务、DNS、宝塔、文件或 SSL。`ready_for_test_deployment` 也不构成正式执行许可。
- 目录/ACL/Nginx/FPM/同文件系统/磁盘/SSL 未获得真实证据时保持 unsupported；PHP、原生后台和第三方 PHP 禁用。
