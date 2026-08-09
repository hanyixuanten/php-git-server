# 使用说明

本项目通过 PHP 发布显式配置的 Git 仓库，同时支持 **Dumb HTTP** 与 **Smart HTTP**：

- `clone`：支持 Smart HTTP；服务器没有 Git/`proc_open` 时可回退到只读 Dumb HTTP。
- `pull` / `fetch`：通过 `git-upload-pack --stateless-rpc` 提供。
- `push`：通过 `git-receive-pack --stateless-rpc` 提供，默认关闭。
- `branch`：远程分支以 `refs/heads/*` 表示，可通过 push 创建、更新和删除。
- `tag`：远程标签以 `refs/tags/*` 表示，可通过 push 创建、更新和删除。
- `create`：可从主界面创建受控目录内的 bare 仓库。

Git 协议不会向服务器发送名为“branch”或“tag”的独立命令：本地 `git branch`、`git tag` 不访问服务器；远程分支和标签通过 fetch/pull 获取，通过 push 更新。

## 1. 代码结构

`index.php` 是唯一入口和主路由。各项能力已拆分为独立文件：

```text
index.php                 主入口，加载配置并注册路由
lib/http.php              HTTP 状态、响应头和认证用户读取
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
- Apache `mod_rewrite` 模块。
- 允许项目目录中的 `.htaccess` 使用重写规则。
- Smart HTTP 需要服务器安装 Git，并允许 PHP 使用 `proc_open`。
- Web 服务器进程对仓库具有读取权限；启用 push 时还需要写入权限。

项目没有 Composer 依赖，也不需要构建。

仅使用 Dumb HTTP 只读功能时，服务器可以不安装 Git；但 push 一定需要 Git。若 PHP 配置通过 `disable_functions` 禁用了 `proc_open`，Smart HTTP 也不可用。

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

$managed_repositories = array(
    'path' => '/srv/git',
    'require_auth' => TRUE,
    'session_cookie_secure' => TRUE,
    'options' => array(
        'read' => TRUE,
        'push' => TRUE,
        'require_auth' => TRUE,
        'branches' => TRUE,
        'tags' => TRUE,
        'other_refs' => FALSE,
        'max_request_bytes' => 0));

$repos = array(
    array('/project.git', '/srv/git/project.git', array(
        'read' => TRUE,
        'push' => TRUE,
        'require_auth' => TRUE,
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

`$managed_repositories` 用于启用主界面的创建入口。其 `path` 必须是已经存在、由 PHP/Web 服务器进程可读、可写且可进入的目录，并应由本应用独占；应用只会发现该目录直属的、名称以 `.git` 结尾的 bare 仓库，不递归扫描，也不会改写 `config.php`。

```php
$managed_repositories = array(
    'path' => '/srv/git',
    'require_auth' => TRUE,
    'session_cookie_secure' => TRUE,
    'options' => array(
        'read' => TRUE,
        'push' => TRUE,
        'require_auth' => TRUE));
