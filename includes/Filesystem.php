<?php

namespace BoltOS;

use Exception;

/**
 * Класс для работы с файловой системой boltOS
 */
class Filesystem
{
    private string $root;
    private string $currentPath;
    private string $homePath;
    private string $user;
    
    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        $this->currentPath = '/home/root';
        $this->homePath = '/home/root';
        $this->user = 'root';
        
        $this->initFilesystem();
    }
    
    /**
     * Инициализация файловой системы
     */
    private function initFilesystem(): void
    {
        // Создаем базовую структуру директорий
        $dirs = [
            '/bin',
            '/dev',
            '/etc',
            '/home',
            '/home/root',
            '/media',
            '/mnt',
            '/opt',
            '/proc',
            '/root',
            '/sys',
            '/tmp',
            '/usr',
            '/usr/bin',
            '/usr/lib',
            '/usr/share',
            '/var',
            '/var/log',
            '/var/tmp',
        ];
        
        foreach ($dirs as $dir) {
            $this->mkdir($dir, 0755, true);
        }
        
        // Создаем конфигурационные файлы
        $this->initConfigFiles();
    }
    
    /**
     * Инициализация конфигурационных файлов
     */
    private function initConfigFiles(): void
    {
        // /etc/passwd
        $this->writeFile('/etc/passwd', "root:x:0:0:root:/home/root:/bin/matter\n");
        
        // /etc/hostname
        $this->writeFile('/etc/hostname', "boltos\n");
        
        // /etc/os-release
        $osRelease = "NAME=\"boltOS\"\n";
        $osRelease .= "VERSION=\"1.0.0\"\n";
        $osRelease .= "ID=boltos\n";
        $osRelease .= "PRETTY_NAME=\"boltOS 1.0.0\"\n";
        $this->writeFile('/etc/os-release', $osRelease);
        
        // /etc/profile
        $profile = "# /etc/profile: system-wide .profile file for boltOS\n";
        $profile .= "export PATH=/bin:/usr/bin\n";
        $profile .= "export HOME=/home/root\n";
        $profile .= "export USER=root\n";
        $profile .= "export TERM=xterm-256color\n";
        $profile .= "export LANG=en_US.UTF-8\n";
        $this->writeFile('/etc/profile', $profile);
        
        // /etc/hosts
        $this->writeFile('/etc/hosts', "127.0.0.1 localhost boltos\n");
        
        // /etc/resolv.conf
        $this->writeFile('/etc/resolv.conf', "nameserver 8.8.8.8\n");
    }
    
    /**
     * Получение полного пути к файлу
     */
    public function getFullPath(string $path): string
    {
        // Если путь абсолютный
        if (str_starts_with($path, '/')) {
            return $this->root . $path;
        }
        
        // Если путь относительный
        return $this->root . '/' . $this->currentPath . '/' . $path;
    }
    
    /**
     * Получение относительного пути
     */
    public function getRelativePath(string $fullPath): string
    {
        $relative = str_replace($this->root, '', $fullPath);
        return $relative === '' ? '/' : $relative;
    }
    
    /**
     * Проверка существования файла/директории
     */
    public function exists(string $path): bool
    {
        return file_exists($this->getFullPath($path));
    }
    
    /**
     * Проверка что путь - директория
     */
    public function isDir(string $path): bool
    {
        return is_dir($this->getFullPath($path));
    }
    
    /**
     * Проверка что путь - файл
     */
    public function isFile(string $path): bool
    {
        return is_file($this->getFullPath($path));
    }
    
    /**
     * Создание директории
     */
    public function mkdir(string $path, int $mode = 0755, bool $recursive = false): bool
    {
        $fullPath = $this->getFullPath($path);
        
        if (!file_exists($fullPath)) {
            return mkdir($fullPath, $mode, $recursive);
        }
        
        return true;
    }
    
    /**
     * Удаление директории
     */
    public function rmdir(string $path): bool
    {
        return rmdir($this->getFullPath($path));
    }
    
    /**
     * Удаление файла
     */
    public function unlink(string $path): bool
    {
        return unlink($this->getFullPath($path));
    }
    
    /**
     * Рекурсивное удаление
     */
    public function remove(string $path): bool
    {
        $fullPath = $this->getFullPath($path);
        
        if (!file_exists($fullPath)) {
            return false;
        }
        
        if (is_dir($fullPath)) {
            $files = array_diff(scandir($fullPath), ['.', '..']);
            foreach ($files as $file) {
                $this->remove($this->getRelativePath($fullPath . '/' . $file));
            }
            return rmdir($fullPath);
        }
        
        return unlink($fullPath);
    }
    
    /**
     * Чтение файла
     */
    public function readFile(string $path): string|false
    {
        $fullPath = $this->getFullPath($path);
        
        if (!file_exists($fullPath)) {
            return false;
        }
        
        return file_get_contents($fullPath);
    }
    
    /**
     * Запись файла
     */
    public function writeFile(string $path, string $content): bool
    {
        $fullPath = $this->getFullPath($path);
        
        // Создаем директорию если нужно
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        return file_put_contents($fullPath, $content) !== false;
    }
    
    /**
     * Копирование файла
     */
    public function copy(string $source, string $dest): bool
    {
        return copy($this->getFullPath($source), $this->getFullPath($dest));
    }
    
    /**
     * Перемещение/переименование файла
     */
    public function move(string $source, string $dest): bool
    {
        return rename($this->getFullPath($source), $this->getFullPath($dest));
    }
    
    /**
     * Получение списка файлов в директории
     */
    public function scandir(string $path): array
    {
        $fullPath = $this->getFullPath($path);
        
        if (!is_dir($fullPath)) {
            return [];
        }
        
        return array_values(array_diff(scandir($fullPath), ['.', '..']));
    }
    
    /**
     * Получение информации о файле
     */
    public function stat(string $path): array|false
    {
        $fullPath = $this->getFullPath($path);
        
        if (!file_exists($fullPath)) {
            return false;
        }
        
        $stat = stat($fullPath);
        
        return [
            'size' => $stat['size'],
            'mtime' => $stat['mtime'],
            'mode' => $stat['mode'],
            'uid' => $stat['uid'],
            'gid' => $stat['gid'],
            'is_dir' => is_dir($fullPath),
            'is_file' => is_file($fullPath),
        ];
    }
    
    /**
     * Получение текущей директории
     */
    public function getPwd(): string
    {
        return $this->currentPath;
    }
    
    /**
     * Смена текущей директории
     */
    public function cd(string $path): bool
    {
        $fullPath = $this->getFullPath($path);
        
        if (!is_dir($fullPath)) {
            return false;
        }
        
        $this->currentPath = $this->getRelativePath($fullPath);
        return true;
    }
    
    /**
     * Получение домашней директории
     */
    public function getHome(): string
    {
        return $this->homePath;
    }
    
    /**
     * Получение имени пользователя
     */
    public function getUser(): string
    {
        return $this->user;
    }
    
    /**
     * Получение имени хоста
     */
    public function getHostname(): string
    {
        $hostname = $this->readFile('/etc/hostname');
        return trim($hostname ?: 'boltos');
    }
    
    /**
     * Получение размера директории
     */
    public function getDirSize(string $path): int
    {
        $fullPath = $this->getFullPath($path);
        
        if (!is_dir($fullPath)) {
            return 0;
        }
        
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            $size += $file->getSize();
        }
        
        return $size;
    }
    
    /**
     * Получение свободного места
     */
    public function getDiskFree(): int
    {
        return disk_free_space($this->root);
    }
    
    /**
     * Получение общего места
     */
    public function getDiskTotal(): int
    {
        return disk_total_space($this->root);
    }
}
