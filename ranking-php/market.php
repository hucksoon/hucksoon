<?php
// 거래소와 우편함. api.php가 불러서 씀.
//
//   GET  api.php?r=market       &slot=&rarity=&sort=new|cash|gold   지금 팔고 있는 아이템
//   POST api.php?r=market_list  { item: {seed, src, lvl, v, enh, id, tries}, gold, cash, stage }  아이템 등록
//   POST api.php?r=market_cancel { id }   내 등록 취소 → 우편함으로
//   POST api.php?r=market_buy   { id }    구매: 캐시(가격 + 수수료)를 Xsolla에서 차감, 아이템은 내 우편함으로
//   GET  api.php?r=market_mine            내 등록, 지난 거래, 우편함
//   POST api.php?r=mail_claim   { id }    우편 받기: 아이템 참조·골드를 돌려주고, 캐시는 Xsolla로 지급
//
// 원칙
//   - 거래(등록·구매·우편)는 로그인 사용자만. 모든 거래에 캐시 수수료(MARKET_FEE_CASH)가 있어서 Xsolla 계정이 필요함
//   - 수수료는 구매자가 가격에 더해 냄. 판매자는 정한 가격을 그대로 받음 (우편함으로)
//   - 레전더리·세트 아이템은 캐시 가격이 1 이상이어야 함
//   - 아이템은 서버가 rules.php로 다시 만들어 검증. 아이템 ID별 주인(item_owner)을 기록해서
//     남에게 넘어간 아이템(복사본)은 다시 등록할 수 없음
//   - 등록 후 MARKET_HOURS 시간이 지나면 판매자 우편함으로 돌아감
//   - 등록·구매·가격·시간은 전부 listings 표에, 아이템·골드·캐시 전달은 mail 표에 남음
//   - 저장하는 사람 정보는 내부 키(HMAC)와 가린 이름뿐. Xsolla 사용자 ID는 요청한 토큰에서 그때그때 읽고 저장하지 않음
//
// 골드는 게임(브라우저)에 저장되는 값이라 서버가 잔액을 확인할 수 없음. 캐시는 Xsolla가 확인함.

const MARKET_FEE_CASH = 1;
const MARKET_HOURS = 24;
const MARKET_MAX_ACTIVE = 10;
const MARKET_MAX_GOLD = 10000000;
const MARKET_MAX_CASH = 100000;
const MARKET_CASH_REQUIRED = ['legendary', 'set'];

