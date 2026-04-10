<?php

namespace BoltOS;

use Exception;
use BoltOS\Vie;

/**
 * Класс Matter - эмуляция shell с автозаполнением и темами
 */
class Matter
{
    private Filesystem $filesystem;
    private Commands $commands;
    private Vie $vie;
    private string $theme;
    private array $history;
    private int $historyIndex;
    private array $aliases;
    private string $prompt;
    
    // Цвета Gruvbox Dark
    private const GRUVBOX = [
        'bg_hard' => '#1d2021',
        'bg' => '#282828',
        'bg_soft' => '#32302f',
        'bg1' => '#3c3836',
        'bg2' => '#504945',
        'bg3' => '#665c54',
        'bg4' => '#7c6f64',
        'fg' => '#ebdbb2',
        'fg0' => '#fbf1c7',
        'fg1' => '#ebdbb2',
        'fg2' => '#d5c4a1',
        'fg3' => '#bdae93',
        'fg4' => '#a89984',
        'red' => '#cc241d',
        'green' => '#98971a',
        'yellow' => '#d79921',
        'blue' => '#458588',
        'purple' => '#b16286',
        'aqua' => '#689d6a',
        'orange' => '#d65d0e',
        'red_bright' => '#fb4934',
        'green_bright' => '#b8bb26',
        'yellow_bright' => '#fabd2f',
        'blue_bright' => '#83a598',
        'purple_bright' => '#d3869b',
        'aqua_bright' => '#8ec07c',
        'orange_bright' => '#fe8019',
    ];
    
    public function __construct(Filesystem $filesystem, Commands $commands, Vie $vie)
    {
        $this->filesystem = $filesystem;
        $this->commands = $commands;
        $this->vie = $vie;
        $this->theme = 'gruvbox-dark';
        $this->history = $this->loadHistory();
        $this->historyIndex = count($this->history);
        $this->aliases = [
            'll' => 'ls -la',
            'la' => 'ls -a',
            'l' => 'ls',
            'cls' => 'clear',
            'c' => 'clear',
        ];
        $this->prompt = '';
        
        $this->initTheme();
    }
    
    /**
     * Инициализация темы
     */
    private function initTheme(): void
    {
        switch ($this->theme) {
            case 'gruvbox-dark':
                $this->prompt = $this->buildGruvboxPrompt();
                break;
            default:
                $this->prompt = $this->buildDefaultPrompt();
        }
    }
    
    /**
     * Построение промпта в стиле Gruvbox Dark
     */
    private function buildGruvboxPrompt(): string
    {
        $user = $this->filesystem->getUser();
        $hostname = $this->filesystem->getHostname();
        $pwd = $this->filesystem->getPwd();
        
        // Цвета для промпта
        $user_color = "\033[38;2;251;241;199m"; // fg0
        $at_color = "\033[38;2;184;187;38m"; // green_bright
        $host_color = "\033[38;2;131;165;152m"; // blue_bright
        $colon_color = "\033[38;2;251;241;199m"; // fg0
        $path_color = "\033[38;2;254;128;47m"; // orange_bright
        $prompt_color = "\033[38;2;184;187;38m"; // green_bright
        $reset = "\033[0m";
        
        // Укорачиваем путь для домашней директории
        $home = $this->filesystem->getHome();
        if (str_starts_with($pwd, $home)) {
            $pwd = '~' . substr($pwd, strlen($home));
        }
        
        return "{$user_color}{$user}{$at_color}@{$host_color}{$hostname}{$colon_color}:{$path_color}{$pwd}{$prompt_color} λ {$reset}";
    }
    
    /**
     * Построение стандартного промпта
     */
    private function buildDefaultPrompt(): string
    {
        $user = $this->filesystem->getUser();
        $hostname = $this->filesystem->getHostname();
        $pwd = $this->filesystem->getPwd();
        
        return "{$user}@{$hostname}:{$pwd}$ ";
    }
    
    /**
     * Установка темы
     */
    public function setTheme(string $theme): void
    {
        $this->theme = $theme;
        $this->initTheme();
    }
    
    /**
     * Загрузка истории команд
     */
    private function loadHistory(): array
    {
        $historyFile = BOLTOS_STORAGE . '/history.json';
        
        if (!file_exists($historyFile)) {
            return [];
        }
        
        $content = file_get_contents($historyFile);
        $data = json_decode($content, true);
        
        return is_array($data) ? $data : [];
    }
    
    /**
     * Сохранение истории команд
     */
    private function saveHistory(): void
    {
        $historyFile = BOLTOS_STORAGE . '/history.json';
        file_put_contents($historyFile, json_encode($this->history, JSON_PRETTY_PRINT));
    }
    
