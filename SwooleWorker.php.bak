<?php
/**
 * Created by PhpStorm.
 * User: jun
 * Date: 2017/7/17
 * Time: 10:07
 */

namespace ijuniorfu\worker;

use Swoole\Timer;

class SwooleWorker
{

    const VERSION = "1.0.0";

    public $workerNum = 1;

    public $name = 'none';

    public $onWorkerStart;

    public $onWorkerStop;

    public $onWorkerReload;

    public $id;

    public $reloadable = true;

    public $user = '';

    public $group = '';


    public static $daemonize = true;

    public static $onMasterStop;

    public static $onMasterReload;

    /**
     * Stdout file.
     *
     * @var string
     */
    public static $stdoutFile = '/dev/null';

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
    const KILL_WORKER_TIMER_TIME = 2;

    /**
     * All worker instances.
     *
     * @var array
     */
    protected static $_workers;

    /**
     * All worker porcesses pid.
     * The format is like this [worker_id=>[pid=>pid, pid=>pid, ..], ..]
     *
     * @var array
     */
    protected static $_pidMap;

    /**
     * Mapping from PID to worker process ID.
     * The format is like this [worker_id=>[0=>$pid, 1=>$pid, ..], ..].
     *
     * @var array
     */
    protected static $_idMap;

    /**
     * All worker processes waiting for restart.
     * The format is like this [pid=>pid, pid=>pid].
     *
     * @var array
     */
    protected static $_pidsToRestart;

    /**
     * The PID of master process.
     *
     * @var int
     */
    protected static $_masterPid = 0;

    /**
     * Current status.
     *
     * @var int
     */
    protected static $_status = self::STATUS_STARTING;

    /**
     * Maximum length of the worker names.
     *
     * @var int
     */
    protected static $_maxWorkerNameLength = 12;

    /**
     * Maximum length of the process user names.
     *
     * @var int
     */
    protected static $_maxUserNameLength = 12;

    /**
     * The file to store status info of current worker process.
     *
     * @var string
     */
    protected static $_statusFile = '';

    /**
     * Start file.
     *
     * @var string
     */
    protected static $_startFile = '';

    /**
     * Status info of current worker process.
     *
     * @var array
     */
    protected static $_globalStatus = [
        'start_timestamp' => 0,
        'worker_exit_info' => []
    ];

    public function __construct()
    {
        // Save all worker instances.
        $this->workerId                  = spl_object_hash($this);
        self::$_workers[$this->workerId] = $this;
        self::$_pidMap[$this->workerId]  = [];
    }

    /**
     * Check sapi.
     *
     * @return void
     */
    protected static function checkSapiEnv()
    {
        // Only for cli.
        if (php_sapi_name() != "cli") {
            exit("only run in command line mode \n");
        };
        if(!extension_loaded('swoole')){
            exit("swoole extension is needed \n");
        }
    }

    /**
     * run worker
     */
    public static function runAll()
    {
        self::init();
        self::daemonize();
        self::initWorkers();
        self::installSignal();
        self::saveMasterPid();
        self::forkWorkers();
        self::resetStd();
        self::monitorWorkers();
    }

    /**
     * Run as deamon mode.
     *
     * @throws \Exception
     */
    protected static function daemonize()
    {
        if (self::$daemonize) {
            \swoole_process::daemon();
        }
    }

    /**
     * Init.
     *
     * @return void
     */
    protected static function init()
    {
        // Start file.
        self::$_startFile = realpath($_SERVER['PHP_SELF']);
        // Pid file.
        if (empty(self::$pidFile)) {
            self::$pidFile = __DIR__ . "/../" . str_replace('/', '_', self::$_startFile) . ".pid";
        }
        // Log file.
        if (empty(self::$logFile)) {
            self::$logFile = __DIR__ . '/../swoole.worker.log';
        }
        $log_file = (string)self::$logFile;
        if (!is_file($log_file)) {
            touch($log_file);
            chmod($log_file, 0622);
        }
        // State.
        self::$_status = self::STATUS_STARTING;
        // For statistics.
        self::$_globalStatus['start_timestamp'] = time();
        self::$_statusFile                      = sys_get_temp_dir() . '/swoole.worker.status';
        // Process title.
        $masterProcessName = sprintf('SwooleWorker:%s', ' master process  start_file=' . self::$_startFile);
        swoole_set_process_name($masterProcessName);

        self::initId();
    }

    /**
     * Init idMap.
     * return void
     */
    protected static function initId()
    {
        foreach (self::$_workers as $worker_id => $worker) {
            $new_id_map = [];
            for($key = 0; $key < $worker->workerNum; $key++) {
                $new_id_map[$key] = isset(self::$_idMap[$worker_id][$key]) ? self::$_idMap[$worker_id][$key] : 0;
            }
            self::$_idMap[$worker_id] = $new_id_map;
        }
    }

