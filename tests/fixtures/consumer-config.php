<?php
// Simulates installed consumer packages; never loaded by production config.
return ['components' => ['jobQueueFactory' => ['queues' => [
    'imports' => [], 'exports' => [], 'notifications' => [],
    'mail' => [], 'hosting' => [], 'bulk' => [],
]]]];
