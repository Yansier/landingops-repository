# Phase 1B / 1B.1 / 1B.2：离线模板验证、实例化与恢复

本阶段只实现 LTS v1.0 的离线能力，不连接宝塔、Cloudflare、DNS、SSL、MySQL 或生产站点。

## 环境要求

- PHP `>=8.1 <9.0`
- `json` 与 `zip` 扩展
- Composer 可选；代码不依赖第三方包

检查扩展：

```bash
php -m
```

如 PHP 的 ZIP 扩展未默认启用，可在本地 PHP 配置中启用，或在命令中使用对应环境的 `-d extension=zip`。

## 可复现命令

安装 Composer autoload（可选）：

```bash
composer dump-autoload
```

运行全部测试：

```bash
composer test
```

没有 Composer 时：

```bash
php -d extension=zip tests/run.php
```

生成标准示例 ZIP：

```bash
php -d extension=zip bin/landingops package-sample build/minimal-lts-v1.zip
```

验证模板：

```bash
php -d extension=zip bin/landingops validate build/minimal-lts-v1.zip
```

执行完全离线的实例构建：

```bash
php -d extension=zip bin/landingops build \
  build/minimal-lts-v1.zip \
  examples/configs/minimal-instance.json \
  build/offline-instance
```

Windows PowerShell 可将续行改为反引号，或把命令写为一行。

## 输出结构

```text
build/offline-instance/
├── .landingops-instance.json
├── .landingops-publications/      # 未完成发布的恢复 journal
├── revision-ledger.json
├── current.release
├── releases/
│   └── r<revision>-<hash12>/
│       ├── public/
│       │   ├── index.html
│       │   ├── assets/app.js
│       │   └── assets/landingops.config.r<revision>.<hash12>.js
│       ├── private/.landingops/config.json
│       └── release-manifest.json
└── staging/
```

`current.release` 是跨平台离线演示使用的原子指针文件。生产环境仍按架构文档使用经验证的 symlink rename 或 Nginx root include 切换；本阶段不实现生产发布器。

## 公开配置可见性

`x-landingops-visibility` 不继承。manifest 的每个 `public_paths` 入口自身必须显式标记为 `public`，其被整体投影的子树还必须满足：

- object 的每个 property 都显式标记为 `public`；未标记或 `private` 的 property 会使模板验证失败。
- array 的 `items` 必须存在并显式标记为 `public`；对象数组继续逐层应用相同规则。
- `$ref` 节点及其 `#/$defs/...` 目标都必须显式标记为 `public`，嵌套引用逐层检查，循环引用拒绝。
- `oneOf` 容器及每个分支都必须显式标记为 `public`。
- 如 `custom.headline` 只公开叶子路径，未列入路径的同级字段不会因父对象存在而公开。

`SchemaContractValidator` 负责拒绝不完整的公开声明；`ConfigProjector` 仍会按 schema 逐字段重建输出，防止调用方绕过验证时把 private 或未标记字段深拷贝到 JavaScript。

## JSON Schema 循环引用

循环检测独立于公开配置检查。`SchemaContractValidator` 会为根 `$defs` 中的所有定义建立完整引用图，并检查 properties、array items、`oneOf` 和嵌套 `$defs` 中出现的引用：

- 直接自引用与任意长度的间接循环均以 `schema_ref_cycle` 拒绝。
- 未被公开路径使用、未被根配置引用或标记为 `private` 的定义也必须无环，不能借可见性绕过。
- 合法的有向无环引用链允许共享定义，不会被误判。

`ConfigValidator` 另有运行时防线：沿当前验证路径记录 `$ref` 栈，重复进入同一引用时返回 `config_schema_cycle`；所有 schema/value 递归最多 128 层，超过时返回 `config_recursion_limit`。因此即使调用方绕过模板 Schema 校验，也不会因异常引用或极深输入无限递归。

## 稳定的 release 资源 URL

实例化后的 HTML 不再依赖随 active release 变化的 `/assets/...`。所有本地 `src` 和 `href` 都改写为：

```text
/__landingops/releases/<release-id>/<template-relative-path>
```

release ID 包含单调 revision 和公开配置哈希前缀。该 URL 映射到 `releases/<release-id>/public/`，旧 release 保持不可变并继续保留，因此用户缓存中的 release A HTML 在指针切换到 B 后，仍按原 URL 读取 A 的 CSS、JavaScript 和公开配置。`OfflinePublicRouter` 在测试中模拟此文件路由，同时提供 `/` 与 `/index.html` 到当前指针的解析；不依赖宝塔或 Nginx。

