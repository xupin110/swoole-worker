<?php
/**
 * Created by PhpStorm.
 * User: jun
 * Date: 2017/7/17
 * Time: 10:07
 */

namespace ijuniorfu\worker;

class SwooleWorker
{

    const VERSION = "1.0.0";

    public $num = 1;

    public $daemon = false;

    public $name = 'none';

    public $onWorkerStart = null;

    protected static $_masterPid;

    protected static $_pids = [];

    protected static $_pidsToRestart = [];

    protected static $_statusFile = '/tmp/swoole.status.log';

    public function __construct()
    {
        if (!extension_loaded('swoole')) {
            throw  new \Exception('require swoole extension!!!');
        }
    }

    /**
     * run worker
     */
    public function run()
    {
        try {
            if ($this->daemon) {
                \swoole_process::daemon();
            }
            $masterProcessName = sprintf('SwooleWorker:%s', ' master process  start_file=' . realpath($_SERVER['PHP_SELF']));
            swoole_set_process_name($masterProcessName);
        } catch (\Exception $e) {
            die('ALL ERROR: ' . $e->getMessage());
        }

        self::installSignal();

        self::$_masterPid = getmypid();
        for ($i = 0; $i < $this->num; $i++) {
            $this->createProcess($i);
        }
        $this->processWait();
    }

    /**
     * Install signal handler
     */
    public static function installSignal()
    {
        // stop
        \swoole_process::signal(SIGINT, ['\ijuniorfu\worker\SwooleWorker', 'signalHandler']);
        // reload
        \swoole_process::signal(SIGUSR1, ['\ijuniorfu\worker\SwooleWorker', 'signalHandler']);
        // status
        \swoole_process::signal(SIGUSR2, ['\ijuniorfu\worker\SwooleWorker', 'signalHandler']);
        // ignore
        \swoole_process::signal(SIGPIPE, function () {});

    }

    /**
     * create process
     * @param $index
     * @return mixed
     */
    public function createProcess($index)
    {
        $process = new \swoole_process(function (\swoole_process $process) use ($index) {
            $processName = sprintf('SwooleWorker:%s', ' worker process  ' . $this->name);
            swoole_set_process_name($processName);
            self::installSignal();

            if ($this->onWorkerStart) {
                call_user_func_array($this->onWorkerStart, [$process, $index]);
            }
        }, false, false);
        $pid = $process->start();
        self::$_pids[$index] = $pid;
        return $pid;
    }

    /**
     * reboot process
     * @param $ret
     * @return mixed
     * @throws \Exception
     */
    public function rebootProcess($ret)
    {
        $pid = $ret['pid'];
        $index = array_search($pid, self::$_pids);
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
     * @param \swoole_process $worker
     */
    public function checkMasterPid(\swoole_process $worker)
    {
        if (!\swoole_process::kill(self::$_masterPid, 0)) {
            $worker->exit();
        }
    }

    /**
     *  wait process
     */
    public function processWait()
    {
        \swoole_process::signal(SIGCHLD, function () {
            //表示子进程已关闭，回收它
            while ($ret = \swoole_process::wait(false)) {
                $this->rebootProcess($ret);
            }
        });
    }

    /**
     * signal handler
     * @param $signo
     */
    public static function signalHandler($signo)
    {

        switch ($signo) {
            // Stop.
            case SIGINT:
                self::stop();
                break;
            // Reload.
            case SIGUSR1:
                self::$_pidsToRestart = self::$_pids;
                self::reload();
                break;
            // Show status.
            case SIGUSR2:
                self::status();
                break;
        }
    }

    /**
     *  stop
     */
    public static function stop()
    {
        $pid = getmypid();
        \swoole_process::kill($pid, SIGTERM);
        \Swoole\Timer::after(2000, function () use ($pid) {
            \swoole_process::kill($pid, SIGKILL);
        });
    }

    /**
     *  reload
     */
    public static function reload()
    {
        $pid = getmypid();
        if ($pid == self::$_masterPid) {

            foreach (self::$_pidsToRestart as $index => $pid) {
                \swoole_process::kill($pid, SIGUSR1);
                \Swoole\Timer::after(2000, function () use ($pid) {
                    \swoole_process::kill($pid, SIGKILL);
                });
            }

        } else {
            self::stop();
        }
    }

    /**
     *  status
     */
    public static function status()
    {
        $pid = getmypid();

        if ($pid == self::$_masterPid) {
            $loadavg = function_exists('sys_getloadavg') ? array_map('round', sys_getloadavg(), array(2)) : array('-', '-', '-');
            file_put_contents(self::$_statusFile,
                "---------------------------------------GLOBAL STATUS--------------------------------------------\n");

            file_put_contents(self::$_statusFile,
                'SwooleWorker version:' . SwooleWorker::VERSION . "          PHP version:" . PHP_VERSION . "\n", FILE_APPEND);

            $load_str = 'load average: ' . implode(", ", $loadavg);
            file_put_contents(self::$_statusFile, str_pad($load_str, 33) . "\n", FILE_APPEND);

            file_put_contents(self::$_statusFile, count(self::$_pids) . " processes\n", FILE_APPEND);

            file_put_contents(self::$_statusFile,
                "---------------------------------------PROCESS STATUS-------------------------------------------\n",
                FILE_APPEND);

            file_put_contents(self::$_statusFile,
                "pid\tmemory  " . "\n", FILE_APPEND);

            $mem = str_pad(round(memory_get_usage(true) / (1024 * 1024), 2) . "M", 7);
            $worker_status_str = $pid . "\t" . $mem . " " . "\n";
            file_put_contents(self::$_statusFile, $worker_status_str, FILE_APPEND);

            chmod(self::$_statusFile, 0722);
            foreach (self::$_pids as $worker_pid) {
                \swoole_process::kill($worker_pid, SIGUSR2);
            }
            return;
        }

        $mem = str_pad(round(memory_get_usage(true) / (1024 * 1024), 2) . "M", 7);
        $worker_status_str = $pid . "\t" . $mem . " " . "\n";
        file_put_contents(self::$_statusFile, $worker_status_str, FILE_APPEND);
    }

}