# task — служебные задачи

Модуль CLI-задач Kohana/Minion.

## Задача checkAnalitCode

Переносит события `events` с заданными кодами `analit` в очередь `cardindev`
(`operation = 2`) и ведёт указатель последнего обработанного `id_event`
в файле `idevent.txt`.

Класс: `modules/task/classes/Task/CheckAnalitCode.php`
(`Task_CheckAnalitCode`).

### Запуск

Безопасный запуск (по умолчанию `--dry-run=1`, только выборка и лог):

```bat
c:\xampp\php\php.exe c:\xampp\htdocs\city\modules\minion\minion --task=checkAnalitCode
```

Реальные вставки в `cardindev` — только с явным `--dry-run=0`:

```bat
c:\xampp\php\php.exe c:\xampp\htdocs\city\modules\minion\minion --task=checkAnalitCode --dry-run=0
```

Или через `run_checkAnalitCode.bat` (аргументы пробрасываются):

```bat
run_checkAnalitCode.bat --code=501,502,503
run_checkAnalitCode.bat --code=504 --dry-run=0
```

### Параметры

| Параметр | По умолчанию | Описание |
|---|---|---|
| `--code` | `501,502,503,504,505,506` | набор кодов `analit` через запятую |
| `--dry-run` | `1` | `1` — только выборка и запись в лог, без `INSERT` и без изменения `idevent.txt` (безопасный режим); `0` — выполнять реальные `INSERT` |

Если `--code` не указан (или пуст), используются коды по умолчанию.
Каждый элемент `--code` должен состоять только из цифр.

### Файлы

| Файл | Назначение |
|---|---|
| `modules/task/idevent.txt` | последний обработанный `id_event` |
| `modules/task/logs/checkAnalitCode.log` | лог работы задачи (дописывается) |

Лог-файл создаётся при первом запуске, `idevent.txt` — после первой успешной
обработки событий.

> Если файла `idevent.txt` нет, последним обработанным считается минимальный
> `id_event` за сегодня (см. запрос ниже). Дальше обрабатываются события
> с `id_event` строго больше этого значения. По умолчанию включён безопасный
> режим `--dry-run=1`; для реальной обработки укажите `--dry-run=0`.

### SQL

Если `idevent.txt` отсутствует, стартовый `id_event` определяется так:

```sql
SELECT MIN(e.id_event)
FROM events e
WHERE e.datetime > current_date
  AND e.datetime < current_timestamp
```

Если запрос вернул `NULL` (за сегодня событий нет), задача завершается без выборки.

Основная выборка:

```sql
SELECT e.id_event, e.id_card, e.id_dev, e.ess1
FROM events e
WHERE e.analit IN (501,502,503,504,505,506)
  AND e.id_event > <стартовый id_event>
ORDER BY e.id_event
```

Вставка (для каждой строки выборки):

```sql
INSERT INTO cardindev (id_db, id_card, devidx, id_dev, operation,
                       attempts, id_pep, time_stamp, id_cardtype, fromuser)
VALUES (1, <e.id_card>, null, <e.id_dev>, 2,
        0, <e.ess1>, current_timestamp, null, current_user)
```

Примечание: в ТЗ условие записано как `e.id_event > e.id_event`; правая часть —
это стартовый `id_event`: значение из `idevent.txt`, а если файла нет —
`min(id_event)` за сегодня. Именно так реализована продолжаемая обработка:
следующий запуск берёт только новые события.

### Особенность драйвера БД

`Kohana_Database_PDO` после каждого `INSERT` вызывает `PDO::lastInsertId()`,
а драйвер Gemini InterBase ODBC не поддерживает эту функцию
(`SQLSTATE[IM001]`) — исключение возникает уже после успешной вставки.
Поэтому задача работает с PDO напрямую (`PDO::exec` для `INSERT`,
`PDO::query` для `SELECT`), используя DSN из `application/config/database.php`
(подключение `fb`).

### Порядок работы

1. Открывается лог-файл, в него пишется старт задачи.
2. Читается `--code` (или набор по умолчанию).
3. Читается последний `id_event` из `idevent.txt`; если файла нет — минимальный
   `id_event` за сегодня.
4. Выполняется выборка событий.
5. Набор данных фиксируется в лог-файле.
6. Для каждой строки выполняется `INSERT` в `cardindev` (при `--dry-run=0`),
   результат пишется в лог.
7. Последний `id_event` сохраняется в `idevent.txt`.
8. В лог-файл пишется остановка задачи.

### Поведение при ошибках

- Ошибки `INSERT` фиксируются в логе; обработка продолжается.
- Если хотя бы один `INSERT` завершился ошибкой, `idevent.txt` **не** обновляется,
  чтобы не потерять необработанные события. После устранения причины задачу
  можно запустить повторно — она начнётся с прежнего `id_event`.
- Если подходящих строк не найдено, `idevent.txt` не меняется.
- Если `idevent.txt` отсутствует и за сегодня событий нет, задача завершается
  без выборки и без создания `idevent.txt`.
- Если задача прервана (например, закрыто окно консоли), `idevent.txt` остаётся
  с прежним значением: следующий запуск начнётся с того же `id_event`.

### Проверка без изменения данных

`--dry-run=1` — режим по умолчанию: он ничего не пишет в `cardindev`
и не меняет `idevent.txt`. Реальная обработка включается только явным
`--dry-run=0`.
# city_task
