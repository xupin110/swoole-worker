<?php

/**
 * Created by PhpStorm.
 * User: jun
 * Date: 2017/7/17
 * Time: 10:07
 */
class SwooleWorker
{

    const VERSION = 1.0;

    public $workerNum = 1;

    public $daemon = false;

    public $name;

    public $onWorkerStart;

    public static $masterPid;

    public static $workers = [];

    public static $statusFile = '/tmp/swoole.status.log';

    public function __construct()
    {
        if (!extension_loaded('swoole')) {
            throw  new Exception('require swoole extension!!!');
        }
    }

    /**
     * run worker
     */
    public function run()
    {
        try {
            if ($this->daemon) {
                swoole_process::daemon();
            }
            $masterProcessName = sprintf('SwooleWorker:%s', ' master process  start_file=' . realpath($_SERVER['PHP_SELF']));
            swoole_set_process_name($masterProcessName);
        } catch (\Exception $e) {
            die('ALL ERROR: ' . $e->getMessage());
        }

        $this->installSignal(false);

        self::$masterPid = getmypid();
        for ($i = 0; $i < $this->workerNum; $i++) {
            $this->createProcess($i);
        }
        $this->processWait();
    }

    /**
     * Install signal handler
     * @param bool $async
     */
    public function installSignal($async = false)
    {
        if (!$async) {
            // stop
            pcntl_signal(SIGINT, [$this, 'signalHandler'], false);
            // reload
            pcntl_signal(SIGUSR1, [$this, 'signalHandler'], false);
            // status
            pcntl_signal(SIGUSR2, [$this, 'signalHandler'], false);
            // ignore
            pcntl_signal(SIGPIPE, function () {}, false);
        } else {
            // stop
            swoole_process::signal(SIGINT, [$this, 'signalHandler']);
            // reload
            swoole_process::signal(SIGUSR1, [$this, 'signalHandler']);
            // status
            swoole_process::signal(SIGUSR2, [$this, 'signalHandler']);
            // ignore
            swoole_process::signal(SIGPIPE, function () {});
        }

    }

    /**
     * create process
     * @param $index
     * @return mixed
     */
    public function createProcess($index)
    {
        $process = new swoole_process(function (swoole_process $process) use ($index) {
            $processName = sprintf('SwooleWorker:%s', ' worker process  ' . $this->name);
            swoole_set_process_name($processName);

            $this->installSignal(true);

            if ($this->onWorkerStart) {
                call_user_func_array($this->onWorkerStart, [$process, $index]);
            }
        }, false, false);
        $pid = $process->start();
        self::$workers[$index] = $pid;
        return $pid;
    }

    /**
     * reboot process
     * @param $ret
     * @return mixed
     * @throws Exception
     */
    public function rebootProcess($ret)
    {
        $pid = $ret['pid'];
        $index = array_search($pid, self::$workers);
        if ($index !== false) {
            $index = intval($index);
            $newPid = $this->createProcess($index);
            return $newPid;
        } else {
            throw new \Exception('rebootProcess Error: no pid');
        }
    }

    /**
     * worker exit if checked master pid was unkilled
     * @param swoole_process $worker
     */
    public function checkMasterPid(swoole_process $worker)
    {
        if (!swoole_process::kill(self::$masterPid, 0)) {
            $worker->exit();
        }
    }

    /**
     *  wait process
     */
    public function processWait()
    {
        while (true) {
            pcntl_signal_dispatch();
            if (count(self::$workers)) {
                $ret = swoole_process::wait(false);
                pcntl_signal_dispatch();
                if ($ret) {
                    $this->rebootProcess($ret);
                } else {
                    sleep(1);
                }
            } else {
                break;
            }
        }
    }

    /**
     * signal handler
     * @param $signo
     */
    public function signalHandler($signo)
    {

        switch ($signo) {
            // Stop.
            case SIGINT:
                $this->stop();
                break;
            // Reload.
            case SIGUSR1:
                $this->reload();
                break;
            // Show status.
            case SIGUSR2:
                $this->status();
                break;
        }
    }

    /**
     *  stop
     */
    public function stop()
    {
        $pid = getmypid();
        swoole_process::kill($pid, SIGKILL);
    }

    /**
     *  reload
     */
    public function reload()
    {
        $pid = getmypid();
        if ($pid == self::$masterPid) {
            $unreloadedWorkers = [];
            foreach (self::$workers as $index => $pid) {
                if (!swoole_process::kill($pid, 0)) {
                    $unreloadedWorkers[] = $index;
                } else {
                    swoole_process::kill($pid, SIGKILL);
                }
            }
            if ($unreloadedWorkers) {
                $this->reload();
            }
            return;
        } else {
            $this->stop();
        }
    }

    /**
     *  status
     */
    public function status()
    {
        $pid = getmypid();

        if ($pid == self::$masterPid) {
            $loadavg = function_exists('sys_getloadavg') ? array_map('round', sys_getloadavg(), array(2)) : array('-', '-', '-');
            file_put_contents(self::$statusFile,
                "---------------------------------------GLOBAL STATUS--------------------------------------------\n");

            file_put_contents(self::$statusFile,
                'SwooleWorker version:' . SwooleWorker::VERSION . "          PHP version:" . PHP_VERSION . "\n", FILE_APPEND);

            $load_str = 'load average: ' . implode(", ", $loadavg);
            file_put_contents(self::$statusFile, str_pad($load_str, 33) . "\n", FILE_APPEND);

            file_put_contents(self::$statusFile, count(self::$workers) . " processes\n", FILE_APPEND);

            file_put_contents(self::$statusFile,
                "---------------------------------------PROCESS STATUS-------------------------------------------\n",
                FILE_APPEND);

            file_put_contents(self::$statusFile,
                "pid\tmemory  " . "\n", FILE_APPEND);

            chmod(self::$statusFile, 0722);
            foreach (self::$workers as $worker_pid) {
                swoole_process::kill($worker_pid, SIGUSR2);
            }
            return;
        }

        $mem = str_pad(round(memory_get_usage(true) / (1024 * 1024), 2) . "M", 7);
        $worker_status_str = $pid . "\t" . $mem . " " . "\n";
        file_put_contents(self::$statusFile, $worker_status_str, FILE_APPEND);
    }

}