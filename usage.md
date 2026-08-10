# 使用说明

本项目通过 PHP 发布显式配置的 Git 仓库，同时支持 **Dumb HTTP** 与 **Smart HTTP**：

- `clone`：支持 Smart HTTP；服务器没有 Git/`proc_open` 时由 PHP 原生 upload-pack 提供，也保留 Dumb HTTP 兼容路径。
- `pull` / `fetch`：通过 `git-upload-pack --stateless-rpc` 提供。
- `push`：有 Git 时通过 `git-receive-pack --stateless-rpc` 提供；无 Git 时由 PHP 原生 receive-pack 提供，默认关闭。
- `branch`：远程分支以 `refs/heads/*` 表示，可通过 push 创建、更新和删除。
- `tag`：远程标签以 `refs/tags/*` 表示，可通过 push 创建、更新和删除。
- `create`：可从主界面创建受控目录内的 bare 仓库；Git 不可用时由 PHP 直接初始化。

Git 协议不会向服务器发送名为“branch”或“tag”的独立命令：本地 `git branch`、`git tag` 不访问服务器；远程分支和标签通过 fetch/pull 获取，通过 push 更新。

## 1. 代码结构

`index.php` 是唯一入口和主路由。各项能力已拆分为独立文件：

```text
index.php                 主入口，加载配置并注册路由
lib/http.php              HTTP 状态、响应头和认证用户读取
lib/auth.php              MySQL 用户、网页登录会话与 Access Token 验证
lib/repository.php        仓库配置、安全路径和 Dumb HTTP refs
lib/router.php            请求路由
lib/git_service.php       Smart HTTP Git 子进程与流式传输
operations/clone.php      Dumb HTTP clone/object 资源
operations/pull.php       upload-pack：clone/fetch/pull
operations/push.php       receive-pack：push 请求、大小及 refs 校验
operations/branch.php     refs/heads/* 分支更新规则
operations/tag.php        refs/tags/* 标签更新规则
```

## 2. 环境要求

推荐环境：

- Apache HTTP Server。
- PHP 7.4 或更新版本。
- MySQL 5.7+/MariaDB 10.2+ 与 PHP PDO MySQL 扩展（`pdo_mysql`）。
- Apache `mod_rewrite` 模块。
- 允许项目目录中的 `.htaccess` 使用重写规则。
- Smart HTTP 需要服务器安装 Git，并允许 PHP 使用 `proc_open`。
- Web 服务器进程对仓库具有读取权限；启用 push 时还需要写入权限。

项目没有 Composer 依赖，也不需要构建。若系统尚未启用 PDO MySQL，先安装对应 PHP 扩展并重启 Apache/PHP-FPM。

服务器可以不安装 Git。Git 或 `proc_open` 不可用时，应用使用纯 PHP 实现的 Smart HTTP 服务端协议，支持普通 SHA-1 仓库的 clone、fetch、pull、push、delta、分支和标签；PHP 必须启用 zlib 与 hash 扩展。

原生 PHP 后端当前不支持 SHA-256 仓库、shallow clone、partial clone/filter、push certificate、Git hooks 和仅 protocol v2 提供的功能。需要这些能力时仍应安装 Git 并允许 `proc_open`。

## 3. 安装

将项目放到 Apache 可以访问的目录中，例如：

```text
https://git.example.com/php-git-server/
```

复制配置模板：

```sh
cp config.php.sample config.php
```

不要提交真实的 `config.php`，其中可能包含服务器目录结构和安全策略。

创建数据库、低权限应用用户并导入表结构。下面的密码必须替换为随机强密码：

```sql
CREATE DATABASE php_git_server CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'php_git_server'@'127.0.0.1' IDENTIFIED BY 'replace-with-a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON php_git_server.* TO 'php_git_server'@'127.0.0.1';
```

```sh
mysql -u root -p php_git_server < schema.mysql.sql
```

确认 Apache 已启用重写模块：

