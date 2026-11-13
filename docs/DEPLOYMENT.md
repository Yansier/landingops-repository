# LandingOps 部署与发布流程设计

## 1. 本文适用范围

本文定义 Phase 1B 可实现的流程和安全规则，不执行真实部署，也不声明某个宝塔 API 端点必然存在。真实调用必须依据目标宝塔版本与 Cloudflare 官方接口联调后落地。

部署完成后，网站访客的路径是：`浏览器 → Cloudflare DNS（及可能的代理）→ 宝塔 Nginx/PHP → 实例目录/本地配置`。LandingOps API、MySQL 和 worker 不在路径中。

## 2. 部署前置条件

- 唯一宝塔配置已由总管理员保存并验证；服务器地址、鉴权方式、PHP 版本能力已联调。
- 对应 Cloudflare 连接有效，Zone 已明确绑定该连接，主域名已授权给实例团队。
- 模板版本通过 LTS 校验且团队授权仍有效。
- FQDN 格式合规，未被活动实例/预留/同批其他行占用。
- 实例目录由系统派生；模板、组件、备用页、配置均有内容哈希。
- 任务提交时只保存密文引用，不把第三方 Token/API Key 放入任务 payload。

## 3. 单站部署步骤

### 3.1 事务性提交

API 在短事务内：再次鉴权 → 锁定授权/域名相关行 → 建立实例 → 持有唯一 FQDN 预留 → 建立初始配置版本 → 建立任务和步骤 → 写审计。事务失败不得留下半条预留。

### 3.2 `preflight`

1. 核验模板/主域名授权、连接状态、宝塔 singleton 和配置 schema。
2. 安全解析目标站点路径，确认位于允许的站点父目录且不已存在未知内容。
3. 获取 Cloudflare Zone 和记录的当前状态；列出精确 FQDN、A/AAAA/CNAME，并在适用时检查匹配的通配符记录。
4. 获取宝塔现有站点/绑定域名/根目录清单，规范化后检查目标 FQDN 与路径。
5. 保存检查快照摘要和外部资源 ID，不保存敏感响应。

任何信息不足、权限不足或查询超时都应阻止写入，不能把“查不到”视为“不存在”。

### 3.3 DNS 决策

按以下顺序：

1. 精确检查目标 FQDN 的 A、AAAA、CNAME；按 Cloudflare 能力处理同名互斥关系。
2. 检查会影响目标的通配符记录，并记录代理状态、TTL 和目标。
3. 与配置的本机公网 IPv4/IPv6 和预期 CNAME 目标比较。

决策表：

| 发现 | 行为 |
| --- | --- |
| 无精确记录且无阻塞性通配符 | 按审批策略创建记录，并记录 ID/内容/所有权 |
| 记录由同一实例先前步骤创建，内容一致 | 视为幂等成功 |
| 既有记录已明确指向本机、类型和代理策略兼容 | 只在核验后复用；标记 `reused`，补偿时绝不删除 |
| 指向其他服务器/不兼容 CNAME/冲突类型 | 阻止，进入 `needs_attention` |
| 通配符会使结果不确定 | 阻止并由管理员判断，不自动覆盖或删除 |
| 无权限/分页不完整/超时 | 阻止；不得创建以“试试看” |

默认不覆盖任何已有记录。仅“核验后复用”，不等于取得该记录所有权。

V1 保守默认：只创建指向已确认服务器公网 IPv4 的 `A` 记录，Cloudflare `proxied=false`（DNS only）、TTL 使用 Cloudflare Auto；不自动创建 AAAA、CNAME 或通配符。只有管理员确认稳定 IPv6、代理/证书兼容性或明确 CNAME 目标后，才可按主域名策略覆盖这些默认值。上述能力仍需真实测试 Zone 联调确认。

### 3.4 宝塔站点决策

- 目标 FQDN 已绑定任一既有站点：阻止，不追加绑定、不接管。
- 目标路径存在或被既有站点使用：阻止；即使目录看似为空也需要管理员确认来源。
- 找到同一 LandingOps 实例先前创建并记录外部 ID/路径的站点：核验一致后幂等复用。
- 无冲突才创建新站点，选择已联调支持的 PHP 版本，记录站点 ID、域名和根目录。
- 不对其他宝塔站点改配置、重载内容或改证书。

### 3.5 文件发布

#### 3.5.1 目录所有权判定

