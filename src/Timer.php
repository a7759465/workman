<?php

namespace A7759465\Workerman;

class Timer
{
    /**
     * 任务  基于 Alarm 信号
     * [
     *   run_time => [[$func, $args, $persistent, time_interval],[$func, $args, $persistent, time_interval],..]],
     *   run_time => [[$func, $args, $persistent, time_interval],[$func, $args, $persistent, time_interval],..]],
     *   ..
     * ]
     */
    protected static $_tasks = [];
    //事件
    protected static $_event = null;
    //计时器id
    protected static $_timerId = 0;
    /**
     * 状态
     *   timer_id1 => bool,
     *   timer_id2 => bool,
     */
    protected static $_status = [];

    public static function init($event = null)
    {
        if ($event) {
            self::$_event = $event;
            return;
        }
        if (\function_exists('pcntl_signal')) {
            //安装一个信号处理器 警告信号 会调用\A7759465\Workerman\Timer::signalHandle();
            \pcntl_signal(\SIGALRM, ['\A7759465\Workerman\Timer', 'signalHandle', false]);
        }
    }

    public static function signalHandle()
    {
        if (!self::$_event) {
            \pcntl_alarm(1);    //为进程设置一个alarm闹钟信号 这里一秒一次  操作系统1秒向进程发送一个警告信号
            self::tick();
        }
    }

    public static function tick()
    {
        if (empty(self::$_tasks)) { //如果没有任务
            \pcntl_alarm(0);    //取消alarm闹钟信号
            return 0;
        }
        $time_now = \time();
        foreach (self::$_tasks as $run_time => $task_data) {
            if ($time_now >= $run_time) {
                foreach ($task_data as $index => $one_task) {
                    $task_func = $one_task[0];
                    $task_args = $one_task[1];
                    $persistent = $one_task[2];
                    $time_interval = $one_task[3];
                    try {
                        \call_user_func_array($task_func, $task_args);
                    }catch (\Exception $e) {
                        Worker::safeEcho($e);
                    }
                    //持续的 非一次的任务
                    if ($persistent && !empty(self::$_status[$index])) {
                        $new_run_time= \time() + $time_interval;
                        self::$_tasks[$new_run_time] = self::$_tasks[$new_run_time] ?? [];
                        self::$_tasks[$new_run_time][$index] = [$task_func, (array)$task_args, $persistent, $time_interval];
                    }
                }
                unset(self::$_tasks[$run_time]);
            }
        }
    }


}