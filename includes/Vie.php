<?php

namespace BoltOS;

use Exception;

/**
 * Класс Vie - VIm Easy (простой текстовый редактор)
 */
class Vie
{
    private Filesystem $filesystem;
    private array $buffer;
    private int $cursorX;
    private int $cursorY;
    private int $scrollX;
    private int $scrollY;
    private string $filename;
    private bool $modified;
    private string $statusMessage;
    
    // Цвета Gruvbox Dark
    private const GRUVBOX = [
        'bg' => "\033[48;2;40;40;40m",
        'fg' => "\033[38;2;235;219;178m",
        'status_bg' => "\033[48;2;60;56;54m",
        'status_fg' => "\033[38;2;168;153;132m",
        'line_num' => "\033[38;2;124;111;100m",
        'reset' => "\033[0m",
    ];
    
    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
        $this->buffer = [''];
        $this->cursorX = 0;
        $this->cursorY = 0;
        $this->scrollX = 0;
        $this->scrollY = 0;
        $this->filename = '';
        $this->modified = false;
        $this->statusMessage = '';
    }
    
    /**
     * Открытие файла
     */
    public function open(string $filename): void
    {
        $this->filename = $filename;
        
        if ($this->filesystem->exists($filename)) {
            if ($this->filesystem->isDir($filename)) {
                throw new Exception("{$filename} является директорией");
            }
            
            $content = $this->filesystem->readFile($filename);
            if ($content !== false) {
                $this->buffer = explode("\n", $content);
            } else {
                $this->buffer = [''];
            }
        } else {
            $this->buffer = [''];
        }
        
        $this->cursorX = 0;
        $this->cursorY = 0;
        $this->scrollX = 0;
        $this->scrollY = 0;
        $this->modified = false;
        $this->statusMessage = '';
    }
    
    /**
     * Сохранение файла
     */
    public function save(): bool
    {
        $content = implode("\n", $this->buffer);
        return $this->filesystem->writeFile($this->filename, $content);
    }
    
    /**
     * Запуск редактора
     */
    public function run(string $filename = ''): void
    {
        if ($filename) {
            try {
                $this->open($filename);
            } catch (Exception $e) {
                echo "\033[38;2;251;73;68mОшибка: " . $e->getMessage() . "\033[0m\n";
                return;
            }
        }
        
        // Отключаем эхо, включаем сырой режим и отключаем flow control
        system('stty -echo -icanon -ixon');
        
        try {
            $this->mainLoop();
        } finally {
            // Восстанавливаем настройки терминала
            system('stty echo icanon ixon');
            echo "\033[2J\033[H"; // Очищаем экран
        }
    }
    
    /**
     * Главный цикл редактора
     */
    private function mainLoop(): void
    {
        while (true) {
            $this->render();
            
            $char = fread(STDIN, 1);
            
            if ($char === "\033") {
                // Escape sequence
                $seq = fread(STDIN, 2);
                
                if ($seq === '[A') {
                    // Стрелка вверх
                    $this->moveCursor(-1, 0);
                } elseif ($seq === '[B') {
                    // Стрелка вниз
                    $this->moveCursor(1, 0);
                } elseif ($seq === '[C') {
                    // Стрелка вправо
                    $this->moveCursor(0, 1);
                } elseif ($seq === '[D') {
                    // Стрелка влево
                    $this->moveCursor(0, -1);
                } elseif ($seq === '[5') {
                    // Page Up
                    $c = fread(STDIN, 1);
                    if ($c === '~') {
                        $this->scrollY = max(0, $this->scrollY - 10);
                        $this->cursorY = max(0, $this->cursorY - 10);
                    }
                } elseif ($seq === '[6') {
                    // Page Down
                    $c = fread(STDIN, 1);
                    if ($c === '~') {
                        $this->scrollY = min(count($this->buffer) - 1, $this->scrollY + 10);
                        $this->cursorY = min(count($this->buffer) - 1, $this->cursorY + 10);
                    }
                }
            } elseif ($char === "\177" || $char === "\010") {
                // Backspace
                $this->deleteChar();
            } elseif ($char === "\004") {
                // Ctrl+D - выход
                if ($this->modified) {
                    $this->statusMessage = "Файл изменен. Используйте Ctrl+O для сохранения";
                } else {
                    break;
                }
            } elseif ($char === "\013") {
                // Ctrl+K - вырезать строку
                $this->cutLine();
            } elseif ($char === "\017") {
                // Ctrl+O - сохранить
                if ($this->save()) {
                    $this->modified = false;
                    $this->statusMessage = "Файл сохранен";
                } else {
                    $this->statusMessage = "Ошибка сохранения";
                }
            } elseif ($char === "\023") {
                // Ctrl+S - сохранить и выйти
                if ($this->save()) {
                    break;
                } else {
                    $this->statusMessage = "Ошибка сохранения";
                }
            } elseif ($char === "\n") {
                // Enter
                $this->insertNewline();
            } elseif ($char === "\t") {
                // Tab
                $this->insertChar("    ");
            } elseif (ord($char) >= 32) {
                // Обычный символ
                $this->insertChar($char);
            }
        }
    }
    
    /**
     * Перемещение курсора
     */
    private function moveCursor(int $dy, int $dx): void
    {
        $newY = $this->cursorY + $dy;
        $newX = $this->cursorX + $dx;
        
        // Ограничиваем по Y
        $newY = max(0, min(count($this->buffer) - 1, $newY));
        
        // Ограничиваем по X
        $lineLen = strlen($this->buffer[$newY] ?? '');
        $newX = max(0, min($lineLen, $newX));
        
        $this->cursorY = $newY;
        $this->cursorX = $newX;
        
        // Прокрутка
        $termHeight = $this->getTerminalHeight() - 2; // Минус статус бар
        
        if ($this->cursorY < $this->scrollY) {
            $this->scrollY = $this->cursorY;
        } elseif ($this->cursorY >= $this->scrollY + $termHeight) {
            $this->scrollY = $this->cursorY - $termHeight + 1;
        }
    }
    
    /**
     * Вставка символа
     */
    private function insertChar(string $char): void
    {
        $line = $this->buffer[$this->cursorY] ?? '';
        $before = substr($line, 0, $this->cursorX);
        $after = substr($line, $this->cursorX);
        
        $this->buffer[$this->cursorY] = $before . $char . $after;
        $this->cursorX += strlen($char);
        $this->modified = true;
    }
    
    /**
     * Удаление символа
     */
    private function deleteChar(): void
    {
        if ($this->cursorX > 0) {
            // Удаляем символ в текущей строке
            $line = $this->buffer[$this->cursorY];
            $before = substr($line, 0, $this->cursorX - 1);
            $after = substr($line, $this->cursorX);
            
            $this->buffer[$this->cursorY] = $before . $after;
            $this->cursorX--;
            $this->modified = true;
        } elseif ($this->cursorY > 0) {
            // Объединяем с предыдущей строкой
            $prevLine = $this->buffer[$this->cursorY - 1];
            $currLine = $this->buffer[$this->cursorY];
            
            $this->buffer[$this->cursorY - 1] = $prevLine . $currLine;
            $this->cursorX = strlen($prevLine);
            array_splice($this->buffer, $this->cursorY, 1);
            $this->cursorY--;
            $this->modified = true;
        }
    }
    
    /**
     * Вставка новой строки
     */
    private function insertNewline(): void
    {
        $line = $this->buffer[$this->cursorY] ?? '';
        $before = substr($line, 0, $this->cursorX);
        $after = substr($line, $this->cursorX);
        
        $this->buffer[$this->cursorY] = $before;
        array_splice($this->buffer, $this->cursorY + 1, 0, [$after]);
        
        $this->cursorY++;
        $this->cursorX = 0;
        $this->modified = true;
    }
    
    /**
     * Вырезание строки
     */
    private function cutLine(): void
    {
        if (count($this->buffer) > 1) {
            array_splice($this->buffer, $this->cursorY, 1);
            $this->cursorY = min($this->cursorY, count($this->buffer) - 1);
            $this->cursorX = 0;
            $this->modified = true;
        } else {
            $this->buffer[$this->cursorY] = '';
            $this->cursorX = 0;
            $this->modified = true;
        }
    }
    
    /**
     * Отрисовка редактора
     */
    private function render(): void
    {
        echo "\033[H"; // Перемещаем курсор в начало
        
        $termWidth = $this->getTerminalWidth();
        $termHeight = $this->getTerminalHeight();
        
        // Отрисовываем строки
        $endLine = min($this->scrollY + $termHeight - 2, count($this->buffer));
        
        for ($i = $this->scrollY; $i < $endLine; $i++) {
            $line = $this->buffer[$i] ?? '';
            $lineNum = $i + 1;
            
            // Номер строки
            echo self::GRUVBOX['line_num'];
            echo sprintf("%4d ", $lineNum);
            echo self::GRUVBOX['reset'];
            
            // Содержимое строки
            echo self::GRUVBOX['fg'];
            echo self::GRUVBOX['bg'];
            
            // Обрезаем строку если она длиннее экрана
            if (strlen($line) > $termWidth - 6) {
                $line = substr($line, 0, $termWidth - 6);
            }
            
            echo str_pad($line, $termWidth - 6);
            echo self::GRUVBOX['reset'];
            echo "\n";
        }
        
        // Заполняем пустые строки
        for ($i = $endLine; $i < $termHeight - 1; $i++) {
            echo self::GRUVBOX['line_num'];
            echo "    ~ ";
            echo self::GRUVBOX['reset'];
            echo str_repeat(' ', $termWidth - 6) . "\n";
        }
        
        // Статус бар
        echo self::GRUVBOX['status_bg'];
        echo self::GRUVBOX['status_fg'];
        
        $status = " Файл: " . ($this->filename ?: 'Без названия');
        if ($this->modified) {
            $status .= " [Изменен]";
        }
        $status .= " | Строка: " . ($this->cursorY + 1) . "/" . count($this->buffer);
        $status .= " | Столбец: " . ($this->cursorX + 1);
        $status .= " | Ctrl+O: Сохранить | Ctrl+S: Сохранить и выйти | Ctrl+D: Выход";
        
        echo str_pad($status, $termWidth);
        echo self::GRUVBOX['reset'];
        
        // Сообщение
        if ($this->statusMessage) {
            echo "\n" . self::GRUVBOX['status_bg'] . self::GRUVBOX['status_fg'];
            echo str_pad(" " . $this->statusMessage, $termWidth);
            echo self::GRUVBOX['reset'];
            $this->statusMessage = '';
        }
        
        // Позиционируем курсор
        $screenY = $this->cursorY - $this->scrollY + 1;
        $screenX = $this->cursorX + 6; // +6 для номера строки
        echo "\033[{$screenY};{$screenX}H";
    }
    
    /**
     * Получение высоты терминала
     */
    private function getTerminalHeight(): int
    {
        return (int)exec('tput lines') ?: 24;
    }
    
    /**
     * Получение ширины терминала
     */
    private function getTerminalWidth(): int
    {
        return (int)exec('tput cols') ?: 80;
    }
}
