# 阿里云流量查询系统

基于 PHP 的阿里云 ECS 实例流量监控与管理平台，支持多 AccessKey 配置、流量统计、VNC 远程连接、自动关机/开机、邮件告警等功能。

## 功能特性

- **流量监控**：实时获取阿里云 CDT 云数据传输流量，支持今日/昨日/本月流量统计
- **多配置管理**：支持多个阿里云 AccessKey 配置，独立管理不同账号的实例
- **ECS 实例管理**：开机、关机、重启、重装系统、VNC 远程连接
- **流量告警**：流量达到阈值自动发送邮件提醒，达到关机阈值自动关闭实例
- **自动开关机**：支持按月设定定时自动开机时间
- **VNC 远程连接**：通过浏览器直接连接 ECS 实例控制台
- **操作日志**：记录所有管理员操作，便于审计追踪
- **定时任务**：支持 Cron 定时刷新流量数据

## 技术栈

| 组件 | 要求 |
|------|------|
| PHP | 7.2+ |
| MySQL | 5.6.50+ |
| Redis | 7.4.3+（可选） |
| Nginx | 任意版本 |
| 阿里云 SDK | 通过 API 手动调用（无需 Composer） |

## 目录结构

```
阿里云流量/
├── admin/                          # 后台管理模块
│   ├── components/
│   │   └── sidebar.php             # 侧边栏导航组件
│   ├── common.php                  # 后台公共文件（鉴权/数据库/日志）
│   ├── config.php                  # 阿里云配置管理
│   ├── index.php                   # 控制台首页
│   ├── login.php                   # 管理员登录
│   ├── logout.php                  # 退出登录
│   ├── logs.php                    # 操作日志
│   ├── mail_config.php             # 邮箱配置管理
│   ├── server.php                  # 服务器管理（ECS操作）
│   ├── system.php                  # 系统设置
│   ├── traffic.php                 # 流量统计
│   └── vnc.php                     # VNC远程连接
├── api/                            # API接口模块
│   ├── auto_power.php              # 自动开关机任务
│   ├── auto_refresh.php            # 前端自动刷新接口
│   ├── cron.php                    # 定时任务（流量刷新/告警/开关机）
│   └── traffic.php                 # 流量数据API
├── assets/                         # 静态资源
│   └── css/
│       ├── admin.css               # 后台样式
│       └── style.css               # 前端样式
├── config/
│   └── config.php                  # 系统主配置文件
├── core/                           # 核心类库
│   ├── AliyunCdt.php               # 阿里云CDT流量API封装
│   ├── AliyunEcs.php               # 阿里云ECS实例API封装
│   ├── Database.php                # 数据库操作类
│   ├── Helper.php                  # 辅助工具类（加密/解密）
│   └── Mailer.php                  # 邮件发送类
├── install/                        # 安装向导
│   ├── index.php                   # 安装入口
│   ├── install.sql                 # 数据库结构SQL
│   ├── step1.php                   # 环境检测
│   ├── step2.php                   # 数据库配置
│   ├── step3.php                   # 管理员设置
│   ├── step4.php                   # 安装完成
│   └── upgrade_traffic_settings.php # 流量设置升级脚本
├── scripts/
│   └── cron_refresh.sh             # 宝塔面板Cron脚本
├── .env.example                    # 环境配置示例
├── .gitignore                      # Git忽略规则
├── index.php                       # 前端首页
└── README.md                       # 项目说明
```

## 快速部署

### 1. 克隆项目

```bash
git clone https://github.com/your-username/aliyun-traffic.git
cd aliyun-traffic
```

### 2. 配置环境

```bash
cp .env.example .env
```

编辑 `.env` 文件，填写数据库和阿里云配置：

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=aliyun_traffic
DB_USER=root
DB_PASS=your_password
DB_PREFIX=at_

APP_DEBUG=false
APP_URL=http://your-domain.com
APP_SECRET=your_random_secret_key_here
```

### 3. Nginx 配置

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/aliyun-traffic;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location /admin {
        try_files $uri $uri/ /admin/index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/tmp/php-cgi.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.env {
        deny all;
    }
}
```

### 4. 安装向导

访问 `http://your-domain.com/install/` 按步骤完成安装：
1. 环境检测
2. 数据库配置
3. 管理员账号设置
4. 安装完成

### 5. 设置定时任务

**宝塔面板方式：**

计划任务 → 添加任务 → Shell脚本，执行周期建议5分钟：

```bash
/www/server/php/82/bin/php /path/to/aliyun-traffic/api/cron.php
```

**Crontab 方式：**

```bash
*/5 * * * * /usr/bin/php /path/to/aliyun-traffic/api/cron.php > /dev/null 2>&1
```

## 使用说明

### 前端首页

- 访问 `http://your-domain.com/` 查看流量统计
- 显示启用/总配置数、今日流量、昨日流量、本月流量
- 流量使用进度条和实例排行

### 后台管理

- 访问 `http://your-domain.com/admin/` 进入管理后台
- 默认路径为 `/admin`

| 页面 | 功能 |
|------|------|
| 控制台 | 流量概览、趋势图、配置状态 |
| 阿里云配置 | 添加/编辑/删除 AccessKey，设置流量阈值 |
| 服务器管理 | ECS 实例列表、开机/关机/重启/重装/VNC |
| 流量统计 | 按日期/实例统计，趋势图，排行榜 |
| 邮箱配置 | SMTP 邮箱设置，邮件模板管理 |
| 操作日志 | 管理员操作记录 |
| 系统设置 | 站点名称、描述、自动刷新等 |

### VNC 远程连接

在服务器管理页面点击 VNC 按钮，即可在新窗口中通过浏览器连接 ECS 实例控制台，支持 Linux 和 Windows 系统。

### 自动关机/开机

1. 在阿里云配置中设置 **流量提醒阈值**（默认80%）和 **自动关机阈值**（默认95%）
2. 开启 **自动关机** 开关
3. 设置 **定时开机时间**（每月几号几时几分）
4. 当流量达到关机阈值时，系统自动关闭该配置下所有 ECS 实例
5. 到达定时开机时间时，系统自动启动实例

## 数据库表结构

| 表名 | 说明 |
|------|------|
| at_system_config | 系统配置键值对 |
| at_admin_users | 管理员账号 |
| at_aliyun_config | 阿里云 AccessKey 配置 |
| at_traffic_records | 流量记录（按日期存储） |
| at_operation_logs | 操作日志 |
| at_mail_config | SMTP 邮箱配置 |
| at_mail_template | 邮件模板 |

## 安全说明

- AccessKey Secret 使用 AES-256-CBC 加密存储
- 管理员密码使用 `password_hash()` bcrypt 加密
- `.env` 文件包含敏感信息，已加入 `.gitignore`
- 安装完成后会生成 `install.lock` 防止重复安装
- Nginx 配置中应禁止直接访问 `.env` 文件

## 许可证

MIT License