```sh
sudo a2enmod rewrite
sudo systemctl reload apache2
```

对应站点或目录还需要允许 `.htaccess`：

```apache
<Directory /var/www/php-git-server>
    AllowOverride FileInfo
    Require all granted
</Directory>
```

项目自带的 `.htaccess` 会把请求转发到 `index.php`，避免仓库文件绕过安全路由被 Web 服务器直接公开。

## 4. 基本配置

### 部署在子目录

应用地址为：

```text
https://git.example.com/php-git-server/
```

配置示例：

```php
<?php

$url_base = '/php-git-server';
$git_executable = 'git';

$auth = array(
    'enabled' => TRUE,
    'registration_enabled' => TRUE,
    'administrators' => array('alice'),
    'session_cookie_secure' => TRUE,
    'database' => array(
        'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=php_git_server;charset=utf8mb4',
        'username' => 'php_git_server',
        'password' => 'replace-with-a-long-random-password'));

$managed_repositories = array(
    'options' => array(
        'read' => TRUE,
        'push' => TRUE,
        'branches' => TRUE,
        'tags' => TRUE,
        'other_refs' => FALSE,
        'max_request_bytes' => 0));

$repos = array(
    array('/project.git', '/srv/git/project.git', array(
        'read' => TRUE,
        'push' => TRUE,
        'owner' => 'alice',
        'private' => TRUE,
        'branches' => TRUE,
        'tags' => TRUE,
        'other_refs' => FALSE,
        'max_request_bytes' => 0)));
```

仓库地址为：

```text
https://git.example.com/php-git-server/project.git
```

### 部署在域名根路径

```php
$url_base = '';

$repos = array(
    array('/project.git', '/srv/git/project.git'));
```

仓库地址为：

```text
https://git.example.com/project.git
```

### 相对路径

仓库路径可以是绝对路径，也可以是相对于本项目目录的路径。例如：

```php
array('/self.git', '.git')
```

推荐生产环境使用明确的绝对路径和 bare 仓库。

### 从主界面创建仓库

`$managed_repositories` 用于启用主界面的创建入口。托管目录固定为本项目下的 `repos` 文件夹，不能通过 `config.php` 修改。目录不存在时应用会自动创建，此时 PHP/Web 服务器进程必须能写入项目根目录；创建后的目录必须可读、可写且可进入，并应由本应用独占。应用只会发现该目录直属的、名称以 `.git` 结尾的 bare 仓库，不递归扫描。

```php
$managed_repositories = array(
    'options' => array(
        'read' => TRUE,
        'push' => TRUE));
```

- 创建仓库必须登录；当前账号自动成为仓库所有者，并可在表单中选择“公开”或“私有”。
- 托管仓库所有者可在首页勾选确认后永久删除自己的仓库。删除同时移除 `pgit_repositories` 记录和对应 bare 仓库目录，不能撤销；静态 `$repos` 条目不会显示删除操作。
- 启用账号认证时，`$auth['session_cookie_secure']` 控制登录与表单共用 Session Cookie 的 Secure 属性。应用直连 HTTPS 时会自动识别；TLS 在可信反向代理终止时应显式设为 `TRUE`，并确保外部流量只能通过 HTTPS 访问。
- `options` 是所有主界面新建仓库共同继承的仓库选项，其含义与 `$repos` 条目相同。
- 设置 `$managed_repositories = array();` 可完全关闭主界面创建功能。
- 仓库名称仅允许字母、数字、点、短横线和下划线，长度最多 64 个字符；`.git` 后缀可省略。
- 新仓库是 bare 仓库，默认分支为 `main`。应用内的创建请求使用锁、暂存目录和原子改名，不会互相覆盖；托管目录不应由其他进程同时写入。
- Git 或 `proc_open` 不可用时，应用以纯 PHP 创建标准 SHA-1 格式的空 bare 仓库；之后可以通过 Dumb HTTP clone，但首次写入仍需在其他具备 Git 的环境中生成仓库内容并同步到服务器。

