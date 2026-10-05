-- Run on MySQL 8 after backing up the database. Existing rating and link tables are untouched.
CREATE TABLE IF NOT EXISTS vogoo_models (
  id BINARY(16) NOT NULL,
  objective VARCHAR(16) NOT NULL,
  category INT UNSIGNED NOT NULL,
  placement VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_mask INT UNSIGNED NOT NULL,
  context_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  feature_schema_version INT UNSIGNED NOT NULL,
  artifact JSON NOT NULL,
  trained_at DATETIME(6) NOT NULL,
  activated_at DATETIME(6) NULL,
  status VARCHAR(16) NOT NULL,
  active_marker TINYINT GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN 1 ELSE NULL END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vogoo_active_model (objective, category, placement, source_mask, context_key, active_marker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vogoo_impressions (
  id BINARY(16) NOT NULL,
  category INT UNSIGNED NOT NULL,
  placement VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_mask INT UNSIGNED NOT NULL,
  context_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  score_kind VARCHAR(24) NOT NULL,
  member_id INT UNSIGNED NULL,
  shown_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  KEY ix_vogoo_impression_key (category, placement, source_mask, context_key, shown_at),
  KEY ix_vogoo_impression_member (member_id, shown_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vogoo_impression_items (
  impression_id BINARY(16) NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  position INT UNSIGNED NOT NULL,
  ranking_score DOUBLE NULL,
  display_click_probability DOUBLE NULL,
  model_id BINARY(16) NULL,
  feature_schema_version INT UNSIGNED NOT NULL,
  feature_snapshot JSON NOT NULL,
  PRIMARY KEY (impression_id, item_id),
  UNIQUE KEY uq_vogoo_impression_position (impression_id, position),
  KEY ix_vogoo_item_model (model_id),
  CONSTRAINT fk_vogoo_item_impression FOREIGN KEY (impression_id)
    REFERENCES vogoo_impressions(id) ON DELETE CASCADE,
  CONSTRAINT fk_vogoo_item_model FOREIGN KEY (model_id)
    REFERENCES vogoo_models(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vogoo_impression_evidence (
  impression_id BINARY(16) NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  source VARCHAR(32) NOT NULL,
  raw_score DOUBLE NULL,
  source_rank INT UNSIGNED NULL,
  support_count BIGINT UNSIGNED NULL,
  log_odds_contribution DOUBLE NULL,
  contributing_item_ids JSON NULL,
  PRIMARY KEY (impression_id, item_id, source),
  CONSTRAINT fk_vogoo_evidence_item FOREIGN KEY (impression_id, item_id)
    REFERENCES vogoo_impression_items(impression_id, item_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vogoo_outcomes (
  event_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  impression_id BINARY(16) NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(16) NOT NULL,
  occurred_at DATETIME(6) NOT NULL,
  PRIMARY KEY (event_id),
  KEY ix_vogoo_outcome_item (impression_id, item_id, event_type, occurred_at),
  CONSTRAINT fk_vogoo_outcome_item FOREIGN KEY (impression_id, item_id)
    REFERENCES vogoo_impression_items(impression_id, item_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
