# 使用说明

本项目使用 PHP 实现 Git 的 **Dumb HTTP（哑 HTTP）只读协议**。它可以让 Git 客户端通过 HTTP 查看和克隆服务器上的 Git 仓库，不依赖服务器安装 `git` 命令。

> 本项目只提供读取功能，不支持通过 HTTP `push`，也不支持 Git Smart HTTP。

## 1. 环境要求

推荐环境：

- Apache HTTP Server
- PHP（已在 PHP 8.5 下验证）
- Apache `mod_rewrite` 模块
- 允许项目目录中的 `.htaccess` 使用重写规则
- Web 服务器进程对被发布的 Git 仓库具有读取权限

项目没有 Composer 依赖，也不需要构建。

## 2. 安装

将本项目放到 Apache 可以访问的目录中。例如，项目对应的 URL 为：

```text
https://git.example.com/php-git-server
```

目录中至少应包含：

```text
php-git-server/
├── .htaccess
├── config.php
└── index.php
```

复制示例配置：

```sh
cp config.php.sample config.php
```

`config.php` 是部署环境配置文件，不应提交到版本库。

## 3. 配置 Apache

确认 Apache 已启用 `mod_rewrite`：

```sh
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Apache 站点配置需要允许 `.htaccess` 中的重写规则生效。例如：

```apache
<Directory /var/www/html/php-git-server>
    AllowOverride FileInfo
    Require all granted
</Directory>
```

修改配置后重新加载 Apache：

```sh
sudo systemctl reload apache2
```

不同 Linux 发行版的 Apache 配置目录和服务名称可能不同，请按实际环境调整。

### 不要使用 PHP 内置服务器进行生产部署

PHP 内置服务器不会处理 `.htaccess`。它可以配合 `index.php` 作为路由脚本进行临时测试，但不应作为生产部署方式：

```sh
php -S 127.0.0.1:8080 index.php
```

## 4. 配置仓库

编辑 `config.php`：

```php
<?php

/* 本应用在网站中的 URL 路径；末尾不要加斜杠 */
$url_base = '/php-git-server';

/* 每项依次为公开仓库 URL 和服务器上的 Git 仓库路径 */
$repos = array(
    array('/project.git', '/srv/git/project.git'),
    array('/wiki.git', '/srv/git/wiki.git'));
```

上述配置会公开以下只读地址：

```text
https://git.example.com/php-git-server/project.git
https://git.example.com/php-git-server/wiki.git
```

### 部署在域名根路径

如果应用直接部署在域名根路径，例如仓库地址为：

```text
https://git.example.com/project.git
```

则配置为：

```php
$url_base = '';

$repos = array(
    array('/project.git', '/srv/git/project.git'));
```

### 仓库路径说明

仓库路径可以指向：

- bare 仓库，例如 `/srv/git/project.git`
- 普通工作仓库的 `.git` 目录，例如 `/srv/project/.git`

推荐发布 bare 仓库，以避免工作区状态影响部署和维护。

如果服务器上安装了 Git，可以这样创建 bare 仓库：

```sh
git clone --bare /path/to/project /srv/git/project.git
```

本项目运行时不执行 Git 命令，因此生产服务器本身可以不安装 Git。

## 5. 设置文件权限

Apache/PHP 进程只需要读取仓库，不需要写入权限。以 Debian/Ubuntu 常见的 `www-data` 用户为例，可按实际权限策略设置：

```sh
sudo chown -R git:www-data /srv/git/project.git
sudo find /srv/git/project.git -type d -exec chmod 750 {} \;
sudo find /srv/git/project.git -type f -exec chmod 640 {} \;
```

不要为了省事给仓库设置全局可写权限，例如 `chmod -R 777`。

## 6. 验证部署

### 检查 PHP 语法

```sh
php -l index.php
php -l config.php
```

### 查看远程引用

```sh
git ls-remote https://git.example.com/php-git-server/project.git
```

正常情况下会看到类似输出：

```text
0123456789abcdef0123456789abcdef01234567    HEAD
0123456789abcdef0123456789abcdef01234567    refs/heads/main
```

### 克隆仓库

```sh
git clone https://git.example.com/php-git-server/project.git
```

### 检查 HTTP 状态码

不存在的路径应返回 `404`：

```sh
curl -i https://git.example.com/php-git-server/project.git/not-found
```

不支持的方法应返回 `405`，并包含 `Allow: GET`：

```sh
curl -i -X POST https://git.example.com/php-git-server/project.git/HEAD
```

## 7. 更新已发布的仓库

本项目不支持 HTTP push。需要通过其他方式更新服务器上的仓库，例如：

- SSH
- 文件同步工具
- 部署脚本
- CI/CD
- 服务器本地文件路径或 SSH 协议执行 `git push`

例如，从有写权限的环境推送到 bare 仓库：

```sh
git remote add publish ssh://git@git.example.com/srv/git/project.git
git push publish main
```

本项目会动态生成 `/info/refs` 和 `/objects/info/packs`，通常不需要手动执行：

```sh
git update-server-info
```

## 8. 支持范围

当前支持通过 `GET` 读取以下 Dumb HTTP 资源：

- `HEAD`
- `info/refs`
- `objects/info/packs`
- `objects/info/alternates`
- `objects/info/http-alternates`
- loose objects
- pack 文件
- pack index 文件
- loose refs
- packed refs
- packed annotated tag 的 peeled refs

当前限制：

- 只读，不支持 push
- 不支持 Git Smart HTTP
- 只支持 SHA-1 格式仓库
- 不支持 SHA-256 object-format 仓库
- 不提供身份认证和授权功能

如需限制访问，请在 Apache、反向代理或其他外层服务中配置 HTTPS 和身份认证。

## 9. 常见问题

### `git clone` 返回 404

依次检查：

1. `$url_base` 是否与部署路径完全一致。
2. `$repos` 中的公开路径是否以 `/` 开头。
3. Apache 是否启用了 `mod_rewrite`。
4. Apache 是否允许 `.htaccess` 使用 `RewriteRule`。
5. 仓库路径是否存在，且 PHP 进程是否有读取权限。
6. 仓库地址是否包含配置中的 `.git` 后缀。

### 浏览器可以访问，但 Git 克隆失败

使用下面的命令查看 Git 的 HTTP 请求过程：

```sh
GIT_TRACE=1 GIT_CURL_VERBOSE=1 git clone \
    https://git.example.com/php-git-server/project.git
```

重点检查 `/info/refs`、`/HEAD`、loose object 或 pack 文件请求是否返回了正确的 `200`、`404` 和内容类型。

### 返回 500

检查 Apache/PHP 错误日志，并确认：

- `config.php` PHP 语法正确。
- `$repos` 已定义为数组。
- 仓库目录可读取。
- PHP 未被 `open_basedir` 等配置禁止访问仓库目录。

### 仓库可以读取，但无法 push

这是预期行为。本项目是只读 Dumb HTTP 服务。请通过 SSH、文件路径、部署脚本或其他具备写入能力的服务更新仓库。

## 10. 安全建议

- 使用 HTTPS，避免 Git 内容和认证信息通过明文 HTTP 传输。
- 不要将 `config.php` 提交到公共版本库。
- 仓库路径应明确配置，不要根据 URL 动态拼接任意文件系统路径。
- Web 服务器只应具有必要的读取权限。
- 不要在仓库中保存不希望通过 Git 下载的敏感内容。
- 如需私有仓库，在 Apache或反向代理层配置认证和访问控制。
- 确保 `.htaccess` 生效，避免 Web 服务器绕过 `index.php` 直接提供仓库内部文件。
