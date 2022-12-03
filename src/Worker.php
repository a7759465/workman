<?php

namespace A7759465\Workerman;

require_once __DIR__ . '/Lib/Constants.php';


class Worker
{
    const Version = '1.0.0';
    const STATUS_STARTING = 1;  //启动中
    const STATUS_RUNNING = 2;   //运行中
    const STATUS_SHUTDOWN = 4;  //关闭
    const STATUS_RELOADING = 8; //重启中
    const DEFAULT_BACKLOG = 102400; //tcp半连接最大数
    const MAX_UDP_PACKAGE_SIZE = 65535; //UDP包最大字节数
    const UI_SAFE_LENGTH = 4;   //相邻列的安全距离 ???

    public $id = 0;         //worker ID
    public $name = 'none';  //worker 名称
    public $count = 1;  //进程数
    public $user = '';  //linux用户
    public $group = ''; //linux用户组
    public $reloadable;  //可重启
    public $reusePort = false; //端口可重用
    public $onWorkerStart = null;   //worker 进程启动时执行回调
    public $onConnect = null;       //socket 成功建立连接时回调
    public $onMessage = null;       //收到信息时回调
    public $onClose = null;         //socket收到对端FIN包时
    public $onError = null;         //连接发生错误时 比如发送缓冲池满了
    public $onBufferFull = null;    //发送缓冲满了 回调
    public $onBufferDrain = null;   //发送缓冲清空了 回调
    public $onWorkerStop = null;    //worker 进程停止回调
    public $onWorkerReload = null;  //worker 进程重启回调
    public $onWorkerExit = null;    //worker 进程退出回调
    public $transport = 'tcp';      //传输层协议
    public $connections = [];       //存储客户端所有连接
    public $protocol = null;        //用户层协议
    public $stopping = false;   //worker 停止中


    protected $_autoloadRootPath = '';          //autoload 根目录
    protected $_pauseAccept = true;             //暂停接收新的连接
    protected $_mainSocket = null;              //监听socket
    protected $_socketName = '';                //socket 名称  http://0.0.0.0:80
    protected $_localSocket = null;             //通过socketName解析 tcp://0.0.0.0:8080
    protected $_context = null;                 //socket 上下文

    public static $daemonize = false;           //后台运行
    public static $stdoutFile = '/dev/null';    //标准输出存储文件
    public static $pidFile = '';                //主进程PID文件
    public static $statusFile = '';             //存储主进程状态文件
    public static $logFile = '';                //日志文件
    public static $globalEvent = null;          //全局事件循环
    public static $onMasterReload = null;       //主进程收到reload信号时回调
    public static $onMasterStop = null;         //主进程终止回调
    public static $eventLoopClass = '';         //事件循环类
    public static $processTitle = 'Workerman';  //进程标题
    public static $stopTimeout = 2;             //发送通知命令后如果2秒还活着就强制关闭

