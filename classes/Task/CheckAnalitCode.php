<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Задача checkAnalitCode (модуль task).
 *
 * Назначение:
 *   Переносит события таблицы events с заданными кодами analit в очередь
 *   cardindev (operation = 2) и сохраняет последний обработанный id_event
 *   в файл idevent.txt, чтобы следующий запуск продолжал с этого места.
 *
 * Алгоритм:
 *   1. Читает --code (по умолчанию 501,502,503,504,505,506).
 *   2. Читает последний id_event из modules/task/idevent.txt. Если файла нет,
 *      берёт min(e.id_event) за сегодня (datetime > current_date
 *      и datetime < current_timestamp).
 *   3. Пишет в лог старт задачи.
 *   4. Выбирает события:
 *        SELECT e.id_event, e.id_card, e.id_dev, e.ess1
 *          FROM events e
 *         WHERE e.analit IN (<code>)
 *           AND e.id_event > <последний id_event>
 *         ORDER BY e.id_event
 *      (в ТЗ условие записано как "e.id_event > e.id_event"; правая часть —
 *       это значение, сохранённое в idevent.txt предыдущим запуском).
 *   5. Фиксирует набор данных в логе.
 *   6. Для каждой строки выполняет INSERT в cardindev и пишет результат в лог.
 *   7. Сохраняет последний e.id_event в idevent.txt.
 *   8. Пишет в лог остановку задачи.
 *
 * Запуск:
 *   c:\xampp\php\php.exe c:\xampp\htdocs\city\modules\minion\minion --task=checkAnalitCode
 *   c:\xampp\php\php.exe ... --task=checkAnalitCode --code=501,502,503
 *   c:\xampp\php\php.exe ... --task=checkAnalitCode --code=504 --dry-run=1
 *
 * Параметры:
 *   --code     Набор цифр (кодов analit) через запятую.
 *   --dry-run  1 — только выборка и запись в лог, без INSERT и без изменения
 *              idevent.txt (безопасный режим по умолчанию).
 *              0 — выполнять реальные INSERT.
 *
 * Файлы:
 *   modules/task/idevent.txt               — последний обработанный id_event;
 *   modules/task/logs/checkAnalitCode.log  — лог-файл задачи (дописывается).
 *
 * @package    task
 * @subpackage Task
 */
class Task_CheckAnalitCode extends Minion_Task
{
    /** Коды analit, используемые, если --code не указан. */
    const DEFAULT_CODES = '501,502,503,504,505,506';

    /**
     * Опции задачи.
     *
     * dry-run по умолчанию 1: запуск без параметров ничего не пишет в БД.
     * Для реальных вставок нужно явно указать --dry-run=0.
     */
    protected $_options = array(
        'code'    => NULL,
        'dry-run' => 1,
    );

    /** @var string Каталог модуля task. */
    protected $_module_dir;

    /** @var string Путь к файлу idevent.txt. */
    protected $_state_file;

    /** @var string Каталог логов. */
    protected $_log_dir;

    /** @var string Путь к лог-файлу. */
    protected $_log_file;

    /** @var resource|null Дескриптор лог-файла. */
    protected $_log_handle = NULL;

    /** @var PDO|null Подключение к БД. */
    protected $_pdo = NULL;

    /** @var array Счётчики выполнения. */
    protected $_stats = array(
        'rows'   => 0,
        'ok'     => 0,
        'failed' => 0,
        'saved'  => NULL,
    );

