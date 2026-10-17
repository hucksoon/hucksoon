<?php
// 던전 크롤러 랭킹 서버 (PHP 버전). ranking/worker.js(Cloudflare)와 같은 API·규칙.
//
//   POST api.php?r=score    내 기록 올리기 { stage, item: { rarity, enh, lvl, name } | null }
//   GET  api.php?r=ranking  &type=stage|item&limit=50  랭킹 (가린 이름만)
//   GET  api.php?r=me       내 순위
//   GET  api.php?r=player&id=<공개 ID>  그 사람이 최고 층을 세운 순간의 장비·능력치, 최고 아이템
//   POST api.php?r=lobby    로비 위치(또는 탐험 중인 층)를 알리고, 지금 접속 중인 사람들을 받음
//   거래소·우편함 경로는 market.php, 하루 입장·광고 보상 경로는 ads.php 맨 위 설명 참고
//   (서버가 PATH_INFO를 지원하면 api.php/api/score 처럼 불러도 됨)
//
// 누구인지는 서버가 확인한다.
//   로그인 사용자: Authorization: Bearer <Xsolla 사용자 토큰> → 서명·만료·Login 프로젝트 검증
//   게스트:       X-Guest-Id: <게임이 만든 기기 ID> (검증 불가, 조작될 수 있음)
// 개인정보:
//   원본 이메일·휴대폰·기기 ID는 저장하지 않는다. 화면용 가린 값과 HMAC만 저장한다.
//   개발자 계정(dev_emails)은 저장하지 않고 랭킹에서 빠진다.
//
// 위조 방지: 게임은 장비의 (시드, 출처, 레벨, 규칙 버전, 강화)만 보내고, 서버가 rules.php로 아이템을 다시 만들어
//   등급·능력치를 직접 계산한다. 시드가 게임이 실제로 쓰는 형식(몬스터·포션 자리·상점·장비 상자)이고 층과 맞아야 한다.
//
// 설정은 config.php (config.sample.php를 복사). PHP 7.4 이상, PDO(SQLite 또는 MySQL), openssl, curl, mbstring 필요.

declare(strict_types=1);

require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/market.php';
require_once __DIR__ . '/ads.php';

const RARITY_RANK = ['common' => 0, 'uncommon' => 1, 'rare' => 2, 'epic' => 3, 'set' => 4, 'legendary' => 5];
const MAX_STAGE = 10000;
const MAX_ENH = 10;

final class HttpError extends Exception {
    public $status;
    public $codeName;
    public function __construct(int $status, string $code) { parent::__construct($code); $this->status = $status; $this->codeName = $code; }
}

// ---------------------------------------------------------------- 설정

