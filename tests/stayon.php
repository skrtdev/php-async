<?php
require '../vendor/autoload.php';

use skrtdev\async\Pool;

$pool = new Pool();

for ($i=0; $i < 10; $i++) {
    $pool->parallel(function () use ($i) {
        print("Parallel n.$i".PHP_EOL);
        while(true){
            sleep(1);
        }

    });
}