    /**
     * Точка входа Minion.
     *
     * @param  array $params
     * @return void
     */
    protected function _execute(array $params)
    {
        $this->_init_paths();

        if ( ! $this->_open_log())
        {
            $this->_console_write('ОШИБКА: не удалось открыть лог-файл: '.$this->_log_file);
            return;
        }

        $started = microtime(TRUE);

        $this->_log('============================================================');
        $this->_log('[START] Задача checkAnalitCode запущена');
        $this->_log('Время старта: '.date('Y-m-d H:i:s'));
        $this->_log('Лог-файл: '.$this->_log_file);
        $this->_log('Файл состояния: '.$this->_state_file);

        try
        {
            $this->_run($params);
        }
        catch (Exception $e)
        {
            $this->_log('[ERROR] Критическая ошибка: '.$e->getMessage());
            $this->_log('[ERROR] '.$e->getTraceAsString());
        }

        $this->_log('[STOP] Задача checkAnalitCode остановлена');
        $this->_log('Время работы: '.number_format(microtime(TRUE) - $started, 3, '.', '').' сек.');
        $this->_log('Итого: строк '.$this->_stats['rows']
            .', успешно '.$this->_stats['ok']
            .', ошибок '.$this->_stats['failed']
            .($this->_stats['saved'] === NULL ? '' : ', idevent.txt = '.$this->_stats['saved']));
        $this->_log('============================================================');
        $this->_log('');

        $this->_close_log();
    }

    /**
     * Основной сценарий задачи.
     *
     * @param  array $params
     * @return void
     */
    protected function _run(array $params)
    {
        $raw_code = Arr::get($params, 'code');
        $codes    = $this->_parse_codes($raw_code);

        if ($codes === FALSE)
        {
            $this->_log('[ERROR] Задача прервана: некорректный параметр --code.');
            return;
        }

        $default_used = ($raw_code === NULL || trim((string) $raw_code) === '');

        $this->_log('Параметр --code: '.($default_used ? 'не указан' : (string) $raw_code));
        $this->_log('Коды analit: '.implode(', ', $codes).($default_used ? ' (по умолчанию)' : ''));

        $dry_run = ((int) Arr::get($params, 'dry-run', 0) === 1);

        if ($dry_run)
        {
            $this->_log('[INFO] Режим DRY-RUN: INSERT выполняться не будут, idevent.txt не изменится.');
        }

        if ( ! $this->_connect_db())
        {
            return;
        }

        // 2. Последний id_event: из idevent.txt, а если файла нет —
        //    минимальный id_event за сегодня.
        $last_id = $this->_resolve_start_event_id();

        if ($last_id === FALSE)
        {
            return;
        }

        $rows = $this->_fetch_events($codes, $last_id);

        if ($rows === FALSE)
        {
            return;
        }

        $this->_stats['rows'] = count($rows);
        $this->_log('Получено строк: '.$this->_stats['rows']);

        if (empty($rows))
        {
            $this->_log('[INFO] Новых событий нет. idevent.txt не изменён.');
            return;
        }

        // 1. Зафиксировать набор данных в лог-файле.
        $this->_log_dataset($rows);

        $total        = count($rows);
        $max_event_id = 0;
        $i            = 0;

        foreach ($rows as $row)
        {
            $i++;
            $event_id = (int) Arr::get($row, 'ID_EVENT');

            $row_label = 'id_event='.$event_id
                .' id_card='.$this->_format_value(Arr::get($row, 'ID_CARD'))
                .' id_dev='.$this->_format_value(Arr::get($row, 'ID_DEV'))
                .' ess1='.$this->_format_value(Arr::get($row, 'ESS1'));

            if ($dry_run)
            {
                $this->_log('[DRY-RUN] '.$row_label.' — INSERT пропущен', FALSE);
                $this->_stats['ok']++;
                $max_event_id = $event_id;
            }
            else
            {
                $sql = $this->_build_insert_sql($row);

                try
                {
                    $affected = $this->_pdo->exec($sql);

                    $this->_stats['ok']++;
                    $max_event_id = $event_id;

                    // 2. Зафиксировать в лог-файле результат выполнения запроса.
                    $this->_log('[INSERT] '.$row_label
                        .' — OK (затронуто строк: '.(int) $affected.')', FALSE);
                }
                catch (Exception $e)
                {
                    $this->_stats['failed']++;
                    $this->_log('[INSERT] '.$row_label.' — ОШИБКА: '.$e->getMessage(), FALSE);
                    $this->_log('[INSERT] SQL: '.$sql, FALSE);
                }
            }

            if ($i % 100 === 0 || $i === $total)
            {
                $this->_log('Прогресс: '.$i.' / '.$total
                    .' (успешно '.$this->_stats['ok'].', ошибок '.$this->_stats['failed'].')');
            }
        }

        if ($dry_run)
        {
            $this->_log('[INFO] DRY-RUN завершён: вставки не выполнялись, idevent.txt не изменён.');
            return;
        }

        if ($this->_stats['failed'] > 0)
        {
            $this->_log('[WARNING] '.$this->_stats['failed'].' INSERT завершились ошибкой.');
            $this->_log('[WARNING] idevent.txt не изменён, чтобы не потерять необработанные события.'
                .' Устраните причину и запустите задачу повторно.');
            return;
        }

        // 3. Последний по порядку e.id_event сохранить в файл idevent.txt.
        if ($max_event_id > 0 && $this->_save_last_event_id($max_event_id))
        {
            $this->_stats['saved'] = $max_event_id;
        }
    }

