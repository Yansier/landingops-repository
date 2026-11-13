# LandingOps Phase 1D：Vue 3 管理后台第一版

## 1. 实现范围

Phase 1D 在既有 PHP 控制面上新增 `frontend/` 单页管理后台，技术栈为 Vue 3、Vite、TypeScript、Vue Router、Pinia和自有轻量 CSS 组件。界面优先适配桌面，并在窄屏下提供抽屉导航、横向表格和单列表单。

已开放页面：

- 登录：邮箱/密码、失败提示、Cookie Session、状态恢复与退出；
- 仪表盘：仅以当前账号可查询的列表计算“当前授权范围”统计，不生成模拟业务数据；
- 团队管理：总管理员查询和创建团队，并以授权用户列表计算成员数量；
- 用户管理：总管理员或团队管理员查询、创建、停用权限范围内用户；
- 网站实例：三级角色分别查询各自授权范围，管理员可按现有接口转交负责人；
- 个人设置：查看身份、修改显示名称、修改密码和退出登录。

模板管理、域名管理、部署任务和系统设置在侧栏中仅以“即将开放”静态文字标记，不能点击，也不会发送伪造请求。实例创建仍未开放。

## 2. 认证、CSRF 与权限

浏览器通过 Vite 同源代理访问 `/api/v1`，所有请求使用 `credentials: include`。前端不接触 Session Cookie 内容，也不把 CSRF Token 写入任何持久化浏览器存储。

刷新页面时，路由守卫先请求 `GET /api/v1/me`。服务端验证 Cookie Session、轮换该 Session 的 CSRF 哈希，并在响应中返回新的 `csrf_token`；Pinia 只在内存保存它。后续写请求统一附带 `X-CSRF-Token`。401 会清空内存身份并返回登录页；403、404、409、422、501 保留服务端错误码和消息进入统一错误处理。其中实例转交的 409 会刷新列表，要求操作者基于最新版本重新提交。

路由和导航按角色隐藏入口，但它们只改善体验。最终边界仍由 PHP 的集中授权服务执行，客户端提供的团队、负责人或资源 ID 均不作为可信授权依据。

`GET /me` 每次调用都会使上一枚 CSRF Token 失效。同一 Session 若在多个标签页并行使用，旧标签页需要刷新或重新恢复身份；后续可在不降低 CSRF 强度的前提下评估多标签页协调。

## 3. 本地启动

要求 Node.js 22+、npm 10+、PHP 8.1+，以及已迁移的本地/独立测试数据库。数据库凭据仍只通过被 Git 忽略的 `.env` 或当前进程环境提供。

终端一启动 PHP API：

```powershell
.\scripts\Enter-ProjectPhp.ps1
php -S 127.0.0.1:8080 -t public
```

终端二启动前端：

```powershell
cd frontend
npm install
npm run dev
```

使用 Vite 输出的 `http://localhost:5173` 地址访问，不要把 API 数据库凭据放入 `frontend/`。代理目标固定为本机 `http://127.0.0.1:8080`，不会连接宝塔或 Cloudflare。

## 4. 构建与测试

```powershell
cd frontend
npm test
npm run build
npm audit

cd ..
.\.tools\php\php.exe tests\run.php
.\.tools\php\php.exe .\.tools\composer.phar validate --strict
```

前端自动化覆盖登录/登出、刷新后身份及 CSRF 恢复、Session 过期、三级角色导航、统一错误状态、用户创建/停用请求、负责人转交版本字段和空实例列表。PHP 套件继续覆盖跨团队资源伪造、服务端三级授权、账号停用、CSRF 和转交乐观锁。

## 5. 当前边界

- 未实现 Vue 端的模板上传、Cloudflare、DNS、SSL、宝塔设置、实例创建或部署功能；
- 未提供 Mock 业务数据，空库显示正式空状态；
- 未实现生产静态资源发布方案，本阶段只提供可构建的前端源码与本地 Vite 代理；
- 未改变单宝塔、多 Cloudflare 连接和网站运行时完全脱离 LandingOps 的架构；
- 未修改参考 HTML、第三方供应商组件或既有数据库迁移。
