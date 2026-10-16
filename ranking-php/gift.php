<?php
// 운영자 선물: 이메일로 찾은 사용자의 우편함에 골드·캐시를 넣음. 서버에서 명령으로만 실행 (웹으로는 열리지 않음)
//
//   sudo -u www-data php gift.php <이메일> <골드> <캐시> [메모]
//   예) sudo -u www-data php gift.php tester@example.com 99999 99999 "테스터 지급"
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

[$self, $email, $gold, $cash] = array_pad($argv, 4, null);
$note = $argv[4] ?? '운영자 선물';
if (!$email || !preg_match('/^[^@\s]+@[^@\s]+$/', $email) || !ctype_digit((string)$gold) || !ctype_digit((string)$cash)) {
    fail("사용법: php gift.php <이메일> <골드> <캐시> [메모]");
}
$gold = (int)$gold;
$cash = (int)$cash;
if ($gold + $cash <= 0 || $gold > MARKET_MAX_GOLD || $cash > MARKET_MAX_CASH) fail('골드·캐시는 0 이상, 하나는 1 이상이어야 해요 (최대 골드 ' . MARKET_MAX_GOLD . ', 캐시 ' . MARKET_MAX_CASH . ')');

$st = db()->prepare('SELECT pkey, display FROM players WHERE email_hmac = ?');
$st->execute([hmac_hex('e:' . strtolower(trim($email)))]);
$rows = $st->fetchAll();
if (!$rows) fail('이 이메일로 플레이한 기록이 없어요. 그 계정으로 로그인해서 한 번 플레이한 뒤(랭킹에 기록이 올라간 뒤) 다시 실행하세요. 개발자 계정은 랭킹에 저장되지 않아서 찾을 수 없어요.');

foreach ($rows as $r) {
    $id = add_mail($r['pkey'], 'gift', ['gold' => $gold, 'cash' => $cash, 'note' => mb_substr($note, 0, 100)], now_ms());
    echo "{$r['display']} 우편함에 선물을 넣었어요: 골드 {$gold}, 캐시 {$cash} (우편 {$id})\n";
}
echo "받는 사람이 로비의 우편함에서 \"받기\"를 누르면 들어가요.\n";
