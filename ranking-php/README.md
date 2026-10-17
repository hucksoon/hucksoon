# 랭킹 서버 (PHP 버전)

`ranking/worker.js`(Cloudflare Workers)와 **같은 API와 규칙**을 PHP로 만든 버전이에요. 이미 운영 중인 PHP 서버에 폴더째 올려서 써요.
랭킹 기준, 개인정보 원칙(원본 이메일 저장 안 함, HMAC, 개발자 계정 제외)은 [Cloudflare 버전 문서](../ranking/README.md)와 같아요.

## 필요한 것

- PHP 7.4 이상, 확장: `pdo_sqlite`(또는 `pdo_mysql`), `openssl`, `curl`, `mbstring`
- HTTPS로 공개된 주소 (게임이 https라서 http 주소는 브라우저가 막아요)

## 올리기

1. 이 폴더(`api.php`, `.htaccess`, `data/`)를 서버에 올려요. 예: `https://내서버/ranking/`
2. `config.sample.php`를 `config.php`로 복사하고 값을 채워요
   - `hash_key`: `php -r "echo bin2hex(random_bytes(32));"` 로 만든 값. **따로 안전하게 보관**하세요 (잃어버리면 같은 사람을 다시 찾을 수 없어요)
   - `dev_emails`: 랭킹에서 뺄 개발자 이메일, 쉼표로 구분
   - `jwt_secret`: 비워 두면 돼요 (Xsolla 공개 키 또는 Xsolla 확인 API로 검증)
3. `data/` 폴더를 웹 서버가 쓸 수 있게 해요 (예: `chmod 770 data`, 소유자를 웹 서버 계정으로). SQLite DB와 키 캐시가 여기에 생겨요
4. 브라우저로 `https://내서버/ranking/api.php?r=ranking` 을 열어 `{"type":"stage","total":0,...}` 이 나오면 성공
5. 게임 저장소의 `ranking-config.js`에 주소를 넣어요: `window.RANKING_API = "https://내서버/ranking/api.php";`

### 보안

- `config.php`와 `data/`는 비밀값과 DB예요. Apache면 함께 들어 있는 `.htaccess`가 막아 줘요
- **nginx**면 `.htaccess`가 동작하지 않아요. 아래처럼 막거나, `config.php`와 DB를 웹 루트 밖으로 옮기고 웹 서버 환경변수 `RANKING_CONFIG`에 config 경로를 지정하세요

```nginx
location ~ ^/ranking/(config\.php|config\.sample\.php|README\.md|data/) { deny all; }
```

- 가장 안전한 배치: `config.php`와 `data/`를 웹 루트 밖(예: `/var/app/ranking/`)에 두고 `api.php`만 공개

## API

| 요청 | 설명 |
| --- | --- |
| `POST api.php?r=score` `{ stage, item }` | 내 기록 올리기. 더 좋은 기록만 반영 |
| `GET api.php?r=ranking&type=stage\|item&limit=50` | 랭킹 (최대 100명) |
| `GET api.php?r=me` | 내 순위 |
| `GET api.php?r=player&id=<공개 ID>` | 그 사람이 최고 층을 세운 순간의 장비·능력치, 최강 아이템 |
| `POST api.php?r=lobby` `{ scene: "lobby", x, y, portal }` 또는 `{ scene: "dungeon", depth }` | 로비 위치(또는 탐험 중인 층)를 알리고, 지금 접속 중인 사람들을 받음 |
| `GET api.php?r=market&slot=&rarity=&sort=new\|cash\|gold` | 거래소에 올라온 아이템 (누구나) |
| `POST api.php?r=market_list` `{ item, gold, cash, stage }` | 아이템 올리기 (로그인) |
| `POST api.php?r=market_cancel` `{ id }` | 내 아이템 내리기 → 우편함 |
| `POST api.php?r=market_buy` `{ id }` | 사기: 캐시(가격 + 수수료 1)를 Xsolla에서 차감 |
| `GET api.php?r=market_mine` | 내가 올린 아이템, 지난 거래, 우편함 |
| `POST api.php?r=mail_claim` `{ id }` | 우편 받기 (판매 캐시는 이때 Xsolla로 지급) |
| `GET api.php?r=entry_status` | 오늘 무료 입장·광고 보상이 몇 번 남았는지 |
| `POST api.php?r=entry` `{ adTicket? }` | 1층 문으로 새로 시작 (하루 무료 5회, 그 뒤엔 광고 티켓) |
| `POST api.php?r=ad_start` `{ kind }` | 광고 보기 전에 티켓 받기 (entry / revive / fee) |
| `POST api.php?r=revive_ad` `{ adTicket }` | 광고 보고 부활 |
| `GET api.php?r=profile` | 내 계정 저장 (가방·진행 상황) (로그인) |
| `POST api.php?r=profile` `{ rev, data }` | 계정 저장 올리기. 저장 번호가 다르면 서버 내용을 돌려줌 |

