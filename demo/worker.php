<?php
/**
 * Created by PhpStorm.
 * User: jun
 * Date: 2017/7/17
 * Time: 13:52
 */
use ijuniorfu\worker\SwooleWorker;

$worker = new SwooleWorker();
$worker->num = 5;
$worker->daemon = true;
$worker->name = 'testest';
$worker->onWorkerStart = function ($process, $index) use($worker) {
    \Swoole\Timer::tick(1000, function () use($process, $index, $worker) {
        $worker->checkMasterPid($process);
        static $timerCount = 0;
        file_put_contents('/tmp/swoole.test.log', 'worker index:' . $index . ' timerCount:' . $timerCount . PHP_EOL, FILE_APPEND);
        if (++$timerCount >= 100) {
            $process->exit();
        }
    });
};

$worker->run();