    /**
     * Get unix user of current porcess.
     *
     * @return string
     */
    protected static function getCurrentUser()
    {
        $user_info = posix_getpwuid(posix_getuid());
        return $user_info['name'];
    }

    /**
     * Init All worker instances.
     *
     * @return void
     */
    protected static function initWorkers()
    {
        foreach (self::$_workers as $worker) {
            // Worker name.
            if (empty($worker->name)) {
                $worker->name = 'none';
            }

            // Get maximum length of worker name.
            $worker_name_length = strlen($worker->name);
            if (self::$_maxWorkerNameLength < $worker_name_length) {
                self::$_maxWorkerNameLength = $worker_name_length;
            }

            // Get unix user of the worker process.
            if (empty($worker->user)) {
                $worker->user = self::getCurrentUser();
            } else {
                if (posix_getuid() !== 0 && $worker->user != self::getCurrentUser()) {
                    self::log('Warning: You must have the root privileges to change uid and gid.');
                }
            }

            // Get maximum length of unix user name.
            $user_name_length = strlen($worker->user);
            if (self::$_maxUserNameLength < $user_name_length) {
                self::$_maxUserNameLength = $user_name_length;
            }
        }
    }

    /**
     * Fork some worker processes.
     *
     * @return void
     */
    protected static function forkWorkers()
    {
        foreach (self::$_workers as $worker) {
            if (self::$_status === self::STATUS_STARTING) {
                if (empty($worker->name)) {
                    $worker->name = 'none';
                }
                $worker_name_length = strlen($worker->name);
                if (self::$_maxWorkerNameLength < $worker_name_length) {
                    self::$_maxWorkerNameLength = $worker_name_length;
                }
            }

            $worker->workerNum = $worker->workerNum <= 0 ? 1 : $worker->workerNum;
            while (count(self::$_pidMap[$worker->workerId]) < $worker->workerNum) {
                static::forkOneWorker($worker);
            }
        }
    }

