<?php
// 계정별 게임 저장 (로그인 사용자만). api.php가 불러서 씀.
//
//   GET  api.php?r=profile                → { rev, data | null, updatedAt }
//   POST api.php?r=profile { rev, data }  → { ok: true, rev } 또는 { ok: false, conflict: true, rev, data } (다른 기기가 먼저 저장함)
//
// data: { v, save: { best, run, dead }, inv: { bag, equip, stones }, dex: { seen, kills }, boxMail: [...] }
//   웹·앱·다른 기기에서 같은 계정이면 같은 가방과 진행 상황을 쓰도록 서버에 보관.
//   rev(저장 번호)가 서버와 같을 때만 덮어씀 → 두 기기가 서로의 저장을 지우지 않게.
// 아이템은 여기서 검증하지 않음 (가져가는 곳인 랭킹·거래소에서 시드로 검증하고, 남에게 넘어간 아이템은 막음).

const PROFILE_MAX_BYTES = 200000;

function profile_tables(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS profiles (
        pkey        VARCHAR(64) PRIMARY KEY,
        rev         INTEGER NOT NULL,
        data        TEXT NOT NULL,
        updated_at  BIGINT NOT NULL
    )');
}

// 저장 내용이 게임이 쓰는 모양인지 (크기·개수만 확인)
function profile_valid($d): bool {
    if (!is_array($d)) return false;
    $inv = $d['inv'] ?? null;
    if (!is_array($inv) || !is_array($inv['bag'] ?? null) || count($inv['bag']) > 60) return false;
    if (!is_array($inv['equip'] ?? null) || count($inv['equip']) > 6) return false;
    if (isset($inv['stones']) && (!is_int($inv['stones']) || $inv['stones'] < 0)) return false;
    if (isset($d['dex']) && !is_array($d['dex'])) return false;
    if (isset($d['save']) && !is_array($d['save'])) return false;
    if (isset($d['boxMail']) && (!is_array($d['boxMail']) || count($d['boxMail']) > 300)) return false;
    return true;
}

function profile_get(): array {
    $who = require_user();
    $r = q_row('SELECT rev, data, updated_at FROM profiles WHERE pkey = ?', [$who['key']]);
    return $r ? ['rev' => (int)$r['rev'], 'data' => json_decode($r['data']), 'updatedAt' => (int)$r['updated_at']]
              : ['rev' => 0, 'data' => null, 'updatedAt' => null];
}

function profile_put(): array {
    $who = require_user();
    $body = read_body(PROFILE_MAX_BYTES);
    $rev = $body['rev'] ?? null;
    $data = $body['data'] ?? null;
    if (!is_int($rev) || $rev < 0 || !profile_valid($data)) throw new HttpError(400, 'bad_profile');
    // 빈 객체({})가 배열([])로 바뀌지 않게 객체 그대로 다시 읽어서 저장
    $json = json_encode(json_decode((string)file_get_contents('php://input'))->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return tx(function (PDO $pdo) use ($who, $rev, $json) {
        $cur = q_row('SELECT rev, data FROM profiles WHERE pkey = ?', [$who['key']]);
        $curRev = $cur ? (int)$cur['rev'] : 0;
        // 다른 기기가 먼저 저장했으면 덮어쓰지 않고 서버 내용을 돌려줌
        if ($rev !== $curRev) return ['ok' => false, 'conflict' => true, 'rev' => $curRev, 'data' => $cur ? json_decode($cur['data']) : null];
        $now = now_ms();
        if ($cur) $pdo->prepare('UPDATE profiles SET rev = ?, data = ?, updated_at = ? WHERE pkey = ?')->execute([$curRev + 1, $json, $now, $who['key']]);
        else $pdo->prepare('INSERT INTO profiles (pkey, rev, data, updated_at) VALUES (?, ?, ?, ?)')->execute([$who['key'], 1, $json, $now]);
        return ['ok' => true, 'rev' => $curRev + 1];
    });
}
