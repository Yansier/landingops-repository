# LandingOps

LandingOps 是面向落地页的轻量部署与配置发布控制面。V1 运行在**一台现有宝塔服务器**上，通过唯一宝塔连接管理由 LandingOps 创建的站点，并通过多个 Cloudflare 连接管理获授权的 Zone 和主域名。

> 当前状态：**Phase 1B（模板验证与离线实例化）**。仓库已包含可运行的 PHP 离线验证器、配置投影、release 构建演示和自动化测试；仍不包含 Vue/PHP 业务 API、数据库迁移、生产发布或真实第三方 API 调用。

## 最重要的边界

- LandingOps 是部署与配置发布工具，不是网站运行时网关。
- 部署完成后，访客直接访问宝塔 Nginx/PHP 托管的站点；站点不得在请求期间查询 LandingOps API 或数据库。
- LandingOps 停机或数据库不可用，不影响已部署站点读取自身文件和本地配置。
- 只管理 LandingOps 创建且已登记的实例。既有宝塔站点仅参与冲突检测，绝不自动接管、覆盖、改写或删除。
- V1 固定单台宝塔；Cloudflare 可有多个账号/连接，各 Zone 仍解析到同一台服务器。
- 不提供模板截图、效果图、缩略图或独立预览服务。

## Phase 1A 文档

| 文档 | 内容 |
| --- | --- |
| [PRD](docs/PRD.md) | V1 范围、权限矩阵、用户流程、排除项 |
| [架构](docs/ARCHITECTURE.md) | 控制面/运行面、任务模型、故障隔离与安全边界 |
| [模板标准](docs/TEMPLATE_STANDARD.md) | LTS v1.0、ZIP 结构、配置与原生后台契约 |
| [数据库](docs/DATABASE.md) | ER 图、字段、索引、约束和密钥存储要求 |
| [部署流程](docs/DEPLOYMENT.md) | 单站/批量、DNS 保护、SSL、重试补偿与配置发布 |
| [验收标准](docs/ACCEPTANCE.md) | 可复现的功能、异常与独立性验收用例 |
| [架构决策](docs/DECISIONS.md) | 已定 ADR、待联调项和非阻塞待确认问题 |
| [API 契约](docs/API_CONTRACT.md) | 控制面接口、幂等、并发与错误语义 |
| [Phase 1B 本地运行](docs/PHASE_1B.md) | 离线验证、示例打包、实例构建与测试命令 |

纯契约示例位于 `docs/examples/`，不能作为生产配置或真实凭据使用。

## 参考素材

`references/LandingOps_Backup_Bubble_Page.html` 是当前备用 HTML 的参考原件，不是模板截图，也不是平台业务页面。当前文件：

- 标题：`Éclate-bulles`
- 大小：13,088 bytes
- SHA-256：`7562582EC704307B339907CA6B149340845966744EBF698B3B8FDBE0E8114352`

Phase 1B 实现时应将“备用页面版本”作为受审计的管理员资产管理；替换后创建新版本，不能静默改写历史部署所引用的版本。本仓库不把该参考文件复制成模板或站点运行文件。

## 当前代码结构

```text
src/                   # 无第三方依赖的 PHP 离线核心
bin/landingops         # validate / package-sample / build CLI
examples/              # 最小 LTS v1.0 模板和实例配置
tests/                 # ZIP、契约、投影和 release 自动化测试
```

运行方式和输出结构见 [Phase 1B 本地运行说明](docs/PHASE_1B.md)。

## 安全提示

- 不提交宝塔 API Key、Cloudflare Token、数据库密码、原生后台密码或供应商组件中的密钥。
- 文档中的域名、ID、URL 和哈希均为示例值或本地参考文件信息。
- 在真实联调前，必须确认宝塔版本及 API 能力、Cloudflare Token 权限、服务器公网地址、Nginx/PHP 环境和证书策略。

