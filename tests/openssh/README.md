# 隔离 OpenSSH 行为测试

`Run-IsolatedOpenSshIntegration.ps1` 只针对本机 Docker 中临时 Ubuntu 24.04 容器。它不会读取部署配置、生产私钥或连接生产服务器。

覆盖范围：

- Ed25519 公钥成功认证及固定 `SSH_ORIGINAL_COMMAND`；
- 交互 shell、命令注入、SCP、SFTP、端口转发和密码认证失败；
- 启用本地 SSH Agent 后，`restrict` 阻止 Agent socket 进入 fixed-command；
- stdin 内容不被执行；
- 审计账号不能修改 wrapper、probe 或 authorized_keys；
- 容器内 sshd 使用 publickey-only、单一 AuthorizedKeysFile 和禁用环境输入的配置。

运行：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass `
  -File .\tests\openssh\Run-IsolatedOpenSshIntegration.ps1
```

运行需要本机 Docker daemon 和可用的 `ubuntu:24.04` 镜像/构建网络。脚本生成的无口令密钥仅存在于随机临时目录和隔离容器中，结束后删除，严禁用作服务器身份。

若 Docker 不可用，结果必须记录为 `SKIP: isolated_linux_openssh_unavailable`，静态 PHP/源码测试不能替代这项真实行为验收。
