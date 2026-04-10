<?php

namespace BoltOS;

use Exception;

/**
 * Класс для выполнения команд
 */
class Commands
{
    private Filesystem $filesystem;
    private Apk $apk;
    private Vie $vie;
    private array $internalCommands;
    
    public function __construct(Filesystem $filesystem, Apk $apk, Vie $vie)
    {
        $this->filesystem = $filesystem;
        $this->apk = $apk;
        $this->vie = $vie;
        
        $this->internalCommands = [
            'help', 'clear', 'exit', 'quit', 'pwd', 'cd', 'ls', 'cat', 'mkdir',
            'rm', 'cp', 'mv', 'touch', 'echo', 'date', 'whoami', 'hostname',
            'uname', 'df', 'du', 'free', 'export', 'env', 'alias', 'history',
            'apk', 'vie', 'which', 'type', 'true', 'false', 'sleep', 'wc',
            'head', 'tail', 'grep', 'sort', 'uniq', 'find', 'tree', 'htop',
            'ps', 'top', 'kill', 'xargs', 'tar',
        ];
    }
    
    /**
     * Получение списка доступных команд
     */
    public function getAvailableCommands(): array
    {
        $commands = $this->internalCommands;
        
        // Добавляем установленные пакеты
        $installed = $this->apk->getInstalled();
        foreach ($installed as $pkg => $info) {
            if (isset($info['provides'])) {
                $commands = array_merge($commands, $info['provides']);
            }
        }
        
        return array_unique($commands);
    }
    
    /**
     * Выполнение команды
     */
    public function execute(string $command, array $args): string
    {
        // Проверяем внутренние команды
        if (in_array($command, $this->internalCommands)) {
            return $this->executeInternal($command, $args);
        }
        
        // Проверяем установленные пакеты
        $installed = $this->apk->getInstalled();
        foreach ($installed as $pkg => $info) {
            if (isset($info['provides']) && in_array($command, $info['provides'])) {
                return $this->executePackageCommand($command, $args);
            }
        }
        
        throw new Exception("Команда не найдена: {$command}");
    }
    
    /**
     * Выполнение внутренней команды
     */
    private function executeInternal(string $command, array $args): string
    {
        // vie всегда использует встроенный редактор
        if ($command === 'vie') {
            return $this->cmdVie($args);
        }
        
        // Сначала проверяем пакетные команды
        $installed = $this->apk->getInstalled();
        foreach ($installed as $pkg => $info) {
            if (isset($info['provides']) && in_array($command, $info['provides'])) {
                return $this->executePackageCommand($command, $args);
            }
        }
        
        return match ($command) {
            'help' => $this->cmdHelp($args),
            'clear' => $this->cmdClear($args),
            'exit' => $this->cmdExit($args),
            'quit' => $this->cmdExit($args),
            'pwd' => $this->cmdPwd($args),
            'cd' => $this->cmdCd($args),
            'ls' => $this->cmdLs($args),
            'cat' => $this->cmdCat($args),
            'mkdir' => $this->cmdMkdir($args),
            'rm' => $this->cmdRm($args),
            'cp' => $this->cmdCp($args),
            'mv' => $this->cmdMv($args),
            'touch' => $this->cmdTouch($args),
            'echo' => $this->cmdEcho($args),
            'date' => $this->cmdDate($args),
            'whoami' => $this->cmdWhoami($args),
            'hostname' => $this->cmdHostname($args),
            'uname' => $this->cmdUname($args),
            'df' => $this->cmdDf($args),
            'du' => $this->cmdDu($args),
            'free' => $this->cmdFree($args),
            'export' => $this->cmdExport($args),
            'env' => $this->cmdEnv($args),
            'alias' => $this->cmdAlias($args),
            'history' => $this->cmdHistory($args),
            'apk' => $this->cmdApk($args),
            'vie' => $this->cmdVie($args),
            'which' => $this->cmdWhich($args),
            'type' => $this->cmdType($args),
            'true' => $this->cmdTrue($args),
            'false' => $this->cmdFalse($args),
            'sleep' => $this->cmdSleep($args),
            'wc' => $this->cmdWc($args),
            'head' => $this->cmdHead($args),
            'tail' => $this->cmdTail($args),
            'grep' => $this->cmdGrep($args),
            'sort' => $this->cmdSort($args),
            'uniq' => $this->cmdUniq($args),
            'chmod' => $this->cmdChmod($args),
            'chown' => $this->cmdChown($args),
            'ln' => $this->cmdLn($args),
            'ps' => $this->cmdPs($args),
            'kill' => $this->cmdKill($args),
            'top' => $this->cmdTop($args),
            'find' => $this->cmdFind($args),
            'xargs' => $this->cmdXargs($args),
            'tar' => $this->cmdTar($args),
            default => throw new Exception("Неизвестная команда: {$command}"),
        };
    }
    
