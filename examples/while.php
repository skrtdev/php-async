<?php declare(ticks=1);

require '../vendor/autoload.php';

use skrtdev\async\Pool;
use function skrtdev\async\range;

$pool = new Pool();

$pool->iterate(range(0, 10), function ($i) {
    for ($ii=0; $ii < 10; $ii++) {
        sleep(1);
        print("by the child n. $i - $ii".PHP_EOL);
    }

});

while(true){
    sleep(1);
}