function market_tables(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS listings (
        id             VARCHAR(32) PRIMARY KEY,
        item_id        VARCHAR(32) NOT NULL,
        item_ref       TEXT NOT NULL,
        item_view      TEXT NOT NULL,
        item_name      VARCHAR(64) NOT NULL,
        rarity         VARCHAR(16) NOT NULL,
        slot           VARCHAR(16) NOT NULL,
        seller_key     VARCHAR(64) NOT NULL,
        seller_display VARCHAR(80) NOT NULL,
        price_gold     INTEGER NOT NULL,
        price_cash     INTEGER NOT NULL,
        fee_cash       INTEGER NOT NULL,
        state          VARCHAR(12) NOT NULL,
        created_at     BIGINT NOT NULL,
        expires_at     BIGINT NOT NULL,
        ended_at       BIGINT,
        buyer_key      VARCHAR(64),
        buyer_display  VARCHAR(80),
        buying_at      BIGINT,
        sold_at        BIGINT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS mail (
        id          VARCHAR(32) PRIMARY KEY,
        owner_key   VARCHAR(64) NOT NULL,
        kind        VARCHAR(12) NOT NULL,
        listing_id  VARCHAR(32),
        item_id     VARCHAR(32),
        item_ref    TEXT,
        item_name   VARCHAR(64),
        rarity      VARCHAR(16),
        gold        INTEGER NOT NULL DEFAULT 0,
        cash        INTEGER NOT NULL DEFAULT 0,
        note        VARCHAR(120),
        state       VARCHAR(12) NOT NULL,
        created_at  BIGINT NOT NULL,
        claimed_at  BIGINT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS item_owner (
        item_id     VARCHAR(32) PRIMARY KEY,
        owner_key   VARCHAR(64) NOT NULL,
        updated_at  BIGINT NOT NULL
    )');
    foreach ([
        'CREATE INDEX idx_listings_state ON listings (state, expires_at)',
        'CREATE INDEX idx_listings_seller ON listings (seller_key, state)',
        'CREATE INDEX idx_listings_item ON listings (item_id, state)',
        'CREATE INDEX idx_mail_owner ON mail (owner_key, state)',
        'CREATE INDEX idx_mail_item ON mail (item_id, state)',
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* 이미 있음 */ }
    }
}

function new_id(): string { return bin2hex(random_bytes(8)); }

function read_body(int $max): array {
    $raw = file_get_contents('php://input');
    if (strlen((string)$raw) > $max) throw new HttpError(413, 'too_large');
    $body = json_decode((string)$raw, true);
    if (!is_array($body)) throw new HttpError(400, 'bad_json');
    return $body;
}

function require_user(): array {
    $who = identify();
    if ($who['kind'] !== 'user') throw new HttpError(403, 'login_required');
    return $who;
}

// 다른 사람이 주인으로 기록된 아이템인지 (남에게 판 아이템의 복사본)
function owned_by_other(string $itemId, string $key): bool {
    $st = db()->prepare('SELECT owner_key FROM item_owner WHERE item_id = ?');
    $st->execute([$itemId]);
    $owner = $st->fetchColumn();
    return $owner !== false && $owner !== $key;
}

function set_owner(string $itemId, string $key, int $now): void {
    $up = db()->prepare('UPDATE item_owner SET owner_key = ?, updated_at = ? WHERE item_id = ?');
    $up->execute([$key, $now, $itemId]);
    if ($up->rowCount() === 0) {
        db()->prepare('INSERT INTO item_owner (item_id, owner_key, updated_at) VALUES (?, ?, ?)')->execute([$itemId, $key, $now]);
    }
}

function add_mail(string $owner, string $kind, array $f, int $now): string {
    $id = new_id();
    db()->prepare('INSERT INTO mail (id, owner_key, kind, listing_id, item_id, item_ref, item_name, rarity, gold, cash, note, state, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $id, $owner, $kind, $f['listing_id'] ?? null, $f['item_id'] ?? null, $f['item_ref'] ?? null, $f['item_name'] ?? null, $f['rarity'] ?? null,
        $f['gold'] ?? 0, $f['cash'] ?? 0, $f['note'] ?? null, 'new', $now,
    ]);
    return $id;
}

// 등록 시간이 지난 아이템은 판매자 우편함으로
function market_expire(): void {
    $pdo = db();
    $now = now_ms();
    $st = $pdo->prepare("SELECT * FROM listings WHERE state = 'active' AND expires_at <= ? LIMIT 200");
    $st->execute([$now]);
    foreach ($st->fetchAll() as $l) {
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare("UPDATE listings SET state = 'expired', ended_at = ? WHERE id = ? AND state = 'active'");
            $up->execute([$now, $l['id']]);
            if ($up->rowCount() === 1) {
                add_mail($l['seller_key'], 'returned', [
                    'listing_id' => $l['id'], 'item_id' => $l['item_id'], 'item_ref' => $l['item_ref'], 'item_name' => $l['item_name'],
                    'rarity' => $l['rarity'], 'note' => MARKET_HOURS . '시간이 지나 돌아온 아이템',
                ], $now);
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }
}

function listing_out(array $l, ?string $me): array {
    return [
        'id' => $l['id'],
        'item' => json_decode($l['item_view'], true),
        'ref' => json_decode($l['item_ref'], true),
        'seller' => $l['seller_display'],
        'mine' => $me !== null && $l['seller_key'] === $me,
        'gold' => (int)$l['price_gold'],
        'cash' => (int)$l['price_cash'],
        'fee' => (int)$l['fee_cash'],
        'state' => $l['state'],
        'createdAt' => (int)$l['created_at'],
        'expiresAt' => (int)$l['expires_at'],
        'endedAt' => $l['ended_at'] !== null ? (int)$l['ended_at'] : ($l['sold_at'] !== null ? (int)$l['sold_at'] : null),
        'buyer' => $l['buyer_display'],
    ];
}

function mail_out(array $m): array {
    return [
        'id' => $m['id'], 'kind' => $m['kind'], 'itemName' => $m['item_name'], 'rarity' => $m['rarity'],
        'ref' => $m['item_ref'] ? json_decode($m['item_ref'], true) : null,
        'gold' => (int)$m['gold'], 'cash' => (int)$m['cash'], 'note' => $m['note'], 'createdAt' => (int)$m['created_at'],
    ];
}

function market_settings(): array {
    return ['fee' => MARKET_FEE_CASH, 'hours' => MARKET_HOURS, 'maxActive' => MARKET_MAX_ACTIVE, 'cashRequired' => MARKET_CASH_REQUIRED];
}

// 누구나 볼 수 있음 (로그인하지 않았으면 내 것 표시만 없음)
function market_browse(): array {
    market_expire();
    $me = null;
    try { $me = identify()['key']; } catch (HttpError $e) { /* 손님 */ }
    $where = ["state = 'active'"];
    $args = [];
    if (in_array($_GET['slot'] ?? '', R_SLOTS, true)) { $where[] = 'slot = ?'; $args[] = $_GET['slot']; }
    if (isset(RARITY_RANK[$_GET['rarity'] ?? ''])) { $where[] = 'rarity = ?'; $args[] = $_GET['rarity']; }
    $order = ['cash' => 'price_cash ASC, price_gold ASC', 'gold' => 'price_gold ASC, price_cash ASC'][$_GET['sort'] ?? ''] ?? 'created_at DESC';
    $st = db()->prepare('SELECT * FROM listings WHERE ' . implode(' AND ', $where) . " ORDER BY {$order} LIMIT 100");
    $st->execute($args);
    $total = (int)db()->query("SELECT COUNT(*) FROM listings WHERE state = 'active'")->fetchColumn();
    return array_merge(market_settings(), [
        'now' => now_ms(), 'total' => $total,
        'rows' => array_map(function ($l) use ($me) { return listing_out($l, $me); }, $st->fetchAll()),
    ]);
}

function market_list(): array {
    $who = require_user();
    $body = read_body(4096);
    $gold = $body['gold'] ?? null;
    $cash = $body['cash'] ?? null;
    if (!is_int($gold) || !is_int($cash) || $gold < 0 || $cash < 0 || $gold > MARKET_MAX_GOLD || $cash > MARKET_MAX_CASH || $gold + $cash <= 0) {
        throw new HttpError(400, 'bad_price');
    }
    $ref = $body['item'] ?? null;
    if (!is_array($ref) || ($ref['v'] ?? null) !== 2) throw new HttpError(400, 'old_item');
    // 아이템 레벨은 최고 층보다 높을 수 없음 (랭킹과 같은 기준). 개발자는 랭킹 기록이 없어서 제한 없음
    $st = db()->prepare('SELECT best_stage FROM players WHERE pkey = ?');
    $st->execute([$who['key']]);
    $stage = is_int($body['stage'] ?? null) ? min(MAX_STAGE, max(0, $body['stage'])) : 0;
    $maxLvl = $who['developer'] ? MAX_STAGE : max((int)($st->fetchColumn() ?: 0), $stage);
    $item = verify_item($ref, $maxLvl);
    if (!$item) throw new HttpError(400, 'bad_item');
    if (in_array($item['rarity'], MARKET_CASH_REQUIRED, true) && $cash < 1) throw new HttpError(400, 'cash_required');
    $tries = is_int($ref['tries'] ?? null) ? max(0, min(1000, $ref['tries'])) : 0;
    $cleanRef = ['seed' => $item['seed'], 'src' => $item['src'], 'lvl' => $item['lvl'], 'v' => 2, 'enh' => $item['enh'], 'id' => $item['id'], 'tries' => $tries];

    $pdo = db();
    $now = now_ms();
    $pdo->beginTransaction();
    try {
        $count = function (string $sql, array $args) use ($pdo): int { $q = $pdo->prepare($sql); $q->execute($args); return (int)$q->fetchColumn(); };
        if ($count("SELECT COUNT(*) FROM listings WHERE seller_key = ? AND state IN ('active', 'buying')", [$who['key']]) >= MARKET_MAX_ACTIVE) throw new HttpError(409, 'too_many');
        if ($count("SELECT COUNT(*) FROM listings WHERE item_id = ? AND state IN ('active', 'buying')", [$item['id']]) > 0) throw new HttpError(409, 'already_listed');
        if ($count("SELECT COUNT(*) FROM mail WHERE item_id = ? AND state <> 'claimed'", [$item['id']]) > 0) throw new HttpError(409, 'in_mail');
        if (owned_by_other($item['id'], $who['key'])) throw new HttpError(403, 'not_owner');
        set_owner($item['id'], $who['key'], $now);
        $id = new_id();
        $pdo->prepare('INSERT INTO listings (id, item_id, item_ref, item_view, item_name, rarity, slot, seller_key, seller_display,
            price_gold, price_cash, fee_cash, state, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $id, $item['id'], json_encode($cleanRef), json_encode(item_view($item), JSON_UNESCAPED_UNICODE), r_item_name($item), $item['rarity'], $item['slot'],
            $who['key'], $who['display'], $gold, $cash, MARKET_FEE_CASH, 'active', $now, $now + MARKET_HOURS * 3600 * 1000,
        ]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    $st = $pdo->prepare('SELECT * FROM listings WHERE id = ?');
    $st->execute([$id]);
    return ['ok' => true, 'listing' => listing_out($st->fetch(), $who['key'])];
}

function market_cancel(): array {
    $who = require_user();
    $id = (string)(read_body(512)['id'] ?? '');
    $pdo = db();
    $now = now_ms();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM listings WHERE id = ? AND seller_key = ?');
        $st->execute([$id, $who['key']]);
        $l = $st->fetch();
        $up = $pdo->prepare("UPDATE listings SET state = 'cancelled', ended_at = ? WHERE id = ? AND seller_key = ? AND state = 'active'");
        $up->execute([$now, $id, $who['key']]);
        if (!$l || $up->rowCount() !== 1) throw new HttpError(409, 'gone');
        $mail = add_mail($who['key'], 'returned', [
            'listing_id' => $id, 'item_id' => $l['item_id'], 'item_ref' => $l['item_ref'], 'item_name' => $l['item_name'],
            'rarity' => $l['rarity'], 'note' => '등록을 취소한 아이템',
        ], $now);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return ['ok' => true, 'mail' => $mail];
}

// ---- Xsolla Store (캐시) ----

// 게임의 Xsolla Store 프로젝트 (공개 값. config.php에서 바꿀 수 있음)
const DEFAULT_STORE_PROJECT = 317991;

function store_project(): string { return (string)(config()['xsolla_project_id'] ?? DEFAULT_STORE_PROJECT); }

function store_url(string $path): string {
    $cfg = config();
    $pid = store_project();
    return rtrim((string)($cfg['xsolla_store_url'] ?? 'https://store.xsolla.com/api'), '/') . "/v2/project/{$pid}{$path}";
}
function cash_sku(): string { return (string)(config()['cash_sku'] ?? 'cash'); }

// 구매자 토큰으로: 캐시 잔액
function cash_balance(string $token): ?int {
    [$status, $text] = http_request('GET', store_url('/user/virtual_currency_balance'), null, ["Authorization: Bearer {$token}"]);
    if ($status !== 200) return null;
    foreach ((json_decode($text, true)['items'] ?? []) as $vc) if (($vc['sku'] ?? '') === cash_sku()) return (int)$vc['amount'];
    return 0;
}

// 구매자 토큰으로: 캐시 차감 (Xsolla 인벤토리의 가상 화폐 사용)
function cash_consume(string $token, int $amount): array {
    $body = json_encode(['sku' => cash_sku(), 'quantity' => $amount, 'instance_id' => null]);
    return http_request('POST', store_url('/user/inventory/item/consume'), $body, ["Authorization: Bearer {$token}"]);
}

// 프로젝트 API 키로: 판매 대금 캐시 지급
function cash_grant(string $userId, int $amount, string $comment): array {
    $cfg = config();
    $key = (string)($cfg['xsolla_api_key'] ?? '');
    if ($key === '') throw new HttpError(503, 'market_not_configured');
    $auth = base64_encode(store_project() . ':' . $key);
    $body = json_encode([['user' => ['id' => $userId], 'platform' => 'xsolla', 'items' => [['sku' => cash_sku(), 'quantity' => $amount]], 'comment' => $comment]]);
    return http_request('POST', store_url('/inventory/reward'), $body, ["Authorization: Basic {$auth}"]);
}

function market_buy(): array {
    $who = require_user();
    $id = (string)(read_body(512)['id'] ?? '');
    market_expire();
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM listings WHERE id = ?');
    $st->execute([$id]);
    $l = $st->fetch();
    if (!$l || $l['state'] !== 'active') throw new HttpError(409, 'gone');
    if ($l['seller_key'] === $who['key']) throw new HttpError(400, 'own');
    $total = (int)$l['price_cash'] + (int)$l['fee_cash'];

    // 다른 사람이 동시에 사지 못하게 먼저 잡아 둠
    $now = now_ms();
    $hold = $pdo->prepare("UPDATE listings SET state = 'buying', buyer_key = ?, buyer_display = ?, buying_at = ? WHERE id = ? AND state = 'active' AND expires_at > ?");
    $hold->execute([$who['key'], $who['display'], $now, $id, $now]);
    if ($hold->rowCount() !== 1) throw new HttpError(409, 'gone');
    $release = function () use ($pdo, $id) {
        $pdo->prepare("UPDATE listings SET state = 'active', buyer_key = NULL, buyer_display = NULL, buying_at = NULL WHERE id = ? AND state = 'buying'")->execute([$id]);
    };
    try {
        $balance = cash_balance($who['token']);
        if ($balance === null) throw new HttpError(502, 'payment_failed');
        if ($balance < $total) throw new HttpError(402, 'not_enough_cash');
        [$status, $text] = cash_consume($who['token'], $total);
        if ($status < 200 || $status >= 300) {
            error_log("[market] consume {$total} failed for listing {$id}: {$status} " . substr($text, 0, 200));
            throw new HttpError($status === 422 ? 402 : 502, $status === 422 ? 'not_enough_cash' : 'payment_failed');
        }
    } catch (Throwable $e) { $release(); throw $e; }

    // 캐시가 빠져나간 뒤: 거래 완료 기록, 구매자에게 아이템, 판매자에게 대금
    $now = now_ms();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE listings SET state = 'sold', sold_at = ?, ended_at = ? WHERE id = ?")->execute([$now, $now, $id]);
        $mail = add_mail($who['key'], 'bought', [
            'listing_id' => $id, 'item_id' => $l['item_id'], 'item_ref' => $l['item_ref'], 'item_name' => $l['item_name'], 'rarity' => $l['rarity'],
            'note' => "{$l['seller_display']}에게서 산 아이템",
        ], $now);
        add_mail($l['seller_key'], 'sale', [
            'listing_id' => $id, 'item_name' => $l['item_name'], 'rarity' => $l['rarity'],
            'gold' => (int)$l['price_gold'], 'cash' => (int)$l['price_cash'], 'note' => "{$who['display']}에게 판매",
        ], $now);
        set_owner($l['item_id'], $who['key'], $now);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log("[market] listing {$id} paid ({$total} cash) but not recorded: " . $e->getMessage());
        throw $e;
    }
    return ['ok' => true, 'mail' => $mail, 'gold' => (int)$l['price_gold'], 'cash' => $total];
}

function market_mine(): array {
    $who = require_user();
    market_expire();
    $pdo = db();
    $q = function (string $sql, array $args) use ($pdo): array { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); };
    $active = $q("SELECT * FROM listings WHERE seller_key = ? AND state IN ('active', 'buying') ORDER BY created_at DESC", [$who['key']]);
    $history = $q("SELECT * FROM listings WHERE (seller_key = ? OR buyer_key = ?) AND state IN ('sold', 'expired', 'cancelled') ORDER BY COALESCE(sold_at, ended_at) DESC LIMIT 30", [$who['key'], $who['key']]);
    $mail = $q("SELECT * FROM mail WHERE owner_key = ? AND state <> 'claimed' ORDER BY created_at ASC LIMIT 100", [$who['key']]);
    $hist = array_map(function ($l) use ($who) {
        $o = listing_out($l, $who['key']);
        $o['role'] = $l['seller_key'] === $who['key'] ? 'seller' : 'buyer';
        return $o;
    }, $history);
    return array_merge(market_settings(), [
        'now' => now_ms(),
        'active' => array_map(function ($l) use ($who) { return listing_out($l, $who['key']); }, $active),
        'history' => $hist,
        'mail' => array_map('mail_out', $mail),
    ]);
}

function mail_claim(): array {
    $who = require_user();
    $id = (string)(read_body(512)['id'] ?? '');
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM mail WHERE id = ? AND owner_key = ? AND state = 'new'");
    $st->execute([$id, $who['key']]);
    $m = $st->fetch();
    if (!$m) throw new HttpError(404, 'not_found');
    // 먼저 "받는 중"으로 잡아서 같은 우편을 두 번 받지 못하게
    $hold = $pdo->prepare("UPDATE mail SET state = 'claiming' WHERE id = ? AND state = 'new'");
    $hold->execute([$id]);
    if ($hold->rowCount() !== 1) throw new HttpError(404, 'not_found');
    if ((int)$m['cash'] > 0) {
        try {
            [$status, $text] = cash_grant($who['sub'], (int)$m['cash'], "market sale {$m['listing_id']}");
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE mail SET state = 'new' WHERE id = ?")->execute([$id]);
            throw $e;
        }
        if ($status < 200 || $status >= 300) {
            error_log("[market] grant {$m['cash']} failed for mail {$id}: {$status} " . substr($text, 0, 200));
            $pdo->prepare("UPDATE mail SET state = 'new' WHERE id = ?")->execute([$id]);
            throw new HttpError(502, 'grant_failed');
        }
    }
    $pdo->prepare("UPDATE mail SET state = 'claimed', claimed_at = ? WHERE id = ?")->execute([now_ms(), $id]);
    return ['ok' => true, 'mail' => mail_out($m)];
}

function mail_count(string $key): int {
    $st = db()->prepare("SELECT COUNT(*) FROM mail WHERE owner_key = ? AND state = 'new'");
    $st->execute([$key]);
    return (int)$st->fetchColumn();
}