    /**
     * Добавление команды в историю
     */
    private function addToHistory(string $command): void
    {
        // Не добавляем пустые команды и дубликаты последней команды
        if (empty(trim($command))) {
            return;
        }
        
        if (!empty($this->history) && end($this->history) === $command) {
            return;
        }
        
        $this->history[] = $command;
        
        // Ограничиваем историю 1000 команд
        if (count($this->history) > 1000) {
            $this->history = array_slice($this->history, -1000);
        }
        
        $this->saveHistory();
        $this->historyIndex = count($this->history);
    }
    
    /**
     * Получение команды из истории
     */
    private function getFromHistory(int $index): ?string
    {
        if ($index < 0 || $index >= count($this->history)) {
            return null;
        }
        
        return $this->history[$index];
    }
    
    /**
     * Разбор команды
     */
    private function parseCommand(string $input): array
    {
        $input = trim($input);
        
        if (empty($input)) {
            return ['command' => '', 'args' => []];
        }
        
        // Разделяем на части с учетом кавычек
        $parts = [];
        $current = '';
        $inQuote = false;
        $quoteChar = '';
        
        for ($i = 0; $i < strlen($input); $i++) {
            $char = $input[$i];
            
            if ($inQuote) {
                if ($char === $quoteChar) {
                    $inQuote = false;
                } else {
                    $current .= $char;
                }
            } elseif ($char === '"' || $char === "'") {
                $inQuote = true;
                $quoteChar = $char;
            } elseif ($char === ' ') {
                if ($current !== '') {
                    $parts[] = $current;
                    $current = '';
                }
            } else {
                $current .= $char;
            }
        }
        
        if ($current !== '') {
            $parts[] = $current;
        }
        
        if (empty($parts)) {
            return ['command' => '', 'args' => []];
        }
        
        $command = array_shift($parts);
        
        // Проверяем алиасы
        if (isset($this->aliases[$command])) {
            $aliasParts = explode(' ', $this->aliases[$command]);
            $command = array_shift($aliasParts);
            $parts = array_merge($aliasParts, $parts);
        }
        
        return ['command' => $command, 'args' => $parts];
    }
    
    /**
     * Автозаполнение
     */
    private function autocomplete(string $input): string
    {
        $parts = explode(' ', $input);
        $lastPart = end($parts);
        
        if (empty($lastPart)) {
            return $input;
        }
        
        // Если это первая часть - автозаполнение команд
        if (count($parts) === 1) {
            $availableCommands = $this->commands->getAvailableCommands();
            $matches = array_filter($availableCommands, fn($cmd) => str_starts_with($cmd, $lastPart));
            
            if (count($matches) === 1) {
                return array_pop($matches);
            } elseif (count($matches) > 1) {
                echo "\n";
                foreach ($matches as $match) {
                    echo $match . "  ";
                }
                echo "\n";
                echo $this->buildPrompt();
                echo $input;
                return $input;
            }
            
            return $input;
        }
        
        // Иначе - автозаполнение путей
        $path = $lastPart;
        $dir = dirname($path);
        $base = basename($path);
        
        if ($dir === '.') {
            $dir = $this->filesystem->getPwd();
        } elseif (!str_starts_with($dir, '/')) {
            $dir = $this->filesystem->getPwd() . '/' . $dir;
        }
        
        $files = $this->filesystem->scandir($dir);
        $matches = array_filter($files, fn($file) => str_starts_with($file, $base));
        
        if (count($matches) === 1) {
            $match = array_pop($matches);
            $fullPath = $dir . '/' . $match;
            
            if ($this->filesystem->isDir($fullPath)) {
                $match .= '/';
            }
            
            array_pop($parts);
            $parts[] = $match;
            return implode(' ', $parts);
        } elseif (count($matches) > 1) {
            echo "\n";
            foreach ($matches as $match) {
                $fullPath = $dir . '/' . $match;
                $suffix = $this->filesystem->isDir($fullPath) ? '/' : '';
                echo $match . $suffix . "  ";
            }
            echo "\n";
            echo $this->buildPrompt();
            echo $input;
            return $input;
        }
        
        return $input;
    }
    
    /**
     * Построение промпта
     */
    private function buildPrompt(): string
    {
        return $this->buildGruvboxPrompt();
    }
    
