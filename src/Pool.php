<?php

namespace skrtdev\async;

use Closure;

function is_process_running($PID) {
    return posix_getpgid($PID);
    exec("ps $PID", $ProcessState);
    #var_dump($ProcessState);
    if(!(posix_getpgid($PID)/* or posix_kill($PID, 0)*/)){
        print("\n\nAO\n\n");
        return false;
    }
    return(count($ProcessState) >= 2);
}

function breakpoint($value){
    return;
    usleep(300);
    print($value.PHP_EOL);
}

class Pool{

    protected int $max_childs = 50;
    protected array $childs = [];
    protected array $queue = [];
    protected int $pid;


    public function __construct()
    {
        $this->pid = getmypid();
    }

    public function checkChilds()
    {
        breakpoint("checkChilds()");
        $removed = false;
        foreach ($this->childs as $key => $child) {
            if(!is_process_running($child)){
                unset($this->childs[$key]);
                breakpoint("Removed child n. $key");
                $removed = true;
            }
            else{
                $this->internalParallel();
                if(!is_process_running($child)){
                    unset($this->childs[$key]);
                    breakpoint("Removed child n. $key from retrying");
                    $removed = true;
                }
            }
        }
        if(!$removed){
            breakpoint("CheckChilds didn't remove any child");
        }
        return $removed;
    }

    public function enqueue(Closure $closure = null): void
    {
        $this->queue[] = $closure;
        #print(PHP_EOL.PHP_EOL."BELLA LA CODA".PHP_EOL.PHP_EOL);
        /*
        breakpoint("check childs from enqueue");
        if($this->checkChilds()){
            breakpoint("resolveQueue from enqueue (cause checkChilds is succesful)");
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
            pcntl_wait($status);
        }
        else{
            // we are the child
            exit;
        }
    }

    public function parallel(Closure $closure, ...$args)
    {
        if(!empty($this->queue)){
            breakpoint("resolving queue before parallel()");
            $this->resolveQueue();
        }
        if(count($this->childs) > $this->max_childs){
            if(!$this->checkChilds()){
                breakpoint("enqueueing because of max reached (tried checkChilds but no results)");
                return $this->enqueue($closure);
            }
        }
        elseif(count($this->childs) > $this->max_childs/2){
            $this->checkChilds();
        }
        breakpoint("parallel can be done: current childs: ".count($this->childs)."/".$this->max_childs);
        $pid = pcntl_fork();
        if ($pid == -1) {
            die('could not fork');
        }
        elseif($pid){
            // we are the parent
            $this->childs[] = $pid;
            breakpoint("child started");
            pcntl_wait($status, WNOHANG);
        }
        else{
            // we are the child
            $pid = getmypid();
            $closure($args);
            exit;
        }
    }

    public function resolveQueue()
    {
        if(count($this->childs) >= $this->max_childs){
            breakpoint("resolveQueue() -> too many childs, trying to remove...");
            breakpoint("check childs from resolveQueue()");
            $this->checkChilds();
        }

        foreach ($this->queue as $key => $closure) {
            if(count($this->childs) < $this->max_childs){
                unset($this->queue[$key]);
                breakpoint("resolveQueue() is resolving n. $key");
                return $this->parallel($closure);
            }
            else{
                breakpoint("resolveQueue() can't resolve, too many childs");
                break;
                breakpoint("check childs from resolveQueue()");
                $this->checkChilds();
            }
        }
        if(empty($this->queue)){
            breakpoint("queue is empty");
        }

    }

    public function __destruct()
    {
        if($this->pid === getmypid()){
            breakpoint("triggered destructor");
            while(!empty($this->queue)){
                breakpoint("queue is not empty");
                $this->resolveQueue();
            }
            while(!empty($this->childs)){
                breakpoint("there are still childs");
                $this->checkChilds();
            }
        }
    }
}


?>