    /**
     * Выполнение команды из пакета
     */
    private function executePackageCommand(string $command, array $args): string
    {
        // Проверяем наличие исполняемого файла
        $binPath = '/bin/' . $command;
        $usrBinPath = '/usr/bin/' . $command;
        
        if ($this->filesystem->exists($binPath)) {
            return $this->executeBinary($binPath, $args);
        }
        
        if ($this->filesystem->exists($usrBinPath)) {
            return $this->executeBinary($usrBinPath, $args);
        }
        
        throw new Exception("Команда не найдена: {$command}");
    }
    
    /**
     * Выполнение бинарного файла
     */
    private function executeBinary(string $path, array $args): string
    {
        $fullPath = $this->filesystem->getFullPath($path);
        
        if (!file_exists($fullPath)) {
            throw new Exception("Файл не найден: {$path}");
        }
        
        // Проверяем, это PHP скрипт
        $content = file_get_contents($fullPath);
        if (str_starts_with($content, '#!/usr/bin/env php') || str_starts_with($content, '<?php')) {
            // Интерактивные игры выполняем напрямую через passthru
            $command = basename($path);
            $interactiveGames = ['snake', 'tictactoe', 'guess', 'hangman'];
            
            if (in_array($command, $interactiveGames)) {
                // Сохраняем текущий терминал
                system('stty sane');
                
                // Формируем команду
                $cmd = 'php ' . escapeshellarg($fullPath);
                foreach ($args as $arg) {
                    $cmd .= ' ' . escapeshellarg($arg);
                }
                
                // Выполняем напрямую с терминалом
                passthru($cmd, $exitCode);
                
                // Восстанавливаем терминал
                system('stty sane');
                
                return '';
            }
            
            // Обычные скрипты выполняем через shell_exec
            $cmd = 'php ' . escapeshellarg($fullPath);
            foreach ($args as $arg) {
                $cmd .= ' ' . escapeshellarg($arg);
            }
            
            $output = shell_exec($cmd);
            return $output ?: '';
        }
        
        // Если это не PHP скрипт, пробуем выполнить через shell
        $cmd = escapeshellcmd($fullPath);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        
        $output = shell_exec($cmd);
        return $output ?: '';
    }
    
    // ==================== КОМАНДЫ ====================
    
    private function cmdHelp(array $args): string
    {
        $help = "\033[38;2;184;187;38mДоступные команды:\033[0m\n\n";
        
        $categories = [
            'Файловая система' => ['ls', 'cd', 'pwd', 'cat', 'mkdir', 'rm', 'cp', 'mv', 'touch', 'tree', 'find'],
            'Текст' => ['cat', 'head', 'tail', 'grep', 'sort', 'uniq', 'wc'],
            'Система' => ['uname', 'hostname', 'whoami', 'date', 'df', 'du', 'free', 'ps', 'top', 'htop'],
            'Управление пакетами' => ['apk'],
            'Редакторы' => ['vie'],
            'Shell' => ['export', 'env', 'alias', 'history', 'which', 'type'],
            'Другое' => ['clear', 'exit', 'quit', 'help', 'echo', 'sleep', 'true', 'false'],
        ];
        
        foreach ($categories as $category => $commands) {
            $help .= "\033[38;2;254;128;47m{$category}:\033[0m ";
            $help .= implode(', ', $commands) . "\n";
        }
        
        $help .= "\n\033[38;2;109;109;109mИспользуйте 'man <команда>' для подробной информации.\033[0m\n";
        
        return $help;
    }
    
