<?php declare(strict_types=1);

use skrtdev\async\Pool;

if(!function_exists('async')){
    function async(callable $callable, ...$args){
        Pool::getDefaultPool()->parallel($callable, ...$args);
    }
}