静态 `$repos` 条目与托管目录中的仓库 URL 冲突时，以静态条目为准。仓库创建要求已登录 Session，所有修改表单还使用会话 CSRF 令牌。托管仓库的所有者和可见性保存在 `pgit_repositories`；缺少元数据的旧托管仓库按私有、无所有者处理，在完成迁移前不可 push。

## 5. 仓库选项

每个 `$repos` 条目的第三项是选项数组。未提供第三项的旧配置仍可使用，并保持“允许读取、禁止 push”的安全默认值。

| 选项 | 默认值 | 说明 |
| --- | --- | --- |
| `read` | `TRUE` | 允许 clone、fetch、pull 和 Dumb HTTP 对象读取 |
| `push` | `FALSE` | 启用 Smart HTTP receive-pack |
| `owner` | `NULL` | 允许 push 的账号用户名；未设置时任何人都不能 push |
| `private` | `FALSE` | 是否要求有效 Access Token 才能进行任何 Git 读取 |
| `branches` | `TRUE` | 允许更新 `refs/heads/*` |
| `tags` | `TRUE` | 允许更新 `refs/tags/*` |
| `other_refs` | `FALSE` | 允许 notes、replace 等其他 ref 命名空间 |
| `allow_non_fast_forward` | `FALSE` | 原生 PHP 后端是否允许强制改写远程分支 |
| `max_object_bytes` | `268435456` | 原生 PHP 后端允许的单个解压对象最大字节数 |
| `max_pack_objects` | `100000` | 原生 PHP 后端一次 push pack 允许的最大对象数 |
| `max_request_bytes` | `0` | push 请求最大字节数；`0` 表示不限制 |

所有 push 都必须提供 access token，且 token 所属用户名必须与 `owner` 完全一致。`branches`、`tags` 和 `other_refs` 只控制 push 更新，不会隐藏已经存在的 refs。私有仓库的 Smart HTTP 与 Dumb HTTP 路径都会先验证 token，私有对象响应禁止共享缓存。

push 请求会先写入系统临时目录，以便在交给 `git-receive-pack` 前检查 ref 命名空间。启用较大的 push 时，应保证 PHP 系统临时目录具有足够空间；也可以使用 `max_request_bytes` 设置上限。

## 6. 身份认证

### 账号配置

`$auth` 启用后，首页提供注册和登录。用户密码通过 PHP `password_hash()` 保存；应用不会保存明文密码。`registration_enabled => FALSE` 可关闭新用户注册，已有用户仍可登录。生产部署完成首批账号注册后，建议关闭公开注册，或在反向代理/WAF 中为注册和登录请求配置速率限制。

### 管理员配置与管理界面

管理员由 `config.php` 中的账号用户名列表决定：

```php
$auth = array(
    'enabled' => TRUE,
    'registration_enabled' => FALSE,
    'administrators' => array(
        'alice',
        'release-admin'),
    'database' => array(
        // ...
    ));
```

列表按大小写精确匹配，且账号必须已经存在并保持启用。修改配置后无需更新数据库角色；管理员退出并重新登录后即可从首页进入 `manage.php`。配置中的管理员用户名不能通过公开注册创建：部署首个管理员时，应先注册可信账号，再把它加入配置；其他管理员可由现有管理员创建账号后再加入配置。

管理界面提供以下操作：

- 创建、启用和停用用户，重置用户密码，撤销该用户的全部有效 Access Token。
- 删除不再拥有任何托管仓库的非管理员用户；当前登录账号和配置中的管理员账号不能被停用或删除。
- 转移托管仓库所有者，在公开与私有之间切换，并永久删除任意托管仓库。
- 查看静态 `$repos` 配置仓库，但不修改 `config.php`，也不删除其路径。

以下两项约束由服务端强制，构造请求同样无效：