## 로비 (지금 접속 중인 사람)

- 게임은 로비에서 1초마다, 던전에서는 10초마다 `lobby`를 불러요. 소식이 끊기면 로비는 8초, 던전은 25초 뒤 목록에서 빠져요
- `presence` 표에는 내부 키(HMAC), 공개 ID, 가린 이름, 위치만 있어요. 1분 넘게 소식이 없는 줄은 지워요
- 개발자 계정도 로비에는 보여요 (가린 이름). 랭킹에서만 빠져요
- 접속자가 많아지면(수십 명 이상) SQLite 대신 MySQL을 쓰거나, 웹소켓 서버로 바꾸는 걸 생각해 보세요

## 장비 보기와 위조 검증 (PHP 버전에만 있음)

- 게임은 장착 장비와 최강 아이템의 **(시드, 출처, 아이템 레벨, 규칙 버전, 강화 단계)** 만 보내요
- 서버는 `rules.php`(items.js와 같은 규칙)로 아이템을 **다시 만들어** 등급·능력치·이름을 직접 계산해요. 게임이 보낸 능력치나 이름은 쓰지 않아요
- 시드가 게임이 실제로 쓰는 형식인지 확인해요: 몬스터 처치·포션 자리(그 층, 드롭 확률 안), 상점(보스 층 바로 전 층, 예전 기록은 보스 층도), 장비 상자. 층과 아이템 레벨이 맞아야 하고 최고 층보다 높을 수 없어요
- 통과하지 못한 장비는 빼고 저장해요 (장비 창에 "확인되지 않은 장비 N개"로 표시). 최강 아이템이 통과하지 못하면 아이템 랭킹에 반영하지 않아요
- 장비는 **최고 층을 새로 세운 순간**의 것을 저장해요. 기능이 생기기 전 기록은 같은 층에 다시 도달하면 채워져요
- 한계: 시드 규칙이 공개돼 있어서, 좋은 아이템이 나오는 시드를 오프라인에서 찾아 보내는 것까지는 막지 못해요 (실제 획득 기록을 서버가 받아야 막을 수 있어요). 강화 단계와 최고 층도 게임이 보내는 값이에요
- `items.js`의 규칙을 바꾸면 `rules.php`도 같이 바꿔야 해요 (다르면 정상 아이템이 거부돼요)

## 거래소와 우편함