    protected static $_masterPid = 0;           //主进程PID
    protected static $_workers = [];            //所有worker实例
    protected static $_pidMap = [];             //worker进程的pid [worker_id=>[pid=>pid]]
    protected static $_pidsToRestart = [];      //等待重启的worker进程
    protected static $_idMap = [];              //主进程id 映射 worker进程
    protected static $_status = self::STATUS_STARTING;  //当前状态
    protected static $_maxWorkerNameLength = 12;    //worker名称最大长度
    protected static $_maxSocketNameLength = 12;    //socket名称最大长度
    protected static $_maxUserNameLenth = 12;       //进程username最大长度
    protected static $_maxProtoNameLength = 4;      //协议名称最大长度
    protected static $_maxProcessesNameLength = 9;  //进程名称最大长度
    protected static $_maxStatusNameLength = 1;     //状态名称最大长度
    protected static $_statisticsFile = '';         //存储进程当前状态信息文件
    protected static $_startFile = '';              //起始文件
    protected static $_OS = \OS_TYPE_LINUX;         //操作系统
    protected static $_processForWindows = [];      //windows 进程
    //当前worker进程的状态信息
    protected static $_globalStatistics = [
        'start_timestamp'  => 0,
        'worker_exit_info' => []
    ];
    //可用的事件循环
    protected static $_availableEventLoops = [
        'event'    => '\Sonj\MyWorkerman\Events\Event',
        'libevent' => '\Sonj\MyWorkerman\Events\Libevent'
    ];
    //PHP内置协议
    protected static $_builtinTransports = [
        'tcp'  => 'tcp',
        'udp'  => 'udp',
        'unix' => 'unix',
        'ssl'  => 'tcp'
    ];
    //PHP内置错误
    protected static $_errorType = [
        \E_ERROR             => 'E_ERROR',              //1
        \E_WARNING           => 'E_WARNING',            //2
        \E_PARSE             => 'E_PARSE',              //4
        \E_NOTICE            => 'E_NOTICE',             //8
        \E_CORE_ERROR        => 'E_CORE_ERROR',         //16
        \E_CORE_WARNING      => 'E_CORE_WARNING',       //32
        \E_COMPILE_ERROR     => 'E_COMPILE_ERROR',      //64
        \E_COMPILE_WARNING   => 'E_COMPILE_WARNING',    //128
        \E_USER_ERROR        => 'E_USER_ERROR',         //256
        \E_USER_WARNING      => 'E_USER_WARNING',       //512
        \E_USER_NOTICE       => 'E_USER_NOTICE',        //1024
        \E_STRICT            => 'E_STRICT',             //2048
        \E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',  //4096
        \E_DEPRECATED        => 'E_DEPRECATED',         //8192
        \E_USER_DEPRECATED   => 'E_USER_DEPRECATED',    //16384
    ];
    protected static $_gracefulStop = false;        //是否优雅停止
    protected static $_outputStream = null;         //标准输出流
    protected static $_outputDecorated = null;      //输出流是否指出装饰

    /**
     * 启动
     */
    public static function runALl()
    {
        static::checkSapEnv();
        static::init();
        static::lock();
        static::parseCommand();
        static::daemonize();
        static::initWorkers();
        static::installSignal();
        static::saveMasterPid();
        static::lock(\LOCK_UN);
        static::displayUI();
        static::forkWorkers();
        static::resetStd();
        static::monitorWorkers();
    }

    /**
     * 检查SAPI
     */
    protected static function checkSapEnv()
    {
        if (\PHP_SAPI !== 'cli') {
            exit("只能在命令行模式启动");
        }
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::$_OS = \OS_TYPE_WINDOWS;
        }
    }

    /**
     * 初始化
     */
    protected static function init()
    {
        \set_error_handler(function ($code, $msg, $file, $line) {
            Worker::safeEcho("$msg in file $file on line $line\n");
        });
    }

    /**
     * 安全输出
     * @param $string
     */
    public static function safeEcho($msg, $decorated = false)
    {
        $stream = static::outputStream();
        if (!$stream) {
            return false;
        }
        if (!$decorated) {
            $line = $white = $green = $end = '';
            if (static::$_outputDecorated) {    //
                $line = "\033[1A\N\033[k";
                $white = "\033[47;30m";
                $green = "\033[32;40m";
                $end = "\033[0m";
            }
            //让输出有颜色
            $msg = \str_replace(['<n>', '<w>', '<g>'], [$line, $white, $green], $msg);
            $msg = \str_replace(['</n>', '</w>', '</g>'], $end, $msg);
        } elseif (!static::$_outputDecorated) {
            return false;
        }
        \fwrite($stream, $msg);
        \fflush($stream);   //刷新缓冲区 立即输出缓冲区的输出
    }

    /**
     * 确定输出流和输出流是否有装饰功能 命令行输出颜色之类
     * @param null $stream
     */
    private static function outputStream($stream = null)
    {
        if (!$stream) {
            $stream = static::$_outputStream ?: \STDOUT;
        }
        //如果传参不是资源类型 false
        if (!$stream || !\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
            return false;
        }
        $stat = \fstat($stream);    //通过已打开的文件指针取得文件信息
        if (!$stat) {
            return false;
        }
        if (($stat['mode'] & 0170000) === 0100000) {    // S_IFREG     0100000     一般文件
            static::$_outputDecorated = false;
        } else {
            //posix_isatty — 确定文件描述符是否是交互式终端
            static::$_outputDecorated = static::$_OS === \OS_TYPE_LINUX && \function_exists('posix_isatty') && \posix_isatty($stream);
        }
        return static::$_outputStream = $stream;
    }

    public function test()
    {
        static::safeEcho("hello<w>white<g>green<n>end");
    }

}

(new Worker())->test();
echo 123;