- 若某个静态 `$repos` 条目指向 `repos` 目录中的同一路径，该仓库标记为“静态配置”且不可删除。要删除它，应先从 `config.php` 移除对应条目。
- 仍被静态 `$repos` 条目列为 `owner` 的账号不能删除。用户名删除后可被重新注册，若此时仍留在配置中，新账号会直接获得该仓库的 push 权限；应先在配置中更换 `owner`。

停用用户后，其浏览器 Session 在下一次请求时失效，Access Token 也不再通过认证。删除仓库和用户均为不可撤销操作；执行前应确认已有可恢复备份。管理请求只接受网页登录 Session，并使用独立的 CSRF Token；Git Access Token 不能用于登录管理页。

`session_cookie_secure` 在应用直连 HTTPS 时会自动推断。TLS 在可信反向代理终止时必须显式设为 `TRUE`，并确保外部只能通过 HTTPS 访问。不要再给应用路径配置 Apache `AuthType Basic`，否则 Apache 会在请求到达 PHP 前拦截应用注册、登录及 token 验证。

### 创建和使用 Access Token

登录首页后填写 Token 名称并点击“创建 Token”。明文 token 仅显示一次，格式为 `pgs_` 加 64 个十六进制字符；数据库仅保存 SHA-256 摘要。页面可以查看最后使用时间并随时撤销 token。

Git 通过 HTTP Basic 发送凭据：用户名填写注册用户名，密码填写 access token，不能填写网页登录密码。例如：

```sh
git clone https://git.example.com/php-git-server/project.git
git push origin main
```

Git 收到私有读取或 push 的 `401` 响应后会提示输入用户名和密码。也可以使用操作系统的 Git Credential Manager 或其他安全凭据助手保存 token；不要把 token 写入远程 URL、shell 历史、仓库配置或脚本。

公开仓库允许匿名 clone/fetch/pull；私有仓库只接受 access token。浏览器登录 Session 只用于首页、创建仓库和显示私有仓库列表，不能代替 Git access token。Token 验证成功后，用户名会作为 `REMOTE_USER` 传给 Git 子进程和 hooks，现有 hooks 可以继续读取该变量。

### 现有数据库与仓库迁移

从旧版本升级时，先执行独立迁移文件：

```sh
mysql -u root -p php_git_server < migration.repository-ownership.mysql.sql
```

随后为每个已有托管仓库写入所有者和可见性，仓库名必须包含 `.git` 后缀：

```sql
INSERT INTO pgit_repositories (repository_name, owner_user_id, is_private)
SELECT 'project.git', id, 1 FROM pgit_users WHERE username = 'alice';
```

静态 `$repos` 不写入 `pgit_repositories`，必须直接在配置中设置 `owner` 和 `private`。升级前遗留的 `require_auth` 配置不再控制访问，也不能关闭 owner/token 校验。

## 7. 创建和授权仓库

推荐使用 bare 仓库：

```sh
git init --bare /srv/git/project.git
```

启用主界面创建后，缺失的 `repos` 目录会自动创建。生产环境可预先创建并授权该目录，以明确设置属主和权限，例如：

```sh
sudo install -d -o git -g www-data -m 2770 /var/www/php-git-server/repos
```

这里的项目路径、用户和组只是示例，应按实际部署位置以及 PHP/Web 服务器的运行身份调整。托管目录必须是项目根目录下的 `repos`。

只读仓库只需要让 Apache/PHP 用户可读取目录和文件。启用 push 时，运行 PHP 的用户必须能够创建和修改 objects、refs、日志及锁文件。例如可把仓库交给专用组管理：

```sh
sudo chown -R git:www-data /var/www/php-git-server/repos/project.git
sudo find /var/www/php-git-server/repos/project.git -type d -exec chmod 2770 {} \;
sudo find /var/www/php-git-server/repos/project.git -type f -exec chmod 660 {} \;
```

权限策略应根据服务器实际用户、组和备份方案调整。不要使用 `chmod -R 777`。

仓库中的 hooks 会在 `git-receive-pack` 处理 push 时以 PHP/Web 服务器用户身份执行。只能发布受信任的仓库和 hooks，并确保 hooks 不接受未经校验的外部参数去执行任意命令。

