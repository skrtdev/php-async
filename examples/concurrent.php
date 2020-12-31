<?php

error_reporting(E_ALL);
declare(ticks=1); // IN THIS CASE THIS IS NECESSARY

require '../vendor/autoload.php';

use skrtdev\async\Pool;

$pool = new Pool(10);

for ($i=0; $i < 100; $i++) {
    $pool->parallel(function () use ($i) {
        sleep(1);
        print("by the child n. $i".PHP_EOL);
    });
}

print("OUT OF FOR".PHP_EOL.PHP_EOL);

function some_work()
{
    usleep(1000);
}

print("Doing some external work...".PHP_EOL);
for ($i=0; $i < 10000; $i++) {
    #print("SHOULD TICK".PHP_EOL);
    some_work();
}
print("External work finished...".PHP_EOL);
exit;
sleep(5);
var_dump($pool);
var_dump(Pool::$cores_count);
#var_dump($pool->$max_childs);
sleep(1);
