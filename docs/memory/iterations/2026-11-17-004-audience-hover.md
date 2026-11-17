# Iteration 004 — сегмент в hover + актуальный пример в API.md

Date: 2026-11-17
Commit: 48197c2

## Goal
Показать сегмент аудитории в hover-превью и обновить пример запроса в API.md
под актуальную схему (scheduled_at + audience), чтобы отдать команде как образец.

## Changes
- `www/app/ui.php`: hover-превью письма показывает «Сегмент» (из generated-колонок,
  с фолбэком на payload.audience). Карточка задания уже показывала сегмент.
- `www/app/API.md`: раздел «Пример: агент отправляет письмо» переписан —
  полный JSON с scheduled_at и audience, curl через `-d @email.json`, пояснения
  по idempotency_key / scheduled_at / audience.

## Tests
- hover-превью: сегмент рендерится ('SMB (seg-smb)', 'Enterprise · РФ (seg-ent-ru)'),
  у писем без сегмента строка не выводится.
- Пример из API.md прогнан вживую: 201, scheduled_at в UTC, generated-колонки заполнены.

## Notes / next
- Тестовые записи после проверок удалены; в очереди остались демо-данные для UI.
