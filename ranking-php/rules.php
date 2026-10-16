<?php
// items.js의 아이템 규칙을 PHP로 옮긴 것. 서버가 아이템을 시드로 다시 만들어 위조를 확인하고 능력치를 계산한다.
// items.js와 한 비트도 다르면 안 됨: items.js를 바꾸면 여기도 같이 바꾸고 일치 테스트를 돌릴 것.
// v1: 기본 능력치가 부위로 고정 / v2: 주 능력치도 무작위, [최소~최대] 범위에서 최대치 20%

declare(strict_types=1);

const R_MASK = 0xFFFFFFFF;
const R_SLOTS = ['head', 'chest', 'arms', 'pants', 'boots', 'weapon'];
const R_WEAPONS = [
    'dagger' => ['name' => '단검', 'base' => ['atk' => 2, 'crit' => 6], 'atkMul' => 1],
    'staff' => ['name' => '지팡이', 'base' => ['atk' => 2, 'hp' => 6], 'atkMul' => 1],
    'axe' => ['name' => '도끼', 'base' => ['atk' => 4], 'atkMul' => 1.3],
];
const R_ARMOR = [
    'head' => ['name' => '투구', 'base' => ['def' => 1, 'hp' => 2]],
    'chest' => ['name' => '갑옷', 'base' => ['def' => 2, 'hp' => 4]],
    'arms' => ['name' => '팔보호구', 'base' => ['def' => 1]],
    'pants' => ['name' => '바지', 'base' => ['def' => 1, 'hp' => 2]],
    'boots' => ['name' => '장화', 'base' => ['def' => 1]],
];
const R_RARITIES = [
    'common' => ['name' => '커먼', 'lines' => 1],
    'uncommon' => ['name' => '언커먼', 'lines' => 2],
    'rare' => ['name' => '레어', 'lines' => 3],
    'epic' => ['name' => '에픽', 'lines' => 4],
    'legendary' => ['name' => '레전더리', 'lines' => 5],
    'set' => ['name' => '세트', 'lines' => 4],
];
const R_TABLES = [
    'box' => ['common' => 40, 'uncommon' => 30, 'rare' => 18, 'epic' => 8, 'legendary' => 3, 'set' => 1],
    'drop' => ['common' => 60, 'uncommon' => 25, 'rare' => 10, 'epic' => 3.5, 'legendary' => 1, 'set' => 0.5],
    'boss' => ['common' => 20, 'uncommon' => 30, 'rare' => 28, 'epic' => 15, 'legendary' => 5, 'set' => 2],
    'golden' => ['rare' => 65, 'epic' => 25, 'legendary' => 7, 'set' => 3],
];
const R_DROP_CHANCE = ['normal' => 0.08, 'elite' => 0.4];
const R_STAT_KEYS = ['atk', 'def', 'hp', 'crit', 'lifesteal', 'gold', 'xp'];
const R_STAT_NAMES = [
    'atk' => ['공격력', false], 'def' => ['방어력', false], 'hp' => ['최대 HP', false], 'crit' => ['치명타 확률', true],
    'lifesteal' => ['흡혈', true], 'gold' => ['골드 획득', true], 'xp' => ['경험치 획득', true],
];
const R_MAIN_POOL = ['weapon' => ['atk', 'crit', 'lifesteal', 'hp'], 'armor' => ['def', 'hp', 'atk', 'crit']];
const R_MAIN_COUNT = ['common' => [1, 2], 'uncommon' => [1, 2], 'rare' => [3, 4], 'epic' => [3, 4], 'legendary' => [3, 4], 'set' => [3, 4]];
const R_RARITY_MUL = ['common' => 1, 'uncommon' => 1.15, 'rare' => 1.3, 'epic' => 1.5, 'legendary' => 1.75, 'set' => 1.6];
const R_OFF_SLOT_MUL = 0.5;
const R_MAX_ROLL_CHANCE = 0.2;
const R_ENHANCE = ['max' => 10, 'statPerLevel' => 0.1, 'lineSteps' => [4, 6, 8, 10], 'lineBoost' => 0.5];
const R_SETS = [
    'trial' => [
        'name' => '시험의 세트',
        'weapon' => 'axe',
        'pieces' => ['head' => '시험의 투구', 'chest' => '시험의 갑옷', 'arms' => '시험의 팔보호구', 'pants' => '시험의 바지', 'boots' => '시험의 장화', 'weapon' => '시험의 도끼'],
        'bonuses' => [
            ['count' => 2, 'text' => '최대 HP +20%, 방어력 +20%', 'stats' => ['hpPct' => 20, 'defPct' => 20]],
            ['count' => 4, 'text' => '공격력 +25%, 치명타 확률 +10%', 'stats' => ['atkPct' => 25, 'crit' => 10]],
            ['count' => 6, 'text' => '치명타 피해 +100%, 흡혈 +8%, 적을 쓰러뜨리면 최대 HP의 15% 회복', 'stats' => ['critDmg' => 100, 'lifesteal' => 8, 'killHealPct' => 15]],
        ],
    ],
];

