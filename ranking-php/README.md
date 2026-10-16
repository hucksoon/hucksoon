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
