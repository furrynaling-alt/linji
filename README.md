# 棂记 · 从零部署教程（照着做就行）

这套东西分两半：**手机上的 App**（别人装的）和**服务器**（你要上传几个文件上去）。
服务器不是必须的——纯用 App 也能打卡/锁机；配了服务器才有「检查更新 + 手机↔服务器通道（看睡眠、发待办、远程改设置）」。

---

## 0. 你要准备什么

| 需要 | 说明 |
|---|---|
| 一台服务器 | VPS / 云主机 / 虚拟主机都行；用 **宝塔面板**最省事。没有域名就用 **IP**，一样能用 |
| App 安装包 | 见第 1 步下载 |
| 3 分钟 | 真的够了 |

---

## 1. 下载（二选一）

**A. 服务器直链（国内快，推荐）**
- 安装包：`https://furry.gov.naling.net/linji/linji-2.30.apk`
- 更新清单：`https://furry.gov.naling.net/linji/version.json`

**B. GitHub（项目主页 + Releases）**
- 项目主页：https://github.com/furrynaling-alt/linji
- 下载页：https://github.com/furrynaling-alt/linji/releases/latest
  - `linji-2.30.apk` → 装到手机
  - `linji-deploy.zip` → 服务器要用的一整套文件（就是下面第 2 步要传的那些）

> 手机装 APK 时如果提示「不允许安装未知应用」，去 **设置 → 应用 → 允许安装未知应用** 打开就行。

---

## 2. ⚠️ 你需要把这几个文件上传到服务器（重点）

在服务器上建一个目录，例如 `/www/wwwroot/你的站/linji/`，把下面这些**上传**进去：

| 文件 | 传到哪 | 干什么用的 | 必须吗 |
|---|---|---|---|
| `linji-2.30.apk` | `你的站/linji/` | App 点「检查更新」时下载它 | 想要更新就要 |
| `version.json` | `你的站/linji/` | 更新清单：告诉 App 有没有新版 | 想要更新就要 |
| `bridge.php` | `你的站/linji/` | 手机 ↔ 服务器通道（睡眠/待办/远程改设置） | 可选 |

`version.json` 内容照这个改（**url 和 md5 一定改成你自己的**）：

```json
{
  "versionCode": 40,
  "versionName": "2.30",
  "note": "这一版的说明，App 里会显示这段字",
  "url": "https://你的域名/linji/linji-2.30.apk",
  "md5": "把 apk 的 md5 填这里"
}
```
算 md5：`md5sum linji-2.30.apk`

**上传方式**（任选）：
- 宝塔面板 → 文件 → 进目录 → 上传
- 命令行：
  ```bash
  ssh root@你的服务器IP
  mkdir -p /www/wwwroot/你的站/linji
  # 在你自己电脑上：
  scp linji-2.30.apk version.json bridge.php root@你的服务器IP:/www/wwwroot/你的站/linji/
  ```

---

## 3. nginx 配置

### 方案 A：有域名（HTTPS）

站点根目录指到 `你的站`，然后在站点配置里加：

```nginx
location = /linji/bridge.php {
    proxy_pass http://127.0.0.1:16632;      # 用 Node 版桥才需要这两行
    proxy_set_header Host $host;
    client_max_body_size 512k;
}
location ^~ /linji/ {
    root /www/wwwroot/你的站;
}
```
> 只用 **PHP 版 bridge.php** 的话，上面那段反代**不用加**，PHP 由 php-fpm 正常处理即可。

改完 `nginx -t && systemctl reload nginx`。

### 方案 B：只用 IP（没有域名）

在 **80 端口的默认站点**里加（这样 `http://你的IP/linji/...` 就能用）：

```nginx
server {
    listen 80 default_server;
    server_name _;

    location = /linji/bridge.php {
        proxy_pass http://127.0.0.1:16632;
        proxy_set_header Host $host;
        client_max_body_size 512k;
    }
    location ^~ /linji/ {
        root /www/wwwroot/你的站;
    }
    location / { return 404; }
}
```
> 注意：**https 不能直接用 IP**（证书是签给域名的），所以填 IP 时 App 会用 http。

---

## 4. 配置 bridge.php（想让服务器看到手机才需要）

1. 用编辑器打开 `bridge.php`，改第一行的口令（**用英文/数字**）：
   ```php
   $TOKEN = 'CHANGE-ME';     // 改成你自己的，例如 naling-8899
   ```
2. 保证 `你的站/linji/` **可写**（要建 `bridge-data/` 存数据）：`chmod 755 你的站/linji`
3. 浏览器打开 **控制台**：
   `https://你的域名/linji/bridge.php?admin=你的口令`
   这里能看手机状态/睡眠记录、**加待办**、发通知、锁机、改睡眠设置。
