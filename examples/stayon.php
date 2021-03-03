<?php
require '../vendor/autoload.php';

use skrtdev\async\Pool;

$pool = new Pool();

$pool->iterate(range(0, 10), function ($i) {
    print("Parallel n.$i".PHP_EOL);
    while(true){
        sleep(1);
    }

});
