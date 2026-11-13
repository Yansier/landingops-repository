# LandingOps Phase 1C：数据库、身份与权限

## 1. 实现范围

Phase 1C 在既有 Phase 1B 离线模板工具旁新增管理控制面基础：

- 面向 MySQL 8，并经 Phase 1C.1 验证兼容 MariaDB 10.6+ 的版本化迁移；
- PDO 仓储、JSON REST API、统一响应/错误与 Request ID；
- `super_admin`、`team_admin`、`staff` 三级身份与集中式资源授权；
- 服务端 Cookie Session、密码哈希、CSRF、账号停用和 Session 撤销；
- 团队/用户基础管理、权限范围内的实例查询、负责人转交服务；
- 交互式首个总管理员初始化与自动化测试。

本阶段没有部署 Worker、真实宝塔/Cloudflare 连接、DNS/SSL 操作、Vue 界面或生产站点写入。已部署站点仍只读取自身 release 文件，访客请求不经过 LandingOps API 或数据库。

## 2. 运行要求与环境变量

- PHP `8.1+`，扩展 `json`、`pdo`、`pdo_mysql`、`zip`；
- MySQL `8.x` 或 MariaDB `10.6+`；当前宝塔实测为 MariaDB `10.11.10-MariaDB-log`；
- Composer 2。

复制 `.env.example` 仅用于了解变量名。应用不会自动加载 `.env`，应由进程管理器或当前终端注入：

```powershell
$env:LANDINGOPS_DB_DSN = 'mysql:host=127.0.0.1;port=3306;dbname=landingops;charset=utf8mb4'
$env:LANDINGOPS_DB_USER = 'landingops_app'
$env:LANDINGOPS_DB_PASSWORD = '<runtime-secret>'
```

不要把真实密码写入仓库、命令历史、迁移或测试夹具。应用账号应只获得 LandingOps 数据库所需权限；建库与账号授权由运维在仓库外完成。

## 3. 安装、迁移与初始化

```text
composer install
php bin/landingops-control migrate
php bin/landingops-control bootstrap-admin admin@example.com "Platform Admin"
```

`bootstrap-admin` 先执行迁移，再通过终端隐藏输入读取两次密码；不接受命令行密码参数。CLI 以 MySQL 命名锁串行化初始化，用户插入和审计在同一数据库事务内，失败时回滚，不留下半完成账号。已有总管理员时拒绝再次初始化；进程崩溃后命名锁随数据库连接释放。生产必须通过 HTTPS 暴露 `public/index.php`，且 Web Server 不得把项目根目录、迁移、私有配置或上传暂存目录设为 Web Root。

## 4. 身份与授权规则

- 总管理员：跨团队管理团队、账号和资源。
- 团队管理员：查询本团队资源，只能创建、停用本团队 `staff`。
- 员工：实例查询自动限定为“本团队且当前负责人为本人”。客户端提交的团队、角色或资源 ID 不能扩大范围。
- 实例创建人字段不可由转交修改；只有总管理员或实例所属团队管理员可转交，目标必须是同团队启用的团队管理员/员工。
- 模板版本与主域名授权检查位于 `AuthorizationService` / `ResourceAccessService`，Controller 不自行拼装权限规则。
- 模板归属、上传者和 grant 均由仓储读取。团队管理员上传后首次建立本团队默认 grant；总管理员撤销后，模板所有权不能恢复或绕过授权，只有总管理员可再次授权。撤销只影响新实例选用，不影响已部署实例。
- 原生 PHP 页面后台密码与 Session 不属于 LandingOps 身份体系，也不存入本控制面用户表。

跨团队资源在多数读取/管理场景返回 404，减少 ID 枚举；真正拒绝原因只进入安全审计。停用用户会撤销全部 Session，且每次认证还会重新读取用户状态，因此遗留 Cookie 不能继续使用。

## 5. Session 与 API 安全

- 密码优先使用 Argon2id，运行环境不支持时使用 PHP `PASSWORD_DEFAULT`；最短 12 字符。
- Cookie 中只包含 256-bit 随机 Token；数据库仅保存 SHA-256 摘要。
- Cookie 属性：`Path=/api/v1; HttpOnly; Secure; SameSite=Strict`，默认有效期 8 小时。
- 登录响应返回独立 CSRF Token；所有已认证写请求须放入 `X-CSRF-Token`。
- 登录成功/失败与登出写入脱敏安全审计；失败事件只保存规范化标识符摘要。改密、停用、登出会撤销相应 Session。API 与审计响应不返回密码、密码哈希、Cookie Token 或第三方密钥，审计元数据会递归剔除敏感键。
- 响应带 `X-Request-Id` 和 `Cache-Control: no-store`；错误不暴露堆栈、路径、SQL 或底层异常。

已实现接口清单见 `docs/API_CONTRACT.md` 第 11 节。未来资源前缀在本阶段返回 `501 not_implemented`，不会伪造成功结果。

## 6. 测试

通用离线与服务层测试：

```text
composer test
```

真实 MySQL 集成测试只可指向专用数据库 `landingops_test`：

```powershell
$env:LANDINGOPS_TEST_MYSQL_DSN = 'mysql:host=127.0.0.1;port=3306;dbname=landingops_test;charset=utf8mb4'
$env:LANDINGOPS_TEST_MYSQL_USER = 'landingops_test'
$env:LANDINGOPS_TEST_MYSQL_PASSWORD = '<runtime-secret>'
composer test:mysql
```

