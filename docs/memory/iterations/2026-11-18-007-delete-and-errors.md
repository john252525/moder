# Iteration 007 — DELETE записи + внятные ошибки API

Date: 2026-11-18
Commit: 18c64ac

## Goal
Агентам нужна возможность отменить своё задание до модерации. Плюс убрать
неинформативные ответы API (total=0 вместо ошибки, общий 404 на кривой id).

## Changes
- `www/app/api.php`: роут `DELETE /api/queue/{id}` (agent/moder). Только
  `pending`, атомарно (`DELETE ... WHERE id=? AND status='pending'`) — защита от гонки
  с воркером; при конкурентном одобрении -> 409.
- `www/app/api.php`: для роли agent в `GET /api/queue` обязателен `agent_name`
  (иначе 422 missing_param, было молчаливое total=0).
- `www/app/api.php`: `GET /api/queue/{id}` ловит любой id -> битый даёт 400 invalid_id
  (было 404 'no route').
- `www/app/core.php`: `require_uuid()`, `fetch_item()` отдаёт id в тексте ошибки.
- Аудит: событие `deleted` пишется внутри транзакции до коммита (без orphan-строк).
- `www/app/API.md`: раздел DELETE + требование agent_name.

## Tests
- DELETE pending -> 200 {deleted:true}; повтор -> 404; approved -> 409.
- DELETE: битый id -> 400, без токена -> 401, devops-токен -> 403.
- GET кривой id -> 400 invalid_id; несуществующий uuid -> 404 с id в тексте.
- GET списка агентом без agent_name -> 422 с примером правильного запроса.
- Аудит после DELETE сохраняется (created + deleted), orphan-строк нет.
- Реальные записи (42: za2dnya/autrich/apitly) не затронуты.

## Notes / next
- Удаление — «до модерации». После approved — через reject/revoke, не DELETE.
- Tombstone-события остаются в moderation_events (append-only аудит).