function config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $path = getenv('RANKING_CONFIG') ?: (__DIR__ . '/config.php');
    if (!is_file($path)) throw new RuntimeException('config.php가 없습니다 (config.sample.php를 복사해 만드세요)');
    $cfg = require $path;
    foreach (['hash_key', 'login_project_id', 'allowed_origins', 'db_dsn'] as $k) {
        if (empty($cfg[$k])) throw new RuntimeException("config.php에 {$k}가 없습니다");
    }
    if (strlen((string)$cfg['hash_key']) < 32) throw new RuntimeException('hash_key는 32자 이상의 무작위 값이어야 합니다');
    return $cfg;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $cfg = config();
    $pdo = new PDO($cfg['db_dsn'], $cfg['db_user'] ?? null, $cfg['db_pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,  // SQLite: 다른 요청이 쓰는 중이면 최대 5초 기다림 (로비는 1초마다 씀)
    ]);
    // SQLite는 WAL 모드로: 읽기와 쓰기가 서로 덜 막힘
    // (이미 WAL이면 아무 일도 안 함. 새 DB에 여러 요청이 동시에 오면 하나만 바꾸고 나머지는 잠깐 실패할 수 있어서 무시)
    if (strpos($cfg['db_dsn'], 'sqlite:') === 0) {
        try { $pdo->exec('PRAGMA journal_mode = WAL'); } catch (PDOException $e) { /* 다른 요청이 바꾸는 중 */ }
    }
    // 표는 처음 쓸 때 자동으로 만든다 (SQLite·MySQL 공통 문법)
    $pdo->exec('CREATE TABLE IF NOT EXISTS players (
        pkey         VARCHAR(64) PRIMARY KEY,
        kind         VARCHAR(8)  NOT NULL,
        display      VARCHAR(80) NOT NULL,
        email_hmac   VARCHAR(64),
        best_stage   INTEGER NOT NULL DEFAULT 0,
        stage_at     BIGINT,
        item_score   INTEGER NOT NULL DEFAULT -1,
        item_rarity  VARCHAR(16),
        item_enh     INTEGER,
        item_lvl     INTEGER,
        item_name    VARCHAR(64),
        item_at      BIGINT,
        updated_at   BIGINT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS token_cache (
        token_hash   VARCHAR(64) PRIMARY KEY,
        expires_at   BIGINT NOT NULL
    )');
    // 지금 접속 중인 사람: 로비 위치 또는 탐험 중인 층. 잠깐 동안만 쓰는 값이라 오래된 줄은 지움
    $pdo->exec('CREATE TABLE IF NOT EXISTS presence (
        pkey         VARCHAR(64) PRIMARY KEY,
        pub_id       VARCHAR(32) NOT NULL,
        display      VARCHAR(80) NOT NULL,
        scene        VARCHAR(8)  NOT NULL,
        x            INTEGER NOT NULL DEFAULT 0,
        y            INTEGER NOT NULL DEFAULT 0,
        depth        INTEGER NOT NULL DEFAULT 0,
        seen_at      BIGINT NOT NULL
    )');
    // 나중에 추가된 칸: 기존 DB에도 붙임 (이미 있으면 무시)
    market_tables($pdo);
    ads_tables($pdo);
    foreach (['pub_id VARCHAR(32)', 'stage_gear TEXT', 'item_json TEXT'] as $col) {
        try { $pdo->exec("ALTER TABLE players ADD COLUMN {$col}"); } catch (PDOException $e) { /* 이미 있음 */ }
    }
    foreach ([
        'CREATE INDEX idx_players_pub ON players (pub_id)',
        'CREATE INDEX idx_players_stage ON players (best_stage, stage_at)',
        'CREATE INDEX idx_players_item ON players (item_score, item_at)',
        'CREATE INDEX idx_players_email ON players (email_hmac)',
        'CREATE INDEX idx_presence_seen ON presence (seen_at)',
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* 이미 있음 */ }
    }
    return $pdo;
}

// SQLite 주의: SELECT로 한 줄만 읽고 커서를 열어 두면 그 연결이 옛 읽기 시점을 붙잡고 있어서,
// 그사이 다른 요청(1초마다 오는 로비 핑 등)이 쓰면 이 연결의 다음 쓰기가 "database is locked"로 바로 실패함.
// 그래서 한 줄 읽기는 항상 q_row / q_val로 하고(읽고 바로 닫음), 트랜잭션은 tx()로 처음부터 쓰기 잠금을 잡음.
function q_row(string $sql, array $args = []): ?array {
    $st = db()->prepare($sql);
    $st->execute($args);
    $row = $st->fetch();
    $st->closeCursor();
    return $row === false ? null : $row;
}
function q_val(string $sql, array $args = []) {
    $st = db()->prepare($sql);
    $st->execute($args);
    $v = $st->fetchColumn();
    $st->closeCursor();
    return $v;
}
function is_sqlite(): bool { return strpos((string)config()['db_dsn'], 'sqlite:') === 0; }
// 트랜잭션: SQLite는 BEGIN IMMEDIATE(시작할 때 쓰기 잠금을 잡고, 다른 요청이 쓰는 중이면 기다림)
function tx(callable $fn) {
    $pdo = db();
    $sqlite = is_sqlite();
    if ($sqlite) $pdo->exec('BEGIN IMMEDIATE'); else $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        if ($sqlite) $pdo->exec('COMMIT'); else $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        try { if ($sqlite) $pdo->exec('ROLLBACK'); else $pdo->rollBack(); } catch (Throwable $ignored) { /* 이미 끝남 */ }
        throw $e;
    }
}

// ---------------------------------------------------------------- 응답

function cors_headers(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = array_filter(array_map('trim', explode(',', (string)config()['allowed_origins'])));
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Guest-Id');
    header('Access-Control-Max-Age: 86400');
    if (in_array($origin, $allowed, true)) header('Access-Control-Allow-Origin: ' . $origin);
}

function respond(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ---------------------------------------------------------------- 누구인가

function hmac_hex(string $text): string {
    return hash_hmac('sha256', $text, (string)config()['hash_key']);
}

// 이메일 가리기: 앞 몇 글자와 도메인만 남김. ab***@gmail.com
function mask_email(string $email): string {
    $parts = explode('@', $email, 2);
    $local = $parts[0];
    $domain = $parts[1] ?? '';
    $keep = min(3, max(1, intdiv(mb_strlen($local), 3)));
    return mb_substr($local, 0, $keep) . '***@' . $domain;
}

// 휴대폰 번호 가리기: 마지막 4자리만. ***-5678
function mask_phone(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    return '***-' . substr($digits, -4);
}

function header_value(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) return (string)$_SERVER[$key];
    // 일부 호스팅은 Authorization을 다른 이름으로 넘김
    if ($name === 'Authorization') {
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) return (string)$v;
        }
    }
    return '';
}