    private function cmdClear(array $args): string
    {
        echo "\033[2J\033[H";
        return '';
    }
    
    private function cmdExit(array $args): string
    {
        return 'exit';
    }
    
    private function cmdPwd(array $args): string
    {
        return $this->filesystem->getPwd() . "\n";
    }
    
    private function cmdCd(array $args): string
    {
        $path = $args[0] ?? $this->filesystem->getHome();
        
        if ($path === '-') {
            // Вернуться в предыдущую директорию (не реализовано)
            return "cd: нет предыдущей директории\n";
        }
        
        if ($path === '~') {
            $path = $this->filesystem->getHome();
        }
        
        if (!$this->filesystem->cd($path)) {
            throw new Exception("cd: {$path}: Нет такой директории");
        }
        
        return '';
    }
    
    private function cmdLs(array $args): string
    {
        $showAll = false;
        $longFormat = false;
        $path = '.';
        
        foreach ($args as $arg) {
            if ($arg === '-a' || $arg === '--all') {
                $showAll = true;
            } elseif ($arg === '-l' || $arg === '--long') {
                $longFormat = true;
            } elseif ($arg === '-la' || $arg === '-al') {
                $showAll = true;
                $longFormat = true;
            } elseif (!str_starts_with($arg, '-')) {
                $path = $arg;
            }
        }
        
        if (!$this->filesystem->exists($path)) {
            throw new Exception("ls: {$path}: Нет такого файла или директории");
        }
        
        if ($this->filesystem->isFile($path)) {
            return $this->formatFile($path, $longFormat) . "\n";
        }
        
        $files = $this->filesystem->scandir($path);
        
        if (!$showAll) {
            $files = array_filter($files, fn($f) => !str_starts_with($f, '.'));
        }
        
        if ($longFormat) {
            $output = "total " . count($files) . "\n";
            foreach ($files as $file) {
                $output .= $this->formatFile($path . '/' . $file, $longFormat) . "\n";
            }
            return $output;
        }
        
        // Форматирование в колонки
        return implode('  ', $files) . "\n";
    }
    
    private function formatFile(string $path, bool $longFormat): string
    {
        $name = basename($path);
        
        if (!$longFormat) {
            if ($this->filesystem->isDir($path)) {
                return "\033[38;2;131;165;152m{$name}/\033[0m";
            }
            return $name;
        }
        
        $stat = $this->filesystem->stat($path);
        $perms = $this->formatPermissions($stat['mode']);
        $size = $this->formatSize($stat['size']);
        $date = date('M d H:i', $stat['mtime']);
        $dirChar = $stat['is_dir'] ? 'd' : '-';
        
        $color = $stat['is_dir'] ? "\033[38;2;131;165;152m" : "";
        $reset = $stat['is_dir'] ? "\033[0m" : "";
        
        return "{$dirChar}{$perms} 1 {$this->filesystem->getUser()} {$this->filesystem->getUser()} {$size} {$date} {$color}{$name}{$reset}";
    }
    
    private function formatPermissions(int $mode): string
    {
        $perms = '';
        
        for ($i = 0; $i < 9; $i++) {
            $perms .= (($mode & (1 << (8 - $i))) ? 'rwx'[$i % 3] : '-');
        }
        
        return $perms;
    }
    
    private function formatSize(int $size): string
    {
        $units = ['B', 'K', 'M', 'G', 'T'];
        $unit = 0;
        
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }
        
