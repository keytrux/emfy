<?php

/**
 * Вывод массива
 * @param $str
 */
function debug($str)
{
    echo "<pre>";
    print_r($str);
    echo "</pre>";
}

/**
 * Логирование
 * @param $data
 * @param string $title
 * @param null $name
 * @return false|int
 */
function writeToLog($data, $title = null, $name = null) {
    $log = "\n------------------------\n";
    $log .= date("d.m.Y G:i:s") . "\n";
    $log .= (strlen($title) > 0 ? $title : 'DEBUG') . "\n";
    $log .= print_r($data, 1);
    $log .= "\n------------------------\n";
    $path = __DIR__ . '/logs';
    $fileName = "{$name}_". date('Y-m');
    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        $path = __DIR__;
    }
    return file_put_contents("{$path}/roistat_{$fileName}.log", $log, FILE_APPEND);
}