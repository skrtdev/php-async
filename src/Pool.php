<?php
declare(strict_types=1);
#declare(ticks=10);

namespace skrtdev\async;

use Closure;

class Pool{

    protected int $max_childs;
    protected array $childs = [];
    protected array $queue = [];
    protected int $pid;
    public static ?int $cores_count = null;
    protected static int $last_tick = 0;


    public function __construct(?int $max_childs = null)
    {
        $this->pid = getmypid();
        $max_childs ??= (self::getCoresCount() ?? 1) * 50;
        $this->max_childs = $max_childs;
        #register_tick_function([$this, "resolveQueue"]);
        #register_tick_function([__CLASS__, "tick"]);
    }

    public function checkChilds()
    {
        self::breakpoint("checkChilds()");
        $removed = false;
        foreach ($this->childs as $key => $child) {
            if(!self::isProcessRunning($child)){
                unset($this->childs[$key]);
                self::breakpoint("Removed child n. $key");
                $removed = true;
            }
            else{
                $this->internalParallel();
                if(!self::isProcessRunning($child)){
                    unset($this->childs[$key]);
                    self::breakpoint("Removed child n. $key from retrying");
                    $removed = true;
                }
            }
        }
        if(!$removed){
            self::breakpoint("CheckChilds didn't remove any child");
        }
        return $removed;
    }

    public function enqueue(Closure $closure = null, array $args): void
    {
        $this->queue[] = function () use ($closure, $args) {
            return $closure($args);
        };
        // TODO enqueue args
        #print(PHP_EOL.PHP_EOL."BELLA LA CODA".PHP_EOL.PHP_EOL);
        /*
        self::breakpoint("check childs from enqueue");
        if($this->checkChilds()){
            self::breakpoint("resolveQueue from enqueue (cause checkChilds is succesful)");
            $this->resolveQueue();
        }
        */
    }

    public function internalParallel(){
        $pid = pcntl_fork();
        if ($pid == -1) {
            die('could not fork');
        }
        elseif($pid){
            // we are the parent
            pcntl_wait($status, WNOHANG);
        }
        else{
            // we are the child
            exit;
        }
    }

    protected function _parallel(Closure $closure, ...$args)
    {
        self::breakpoint("started a parallel");
        self::breakpoint("parallel can be done: current childs: ".count($this->childs)."/".$this->max_childs);
        $pid = pcntl_fork();
        if ($pid == -1) {
            die('could not fork');
        }
        elseif($pid){
            // we are the parent
            $this->childs[] = $pid;
            self::breakpoint("child started");
            pcntl_wait($status, WNOHANG);
        }
        else{
            // we are the child
            $pid = getmypid();
            $closure($args);
            exit;
        }
    }

    public function parallel(Closure $closure, ...$args)
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
        elseif(count($this->childs) > 1){
            $this->checkChilds();
        }
        return $this->_parallel($closure, ...$args);
    }

    public function resolveQueue()
    {
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
        return empty($this->queue);

    }

    public function __destruct()
    {
        if($this->pid === getmypid()){
            self::breakpoint("triggered destructor");
            while(!empty($this->queue)){
                self::breakpoint("queue is not empty");
                $this->resolveQueue();
            }
            while(!empty($this->childs)){
                self::breakpoint("there are still childs");
                $this->checkChilds();
            }
        }
    }

    public static function getCoresCount()
    {
        if(isset(self::$cores_count)) return self::$cores_count;

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

        self::$cores_count = $cores_count ?? null;
        return self::$cores_count;
    }

    public static function breakpoint($value){
        return;
        usleep(50000);
        print($value.PHP_EOL);
    }

    public static function isProcessRunning($pid) {
        return posix_getpgid($pid);
    }

    public static function tick($value='')
    {
        if(self::$last_tick !== time()){
            print("tick".PHP_EOL);
            self::$last_tick = time();
        }
    }
}


?>