实例容器和站点根路径全部由系统从实例 ID 派生。创建宝塔站点前后都要 `lstat` 并记录设备号、inode/类型、所有者和内容清单：

- 目标不存在：可创建带 LandingOps ownership marker 的实例容器。
- 目标为本实例已登记容器，marker、实例 ID、数据库记录和路径全部一致：可继续幂等发布。
- 宝塔刚创建网站后产生默认根目录：只允许匹配**目标宝塔版本联调后登记的精确文件名+SHA-256 allowlist**。已知引导文件整体移动到实例内 `bootstrap-backup/<timestamp>/` 留证，不原位覆盖或静默删除。
- 目录非空但有任一未知文件、未知子目录、符号链接、所有者异常或 marker 不匹配：立即进入 `needs_attention`，禁止改名、覆盖、合并或清空。

“目录看起来像宝塔默认页”不是所有权证据；allowlist 必须来自真实环境确认，不能在代码中凭经验猜测。

#### 3.5.2 首次发布

1. worker 在同一文件系统的 `instances/<key>/staging/<job-id>/` 安全解压只读模板，并按批准版本装配供应商组件/备用页。
2. 在 staging release 的 `private/.landingops/config.json` 写 canonical 配置；在同 release 的 `public/assets/` 按 manifest `public_paths` 生成无敏感信息的 `landingops.config.r1.<hash12>.js`，将 `public/index.html` 的唯一占位符替换为同 release 相对路径。
3. 生成 release manifest，校验所有文件 hash、schema、HTML→JS 引用、权限和入口；同步文件与目录元数据。
4. 把 staging 原子重命名为不可变 `releases/<release-id>/`；创建临时链接 `.current-<job-id>` 指向该 release，再以同目录原子 rename 建立 `current`。
5. 宝塔站点 document root 固定指向实例 `current/public`。首次暴露 DNS 前验证 Nginx 能安全跟随该链接，且 `current/private` 无 URL 映射并受 Unix 权限保护。
6. 配置 Nginx 禁止访问 dot-path/private 路径；后台未启用时不发布或阻断 `/admin`。

若宝塔 API 必须先创建实体目录，则先完成上面的默认根目录识别与隔离，再把该目录安全改名留证，最后在同一路径建立 `current` 链接。DNS 尚未创建/启用前完成此动作。不得把 release 内容逐文件覆盖到宝塔生成目录。

#### 3.5.3 后续更新与原子切换

- 任何模板或配置更新都创建新的完整 release；活动 release 只读且不原位编辑。
- `current` 必须是 worker 管理的符号链接/等价原子指针。创建 `.current-next` 后核验目标，再用同文件系统 rename 原子替换；每个 HTML 永远与本 release 的内容寻址配置 JS 配套。
- 切换前必须对目标做 `realpath/lstat`、实例 ownership marker、release manifest 和父目录设备号校验；链接目标只能是本站 `releases/<release-id>`，禁止绝对外跳、`..`、嵌套未知链接或跨文件系统目标。
- 若目标文件系统、宝塔/Nginx 配置不支持原子 symlink rename，唯一允许的替代是经测试的“版本化 Nginx root include 原子替换 + `nginx -t` + reload”策略。两者都不具备时阻止部署，不降级为逐文件覆盖。
- 切换后执行本地和 FQDN 健康检查。成功才标记 release `active/known_good` 并推进 active revision。

#### 3.5.4 失败恢复

- staging/校验失败：隔离或清理本任务 staging，`current` 不变。
- rename 返回不确定：读取 `current` 的链接目标与 release manifest 核验，不盲目再次切换。
- 切换后健康检查失败：原子切回上一 known-good release，本次 release 标记 failed；外部 DNS/站点不删除。
- 用户主动恢复历史内容：从历史配置复制 payload，产生更高 revision 和新 release；不能直接把 `current` 指向旧 revision 作为业务恢复。

不得先清空既有路径再复制，也不得从 Web 请求进程解压或发布到生产目录。

### 3.6 SSL/HTTPS

1. 等待 DNS 可观察到预期目标；设置有界超时，显示传播等待状态。
2. 使用目标宝塔版本实际支持的方式申请证书；记录申请 ID/错误摘要。
3. 成功后启用 HTTPS 和约定的 HTTP→HTTPS 跳转。
4. 从外部和/或服务器侧验证证书域名、有效期、TLS 响应及最终页面健康检查。
5. 证书失败时保留明确状态。V1 默认要求 SSL/HTTPS 验证成功后才标记 `active`，不自动开放临时 HTTP；管理员未来若要提供例外必须新增显式策略和审计。签发方式、传播等待与重试窗口仍需真实环境联调。