function identify(): array {
    $auth = header_value('Authorization');
    if (strpos($auth, 'Bearer ') === 0) {
        $claims = verify_xsolla_token(trim(substr($auth, 7)));
        $email = is_string($claims['email'] ?? null) ? strtolower(trim($claims['email'])) : '';
        $phone = is_string($claims['phone_number'] ?? null) ? $claims['phone_number'] : (is_string($claims['phone'] ?? null) ? $claims['phone'] : '');
        $devs = array_filter(array_map(function ($s) { return strtolower(trim($s)); }, explode(',', (string)(config()['dev_emails'] ?? ''))));
        return [
            'kind' => 'user',
            // token·sub는 이 요청 동안만 씀 (거래소에서 Xsolla 캐시를 차감·지급할 때). 저장하지 않음
            'token' => trim(substr($auth, 7)),
            'sub' => (string)$claims['sub'],
            'key' => hmac_hex('u:' . $claims['sub']),
            'display' => $email !== '' ? mask_email($email) : ($phone !== '' ? mask_phone($phone) : ('사용자 #' . substr((string)$claims['sub'], 0, 6))),
            'email_hmac' => $email !== '' ? hmac_hex('e:' . $email) : null,
            'developer' => $email !== '' && in_array($email, $devs, true),
        ];
    }
    $guest = header_value('X-Guest-Id');
    if (preg_match('/^[0-9a-zA-Z-]{8,64}$/', $guest)) {
        return ['kind' => 'guest', 'key' => hmac_hex('g:' . $guest), 'display' => '게스트 #' . substr($guest, 0, 6), 'email_hmac' => null, 'developer' => false];
    }
    throw new HttpError(401, 'unauthorized');
}

// ---------------------------------------------------------------- Xsolla 토큰 검증

function b64url_decode(string $s): string {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $out = base64_decode($s, true);
    if ($out === false) throw new HttpError(401, 'bad_token');
    return $out;
}

function verify_xsolla_token(string $token): array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) throw new HttpError(401, 'bad_token');
    $header = json_decode(b64url_decode($parts[0]), true);
    $claims = json_decode(b64url_decode($parts[1]), true);
    if (!is_array($header) || !is_array($claims)) throw new HttpError(401, 'bad_token');

    if (!is_int($claims['exp'] ?? null) || $claims['exp'] <= time()) throw new HttpError(401, 'token_expired');
    if (empty($claims['sub'])) throw new HttpError(401, 'bad_token');
    // 다른 Xsolla 프로젝트의 토큰은 받지 않음
    if ((string)($claims['xsolla_login_project_id'] ?? '') !== (string)config()['login_project_id']) throw new HttpError(401, 'wrong_project');

    $signed = $parts[0] . '.' . $parts[1];
    $sig = b64url_decode($parts[2]);
    $alg = $header['alg'] ?? '';

    if ($alg === 'RS256') {
        $jwk = null;
        foreach (get_jwks() as $k) if (($k['kid'] ?? null) === ($header['kid'] ?? '')) $jwk = $k;
        if (!$jwk) throw new HttpError(401, 'unknown_key');
        $ok = openssl_verify($signed, $sig, jwk_to_pem($jwk), OPENSSL_ALGO_SHA256);
        if ($ok !== 1) throw new HttpError(401, 'bad_signature');
        return $claims;
    }
    $secret = (string)(config()['jwt_secret'] ?? '');
    if ($alg === 'HS256' && $secret !== '') {
        if (!hash_equals(hash_hmac('sha256', $signed, $secret, true), $sig)) throw new HttpError(401, 'bad_signature');
        return $claims;
    }

    // 서명 키가 없으면 Xsolla에 직접 확인 (요청 제한이 있어 결과를 토큰 만료까지 재사용)
    $hash = hash('sha256', $token);
    $row = q_row('SELECT expires_at FROM token_cache WHERE token_hash = ?', [$hash]);
    if ($row && (int)$row['expires_at'] > time()) return $claims;
    [$status] = http_request('POST', config()['xsolla_validate_url'] ?? 'https://login.xsolla.com/api/token/validate', json_encode(['token' => $token]));
    if ($status < 200 || $status >= 300) throw new HttpError(401, 'bad_signature');
    db()->prepare('DELETE FROM token_cache WHERE expires_at < ?')->execute([time()]);
    db()->prepare('DELETE FROM token_cache WHERE token_hash = ?')->execute([$hash]);
    db()->prepare('INSERT INTO token_cache (token_hash, expires_at) VALUES (?, ?)')->execute([$hash, $claims['exp']]);
    return $claims;
}