```

- 顶层 `require_auth` 控制谁能从主界面创建仓库，默认是 `TRUE`。
- `session_cookie_secure` 控制创建表单会话 Cookie 的 Secure 属性。应用直连 HTTPS 时会自动识别；TLS 在可信反向代理终止时应显式设为 `TRUE`，并确保外部流量只能通过 HTTPS 访问。
- `options` 是所有主界面新建仓库共同继承的仓库选项，其含义与 `$repos` 条目相同。
- 设置 `$managed_repositories = array();` 可完全关闭主界面创建功能。
- 仓库名称仅允许字母、数字、点、短横线和下划线，长度最多 64 个字符；`.git` 后缀可省略。
- 新仓库是 bare 仓库，默认分支为 `main`。应用内的创建请求使用锁、暂存目录和原子改名，不会互相覆盖；托管目录不应由其他进程同时写入。

静态 `$repos` 条目与托管目录中的仓库 URL 冲突时，以静态条目为准。生产环境应让创建页面受到 Web 服务器认证保护，并保持顶层 `require_auth => TRUE`；表单本身还使用会话 CSRF 令牌。

## 5. 仓库选项

每个 `$repos` 条目的第三项是选项数组。未提供第三项的旧配置仍可使用，并保持“允许读取、禁止 push”的安全默认值。

| 选项 | 默认值 | 说明 |
| --- | --- | --- |
| `read` | `TRUE` | 允许 clone、fetch、pull 和 Dumb HTTP 对象读取 |
| `push` | `FALSE` | 启用 Smart HTTP receive-pack |
| `require_auth` | `TRUE` | push 前必须存在由 Web 服务器验证并设置的 `REMOTE_USER` |
| `branches` | `TRUE` | 允许更新 `refs/heads/*` |
| `tags` | `TRUE` | 允许更新 `refs/tags/*` |
| `other_refs` | `FALSE` | 允许 notes、replace 等其他 ref 命名空间 |
| `max_request_bytes` | `0` | push 请求最大字节数；`0` 表示不限制 |

`branches`、`tags` 和 `other_refs` 只控制 push 更新，不会隐藏已经存在的 refs。若要限制读取内容，应发布不同的仓库，而不是依赖 ref 更新选项。

push 请求会先写入系统临时目录，以便在交给 `git-receive-pack` 前检查 ref 命名空间。启用较大的 push 时，应保证 PHP 系统临时目录具有足够空间；也可以使用 `max_request_bytes` 设置上限。

## 6. 身份认证

本项目不保存用户、密码或访问令牌。`require_auth => TRUE` 只信任 Web 服务器认证完成后设置的 `REMOTE_USER`；普通客户端提交的用户名不会被当作已认证身份。

最简单的方式是让 Apache 保护整个仓库 URL：

```apache
<LocationMatch "^/php-git-server/project\.git(?:/|$)">
    AuthType Basic
    AuthName "Private Git"
    AuthUserFile /etc/apache2/git.htpasswd
    Require valid-user
</LocationMatch>
```

启用主界面创建时，还必须让认证覆盖应用首页。例如保护整个应用路径：

```apache
<Location "/php-git-server/">
    AuthType Basic
    AuthName "Git server"
    AuthUserFile /etc/apache2/git.htpasswd
    Require valid-user
</Location>
```

如果只希望认证创建入口而允许匿名 clone，可在 Web 服务器中按请求方法和路径制定更细的规则，但必须确认首页 `POST` 最终能向 PHP 提供可信的 `REMOTE_USER`。

生产环境必须配合 HTTPS。也可以使用反向代理、单点登录或其他认证模块，但需要确认认证结果最终以可信的 `REMOTE_USER` 传给 PHP。

如果设置：

```php
'require_auth' => FALSE
```

则任何能访问 URL 的用户都可 push。此设置只适合隔离的本地开发环境或已经由其他网络边界严格保护的服务。

## 7. 创建和授权仓库

推荐使用 bare 仓库：

```sh
git init --bare /srv/git/project.git
```

启用主界面创建时，应先创建并授权整个托管目录，例如：

```sh
sudo install -d -o git -g www-data -m 2770 /srv/git
```

这里的用户和组只是示例，应按 PHP/Web 服务器的实际运行身份调整。

只读仓库只需要让 Apache/PHP 用户可读取目录和文件。启用 push 时，运行 PHP 的用户必须能够创建和修改 objects、refs、日志及锁文件。例如可把仓库交给专用组管理：

```sh
sudo chown -R git:www-data /srv/git/project.git
sudo find /srv/git/project.git -type d -exec chmod 2770 {} \;
sudo find /srv/git/project.git -type f -exec chmod 660 {} \;
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

Smart HTTP 的具体协商、对象校验、fast-forward 规则和仓库 hooks 由服务器安装的 Git 版本负责。

## 10. 验证部署

### PHP 语法

```sh
php -l index.php
php -l config.php

for file in lib/*.php operations/*.php; do
    php -l "$file"
done
```

### 查看远程 refs

```sh
git ls-remote https://git.example.com/php-git-server/project.git
```

输出通常包括 `HEAD`、`refs/heads/*` 和 `refs/tags/*`。

### 端到端回归

建议在临时仓库上依次验证：

1. `git clone`。
2. 创建提交并 `git push origin main`。
3. 创建并 push 新分支。
4. 创建并 push annotated tag。
5. 在另一个工作目录执行 `git fetch --all --tags` 和 `git pull`。
6. 删除测试分支和标签。
7. 确认未启用 `other_refs` 时，推送到 `refs/notes/*` 返回 `403`。

### HTTP 状态码

不存在的资源应返回 `404`：

```sh
curl -i https://git.example.com/php-git-server/project.git/not-found
```

方法不匹配应返回 `405` 和 `Allow`：

```sh
curl -i -X POST https://git.example.com/php-git-server/project.git/HEAD
```

push 未启用或 ref 命名空间被禁止时应返回 `403`。

## 11. 常见问题

### clone / pull 返回 503

检查：

1. `$git_executable` 是否指向可执行的 Git。
2. Web 服务器进程的 `PATH` 是否包含 Git。
3. PHP 是否允许 `proc_open`。
4. Web 服务器进程是否可读取仓库。

若 Git 不可用，服务会尝试提供 Dumb HTTP；但 packed/alternates 等特殊仓库布局仍应通过实际 clone 测试。

### push 返回 403

依次检查：

1. 仓库是否设置 `'push' => TRUE`。
2. `require_auth` 为 `TRUE` 时，Web 服务器是否设置了可信 `REMOTE_USER`。
3. 分支更新是否启用了 `branches`。
4. 标签更新是否启用了 `tags`。
5. 目标是否属于其他 ref 命名空间，而 `other_refs` 仍为 `FALSE`。

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
- 主界面创建默认要求可信的 `REMOTE_USER`，托管目录不要放置其他文件。
- 使用 Apache、反向代理或统一身份系统完成真实认证。
- 不要把用户自行提供的 HTTP 头直接映射成可信 `REMOTE_USER`。
- 仓库路径必须来自静态配置或受控托管目录，不根据 URL 拼接任意文件系统路径。
- 只给 Web 服务器最小必要的文件权限。
- 将 `other_refs` 保持为 `FALSE`，除非确实需要 notes、replace 或自定义 refs。
- 使用 `max_request_bytes`、Web 服务器请求体限制和磁盘配额防止超大 push。
- 审查仓库 hooks；push 会执行 receive-pack hooks。
- 不提交 `config.php`，也不要把敏感信息写入 Git 历史。
- 确保 `.htaccess` 或等价的虚拟主机规则生效，避免绕过 `index.php`。
