<?php
// 운영자 선물: 이메일로 찾은 사용자의 우편함에 골드·캐시를 넣음. 서버에서 명령으로만 실행 (웹으로는 열리지 않음)
//
//   sudo -u www-data php gift.php <이메일> <골드> <캐시> [메모]
//   예) sudo -u www-data php gift.php tester@example.com 99999 99999 "테스터 지급"
//
//   sudo -u www-data php gift.php --resume <이메일>
//     "받기" 도중 서버 오류로 멈춘 우편을 다시 받을 수 있게 함. 캐시가 든 우편은 "캐시는 이미 받았다"로 표시해서
//     다음 "받기"에서는 캐시를 다시 지급하지 않고 골드·아이템만 줌 → 그 사람이 캐시를 받은 걸 확인한 뒤에만 쓰세요
//
// - 이메일 원본은 저장하지 않음. players 표의 email_hmac(같은 hash_key로 만든 값)으로 사람을 찾음
//   → 그 이메일로 로그인해서 한 번이라도 플레이한(랭킹에 기록이 올라간) 사용자만 찾을 수 있음
// - 받는 사람이 우편함에서 "받기"를 누르면 골드는 지금 판에 더해지고, 캐시는 서버가 Xsolla로 지급함 (config.php의 xsolla_api_key 필요)
// - 선물 기록은 mail 표에 kind = 'gift'로 남음

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('RANKING_NO_ROUTE', true);
require __DIR__ . '/api.php';

function fail(string $msg): void { fwrite(STDERR, $msg . "\n"); exit(1); }

// 이메일 → 랭킹 기록의 사람들 (이메일 HMAC으로 찾음)
function players_by_email(string $email): array {
    $st = db()->prepare('SELECT pkey, display FROM players WHERE email_hmac = ?');
    $st->execute([hmac_hex('e:' . strtolower(trim($email)))]);
    $rows = $st->fetchAll();
    if (!$rows) fail('이 이메일로 플레이한 기록이 없어요. 그 계정으로 로그인해서 한 번 플레이한 뒤(랭킹에 기록이 올라간 뒤) 다시 실행하세요. 개발자 계정은 랭킹에 저장되지 않아서 찾을 수 없어요.');
    return $rows;
}

if (($argv[1] ?? '') === '--resume') {
    $email = $argv[2] ?? '';
    if (!preg_match('/^[^@\s]+@[^@\s]+$/', $email)) fail('사용법: php gift.php --resume <이메일>');
    $fixed = 0;
    foreach (players_by_email($email) as $r) {
        $st = db()->prepare("SELECT * FROM mail WHERE owner_key = ? AND state = 'claiming' AND granted_at IS NULL");
        $st->execute([$r['pkey']]);
        foreach ($st->fetchAll() as $m) {
            if ((int)$m['cash'] > 0) {
                db()->prepare('UPDATE mail SET granted_at = ? WHERE id = ?')->execute([now_ms(), $m['id']]);
                echo "{$r['display']}: 우편 {$m['id']} ({$m['kind']}) → 캐시 {$m['cash']}은 이미 받은 것으로 표시. 다음 \"받기\"에서 골드 {$m['gold']}만 들어가요\n";
            } else {
                db()->prepare("UPDATE mail SET state = 'new' WHERE id = ?")->execute([$m['id']]);
                echo "{$r['display']}: 우편 {$m['id']} ({$m['kind']}) → 다시 받을 수 있게 했어요\n";
            }
            $fixed++;
        }
    }
    echo $fixed ? "로비 우편함에서 다시 \"받기\"를 누르면 돼요.\n" : "멈춘 우편이 없어요.\n";
    exit(0);
}

[$self, $email, $gold, $cash] = array_pad($argv, 4, null);
$note = $argv[4] ?? '운영자 선물';
if (!$email || !preg_match('/^[^@\s]+@[^@\s]+$/', $email) || !ctype_digit((string)$gold) || !ctype_digit((string)$cash)) {
    fail("사용법: php gift.php <이메일> <골드> <캐시> [메모]");
}
$gold = (int)$gold;
$cash = (int)$cash;
if ($gold + $cash <= 0 || $gold > MARKET_MAX_GOLD || $cash > MARKET_MAX_CASH) fail('골드·캐시는 0 이상, 하나는 1 이상이어야 해요 (최대 골드 ' . MARKET_MAX_GOLD . ', 캐시 ' . MARKET_MAX_CASH . ')');

foreach (players_by_email($email) as $r) {
    $id = add_mail($r['pkey'], 'gift', ['gold' => $gold, 'cash' => $cash, 'note' => mb_substr($note, 0, 100)], now_ms());
    echo "{$r['display']} 우편함에 선물을 넣었어요: 골드 {$gold}, 캐시 {$cash} (우편 {$id})\n";
}
echo "받는 사람이 로비의 우편함에서 \"받기\"를 누르면 들어가요.\n";
