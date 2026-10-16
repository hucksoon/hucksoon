<?php
// 던전 크롤러 랭킹 서버 (PHP 버전). ranking/worker.js(Cloudflare)와 같은 API·규칙.
//
//   POST api.php?r=score    내 기록 올리기 { stage, item: { rarity, enh, lvl, name } | null }
//   GET  api.php?r=ranking  &type=stage|item&limit=50  랭킹 (가린 이름만)
//   GET  api.php?r=me       내 순위
//   (서버가 PATH_INFO를 지원하면 api.php/api/score 처럼 불러도 됨)
//
// 누구인지는 서버가 확인한다.
//   로그인 사용자: Authorization: Bearer <Xsolla 사용자 토큰> → 서명·만료·Login 프로젝트 검증
//   게스트:       X-Guest-Id: <게임이 만든 기기 ID> (검증 불가, 조작될 수 있음)
// 개인정보:
//   원본 이메일·휴대폰·기기 ID는 저장하지 않는다. 화면용 가린 값과 HMAC만 저장한다.
//   개발자 계정(dev_emails)은 저장하지 않고 랭킹에서 빠진다.
//
// 설정은 config.php (config.sample.php를 복사). PHP 7.4 이상, PDO(SQLite 또는 MySQL), openssl, curl 필요.

declare(strict_types=1);

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
    ]);
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
    foreach ([
        'CREATE INDEX idx_players_stage ON players (best_stage, stage_at)',
        'CREATE INDEX idx_players_item ON players (item_score, item_at)',
        'CREATE INDEX idx_players_email ON players (email_hmac)',
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* 이미 있음 */ }
    }
    return $pdo;
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
    $st = db()->prepare('SELECT expires_at FROM token_cache WHERE token_hash = ?');
    $st->execute([$hash]);
    $row = $st->fetch();
    if ($row && (int)$row['expires_at'] > time()) return $claims;
    [$status] = http_request('POST', config()['xsolla_validate_url'] ?? 'https://login.xsolla.com/api/token/validate', json_encode(['token' => $token]));
    if ($status < 200 || $status >= 300) throw new HttpError(401, 'bad_signature');
    db()->prepare('DELETE FROM token_cache WHERE expires_at < ?')->execute([time()]);
    db()->prepare('DELETE FROM token_cache WHERE token_hash = ?')->execute([$hash]);
    db()->prepare('INSERT INTO token_cache (token_hash, expires_at) VALUES (?, ?)')->execute([$hash, $claims['exp']]);
    return $claims;
}

