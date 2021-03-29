<?php
require '../vendor/autoload.php';

async(function(){
    sleep(3);
    echo 'Wow, this seems to be async', PHP_EOL;
});

echo 'Hello world', PHP_EOL;