不得为了重试删除或替换其他站点证书。

### 3.7 最终验证

- FQDN 唯一且解析符合策略。
- 宝塔站点 ID、绑定域名和路径与任务证据一致。
- HTTPS 可用，最终响应来自预期实例；健康标记不包含敏感信息。
- 页面/本地配置在断开 LandingOps 依赖时仍能运行。
- 后台开关符合请求；模板事件与链接配置通过静态/功能检查。
- 完成后将预留 `committed`、配置 `applied`、实例 `active`、任务 `succeeded`。

## 4. 批量部署

- API 先做逐行校验和批内 FQDN 去重，返回明确错误行；确认后每个有效行创建一个实例和一个单站任务。
- 可限制单批行数和每团队并发；worker 公平调度并尊重 Cloudflare/宝塔限额。
- 批次仅聚合状态，不持有共享外部事务。某一行成功不会因另一行失败被回滚。
- 重试选择失败/需处理的子任务；成功任务不会重跑。
- 如果同批两行竞争同一 FQDN，数据库唯一预留保证最多一行成功持有。

## 5. 幂等、超时与重试

每个写步骤的幂等键至少包含 `instance_id + operation + step_key + intended_digest`。处理规则：

- 调用前记录 `running` 和预期内容摘要；调用后保存外部 ID、真实状态和是否本步骤创建。
- 网络超时属于未知结果。下一次先按 FQDN/外部 ID/路径查询，再决定采纳、重试或人工处理。
- 429、暂时性 5xx、DNS 传播等待采用指数退避加随机抖动并尊重 `Retry-After`。
- 鉴权失败、权限不足、业务冲突和 schema 错误不自动重试。
- 达到次数/时间上限转 `needs_attention` 或 `failed`，不无限循环。
- worker 用任务租约和心跳防止并发执行；租约过期后恢复者仍需先核验外部状态。

## 6. 失败补偿与取消

补偿采用反向步骤，但只处理有强所有权证据的资源：

| 资源 | 可自动补偿的必要条件 | 不满足时 |
| --- | --- | --- |
| 新建 DNS | 记录 ID、类型、内容仍与本步骤创建时一致，且 `created_by_this_step=true` | 保留并转人工，绝不按名称盲删 |
| 新建宝塔站点 | 外部 ID/域名/路径匹配，目录未被确认接管或产生未知修改 | 保留并转人工 |
| 暂存目录 | 规范化路径在本任务私有暂存根下 | 保留并告警，不递归操作未知路径 |
| 已发布目录 | 仅新实例、所有权匹配、未激活或经显式高风险确认 | 默认保留供诊断 |
| 域名预留 | 没有活动外部资源，或补偿已完成且无未知结果 | 续租并阻止复用，等待人工 |

取消是协作请求：正在执行的不可中断外部调用先完成核验，再补偿。任何部分补偿都显示 `partial` 和人工操作清单。

## 7. 配置修改与恢复

### 7.1 LandingOps 发起

1. 读取数据库记录及站点本地 `revision/hash`，二者不一致先协调。
2. 校验操作者、模板 schema、URL/事件语义和 `expected_revision`。
3. 生成新 canonical 文档，在实例锁内再次比较 revision；白名单投影公开配置，构建完整 release 并原子切换 `current`。
4. 读取确认新 revision、私有 hash、公开 hash、release ID 和健康状态后更新数据库为 `applied` 并写审计。
5. 若数据库提交失败，本地版本保持；后续以本地事实创建 `reconciled` 记录，不回滚站点到旧值。

### 7.2 原生后台发起

原生后台走相同 `publish_config` 库、实例锁、schema、公开字段投影和 release 切换，更新 `updated_by.type=native_admin`。LandingOps 下次读取时发现新 revision，先登记为外部本地变更；有冲突则要求用户重新加载。

### 7.3 恢复

- V1 默认保留最近 10 个成功 release，且至少覆盖 30 天；只有同时超出两项且未被引用才清理。数据库配置版本默认保留 180 天，真实磁盘与合规要求可调整。
- 恢复同样创建更高 revision，不能把 revision 数字倒退。
- 配置损坏时站点优先读取已知良好副本或展示安全维护页，不查询 LandingOps。

