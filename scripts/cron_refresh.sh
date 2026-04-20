#!/bin/bash
#
# 阿里云流量自动任务脚本
# 宝塔面板计划任务使用
#
# 功能说明：
# 1. 自动刷新所有配置的流量数据
# 2. 检查流量阈值并发送邮件提醒
# 3. 流量达到关机阈值时自动关闭ECS实例
# 4. 定时自动开机
#
# 使用方法：
# 1. 宝塔面板 -> 计划任务 -> 添加任务
# 2. 任务类型：Shell脚本
# 3. 执行周期：N分钟（建议5分钟）
# 4. 脚本内容：填写此脚本路径或直接粘贴内容
#
# 注意事项：
# - 请确保已在"邮箱配置"中配置SMTP邮箱
# - 请确保已在"阿里云配置"中设置提醒阈值和关机阈值
# - PHP路径请根据服务器实际版本调整
#

PHP_PATH=/www/server/php/82/bin/php
SCRIPT_PATH=/pan/acg/aly/api/cron.php
LOG_PATH=/pan/acg/aly/logs/cron.log

if [ ! -d "$(dirname $LOG_PATH)" ]; then
    mkdir -p "$(dirname $LOG_PATH)"
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] ========== 开始执行定时任务 ==========" >> $LOG_PATH

$PHP_PATH $SCRIPT_PATH >> $LOG_PATH 2>&1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] ========== 定时任务执行完成 ==========" >> $LOG_PATH
echo "" >> $LOG_PATH
