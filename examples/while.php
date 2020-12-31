<?php
declare(ticks=1);

require '../vendor/autoload.php';

use skrtdev\async\Pool;

$pool = new Pool(100);

for ($i=0; $i < 10; $i++) {
    $pool->parallel(function () use ($i) {
        for ($ii=0; $ii < 10; $ii++) {
            sleep(1);
            print("by the child n. $i - $ii".PHP_EOL);
        }
    });
}

while(true){
    sleep(1);
}
