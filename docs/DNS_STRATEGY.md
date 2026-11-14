# LandingOps V1 DNS 策略

## 默认策略

V1 把一个已验证 Cloudflare Zone 主要关联到唯一宝塔网站服务器。服务器地址只读取管理员明确配置的 `LANDINGOPS_CONFIRMED_PUBLIC_IPV4`，不得用面板地址或公网递归解析结果猜测。LandingOps 默认复用已经存在、内容匹配的 Cloudflare 通配符 A 记录，不为每个实例创建精确 A 记录，也不修改、删除或替换通配符。

`reuse_existing_wildcard` 仅在下列证据同时成立时出现：连接和 Zone 已启用；Zone 验证绑定当前凭据上下文；候选 FQDN 在 Zone 内；精确名称没有记录；实际适用的通配符名称存在且只有一个 A 记录；记录 content 等于管理员确认 IPv4、`proxied=false`、`ttl=1`；候选路径没有 NS 委派；查询完整且观察时间不超过五分钟。

DNS 通配符只替换一个标签。因此 `offer.pages.example.com` 的直接适用名称是 `*.pages.example.com`，不能仅凭 `*.example.com` 存在就判断覆盖。精确 A/AAAA/CNAME/NS 或其他类型会影响通配符，相关委派、代理状态缺失、复杂 CNAME、证据过期和查询不完整均采用 fail-closed 结果。

## 分类

- `wildcard_covered`：完整证据允许推荐复用，DNS 新增/修改/删除均为 0。
- `matched_server`：Zone 自动筛选发现根级通配符明确匹配当前服务器；这仍不是任意候选 FQDN 的最终复用证明。
- `other_server`：通配符 A 明确指向其他 IPv4。
- `conflict`：存在精确覆盖、委派或不兼容类型。
- `needs_attention`：缺少确认 IP、通配符、代理/TTL 策略未通过或存在复杂目标。
- `unsupported`：证据不完整、过期、权限不足或超出查询预算。

DNS 匹配从不证明 LandingOps 拥有宝塔网站。正式执行前必须重新读取 Cloudflare 和完整宝塔站点绑定；本阶段没有 DNS 写接口。

实现依据：Cloudflare 官方 [List Zones](https://developers.cloudflare.com/api/resources/zones/methods/list/)（`Zone Read`、单页最多 50）与 [List DNS Records](https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/list/)（`DNS Read`、`name.exact`）契约。

## Zone 自动发现

总管理员可按已登记连接发起只读 Zone List。每次最多筛选前 20 个 Zone 的根级通配符，返回推荐状态但不自动登记 Zone、不创建主域名、不授予团队。Token 没有 Zone List 权限时返回 `manual_registration_required`，保留现有手动登记与逐 Zone 验证流程，不要求扩大 Token 权限。
