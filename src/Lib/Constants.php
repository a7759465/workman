<?php
//pcre.jit 不稳定 临时禁用  php 7.3的bug导致的,preg_match导致进程终止,目前看php7.3 的jit功能还不稳定。
//
ini_set('pcre.jit', 0);

const WORKERMAN_CONNECT_FAIL = 1;

const WORKERMAN_SEND_FAIL = 2;

const OS_TYPE_LINUX = 'linux';
const OS_TYPE_WINDOWS = 'windows';

if (!class_exists('Error')) {
    class Error extends Exception
    {

    }
}

if (!interface_exists('SessionHandlerInterface')) {
    interface SessionHandlerInterface
    {
        public function close();

        public function destroy($session_id);

        public function gc($maxlifetime);

        public function open($save_path, $session_name);

        public function read($session_id);

        public function write($session, $session_data);
    }
}