## 8. 操作示例

以下示例假设仓库 URL 为：

```text
https://git.example.com/php-git-server/project.git
```

### clone

```sh
git clone https://git.example.com/php-git-server/project.git
```

### pull / fetch

```sh
cd project
git pull --ff-only

git fetch origin
```

clone、fetch 和 pull 在服务器端共用 upload-pack。服务器无法区分一次 fetch 是首次 clone 还是后续 pull。

### push 提交

```sh
git add .
git commit -m "Update project"
git push origin main
```

### 创建并推送分支

```sh
git switch -c feature/login
git push -u origin feature/login
```

查看远程分支：

```sh
git branch -r
```

删除远程分支：

```sh
git push origin --delete feature/login
```

以上操作需要仓库同时启用 `push` 和 `branches`。

### 创建并推送标签

推荐创建 annotated tag：

```sh
git tag -a v1.0.0 -m "Release v1.0.0"
git push origin v1.0.0
```

推送所有本地标签：

```sh
git push origin --tags
```

删除远程标签：

```sh
git push origin --delete v1.0.0
```

以上操作需要仓库同时启用 `push` 和 `tags`。

## 9. 协议和资源支持

Smart HTTP 支持：

- `GET /info/refs?service=git-upload-pack`
- `POST /git-upload-pack`
- `GET /info/refs?service=git-receive-pack`
- `POST /git-receive-pack`
- upload-pack 的 Git protocol v0/v1/v2 协商
- Git 自身支持的 SHA-1 或 SHA-256 仓库格式

Dumb HTTP 支持：

- `HEAD`
- `info/refs`
- `objects/info/packs`
- `objects/info/alternates`
- `objects/info/http-alternates`
- loose objects
- pack 文件和 pack index
- loose refs、packed refs、packed annotated tag 的 peeled refs
- SHA-1 和 SHA-256 长度的对象名称

安装 Git 时，Smart HTTP 的具体协商、对象校验、fast-forward 规则和仓库 hooks 由服务器 Git 负责。无 Git 时，PHP 后端执行 SHA-1 对象哈希、pack 校验、OFS/REF delta、对象连通性、ref 锁和默认 fast-forward 检查，但不会执行 hooks。

## 10. 验证部署

### PHP 语法

```sh
php -l index.php
php -l config.php

for file in lib/*.php operations/*.php; do
    php -l "$file"
done
```

确认 PDO MySQL 已启用：

```sh
php -m | grep pdo_mysql
```

### 查看远程 refs

```sh
git ls-remote https://git.example.com/php-git-server/project.git
```

输出通常包括 `HEAD`、`refs/heads/*` 和 `refs/tags/*`。

### 端到端回归

建议在临时仓库上依次验证：

1. 匿名 clone 公开仓库。
2. 使用 access token clone 私有仓库，并确认匿名访问返回 `401`。
3. 用 owner token 创建提交并 `git push origin main`。
4. 确认非 owner token 的 push 返回 `403`。
5. 创建并 push 新分支。
6. 创建并 push annotated tag。
7. 在另一个工作目录执行 `git fetch --all --tags` 和 `git pull`。
8. 删除测试分支和标签。
9. 确认未启用 `other_refs` 时，推送到 `refs/notes/*` 返回 `403`。

### HTTP 状态码

不存在的资源应返回 `404`：

```sh
curl -i https://git.example.com/php-git-server/project.git/not-found
```

方法不匹配应返回 `405` 和 `Allow`：

```sh
curl -i -X POST https://git.example.com/php-git-server/project.git/HEAD
```

私有仓库读取或 push 未提供有效 token 时应返回 `401` 和 `WWW-Authenticate`；非所有者 push、push 未启用或 ref 命名空间被禁止时应返回 `403`。

## 11. 常见问题

### clone / pull 返回 503

检查：