function http_request(string $method, string $url, ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
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

function read_score(array $body): array {
    $stage = $body['stage'] ?? null;
    if (!is_int($stage) || $stage < 1 || $stage > MAX_STAGE) throw new HttpError(400, 'bad_stage');
    $item = null;
    if (!empty($body['item'])) {
        $it = $body['item'];
        if (!is_array($it)) throw new HttpError(400, 'bad_item');
        $rarity = $it['rarity'] ?? '';
        $enh = $it['enh'] ?? null;
        $lvl = $it['lvl'] ?? null;
        if (!is_string($rarity) || !array_key_exists($rarity, RARITY_RANK)) throw new HttpError(400, 'bad_item');
        if (!is_int($enh) || $enh < 0 || $enh > MAX_ENH) throw new HttpError(400, 'bad_item');
        // 아이템 레벨은 얻은 층이라 최고 층보다 높을 수 없음
        if (!is_int($lvl) || $lvl < 1 || $lvl > $stage) throw new HttpError(400, 'bad_item');
        $name = mb_substr(preg_replace('/[\x00-\x1f<>]/u', '', (string)($it['name'] ?? '')) ?? '', 0, 40);
        $item = ['rarity' => $rarity, 'enh' => $enh, 'lvl' => $lvl, 'name' => $name, 'score' => RARITY_RANK[$rarity] * 100000 + $enh * 1000 + $lvl];
    }
    return [$stage, $item];
}

function now_ms(): int { return (int)floor(microtime(true) * 1000); }

function submit_score(): array {
    $who = identify();
    $pdo = db();
    if ($who['developer']) {
        // 개발자 계정은 저장하지 않음 (예전에 저장된 게 있으면 지움)
        $pdo->prepare('DELETE FROM players WHERE pkey = ?')->execute([$who['key']]);
        return ['ok' => true, 'ranked' => false, 'reason' => 'developer'];
    }
    $raw = file_get_contents('php://input');
    if (strlen((string)$raw) > 4096) throw new HttpError(413, 'too_large');
    $body = json_decode((string)$raw, true);
    if (!is_array($body)) throw new HttpError(400, 'bad_json');
    [$stage, $item] = read_score($body);
    $now = now_ms();

    // 최고 기록만 남김: 층은 더 높을 때, 아이템은 점수가 더 높을 때만 바꿈
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM players WHERE pkey = ?');
        $st->execute([$who['key']]);
        $old = $st->fetch();
        if (!$old) {
            $pdo->prepare('INSERT INTO players (pkey, kind, display, email_hmac, best_stage, stage_at, item_score, item_rarity, item_enh, item_lvl, item_name, item_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $who['key'], $who['kind'], $who['display'], $who['email_hmac'], $stage, $now,
                $item ? $item['score'] : -1, $item['rarity'] ?? null, $item['enh'] ?? null, $item['lvl'] ?? null, $item['name'] ?? null, $item ? $now : null, $now,
            ]);
        } else {
            $betterStage = $stage > (int)$old['best_stage'];
            $betterItem = $item && $item['score'] > (int)$old['item_score'];
            $pdo->prepare('UPDATE players SET display = ?, email_hmac = ?, updated_at = ?,
                best_stage = ?, stage_at = ?,
                item_score = ?, item_rarity = ?, item_enh = ?, item_lvl = ?, item_name = ?, item_at = ?
                WHERE pkey = ?')->execute([
                $who['display'], $who['email_hmac'], $now,
                $betterStage ? $stage : (int)$old['best_stage'], $betterStage ? $now : $old['stage_at'],
                $betterItem ? $item['score'] : (int)$old['item_score'],
                $betterItem ? $item['rarity'] : $old['item_rarity'], $betterItem ? $item['enh'] : $old['item_enh'],
                $betterItem ? $item['lvl'] : $old['item_lvl'], $betterItem ? $item['name'] : $old['item_name'],
                $betterItem ? $now : $old['item_at'],
                $who['key'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
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
    $rows = db()->query("SELECT display, kind, best_stage, item_score, item_rarity, item_enh, item_lvl, item_name FROM players WHERE {$b['where']} ORDER BY {$b['order']} LIMIT {$limit}")->fetchAll();
    $total = (int)db()->query("SELECT COUNT(*) AS n FROM players WHERE {$b['where']}")->fetch()['n'];
    $out = [];
    foreach ($rows as $i => $r) $out[] = row_out($r, $i + 1);
    return ['type' => $type, 'total' => $total, 'updatedAt' => now_ms(), 'rows' => $out];
}

function get_me(): array {
    $who = identify();
    if ($who['developer']) return ['developer' => true];
    $st = db()->prepare('SELECT * FROM players WHERE pkey = ?');
    $st->execute([$who['key']]);
    $r = $st->fetch();
    if (!$r) return ['name' => $who['display'], 'stageRank' => null, 'itemRank' => null];
    $ahead = function (string $sql, array $args): int {
        $q = db()->prepare($sql);
        $q->execute($args);
        return (int)$q->fetch()['n'];
    };
    $stageRank = (int)$r['best_stage'] > 0
        ? 1 + $ahead('SELECT COUNT(*) AS n FROM players WHERE best_stage > ? OR (best_stage = ? AND stage_at < ?)', [$r['best_stage'], $r['best_stage'], $r['stage_at']])
        : null;
    $itemRank = (int)$r['item_score'] >= 0
        ? 1 + $ahead('SELECT COUNT(*) AS n FROM players WHERE item_score > ? OR (item_score = ? AND item_at < ?)', [$r['item_score'], $r['item_score'], $r['item_at']])
        : null;
    return array_merge(row_out($r, null), ['stageRank' => $stageRank, 'itemRank' => $itemRank]);
}

// ---------------------------------------------------------------- 시작

try {
    cors_headers();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') { http_response_code(204); exit; }
    $route = $_GET['r'] ?? '';
    if ($route === '' && !empty($_SERVER['PATH_INFO'])) $route = preg_replace('#^/?(api/)?#', '', (string)$_SERVER['PATH_INFO']);
    if ($route === 'score' && $method === 'POST') respond(200, submit_score());
    elseif ($route === 'ranking' && $method === 'GET') respond(200, get_ranking());
    elseif ($route === 'me' && $method === 'GET') respond(200, get_me());
    else respond(404, ['error' => 'not_found']);
} catch (HttpError $e) {
    respond($e->status, ['error' => $e->codeName]);
} catch (Throwable $e) {
    error_log('[ranking] ' . $e->getMessage());
    respond(500, ['error' => 'server_error']);
}