function http_request(string $method, string $url, ?string $body = null, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $text = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $text === false ? '' : $text];
}

// Xsolla Login 공개 키 목록. 1시간 동안 파일에 보관
function get_jwks(): array {
    $cfg = config();
    $url = $cfg['jwks_url'] ?? "https://login.xsolla.com/api/projects/{$cfg['login_project_id']}/jwks.json";
    $cache = ($cfg['cache_dir'] ?? sys_get_temp_dir()) . '/jwks-' . md5($url) . '.json';
    if (is_file($cache) && filemtime($cache) > time() - 3600) {
        $keys = json_decode((string)file_get_contents($cache), true);
        if (is_array($keys) && $keys) return $keys;
    }
    [$status, $text] = http_request('GET', $url);
    $body = json_decode($text, true);
    if ($status !== 200 || !is_array($body['keys'] ?? null)) throw new HttpError(503, 'jwks_unavailable');
    @file_put_contents($cache, json_encode($body['keys']), LOCK_EX);
    return $body['keys'];
}

// JWK(RSA n, e) → PEM 공개 키
function jwk_to_pem(array $jwk): string {
    $der = function (int $tag, string $bytes): string {
        $len = strlen($bytes);
        if ($len < 0x80) return chr($tag) . chr($len) . $bytes;
        $l = ltrim(pack('N', $len), "\0");
        return chr($tag) . chr(0x80 | strlen($l)) . $l . $bytes;
    };
    $int = function (string $b) use ($der): string {
        if (ord($b[0]) > 0x7f) $b = "\0" . $b;
        return $der(0x02, $b);
    };
    $rsa = $der(0x30, $int(b64url_decode($jwk['n'])) . $int(b64url_decode($jwk['e'])));
    $algo = $der(0x30, $der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00");
    $spki = $der(0x30, $algo . $der(0x03, "\0" . $rsa));
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

// ---------------------------------------------------------------- 기록 올리기

const SLOT_NAMES = ['head' => '머리', 'chest' => '가슴', 'arms' => '팔', 'pants' => '바지', 'boots' => '장화', 'weapon' => '무기'];

// 게임이 실제로 만드는 시드인지, 층·출처가 맞는지 (items.js / index.html / shop.html 의 시드 형식)
function seed_origin_ok(int $v, string $seed, string $src, int $lvl): bool {
    if (preg_match('/^[0-9A-Za-z_-]{1,40}\|d(\d{1,5})\|k\d{1,8}$/', $seed, $m)) {           // 몬스터 처치
        if ((int)$m[1] !== $lvl) return false;
        if ($src === 'drop') return r_drop_roll($v, $seed) < R_DROP_CHANCE['elite'];      // 일반 8% / 정예 40% 안에 들어야 떨어짐
        if ($src === 'boss') return $lvl % 5 === 0;
        return $src === 'golden' && $lvl >= 2;
    }
    if (preg_match('/^[0-9A-Za-z_-]{1,40}\|d(\d{1,5})\|p\d{1,8}$/', $seed, $m)) {           // 포션 자리
        return (int)$m[1] === $lvl && $src === 'drop' && r_drop_roll($v, $seed) < R_DROP_CHANCE['normal'];
    }
    if (preg_match('/^[0-9A-Za-z_-]{1,40}\|shop\|d(\d{1,5})(\|r[1-3])?\|s[0-5]$/', $seed, $m)) { // 상점
        // 보스 층 바로 전 층(4, 9, 14…). 예전에는 보스 층(5, 10…)에 있어서 그때 산 아이템도 인정
        return (int)$m[1] === $lvl && ($lvl % 5 === 4 || $lvl % 5 === 0) && $src === 'golden';
    }
    if (preg_match('/^box\|o[0-9A-Za-z]{1,48}\|(\d{1,2})$/', $seed, $m)) {                   // 장비 상자 (최대 50회)
        return $src === 'box' && (int)$m[1] < 50;
    }
    return false;
}

// 게임이 보낸 아이템 참조 { seed, src, lvl, v, enh } → 서버가 다시 만든 아이템. 형식이 틀리거나 위조면 null
function verify_item($q, int $maxLvl): ?array {
    if (!is_array($q)) return null;
    $seed = $q['seed'] ?? null; $src = $q['src'] ?? null; $lvl = $q['lvl'] ?? null; $v = $q['v'] ?? 1; $enh = $q['enh'] ?? 0;
    if (!is_string($seed) || strlen($seed) > 120 || !is_string($src) || !isset(R_TABLES[$src])) return null;
    // 아이템 레벨은 얻은 층이라 최고 층보다 높을 수 없음
    if (!is_int($lvl) || $lvl < 1 || $lvl > $maxLvl || !in_array($v, [1, 2], true)) return null;
    if (!is_int($enh) || $enh < 0 || $enh > MAX_ENH) return null;
    if (!seed_origin_ok($v, $seed, $src, $lvl)) return null;
    $item = r_generate($v, $seed, $src, $lvl);
    if (isset($q['id']) && $q['id'] !== $item['id']) return null;
    $item['enh'] = $enh;
    return $item;
}

// 화면에 보여줄 아이템 정보 (서버가 계산한 값만)
function item_view(array $it): array {
    $fmt = function (string $k, int $v): string { return R_STAT_NAMES[$k][0] . ' +' . $v . (R_STAT_NAMES[$k][1] ? '%' : ''); };
    $rng = function (string $k, ?array $r): ?string {
        if (!$r) return null;
        $u = R_STAT_NAMES[$k][1] ? '%' : '';
        return $r[0] === $r[1] ? "{$r[1]}{$u}" : "{$r[0]}~{$r[1]}{$u}";
    };
    $eb = r_enhanced_base($it);
    $main = [];
    foreach (r_main_stats($it) as [$k, $v]) {
        $range = isset($it['main']) ? r_main_range($k, $it) : null;
        $main[] = ['text' => $fmt($k, $eb[$k]), 'range' => $rng($k, $range), 'max' => $range && $v === $range[1], 'plus' => $eb[$k] - $v];
    }
    $lines = [];
    foreach (r_enhanced_lines($it) as [$k, $v, $plus]) {
        $range = r_line_range($k, $it['lvl']);
        $lines[] = ['text' => $fmt($k, $v), 'range' => $rng($k, $range), 'max' => $v - $plus === $range[1], 'plus' => $plus];
    }
    return [
        'name' => r_item_name($it), 'rarity' => $it['rarity'], 'rarityName' => R_RARITIES[$it['rarity']]['name'],
        'slot' => $it['slot'], 'slotName' => SLOT_NAMES[$it['slot']], 'lvl' => $it['lvl'], 'enh' => $it['enh'],
        'ancient' => !empty($it['ancient']), 'set' => $it['set'] ? R_SETS[$it['set']]['name'] : null,
        'main' => $main, 'lines' => $lines,
    ];
}

function read_score(array $body, int $oldBest, string $key): array {
    $stage = $body['stage'] ?? null;
    if (!is_int($stage) || $stage < 1 || $stage > MAX_STAGE) throw new HttpError(400, 'bad_stage');
    $maxLvl = max($stage, $oldBest);
    $plvl = $body['plvl'] ?? 1;
    if (!is_int($plvl) || $plvl < 1 || $plvl > 500) $plvl = 1;

    // 최고 아이템: 시드로 다시 만든 아이템만 인정 (시드가 없는 예전 형식은 무시)
    $item = null;
    $verified = verify_item($body['item'] ?? null, $maxLvl);
    // 거래소에서 남에게 판 아이템(의 복사본)은 인정하지 않음
    if ($verified && owned_by_other($verified['id'], $key)) $verified = null;
    if ($verified) {
        $item = [
            'rarity' => $verified['rarity'], 'enh' => $verified['enh'], 'lvl' => $verified['lvl'], 'name' => r_item_name($verified),
            'score' => RARITY_RANK[$verified['rarity']] * 100000 + $verified['enh'] * 1000 + $verified['lvl'],
            'json' => json_encode(item_view($verified), JSON_UNESCAPED_UNICODE),
        ];
    }

    // 장착 장비: 부위마다 1개, 검증을 통과한 것만
    $gear = [];
    $rejected = 0;
    foreach (array_slice(is_array($body['gear'] ?? null) ? $body['gear'] : [], 0, 6) as $q) {
        $it = verify_item($q, $maxLvl);
        if (!$it || isset($gear[$it['slot']]) || owned_by_other($it['id'], $key)) { $rejected++; continue; }
        $gear[$it['slot']] = $it;
    }
    $ordered = [];
    foreach (R_SLOTS as $slot) if (isset($gear[$slot])) $ordered[] = $gear[$slot];
    $totals = r_totals($ordered);
    $snapshot = [
        'stage' => $stage,
        'stats' => r_player_stats($plvl, $totals),
        'items' => array_map('item_view', $ordered),
        'sets' => array_map(function ($s) { return ['name' => $s['name'], 'count' => $s['count'], 'bonuses' => $s['bonuses']]; }, $totals['sets']),
        'rejected' => $rejected,
    ];
    return [$stage, $item, $snapshot];
}

function now_ms(): int { return (int)floor(microtime(true) * 1000); }

// 랭킹에 공개하는 ID: 내부 키에서 다시 만든 짧은 값 (내부 키·기기 ID·이메일을 알 수 없음)
function pub_id(string $pkey): string { return substr(hmac_hex('p:' . $pkey), 0, 16); }

function submit_score(): array {
    $who = identify();
    $pdo = db();
    if ($who['developer']) {
        // 개발자 계정은 저장하지 않음 (예전에 저장된 게 있으면 지움)
        $pdo->prepare('DELETE FROM players WHERE pkey = ?')->execute([$who['key']]);
        return ['ok' => true, 'ranked' => false, 'reason' => 'developer'];
    }
    $raw = file_get_contents('php://input');
    if (strlen((string)$raw) > 8192) throw new HttpError(413, 'too_large');
    $body = json_decode((string)$raw, true);
    if (!is_array($body)) throw new HttpError(400, 'bad_json');
    [$stage, $item, $snapshot] = read_score($body, (int)(q_val('SELECT best_stage FROM players WHERE pkey = ?', [$who['key']]) ?: 0), $who['key']);
    $now = now_ms();
    $snapshot['at'] = $now;
    $gearJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE);

    // 최고 기록만 남김: 층은 더 높을 때, 아이템은 점수가 더 높을 때만 바꿈
    tx(function (PDO $pdo) use ($who, $stage, $item, $now, $gearJson) {
        $old = q_row('SELECT * FROM players WHERE pkey = ?', [$who['key']]);
        if (!$old) {
            $pdo->prepare('INSERT INTO players (pkey, pub_id, kind, display, email_hmac, best_stage, stage_at, stage_gear, item_score, item_rarity, item_enh, item_lvl, item_name, item_json, item_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $who['key'], pub_id($who['key']), $who['kind'], $who['display'], $who['email_hmac'], $stage, $now, $gearJson,
                $item ? $item['score'] : -1, $item['rarity'] ?? null, $item['enh'] ?? null, $item['lvl'] ?? null, $item['name'] ?? null, $item['json'] ?? null, $item ? $now : null, $now,
            ]);
        } else {
            $betterStage = $stage > (int)$old['best_stage'];
            $betterItem = $item && $item['score'] > (int)$old['item_score'];
            // 장비는 최고 층을 세운 순간의 것. 예전 기록이라 장비가 없으면 같은 층일 때 채움
            $saveGear = $betterStage || ($stage === (int)$old['best_stage'] && empty($old['stage_gear']));
            $pdo->prepare('UPDATE players SET display = ?, email_hmac = ?, updated_at = ?, pub_id = ?,
                best_stage = ?, stage_at = ?, stage_gear = ?,
                item_score = ?, item_rarity = ?, item_enh = ?, item_lvl = ?, item_name = ?, item_json = ?, item_at = ?
                WHERE pkey = ?')->execute([
                $who['display'], $who['email_hmac'], $now, $old['pub_id'] ?: pub_id($who['key']),
                $betterStage ? $stage : (int)$old['best_stage'], $betterStage ? $now : $old['stage_at'], $saveGear ? $gearJson : $old['stage_gear'],
                $betterItem ? $item['score'] : (int)$old['item_score'],
                $betterItem ? $item['rarity'] : $old['item_rarity'], $betterItem ? $item['enh'] : $old['item_enh'],
                $betterItem ? $item['lvl'] : $old['item_lvl'], $betterItem ? $item['name'] : $old['item_name'],
                $betterItem ? $item['json'] : $old['item_json'],
                $betterItem ? $now : $old['item_at'],
                $who['key'],
            ]);
        }
    });
    return ['ok' => true, 'ranked' => true];
}