// ---------------------------------------------------------------- 시드 난수 (JS Math.imul / >>> 와 같게)

function r_imul(int $a, int $b): int {
    $a &= R_MASK; $b &= R_MASK;
    $lo = ($a & 0xFFFF) * $b;
    $hi = ((($a >> 16) & 0xFFFF) * $b) & 0xFFFF;
    return ($lo + ($hi << 16)) & R_MASK;
}

// JS의 charCodeAt처럼 UTF-16 코드 단위로
function r_code_units(string $s): array {
    if ($s === '') return [];
    $u = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
    return array_values(unpack('v*', $u));
}

function r_hash_seed(string $str): array {
    $h1 = 0xdeadbeef; $h2 = 0x41c6ce57;
    foreach (r_code_units($str) as $c) {
        $h1 = r_imul($h1 ^ $c, 2654435761);
        $h2 = r_imul($h2 ^ $c, 1597334677);
    }
    $h1 = r_imul($h1 ^ ($h1 >> 16), 2246822507) ^ r_imul($h2 ^ ($h2 >> 13), 3266489909);
    $h2 = r_imul($h2 ^ ($h2 >> 16), 2246822507) ^ r_imul($h1 ^ ($h1 >> 13), 3266489909);
    return [$h1 & R_MASK, $h2 & R_MASK];
}

function r_rng(string $seed): Closure {
    $a = r_hash_seed($seed)[0];
    return function () use (&$a): float {
        $a = ($a + 0x6d2b79f5) & R_MASK;
        $t = r_imul($a ^ ($a >> 15), 1 | $a);
        $t = (($t + r_imul($t ^ ($t >> 7), 61 | $t)) & R_MASK) ^ $t;
        return (($t ^ ($t >> 14)) & R_MASK) / 4294967296;
    };
}

function r_rand_int(Closure $rng, int $lo, int $hi): int { return $lo + (int)floor($rng() * ($hi - $lo + 1)); }
function r_pick(Closure $rng, array $list) { return $list[(int)floor($rng() * count($list))]; }
function r_pick_weighted(Closure $rng, array $weights): string {
    $entries = array_filter($weights, function ($w) { return $w > 0; });
    $total = array_sum($entries);
    $r = $rng() * $total;
    $last = '';
    foreach ($entries as $k => $w) { $last = $k; if (($r -= $w) < 0) return $k; }
    return $last;
}
// JS Math.round (양수)
function r_round(float $x): int { return (int)floor($x + 0.5); }

// ---------------------------------------------------------------- 범위

function r_line_range(string $stat, int $lv): array {
    switch ($stat) {
        case 'atk': return [1, 1 + intdiv($lv, 2)];
        case 'def': return [1, 1 + intdiv($lv, 3)];
        case 'hp': return [2, 4 + $lv * 2];
        case 'crit': return [1, 2 + intdiv($lv, 3)];
        case 'lifesteal': return [1, 2 + intdiv($lv, 4)];
        case 'gold': case 'xp': return [3, 8 + $lv];
    }
    throw new InvalidArgumentException("unknown stat $stat");
}

function r_main_base_range(string $stat, int $lv): array {
    switch ($stat) {
        case 'atk': return [1 + (int)floor($lv * 0.3), 3 + (int)floor($lv * 0.6)];
        case 'def': return [1 + (int)floor($lv * 0.2), 2 + (int)floor($lv * 0.5)];
        case 'hp': return [2 + $lv, 4 + $lv * 2];
        case 'crit': return [1, 2 + intdiv($lv, 4)];
        case 'lifesteal': return [1, 1 + intdiv($lv, 5)];
    }
    throw new InvalidArgumentException("unknown main stat $stat");
}