- 로비의 거래소에서 가방의 아이템을 골드·캐시 가격으로 올리고, 다른 사람이 사요. 거래는 **로그인 사용자만** (캐시 수수료 때문)
- **수수료**: 모든 거래에 캐시 1. 사는 사람이 가격에 더해 내요 (가격 ◆3 → ◆4 차감). 판매자는 정한 가격을 그대로 받아요
- **레전더리·세트** 아이템은 캐시 가격이 1 이상이어야 해요
- 캐시 차감: 서버가 **구매자의 로그인 토큰**으로 Xsolla 인벤토리의 가상 화폐를 사용(consume)해요. 별도 아이템이 필요 없어요
- 판매 대금: 판매자 **우편함**으로 가요. 받을 때 서버가 **프로젝트 API 키**로 Xsolla에 캐시를 지급(reward)해요 → `config.php`에 `xsolla_api_key`가 있어야 해요
- 산 아이템, 내린 아이템, **24시간이 지나 안 팔린 아이템**도 우편함으로 가요. 가방(48칸)이 가득 차면 정리한 뒤 받아요
- 한 사람이 동시에 올릴 수 있는 건 10개
- 서버에 남는 것: 아이템(시드로 다시 만든 정보), 판매자·구매자(내부 키와 가린 이름), 골드·캐시 가격, 수수료, 올린·팔린·끝난 시간 (`listings` 표), 우편(`mail` 표), 아이템 ID별 주인(`item_owner` 표). Xsolla 사용자 ID·이메일·토큰은 저장하지 않아요
- 복사 방지: 아이템 ID는 시드에서 정해져서 복사본도 ID가 같아요. 남에게 넘어간 아이템은 원래 주인이 다시 올릴 수 없고, 랭킹에서도 인정하지 않아요
- 한계: 골드는 게임(브라우저)에 저장되는 값이라 서버가 잔액을 확인할 수 없어요. 캐시는 Xsolla가 확인해요

### 설정 (`config.php`에 추가)

```php
    'xsolla_api_key' => '<프로젝트 API 키>',   // Publisher Account → 프로젝트 설정 → API 키. 판매 대금 지급에 필요
    // 'xsolla_project_id' => 317991,           // 기본값. 다른 Store 프로젝트를 쓸 때만
```

## 하루 입장과 광고 보상

- **1층 문으로 새로 시작할 때만** 하루 입장 횟수를 세요. 하루 **5회 무료**, 그 뒤엔 광고를 봐야 들어가요 (포탈 층으로 다시 들어가는 건 세지 않아요)
- 광고로 대신할 수 있는 것 (캐시로 하는 방법은 그대로): **부활 하루 1회**, **거래 수수료 면제 하루 3회**. 광고 입장은 하루 30회까지
- 하루는 **한국 시간 자정**에 바뀌어요. 개발자 계정은 입장 제한이 없어요
- 광고를 끝까지 봤다는 신호는 브라우저에서 와서 서버가 확인할 수 없어요. 그래서 광고를 시작할 때 티켓을 주고, `ad_min_seconds`(기본 5초)가 지나야 쓸 수 있게 하고, 티켓은 한 번만·그날만 쓰고, 종류마다 하루 상한을 둬요
- 기록: `daily_entries`(사람별 하루 무료 입장 수), `ad_tickets`(광고 티켓). 3일 지난 티켓은 지워요
- 광고 종류는 게임의 `ads-config.js`. 지금은 테스트 광고(5초 안내 화면, 수익 없음). 구글 AdSense **H5 게임 광고(보상형)** 승인을 받으면 거기에 게시자 ID를 넣어요

## 계정 저장 (가방·진행 상황)

로그인한 사람의 **가방, 장착 장비, 강화석, 도감, 진행 중인 판(층·골드·레벨), 상점 상자 보관함**을 서버에 저장해요 (`profile.php`). 같은 계정이면 웹·앱·다른 기기에서 같은 데이터를 써요.

- `GET ?r=profile` → `{ rev, data }` / `POST ?r=profile { rev, data }` → `{ ok, rev }`
- `rev`(저장 번호)가 서버와 같을 때만 덮어써요. 다른 기기가 먼저 저장했으면 `{ ok: false, conflict: true, rev, data }`로 서버 내용을 돌려주고, 게임은 그걸로 바꿔요 (두 기기가 서로의 저장을 지우지 않게)
- 요청을 줄이려고 게임은 **중요한 변경**(아이템 얻기·팔기·장착·강화, 층 이동, 포탈, 쓰러짐·부활, 골드 거래)은 2초 모았다가, **이동·전투**(HP 등)는 1분에 한 번 올려요. 탭을 닫거나 숨길 때·로그아웃할 때는 바로 올리고, 지난번에 올린 것과 같으면 보내지 않아요. 서버가 안 되면 15초마다 다시 시도해요
- 계속 플레이하는 사람 1명이 1시간에 약 60번 정도 보내요
- 처음 한 번은 그 브라우저에 있던 가방을 올려요. 예전 방식(Xsolla 계정 속성)에 저장된 진행 상황이 있으면 그걸 이어서 올려요
- 게스트는 서버에 저장하지 않아요 (그 기기에만). 계정을 연결하면 게스트 장비가 계정으로 옮겨져요
- 얻은·버린 기록(log)은 커서 브라우저에만 둬요. 아이템 위조는 지금처럼 랭킹·거래소에서 시드로 검증해요
- 기록: `profiles` 표 (사람 키는 HMAC, 내용은 JSON). 한 사람당 200KB까지

