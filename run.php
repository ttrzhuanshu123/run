<?PHP
define('SYSPATH',__DIR__.'/');
require 'classes/cli.php';
require 'classes/arr.php';

$helpdoc = <<< EOT
php run.php  --n=<NUMBER OF PROCESS> --e=<EXECUTABLE>

EOT;
set_time_limit(0);

$g_run_cycles = 0;
$g_run = true;
$g_reload = false;
declare(ticks = 30);
$g_options = [];
$g_options = Cli::options('e','n');
$g_num_process = Arr::get($g_options,'n');
if(!$g_num_process){
	die($helpdoc);
}else{
	define('WANT_PROCESSORS', $g_num_process);
}

$g_executable = Arr::get($g_options,'e');
if(!$g_executable){
	die($helpdoc);
}else{
	define('PROCESSOR_EXECUTABLE', $g_executable);
}

echo 'Starting '.posix_getpid().PHP_EOL;

function signal_handler($signal) {
    switch($signal) {
    case SIGINT:
    case SIGTERM :
        global $g_run;
        $g_run = false;
		echo posix_getpid().' Catch SIGTERM/SIGINT'.PHP_EOL;
        break;
    case SIGHUP  :
        global $g_reload;
        $g_reload = true;
		echo posix_getpid().' Catch SIGHUP'.PHP_EOL;
        break;
	default:
		echo posix_getpid().' Catch Signal='.$signal.PHP_EOL;
    }   
}

pcntl_signal(SIGTERM, 'signal_handler');
pcntl_signal(SIGHUP, 'signal_handler');
pcntl_signal(SIGINT, 'signal_handler');

function spawn_processor() {
    $pid = pcntl_fork();
    if($pid) {
        global $processors;
        $processors[] = $pid;
    } else {
        if(posix_setsid() == -1)
            die("Forked process could not detach from terminal\n");
        fclose(stdin);//关闭屏幕输入
        fclose(stdout);//关闭屏幕输出
        fclose(stderr);//关闭屏幕报错
        pcntl_exec(PROCESSOR_EXECUTABLE);//执行脚本
        die('Failed to fork ' . PROCESSOR_EXECUTABLE . "\n");
    }
}

function spawn_processors() {
    global $processors;
    if($processors)
        kill_processors();
    $processors = array();
    for($ix = 0; $ix < WANT_PROCESSORS; $ix++)
        spawn_processor();
}

function kill_processors() {
    global $processors;
    foreach($processors as $processor)
        posix_kill($processor, SIGTERM);
    foreach($processors as $processor)
        pcntl_waitpid($processor);
    unset($processors);
}

function check_processors() {
    global $processors;
    $valid = array();
    foreach($processors as $processor) {
        pcntl_waitpid($processor, $status, WNOHANG);
        if(posix_getsid($processor))
            $valid[] = $processor;
    }
    $processors = $valid;
    if(count($processors) > WANT_PROCESSORS) {
        for($ix = count($processors) - 1; $ix >= WANT_PROCESSORS; $ix--)
            posix_kill($processors[$ix], SIGTERM);
        for($ix = count($processors) - 1; $ix >= WANT_PROCESSORS; $ix--)
            pcntl_waitpid($processors[$ix]);
        //why not unset($processors[$ix]) ??
    } elseif(count($processors) < WANT_PROCESSORS) {
        for($ix = count($processors); $ix < WANT_PROCESSORS; $ix++)
            spawn_processor();
    }
}

spawn_processors();

while($g_run) {
    $g_run_cycles++;
    if($g_reload) {
        $g_reload = false;
        kill_processors();
        spawn_processors();
    } else {
        check_processors();
    }
    usleep(150000);
	pcntl_signal_dispatch();
}
kill_processors();
pcntl_wait();