4. 数据文件（`bridge-data/state.json`、`cmds.json`、`acks.json`）别公开，控制台地址别外传。

> 没有 PHP（自己的 VPS，跑 Node）？用包里的 `server.js`：
> ```bash
> mkdir -p /opt/linji-bridge && cp server.js /opt/linji-bridge/
> printf '%s\n' '你的口令' > /opt/linji-bridge/token.txt && chmod 600 /opt/linji-bridge/token.txt
> npm i -g pm2 && pm2 start /opt/linji-bridge/server.js --name linji-bridge && pm2 save
> ```
> 然后第 3 步的反代指向 `127.0.0.1:16632`。

---

## 5. App 里怎么填

| 位置 | 填什么 |
|---|---|
| **设置 → 服务器** | 你的域名或 IP（带不带 `https://` 都行）。**留空 = 用默认服务器**（能用，但数据会报到默认那台） |
| 设置 → 连点「版本号」**7 次** → 开发者模式 | **App 桥打开** + 令牌填 `bridge.php` 里那个口令 + 点「立即同步」 |

填完：设置里点「检查更新」应该能查到新版；记录页能看到 **🌟 待办**（服务器加的），**点一下 = 完成**，服务器控制台那边就显示完成了。

---

## 6. 关于加密（谁是谁，别搞混）

| 走法 | 加不加密 | 说明 |
|---|---|---|
| 域名 + `https://` | ✅ TLS 加密 | **推荐**；数据在传输中加密，任何人都看不到内容 |
| 纯 IP + `http://` | ❌ **明文** | 只有令牌挡人，同网络能被嗅探 → 填 IP 就图省事，别传敏感东西 |
| 手机 Termux 里 `ssh` 登服务器 | ✅ **SSH 加密** | 这是**另一条路**：手机主动登服务器（用 App 里「SSH 密钥」生成的钥匙）。它不等于 App 桥 |
| 想把 http 也套进 SSH | ✅ | Termux 里跑 `ssh -N -L 8899:127.0.0.1:80 root@你的服务器`，App 服务器栏填 `http://127.0.0.1:8899`（没域名的场合可用） |

**结论**：App 桥走的是 **HTTPS**（不是 SSH），同样是加密传输，只是协议不一样；**填 http 的 IP 时不加密**，要加密就用域名 + HTTPS。
另外：域名套了 Cloudflare 的话，CF 会先解密再加密（它能看到内容）；特别在意就别套 CF，直接用 A 记录指向服务器。

---

## 7. 导出文件（备份 / 换手机）—— 导出后你要自己上传保管

App 里的数据（打卡/睡眠/记账/待办）都在手机本地，换手机就没了，所以要导出：

1. App → **设置 → 导出备份** → 设一个**口令**（自己记住！丢了打不开）
2. 生成加密文件 `.linji`，存在手机 **下载 / 棂记** 目录
3. ⚠️ **你需要把这个 `.linji` 文件上传到服务器（或网盘）** —— 只留在手机上，手机丢了/刷机了就等于没备份
   - 上传方式：手机文件管理器选中它 → 分享 → 保存到网盘；或用 SSH/宝塔面板传到服务器
   - 放在服务器上就更稳，而且能跟第 4 步的桥配合
4. 换手机恢复：装 App → **设置 → 导入备份** → 选那个 `.linji` + 输入口令 → 数据回来（兼容旧的 `.json` 备份）

---

## 8. 常见问题

| 问题 | 原因 / 怎么办 |
|---|---|
| 点「检查更新」失败 | 浏览器直接打开 `你的地址/linji/version.json` 看是不是 404；被 CDN 缓存就加 `?v=1` 试试；`url`/`md5` 是不是没改成你自己的 |
| 装不上 / 提示签名不一致 | 你这台手机装过别人签名的版本 → 先导出备份，卸载旧版，再装这个 |
| 待办、通知不来 | ① 桥开关没开 ② 令牌和 `bridge.php` 里不一致 ③ 手机 30 秒才上报一次，等一下；服务器 `bridge-data/reject.log` 会记错令牌 |
| 睡眠记录看不到 | App 里「早上好」要点（那一觉才算结束）；记录页看历史和平均 |
| 锁机不触发 | 手机设置里给 App 开：自启动、关闭电池优化、悬浮窗权限、通知权限、精确闹钟 |
| 安全 | 桥只认口令，错口令 401；口令别人猜不到就行，控制台地址别发群里 |

---

**一句话总结**：装 APK → 把 `linji-2.30.apk` + `version.json`（+ `bridge.php`）**上传**到服务器 `你的站/linji/` → App 里填服务器地址和口令 → 完事。