## ZIP 验证与解压一致性

验证器在读取中央目录前，将源 ZIP 复制到权限为 `0700` 的随机私有暂存目录，并记录源文件复制前后的文件身份、快照文件身份及 SHA-256。manifest/schema 读取和安全解压都只使用该私有快照；每次读取以及解压前后都会复核快照身份和 SHA-256。

因此，即使原上传路径在验证后被替换，实例化也只会解压已验证内容。快照随验证对象生命周期清理，既定的 30 MiB 压缩包、100 MiB 解压总量、2,000 条目和单文件限制不变。

## 离线发布恢复

每个发布 revision 使用 `.landingops-publications/revision-<n>.json` 原子 journal，记录 `allocated`、`staging_ready`、`release_committed` 和 `pointer_activated` 阶段。`OfflineInstanceBuilder::recover()` 采用以下确定性策略：

- 只有 staging、尚未提交 release：删除私有 staging，revision 标记为 `failed`。
- release 已提交、指针尚未切换：向前完成指针切换及 ledger 激活。
- 指针已切换、ledger 尚未完成：以不可变 release 和指针为准补齐 ledger。

恢复会先汇总 current pointer、ledger active 状态以及所有遗留 journal 对应的完整 release，只选择 revision 最大的有效候选完成激活；较旧候选标记为 `superseded`，不能覆盖较新的有效版本。恢复后 `active_revision`、`active_release_id` 与 `current.release` 一致；任何已分配 revision 都不会回收或重复使用。测试通过三个显式故障注入点模拟进程中断。

## 实例级发布锁

每个实例按规范化绝对路径计算独立锁键，并在实例父目录创建 `.landingops-instance-<hash24>.lock`。`InstanceLock` 使用操作系统 `flock(LOCK_EX)`，覆盖以下完整临界区：

- ownership marker 与实例目录初始化；
- 遗留 journal 恢复；
- revision 分配、staging 生成和不可变 release 提交；
- `current.release` 切换、ledger 激活和 journal 清理。

同一 PHP 进程对同一实例再次进入锁时复用已持有锁并记录嵌套深度，不会自锁；不同实例使用不同锁键，可以独立执行。ledger 的细粒度文件锁继续保留作为原子写保护，但所有 ledger 变更、journal 变更和指针激活都会断言实例锁已经持有。进程异常退出时，操作系统释放文件锁；锁文件可以保留并由后续恢复安全复用。

自动化测试使用独立 PHP 子进程验证同实例构建与恢复会等待、不同实例不会互相阻塞、锁重入无死锁、崩溃退出后可恢复，以及多个遗留 journal 不导致版本回退。本阶段完整测试为 38 项。

## 安全行为

- 验证 ZIP 压缩/解压大小、文件数量、单文件大小及异常压缩比。
- 拒绝绝对路径、盘符、反斜杠、`..`、NUL、大小写冲突、重复路径、保留设备名、平台歧义路径、加密条目和 Unix 特殊文件。Phase 1B 为保证跨平台确定性，ZIP 路径暂限可打印 ASCII；文件内容仍完整支持 UTF-8。
- 拒绝 `.env`、服务器覆盖文件、私钥扩展、二进制脚本及内嵌供应商 `index.php/core.php`。
- 不使用 `ZipArchive::extractTo()`；逐条目读取已验证私有快照并再次执行运行时大小限制。
- manifest、受限 JSON Schema、能力声明、后台入口、HTML 占位符和相对资源必须一致。
- 公开配置只来自 `public_paths`，且所有实际投影节点都必须显式标记 `x-landingops-visibility=public`。
- 每次发布尝试先分配单调 revision；失败 revision 标记为 failed，后续不会复用。
- release 一旦提交不原位修改；切换后旧 release 的 HTML 和版本化资源 URL 仍然可解析。
- 同一实例的恢复、构建、指针和 ledger 变更必须持有同一个实例级发布锁。

## 当前边界

- 离线构建器固定关闭原生后台，不组装供应商组件。
- 不实现 Linux ACL/FPM、Nginx、宝塔根目录接管或真实 symlink 发布；这些仍属于真实环境联调范围。
- 不实现数据库、队列、团队权限、API 服务或 Vue 管理界面。
- 离线路由器只定义并验证文件映射契约，不启动独立预览服务或生产 HTTP 服务。
