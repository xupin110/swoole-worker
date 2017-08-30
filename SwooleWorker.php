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

    public static $workerNum = 1;

    public static $workerName = 'none';

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
     * The PID of master process.
     *
     * @var int
     */
    protected static $_masterPid;

    /**
     * All worker porcesses pid.
     * The format is like this [pid=>pid, pid=>pid, ..]
     *
     * @var array
     */
    protected static $_pids = [];

    /**
     * All worker processes waiting for restart.
     * The format is like this [pid=>pid, pid=>pid].
     *
     * @var array
     */
    protected static $_pidsToRestart = [];

    /**
     * The file to store status info of current worker process.
     *
     * @var string
     */
    protected static $_statusFile = '/tmp/swoole.status.log';

    /**
     * Current status.
     *
     * @var int
     */
    protected static $_status = self::STATUS_STARTING;

    /**
     * Start file.
     *
     * @var string
     */
    protected static $_startFile = '';

    protected static $_onWorkerStart = null;

    protected static $_onWorkerStop = null;

    protected static $_onWorkerReload = null;

    /**
     * Status starting.
     *
     * @var int
     */
    const STATUS_STARTING = 1;
    /**
     * Status running.
     *
     * @var int
     */
    const STATUS_RUNNING = 2;
    /**
     * Status shutdown.
     *
     * @var int
     */
    const STATUS_SHUTDOWN = 4;
    /**
     * Status reloading.
     *
     * @var int
     */
    const STATUS_RELOADING = 8;
    /**
     * After sending the restart command to the child process KILL_WORKER_TIMER_TIME seconds,
     * if the process is still living then forced to kill.
     *
     * @var int
     */
    const KILL_WORKER_TIMER_TIME = 3;

    public function __construct($config = [])
    {
        if (!extension_loaded('swoole')) {
            throw  new \Exception('require swoole extension!!!');
        }
        foreach ($config as $item => $value) {
            switch ($item) {
                case 'worker_num':
                    self::$workerNum = $value;
                    break;
                case 'worker_name':
                    self::$workerName = $value;
                    break;
                case 'daemonize':
                    self::$daemonize = $value;
                    break;
                case 'pid_file':
                    self::$pidFile = $value;
                    break;
                case 'log_file':
                    self::$logFile = $value;
                    break;
                default:
                    throw new \Exception('unknow config field');
                    break;
            }
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
        self::forkAllWorkers();
        self::processWait();
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

        // State.
        self::$_status = self::STATUS_STARTING;

        self::log("SwooleWorker[" . basename(self::$_startFile) . "] Starting ...");

        self::$_masterPid = getmypid();

        // Register shutdown function for checking errors.
        register_shutdown_function(['\ijuniorfu\worker\SwooleWorker', 'checkErrors']);
    }

    /**
     * Fork all worker processes.
     *
     * @return void
     */
    protected static function forkAllWorkers()
    {
        for ($i = 0; $i < self::$workerNum; $i++) {
            self::forkOneWorker();
        }
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
        \swoole_process::signal(SIGPIPE, function () {
        });

    }

    /**
     * Fork one worker process.
     * @return mixed
     */
    protected static function forkOneWorker()
    {
        $process = new \swoole_process(function (\swoole_process $process) {
            $processName = sprintf('SwooleWorker:%s', ' worker process  ' . self::$workerName);
            swoole_set_process_name($processName);
            self::installSignal();

            // Register shutdown function for checking errors.
            register_shutdown_function(['\ijuniorfu\worker\SwooleWorker', 'checkErrors']);

            if (self::$_onWorkerStart) {
                try {
                    call_user_func_array(self::$_onWorkerStart, [$process]);
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
        self::$_pids[$pid] = $pid;
        return $pid;
    }

    /**
     * worker exit if checked master pid was unkilled
     * @param \swoole_process $process
     */
    public static function checkMasterPid(\swoole_process $process)
    {
        if (!\swoole_process::kill(self::$_masterPid, 0)) {
            $process->exit();
        }
    }

    /**
     *  wait process
     */
    protected static function processWait()
    {
        self::$_status = self::STATUS_RUNNING;
        \swoole_process::signal(SIGCHLD, function () {
            //表示子进程已关闭，回收它
            while ($ret = \swoole_process::wait(false)) {

                $pid = $ret['pid'];
                $status = $ret['code'];

                if (isset(self::$_pids[$pid])) {
                    // Exit status.
                    if ($status !== 0) {
                        self::log("worker[pid:$pid] exit with status $status");
                    }
                    // Clear process data.
                    unset(self::$_pids[$pid]);
                }


                // Is still running state then fork a new worker process.
                if (self::$_status !== self::STATUS_SHUTDOWN) {
                    self::forkOneWorker();
                    // If reloading continue.
                    if (isset(self::$_pidsToRestart[$pid])) {
                        unset(self::$_pidsToRestart[$pid]);
                        self::reload();
                    }
                } else {
                    // If shutdown state and all child processes exited then master process exit.
                    if (!self::$_pids) {
                        self::exitAndClearAll();
                    }
                }

            }
        });

        self::log("SwooleWorker[" . basename(self::$_startFile) . "] is running");
    }

    /**
     * Exit current process.
     *
     * @return void
     */
    protected static function exitAndClearAll()
    {
        @unlink(self::$pidFile);
        self::log("SwooleWorker[" . basename(self::$_startFile) . "] has been stopped");
        exit(0);
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
            default:
                break;
        }
    }

    /**
     *  stop
     */
    public static function stop()
    {
        $pid = getmypid();
        // For master process.
        if ($pid == self::$_masterPid) {

            self::$_status = self::STATUS_SHUTDOWN;

            self::log("SwooleWorker[" . basename(self::$_startFile) . "] Stopping ...");

            foreach (self::$_pids as $workerPid) {
                \swoole_process::kill($workerPid, SIGINT);
                \Swoole\Timer::after(self::KILL_WORKER_TIMER_TIME * 1000, function () use ($workerPid) {
                    if (\swoole_process::kill($workerPid, 0)) {
                        \swoole_process::kill($workerPid, SIGKILL);
                    }
                });
            }

            // Remove statistics file.
            if (is_file(self::$_statusFile)) {
                @unlink(self::$_statusFile);
            }

        } else {// For child processes.
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
            exit(0);
        }
    }

    /**
     *  reload
     */
    public static function reload()
    {
        $pid = getmypid();
        if ($pid == self::$_masterPid) {
            // Set reloading state.
            if (self::$_status !== self::STATUS_RELOADING && self::$_status !== self::STATUS_SHUTDOWN) {
                self::log("SwooleWorker[" . basename(self::$_startFile) . "] reloading");
                self::$_status = self::STATUS_RELOADING;
            }

            foreach (self::$_pidsToRestart as $workerPid) {
                \swoole_process::kill($workerPid, SIGUSR1);
                \Swoole\Timer::after(self::KILL_WORKER_TIMER_TIME * 1000, function () use ($workerPid) {
                    if (\swoole_process::kill($workerPid, 0)) {
                        \swoole_process::kill($workerPid, SIGKILL);
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
                "pid\tmemory\tworker_name" . "\n", FILE_APPEND);

            $mem = str_pad(round(memory_get_usage(true) / (1024 * 1024), 2) . "M", 7);
            $worker_status_str = $pid . "\t" . $mem . "\t" . 'master process' . "\n";
            file_put_contents(self::$_statusFile, $worker_status_str, FILE_APPEND);

            chmod(self::$_statusFile, 0722);
            foreach (self::$_pids as $worker_pid) {
                \swoole_process::kill($worker_pid, SIGUSR2);
            }
            return;
        }

        $mem = str_pad(round(memory_get_usage(true) / (1024 * 1024), 2) . "M", 7);
        $worker_status_str = $pid . "\t" . $mem . "\t" . self::$workerName . "\n";
        file_put_contents(self::$_statusFile, $worker_status_str, FILE_APPEND);
    }

    /**
     * Check errors when current process exited.
     *
     * @return void
     */
    public static function checkErrors()
    {
        $fatalErrors = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (self::STATUS_SHUTDOWN != self::$_status) {
            $errors = error_get_last();
            if ($errors) {
                $error_msg = 'SwooleWorker[' . posix_getpid() . '] process terminated with ';
                $error_msg .= self::getErrorType($errors['type']) . " \"{$errors['message']} in {$errors['file']} on line {$errors['line']}\"";
                self::log($error_msg);
            }
        }
    }

    /**
     * Get error message by error code.
     *
     * @param integer $type
     * @return string
     */
    protected static function getErrorType($type)
    {
        switch ($type) {
            case E_ERROR: // 1 //
                return 'E_ERROR';
            case E_WARNING: // 2 //
                return 'E_WARNING';
            case E_PARSE: // 4 //
                return 'E_PARSE';
            case E_NOTICE: // 8 //
                return 'E_NOTICE';
            case E_CORE_ERROR: // 16 //
                return 'E_CORE_ERROR';
            case E_CORE_WARNING: // 32 //
                return 'E_CORE_WARNING';
            case E_COMPILE_ERROR: // 64 //
                return 'E_COMPILE_ERROR';
            case E_COMPILE_WARNING: // 128 //
                return 'E_COMPILE_WARNING';
            case E_USER_ERROR: // 256 //
                return 'E_USER_ERROR';
            case E_USER_WARNING: // 512 //
                return 'E_USER_WARNING';
            case E_USER_NOTICE: // 1024 //
                return 'E_USER_NOTICE';
            case E_STRICT: // 2048 //
                return 'E_STRICT';
            case E_RECOVERABLE_ERROR: // 4096 //
                return 'E_RECOVERABLE_ERROR';
            case E_DEPRECATED: // 8192 //
                return 'E_DEPRECATED';
            case E_USER_DEPRECATED: // 16384 //
                return 'E_USER_DEPRECATED';
        }
        return "";
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
        file_put_contents((string)self::$logFile, date('Y-m-d H:i:s') . ' ' . 'pid:' . posix_getpid() . ' ' . $msg, FILE_APPEND | LOCK_EX);
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