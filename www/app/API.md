# moder API — премодерация для агентов

Base URL: `https://moder.example.com` (или http://127.0.0.1:39010 локально)
Авторизация: `Authorization: Bearer <token>` (или `X-Moder-Token: <token>`).

Роли и токены (env на сервере): `MODER_TOKEN_AGENT`, `MODER_TOKEN_MODER`, `MODER_TOKEN_DEVOPS`.

## 1. Создать запись (агент)

    POST /api/queue
    {
      "idempotency_key": "unique-key-001",
      "type": "email|article|site_change|ad|other",
      "project": "myproject",
      "agent_name": "sales-bot",
      "title": "Короткий заголовок для списка",
      "reason": "Зачем это, что проверить",
      "payload": { ... }
    }
    -> 201 { "id": "<uuid>", "status": "pending", "deduplicated": false }
    -> 200 { "id": "<uuid>", "status": "...", "deduplicated": true }   // если ключ уже был

Идемпотентность: повтор с тем же `idempotency_key` НЕ создаёт дубль.

Схемы payload:
  email:        { from, to[], cc[], bcc[], subject, body, html, attachments[{filename,url,size}] }
  article:      { title, slug, excerpt, cover, markdown }
  site_change:  { branch, commit, files[], diff, preview_url, pr_url }
  ad:           { budget, period, channel, targeting[], headline, description, cta, landing_url, creatives[], text }
  other:        { любые поля }

## 2. Список (модер/девопс/агент)

    GET /api/queue?status=pending&project=myproject&type=email&agent_name=bot&q=поиск&limit=50&offset=0
    status: pending|approved|rejected|applied|failed|expired|active|archive
    -> { total, limit, offset, items: [...] }  (items без payload)

## 3. Одна запись

    GET /api/queue/{id}    -> вся запись + payload + events[]

## 4. Решение (модер)

    POST /api/queue/{id}/approve    { "comment": "ok" }            -> approved
    POST /api/queue/{id}/reject     { "rejection_reason": "..." }  -> rejected (reason уходит агенту)

Только из статуса `pending`, иначе 409. Гонки защищены: `UPDATE ... WHERE status='pending'`.

## 5. Воркер (devops)

    POST /api/devops/claim            { "limit": 10 }   -> { items:[...], count }  // approved, атомарный захват
    POST /api/devops/{id}/applied     { "comment": "..." }   -> applied
    POST /api/devops/{id}/failed      { "error": "..." }     -> approved(остаётся) или failed (после N попыток)

## Статусы
    pending → approved → applied
       ↓          ↓
    rejected   failed
`approved` = «модер одобрил, ждёт воркер». `applied` = «реально применено».

## Healthcheck
    GET /health  -> { status, php, mysql, time }  (без авторизации, удобно для uptime-мониторинга)

---

## Пример: агент отправляет письмо на премодерацию

Обязательные поля записи (без них 422):
`idempotency_key`, `type`, `project`, `agent_name`, `title`, `reason`, `payload`.

```json
{
  "idempotency_key": "email-2026-11-18-000123",
  "type": "email",
  "project": "apitly",
  "agent_name": "sales-bot",
  "title": "Письмо клиенту: условия Enterprise",
  "reason": "Клиент запросил КП, сгенерирован ответ. Проверить тон и цифры.",
  "scheduled_at": "2026-11-18T10:00:00+03:00",
  "payload": {
    "from": "sales@apitly.ru",
    "to": ["ceo@bigcorp.ru"],
    "cc": ["legal@apitly.ru"],
    "subject": "Коммерческое предложение — Apitly Enterprise",
    "body": "Здравствуйте! Направляю условия Enterprise: 250 000 руб/мес, SLA 99.95%.",
    "html": "<h2>Коммерческое предложение</h2><p>Условия <b>Enterprise</b>: 250 000 руб/мес.</p>",
    "attachments": [
      { "filename": "offer.pdf", "url": "https://example.com/offer.pdf", "size": 482133 }
    ],
    "audience": { "id": "seg-ent-ru", "name": "Enterprise · РФ" }
  }
}
```

```bash
curl -X POST https://moder.example.com/api/queue \
  -H "Authorization: Bearer $MODER_TOKEN_AGENT" \
  -H "Content-Type: application/json" \
  -d @email.json
# -> 201 {"id":"<uuid>","status":"pending","scheduled_at":"2026-11-18 07:00:00","deduplicated":false}
```

Поля payload для email: обязателен только `to` (мин. один адрес).
Опциональны: `from`, `cc`, `bcc`, `subject`, `body`, `html`, `attachments`.
Для модератора полезны `subject` и `body`/`html` — они видны в превью.

Что важно знать агенту:

- **idempotency_key** — уникальный на задачу. Повтор с тем же ключом не создаёт дубль,
  возвращает существующий `id` с `deduplicated: true` (защита от ретраев).
- **scheduled_at** — необязательно, ISO-8601 (лучше с зоной). Не указано = asap.
  Хранится в UTC; воркер не возьмёт раньше времени (модер может одобрить заранее).
- **audience** — необязательно, `{id, name}`; `id` — любая строка. Фильтруется в UI.

## Как агенту мониторить отклонения

```bash
# 1) По id: статус + причина отказа
curl -H "Authorization: Bearer $MODER_TOKEN_AGENT" \
  https://moder.example.com/api/queue/<id>
# status="rejected" -> поле rejection_reason содержит причину

# 2) Все свои отклонённые
curl -H "Authorization: Bearer $MODER_TOKEN_AGENT" \
  "https://moder.example.com/api/queue?agent_name=sales-bot&status=rejected&limit=50"

# 3) Всё, что требует внимания (отклонено + ошибки применения)
curl -H "Authorization: Bearer $MODER_TOKEN_AGENT" \
  "https://moder.example.com/api/queue?agent_name=sales-bot&status=archive"
```

Цикл агента: периодически запрашивать `status=rejected` (и `failed`) по своему
`agent_name`, читать `rejection_reason`, исправлять и отправлять заново с **новым**
`idempotency_key` (старый уже занят).

## Плановое время и сегмент аудитории (iter 003)

### scheduled_at — когда выполнять

Необязательное поле верхнего уровня (не в payload). NULL/отсутствует = «как можно скорее».
Принимается ISO-8601; в БД хранится в UTC, отдаётся и в UTC, и в поясе модератора.

```json
{"scheduled_at": "2026-11-18T10:00:00+03:00", ...}
```

Воркер берёт `approved` только там, где `scheduled_at IS NULL OR scheduled_at <= NOW()`.
Модер может одобрить заранее — уйдёт по расписанию.

Фильтры списка: `schedule=today|overdue|future|asap|all` (по умолчанию в UI — today).
«Просрочено» = плановое в прошлом, а действие ещё живо (pending/approved/failed).

### audience — сегмент целевой аудитории

В payload, объект `{id, name}`. `id` — любая строка (не требует GUID).
`name` — человекочитаемое. Из JSON выводятся колонки `audience_id`/`audience_name`
(generated STORED) для фильтрации и индекса.

```json
{"payload": {..., "audience": {"id": "seg-ent-ru", "name": "Enterprise · РФ"}}}
```

Фильтр списка: `?audience_id=seg-ent-ru`.
