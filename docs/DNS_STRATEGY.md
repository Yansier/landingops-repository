# LandingOps V1 DNS 策略

## 默认策略

V1 把一个已验证 Cloudflare Zone 主要关联到唯一宝塔网站服务器。服务器地址只读取管理员明确配置的 `LANDINGOPS_CONFIRMED_PUBLIC_IPV4`，不得用面板地址或公网递归解析结果猜测。LandingOps 默认复用已经存在、内容匹配的 Cloudflare 通配符 A 记录，不为每个实例创建精确 A 记录，也不修改、删除或替换通配符。

`reuse_existing_wildcard` 仅在下列证据同时成立时出现：连接和 Zone 已启用；Zone 验证绑定当前凭据上下文；候选 FQDN 在 Zone 内；精确名称没有记录；从最具体父级到 Zone 根逐级检查后得到的实际适用通配符存在且只有一个 A 记录；记录 content 等于管理员确认 IPv4、`proxied=false`、`ttl=1`；路径不存在具体 DNS 节点或 NS 委派造成的遮蔽；查询完整且观察时间不超过五分钟。

Cloudflare 的通配符记录语法只允许最左侧标签为 `*`，但标准名称服务器默认支持多层匹配：没有更具体节点遮蔽时，`*.example.com` 可覆盖 `a.example.com` 和 `b.a.example.com`。因此检查 `offer.pages.example.com` 时必须依次考虑 `*.pages.example.com`、`*.example.com`，不能因前者不存在就忽略后者；一旦发现更具体通配符，就不得因其目标不兼容而退回较宽通配符。

候选名称到通配符基点之间的任一具体节点记录（包括 TXT、A、AAAA、CNAME 等）会遮蔽较宽通配符；NS 表示委派并直接阻断。对没有自身记录但存在后代记录的 empty non-terminal，标准名称服务器仍可应用较宽通配符，Advanced Nameservers 按 RFC 4592 行为阻断；无法从 Zone 元数据确认模式时返回 `unsupported`。所有祖先精确查询及后缀查询必须完整，超页、权限不足、模式未知、证据过期或字段缺失均 fail closed。

## 分类

- `wildcard_covered`：完整证据允许推荐复用，DNS 新增/修改/删除均为 0。
- `matched_server`：Zone 自动筛选发现根级通配符明确匹配当前服务器；这仍不是任意候选 FQDN 的最终复用证明。
- `other_server`：通配符 A 明确指向其他 IPv4。
- `conflict`：存在精确覆盖、委派或不兼容类型。
- `needs_attention`：缺少确认 IP、通配符、代理/TTL 策略未通过或存在复杂目标。
- `unsupported`：证据不完整、过期、权限不足或超出查询预算。

DNS 匹配从不证明 LandingOps 拥有宝塔网站。正式执行前必须重新读取 Cloudflare 和完整宝塔站点绑定；本阶段没有 DNS 写接口。

实现依据：Cloudflare 官方 [Wildcard DNS records](https://developers.cloudflare.com/dns/manage-dns-records/reference/wildcard-dns-records/)（多层匹配、具体记录优先、empty non-terminal 与 Advanced Nameservers 差异）、[List Zones](https://developers.cloudflare.com/api/resources/zones/methods/list/)（Zone 元数据）与 [List DNS Records](https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/list/)（`DNS Read`、`name.exact`/`name.endswith`）契约。

## Zone 自动发现

总管理员可按已登记连接发起只读 Zone List。每次最多筛选前 20 个 Zone 的根级通配符，返回推荐状态但不自动登记 Zone、不创建主域名、不授予团队。Token 没有 Zone List 权限时返回 `manual_registration_required`，保留现有手动登记与逐 Zone 验证流程，不要求扩大 Token 权限。
