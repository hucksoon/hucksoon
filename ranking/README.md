# 랭킹 서버 (Cloudflare Workers + D1)

게임(`index.html`)이 기록을 올리고, 대시보드(`ranking.html`)가 10초마다 랭킹을 읽어 옵니다.

> Cloudflare를 쓸 수 없으면 같은 기능의 PHP 버전(`../ranking-php/`)을 기존 PHP 서버에 올려서 쓰면 돼요.

| 랭킹 | 기준 |
| --- | --- |
| 최고 층 | 가장 깊이 내려간 층. 같으면 먼저 도달한 사람이 위 |
| 최강 아이템 | 가진 적 있는 최고 아이템. 등급(레전더리 > 세트 > 에픽 > 레어 > 언커먼 > 커먼) → 강화 → 아이템 레벨 순. 같으면 먼저 얻은 사람이 위 |

## 개인정보

- **원본 이메일·휴대폰 번호는 저장하지 않습니다.** 서버는 Xsolla 토큰에서 이메일을 읽어 아래 두 가지만 저장합니다.
  - 화면용으로 가린 값: `abc***@gmail.com`, `***-5678`
  - `HMAC-SHA256(HASH_KEY, 이메일)`: 되돌릴 수 없는 값. 긴급할 때 `HASH_KEY`를 가진 사람만 특정 이메일의 행을 찾을 수 있습니다.
- 게스트는 `게스트 #앞6자리`로 표시하고, 기기 ID 원본도 저장하지 않습니다 (HMAC만 저장).
- 사용자 구분 키도 `HMAC("u:" + Xsolla 사용자 ID)`라서 DB만으로는 누구인지 알 수 없습니다.
- 개발자 계정은 `DEV_EMAILS` 비밀값으로 지정합니다. 이 계정들의 기록은 저장하지 않고, 예전 기록이 있으면 지웁니다. 공개 저장소에 이메일을 남기지 않으려고 코드에 적지 않았습니다.
- 랭킹 API 응답에는 가린 이름, 층, 아이템만 들어갑니다.

## 신원 확인

- 로그인 사용자: 게임이 Xsolla 토큰을 `Authorization: Bearer`로 보냅니다. 서버는 서명, 만료 시각, Login 프로젝트 ID를 확인합니다.
  - RS256 토큰은 Xsolla JWKS로 검증합니다.
  - HS256 토큰은 `JWT_SECRET`이 있으면 그 키로 검증하고, 없으면 Xsolla `/api/token/validate`에 확인을 요청합니다.
- 게스트: `X-Guest-Id` 헤더(기기 ID)로 구분합니다. 서버에서 검증할 방법이 없어 조작될 수 있습니다.
- 점수는 게임이 보내는 값입니다. 범위 검사를 합니다 (층 1–10000, 강화 0–10, 아이템 레벨 ≤ 층). 시드 기반 서버 검증은 나중 과제입니다.

## 배포 (처음 한 번)

Cloudflare 계정이 필요합니다 (무료 플랜으로 충분). 아래 명령은 이 `ranking/` 폴더에서 실행합니다.

```bash
cd ranking
npx wrangler login                                  # 브라우저에서 Cloudflare 로그인
npx wrangler d1 create dungeon-ranking              # 출력된 database_id 를 wrangler.toml 에 넣기
npx wrangler d1 execute dungeon-ranking --remote --file schema.sql

# 비밀값 (입력 창에 붙여 넣기. 저장소에는 절대 커밋하지 않기)
openssl rand -hex 32                                # HASH_KEY 로 쓸 무작위 값. 안전한 곳에 따로 보관
npx wrangler secret put HASH_KEY
npx wrangler secret put DEV_EMAILS                  # 예: a@example.com,b@example.com

npx wrangler deploy                                 # https://dungeon-ranking.<계정>.workers.dev 주소가 나옴
```

마지막으로 저장소 루트의 `ranking-config.js`에 Worker 주소를 넣고 커밋하면 게임과 대시보드가 랭킹을 사용합니다.

```js
window.RANKING_API = "https://dungeon-ranking.<계정>.workers.dev";
```

`HASH_KEY`를 잃어버리면 같은 사람을 다시 찾을 수 없고, 바꾸면 기존 기록이 다른 사람처럼 보입니다. 처음 정한 값을 계속 쓰세요.

## 긴급 확인

`HASH_KEY`를 가진 사람만 할 수 있습니다.

```bash
RANKING_HASH_KEY=<HASH_KEY> node tools/ranking-lookup.mjs someone@example.com
# 출력된 wrangler 명령을 ranking/ 폴더에서 실행
```

## API

| 요청 | 설명 |
| --- | --- |
| `POST /api/score` `{ stage, item: { rarity, enh, lvl, name } \| null }` | 내 기록 올리기. 더 좋은 기록만 반영 |
| `GET /api/ranking?type=stage\|item&limit=50` | 랭킹 (최대 100명) |
| `GET /api/me` | 내 순위 |

CORS는 `ALLOWED_ORIGINS`(기본 `https://hucksoon.github.io`)에서만 허용합니다.
