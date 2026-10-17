<?php
// 하루 입장 횟수와 광고 보상. api.php가 불러서 씀.
//
//   GET  api.php?r=entry_status          오늘 무료 입장·광고 보상이 몇 번 남았는지
//   POST api.php?r=entry       { adTicket? }      1층 문으로 새로 시작할 때. 하루 무료 FREE_ENTRIES회, 그 뒤엔 광고 티켓 필요
//   POST api.php?r=ad_start    { kind }           광고를 보기 전에 티켓 받기 (kind: entry | revive | fee). 하루 상한 확인
//   POST api.php?r=revive_ad   { adTicket }       광고 보고 부활 (하루 AD_CAPS['revive']회)
//   거래소 수수료 면제는 market_buy { id, adTicket }
//
// 광고를 끝까지 봤다는 신호는 브라우저에서 와서 서버가 확인할 수 없음. 그래서
//   - 광고를 시작할 때 서버가 티켓을 주고, 최소 ad_min_seconds초가 지나야 쓸 수 있게 하고
//   - 티켓은 한 번만, 받은 날(한국 시간)에만 쓸 수 있고
//   - 종류마다 하루 상한을 둠
// 하루는 한국 시간 자정에 바뀜. 개발자 계정은 입장 횟수 제한이 없음 (광고 상한은 같음)

const FREE_ENTRIES = 5;
const AD_CAPS = ['entry' => 30, 'revive' => 1, 'fee' => 3];
const AD_TICKET_TTL_MS = 30 * 60 * 1000;

function ads_tables(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS daily_entries (
        pkey   VARCHAR(64) NOT NULL,
        day    VARCHAR(10) NOT NULL,
        used   INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (pkey, day)
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ad_tickets (
        id          VARCHAR(32) PRIMARY KEY,
        pkey        VARCHAR(64) NOT NULL,
        kind        VARCHAR(12) NOT NULL,
        day         VARCHAR(10) NOT NULL,
        started_at  BIGINT NOT NULL,
        used_at     BIGINT
    )');
    try { $pdo->exec('CREATE INDEX idx_ad_tickets_owner ON ad_tickets (pkey, day, kind)'); } catch (PDOException $e) { /* 이미 있음 */ }
}

function today_kst(): string { return (new DateTime('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d'); }
function ad_min_seconds(): int { return max(0, (int)(config()['ad_min_seconds'] ?? 5)); }

function entries_used(string $key, string $day): int {
    return (int)(q_val('SELECT used FROM daily_entries WHERE pkey = ? AND day = ?', [$key, $day]) ?: 0);
}
function ads_used(string $key, string $day, string $kind): int {
    return (int)q_val('SELECT COUNT(*) FROM ad_tickets WHERE pkey = ? AND day = ? AND kind = ? AND used_at IS NOT NULL', [$key, $day, $kind]);
}

function entry_status_for(array $who): array {
    $day = today_kst();
    $used = entries_used($who['key'], $day);
    $ads = [];
    foreach (AD_CAPS as $kind => $cap) $ads[$kind] = max(0, $cap - ads_used($who['key'], $day, $kind));
    return [
        'day' => $day, 'free' => FREE_ENTRIES, 'used' => $used,
        'left' => $who['developer'] ? FREE_ENTRIES : max(0, FREE_ENTRIES - $used),
        'unlimited' => (bool)$who['developer'],
        'ads' => $ads, 'adCaps' => AD_CAPS, 'adMinSeconds' => ad_min_seconds(),
    ];
}

function entry_status(): array { return entry_status_for(identify()); }

function ad_start(): array {
    $who = identify();
    $kind = (string)(read_body(256)['kind'] ?? '');
    if (!isset(AD_CAPS[$kind])) throw new HttpError(400, 'bad_kind');
    $day = today_kst();
    $now = now_ms();
    if (ads_used($who['key'], $day, $kind) >= AD_CAPS[$kind]) throw new HttpError(429, 'ad_limit');
    // 티켓만 잔뜩 받아 두지 못하게: 아직 안 쓴 최근 티켓은 5장까지
    $open = (int)q_val('SELECT COUNT(*) FROM ad_tickets WHERE pkey = ? AND used_at IS NULL AND started_at > ?', [$who['key'], $now - AD_TICKET_TTL_MS]);
    if ($open >= 5) throw new HttpError(429, 'too_many_tickets');
    db()->prepare('DELETE FROM ad_tickets WHERE started_at < ?')->execute([$now - 3 * 86400000]);
    $id = new_id();
    db()->prepare('INSERT INTO ad_tickets (id, pkey, kind, day, started_at) VALUES (?, ?, ?, ?, ?)')->execute([$id, $who['key'], $kind, $day, $now]);
    return ['ticket' => $id, 'minSeconds' => ad_min_seconds(), 'left' => AD_CAPS[$kind] - ads_used($who['key'], $day, $kind)];
}

// 광고 티켓 확인: 내 것, 같은 종류, 오늘 받은 것, 최소 시간이 지난 것, 아직 안 쓴 것
function check_ticket(array $who, $ticket, string $kind): array {
    $t = is_string($ticket) ? q_row('SELECT * FROM ad_tickets WHERE id = ?', [$ticket]) : null;
    $now = now_ms();
    if (!$t || $t['pkey'] !== $who['key'] || $t['kind'] !== $kind || $t['used_at'] !== null
        || $t['day'] !== today_kst() || (int)$t['started_at'] < $now - AD_TICKET_TTL_MS) throw new HttpError(403, 'bad_ticket');
    if ($now - (int)$t['started_at'] < ad_min_seconds() * 1000) throw new HttpError(403, 'ad_too_short');
    if (ads_used($who['key'], $t['day'], $kind) >= AD_CAPS[$kind]) throw new HttpError(429, 'ad_limit');
    return $t;
}
// 티켓 쓰기 (한 번만)
function use_ticket(string $id): void {
    $up = db()->prepare('UPDATE ad_tickets SET used_at = ? WHERE id = ? AND used_at IS NULL');
    $up->execute([now_ms(), $id]);
    if ($up->rowCount() !== 1) throw new HttpError(403, 'bad_ticket');
}

// 1층 문으로 새로 시작: 무료 횟수가 남았으면 쓰고, 아니면 광고 티켓
function entry_start(): array {
    $who = identify();
    $body = read_body(256);
    $day = today_kst();
    if ($who['developer']) return array_merge(entry_status_for($who), ['ok' => true, 'by' => 'developer']);
    $by = tx(function (PDO $pdo) use ($who, $body, $day) {
        $used = entries_used($who['key'], $day);
        if ($used < FREE_ENTRIES) {
            $up = $pdo->prepare('UPDATE daily_entries SET used = used + 1 WHERE pkey = ? AND day = ?');
            $up->execute([$who['key'], $day]);
            if ($up->rowCount() === 0) $pdo->prepare('INSERT INTO daily_entries (pkey, day, used) VALUES (?, ?, 1)')->execute([$who['key'], $day]);
            return 'free';
        }
        if (empty($body['adTicket'])) throw new HttpError(403, 'need_ad');
        $t = check_ticket($who, $body['adTicket'], 'entry');
        use_ticket($t['id']);
        return 'ad';
    });
    return array_merge(entry_status_for($who), ['ok' => true, 'by' => $by]);
}

function revive_ad(): array {
    $who = identify();
    $body = read_body(256);
    $t = check_ticket($who, $body['adTicket'] ?? null, 'revive');
    use_ticket($t['id']);
    return array_merge(entry_status_for($who), ['ok' => true]);
}
