# Iteration 003 — плановое время (scheduled_at) + сегмент аудитории

Date: 2026-11-17
Commit: a57f4ff

## Goal
Добавить плановое время выполнения (рассредоточить отправки по дням/часам) и сегмент
целевой аудитории. Воркер должен уважать расписание.

## Changes
- `www/app/schema.sql`: `scheduled_at DATETIME NULL`; generated STORED колонки
  `audience_id`, `audience_name` из `payload.audience` (id/name); индексы
  `idx_sched(status, scheduled_at)`, `idx_audience(audience_id)`; таблица `schema_meta`.
- `www/app/db.php`: версионные миграции (`schema_version`) — догоняем существующую БД
  без пересоздания (ADD COLUMN/ADD KEY по факту наличия).
- `www/app/api.php`: приём `scheduled_at` (ISO-8601, хранение в UTC); воркер берёт только
  `scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP()`, сортировка по времени.
- `www/app/core.php`: фильтры `schedule=today|overdue|future|asap|all` и `audience_id`;
  хелперы `moder_tz()`, `day_bounds_utc()`, `dt_local_iso()`; поля в `item_to_array`.
- `www/app/config.php`: `ui.timezone` из `MODER_TZ` (дефолт Europe/Moscow).
- `www/app/ui.php`: фильтры «Сегодня (дефолт)/Все/Просроченные/Будущие/asap» и сегмент;
  колонки «Выполнить» и «Сегмент»; бейджи asap/today/future/overdue; чипсы
  «Просрочено N»/«Будущие N»; расписание и сегмент в карточке.
- `www/app/render.php`: строка «Сегмент» в превью payload.
- `docker-compose.yml`, `.env.example`: `MODER_TZ`.
- `www/app/API.md`: разделы про `scheduled_at` и `audience`.

## Tests
- Создание с `scheduled_at`: хранится UTC, отдаётся `scheduled_at` + `scheduled_at_local` (+03).
- Generated колонки заполняются из payload.audience.
- Фильтры: all=8 today=6 overdue=1 future=1 asap=6 — согласованы.
- Воркер: future-запись осталась approved (attempts=0), «сейчас» забрана (attempts=1).
- UI: колонки/фильтры/бейджи рендерятся; hover и bulk не сломаны.
- Снаружи через Caddy: /, /health, /login, /phpmyadmin/ — ок.

## Notes / next
- «Просрочено» выводится, второго поля (дедлайн) не заводили — сознательно.
- Индекс по audience_id уже есть на случай тяжёлых фильтров по сегменту.
