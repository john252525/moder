# PROJECT

> Медленная память. Меняется редко, читается всегда. «Где мы сейчас» — не здесь, а в `STATE.md`.

## Что это

**moder** — сервис премодерации контента для автономных (stateless) агентов. Агенты генерируют исходящие действия (письма, статьи, правки сайта, рекламу), человек-модератор просматривает их в единой очереди и одобряет либо отклоняет с причиной. Одобренное забирает devops-воркер и применяет (отправка/публикация/деплой).

Идея: **outbox/inbox паттерн с human-in-the-loop** — один источник правды, один UI, один процесс ревью, обратная связь агенту через причину отказа.

## Для кого

- **Агенты** (машинные клиенты) — кладут записи в очередь через REST API.
- **Модератор** (человек) — смотрит очередь в веб-UI, одобряет/отклоняет.
- **Devops-воркер** — поллит одобренное и применяет.

## Стек

| Слой | Технология |
|---|---|
| PHP | 8.5.12 FPM (Debian), ext: pdo_mysql, mysqli, gd, zip, intl, bcmath, exif, opcache |
| БД | MySQL 9.4 |
| Веб-сервер | nginx 1.29 (внутри), Caddy 2 (снаружи, TLS) |
| Админка БД | phpMyAdmin 5.2 |
| Оркестрация | Docker Compose |

Бэкенд — **vanilla PHP без фреймворка** (роутинг в `www/public/index.php`, слои в `www/app/`).

## Архитектура

    Агент --POST /api/queue--> [moderation_queue] <--UI-- Модератор
                                      ^                      |
                                      |<---- approve/reject -+
                                      v
                              [status=approved]
                                      |
                        devops-worker (claim) --> applied / failed

- **Одна таблица** `moderation_queue` с полиморфным `payload JSON`.
- **Полиморфный рендер** по `type` (`www/app/render.php`): email / article / site_change / ad / other.
- **Идемпотентность** — уникальный `idempotency_key` от агента.
- **Гонки** — оптимистичная блокировка `UPDATE ... WHERE status='pending'`, атомарный `claim` через `FOR UPDATE SKIP LOCKED`.

## Схема БД

См. `www/app/schema.sql`. Две таблицы:

- `moderation_queue` — очередь (id, idempotency_key, type, project, agent_name, title, reason, payload JSON, status, reviewed_*, rejection_reason, applied_at, apply_error, apply_attempts, claimed_at, timestamps).
- `moderation_events` — append-only аудит (кто/что/когда/комментарий).

Статусы: `pending -> approved -> applied`, ветки `rejected` и `failed`.

## Внешние интеграции

- **Caddy** (системный, на хосте) — TLS + reverse proxy. Конфиг: `/etc/caddy/sites/moder.<domain>.caddy` -> nginx:39010, `/phpmyadmin/` -> phpMyAdmin:39011.
- **SMTP / Git / CMS / Ads API** — точки применения в `www/bin/devops-worker.sh` (сейчас заглушка).

## Структура репозитория

    /opt/moder
      AGENTS.md              точка входа для агента
      README.md              для человека
      docs/memory/           memory layer (этот каталог)
      docs/spec/             спеки «как должно быть» (отдельно от STATE)
      docker-compose.yml
      .env.example           шаблон секретов (реальный .env в .gitignore)
      moder                  CLI управления стеком
      nginx/default.conf
      php/Dockerfile, php/php.ini
      www/app/               backend (api, ui, render, core, db, config, schema)
      www/bin/               devops-worker.sh, demo-agents.sh
      www/public/index.php   front controller
