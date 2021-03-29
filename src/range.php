<?php declare(strict_types=1);

namespace skrtdev\async;

function range (int $start, int $end, int $step = 1): \Generator {
    for ($i = $start; $i < $end; $i += $step) {
        yield $i;
    }
}
