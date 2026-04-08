<?php

$paths = [
    __DIR__ . DIRECTORY_SEPARATOR . 'after(function',
    __DIR__ . DIRECTORY_SEPARATOR . 'url(',
];

foreach ($paths as $path) {
    $target = $path . '.tmp-delete';

    @chmod($path, 0666);

    echo $path
        . ' | is_file=' . (is_file($path) ? '1' : '0')
        . ' | renamed=' . (@rename($path, $target) ? '1' : '0')
        . ' | deleted=' . (@unlink($target) ? '1' : '0')
        . PHP_EOL;
}
