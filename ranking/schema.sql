-- 던전 크롤러 랭킹 (Cloudflare D1)
-- 원본 이메일은 저장하지 않는다. 화면용으로 가린 이메일과, 확인용으로 되돌릴 수 없는 HMAC만 저장한다.
CREATE TABLE IF NOT EXISTS players (
  key          TEXT PRIMARY KEY,           -- HMAC("u:" + Xsolla 사용자 ID | "g:" + 게스트 기기 ID)
  kind         TEXT NOT NULL,              -- 'user' | 'guest'
  display      TEXT NOT NULL,              -- 가린 이메일 또는 "게스트 #abc123"
  email_hmac   TEXT,                       -- HMAC(소문자 이메일). 원본은 저장하지 않음
  best_stage   INTEGER NOT NULL DEFAULT 0, -- 최고 도달 층
  stage_at     INTEGER,                    -- 최고 층을 처음 달성한 시각 (동점일 때 먼저 달성한 사람이 위)
  item_score   INTEGER NOT NULL DEFAULT -1,-- 등급 × 100000 + 강화 × 1000 + 아이템 레벨
  item_rarity  TEXT,
  item_enh     INTEGER,
  item_lvl     INTEGER,
  item_name    TEXT,
  item_at      INTEGER,
  updated_at   INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_players_stage ON players (best_stage DESC, stage_at ASC);
CREATE INDEX IF NOT EXISTS idx_players_item ON players (item_score DESC, item_at ASC);
CREATE INDEX IF NOT EXISTS idx_players_email ON players (email_hmac);
