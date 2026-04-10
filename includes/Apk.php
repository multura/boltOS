<?php

namespace BoltOS;

use Exception;
use PharData;

/**
 * Класс APK - пакетный менеджер
 */
class Apk
{
    private string $repositoriesPath;
    private string $filesystemPath;
    private array $repositories;
    private array $packages;
    private string $installedFile;
    private string $repositoriesConfig;
    
    public function __construct(string $repositoriesPath, string $filesystemPath)
    {
        $this->repositoriesPath = $repositoriesPath;
        $this->filesystemPath = $filesystemPath;
        $this->installedFile = BOLTOS_STORAGE . '/installed.json';
        $this->repositoriesConfig = BOLTOS_STORAGE . '/repositories.json';
        
        $this->loadRepositories();
        $this->loadPackages();
    }
    
    /**
     * Загрузка репозиториев из конфигурационного файла
     */
    private function loadRepositories(): void
    {
        $this->repositories = [];
        
        // Загружаем конфигурацию репозиториев
        if (!file_exists($this->repositoriesConfig)) {
            // Создаем конфигурацию по умолчанию
            $defaultConfig = [
                'repositories' => [
                    [
                        'name' => 'official',
                        'url' => 'https://content.uzbekistation.serv00.net/boltos/repository',
                        'enabled' => true
                    ]
                ]
            ];
            file_put_contents($this->repositoriesConfig, json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $config = $defaultConfig;
        } else {
            $config = json_decode(file_get_contents($this->repositoriesConfig), true);
            if (!is_array($config) || !isset($config['repositories'])) {
                $this->repositories = [];
                return;
            }
        }
        
        // Объединяем пакеты из всех включенных репозиториев
        foreach ($config['repositories'] as $repo) {
            if (!isset($repo['enabled']) || !$repo['enabled']) {
                continue;
            }
            
            $repoUrl = $repo['url'];
            $packagesJson = $repoUrl . '/packages.json';
            
            try {
                $content = @file_get_contents($packagesJson);
                if ($content !== false) {
                    $repoPackages = json_decode($content, true);
                    if (is_array($repoPackages)) {
                        // Объединяем пакеты (последующие репозитории перекрывают предыдущие)
                        $this->repositories = array_merge($this->repositories, $repoPackages);
                    }
                }
            } catch (Exception $e) {
                // Игнорируем ошибки, пробуем следующий репозиторий
            }
        }
    }
    
    /**
     * Загрузка пакетов из репозитория
     */
    private function loadPackages(): void
    {
        $this->packages = [];
        
        // Загружаем из local.json
        foreach ($this->repositories as $name => $info) {
            $this->packages[$name] = $info;
        }
    }
    
    /**
     * Получение списка установленных пакетов
     */
    public function getInstalled(): array
    {
        if (!file_exists($this->installedFile)) {
            return [];
        }
        
        $content = file_get_contents($this->installedFile);
        $data = json_decode($content, true);
        
        return $data['packages'] ?? [];
    }
    
    /**
     * Сохранение списка установленных пакетов
     */
    private function saveInstalled(array $installed): void
    {
        $data = [
            'packages' => $installed,
            'last_update' => time()
        ];
        
        file_put_contents($this->installedFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    /**
     * Выполнение команды apk
     */
    public function execute(array $args): string
    {
        if (empty($args)) {
            return $this->showHelp();
        }
        
        $command = $args[0];
        $params = array_slice($args, 1);
        
        return match ($command) {
            'update' => $this->update($params),
            'add', 'install' => $this->add($params),
            'del', 'remove' => $this->remove($params),
            'reinstall' => $this->reinstall($params),
            'list' => $this->listPackages($params),
            'search' => $this->search($params),
            'info' => $this->info($params),
            'upgrade' => $this->upgrade($params),
            'repo' => $this->repo($params),
            default => $this->showHelp(),
        };
    }
    
    /**
     * Обновление индекса пакетов
     */
    private function update(array $params): string
    {
        echo "\033[38;2;184;187;38mОбновление индекса пакетов...\033[0m\n";
        
        // Перечитываем репозитории
        $this->loadRepositories();
        $this->loadPackages();
        
        echo "\033[38;2;184;187;38mOK: " . count($this->packages) . " пакетов\n\033[0m";
        
        return '';
    }
    
    /**
     * Установка пакета
     */
    private function add(array $params): string
    {
        if (empty($params)) {
            return "apk add: missing operand\n";
        }
        
        // Проверяем флаг --yes
        $yesMode = in_array('--yes', $params) || in_array('-y', $params);
        $params = array_filter($params, fn($p) => $p !== '--yes' && $p !== '-y');
        $params = array_values($params);
        
        $installed = $this->getInstalled();
        $toInstall = [];
        
        // Сначала собираем все пакеты для установки с зависимостями
        foreach ($params as $pkgName) {
            $this->resolveDependencies($pkgName, $installed, $toInstall);
        }
        
        // Показываем что будет установлено
        if (count($toInstall) > 0) {
            echo "\033[38;2;184;187;38mПакеты для установки:\033[0m\n";
            foreach ($toInstall as $pkgName) {
                $info = $this->packages[$pkgName] ?? null;
                $version = $info['version'] ?? 'unknown';
                $size = $info['size'] ?? 0;
                echo "  - {$pkgName} ({$version}, {$size} bytes)\n";
            }
            
            if (!$yesMode) {
                echo "\033[38;2;254;128;47mПродолжить установку? [y/N]: \033[0m";
                $response = trim(fgets(STDIN));
                
                if (strtolower($response) !== 'y') {
                    echo "\033[38;2;109;109;109mУстановка отменена\033[0m\n";
                    return '';
                }
            }
        }
        
        // Устанавливаем пакеты
        foreach ($toInstall as $pkgName) {
            if (isset($installed[$pkgName])) {
                echo "\033[38;2;131;165;152m{$pkgName} уже установлен\033[0m\n";
                continue;
            }
            
            echo "\033[38;2;254;128;47mУстановка {$pkgName}...\033[0m\n";
            
            if (!$this->installPackage($pkgName)) {
                echo "\033[38;2;251;73;68mОшибка установки {$pkgName}\033[0m\n";
                continue;
            }
            
            // Добавляем в список установленных
            if (isset($this->packages[$pkgName])) {
                $installed[$pkgName] = [
                    'version' => $this->packages[$pkgName]['version'],
                    'installed_at' => time(),
                    'size' => $this->packages[$pkgName]['size'],
                    'arch' => 'x86_64',
                    'provides' => $this->packages[$pkgName]['provides'] ?? []
                ];
            } else {
                // Если пакета нет в local.json, добавляем базовую информацию
                $installed[$pkgName] = [
                    'version' => 'unknown',
                    'installed_at' => time(),
                    'size' => 0,
                    'arch' => 'x86_64',
                    'provides' => [$pkgName]
                ];
            }
        }
        
        $this->saveInstalled($installed);
        
        return '';
    }
    
    /**
     * Разрешение зависимостей
     */
    private function resolveDependencies(string $pkgName, array $installed, array &$toInstall): void
    {
        if (in_array($pkgName, $toInstall) || isset($installed[$pkgName])) {
            return;
        }
        
        if (!isset($this->packages[$pkgName])) {
            echo "\033[38;2;251;73;68mПакет {$pkgName} не найден\033[0m\n";
            return;
        }
        
        $toInstall[] = $pkgName;
        
        // Рекурсивно разрешаем зависимости
        $deps = $this->packages[$pkgName]['dependencies'] ?? [];
        foreach ($deps as $dep) {
            $this->resolveDependencies($dep, $installed, $toInstall);
        }
    }
    
    /**
     * Поиск URL пакета в репозиториях
     */
    private function findPackageUrl(string $pkgName): ?string
    {
        $config = json_decode(file_get_contents($this->repositoriesConfig), true);
        if (!is_array($config) || !isset($config['repositories'])) {
            return null;
        }
        
        foreach ($config['repositories'] as $repo) {
            if (!isset($repo['enabled']) || !$repo['enabled']) {
                continue;
            }
            
            $repoUrl = $repo['url'];
            $packageUrl = $repoUrl . '/' . $pkgName . '.tar.gz';
            
            try {
                $content = @file_get_contents($packageUrl, false, stream_context_create(['http' => ['method' => 'HEAD']]));
                if ($content !== false || $this->urlExists($packageUrl)) {
                    return $packageUrl;
                }
            } catch (Exception $e) {
                // Пробуем следующий репозиторий
            }
        }
        
        return null;
    }
    
    /**
     * Проверка существования URL
     */
    private function urlExists(string $url): bool
    {
        $headers = @get_headers($url);
        return $headers && strpos($headers[0], '200') !== false;
    }
    
    /**
     * Установка пакета
     */
    private function installPackage(string $pkgName): bool
    {
        $archivePath = sys_get_temp_dir() . '/boltos_' . $pkgName . '.tar.gz';
        $useRemote = false;
        
        // Пробуем найти пакет в репозиториях
        $packageUrl = $this->findPackageUrl($pkgName);
        
        if ($packageUrl !== null) {
            // Скачиваем архив из найденного репозитория
            try {
                echo "  Загрузка из репозитория...\n";
                $archiveContent = @file_get_contents($packageUrl);
                if ($archiveContent !== false) {
                    file_put_contents($archivePath, $archiveContent);
                    $useRemote = true;
                    echo "  Загружено " . strlen($archiveContent) . " bytes\n";
                } else {
                    throw new Exception("Не удалось загрузить архив");
                }
            } catch (Exception $e) {
                echo "  Ошибка загрузки: " . $e->getMessage() . "\n";
            }
        }
        
        // Если пакет не найден в репозиториях
        if (!$useRemote) {
            echo "\033[38;2;251;73;68mАрхив не найден в репозиториях: {$pkgName}\033[0m\n";
            return false;
        }
        
        try {
            // Создаем временную директорию
            $tempDir = sys_get_temp_dir() . '/boltos_' . $pkgName;
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            echo "  Распаковка архива...\n";
            // Распаковываем архив
            $phar = new PharData($archivePath);
            $phar->extractTo($tempDir, null, true);
            
            echo "  Установка файлов...\n";
            // Перемещаем файлы в файловую систему
            $this->movePackageFiles($tempDir, $pkgName);
            
            // Очищаем временную директорию
            $this->removeDir($tempDir);
            
            // Удаляем временный архив если он был скачан
            if (strpos($archivePath, sys_get_temp_dir()) === 0 && file_exists($archivePath)) {
                unlink($archivePath);
            }
            
            echo "  OK: {$pkgName} установлен\n";
            return true;
            
        } catch (Exception $e) {
            echo "\033[38;2;251;73;68mОшибка распаковки: " . $e->getMessage() . "\033[0m\n";
            return false;
        }
    }
    
    /**
     * Перемещение файлов пакета в файловую систему
     */
    private function movePackageFiles(string $tempDir, string $pkgName): void
    {
        // Ищем директорию с файлами пакета
        $dirs = scandir($tempDir);
        $packageDir = null;
        
        foreach ($dirs as $dir) {
            if ($dir !== '.' && $dir !== '..') {
                $packageDir = $tempDir . '/' . $dir;
                break;
            }
        }
        
        if (!$packageDir || !is_dir($packageDir)) {
            // Если нет поддиректории, используем сам tempDir
            $packageDir = $tempDir;
        }
        
        // Рекурсивно копируем файлы
        $this->copyDirectory($packageDir, $this->filesystemPath);
    }
    
    /**
     * Рекурсивное копирование директории
     */
    private function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        
        $files = scandir($source);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $srcPath = $source . '/' . $file;
            $dstPath = $dest . '/' . $file;
            
            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }
    
    /**
     * Удаление директории
     */
    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        
        $files = array_diff(scandir($path), ['.', '..']);
        
        foreach ($files as $file) {
            $filePath = $path . '/' . $file;
            
            if (is_dir($filePath)) {
                $this->removeDir($filePath);
            } else {
                unlink($filePath);
            }
        }
        
        rmdir($path);
    }
    
    /**
     * Удаление пакета
     */
    private function remove(array $params): string
    {
        if (empty($params)) {
            return "apk del: missing operand\n";
        }
        
        // Проверяем флаг --yes
        $yesMode = in_array('--yes', $params) || in_array('-y', $params);
        $params = array_filter($params, fn($p) => $p !== '--yes' && $p !== '-y');
        $params = array_values($params);
        
        $installed = $this->getInstalled();
        $toRemove = [];
        
        // Сначала собираем пакеты для удаления
        foreach ($params as $pkgName) {
            if (isset($installed[$pkgName])) {
                $toRemove[] = $pkgName;
            }
        }
        
        // Показываем что будет удалено
        if (count($toRemove) > 0) {
            echo "\033[38;2;251;73;68mПакеты для удаления:\033[0m\n";
            foreach ($toRemove as $pkgName) {
                $info = $installed[$pkgName];
                $version = $info['version'] ?? 'unknown';
                echo "  - {$pkgName} ({$version})\n";
            }
            
            if (!$yesMode) {
                echo "\033[38;2;254;128;47mПродолжить удаление? [y/N]: \033[0m";
                $response = trim(fgets(STDIN));
                
                if (strtolower($response) !== 'y') {
                    echo "\033[38;2;109;109;109mУдаление отменено\033[0m\n";
                    return '';
                }
            }
        }
        
        // Удаляем пакеты
        foreach ($params as $pkgName) {
            if (!isset($installed[$pkgName])) {
                echo "\033[38;2;109;109;109m{$pkgName} не установлен\033[0m\n";
                continue;
            }
            
            echo "\033[38;2;254;128;47mУдаление {$pkgName}...\033[0m\n";
            
            // Удаляем файлы пакета (упрощенно)
            $this->removePackageFiles($pkgName);
            
            // Удаляем из списка установленных
            unset($installed[$pkgName]);
            
            echo "\033[38;2;184;187;38mOK: {$pkgName} удален\033[0m\n";
        }
        
        $this->saveInstalled($installed);
        
        return '';
    }
    
    /**
     * Переустановка пакета
     */
    private function reinstall(array $params): string
    {
        if (empty($params)) {
            return "apk reinstall: missing operand\n";
        }
        
        foreach ($params as $pkgName) {
            // Сначала удаляем
            $this->remove([$pkgName]);
            // Затем устанавливаем
            $this->add([$pkgName]);
        }
        
        return '';
    }
    
    /**
     * Удаление файлов пакета
     */
    private function removePackageFiles(string $pkgName): void
    {
        // Упрощенная реализация - удаляем только бинарные файлы
        $binPaths = ['/bin/' . $pkgName, '/usr/bin/' . $pkgName];
        
        foreach ($binPaths as $path) {
            $fullPath = $this->filesystemPath . $path;
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }
    }
    
    /**
     * Список пакетов
     */
    private function listPackages(array $params): string
    {
        $installedOnly = in_array('--installed', $params) || in_array('-i', $params);
        
        if ($installedOnly) {
            $installed = $this->getInstalled();
            
            $output = "\033[38;2;184;187;38mУстановленные пакеты:\033[0m\n\n";
            
            foreach ($installed as $name => $info) {
                $output .= sprintf("%-20s %-15s %s\n", 
                    $name, 
                    $info['version'], 
                    $this->packages[$name]['description'] ?? ''
                );
            }
            
            return $output;
        }
        
        // Все доступные пакеты
        $output = "\033[38;2;184;187;38mДоступные пакеты:\033[0m\n\n";
        
        foreach ($this->packages as $name => $info) {
            $isInstalled = isset($this->getInstalled()[$name]);
            $marker = $isInstalled ? "\033[38;2;184;187;38m[I]\033[0m" : "   ";
            
            $output .= sprintf("%s %-20s %-15s %s\n", 
                $marker,
                $name, 
                $info['version'], 
                $info['description'] ?? ''
            );
        }
        
        return $output;
    }
    
    /**
     * Поиск пакетов
     */
    private function search(array $params): string
    {
        if (empty($params)) {
            return "apk search: missing operand\n";
        }
        
        $query = strtolower(implode(' ', $params));
        $installed = $this->getInstalled();
        
        $output = "\033[38;2;184;187;38mРезультаты поиска:\033[0m\n\n";
        
        foreach ($this->packages as $name => $info) {
            $nameLower = strtolower($name);
            $descLower = strtolower($info['description'] ?? '');
            
            if (str_contains($nameLower, $query) || str_contains($descLower, $query)) {
                $isInstalled = isset($installed[$name]);
                $marker = $isInstalled ? "\033[38;2;184;187;38m[I]\033[0m" : "   ";
                
                $output .= sprintf("%s %-20s %-15s %s\n", 
                    $marker,
                    $name, 
                    $info['version'], 
                    $info['description'] ?? ''
                );
            }
        }
        
        return $output;
    }
    
    /**
     * Информация о пакете
     */
    private function info(array $params): string
    {
        if (empty($params)) {
            return "apk info: missing operand\n";
        }
        
        $pkgName = $params[0];
        
        if (!isset($this->packages[$pkgName])) {
            return "apk info: пакет {$pkgName} не найден\n";
        }
        
        $info = $this->packages[$pkgName];
        $installed = $this->getInstalled();
        $isInstalled = isset($installed[$pkgName]);
        
        $output = "\033[38;2;184;187;38mПакет: \033[0m{$pkgName}\n";
        $output .= "\033[38;2;184;187;38mВерсия: \033[0m{$info['version']}\n";
        $output .= "\033[38;2;184;187;38mОписание: \033[0m{$info['description']}\n";
        $output .= "\033[38;2;184;187;38mРазмер: \033[0m{$info['size']} bytes\n";
        $output .= "\033[38;2;184;187;38mСтатус: \033[0m" . ($isInstalled ? "\033[38;2;184;187;38mУстановлен\033[0m" : "Не установлен") . "\n";
        
        if (!empty($info['dependencies'])) {
            $output .= "\033[38;2;184;187;38mЗависимости: \033[0m" . implode(', ', $info['dependencies']) . "\n";
        }
        
        if (!empty($info['provides'])) {
            $output .= "\033[38;2;184;187;38mПредоставляет: \033[0m" . implode(', ', $info['provides']) . "\n";
        }
        
        return $output;
    }
    
    /**
     * Обновление пакетов
     */
    private function upgrade(array $params): string
    {
        $installed = $this->getInstalled();
        
        if (empty($installed)) {
            return "\033[38;2;109;109;109mНет установленных пакетов для обновления\033[0m\n";
        }
        
        echo "\033[38;2;184;187;38mОбновление пакетов...\033[0m\n";
        
        foreach ($installed as $pkgName => $info) {
            if (!isset($this->packages[$pkgName])) {
                continue;
            }
            
            $currentVersion = $info['version'];
            $newVersion = $this->packages[$pkgName]['version'];
            
            if ($currentVersion !== $newVersion) {
                echo "\033[38;2;254;128;47mОбновление {$pkgName} с {$currentVersion} на {$newVersion}...\033[0m\n";
                // Здесь должна быть логика обновления
            }
        }
        
        echo "\033[38;2;184;187;38mOK: все пакеты обновлены\033[0m\n";
        
        return '';
    }
    
    /**
     * Управление репозиториями
     */
    private function repo(array $params): string
    {
        if (empty($params)) {
            return $this->repoList();
        }
        
        $action = $params[0];
        $args = array_slice($params, 1);
        
        return match ($action) {
            'add' => $this->repoAdd($args),
            'remove', 'del' => $this->repoRemove($args),
            'enable' => $this->repoEnable($args),
            'disable' => $this->repoDisable($args),
            'list' => $this->repoList(),
            default => $this->repoHelp(),
        };
    }
    
    /**
     * Добавление репозитория
     */
    private function repoAdd(array $args): string
    {
        if (count($args) < 2) {
            return "Использование: apk repo add <имя> <url>\nПример: apk repo add myrepo https://example.com/repo\n";
        }
        
        $name = $args[0];
        $url = rtrim($args[1], '/');
        
        // Загружаем текущую конфигурацию
        if (!file_exists($this->repositoriesConfig)) {
            $config = ['repositories' => []];
        } else {
            $config = json_decode(file_get_contents($this->repositoriesConfig), true);
            if (!is_array($config) || !isset($config['repositories'])) {
                $config = ['repositories' => []];
            }
        }
        
        // Проверяем, что репозиторий с таким именем не существует
        foreach ($config['repositories'] as $repo) {
            if ($repo['name'] === $name) {
                return "\033[38;2;251;73;68mОшибка: репозиторий с именем '{$name}' уже существует\033[0m\n";
            }
        }
        
        // Добавляем новый репозиторий
        $config['repositories'][] = [
            'name' => $name,
            'url' => $url,
            'enabled' => true
        ];
        
        file_put_contents($this->repositoriesConfig, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return "\033[38;2;184;187;38mРепозиторий '{$name}' добавлен\033[0m\n";
    }
    
    /**
     * Удаление репозитория
     */
    private function repoRemove(array $args): string
    {
        if (empty($args)) {
            return "Использование: apk repo remove <имя>\nПример: apk repo remove myrepo\n";
        }
        
        $name = $args[0];
        
        // Загружаем текущую конфигурацию
        if (!file_exists($this->repositoriesConfig)) {
            return "\033[38;2;251;73;68mОшибка: конфигурация репозиториев не найдена\033[0m\n";
        }
        
        $config = json_decode(file_get_contents($this->repositoriesConfig), true);
        if (!is_array($config) || !isset($config['repositories'])) {
            return "\033[38;2;251;73;68mОшибка: некорректная конфигурация\033[0m\n";
        }
        
        // Находим и удаляем репозиторий
        $found = false;
        $newRepos = [];
        foreach ($config['repositories'] as $repo) {
            if ($repo['name'] === $name) {
                $found = true;
            } else {
                $newRepos[] = $repo;
            }
        }
        
        if (!$found) {
            return "\033[38;2;251;73;68mОшибка: репозиторий '{$name}' не найден\033[0m\n";
        }
        
        $config['repositories'] = $newRepos;
        file_put_contents($this->repositoriesConfig, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return "\033[38;2;184;187;38mРепозиторий '{$name}' удален\033[0m\n";
    }
    
    /**
     * Включение репозитория
     */
    private function repoEnable(array $args): string
    {
        if (empty($args)) {
            return "Использование: apk repo enable <имя>\nПример: apk repo enable myrepo\n";
        }
        
        $name = $args[0];
        
        // Загружаем текущую конфигурацию
        if (!file_exists($this->repositoriesConfig)) {
            return "\033[38;2;251;73;68mОшибка: конфигурация репозиториев не найдена\033[0m\n";
        }
        
        $config = json_decode(file_get_contents($this->repositoriesConfig), true);
        if (!is_array($config) || !isset($config['repositories'])) {
            return "\033[38;2;251;73;68mОшибка: некорректная конфигурация\033[0m\n";
        }
        
        // Находим и включаем репозиторий
        $found = false;
        foreach ($config['repositories'] as &$repo) {
            if ($repo['name'] === $name) {
                $repo['enabled'] = true;
                $found = true;
            }
        }
        
        if (!$found) {
            return "\033[38;2;251;73;68mОшибка: репозиторий '{$name}' не найден\033[0m\n";
        }
        
        file_put_contents($this->repositoriesConfig, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return "\033[38;2;184;187;38mРепозиторий '{$name}' включен\033[0m\n";
    }
    
    /**
     * Отключение репозитория
     */
    private function repoDisable(array $args): string
    {
        if (empty($args)) {
            return "Использование: apk repo disable <имя>\nПример: apk repo disable myrepo\n";
        }
        
        $name = $args[0];
        
        // Загружаем текущую конфигурацию
        if (!file_exists($this->repositoriesConfig)) {
            return "\033[38;2;251;73;68mОшибка: конфигурация репозиториев не найдена\033[0m\n";
        }
        
        $config = json_decode(file_get_contents($this->repositoriesConfig), true);
        if (!is_array($config) || !isset($config['repositories'])) {
            return "\033[38;2;251;73;68mОшибка: некорректная конфигурация\033[0m\n";
        }
        
        // Находим и отключаем репозиторий
        $found = false;
        foreach ($config['repositories'] as &$repo) {
            if ($repo['name'] === $name) {
                $repo['enabled'] = false;
                $found = true;
            }
        }
        
        if (!$found) {
            return "\033[38;2;251;73;68mОшибка: репозиторий '{$name}' не найден\033[0m\n";
        }
        
        file_put_contents($this->repositoriesConfig, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return "\033[38;2;184;187;38mРепозиторий '{$name}' отключен\033[0m\n";
    }
    
    /**
     * Список репозиториев
     */
    private function repoList(): string
    {
        // Загружаем текущую конфигурацию
        if (!file_exists($this->repositoriesConfig)) {
            return "\033[38;2;251;73;68mОшибка: конфигурация репозиториев не найдена\033[0m\n";
        }
        
        $config = json_decode(file_get_contents($this->repositoriesConfig), true);
        if (!is_array($config) || !isset($config['repositories'])) {
            return "\033[38;2;251;73;68mОшибка: некорректная конфигурация\033[0m\n";
        }
        
        $output = "\033[38;2;184;187;38mРепозитории:\033[0m\n\n";
        
        foreach ($config['repositories'] as $repo) {
            $status = isset($repo['enabled']) && $repo['enabled'] ? "\033[38;2;184;187;38m[включен]\033[0m" : "\033[38;2;251;73;68m[отключен]\033[0m";
            $output .= "  {$repo['name']}: {$repo['url']} {$status}\n";
        }
        
        return $output;
    }
    
    /**
     * Справка по репозиториям
     */
    private function repoHelp(): string
    {
        $help = "\033[38;2;184;187;38mУправление репозиториями:\033[0m\n\n";
        $help .= "  apk repo add <имя> <url>      Добавить репозиторий\n";
        $help .= "  apk repo remove <имя>         Удалить репозиторий\n";
        $help .= "  apk repo enable <имя>         Включить репозиторий\n";
        $help .= "  apk repo disable <имя>        Отключить репозиторий\n";
        $help .= "  apk repo list                 Список репозиториев\n\n";
        $help .= "Примеры:\n";
        $help .= "  apk repo add myrepo https://example.com/repo\n";
        $help .= "  apk repo disable official\n";
        $help .= "  apk repo list\n";
        return $help;
    }
    
    /**
     * Справка
     */
    private function showHelp(): string
    {
        $help = "\033[38;2;184;187;38mapk - пакетный менеджер boltOS\033[0m\n\n";
        $help .= "\033[38;2;184;187;38mКоманды:\033[0m\n";
        $help .= "  update        Обновить индекс пакетов\n";
        $help .= "  add <pkg>     Установить пакет\n";
        $help .= "  del <pkg>     Удалить пакет\n";
        $help .= "  list          Список всех пакетов\n";
        $help .= "  search <q>    Поиск пакетов\n";
        $help .= "  info <pkg>    Информация о пакете\n";
        $help .= "  reinstall <pkg> Переустановить пакет\n";
        $help .= "  upgrade       Обновить все пакеты\n";
        $help .= "  repo          Управление репозиториями\n";
        return $help;
    }
}