// ---------------------------------------------------------------- 랭킹

const BOARDS = [
    'stage' => ['where' => 'best_stage > 0', 'order' => 'best_stage DESC, stage_at ASC'],
    'item' => ['where' => 'item_score >= 0', 'order' => 'item_score DESC, item_at ASC'],
];

function row_out(array $r, ?int $rank): array {
    return [
        'rank' => $rank,
        'id' => $r['pub_id'] ?? null,
        'name' => $r['display'],
        'guest' => $r['kind'] === 'guest',
        'stage' => (int)$r['best_stage'],
        'item' => (int)$r['item_score'] >= 0
            ? ['rarity' => $r['item_rarity'], 'enh' => (int)$r['item_enh'], 'lvl' => (int)$r['item_lvl'], 'name' => $r['item_name']]
            : null,
    ];
}

function get_ranking(): array {
    $type = ($_GET['type'] ?? '') === 'item' ? 'item' : 'stage';
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50) ?: 50));
    $b = BOARDS[$type];
    // 공개 ID가 없는 예전 행은 채워 넣음
    $missing = db()->query('SELECT pkey FROM players WHERE pub_id IS NULL LIMIT 200')->fetchAll();
    foreach ($missing as $m) db()->prepare('UPDATE players SET pub_id = ? WHERE pkey = ?')->execute([pub_id($m['pkey']), $m['pkey']]);
    $rows = db()->query("SELECT pub_id, display, kind, best_stage, item_score, item_rarity, item_enh, item_lvl, item_name FROM players WHERE {$b['where']} ORDER BY {$b['order']} LIMIT {$limit}")->fetchAll();
    $total = (int)q_val("SELECT COUNT(*) FROM players WHERE {$b['where']}");
    $out = [];
    foreach ($rows as $i => $r) $out[] = row_out($r, $i + 1);
    return ['type' => $type, 'total' => $total, 'updatedAt' => now_ms(), 'rows' => $out];
}

