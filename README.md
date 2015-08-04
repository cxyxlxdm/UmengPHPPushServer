##UmengPHPPushServer

一个异步的友盟推送 PHP Server，支持 Android 客户端。

USAGE:

前置条件：
1. 安装 redis ，修改程序内 redis 的url；//或修改 server 和 client 数据交互方式，如 mysql 等。
2. 注册友盟账号，开启推送服务；

启动：
1. 启动 server
php umengServer.php
2. 往redis队列插入待推送的消息格式为：
设备token+冒号+消息内容
php testUmengServer.php