# Ubuntu 安装 Docker Compose

本文适用于 Ubuntu 22.04、24.04 及较新的 Ubuntu Server，用于安装 Docker Engine、Buildx 和 Docker Compose V2。

安装完成后应使用：

```bash
docker compose
```

不要使用已经停止维护的旧命令 `docker-compose`。

## 方法一：使用 Docker 官方软件源（推荐）

如果当前是 `root` 用户，直接执行以下命令；普通用户请在命令前添加 `sudo`。

### 1. 安装基础工具

```bash
apt-get update
apt-get install -y ca-certificates curl
```

### 2. 添加 Docker 签名密钥

```bash
install -m 0755 -d /etc/apt/keyrings

curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  -o /etc/apt/keyrings/docker.asc

chmod a+r /etc/apt/keyrings/docker.asc
```

### 3. 添加 Docker 官方软件源

```bash
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo ${UBUNTU_CODENAME:-$VERSION_CODENAME}) stable" \
  > /etc/apt/sources.list.d/docker.list
```

更新软件包索引：

```bash
apt-get update
```

### 4. 安装 Docker 和 Compose V2

```bash
apt-get install -y \
  docker-ce \
  docker-ce-cli \
  containerd.io \
  docker-buildx-plugin \
  docker-compose-plugin
```

### 5. 启动 Docker

```bash
systemctl enable --now docker
```

### 6. 验证安装

```bash
docker --version
docker compose version
systemctl status docker --no-pager
```

正常情况下应显示 Docker 和 Docker Compose 的版本号，并且 Docker 服务状态为 `active (running)`。

## 方法二：使用 Ubuntu 软件源

如果服务器无法访问 Docker 官方软件源，可以尝试 Ubuntu 自带的软件包：

```bash
apt-get update
apt-get install -y docker.io docker-compose-v2
systemctl enable --now docker
```

然后验证：

```bash
docker --version
docker compose version
```

Ubuntu 不同版本的软件包名称可能存在差异。如果提示找不到 `docker-compose-v2`，请优先使用上面的 Docker 官方软件源安装方法。

## 普通用户免 sudo 使用 Docker

`root` 用户不需要配置本节。普通用户可以加入 `docker` 用户组：

```bash
sudo usermod -aG docker "$USER"
```

退出 SSH 并重新登录后验证：

```bash
docker ps
```

加入 `docker` 用户组等同于授予较高的系统权限，只应添加可信用户。

## 部署 Epay

Docker Compose 安装成功后执行：

```bash
git clone https://github.com/maker857/Epay.git
cd Epay
cp .env.example .env
nano .env
docker compose up -d --build
docker compose ps
```

查看应用启动日志：

```bash
docker compose logs -f app
```

aaPanel 反向代理目标：

```text
http://127.0.0.1:8090
```

## 常见问题

### `docker: command not found`

Docker Engine 尚未安装。请完整执行本文“方法一”或“方法二”，不要只安装 Compose 插件。

### `Unable to locate package docker-compose-plugin`

当前系统没有添加 Docker 官方软件源，或添加软件源后没有执行 `apt-get update`。重新执行“方法一”的全部步骤即可。

### `Cannot connect to the Docker daemon`

启动并检查 Docker 服务：

```bash
systemctl restart docker
systemctl status docker --no-pager
```

### `docker compose` 可用，但 `docker-compose` 不可用

这是正常情况。本项目使用 Compose V2，命令中间是空格：

```bash
docker compose up -d --build
```

### 查看 Docker 服务日志

```bash
journalctl -u docker --no-pager -n 100
```

## 卸载提示

仅卸载程序、不删除 Docker 数据：

```bash
apt-get remove -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

不要随意删除 `/var/lib/docker` 或执行 `docker compose down -v`，否则可能永久删除镜像、容器或 PostgreSQL 数据卷。操作前应先完成数据库备份。