Phase 1C.1 将数据库名严格限制为 `landingops_test`，连接后通过 `SELECT DATABASE()` 二次确认，并检查 `SHOW GRANTS FOR CURRENT_USER()`：除全局 `USAGE` 外，只接受 `landingops_test.*` 权限。写入测试夹具前还会确认 22 张业务表均为空；发现其他库显式权限或已有业务数据立即停止。远程主机还必须显式设置 `LANDINGOPS_TEST_MYSQL_ALLOW_REMOTE=I_UNDERSTAND`；推荐始终通过本机 SSH 隧道连接 `127.0.0.1`，不开放公网 3306。脚本验证 22 张业务表、重复迁移、失败迁移恢复、FK/CHECK/UNIQUE、真实 PDO Session、负责人事务/乐观锁、模板撤销/再次授权及单宝塔约束。测试数据使用事务回滚；故障注入表和迁移记录在 `finally` 中清理。

Windows 项目本地 PHP 与 SSH 隧道流程：

```powershell
# 可选：让当前 PowerShell 会话中的 php 指向项目隔离版本，并检查扩展
. .\scripts\Enter-ProjectPhp.ps1

# 窗口 1；只把本机 13306 转发到服务器回环地址 3306
.\scripts\Open-MySqlTestTunnel.ps1 -SshHost '<host>' -SshUser '<user>' -IdentityFile '<private-key>'

# 窗口 2；密码以 SecureString 交互输入，不写文件或命令行
.\scripts\Invoke-MySqlIntegration.ps1 -DatabaseUser '<landingops_test-user>'

# 无稳定终端窗口时，可改用 Windows 原生凭据弹窗
.\scripts\Open-MySqlTestTunnelSecure.ps1 -SshHost '<host>' -SshUser '<user>'
.\scripts\Invoke-MySqlIntegrationSecure.ps1
```

项目私有 PHP 配置位于被 Git 忽略的 `.tools/php/php.ini`。数据库密码不写入 `.env`、代码或日志；测试脚本结束后清除临时环境变量。内存仓储只用于服务契约测试，不模拟 MySQL，也不能代替真实 MySQL 验证。

推荐的完整检查：

```text
composer validate --strict
composer test
composer test:mysql
php bin/landingops-control --help
```

另需对 `src/`、`bin/`、`public/` 和 `tests/` 下所有 PHP 文件执行 `php -l`。

## 7. Phase 1C.1 真实数据库验收结果

2026-11-13 通过 SSH 隧道 `127.0.0.1:13306 → SSH → 127.0.0.1:3306` 完成测试，SSH ED25519 指纹在认证前与操作者提供的完整 SHA-256 指纹一致。未开放公网 3306，也未修改防火墙、MySQL 监听、宝塔站点、DNS 或其他数据库。

- 实际引擎：MariaDB `10.11.10-MariaDB-log`。
- 当前数据库：`landingops_test`；使用仅授权该测试库的专用账号，实际账号标识只保留在被 Git 忽略的本地测试日志中。
- `SHOW GRANTS` 检查通过：除全局 `USAGE` 外仅有 `landingops_test.*` 权限。
- 首次发现 `utf8mb4_0900_ai_ci` 为 Oracle MySQL 8 专属，MariaDB 返回 1273；迁移改为双方支持的 `utf8mb4_unicode_ci`。
- 随后发现 MariaDB 不接受字符串状态生成列，返回 1901；`active_instance_id` / `active_fqdn` 改为显式可空键，并以 `CHECK + UNIQUE` 保持相同约束。
- 最终通过：22 张业务表、迁移重复执行、故障注入后的安全恢复、FK/CHECK/UNIQUE、真实 PDO 登录/Session/CSRF/登出、模板授权撤销与再次授权、负责人事务与乐观锁、单宝塔配置唯一约束。
- 验收补充迁移 002 保持已执行的 001 不变；执行前确认两张活动键表历史异常记录为 0。MariaDB 实测拒绝 active release 的 NULL/错配键、同实例第二个 active release、held/committed 的 NULL 键及同 FQDN 第二个活动预留；staged、released、expired 的 NULL 键合法。重复迁移未改变结构或数据。
- 测试业务数据已回滚，故障注入表和临时迁移记录已清理；测试库保留正式 schema 与 `schema_migrations` 记录。SSH 隧道在测试后关闭。

## 8. 当前遗留边界

- 2026-11-13 本地已配置项目隔离 PHP `8.5.12`，并实测加载 `json`、`PDO`、`pdo_mysql`、`zip`、`openssl`、`mbstring`；系统 PATH 仍不做全局修改。
- Oracle MySQL 8 仍是正式目标之一，但本轮真实环境为 MariaDB 10.11.10；后续生产上线前仍应在最终选定引擎版本上重复执行同一套验收。
- 列表 API 暂返回非分页结果；游标分页、限流和会话清理任务留待后续阶段。
- 模板、域名、实例创建、部署任务等 V1 表已预留，但 Phase 1C 不开放其业务写 API。
- 数据库中的第三方凭据字段只定义密文容器；密钥管理和真实适配器未实现，也未写入任何真实凭据。