    /**
     * Чтение ввода с поддержкой автозаполнения и истории
     */
    private function readline(): string
    {
        echo $this->buildPrompt();
        
        $input = '';
        $cursor = 0;
        
        // Отключаем эхо и включаем сырой режим
        system('stty -echo -icanon');
        
        try {
            while (true) {
                $char = fread(STDIN, 1);
                
                if ($char === "\n") {
                    echo "\n";
                    break;
                } elseif ($char === "\033") {
                    // Escape sequence
                    $seq = fread(STDIN, 2);
                    
                    if ($seq === '[A') {
                        // Стрелка вверх - история назад
                        if ($this->historyIndex > 0) {
                            $this->historyIndex--;
                            $cmd = $this->getFromHistory($this->historyIndex);
                            if ($cmd !== null) {
                                // Очищаем текущую строку
                                echo "\r\033[K";
                                echo $this->buildPrompt();
                                echo $cmd;
                                $input = $cmd;
                                $cursor = strlen($input);
                            }
                        }
                    } elseif ($seq === '[B') {
                        // Стрелка вниз - история вперед
                        if ($this->historyIndex < count($this->history)) {
                            $this->historyIndex++;
                            if ($this->historyIndex === count($this->history)) {
                                // Пустая строка
                                echo "\r\033[K";
                                echo $this->buildPrompt();
                                $input = '';
                                $cursor = 0;
                            } else {
                                $cmd = $this->getFromHistory($this->historyIndex);
                                if ($cmd !== null) {
                                    echo "\r\033[K";
                                    echo $this->buildPrompt();
                                    echo $cmd;
                                    $input = $cmd;
                                    $cursor = strlen($input);
                                }
                            }
                        }
                    } elseif ($seq === '[C') {
                        // Стрелка вправо
                        if ($cursor < strlen($input)) {
                            $cursor++;
                            echo "\033[C";
                        }
                    } elseif ($seq === '[D') {
                        // Стрелка влево
                        if ($cursor > 0) {
                            $cursor--;
                            echo "\033[D";
                        }
                    }
                } elseif ($char === "\t") {
                    // Tab - автозаполнение
                    $input = $this->autocomplete($input);
                    $cursor = strlen($input);
                    echo "\r\033[K";
                    echo $this->buildPrompt();
                    echo $input;
                } elseif ($char === "\177" || $char === "\010") {
                    // Backspace
                    if ($cursor > 0) {
                        // Перемещаем курсор назад
                        echo "\033[D";
                        // Удаляем символ и перерисовываем остаток строки
                        $input = substr($input, 0, $cursor - 1) . substr($input, $cursor);
                        $rest = substr($input, $cursor - 1);
                        echo $rest . "\033[K";
                        // Возвращаем курсор на место
                        if (strlen($rest) > 0) {
                            echo "\033[" . strlen($rest) . "D";
                        }
                        $cursor--;
                    }
                } elseif ($char === "\003") {
                    // Ctrl+C
                    echo "\n";
                    return '';
                } elseif (ord($char) >= 32) {
                    // Обычный символ
                    $input = substr($input, 0, $cursor) . $char . substr($input, $cursor);
                    $cursor++;
                    echo $char;
                }
            }
        } finally {
            // Восстанавливаем настройки терминала
            system('stty echo icanon');
        }
        
        return $input;
    }
    
    /**
     * Главный цикл shell
     */
    public function run(): void
    {
        // Очищаем экран
        echo "\033[2J\033[H";
        
        // Выводим приветствие
        $this->printWelcome();
        
        while (true) {
            try {
                $input = $this->readline();
                
                if (empty(trim($input))) {
                    continue;
                }
                
                // Добавляем в историю
                $this->addToHistory($input);
                $this->historyIndex = count($this->history);
                
                // Разбираем команду
                $parsed = $this->parseCommand($input);
                $command = $parsed['command'];
                $args = $parsed['args'];
                
                // Выполняем команду
                $result = $this->commands->execute($command, $args);
                
                if ($result === 'exit') {
                    break;
                }
                
                // Выводим результат команды
                if ($result !== '' && $result !== false) {
                    echo $result;
                }
                
            } catch (Exception $e) {
                echo "\033[38;2;251;73;68mОшибка: " . $e->getMessage() . "\033[0m\n";
            }
        }
        
        echo "До свидания!\n";
    }
    
    /**
     * Вывод приветствия
     */
    private function printWelcome(): void
    {
        $hostname = $this->filesystem->getHostname();
        $user = $this->filesystem->getUser();
        
        $welcome = "\033[38;2;184;187;38m";
        $welcome .= "  _           _ _    ___  ____  \n";
        $welcome .= " | |__   ___ | | |_ / _ \\/ ___| \n";
        $welcome .= " | '_ \\ / _ \\| | __| | | \\___ \\ \n";
        $welcome .= " | |_) | (_) | | |_| |_| |___) |\n";
        $welcome .= " |_.__/ \\___/|_|\\__|\\___/|____/ \n";
        $welcome .= "                                 \n";
        $welcome .= "\033[0m\n";
        
        $welcome .= "\033[38;2;131;165;152mВерсия 1.0.0\033[0m\n";
        $welcome .= "\033[38;2;184;187;38mДобро пожаловать, {$user}@{$hostname}!\033[0m\n";
        $welcome .= "\033[38;2;109;109;109mВведите 'help' для списка команд.\033[0m\n\n";
        
        echo $welcome;
    }
}
