# Phase 1B：离线模板验证与实例化

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

## 安全行为

- 验证 ZIP 压缩/解压大小、文件数量、单文件大小及异常压缩比。
- 拒绝绝对路径、盘符、反斜杠、`..`、NUL、大小写冲突、重复路径、保留设备名、平台歧义路径、加密条目和 Unix 特殊文件。Phase 1B 为保证跨平台确定性，ZIP 路径暂限可打印 ASCII；文件内容仍完整支持 UTF-8。
- 拒绝 `.env`、服务器覆盖文件、私钥扩展、二进制脚本及内嵌供应商 `index.php/core.php`。
- 不使用 `ZipArchive::extractTo()`；逐条目读取并再次执行运行时大小限制。
- manifest、受限 JSON Schema、能力声明、后台入口、HTML 占位符和相对资源必须一致。
- 公开配置只来自 `public_paths` 且 schema 必须标记 `x-landingops-visibility=public`。
- 每次发布尝试先分配单调 revision；失败 revision 标记为 failed，后续不会复用。
- release 一旦提交不原位修改；切换后旧 release 的 HTML 和内容寻址资源仍然保留。

## 当前边界

- 离线构建器固定关闭原生后台，不组装供应商组件。
- 不实现 Linux ACL/FPM、Nginx、宝塔根目录接管或真实 symlink 发布；这些仍属于真实环境联调范围。
- 不实现数据库、队列、团队权限、API 服务或 Vue 管理界面。
