# Примеры запросов для агентов

Готовые JSON-тела для `POST /api/queue`. Отправка:

```bash
curl -X POST https://moder.example.com/api/queue \
  -H "Authorization: Bearer $MODER_TOKEN_AGENT" \
  -H "Content-Type: application/json" \
  -d @examples/email.json
```

| Файл | Тип | Что демонстрирует |
|---|---|---|
| `email.json` | email | html+body, вложения, сегмент, `scheduled_at` |
| `article.json` | article | markdown, slug, cover, сегмент |
| `ad.json` | ad | бюджет, таргет, креативы, сегмент |
| `site_change.json` | site_change | diff, ветка, PR, preview URL, `scheduled_at: null` (asap) |

Замените `idempotency_key` на свой уникальный (иначе вернётся `deduplicated: true`).
