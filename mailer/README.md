# Mailer — отправка писем через SMTP

Отдельный контейнер, который забирает из moder записи в статусе `approved` (только
`type=email`) и отправляет их через SMTP. Результат возвращает в moder:
`applied` или `failed` с текстом ошибки. Общается с moder по тому же API воркера.

## Зачем отдельно от модерации

- moder решает «можно ли», mailer умеет «доставить». Разные ответственности.
- SMTP-креды живут только у mailer, php-приложение их не видит.
- В PHP-FPM неудобно делать ретраи, throttle и ожидание SMTP — контейнер-демон удобнее.

## Безопасность по умолчанию

`MAILER_DRY_RUN=1` — мейлер только **читает** и логирует, что отправил бы:

```
DRY-RUN would send <id> from=hello@autrich.ru to=[...] subj=...
```

Ничего не отправляет, не захватывает (`claimed`), не меняет статусы. Чтобы включить
реальную отправку — задать `MAILER_DRY_RUN=0` и SMTP-креды.

## SMTP-аккаунты: отдельные для каждого from

Самый гибкий вариант — `MAILER_ACCOUNTS` (JSON):

```json
{
  "hello@autrich.ru": {"user":"hello@autrich.ru","pass":"app-password-1","host":"smtp.mail.ru","port":465,"from_name":"Autrich"},
  "sales@apitly.ru":  {"user":"sales@apitly.ru","pass":"app-password-2","host":"smtp.mail.ru","port":465,"from_name":"Apitly Sales"}
}
```

Агент кладёт в `payload.from` нужный адрес — мейлер берёт подходящий аккаунт.
Если аккаунта для from нет — письмо уходит в `failed` с внятной ошибкой.

Фолбэк: один аккаунт через `MAILER_SMTP_USER` / `MAILER_SMTP_PASS`.

**mail.ru:** нужен «пароль для внешнего приложения», не обычный пароль
(help.mail.ru/mail/security/protection/external).

## Скорость и распределение

| Переменная | Что делает |
|---|---|
| `MAILER_DAILY_LIMIT` | сколько писем максимум в сутки |
| `MAILER_BATCH_SIZE` | сколько брать за один прогон |
| `MAILER_MODE` | `batch` — пачкой; `spread` — по одному |
| `MAILER_WINDOW` | окно отправки для `spread`, напр. `09:00-18:00` |
| `MAILER_TZ` | таймзона окна |
| `MAILER_POLL_INTERVAL` | пауза между опросами очереди, сек |
| `MAILER_MIN_GAP_SECONDS` | пауза между письмами, сек |

### Примеры настроек

**«5 писем утром и спать»:**
```
MAILER_MODE=batch
MAILER_DAILY_LIMIT=5
MAILER_BATCH_SIZE=5
MAILER_POLL_INTERVAL=3600
MAILER_MIN_GAP_SECONDS=30
```

**«5 писем, размазанных по рабочему дню»:**
```
MAILER_MODE=spread
MAILER_DAILY_LIMIT=5
MAILER_WINDOW=09:00-18:00
MAILER_TZ=Europe/Moscow
MAILER_MIN_GAP_SECONDS=1
```
В режиме `spread` пауза между письмами автоматически распределяется по остатку окна
(с случайным разбросом), плюс уважается дневной лимит.

## Управление

```bash
cd /opt/moder
docker compose logs -f mailer      # что происходит
docker compose restart mailer     # перечитать .env
docker compose stop mailer        # выключить отправку
docker compose up -d mailer       # включить
```

Состояние (сколько отправлено за день) хранится в volume `mailer_state`
(файл `/data/state.json`), переживает рестарты.

## Ошибки

Любая ошибка SMTP -> запись получает `apply_error` и остаётся `approved` для
повторной попытки. После `max_apply_attempts` (по умолчанию 3) -> `failed`.