## 8. 备用 HTML 与组件发布

- 备用 HTML 由总管理员上传为不可变版本，进行 MIME/大小/HTML 安全和哈希检查；替换“当前默认”不会静默改写已部署实例。
- 当前本地参考 `references/LandingOps_Backup_Bubble_Page.html` 只用于 Phase 1A 引用，未发生生产导入。
- 供应商组件独立版本化、私有存放、扫描凭据和 PHP 兼容性；实例构建时显式选择兼容版本。
- 不实现按审核/检测人员身份显示不同内容，也不生成随机隐藏路径。

## 9. 最小文件权限隔离

| 主体 | 允许 | 明确禁止 |
| --- | --- | --- |
| 控制面 PHP-FPM 用户 | 读取应用代码/自身配置；写会话、缓存和上传 intake；通过数据库提交任务 | 写实例 release/current；执行上传文件；遍历站点 private |
| CLI worker 用户 | 读取只读模板库；读取必要的加密凭据；按任务写目标实例 staging/release/current | 修改控制面代码；把凭据写入站点；任意写非目标实例 |
| 上传 intake | Web root 外、noexec；基准 `0700/0600`，命名 ACL 仅给 Web `write+traverse`、worker `read/write/traverse` | Web 列举/执行上传；由 Nginx/PHP 提供访问；直接 include |
| 单站 PHP-FPM 用户 | 读本站 active release；启用后台时通过本站发布库创建本站 staging release并切换本站 current | 原位修改 active release；读取平台配置、模板库、其他实例/团队目录或第三方 Token |
| 静态站点 Nginx | 只读本站 current 公开文件 | 暴露 private、dot-path、上传/模板目录 |

每个含 PHP 的实例必须使用独立 Unix 用户/FPM pool和实例级 ACL，并把 `open_basedir` 限定在本站 releases/staging/tmp。本站用户只可创建自己的 staging 和切换自己的 current；active release 只读。`open_basedir` 只是纵深防御，不能替代 POSIX 所有权和目录遍历权限。如果宝塔真实环境无法提供等价隔离，则只允许不执行服务器端模板代码的静态站点，原生后台和供应商 PHP 组件保持禁用。

Phase 1E 默认把模板库存放在项目 `var/private/templates`，生产应通过 `LANDINGOPS_TEMPLATE_STORAGE_DIR` 指向 Web Root 外的专用绝对路径，并在文件系统挂载层设置 `noexec`。Nginx 不得为该目录配置 location 或 alias；PHP 只读 ZIP 进行验证，不从模板库执行任何 PHP 文件。

## 10. 站点独立性测试设计

在隔离测试环境完成一次部署后：

1. 记录站点首页、资源、目标行为和可选后台的基线结果。
2. 停止 LandingOps Web、worker，并阻断实例到 LandingOps DB/API 的网络路径。
3. 清空浏览器缓存后，通过正式 FQDN 请求页面、静态资源和站点自身 PHP 功能。
4. 若后台启用，登录并修改当前页面配置，确认 revision 增长、页面反映新值。
5. 恢复 LandingOps，确认其识别本地新 revision 而不覆盖。

通过标准：站点请求链路没有 LandingOps 调用；前台及启用的原生后台继续工作。第三方 DNS/Nginx/PHP 自身故障不属于此保证。

## 11. 生产联调前检查表

- [ ] 宝塔版本、API 白名单、鉴权与各操作能力已通过只读/沙箱验证。
- [ ] Cloudflare Token 按 Zone 最小权限创建，分页、限流和错误码已验证。
- [ ] 本机 IPv4/IPv6、代理状态、TTL、通配符与证书策略已由管理员确认。
- [ ] 站点根父目录、服务账号、PHP 版本、文件权限和 Nginx 保护规则已确认。
- [ ] 宝塔默认根目录文件已形成目标版本专用的文件名+SHA-256 allowlist；symlink/root include 原子切换已在同文件系统验证。
- [ ] MySQL 版本、事务隔离、worker 领取语法与备份恢复已测试。
- [ ] 使用测试 Zone/测试站点完成端到端演练，未接触现有生产站点。
- [ ] 日志脱敏、密钥轮换、审计、失败补偿和人工处置手册通过验收。

