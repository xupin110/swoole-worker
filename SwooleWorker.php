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

    public $name = 'none';

    public static $daemonize = true;

    /**
     * The file to store master process PID.
     *
     * @var string
     */
    public static $pidFile = '';


    /**
     * Log file.
     *
     * @var mixed
     */
    public static $logFile = '';

    /**
     * After sending the restart command to the child process KILL_WORKER_TIMER_TIME seconds,
     * if the process is still living then forced to kill.
     *
     * @var int
     */
    const KILL_WORKER_TIMER_TIME = 3;

    protected static $_masterPid;

    protected static $_pids = [];

    protected static $_pidsToRestart = [];

    protected static $_statusFile = '/tmp/swoole.status.log';

    /**
     * Start file.
     *
     * @var string
     */
    protected static $_startFile = '';

    protected static $_onWorkerStart = null;

    protected static $_onWorkerStop = null;

    protected static $_onWorkerReload = null;

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

        self::init();
        self::installSignal();
        self::saveMasterPid();

        for ($i = 0; $i < $this->num; $i++) {
            $this->createProcess($i);
        }
        $this->processWait();
    }

    /**
     * Init.
     *
     * @return void
     */
    public static function init()
    {
        try {
            if (self::$daemonize) {
                \swoole_process::daemon();
            }
            $masterProcessName = sprintf('SwooleWorker:%s', ' master process  start_file=' . realpath($_SERVER['PHP_SELF']));
            swoole_set_process_name($masterProcessName);
        } catch (\Exception $e) {
            die('ALL ERROR: ' . $e->getMessage());
        }

        // Start file.
        self::$_startFile = realpath($_SERVER['PHP_SELF']);
        // Pid file.
        if (empty(self::$pidFile)) {
            self::$pidFile = __DIR__ . "/../" . str_replace('/', '_', self::$_startFile) . ".pid";
        }

        // Log file.
        if (empty(self::$logFile)) {
            self::$logFile = __DIR__ . "/../swoole.worker.log";
        }
        $log_file = (string)self::$logFile;
        if (!is_file($log_file)) {
            touch($log_file);
            chmod($log_file, 0622);
        }

        self::$_masterPid = getmypid();
    }

    /**
     * Save pid.
     *
     * @throws \Exception
     */
    protected static function saveMasterPid()
    {
        self::$_masterPid = getmypid();
        if (false === @file_put_contents(self::$pidFile, self::$_masterPid)) {
            throw new \Exception('can not save pid to ' . self::$pidFile);
        }
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

            if (self::$_onWorkerStart) {
                try {
                    call_user_func_array(self::$_onWorkerStart, [$process, $index]);
                } catch (\Exception $e) {
                    self::log($e);
                    // Avoid rapid infinite loop exit.
                    sleep(1);
                    exit(250);
                } catch (\Error $e) {
                    self::log($e);
                    // Avoid rapid infinite loop exit.
                    sleep(1);
                    exit(250);
                }
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
     *  worker event callback
     * @param $event
     * @param $callback
     * @throws \Exception
     */
    public function on($event, $callback)
    {
        switch ($event) {
            case 'WorkerStart' :
                self::$_onWorkerStart = $callback;
                break;
            case 'WorkerStop' :
                self::$_onWorkerStop = $callback;
                break;
            case 'WorkerReload' :
                self::$_onWorkerReload = $callback;
                break;
            default :
                throw new \Exception("unknow event");
                break;
        }
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
        if ($pid != self::$_masterPid) {
            if (self::$_onWorkerStop) {
                try {
                    call_user_func(self::$_onWorkerStop);
                } catch (\Exception $e) {
                    self::log($e);
                    // Avoid rapid infinite loop exit.
                    sleep(1);
                    exit(250);
                } catch (\Error $e) {
                    self::log($e);
                    // Avoid rapid infinite loop exit.
                    sleep(1);
                    exit(250);
                }
            }
        } else {
            self::log("SwooleWorker[" . basename(self::$_startFile) . "] Stopping ...");
        }
        \swoole_process::kill($pid, SIGTERM);
        \Swoole\Timer::after(self::KILL_WORKER_TIMER_TIME * 1000, function () use ($pid) {
            if (\swoole_process::kill($pid, 0)) {
                \swoole_process::kill($pid, SIGKILL);
            }
        });
    }

    /**
     *  reload
     */
    public static function reload()
    {
        $pid = getmypid();
        if ($pid == self::$_masterPid) {
            self::log("SwooleWorker[" . basename(self::$_startFile) . "] reloading");
            foreach (self::$_pidsToRestart as $index => $pid) {
                \swoole_process::kill($pid, SIGUSR1);
                \Swoole\Timer::after(self::KILL_WORKER_TIMER_TIME * 1000, function () use ($pid) {
                    if (\swoole_process::kill($pid, 0)) {
                        \swoole_process::kill($pid, SIGKILL);
                    }
                });
            }

        } else {
            if (self::$_onWorkerReload) {
                try {
                    call_user_func(self::$_onWorkerReload);
                } catch (\Exception $e) {
                    self::log($e);
                    exit(250);
                } catch (\Error $e) {
                    self::log($e);
                    exit(250);
                }
            }
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


    /**
     * Log.
     *
     * @param string $msg
     * @return void
     */
    public static function log($msg)
    {
        $msg = $msg . "\n";
        if (!self::$daemonize) {
            self::safeEcho($msg);
        }
        file_put_contents((string)self::$logFile, date('Y-m-d H:i:s') . ' ' . 'pid:'. posix_getpid() . ' ' . $msg, FILE_APPEND | LOCK_EX);
    }

    /**
     * Safe Echo.
     *
     * @param $msg
     */
    public static function safeEcho($msg)
    {
        if (!function_exists('posix_isatty') || posix_isatty(STDOUT)) {
            echo $msg;
        }
    }

}