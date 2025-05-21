<?php
require_once __DIR__ . '/libs/functions.php';

spl_autoload_register(
/**
 * @param string $class
 */
    function ($class) {
        $prefixes = array(
            'AmoCRM_Wrap\\' => array(
                __DIR__ . '/libs/AmoCRM',
            ),
        );
        foreach ($prefixes as $prefix => $dirs) {
            $prefix_len = mb_strlen($prefix);
            if (mb_strpos($class, $prefix) !== 0) {
                continue;
            }
            $class = mb_substr($class, $prefix_len);
            $part = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
            foreach ($dirs as $dir) {
                $dir = str_replace('/', DIRECTORY_SEPARATOR, $dir);
                $file = $dir . DIRECTORY_SEPARATOR . $part;
                if (is_readable($file)) {
                    require $file;
                    return;
                }
            }
        }
    }
);