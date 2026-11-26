-- Единая очередь премодерации для агентов
CREATE TABLE IF NOT EXISTS moderation_queue (
  id              CHAR(36)      NOT NULL,
  idempotency_key VARCHAR(190)  NOT NULL,
  type            VARCHAR(32)   NOT NULL,
  project         VARCHAR(120)  NOT NULL,
  agent_name      VARCHAR(120)  NOT NULL,
  title           VARCHAR(500)  NOT NULL,
  reason          TEXT          NOT NULL,
  payload         JSON          NOT NULL,
  scheduled_at    DATETIME      NULL,
  status          VARCHAR(16)   NOT NULL DEFAULT 'pending',
  reviewed_by     VARCHAR(120)  NULL,
  reviewed_at     DATETIME      NULL,
  rejection_reason TEXT         NULL,
  applied_at      DATETIME      NULL,
  apply_error     TEXT          NULL,
  apply_attempts  INT           NOT NULL DEFAULT 0,
  claimed_at      DATETIME      NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_idempotency (idempotency_key),
  KEY idx_status_project (status, project),
  KEY idx_agent (agent_name),
  KEY idx_created (created_at),
  KEY idx_type (type),
  KEY idx_sched (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moderation_events (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id    CHAR(36)        NOT NULL,
  actor      VARCHAR(120)    NOT NULL,
  action     VARCHAR(40)     NOT NULL,
  comment    TEXT            NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_item (item_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Метаданные схемы: чтобы не гонять тяжёлые ALTER-проверки на каждом запросе
CREATE TABLE IF NOT EXISTS schema_meta (
  k VARCHAR(64)  NOT NULL,
  v VARCHAR(255) NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
