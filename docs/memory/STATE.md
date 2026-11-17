# Current state

Last updated: 2026-11-17

> Единственное место, где написано «где мы сейчас». Не дублируй в PROJECT.md.

## Done

- Docker-стек: PHP 8.5.12 FPM + MySQL 9.4 + nginx 1.29 + phpMyAdmin 5.2, Caddy снаружи (TLS).
- Caddy-конфиг `/etc/caddy/sites/moder.example.com.caddy`: `/` -> nginx:39010, `/phpmyadmin/` -> 39011.
- Схема БД `moderation_queue` + `moderation_events` (`www/app/schema.sql`, авто-миграция при старте).
- REST API: create/list/get/approve/reject + devops claim/applied/failed.
- Идемпотентность по `idempotency_key`, защита от гонок, атомарный `claim` (SKIP LOCKED).
- UI модера: логин по токену (POST), список с фильтрами и счётчиками, карточка с полиморфным превью.
- Полиморфный рендер payload: email (sandboxed iframe), article (markdown), site_change (diff), ad, other.
- XSS-закрытие: заголовок в `<title>` экранируется, всё остальное уже эскейпилось.
- Fail-closed авторизация: пустая env = роль отключена; нет MODER_TOKEN_MODER -> UI 503.
- Подготовка к публикации: `.env` в `.gitignore`, `.env.example`, dev-дефолты токенов убраны.
- `/health` без авторизации.
- `www/bin/devops-worker.sh` (скелет), `www/bin/demo-agents.sh`.
- Документация: `README.md`, `www/app/API.md`.
- UI iter 002: чекбоксы + select-all, sticky bulk-панель (массовое одобрение/отклонение),
  быстрые ✓/✗ в строке, отзыв одобрения (row + карточка, approved->pending), hover-превью письма.
- `MODER_UI_EMAIL_HOVER` (env) — переключатель hover-превью (дефолт вкл).
- API.md: пример POST письма + минимально обязательные поля + мониторинг отказов агентом.
- iter 003: `scheduled_at` (плановое время, UTC в БД, локально в UI); generated-колонки
  `audience_id`/`audience_name` из payload.audience; фильтры schedule (today/overdue/future/asap/all)
  и audience; воркер уважает расписание; версионные миграции (`schema_meta`).
- **Memory layer полностью готов**: `AGENTS.md` + `docs/memory/*` (PROJECT/STATE/DECISIONS/CONVENTIONS/GLOSSARY/BACKLOG) + `iterations/` с шаблоном.
- Подготовка к публичному репозиторию: `.gitattributes`, домен обезличен на `moder.example.com`, реальный домен только в серверном Caddy-конфиге.

## In progress

- (пусто)

## Next

- Реальная логика apply в воркере (smtp / git merge+deploy / cms / ads api) по типу записи.
- Крон для воркера (раз в минуту).
- Короткие ID для человека либо поиск по `idempotency_key` (`/api/queue/by-key/<key>`).
- Аутентификация UI через Caddy basicauth / OAuth вместо токена в форме.
- Тесты (сейчас проверялось вручную curl-ом).

## Blockers

- A-запись `moder.example.com` ещё не добавлена — Let's Encrypt сертификат пока не выпущен (Caddy ретраит, конфиг валиден).
