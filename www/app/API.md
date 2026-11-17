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
