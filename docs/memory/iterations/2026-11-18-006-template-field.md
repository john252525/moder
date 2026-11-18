# Iteration 006 — поле template (паритет с audience)

Date: 2026-11-18
Commit: dd9f7f2

## Goal
Агенты присылают персонализированные письма по шаблонам. Без id/name шаблона
нельзя анализировать, какой шаблон даёт какой результат. Добавить по аналогии с audience.

## Changes
- `www/app/schema.sql`: generated STORED `template_id`, `template_name` из
  `payload.template` (id/name); индекс `idx_template(template_id)`.
- `www/app/db.php`: миграция v2 (ADD COLUMN/KEY по факту, schema_version=2).
- `www/app/core.php`: фильтр `?template_id=`; поля template_id/template_name в API.
- `www/app/ui.php`: колонка «Шаблон», селект фильтра, строка в карточке, строка в hover.
- `www/app/render.php`: строка «Шаблон» в превью payload.
- `examples/email.json`: добавлен template; `API.md` и `examples/README.md` обновлены.

## Tests
- Миграция применилась: schema_version=2, 4 generated-колонки, idx_template.
- Тестовая запись с template: generated-колонки заполнились, фильтр API вернул 1.
- UI: ячейка шаблона, селект, карточка (2 места), hover (строка «Шаблон»).
- Реальные данные агентов (41 запись) не тронуты; фильтр по audience работает
  (agencies-branding -> 9).

## Notes / next
- Реальные агенты пока не шлют template (template_id IS NULL). Нужно попросить команду
  добавить `payload.template = {id, name}` в генерацию писем.
- id шаблона — любая строка (как и audience.id).
