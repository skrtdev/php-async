<?php
declare(strict_types=1);
#declare(ticks=1);

namespace skrtdev\async;

use Closure;

class Pool{

    protected int $max_childs;
    protected bool $kill_childs;
    protected array $childs = [];
    protected array $queue = [];
    protected int $pid;
    public static ?int $cores_count = null;
    protected static int $last_tick = 0;
    private bool $is_parent = true;
    private bool $is_resolving_queue = false;
    private bool $need_tick = true;

    public function __construct(?int $max_childs = null, bool $kill_childs = true)
    {
        if(!extension_loaded("pcntl")){
            throw new MissingExtensionException("PCNTL Extension is missing in your PHP build");
        }
        $this->pid = getmypid();
        $this->max_childs = $max_childs ?? (self::getCoresCount() ?? 1) * 10;
        $this->kill_childs = $kill_childs;

        register_tick_function([$this, "tick"]);
        pcntl_signal(SIGCHLD, SIG_IGN); // ignores the SIGCHLD signal
    }

    public function checkChilds()
    {
        self::breakpoint("checkChilds()");
        $removed = 0;
        foreach ($this->childs as $key => $child) {
            if(!self::isProcessRunning($child)){
                unset($this->childs[$key]);
                $removed++;
            }
        }
        if($removed === 0){
            self::breakpoint("CheckChilds didn't remove any child");
            return false;
        }
        else{
            self::breakpoint("CheckChilds removed $removed childs");
            return true;
        };
    }

    public function enqueue(Closure $closure, array $args): void
    {
        $this->queue[] = fn() => $closure($args);
        // TODO enqueue args
    }

    protected function _parallel(Closure $closure, string $process_title = null, ...$args)
    {
        self::breakpoint("started a parallel");
        self::breakpoint("parallel can be done: current childs: ".count($this->childs)."/".$this->max_childs);
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new CouldNotForkException("Pool could not fork");
        }
        elseif($pid){
            // we are the parent
            $this->childs[] = $pid;
            self::breakpoint("child started");
            pcntl_wait($status, WNOHANG);
        }
        else{
            // we are the child
            $this->is_parent = false;
            if (!$this->kill_childs) {
                pcntl_signal(SIGINT, SIG_IGN);
            }
            if(isset($process_title)){
                @cli_set_process_title($process_title);
            }
            $closure($args);
            exit;
        }
    }

    public function parallel(Closure $closure, string $process_title = null, ...$args)
    {
        if(!empty($this->queue)){
            self::breakpoint("resolving queue before parallel()");
            if(!$this->resolveQueue()){
                self::breakpoint("enqueueing because there is a queue");
                return $this->enqueue($closure, $args);
            }
            return false;
        }
        elseif(count($this->childs) > $this->max_childs){
            if(!$this->checkChilds()){
                self::breakpoint("enqueueing because of max reached (tried checkChilds but no results)");
                return $this->enqueue($closure, $args);
            }
        }
        elseif(count($this->childs) > $this->max_childs/2){
            $this->checkChilds();
        }
        return $this->_parallel($closure, $process_title, ...$args);
    }

    public function resolveQueue()
    {
        if($this->is_resolving_queue) return;
        $this->is_resolving_queue = true;

        if(count($this->childs) >= $this->max_childs){
            self::breakpoint("resolveQueue() -> too many childs, trying to remove...");
            self::breakpoint("check childs from resolveQueue()");
            $this->checkChilds();
        }

        foreach ($this->queue as $key => $closure) {
            if(count($this->childs) < $this->max_childs){
                unset($this->queue[$key]);
                self::breakpoint("resolveQueue() is resolving n. $key");
                if($this->_parallel($closure)) break;
            }
            else{
                self::breakpoint("resolveQueue() can't resolve, too many childs");
                break;
                self::breakpoint("check childs from resolveQueue()");
                $this->checkChilds();
            }
        }
        if(empty($this->queue)){
            self::breakpoint("queue is empty");
        }

        $this->is_resolving_queue = false;
        return empty($this->queue);

    }

    public function __destruct()
    {
        if($this->is_parent){
            $this->need_tick = false;
            self::breakpoint("triggered destructor");
            $this->wait();
        }
    }

    public static function getCoresCount(): ?int
    {
        if(isset(self::$cores_count) && self::$cores_count === 0) return null;

        if (defined('PHP_WINDOWS_VERSION_MAJOR')){
    		$str = trim(shell_exec('wmic cpu get NumberOfCores 2>&1'));
    		if (!preg_match('/(\d+)/', $str, $matches)) {
    			$cores_count = null;
    		}
    		$cores_count = (int) $matches[1];
    	}
    	$ret = @shell_exec('nproc 2> /dev/null');
    	if (is_string($ret)) {
    		$ret = trim($ret);
    		if (false !== ($tmp = filter_var($ret, FILTER_VALIDATE_INT))){
    			$cores_count = $tmp;
    		}
    	}
    	if (is_readable('/proc/cpuinfo 2> /dev/null')) {
    		$cpuinfo = file_get_contents('/proc/cpuinfo');
    		$count = substr_count($cpuinfo, 'processor');
    		if ($count > 0) {
    			$cores_count = $count;
    		}
    	}

        self::$cores_count = $cores_count ?? 0;
        return $cores_count ?? null;
    }

    public static function breakpoint($value){
        return;
        usleep(5000);
        print($value.PHP_EOL);
    }

    public static function isProcessRunning($pid): bool
    {
        return posix_getpgid($pid) !== false;
    }

    public function tick()
    {
        if($this->is_parent && $this->need_tick && self::$last_tick !== time()){
            #print("tick".PHP_EOL);
            self::$last_tick = time();
            if(!$this->is_resolving_queue) $this->resolveQueue();
        }
    }

    public function hasQueue(): bool
    {
        return !empty($this->queue);
    }

    public function hasChilds(): bool
    {
        return !empty($this->childs);
    }

    public function waitQueue(): void
    {
        while($this->hasQueue()){
            self::breakpoint("queue is not empty");
            $this->resolveQueue();
            usleep(10000);
        }
    }

    public function waitChilds(): void
    {
        while($this->hasChilds()){
            self::breakpoint("there are still childs");
            $this->checkChilds();
            usleep(10000);
        }
    }

    public function wait(): void
    {
        $this->waitQueue();
        $this->waitChilds();
    }
}


?>