    // -----------------------------------------------------------------
    // Пути и лог-файл
    // -----------------------------------------------------------------

    /**
     * Определяет пути к файлам модуля.
     *
     * @return void
     */
    protected function _init_paths()
    {
        $this->_module_dir = dirname(dirname(dirname(__FILE__)));
        $this->_state_file = $this->_module_dir.DIRECTORY_SEPARATOR.'idevent.txt';
        $this->_log_dir    = $this->_module_dir.DIRECTORY_SEPARATOR.'logs';
        $this->_log_file   = $this->_log_dir.DIRECTORY_SEPARATOR.'checkAnalitCode.log';
    }

    /**
     * Открывает лог-файл на дописывание, создавая каталог при необходимости.
     *
     * @return boolean
     */
    protected function _open_log()
    {
        if ( ! is_dir($this->_log_dir))
        {
            if ( ! @mkdir($this->_log_dir, 0777, TRUE) && ! is_dir($this->_log_dir))
            {
                return FALSE;
            }
        }

        $this->_log_handle = @fopen($this->_log_file, 'a');

        return ($this->_log_handle !== FALSE);
    }

    /**
     * Закрывает лог-файл.
     *
     * @return void
     */
    protected function _close_log()
    {
        if (is_resource($this->_log_handle))
        {
            fclose($this->_log_handle);
        }

        $this->_log_handle = NULL;
    }

    /**
     * Пишет строку в лог-файл и (опционально) в консоль.
     *
     * @param  string  $message
     * @param  boolean $console
     * @return void
     */
    protected function _log($message, $console = TRUE)
    {
        $line = date('Y-m-d H:i:s')."\t".$message.PHP_EOL;

        if (is_resource($this->_log_handle))
        {
            fwrite($this->_log_handle, $line);
            fflush($this->_log_handle);
        }

        if ($console)
        {
            $this->_console_write($message);
        }
    }

    /** @var string|null Кодировка консоли (определяется один раз). */
    protected static $_console_encoding = NULL;

    /**
     * Выводит строку в консоль с учётом кодовой страницы Windows.
     *
     * Лог-файл всегда пишется в UTF-8, а консоль cmd.exe обычно работает
     * в CP866, поэтому без перекодировки русский текст выглядит как
     * "кракозябры". Кодовая страница определяется через chcp.
     *
     * @param  string $message
     * @return void
     */
    protected function _console_write($message)
    {
        Minion_CLI::write($this->_to_console_encoding($message));
    }

    /**
     * Перекодирует сообщение из UTF-8 в кодировку консоли.
     *
     * @param  string $message
     * @return string
     */
    protected function _to_console_encoding($message)
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN' || ! function_exists('iconv'))
        {
            return $message;
        }