function r_main_range(string $stat, array $item): array {
    $mul = R_RARITY_MUL[$item['rarity']] ?? 1;
    if ($item['slot'] === 'weapon' && $stat === 'atk' && $item['type']) $mul *= R_WEAPONS[$item['type']]['atkMul'];
    $off = $item['slot'] === 'weapon' ? $stat === 'hp' : ($stat === 'atk' || $stat === 'crit');
    if ($off) $mul *= R_OFF_SLOT_MUL;
    [$lo, $hi] = r_main_base_range($stat, $item['lvl']);
    $a = max(1, r_round($lo * $mul));
    return [$a, max($a, r_round($hi * $mul))];
}

function r_roll_range(Closure $rng, array $range): int {
    [$lo, $hi] = $range;
    if ($hi <= $lo || $rng() < R_MAX_ROLL_CHANCE) return $hi;
    return r_rand_int($rng, $lo, $hi - 1);
}

// ---------------------------------------------------------------- 생성

function r_scale_base(array $base, int $level): array {
    $out = [];
    foreach ($base as $k => $v) $out[$k] = max($v, r_round($v * (1 + ($level - 1) * 0.2)));
    return $out;
}

function r_id(int $version, string $source, int $lv, string $seed): string {
    [$h1, $h2] = r_hash_seed("{$version}|{$source}|{$lv}|{$seed}");
    return str_pad(dechex($h1), 8, '0', STR_PAD_LEFT) . str_pad(dechex($h2), 8, '0', STR_PAD_LEFT);
}

function r_generate(int $version, string $seed, string $source, int $level): array {
    if (!isset(R_TABLES[$source])) throw new InvalidArgumentException('bad source');
    $lv = max(1, $level);
    $rng = r_rng("v{$version}|{$seed}");
    $rarity = r_pick_weighted($rng, R_TABLES[$source]);
    $slot = r_pick($rng, R_SLOTS);
    $set = $rarity === 'set' ? 'trial' : null;
    $type = null;
    if ($slot === 'weapon') $type = $set ? R_SETS[$set]['weapon'] : r_pick($rng, array_keys(R_WEAPONS));
    $item = ['v' => $version, 'seed' => $seed, 'src' => $source, 'lvl' => $lv, 'slot' => $slot, 'type' => $type, 'rarity' => $rarity, 'set' => $set];

    if ($version === 1) {
        $stats = R_STAT_KEYS;
        $lines = [];
        for ($i = 0; $i < R_RARITIES[$rarity]['lines']; $i++) {
            $stat = array_splice($stats, (int)floor($rng() * count($stats)), 1)[0];
            [$lo, $hi] = r_line_range($stat, $lv);
            $lines[] = [$stat, r_rand_int($rng, $lo, $hi)];
        }
        $item['base'] = r_scale_base($type ? R_WEAPONS[$type]['base'] : R_ARMOR[$slot]['base'], $lv);
        $item['lines'] = $lines;
        $item['id'] = r_id(1, $source, $lv, $seed);
        $item['ancient'] = false;
        return $item;
    }
    if ($version !== 2) throw new InvalidArgumentException('bad version');

    $pool = R_MAIN_POOL[$slot === 'weapon' ? 'weapon' : 'armor'];
    [$cLo, $cHi] = R_MAIN_COUNT[$rarity];
    $count = r_rand_int($rng, $cLo, $cHi);
    $rest = array_slice($pool, 1);
    $mainStats = [$pool[0]];
    while (count($mainStats) < $count) $mainStats[] = array_splice($rest, (int)floor($rng() * count($rest)), 1)[0];
    $item['main'] = [];
    foreach ($mainStats as $stat) $item['main'][] = [$stat, r_roll_range($rng, r_main_range($stat, $item))];

    $stats = R_STAT_KEYS;
    $item['lines'] = [];
    for ($i = 0; $i < R_RARITIES[$rarity]['lines']; $i++) {
        $stat = array_splice($stats, (int)floor($rng() * count($stats)), 1)[0];
        $item['lines'][] = [$stat, r_roll_range($rng, r_line_range($stat, $lv))];
    }
    $item['id'] = r_id(2, $source, $lv, $seed);
    $item['ancient'] = r_is_ancient($item);
    return $item;
}

function r_is_ancient(array $item): bool {
    if (!isset($item['main'])) return false;
    foreach ($item['main'] as [$k, $v]) if ($v !== r_main_range($k, $item)[1]) return false;
    foreach ($item['lines'] as [$k, $v]) if ($v !== r_line_range($k, $item['lvl'])[1]) return false;
    return true;
}

