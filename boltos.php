#!/usr/bin/env php
<?php
/**
 * boltOS - PHP UNIX симулятор
 * Полноценная UNIX-подобная система на PHP
 */

declare(strict_types=1);

// Определяем корневую директорию системы
define('BOLTOS_ROOT', dirname(__FILE__));
define('BOLTOS_STORAGE', BOLTOS_ROOT . '/storage');
define('BOLTOS_FILESYSTEM', BOLTOS_STORAGE . '/filesystem');
define('BOLTOS_REPOSITORIES', BOLTOS_ROOT . '/packages/dist');

// Загружаем необходимые классы
require_once BOLTOS_ROOT . '/includes/Filesystem.php';
require_once BOLTOS_ROOT . '/includes/Matter.php';
require_once BOLTOS_ROOT . '/includes/Apk.php';
require_once BOLTOS_ROOT . '/includes/Commands.php';
require_once BOLTOS_ROOT . '/includes/Vie.php';

use BoltOS\Filesystem;
use BoltOS\Matter;
use BoltOS\Apk;
use BoltOS\Commands;
use BoltOS\Vie;

// Проверяем запуск через CLI
if (php_sapi_name() !== 'cli') {
    die("boltOS должен запускаться через CLI\n");
}

// Инициализируем систему
try {
    $filesystem = new Filesystem(BOLTOS_FILESYSTEM);
    $apk = new Apk(BOLTOS_REPOSITORIES, BOLTOS_FILESYSTEM);
    $vie = new Vie($filesystem);
    $commands = new Commands($filesystem, $apk, $vie);
    
    // Создаем matter с автозаполнением и стилем Gruvbox dark
    $matter = new Matter($filesystem, $commands, $vie);
    $matter->setTheme('gruvbox-dark');
    
    // Запускаем shell
    $matter->run();
    
} catch (Exception $e) {
    echo "\033[31mОшибка: " . $e->getMessage() . "\033[0m\n";
    exit(1);
}