## 운영자 선물 (테스터·이벤트 지급)

서버에서 명령으로 특정 사용자의 우편함에 골드·캐시를 넣어요. 웹으로는 열리지 않아요.

```bash
cd /var/www/html/ranking
sudo -u www-data php gift.php <이메일> <골드> <캐시> "메모"
# 예) sudo -u www-data php gift.php tester@example.com 99999 99999 "테스터 지급"
```

- 이메일 원본은 저장하지 않아요. 랭킹 기록의 이메일 HMAC으로 사람을 찾아서, **그 계정으로 로그인해 한 번이라도 플레이한 사용자**만 찾을 수 있어요 (개발자 계정은 랭킹에 저장되지 않아서 못 찾아요)
- 받는 사람이 로비 우편함에서 "받기"를 누르면 골드는 지금 판에 더해지고(쓰러진 판이면 새로 시작하거나 부활한 뒤), 캐시는 서버가 Xsolla로 지급해요 (`xsolla_api_key` 필요)
- 골드는 판마다 따로라서, 쓰러진 뒤 새로 시작하면 사라져요. 캐시는 계정에 남아요
- 기록은 `mail` 표에 `kind = 'gift'`로 남아요

### "받기"가 서버 오류로 멈췄을 때

캐시는 들어왔는데 골드·아이템이 안 들어오고 우편이 그대로 남아 있으면(예전 버전의 "database is locked" 오류), 그 사람이 캐시를 받은 걸 확인한 뒤:

```bash
sudo -u www-data php gift.php --resume <이메일>
```

캐시는 이미 받은 것으로 표시하고, 다음 "받기"에서 골드·아이템만 들어가요 (캐시를 다시 지급하지 않아요).
지금 버전은 캐시를 지급하거나 받은 순간을 바로 기록해서, 중간에 오류가 나도 다음 "받기"나 1분 뒤 자동으로 마무리돼요.

## 업데이트

서버에 이미 올려 둔 경우, 새 파일만 덮어써요 (`config.php`와 `data/`는 그대로).

```bash
cd /tmp && rm -rf dungeon && git clone --depth 1 https://github.com/hucksoon/hucksoon.git dungeon
sudo cp /tmp/dungeon/ranking-php/api.php /tmp/dungeon/ranking-php/rules.php /tmp/dungeon/ranking-php/market.php /tmp/dungeon/ranking-php/gift.php /tmp/dungeon/ranking-php/ads.php /tmp/dungeon/ranking-php/profile.php /var/www/html/ranking/
```

DB 표는 다음 요청 때 새 칸이 자동으로 추가돼요.

PATH_INFO를 지원하는 서버면 `api.php/api/score` 형식도 돼요. 게임은 주소가 `.php`로 끝나면 `?r=` 형식을 써요.

## DB

- 기본: SQLite 파일 `data/ranking.sqlite` (표는 처음 요청 때 자동 생성)
- MySQL: `config.php`의 `db_dsn`, `db_user`, `db_pass`를 바꾸면 돼요 (표 자동 생성은 같아요. MySQL은 테스트하지 않았어요)

## 긴급 확인

DB에는 이메일 원본이 없고 `email_hmac`만 있어요. `hash_key`를 가진 사람만 찾을 수 있어요.

```bash
RANKING_HASH_KEY=<hash_key> node tools/ranking-lookup.mjs someone@example.com
# 출력된 SELECT 문을 서버 DB에서 실행 (SQLite: sqlite3 data/ranking.sqlite "<SELECT 문>")
```
