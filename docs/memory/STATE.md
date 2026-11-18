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
- iter 004: сегмент аудитории в hover-превью; актуальный пример письма (scheduled_at+audience)
  в API.md — готовый образец для агентов.
- iter 005: каталог `examples/` с рабочими JSON (email/article/ad/site_change) + README;
  копипаст из API.md теперь запускается из коробки.
- iter 006: поле `template` (payload.template = {id,name}) — generated-колонки, фильтр,
  отображение в списке/карточке/hover; миграция v2. Паритет с audience.
- iter 007: `DELETE /api/queue/{id}` (только pending, атомарно, без гонки с воркером);
  внятные ошибки: 400 invalid_id, 422 missing_param (agent_name для роли agent), 404 с id.
- iter 008: DELETE — hard delete (вместе с историей moderation_events); 401 с подсказкой
  про заголовок Authorization и роли.
- iter 009: HTTP (порт 80) для /api/* отдаёт JSON-тело с подсказкой (не пустой 308);
  OPTIONS, терпимость к слэшу, 404 со списком эндпоинтов. Сертификат выпущен.
- iter 010: отдельный контейнер `mailer` (SMTP): claim approved emails -> SMTP ->
  applied/failed. Отдельные креды на каждый from, гибкий темп (daily limit, batch/spread,
  окно), безопасный dry-run по умолчанию. Claim фильтрует по types/project.
- iter 011: изолированный мобильный SPA `/m/` (сайдбар задач + панель деталей,
  независимый скролл, History API, фильтры в bottom-sheet, рендер всех типов payload).
  Старый UI и API не тронуты.
- iter 012: фикс OOM MySQL (экономные настройки + mem_limit 384m, убит осиротевший
  mysqld, державший ibdata1); восстановлены случайно удалённые файлы SPA; UI-фиксы (404/400).
- **Memory layer полностью готов**: `AGENTS.md` + `docs/memory/*` (PROJECT/STATE/DECISIONS/CONVENTIONS/GLOSSARY/BACKLOG) + `iterations/` с шаблоном.
- Подготовка к публичному репозиторию: `.gitattributes`, домен обезличен на `moder.example.com`, реальный домен только в серверном Caddy-конфиге.

## In progress

- (пусто)

## Next

- Реальная логика apply для НЕ-email типов (git merge+deploy / cms / ads api). Email — уже есть (mailer, iter 010).
- Крон для не-email воркера (mailer сам поллит по MAILER_POLL_INTERVAL).
- Короткие ID для человека либо поиск по `idempotency_key` (`/api/queue/by-key/<key>`).
- Аутентификация UI через Caddy basicauth / OAuth вместо токена в форме.
- Тесты (сейчас проверялось вручную curl-ом).

## Blockers

- (нет — сертификат Let's Encrypt выпущен 2026-11-16, действует до 2027-02-14)