function get_me(): array {
    $who = identify();
    if ($who['developer']) return ['developer' => true];
    $r = q_row('SELECT * FROM players WHERE pkey = ?', [$who['key']]);
    if (!$r) return ['name' => $who['display'], 'stageRank' => null, 'itemRank' => null];
    $ahead = function (string $sql, array $args): int { return (int)q_val($sql, $args); };
    $stageRank = (int)$r['best_stage'] > 0
        ? 1 + $ahead('SELECT COUNT(*) AS n FROM players WHERE best_stage > ? OR (best_stage = ? AND stage_at < ?)', [$r['best_stage'], $r['best_stage'], $r['stage_at']])
        : null;
    $itemRank = (int)$r['item_score'] >= 0
        ? 1 + $ahead('SELECT COUNT(*) AS n FROM players WHERE item_score > ? OR (item_score = ? AND item_at < ?)', [$r['item_score'], $r['item_score'], $r['item_at']])
        : null;
    return array_merge(row_out($r, null), ['stageRank' => $stageRank, 'itemRank' => $itemRank]);
}

function get_player(): array {
    $id = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[0-9a-f]{16}$/', $id)) throw new HttpError(400, 'bad_id');
    $r = q_row('SELECT * FROM players WHERE pub_id = ?', [$id]);
    if (!$r) throw new HttpError(404, 'not_found');
    $gear = $r['stage_gear'] ? json_decode($r['stage_gear'], true) : null;
    return [
        'name' => $r['display'],
        'guest' => $r['kind'] === 'guest',
        'stage' => (int)$r['best_stage'],
        'stageAt' => $r['stage_at'] !== null ? (int)$r['stage_at'] : null,
        'gear' => $gear,
        'bestItem' => $r['item_json'] ? json_decode($r['item_json'], true) : null,
    ];
}

