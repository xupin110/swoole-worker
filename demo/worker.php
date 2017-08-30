<?php
/**
 * Created by PhpStorm.
 * User: jun
 * Date: 2017/7/17
 * Time: 13:52
 */
use ijuniorfu\worker\SwooleWorker;

require_once (dirname(__DIR__) . '/vendor/autoload.php');

$worker = new SwooleWorker([
    'worker_num' => 6,
    'daemonize' => true,
    'worker_name' => 'haha',
    'pid_file' => '/tmp/swoole.worker.pid',
    'log_file' => '/tmp/swoole.worker.log',
]);
$worker->on('WorkerStart', function ($process) {
    \Swoole\Timer::tick(1000, function () use($process) {
        SwooleWorker::checkMasterPid($process);
        static $timerCount = 0;

        $pid = getmypid();
        file_put_contents('/tmp/swoole.test.log', 'worker pid:' . $pid . ' timerCount:' . $timerCount . PHP_EOL, FILE_APPEND);
        if (++$timerCount >= 100) {
            $process->exit();
        }
    });
});

$worker->run();