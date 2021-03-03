<?php declare(ticks=1);

/**
 * without ticks, this script won't
 * continue to execute parallels
 * when inside the for, it will
 * only finish the pool by destructor
 */

require '../vendor/autoload.php';

use skrtdev\async\Pool;
use function skrtdev\async\range;

$pool = new Pool();

$pool->iterate(range(0, 100), function (int $i) {
    sleep(1);
    print("by the child n. $i".PHP_EOL);
});

print("Out of for, doing some external work...".PHP_EOL);

for ($i=0; $i < 10000; $i++) {
    usleep(1000);
}

print("External work finished...".PHP_EOL);

// here destructor is fired, and it will internally call $pool->wait()