        return round($size, 1) . $units[$unit];
    }
    
    private function cmdCat(array $args): string
    {
        if (empty($args)) {
            return "cat: missing file operand\n";
        }
        
        $output = '';
        foreach ($args as $arg) {
            if (!$this->filesystem->exists($arg)) {
                throw new Exception("cat: {$arg}: Нет такого файла");
            }
            
            if ($this->filesystem->isDir($arg)) {
                throw new Exception("cat: {$arg}: Это директория");
            }
            
            $content = $this->filesystem->readFile($arg);
            if ($content === false) {
                throw new Exception("cat: {$arg}: Ошибка чтения");
            }
            
            $output .= $content;
        }
        
        return $output;
    }
    
    private function cmdMkdir(array $args): string
    {
        $recursive = false;
        $paths = [];
        
        foreach ($args as $arg) {
            if ($arg === '-p' || $arg === '--parents') {
                $recursive = true;
            } elseif (!str_starts_with($arg, '-')) {
                $paths[] = $arg;
            }
        }
        
        if (empty($paths)) {
            return "mkdir: missing operand\n";
        }
        
        foreach ($paths as $path) {
            if (!$this->filesystem->mkdir($path, 0755, $recursive)) {
                if (!$recursive || $this->filesystem->exists($path)) {
                    throw new Exception("mkdir: {$path}: Не удается создать директорию");
                }
            }
        }
        
        return '';
    }
    
    private function cmdRm(array $args): string
    {
        $recursive = false;
        $force = false;
        $paths = [];
        
        foreach ($args as $arg) {
            if ($arg === '-r' || $arg === '-R' || $arg === '--recursive') {
                $recursive = true;
            } elseif ($arg === '-f' || $arg === '--force') {
                $force = true;
            } elseif ($arg === '-rf' || $arg === '-fr') {
                $recursive = true;
                $force = true;
            } elseif (!str_starts_with($arg, '-')) {
                $paths[] = $arg;
            }
        }
        
        if (empty($paths)) {
            return "rm: missing operand\n";
        }
        
        foreach ($paths as $path) {
            if (!$this->filesystem->exists($path)) {
                if (!$force) {
                    throw new Exception("rm: {$path}: Нет такого файла");
                }
                continue;
            }
            
            if ($this->filesystem->isDir($path) && !$recursive) {
                throw new Exception("rm: {$path}: Это директория (используйте -r)");
            }
            
            if (!$this->filesystem->remove($path)) {
                throw new Exception("rm: {$path}: Не удается удалить");
            }
        }
        
        return '';
    }
    
    private function cmdCp(array $args): string
    {
        if (count($args) < 2) {
            return "cp: missing operand\n";
        }
        
        $source = $args[0];
        $dest = $args[1];
        
        if (!$this->filesystem->exists($source)) {
            throw new Exception("cp: {$source}: Нет такого файла");
        }
        
        if (!$this->filesystem->copy($source, $dest)) {
            throw new Exception("cp: Не удается скопировать");
        }
        
        return '';
    }
    
    private function cmdMv(array $args): string
    {
        if (count($args) < 2) {
            return "mv: missing operand\n";
        }
        
        $source = $args[0];
        $dest = $args[1];
        
        if (!$this->filesystem->exists($source)) {
            throw new Exception("mv: {$source}: Нет такого файла");
        }
        
        if (!$this->filesystem->move($source, $dest)) {
            throw new Exception("mv: Не удается переместить");
        }
        
        return '';
    }
    
    private function cmdTouch(array $args): string
    {
        if (empty($args)) {
            return "touch: missing operand\n";
        }
        
        foreach ($args as $path) {
            if ($this->filesystem->exists($path)) {
                // Обновляем время модификации (не реализовано)
                continue;
            }
            $this->filesystem->writeFile($path, '');
        }
        
        return '';
    }
    
    private function cmdEcho(array $args): string
    {
        return implode(' ', $args) . "\n";
    }
    
    private function cmdDate(array $args): string
    {
        return date('Y-m-d H:i:s') . "\n";
    }
    
    private function cmdWhoami(array $args): string
    {
        return $this->filesystem->getUser() . "\n";
    }
    
    private function cmdHostname(array $args): string
    {
        return $this->filesystem->getHostname() . "\n";
    }
    
    private function cmdUname(array $args): string
    {
        $all = in_array('-a', $args) || in_array('--all', $args);
        
        if ($all) {
            return "boltOS 1.0.0 #1 SMP boltOS x86_64\n";
        }
        
        return "boltOS\n";
    }
    
    private function cmdDf(array $args): string
    {
        $free = $this->filesystem->getDiskFree();
        $total = $this->filesystem->getDiskTotal();
        $used = $total - $free;
        
        $output = "Файловая система     Размер   Использовано  Доступно  Использовано%\n";
        $output .= str_pad($this->filesystem->getHostname(), 20);
        $output .= str_pad($this->formatSize($total), 10);
        $output .= str_pad($this->formatSize($used), 14);
        $output .= str_pad($this->formatSize($free), 10);
        $output .= " " . round(($used / $total) * 100, 1) . "%\n";
        
        return $output;
    }
    
    private function cmdDu(array $args): string
    {
        $path = $args[0] ?? '.';
        
        if (!$this->filesystem->exists($path)) {
            throw new Exception("du: {$path}: Нет такого файла");
        }
        
        if ($this->filesystem->isFile($path)) {
            $stat = $this->filesystem->stat($path);
            return $this->formatSize($stat['size']) . "\t{$path}\n";
        }
        
        $size = $this->filesystem->getDirSize($path);
        return $this->formatSize($size) . "\t{$path}\n";
    }
    
    private function cmdFree(array $args): string
    {
        // Эмуляция информации о памяти
        $output = "              total        used        free      shared  buff/cache   available\n";
        $output .= "Mem:        8192M       2048M       4096M         0M       2048M       6144M\n";
        $output .= "Swap:           0B           0B           0B\n";
        return $output;
    }
    
    private function cmdExport(array $args): string
    {
        // Заглушка для export
        return '';
    }
    
    private function cmdEnv(array $args): string
    {
        $env = [
            'PATH=/bin:/usr/bin',
            'HOME=' . $this->filesystem->getHome(),
            'USER=' . $this->filesystem->getUser(),
            'SHELL=/bin/matter',
            'TERM=xterm-256color',
            'LANG=en_US.UTF-8',
        ];
        
        return implode("\n", $env) . "\n";
    }
    
    private function cmdAlias(array $args): string
    {
        return "alias ll='ls -la'\nalias la='ls -a'\nalias l='ls'\nalias cls='clear'\n";
    }
    
    private function cmdHistory(array $args): string
    {
        $historyFile = BOLTOS_STORAGE . '/history.json';
        
        if (!file_exists($historyFile)) {
            return "";
        }
        
        $content = file_get_contents($historyFile);
        $history = json_decode($content, true);
        
        if (!is_array($history)) {
            return "";
        }
        
        $output = '';
        foreach ($history as $i => $cmd) {
            $output .= sprintf("%5d  %s\n", $i + 1, $cmd);
        }
        
        return $output;
    }
    
    private function cmdApk(array $args): string
    {
        return $this->apk->execute($args);
    }
    
    private function cmdVie(array $args): string
    {
        $filename = $args[0] ?? '';
        
        if (!$filename) {
            return "vie: missing filename\n";
        }
        
        try {
            // Используем встроенный редактор boltos вместо пакетного
            $this->vie->run($filename);
            return '';
        } catch (Exception $e) {
            return "vie: " . $e->getMessage() . "\n";
        }
    }
    
    private function cmdWhich(array $args): string
    {
        if (empty($args)) {
            return "which: missing argument\n";
        }
        
        $command = $args[0];
        
        if (in_array($command, $this->internalCommands)) {
            return "/bin/{$command}\n";
        }
        
        $installed = $this->apk->getInstalled();
        foreach ($installed as $pkg => $info) {
            if (isset($info['provides']) && in_array($command, $info['provides'])) {
                return "/usr/bin/{$command}\n";
            }
        }
        
        return "";
    }
    
    private function cmdType(array $args): string
    {
        if (empty($args)) {
            return "type: missing argument\n";
        }
        
        $command = $args[0];
        
        if (in_array($command, $this->internalCommands)) {
            return "{$command} is a shell builtin\n";
        }
        
        $installed = $this->apk->getInstalled();
        foreach ($installed as $pkg => $info) {
            if (isset($info['provides']) && in_array($command, $info['provides'])) {
                return "{$command} is /usr/bin/{$command}\n";
            }
        }
        
        return "type: {$command}: not found\n";
    }
    
    private function cmdTrue(array $args): string
    {
        return '';
    }
    
    private function cmdFalse(array $args): string
    {
        return '';
    }
    
    private function cmdSleep(array $args): string
    {
        $seconds = (int)($args[0] ?? 1);
        sleep($seconds);
        return '';
    }
    
    private function cmdWc(array $args): string
    {
        if (empty($args)) {
            return "wc: missing operand\n";
        }
        
        $output = '';
        foreach ($args as $path) {
            if (!$this->filesystem->exists($path)) {
                throw new Exception("wc: {$path}: Нет такого файла");
            }
            
            $content = $this->filesystem->readFile($path);
            $lines = substr_count($content, "\n") + 1;
            $words = str_word_count($content);
            $chars = strlen($content);
            
            $output .= sprintf(" %7d %7d %7d %s\n", $lines, $words, $chars, $path);
        }
        
        return $output;
    }
    
    private function cmdHead(array $args): string
    {
        $lines = 10;
        $path = null;
        
        foreach ($args as $arg) {
            if (str_starts_with($arg, '-n')) {
                $lines = (int)substr($arg, 2);
            } elseif (!str_starts_with($arg, '-')) {
                $path = $arg;
            }
        }
        
        if (!$path) {
            return "head: missing operand\n";
        }
        
        if (!$this->filesystem->exists($path)) {
            throw new Exception("head: {$path}: Нет такого файла");
        }
        
        $content = $this->filesystem->readFile($path);
        $linesArray = explode("\n", $content);
        $linesArray = array_slice($linesArray, 0, $lines);
        
        return implode("\n", $linesArray) . "\n";
    }
    
    private function cmdTail(array $args): string
    {
        $lines = 10;
        $path = null;
        
        foreach ($args as $arg) {
            if (str_starts_with($arg, '-n')) {
                $lines = (int)substr($arg, 2);
            } elseif (!str_starts_with($arg, '-')) {
                $path = $arg;
            }
        }
        
        if (!$path) {
            return "tail: missing operand\n";
        }
        
        if (!$this->filesystem->exists($path)) {
            throw new Exception("tail: {$path}: Нет такого файла");
        }
        
        $content = $this->filesystem->readFile($path);
        $linesArray = explode("\n", $content);
        $linesArray = array_slice($linesArray, -$lines);
        
        return implode("\n", $linesArray) . "\n";
    }
    
    private function cmdGrep(array $args): string
    {
        if (count($args) < 2) {
            return "grep: missing operand\n";
        }
        
        $pattern = array_shift($args);
        $path = array_shift($args);
        
        if (!$this->filesystem->exists($path)) {
            throw new Exception("grep: {$path}: Нет такого файла");
        }
        
        $content = $this->filesystem->readFile($path);
        $lines = explode("\n", $content);
        
        $output = '';
        foreach ($lines as $line) {
            if (str_contains($line, $pattern)) {
                $output .= $line . "\n";
            }
        }
        
        return $output;
    }
    
    private function cmdSort(array $args): string
    {
        if (empty($args)) {
            return "sort: missing operand\n";
        }
        
        $path = $args[0];
        
        if (!$this->filesystem->exists($path)) {
            throw new Exception("sort: {$path}: Нет такого файла");
        }
        
        $content = $this->filesystem->readFile($path);
        $lines = explode("\n", $content);
        sort($lines);
        
        return implode("\n", $lines) . "\n";
    }
    
    private function cmdUniq(array $args): string
    {
        if (empty($args)) {
            return "uniq: missing operand\n";
        }
        
        $path = $args[0];
        
        if (!$this->filesystem->exists($path)) {
            throw new Exception("uniq: {$path}: Нет такого файла");
        }
        
        $content = $this->filesystem->readFile($path);
        $lines = explode("\n", $content);
        $lines = array_unique($lines);
        
        return implode("\n", $lines) . "\n";
    }
    
    private function cmdChmod(array $args): string
    {
        // Заглушка - chmod не меняет реальные права
        return '';
    }
    
    private function cmdChown(array $args): string
    {
        // Заглушка - chown не меняет владельца
        return '';
    }
    
    private function cmdLn(array $args): string
    {
        // Заглушка - символические ссылки не поддерживаются
        return "ln: символические ссылки не поддерживаются\n";
    }
    
    private function cmdPs(array $args): string
    {
        $output = "  PID TTY          TIME CMD\n";
        $output .= "    1 pts/0    00:00:00 zsh\n";
        $output .= "  123 pts/0    00:00:00 ps\n";
        return $output;
    }
    
    private function cmdKill(array $args): string
    {
        if (empty($args)) {
            return "kill: missing operand\n";
        }
        
        return "kill: процесс не найден\n";
    }
    
    private function cmdTop(array $args): string
    {
        return "top: интерактивная утилита (пока не реализована)\n";
    }
    
    private function cmdHtop(array $args): string
    {
        try {
            return $this->executePackageCommand('htop', $args);
        } catch (Exception $e) {
            return "htop: интерактивная утилита (пока не реализована)\n";
        }
    }
    
    private function cmdTree(array $args): string
    {
        try {
            return $this->executePackageCommand('tree', $args);
        } catch (Exception $e) {
            $path = $args[0] ?? '.';
            
            if (!$this->filesystem->exists($path)) {
                throw new Exception("tree: {$path}: Нет такого файла");
            }
            
            return $this->buildTree($path);
        }
    }
    
    private function buildTree(string $path, string $prefix = ''): string
    {
        if ($this->filesystem->isFile($path)) {
            return basename($path) . "\n";
        }
        
        $output = '';
        $files = $this->filesystem->scandir($path);
        sort($files);
        
        foreach ($files as $i => $file) {
            $isLast = $i === count($files) - 1;
            $connector = $isLast ? '└── ' : '├── ';
            $newPrefix = $prefix . ($isLast ? '    ' : '│   ');
            
            $fullPath = $path . '/' . $file;
            $output .= $prefix . $connector . $file;
            
            if ($this->filesystem->isDir($fullPath)) {
                $output .= "\n" . $this->buildTree($fullPath, $newPrefix);
            } else {
                $output .= "\n";
            }
        }
        
        return $output;
    }
    
    private function cmdFind(array $args): string
    {
        // Упрощенная реализация find
        $path = $args[0] ?? '.';
        $name = null;
        
        for ($i = 1; $i < count($args); $i++) {
            if ($args[$i] === '-name' && isset($args[$i + 1])) {
                $name = $args[$i + 1];
                break;
            }
        }
        
        if (!$this->filesystem->exists($path)) {
            throw new Exception("find: {$path}: Нет такого файла");
        }
        
        return $this->findFiles($path, $name);
    }
    
    private function findFiles(string $path, ?string $name = null): string
    {
        $output = '';
        
        if ($this->filesystem->isFile($path)) {
            if ($name === null || basename($path) === $name) {
                $output .= $path . "\n";
            }
            return $output;
        }
        
        $files = $this->filesystem->scandir($path);
        
        foreach ($files as $file) {
            $fullPath = $path . '/' . $file;
            
            if ($name === null || $file === $name) {
                $output .= $fullPath . "\n";
            }
            
            if ($this->filesystem->isDir($fullPath)) {
                $output .= $this->findFiles($fullPath, $name);
            }
        }
        
        return $output;
    }
    
    private function cmdXargs(array $args): string
    {
        return "xargs: не реализован\n";
    }
    
    private function cmdTar(array $args): string
    {
        return "tar: не реализован\n";
    }
}