// ---------------------------------------------------------------- 로비 (지금 접속 중인 사람)

// 로비에 있는 사람은 1초마다, 던전에 있는 사람은 10초마다 위치를 보냄. 이 시간 동안 소식이 없으면 나간 것으로 봄
const LOBBY_TTL_MS = 8000;
const DUNGEON_TTL_MS = 25000;

// POST api.php?r=lobby { scene: "lobby", x, y, portal } 또는 { scene: "dungeon", depth }
//   portal: 로비에 있을 때 그 사람이 포탈을 타고 나온 층 (다시 들어갈 수 있는 층, 없으면 0). 머리 위에 "◎N층"으로 보임
// → 내 정보와 지금 접속 중인 사람들 (가린 이름과 공개 ID만)
function lobby_ping(): array {
    $who = identify();
    $raw = file_get_contents('php://input');
    if (strlen((string)$raw) > 1024) throw new HttpError(413, 'too_large');
    $body = json_decode((string)$raw, true);
    if (!is_array($body)) throw new HttpError(400, 'bad_json');
    $scene = ($body['scene'] ?? '') === 'dungeon' ? 'dungeon' : 'lobby';
    $int = function ($v, int $max): int { return is_int($v) ? max(0, min($max, $v)) : 0; };
    $x = $scene === 'lobby' ? $int($body['x'] ?? 0, 63) : 0;
    $y = $scene === 'lobby' ? $int($body['y'] ?? 0, 63) : 0;
    // depth 칸: 던전이면 탐험 중인 층, 로비면 포탈 층
    $depth = $int($body[$scene === 'dungeon' ? 'depth' : 'portal'] ?? 0, MAX_STAGE);
    $now = now_ms();
    $pub = pub_id($who['key']);
    $pdo = db();
    $up = $pdo->prepare('UPDATE presence SET pub_id = ?, display = ?, scene = ?, x = ?, y = ?, depth = ?, seen_at = ? WHERE pkey = ?');
    $up->execute([$pub, $who['display'], $scene, $x, $y, $depth, $now, $who['key']]);
    if ($up->rowCount() === 0) {
        try {
            $pdo->prepare('INSERT INTO presence (pkey, pub_id, display, scene, x, y, depth, seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$who['key'], $pub, $who['display'], $scene, $x, $y, $depth, $now]);
        } catch (PDOException $e) { /* 같은 사람이 동시에 보낸 요청이 먼저 넣음 */ }
    }
    $pdo->prepare('DELETE FROM presence WHERE seen_at < ?')->execute([$now - 60000]);
    $st = $pdo->prepare('SELECT pub_id, display, scene, x, y, depth FROM presence
        WHERE (scene = ? AND seen_at >= ?) OR (scene = ? AND seen_at >= ?) ORDER BY seen_at DESC LIMIT 200');
    $st->execute(['lobby', $now - LOBBY_TTL_MS, 'dungeon', $now - DUNGEON_TTL_MS]);
    $players = [];
    foreach ($st->fetchAll() as $r) {
        $p = ['id' => $r['pub_id'], 'name' => $r['display'], 'scene' => $r['scene']];
        if ($r['scene'] === 'lobby') { $p['x'] = (int)$r['x']; $p['y'] = (int)$r['y']; $p['portal'] = (int)$r['depth']; }
        else $p['depth'] = (int)$r['depth'];
        $players[] = $p;
    }
    return [
        'me' => ['id' => $pub, 'name' => $who['display']], 'players' => $players, 'at' => $now,
        'mail' => $who['kind'] === 'user' ? mail_count($who['key']) : 0,  // 우편함에 받을 것
    ];
}

// ---------------------------------------------------------------- 시작

// 서버 명령(gift.php 등)이 함수만 빌려 쓸 때는 요청 처리를 하지 않음
if (defined('RANKING_NO_ROUTE')) return;

try {
    cors_headers();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') { http_response_code(204); exit; }
    $route = $_GET['r'] ?? '';
    if ($route === '' && !empty($_SERVER['PATH_INFO'])) $route = preg_replace('#^/?(api/)?#', '', (string)$_SERVER['PATH_INFO']);
    if ($route === 'score' && $method === 'POST') respond(200, submit_score());
    elseif ($route === 'ranking' && $method === 'GET') respond(200, get_ranking());
    elseif ($route === 'me' && $method === 'GET') respond(200, get_me());
    elseif ($route === 'player' && $method === 'GET') respond(200, get_player());
    elseif ($route === 'lobby' && $method === 'POST') respond(200, lobby_ping());
    elseif ($route === 'market' && $method === 'GET') respond(200, market_browse());
    elseif ($route === 'market_list' && $method === 'POST') respond(200, market_list());
    elseif ($route === 'market_cancel' && $method === 'POST') respond(200, market_cancel());
    elseif ($route === 'market_buy' && $method === 'POST') respond(200, market_buy());
    elseif ($route === 'market_mine' && $method === 'GET') respond(200, market_mine());
    elseif ($route === 'mail_claim' && $method === 'POST') respond(200, mail_claim());
    elseif ($route === 'entry_status' && $method === 'GET') respond(200, entry_status());
    elseif ($route === 'entry' && $method === 'POST') respond(200, entry_start());
    elseif ($route === 'ad_start' && $method === 'POST') respond(200, ad_start());
    elseif ($route === 'revive_ad' && $method === 'POST') respond(200, revive_ad());
    else respond(404, ['error' => 'not_found']);
} catch (HttpError $e) {
    respond($e->status, ['error' => $e->codeName]);
} catch (Throwable $e) {
    error_log('[ranking] ' . $e->getMessage());
    respond(500, ['error' => 'server_error']);
}
