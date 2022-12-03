<?php
use A7759465\Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';


$worker = new Worker('tcp://0.0.0.0:2020');
// 这个例子中进程数必须为1
$worker->count = 1;
// 进程启动时设置一个定时器，定时向所有客户端连接发送数据
$worker->onWorkerStart = function($worker)
{

};
// 运行worker
Worker::runAll();