1. `$git_executable` 是否指向可执行的 Git。
2. Web 服务器进程的 `PATH` 是否包含 Git。
3. PHP 是否允许 `proc_open`。
4. Web 服务器进程是否可读取仓库。

若 Git 不可用，服务使用原生 PHP Smart HTTP 后端。确认 PHP 启用了 zlib 与 hash，并确认仓库是 SHA-1 格式；浅克隆、filter、SHA-256 或 hooks 需求必须改用 Git 后端。

### push 返回 403

依次检查：

1. 仓库是否设置 `'push' => TRUE`。
2. access token 所属用户名是否与仓库 `owner` 完全一致。
3. 分支更新是否启用了 `branches`。
4. 标签更新是否启用了 `tags`。
5. 目标是否属于其他 ref 命名空间，而 `other_refs` 仍为 `FALSE`。

### clone / pull / push 返回 401 或反复询问密码

依次检查：

1. 密码位置输入的是首页生成的 access token，而不是网页登录密码。
2. 用户名与创建 token 的账号完全一致；用户名区分大小写。
3. token 是否已撤销或被凭据助手缓存为旧值。
4. PHP 是否启用 `pdo_mysql`，`$auth['database']` 是否能连接数据库。
5. Apache/FastCGI 是否保留 `Authorization` 头；项目 `.htaccess` 已包含对应重写环境变量规则。

### 首页显示认证数据库不可用

检查 PHP/Apache 错误日志、`pdo_mysql` 扩展、MySQL 地址和账号权限，并确认已导入 `schema.mysql.sql`。应用数据库账号需要 `SELECT`、`INSERT`、`UPDATE` 和 `DELETE`，但不需要运行时建表权限。

### 管理页返回 403

确认已经使用浏览器登录应用账号，并且 `config.php` 的 `$auth['administrators']` 包含该账号的精确用户名。用户名区分大小写；已停用用户、Git HTTP Basic 凭据和 Access Token 都不能直接进入管理页。

### push 返回 500 或远端断开

检查 PHP/Apache 错误日志、Git hooks 输出、仓库写权限、磁盘空间和临时目录空间。还应确认 `max_request_bytes` 没有设置得过小。

### non-fast-forward 被拒绝

这是 Git receive-pack 的正常保护。先同步远程提交，或在明确了解影响时使用受控的 force push：

```sh
git push --force-with-lease origin main
```

仓库本地配置和 hooks 仍可进一步禁止 force push、删除或特定提交。

### 浏览器可以访问，但 Git 操作失败

开启客户端跟踪：

```sh
GIT_TRACE=1 GIT_CURL_VERBOSE=1 git clone \
    https://git.example.com/php-git-server/project.git
```

重点检查 `info/refs`、`git-upload-pack`、`git-receive-pack` 的 HTTP 状态、内容类型和认证过程。

## 12. 安全建议

- 对所有生产流量使用 HTTPS。
- push 默认保持关闭，只为确实需要写入的仓库启用。
- 主界面创建默认要求已登录应用账号，托管目录不要放置其他文件。
- 公开注册应配合速率限制；不需要公开注册时设置 `registration_enabled => FALSE`。
- 数据库账号只授予 `pgit_users`、`pgit_access_tokens` 和 `pgit_repositories` 所需的最小读写权限，并单独备份。
- 定期撤销不再使用的 token；不要记录 `Authorization` 头或 token 明文。
- 仓库路径必须来自静态配置或受控托管目录，不根据 URL 拼接任意文件系统路径。
- 只给 Web 服务器最小必要的文件权限。
- 将 `other_refs` 保持为 `FALSE`，除非确实需要 notes、replace 或自定义 refs。
- 使用 `max_request_bytes`、Web 服务器请求体限制和磁盘配额防止超大 push。
- 审查仓库 hooks；push 会执行 receive-pack hooks。
- 不提交 `config.php`，也不要把敏感信息写入 Git 历史。
- 确保 `.htaccess` 或等价的虚拟主机规则生效，避免绕过 `index.php`。
