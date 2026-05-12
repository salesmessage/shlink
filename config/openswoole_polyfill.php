<?php

declare(strict_types=1);

// OpenSwoole 22.x removed the swoole_set_process_name() global function.
// This polyfill restores it using PHP's native cli_set_process_title().
if (!function_exists('swoole_set_process_name')) {
    function swoole_set_process_name(string $name): void
    {
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title($name);
        }
    }
}