// 몬스터·포션 자리 드롭이 실제로 나오는 시드인지 (items.js rollDrop의 첫 굴림)
function r_drop_roll(int $version, string $seed): float {
    $rng = r_rng("v{$version}|drop?|{$seed}");
    return $rng();
}

// ---------------------------------------------------------------- 강화 / 합계 / 이름

function r_main_stats(array $item): array {
    if (isset($item['main'])) return $item['main'];
    $out = [];
    foreach ($item['base'] ?? [] as $k => $v) $out[] = [$k, $v];
    return $out;
}

function r_enhanced_base(array $item): array {
    $n = (int)($item['enh'] ?? 0);
    $out = [];
    foreach (r_main_stats($item) as [$k, $v]) {
        $floor = R_STAT_NAMES[$k][1] ? intdiv($n, 2) : $n;
        $out[$k] = $v + ($n ? max($floor, r_round($v * R_ENHANCE['statPerLevel'] * $n)) : 0);
    }
    return $out;
}

function r_enhanced_lines(array $item): array {
    $lines = [];
    foreach ($item['lines'] as [$k, $v]) $lines[] = [$k, $v, 0];
    if (!$lines) return $lines;
    foreach (R_ENHANCE['lineSteps'] as $step) {
        if ((int)($item['enh'] ?? 0) < $step) break;
        $rng = r_rng("enhline|{$item['id']}|{$step}");
        $i = (int)floor($rng() * count($lines));
        $add = max(1, r_round(r_line_range($lines[$i][0], $item['lvl'])[1] * R_ENHANCE['lineBoost']));
        $lines[$i][1] += $add;
        $lines[$i][2] += $add;
    }
    return $lines;
}

function r_totals(array $equipped): array {
    $sum = ['atk' => 0, 'def' => 0, 'hp' => 0, 'crit' => 0, 'lifesteal' => 0, 'gold' => 0, 'xp' => 0, 'killHeal' => 0,
        'atkPct' => 0, 'defPct' => 0, 'hpPct' => 0, 'critDmg' => 0, 'killHealPct' => 0];
    $setCounts = [];
    foreach ($equipped as $item) {
        foreach (r_enhanced_base($item) as $k => $v) $sum[$k] += $v;
        foreach (r_enhanced_lines($item) as [$k, $v]) $sum[$k] += $v;
        if (!empty($item['set'])) $setCounts[$item['set']] = ($setCounts[$item['set']] ?? 0) + 1;
    }
    $sets = [];
    foreach ($setCounts as $id => $count) {
        $set = R_SETS[$id];
        $bonuses = [];
        foreach ($set['bonuses'] as $b) {
            $on = $count >= $b['count'];
            if ($on) foreach ($b['stats'] as $k => $v) $sum[$k] += $v;
            $bonuses[] = ['count' => $b['count'], 'text' => $b['text'], 'on' => $on];
        }
        $sets[] = ['id' => $id, 'name' => $set['name'], 'count' => $count, 'bonuses' => $bonuses];
    }
    return ['stats' => $sum, 'sets' => $sets];
}

function r_item_name(array $item): string {
    $plus = (!empty($item['ancient']) ? '고대 ' : '') . (!empty($item['enh']) ? "+{$item['enh']} " : '');
    if (!empty($item['set'])) return $plus . R_SETS[$item['set']]['pieces'][$item['slot']];
    $base = $item['type'] ? R_WEAPONS[$item['type']]['name'] : R_ARMOR[$item['slot']]['name'];
    return $plus . R_RARITIES[$item['rarity']]['name'] . ' ' . $base;
}

// 게임 화면의 stats()와 같은 최종 능력치. 레벨로 오르는 기본치: HP 20 + 4/레벨, 공격력 3 + 1/레벨
function r_player_stats(int $plvl, array $totals): array {
    $g = $totals['stats'];
    $pct = function (float $v, float $p): int { return r_round($v * (1 + $p / 100)); };
    $maxHp = $pct(20 + 4 * ($plvl - 1) + $g['hp'], $g['hpPct']);
    return [
        'level' => $plvl,
        'maxHp' => $maxHp,
        'atk' => $pct(3 + ($plvl - 1) + $g['atk'], $g['atkPct']),
        'def' => $pct($g['def'], $g['defPct']),
        'crit' => min(75, $g['crit']),
        'critMul' => 2 + $g['critDmg'] / 100,
        'lifesteal' => $g['lifesteal'],
        'gold' => $g['gold'],
        'xp' => $g['xp'],
        'killHeal' => $g['killHeal'] + r_round($maxHp * $g['killHealPct'] / 100),
    ];
}