        if (self::$_console_encoding === NULL)
        {
            self::$_console_encoding = $this->_detect_console_encoding();
        }

        if (self::$_console_encoding === '' || self::$_console_encoding === 'UTF-8')
        {
            return $message;
        }

        $converted = @iconv('UTF-8', self::$_console_encoding.'//TRANSLIT', $message);

        return ($converted === FALSE) ? $message : $converted;
    }

    /**
     * Определяет кодовую страницу консоли через chcp.
     *
     * @return string
     */
    protected function _detect_console_encoding()
    {
        if (function_exists('exec'))
        {
            $output = array();
            @exec('chcp', $output);

            if (preg_match('/(\d{3,5})/', implode(' ', $output), $matches))
            {
                $codepage = (int) $matches[1];

                if ($codepage === 65001) return 'UTF-8';
                if ($codepage === 866)   return 'CP866';
                if ($codepage === 1251)  return 'CP1251';
                if ($codepage === 437)   return 'CP437';

                return 'CP'.$codepage;
            }
        }

        // Стандартная кодовая страница cmd.exe в русской Windows.
        return 'CP866';
    }

    // -----------------------------------------------------------------
    // Параметры и файл состояния
    // -----------------------------------------------------------------

    /**
     * Разбирает --code в массив целых кодов analit.
     *
     * @param  mixed $raw
     * @return array|boolean Массив кодов или FALSE при ошибке.
     */
    protected function _parse_codes($raw)
    {
        if ($raw === NULL || trim((string) $raw) === '')
        {
            $raw = self::DEFAULT_CODES;
        }

        $codes = array();

        foreach (explode(',', (string) $raw) as $part)
        {
            $part = trim($part);

            if ($part === '')
            {
                continue;
            }

            if ( ! ctype_digit($part))
            {
                $this->_log('[ERROR] Недопустимое значение в --code: "'.$part.'".'
                    .' Ожидается набор цифр через запятую.');
                return FALSE;
            }

            $code = (int) $part;

            // analit в БД — INTEGER (32 бита); защищаемся от переполнения SQL.
            if ($code > 2147483647)
            {
                $this->_log('[ERROR] Код analit вне допустимого диапазона: '.$part);
                return FALSE;
            }

            $codes[$code] = $code;
        }

        if (empty($codes))
        {
            $this->_log('[ERROR] Параметр --code не содержит ни одного числа.');
            return FALSE;
        }

        ksort($codes);

        return array_values($codes);
    }

    /**
     * Читает последний id_event из idevent.txt.
     *
     * Метод рассчитан на существующий файл. Если файла нет (например, его
     * удалили между проверкой и чтением) или он пуст — возвращает 0.
     * Если в файле несколько строк, берётся последнее числовое значение.
     *
     * @return integer
     */
    protected function _read_last_event_id()
    {
        if ( ! is_file($this->_state_file))
        {
            $this->_log('[WARNING] Файл '.$this->_state_file
                .' не найден при чтении. Используется id_event = 0.');
            return 0;
        }

        $content = @file_get_contents($this->_state_file);

        if ($content === FALSE)
        {
            $this->_log('[WARNING] Не удалось прочитать '.$this->_state_file.'. Начинаем с id_event = 0.');
            return 0;
        }

        $last = 0;

        foreach (preg_split('/\r\n|\r|\n/', $content) as $line)
        {
            $line = trim($line);

            if ($line === '')
            {
                continue;
            }

            if (ctype_digit($line))
            {
                $last = (int) $line;
            }
            else
            {
                $this->_log('[WARNING] В '.$this->_state_file
                    .' пропущена строка с нечисловым значением: '.$line);
            }
        }

        return $last;
    }

    /**
     * Определяет стартовый id_event.
     *
     * Если idevent.txt существует — берётся сохранённое значение.
     * Если файла нет — минимальный id_event за сегодня
     * (datetime > current_date и datetime < current_timestamp).
     *
     * @return integer|boolean Стартовый id_event или FALSE, если обрабатывать нечего.
     */
    protected function _resolve_start_event_id()
    {
        if (is_file($this->_state_file))
        {
            $last_id = $this->_read_last_event_id();

            $this->_log('Последний обработанный id_event: '.$last_id
                .' (выборка: id_event > '.$last_id.')');

            return $last_id;
        }

        $this->_log('[INFO] Файл '.$this->_state_file.' не найден.');

        $min_event_id = $this->_today_min_event_id();

        if ($min_event_id === FALSE)
        {
            return FALSE;
        }

        if ($min_event_id === NULL)
        {
            $this->_log('[INFO] За сегодня событий нет. Обрабатывать нечего.');
            return FALSE;
        }

        $this->_log('[INFO] Стартовый id_event = min(id_event) за сегодня: '.$min_event_id
            .' (выборка: id_event > '.$min_event_id.')');

        return $min_event_id;
    }

    /**
     * Возвращает минимальный id_event за сегодня.
     *
     * @return integer|NULL|boolean NULL — событий за сегодня нет; FALSE — ошибка.
     */
    protected function _today_min_event_id()
    {
        $sql = 'SELECT MIN(e.id_event) AS MIN_EVENT_ID'
            .' FROM events e'
            .' WHERE e.datetime > current_date'
            .' AND e.datetime < current_timestamp';

        $this->_log('[SELECT] SQL (стартовый id_event): '.$sql);

        try
        {
            $row = $this->_pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        }
        catch (Exception $e)
        {
            $this->_log('[ERROR] Ошибка выборки стартового id_event: '.$e->getMessage());
            return FALSE;
        }

        if ( ! is_array($row))
        {
            $this->_log('[ERROR] Не удалось получить min(id_event) за сегодня.');
            return FALSE;
        }

        $min_event_id = Arr::get($row, 'MIN_EVENT_ID');

        return ($min_event_id === NULL) ? NULL : (int) $min_event_id;
    }

    /**
     * Сохраняет последний обработанный id_event в idevent.txt.
     *
     * @param  integer $id
     * @return boolean
     */
    protected function _save_last_event_id($id)
    {
        $id      = (int) $id;
        $written = @file_put_contents($this->_state_file, $id.PHP_EOL, LOCK_EX);

        if ($written === FALSE)
        {
            $this->_log('[ERROR] Не удалось записать id_event='.$id.' в '.$this->_state_file);
            return FALSE;
        }

        $this->_log('[STATE] Последний id_event сохранён в '.$this->_state_file.': '.$id);

        return TRUE;
    }

    // -----------------------------------------------------------------
    // Работа с БД
    // -----------------------------------------------------------------

    /**
     * Открывает соединение с БД.
     *
     * Используется PDO напрямую, а не Database::instance('fb'):
     * Kohana_Database_PDO после каждого INSERT вызывает PDO::lastInsertId(),
     * а драйвер Gemini InterBase ODBC эту функцию не поддерживает
     * (SQLSTATE[IM001]). В результате DB::query(Database::INSERT, ...)
     * бросает исключение уже после успешной вставки, и задача считала бы
     * строку ошибочной. Прямой PDO::exec() такой проблемы не имеет.
     *
     * Параметры подключения берутся из application/config/database.php
     * (подключение "fb"), поэтому DSN остаётся единым для приложения.
     *
     * @return boolean
     */
    protected function _connect_db()
    {
        $config = Kohana::$config->load('database')->get('fb');

        if ( ! is_array($config))
        {
            $this->_log('[ERROR] В конфигурации отсутствует подключение "fb".');
            return FALSE;
        }

        $connection = Arr::get($config, 'connection', array());
        $dsn        = Arr::get($connection, 'dsn');

        if (empty($dsn))
        {
            $this->_log('[ERROR] В конфигурации подключения "fb" не задан DSN.');
            return FALSE;
        }

        try
        {
            $this->_pdo = new PDO(
                $dsn,
                Arr::get($connection, 'username'),
                Arr::get($connection, 'password'),
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
            );
        }
        catch (Exception $e)
        {
            $this->_log('[ERROR] Не удалось подключиться к БД ('.$dsn.'): '.$e->getMessage());
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Выбирает события для обработки.
     *
     * @param  array   $codes   Коды analit.
     * @param  integer $last_id Последний обработанный id_event.
     * @return array|boolean
     */
    protected function _fetch_events(array $codes, $last_id)
    {
        $sql = 'SELECT e.id_event, e.id_card, e.id_dev, e.ess1'
            .' FROM events e'
            .' WHERE e.analit IN ('.implode(', ', $codes).')'
            .' AND e.id_event > '.(int) $last_id
            .' ORDER BY e.id_event';

        $this->_log('[SELECT] SQL: '.$sql);

        try
        {
            $statement = $this->_pdo->query($sql);
            $rows      = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();
        }
        catch (Exception $e)
        {
            $this->_log('[ERROR] Ошибка выборки событий: '.$e->getMessage());
            return FALSE;
        }

        return $rows;
    }

    /**
     * Пишет набор данных в лог-файл (только в файл, без консоли).
     *
     * @param  array $rows
     * @return void
     */
    protected function _log_dataset(array $rows)
    {
        $this->_log('--- НАБОР ДАННЫХ ('.count($rows).' строк) ---');
        $this->_console_write('Строки набора записаны в лог-файл: '.$this->_log_file);
        $this->_log('id_event'."\t".'id_card'."\t".'id_dev'."\t".'ess1', FALSE);

        foreach ($rows as $row)
        {
            $this->_log(
                (int) Arr::get($row, 'ID_EVENT')."\t"
                .$this->_format_value(Arr::get($row, 'ID_CARD'))."\t"
                .$this->_format_value(Arr::get($row, 'ID_DEV'))."\t"
                .$this->_format_value(Arr::get($row, 'ESS1')),
                FALSE
            );
        }

        $this->_log('--- КОНЕЦ НАБОРА ДАННЫХ ---');
    }

    /**
     * Строит INSERT в cardindev для одной строки выборки.
     *
     * @param  array  $row
     * @return string
     */
    protected function _build_insert_sql(array $row)
    {
        $id_card = Arr::get($row, 'ID_CARD');

        $id_card_sql = ($id_card === NULL)
            ? 'NULL'
            : $this->_quote_string(trim((string) $id_card));

        return 'INSERT INTO cardindev (id_db, id_card, devidx, id_dev, operation,'
            .' attempts, id_pep, time_stamp, id_cardtype, fromuser)'
            .' VALUES (1, '.$id_card_sql
            .', null, '.$this->_int_or_null(Arr::get($row, 'ID_DEV')).', 2,'
            .' 0, '.$this->_int_or_null(Arr::get($row, 'ESS1'))
            .', current_timestamp, null, current_user)';
    }

    /**
     * Экранирует строковое значение для SQL (одинарные кавычки).
     *
     * @param  string $value
     * @return string
     */
    protected function _quote_string($value)
    {
        return "'".str_replace("'", "''", (string) $value)."'";
    }

    /**
     * Возвращает целое число или NULL для SQL.
     *
     * @param  mixed $value
     * @return string
     */
    protected function _int_or_null($value)
    {
        if ($value === NULL || $value === '')
        {
            return 'NULL';
        }

        return (string) (int) $value;
    }

    /**
     * Представление значения для лога.
     *
     * @param  mixed $value
     * @return string
     */
    protected function _format_value($value)
    {
        return ($value === NULL) ? 'NULL' : trim((string) $value);
    }
}
