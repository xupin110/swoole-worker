<?php

/**
 * Created by PhpStorm.
 * User: jun
 * Date: 2017/7/17
 * Time: 10:07
 */
class SwooleWorker
{


    public $workerNum = 1;

    public $daemon = false;

    public $name;

    public $onWorkerStart;

    public $masterPid;

    public $workers = [];

    public function __construct()
    {

    }

    public function start()
    {
        try {
            if ($this->daemon) {
                swoole_process::daemon();
            }
            swoole_set_process_name(sprintf('SwooleWorker:%s', ' master process  start_file=' . realpath($_SERVER['PHP_SELF'])));
        } catch (\Exception $e) {
            die('ALL ERROR: ' . $e->getMessage());
        }
        $this->masterPid = posix_getpid();
        for ($i = 0; $i < $this->workerNum; $i++) {
            $this->createProcess($i);
        }
        $this->processWait();
    }

    public function createProcess($index)
    {
        $process = new swoole_process(function (swoole_process $process) use ($index) {
            swoole_set_process_name(sprintf('SwooleWorker:%s', ' worker process  ' . $this->name));
            if ($this->onWorkerStart) {
                $func = $this->onWorkerStart;
                $func($process, $index);
            }
        }, false, false);
        $pid = $process->start();
        $this->workers[$index] = $pid;
        return $pid;
    }

    public function rebootProcess($ret)
    {
        $pid = $ret['pid'];
        $index = array_search($pid, $this->workers);
        if ($index !== false) {
            $index = intval($index);
            $newPid = $this->createProcess($index);
            return $newPid;
        } else {
            throw new \Exception('rebootProcess Error: no pid');
        }
    }

    public function checkMasterPid(swoole_process $worker)
    {
        if (!swoole_process::kill($this->masterPid, 0)) {
            $worker->exit();
        }
    }

    public function processWait()
    {
        while (1) {
            if (count($this->workers)) {
                $ret = swoole_process::wait();
                if ($ret) {
                    $this->rebootProcess($ret);
                }
            } else {
                break;
            }
        }
    }

}