# 教学 2 · SSH 私钥配对（手机直接对服务器执行命令）

> 跟电脑上 `ssh root@服务器` 一模一样，只是密钥放在手机 App 里。
> **谁有你的公钥，服务器就认谁** —— 不需要域名、不需要买证书、跟区块链毫无关系。

---

## 4 步配对

| 步 | App 里做什么 | 服务器上发生什么 |
|---|---|---|
| ① | 设置 → **SSH 私钥连接服务器** → 填 **IP:端口**（例 `你的IP:22`）→ 保存 | — |
| ② | 点「**生成 .ssh 私钥**」 | 私钥只存在 App 私有目录，**不上传任何地方**；同时显示指纹 |
| ③ | 点「**复制一键安装命令**」→ 贴到服务器上跑（云控制台网页终端/宝塔终端都行） | 命令把公钥写进 `~/.ssh/authorized_keys`（幂等，跑两次不会重复）|
| ④ | 点「**配对连接**」 | 服务器回 `uptime` 等状态 → App 显示 **配对成功 ✅** |

那条一键命令做的事（你可以自己看，没有黑魔法）：
```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh && \
(grep -q 'linji@phone' ~/.ssh/authorized_keys 2>/dev/null || echo '<你的公钥>' >> ~/.ssh/authorized_keys) && \
chmod 600 ~/.ssh/authorized_keys && echo 装好了
```

---

## ⚠️ 手机在国内、服务器在海外：一定要让 SSH 走 443

裸 IP 的**高端口**（22/2222/16598…）在国内常被丢包，症状是：**能连上但卡住**、
或报 `channel is not opened` / `Session.connect Read timed out`。
**443 端口最"正常"**，把 SSH 也塞进 443 就稳了（**不用域名、不用 Cloudflare**）：

```bash
# 1) 装 nginx 的 stream 模块
apt-get install -y libnginx-mod-stream
# 2) 网站从 443 挪到 8443：站点配置里所有 listen 443 ssl;  →  listen 8443 ssl;
#    并在 nginx.conf 的 http{} 里加： port_in_redirect off;
# 3) nginx.conf 顶层（http{} 外面）加：
```
```nginx
stream {
    map $ssl_preread_protocol $backend {
        ""      127.0.0.1:22;      # 非 TLS（SSH）→ sshd（写你实际的 SSH 端口）
        default 127.0.0.1:8443;    # TLS（https）→ nginx
    }
    server { listen 443; listen [::]:443; proxy_pass $backend; ssl_preread on; }
}
```
```bash
# 4) 生效 + 验证
nginx -t && systemctl reload nginx
curl -I https://你的域名        # 网站照常
ssh -p 443 root@你的IP          # 能到"要密码/公钥"那步 = 通了
```
然后 App 里 **IP 填 `你的IP:443`**。

---

## 排错

| 报错 | 真因 | 怎么办 |
|---|---|---|
| `socket failed: EPERM` | **App 缺 INTERNET 权限**（老版本 bug，v2.34 起已修）| 升级到最新 APK |
| `Session.connect Read timed out` / `channel is not opened` | 裸 IP 高端口被丢包 | 走上面 443 那条路；或换线路/换服务器 |
| `Auth fail` / `Permission denied (publickey)` | 公钥没装上，或权限不对 | 重跑第③步命令；确认 `~/.ssh` 是 **700**、`authorized_keys` 是 **600**、用户写对 |
| 一直"配对中…" | 老版本连上后不等结果 | 升到 v2.35+（有超时与自动重试）|
| 想快速验证 | — | 手机上「复制 ssh 命令」→ Termux 里粘一下，等于手动版 |

---

## 安全提醒

- **公钥可以随便发**（就是给服务器认的）；**私钥绝对不要复制给别人**，也别贴到聊天里
- 服务器上把它当普通密钥对待：想撤权就删 `authorized_keys` 里那行 `linji@phone`