    /**
     * Run worker instance.
     *
     * @return void
     */
    public function run()
    {
        //Update process state.
        self::$_status = self::STATUS_RUNNING;

        // Reinstall signal.
        self::reinstallSignal();

        // Try to emit onWorkerStart callback.
        if ($this->onWorkerStart) {
            try {
                call_user_func($this->onWorkerStart, $this);
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
    }

    /**
     * Stop current worker instance.
     *
     * @return void
     */
    public function stop()
    {
        // Try to emit onWorkerStop callback.
        if ($this->onWorkerStop) {
            try {
                call_user_func($this->onWorkerStop, $this);
            } catch (\Exception $e) {
                self::log($e);
                exit(250);
            } catch (\Error $e) {
                self::log($e);
                exit(250);
            }
        }
    }

    /**
     * Get all worker instances.
     *
     * @return array
     */
    public static function getAllWorkers()
    {
        return self::$_workers;
    }

    /**
     * Get all pids of worker processes.
     *
     * @return array
     */
    protected static function getAllWorkerPids()
    {
        $pid_array = [];
        foreach (self::$_pidMap as $worker_pid_array) {
            foreach ($worker_pid_array as $worker_pid) {
                $pid_array[$worker_pid] = $worker_pid;
            }
        }
        return $pid_array;
    }

    /**
     * Fork one worker process.
     *
     * @param SwooleWorker $worker
     * @throws \Exception
     */
    protected static function forkOneWorker($worker)
    {
        // Get available worker id.
        $id = self::getId($worker->workerId, 0);
        if ($id === false) {
            return;
        }
        $process = new \swoole_process(function(\swoole_process $process) use($worker,$id){
            if (self::$_status === self::STATUS_STARTING) {
                self::resetStd();
            }
            self::$_pidMap  = [];
            self::$_workers = [$worker->workerId => $worker];

            $process->name('SwooleWorker: worker process  ' . $worker->name);
            $worker->setUserAndGroup();
            $worker->id = $id;
            $worker->run();
        });
        $pid = $process->start();
        self::$_pidMap[$worker->workerId][$pid] = $pid;
        self::$_idMap[$worker->workerId][$id]   = $pid;
    }

    /**
     * Get worker id.
     *
     * @param int $worker_id
     * @param int $pid
     */
    protected static function getId($worker_id, $pid)
    {
        return array_search($pid, self::$_idMap[$worker_id]);
    }

    /**
     * Set unix user and group for current process.
     *
     * @return void
     */
    public function setUserAndGroup()
    {
        if($this->user == ''){
            return ;
        }
        // Get uid.
        $user_info = posix_getpwnam($this->user);
        if (!$user_info) {
            self::log("Warning: User {$this->user} not exsits");
            return;
        }
        $uid = $user_info['uid'];
        // Get gid.
        if ($this->group) {
            $group_info = posix_getgrnam($this->group);
            if (!$group_info) {
                self::log("Warning: Group {$this->group} not exsits");
                return;
            }
            $gid = $group_info['gid'];
        } else {
            $gid = $user_info['gid'];
        }
        // Set uid and gid.
        if ($uid != posix_getuid() || $gid != posix_getgid()) {
            if (!posix_setgid($gid) || !posix_initgroups($user_info['name'], $gid) || !posix_setuid($uid)) {
                self::log("Warning: change gid or uid fail.");
            }
        }
    }

    /**
     * Redirect standard input and output.
     *
     * @throws \Exception
     */
    public static function resetStd()
    {
        if (!self::$daemonize) {
            return;
        }
        global $STDOUT, $STDERR;
        $handle = fopen(self::$stdoutFile, "a");
        if ($handle) {
            unset($handle);
            @fclose(STDOUT);
            @fclose(STDERR);
            $STDOUT = fopen(self::$stdoutFile, "a");
            $STDERR = fopen(self::$stdoutFile, "a");
        } else {
            throw new \Exception('can not open stdoutFile ' . self::$stdoutFile);
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
        \swoole_process::signal(SIGPIPE, function () {});

    }

    /**
     * Reinstall signal handler
     */
    public static function reinstallSignal()
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
     * Signal handler.
     *
     * @param int $signal
     */
    public static function signalHandler($signal)
    {
        switch ($signal) {
            // Stop.
            case SIGINT:
                self::stopAll();
                break;
            // Reload.
            case SIGUSR1:
                self::$_pidsToRestart = self::getAllWorkerPids();
                self::reload();
                break;
            // Show status.
            case SIGUSR2:
                self::status();
                break;
        }
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     */
    protected static function monitorWorkers()
    {
        self::$_status = self::STATUS_RUNNING;
        \swoole_process::signal(SIGCHLD, function(){
            //表示子进程已关闭，回收它
            while($ret =  \swoole_process::wait(false)) {
                $pid = $ret['pid'];
                $status = $ret['code'];
                // Find out witch worker process exited.
                foreach (self::$_pidMap as $worker_id => $worker_pid_array) {
                    if (isset($worker_pid_array[$pid])) {
                        $worker = self::$_workers[$worker_id];
                        // Exit status.
                        if ($status !== 0) {
                            self::log("worker[" . $worker->name . ":$pid] exit with status $status");
                        }
                        // For Statistics.
                        if (!isset(self::$_globalStatus['worker_exit_info'][$worker_id][$status])) {
                            self::$_globalStatus['worker_exit_info'][$worker_id][$status] = 0;
                        }
                        self::$_globalStatus['worker_exit_info'][$worker_id][$status]++;
                        // Clear process data.
                        unset(self::$_pidMap[$worker_id][$pid]);
                        // Mark id is available.
                        $id                            = self::getId($worker_id, $pid);
                        self::$_idMap[$worker_id][$id] = 0;
                        break;
                    }
                }
                // Is still running state then fork a new worker process.
                if (self::$_status !== self::STATUS_SHUTDOWN) {
                    self::forkWorkers();
                    // If reloading continue.
                    if (isset(self::$_pidsToRestart[$pid])) {
                        unset(self::$_pidsToRestart[$pid]);
                        self::reload();
                    }
                } else {
                    // If shutdown state and all child processes exited then master process exit.
                    if (!self::getAllWorkerPids()) {
                        self::exitAndClearAll();
                    }
                }
            }
        });
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
        if (self::$onMasterStop) {
            call_user_func(self::$onMasterStop);
        }
        exit(0);
    }

    /**
     * worker exit if checked master pid was unkilled
     */
    public function checkMasterPid()
    {
        if (!\swoole_process::kill(self::$_masterPid, 0)) {
            self::stopAll();
        }
    }


    /**
     *  stop
     */
    public static function stopAll()
    {
        self::$_status = self::STATUS_SHUTDOWN;
        // For master process.
        if (self::$_masterPid === posix_getpid()) {
            self::log("SwooleWorker[" . basename(self::$_startFile) . "] Stopping ...");
            $worker_pid_array = self::getAllWorkerPids();
            // Send stop signal to all child processes.
            foreach ($worker_pid_array as $worker_pid) {
                \swoole_process::kill($worker_pid, SIGINT);
                \Swoole\Timer::after(self::KILL_WORKER_TIMER_TIME * 1000, function () use($worker_pid) {
                    \swoole_process::kill($worker_pid, SIGKILL);
                });
            }
            // Remove statistics file.
            if (is_file(self::$_statusFile)) {
                @unlink(self::$_statusFile);
            }
        } // For child processes.
        else {
            // Execute exit.
            foreach (self::$_workers as $worker) {
                $worker->stop();
            }
            exit(0);
        }
    }

    /**
     * Execute reload.
     *
     * @return void
     */
    protected static function reload()
    {
        // For master process.
        if (self::$_masterPid === posix_getpid()) {
            // Set reloading state.
            if (self::$_status !== self::STATUS_RELOADING && self::$_status !== self::STATUS_SHUTDOWN) {
                self::log("SwooleWorker[" . basename(self::$_startFile) . "] reloading");
                self::$_status = self::STATUS_RELOADING;
                // Try to emit onMasterReload callback.
                if (self::$onMasterReload) {
                    try {
                        call_user_func(self::$onMasterReload);
                    } catch (\Exception $e) {
                        self::log($e);
                        exit(250);
                    } catch (\Error $e) {
                        self::log($e);
                        exit(250);
                    }
                    self::initId();
                }
            }
            // Send reload signal to all child processes.
            $reloadable_pid_array = [];
            foreach (self::$_pidMap as $worker_id => $worker_pid_array) {
                $worker = self::$_workers[$worker_id];
                if ($worker->reloadable) {
                    foreach ($worker_pid_array as $pid) {
                        $reloadable_pid_array[$pid] = $pid;
                    }
                } else {
                    foreach ($worker_pid_array as $pid) {
                        // Send reload signal to a worker process which reloadable is false.
                        \swoole_process::kill($pid, SIGUSR1);
                    }
                }
            }
            // Get all pids that are waiting reload.
            self::$_pidsToRestart = array_intersect(self::$_pidsToRestart, $reloadable_pid_array);
            // Reload complete.
            if (empty(self::$_pidsToRestart)) {
                if (self::$_status !== self::STATUS_SHUTDOWN) {
                    self::$_status = self::STATUS_RUNNING;
                }
                return;
            }
            // Continue reload.
            $one_worker_pid = current(self::$_pidsToRestart);
            // Send reload signal to a worker process.
            \swoole_process::kill($one_worker_pid, SIGUSR1);
            // If the process does not exit after self::KILL_WORKER_TIMER_TIME seconds try to kill it.
            Timer::tick(self::KILL_WORKER_TIMER_TIME * 1000, function () use($one_worker_pid) {
                \swoole_process::kill($one_worker_pid, SIGKILL);
            });
        } // For child processes.
        else {
            $worker = current(self::$_workers);
            // Try to emit onWorkerReload callback.
            if ($worker->onWorkerReload) {
                try {
                    call_user_func($worker->onWorkerReload, $worker);
                } catch (\Exception $e) {
                    self::log($e);
                    exit(250);
                } catch (\Error $e) {
                    self::log($e);
                    exit(250);
                }
            }
            if ($worker->reloadable) {
                self::stopAll();
            }
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

            file_put_contents(self::$_statusFile,
                count(self::$_pidMap) . ' workers       ' . count(self::getAllWorkerPids()) . " processes\n",
                FILE_APPEND);
            file_put_contents(self::$_statusFile,
                str_pad('worker_name', self::$_maxWorkerNameLength) . " exit_status     exit_count\n", FILE_APPEND);
            foreach (self::$_pidMap as $worker_id => $worker_pid_array) {
                $worker = self::$_workers[$worker_id];
                if (isset(self::$_globalStatus['worker_exit_info'][$worker_id])) {
                    foreach (self::$_globalStatus['worker_exit_info'][$worker_id] as $worker_exit_status => $worker_exit_count) {
                        file_put_contents(self::$_statusFile,
                            str_pad($worker->name, self::$_maxWorkerNameLength) . " " . str_pad($worker_exit_status,
                                16) . " $worker_exit_count\n", FILE_APPEND);
                    }
                } else {
                    file_put_contents(self::$_statusFile,
                        str_pad($worker->name, self::$_maxWorkerNameLength) . " " . str_pad(0, 16) . " 0\n",
                        FILE_APPEND);
                }
            }

            file_put_contents(self::$_statusFile,
                "---------------------------------------PROCESS STATUS-------------------------------------------\n",
                FILE_APPEND);

            file_put_contents(self::$_statusFile,
                "pid\tmemory  " . "\n", FILE_APPEND);

            chmod(self::$_statusFile, 0722);
            foreach (self::getAllWorkerPids() as $worker_pid) {
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