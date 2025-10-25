<?php

return [
    'bootstrap' => __DIR__ . '/vendor/autoload.php',
    'test_patterns' => [
        __DIR__ . '/tests/*Test.php',     // PHPUnit-style classes
        __DIR__ . '/tests/*_test.php',    // describe/it style
    ],
];
