<?php declare(strict_types=1);

namespace skrtdev\async;

use Throwable;

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

    protected static self $default_pool;

    /**
     * @throws MissingExtensionException
     */
    public function __construct(?int $max_childs = null, bool $kill_childs = true)
    {
        if(!extension_loaded('pcntl')){
            throw new MissingExtensionException('PCNTL Extension is missing in your PHP build');
        }
        if(!extension_loaded('posix')){
            throw new MissingExtensionException('POSIX Extension is missing in your PHP build');
        }
        $this->pid = getmypid();
        $this->max_childs = $max_childs ?? (self::getCoresCount() ?? 1) * 10;
        $this->kill_childs = $kill_childs;

        register_tick_function([$this, 'tick']);

        pcntl_async_signals(true);

        pcntl_signal(SIGCHLD, function () {
            while (true) {
                $pid = pcntl_waitpid(-1, $processState, WNOHANG | WUNTRACED);

                if ($pid <= 0) {
                    break;
                }

                foreach ($this->childs as $key => $child) {
                    if($pid === $child){
                        self::breakpoint('removed child from signal handler');
                        unset($this->childs[$key]);
                        break;
                    }
                }
            }
        });
    }

    protected function checkChilds(): bool
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
        }
    }


    public function enqueue(callable $callable, array $args = []): void
    {
        $this->queue[] = [$callable, $args];
    }

    /**
     * @throws CouldNotForkException
     */
    protected function _parallel(callable $callable, array $args = [])
    {
        self::breakpoint('started a parallel');
        self::breakpoint('parallel can be done: current childs: '.count($this->childs).'/'.$this->max_childs);
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new CouldNotForkException('Pool could not fork');
        }
        elseif($pid){
            // we are the parent
            $this->childs[] = $pid;
            self::breakpoint('child started');
            pcntl_wait($status, WNOHANG);
        }
        else{
            // we are the child
            $this->is_parent = false;
            if (!$this->kill_childs) {
                pcntl_signal(SIGINT, SIG_IGN);
            }
            try {
                $callable(...$args);
            }
            catch(Throwable $e){
                echo "Uncaught $e";
            }
            exit;
        }
    }

    /**
     * @throws CouldNotForkException
     */
    public function parallel(callable $callable, ...$args)
    {
        if($this->hasQueue()){
            self::breakpoint('resolving queue before parallel()');
            $this->resolveQueue();
            if($this->hasQueue()){
                self::breakpoint('enqueueing because there is a queue');
                return $this->enqueue($callable, $args);
            }
        }
        elseif(count($this->childs) > $this->max_childs){
            self::breakpoint('enqueueing because of max reached');
            return $this->enqueue($callable, $args);
        }
        return $this->_parallel($callable, $args);
    }

    public function iterate(iterable $iterable, callable $callable): void
    {
        foreach ($iterable as $value) {
            $this->parallel($callable, $value);
        }
    }

    public function resolveQueue(): void
    {
        if($this->is_resolving_queue) return;

        if(count($this->childs) >= $this->max_childs){
            if(!$this->checkChilds()) {
                self::breakpoint('resolveQueue() exited because of too many childs');
                return;
            }
        }

        $this->is_resolving_queue = true;

        foreach ($this->queue as $key => $callable) {
            if(count($this->childs) < $this->max_childs){
                unset($this->queue[$key]);
                self::breakpoint("resolveQueue() is resolving n. $key");
                $this->_parallel(...$callable);
            }
            else{
                self::breakpoint('resolveQueue() can\'t resolve, too many childs');
                break;
            }
        }
        if(empty($this->queue)){
            self::breakpoint('queue is empty');
        }

        $this->is_resolving_queue = false;

    }

    public function __destruct()
    {
        // pid check added because of an unidentified bug
        if($this->is_parent && $this->pid === getmypid()){
            $this->need_tick = false;
            self::breakpoint('triggered destructor');
            $this->wait();
        }
    }

    public static function getCoresCount(): ?int
    {
        if(isset(self::$cores_count)) return self::$cores_count === 0 ? null : self::$cores_count;


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
        print($value.PHP_EOL);
    }

    public function tick()
    {
        if($this->is_parent && $this->need_tick && self::$last_tick !== time()){
            self::$last_tick = time();
            if(!$this->is_resolving_queue) $this->resolveQueue();
        }
    }

    public function hasQueue(): bool
    {
        return !empty($this->queue);
    }

    public function getQueueLength(): int
    {
        return count($this->queue);
    }

    public function hasChilds(): bool
    {
        return !empty($this->childs);
    }

    public function getChildsCount(): int
    {
        return count($this->childs);
    }

    public function waitQueue(): void
    {
        while($this->hasQueue()){
            self::breakpoint('queue is not empty');
            $this->resolveQueue();
            usleep(10000);
        }
    }

    public function waitChilds(): void
    {
        $i = 0;
        while($this->hasChilds()){
            self::breakpoint('there are still childs');
            if($i % 100 === 0){
                $this->checkChilds();
            }
            usleep(10000);
        }
    }

    public function wait(): void
    {
        $this->waitQueue();
        $this->waitChilds();
    }

    public static function isProcessRunning(int $pid): bool
    {
        return posix_getpgid($pid) !== false;
    }

    public static function getDefaultPool(): self
    {
        return static::$default_pool ??= new static();
    }

}
