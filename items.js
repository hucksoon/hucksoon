// 장비 규칙: 부위, 무기 종류, 등급, 추가 능력치, 세트 효과, 등급 확률.
// 같은 시드로 만들면 항상 같은 아이템이 나온다. 획득 로그의 시드로 서버에서 다시 계산해 검증할 수 있도록
// 브라우저(window.RLItems)와 Node(require) 양쪽에서 쓸 수 있게 작성.
(function (root) {
  "use strict";

  // 생성 규칙이 바뀌면 올린다. 검증할 때는 아이템에 기록된 버전의 규칙으로 다시 계산해야 함
  // v1: 기본 능력치는 부위로 고정, 추가 능력치만 무작위
  // v2: 주 능력치(강화로 오름)도 등급별 개수만큼 무작위, 모든 능력치는 [최소~최대] 범위에서 최대치 20%
  const RULES_VERSION = 2;

  // ---- 시드 기반 난수 ----
  function hashSeed(str) {
    let h1 = 0xdeadbeef, h2 = 0x41c6ce57;
    for (let i = 0; i < str.length; i++) {
      const c = str.charCodeAt(i);
      h1 = Math.imul(h1 ^ c, 2654435761);
      h2 = Math.imul(h2 ^ c, 1597334677);
    }
    h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
    h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
    return [h1 >>> 0, h2 >>> 0];
  }

  function rngFrom(seed) {
    let a = hashSeed(seed)[0];
    return () => {
      a = (a + 0x6d2b79f5) | 0;
      let t = Math.imul(a ^ (a >>> 15), 1 | a);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  const randInt = (rng, lo, hi) => lo + Math.floor(rng() * (hi - lo + 1));
  const pick = (rng, list) => list[Math.floor(rng() * list.length)];

  function pickWeighted(rng, weights) {
    const entries = Object.entries(weights).filter(([, w]) => w > 0);
    const total = entries.reduce((s, [, w]) => s + w, 0);
    let r = rng() * total;
    for (const [key, w] of entries) {
      if ((r -= w) < 0) return key;
    }
    return entries[entries.length - 1][0];
  }

  // ---- 부위와 무기 ----
  const SLOTS = ["head", "chest", "arms", "pants", "boots", "weapon"];
  const SLOT_NAMES = { head: "머리", chest: "가슴", arms: "팔", pants: "바지", boots: "장화", weapon: "무기" };

  // 기본 능력치는 아이템 레벨에 따라 커짐
  // base는 v1 아이템용. v2에서는 atkMul(주 능력치 공격력 배율)만 씀
  const WEAPONS = {
    dagger: { name: "단검", base: { atk: 2, crit: 6 }, atkMul: 1 },
    staff: { name: "지팡이", base: { atk: 2, hp: 6 }, atkMul: 1 },
    axe: { name: "도끼", base: { atk: 4 }, atkMul: 1.3 },
  };
  const ARMOR = {
    head: { name: "투구", base: { def: 1, hp: 2 } },
    chest: { name: "갑옷", base: { def: 2, hp: 4 } },
    arms: { name: "팔보호구", base: { def: 1 } },
    pants: { name: "바지", base: { def: 1, hp: 2 } },
    boots: { name: "장화", base: { def: 1 } },
  };

  // ---- 등급 ----
  const RARITIES = {
    common: { name: "커먼", lines: 1, color: "#cfd3da" },
    uncommon: { name: "언커먼", lines: 2, color: "#6bd36b" },
    rare: { name: "레어", lines: 3, color: "#5aa9ff" },
    epic: { name: "에픽", lines: 4, color: "#b77bff" },
    legendary: { name: "레전더리", lines: 5, color: "#ff9f2e" },
    set: { name: "세트", lines: 4, color: "#2fd6b5" },
  };

  // 등급 확률(가중치). 임시 값 — 확정된 확률표를 받으면 여기만 바꾸고 RULES_VERSION을 올린다
  const TABLES = {
    box: { common: 40, uncommon: 30, rare: 18, epic: 8, legendary: 3, set: 1 },
    drop: { common: 60, uncommon: 25, rare: 10, epic: 3.5, legendary: 1, set: 0.5 },
    boss: { common: 20, uncommon: 30, rare: 28, epic: 15, legendary: 5, set: 2 },
    golden: { rare: 65, epic: 25, legendary: 7, set: 3 },  // 황금 고블린: 레어 이상만
  };
  // 장비를 떨어뜨릴 확률과 등급표. normal은 일반 몬스터와 포션 자리
  const DROPS = {
    normal: { chance: 0.08, table: "drop" },
    elite: { chance: 0.4, table: "drop" },
    boss: { chance: 1, table: "boss" },
    golden: { chance: 1, table: "golden" },  // 한 마리당 2번 굴림 (처치 순번 2개 사용)
  };
  const DROP_CHANCE = DROPS.normal.chance;

  // ---- 능력치 ----
  // 모든 능력치는 아이템 레벨(lv)로 정해지는 [최소, 최대] 범위에서 굴림. 최대치가 나올 확률은 20%,
  // 나머지 80%는 최소 ~ (최대 − 1)에서 고르게. 모든 능력치가 최대치인 아이템은 "고대" 아이템
  const MAX_ROLL_CHANCE = 0.2;
  const STATS = {
    atk: { name: "공격력", pct: false, line: (lv) => [1, 1 + Math.floor(lv / 2)] },
    def: { name: "방어력", pct: false, line: (lv) => [1, 1 + Math.floor(lv / 3)] },
    hp: { name: "최대 HP", pct: false, line: (lv) => [2, 4 + lv * 2] },
    crit: { name: "치명타 확률", pct: true, line: (lv) => [1, 2 + Math.floor(lv / 3)] },
    lifesteal: { name: "흡혈", pct: true, line: (lv) => [1, 2 + Math.floor(lv / 4)] },
    gold: { name: "골드 획득", pct: true, line: (lv) => [3, 8 + lv] },
    xp: { name: "경험치 획득", pct: true, line: (lv) => [3, 8 + lv] },
    // 아래는 세트 효과에만 있음
    atkPct: { name: "공격력", pct: true },
    defPct: { name: "방어력", pct: true },
    hpPct: { name: "최대 HP", pct: true },
    critDmg: { name: "치명타 피해", pct: true },
    killHealPct: { name: "처치 시 최대 HP 회복", pct: true },
  };
  const STAT_KEYS = ["atk", "def", "hp", "crit", "lifesteal", "gold", "xp"];  // 추가 능력치로 붙을 수 있는 것

  // 주 능력치: 강화하면 함께 오르는 능력치. 무기는 공격력, 방어구는 방어력이 항상 첫 번째로 붙고
  // 나머지는 부위별 후보에서 무작위. 개수는 커먼·언커먼 1~2개, 레어 이상 3~4개
  const MAIN = {
    pool: { weapon: ["atk", "crit", "lifesteal", "hp"], armor: ["def", "hp", "atk", "crit"] },
    count: { common: [1, 2], uncommon: [1, 2], rare: [3, 4], epic: [3, 4], legendary: [3, 4], set: [3, 4] },
    // 아이템 레벨 lv에서의 범위 (등급 배율 적용 전)
    range: {
      atk: (lv) => [1 + Math.floor(lv * 0.3), 3 + Math.floor(lv * 0.6)],
      def: (lv) => [1 + Math.floor(lv * 0.2), 2 + Math.floor(lv * 0.5)],
      hp: (lv) => [2 + lv, 4 + lv * 2],
      crit: (lv) => [1, 2 + Math.floor(lv / 4)],
      lifesteal: (lv) => [1, 1 + Math.floor(lv / 5)],
    },
    // 등급이 높을수록 범위 자체가 커짐
    rarityMul: { common: 1, uncommon: 1.15, rare: 1.3, epic: 1.5, legendary: 1.75, set: 1.6 },
    offSlotMul: 0.5,  // 방어구에 붙은 공격력·치명타, 무기에 붙은 최대 HP는 절반
  };
  const scaleRange = ([lo, hi], mul) => {
    const a = Math.max(1, Math.round(lo * mul));
    return [a, Math.max(a, Math.round(hi * mul))];
  };
  function mainRange(stat, item) {
    let mul = MAIN.rarityMul[item.rarity] || 1;
    if (item.slot === "weapon" && stat === "atk" && item.type) mul *= WEAPONS[item.type].atkMul;
    const off = item.slot === "weapon" ? stat === "hp" : (stat === "atk" || stat === "crit");
    if (off) mul *= MAIN.offSlotMul;
    return scaleRange(MAIN.range[stat](item.lvl), mul);
  }
  const lineRange = (stat, lv) => STATS[stat].line(lv);
  // 최대치 20%, 그 외에는 최소 ~ (최대 − 1)
  function rollRange(rng, [lo, hi]) {
    if (hi <= lo || rng() < MAX_ROLL_CHANCE) return hi;
    return randInt(rng, lo, hi - 1);
  }

  // ---- 세트 ----
  // 2·4·6개를 입을 때마다 효과가 하나씩 켜짐. 직업이 생기면 직업별 세트를 여기에 추가
  const SETS = {
    trial: {
      name: "시험의 세트",
      weapon: "axe",
      pieces: {
        head: "시험의 투구", chest: "시험의 갑옷", arms: "시험의 팔보호구",
        pants: "시험의 바지", boots: "시험의 장화", weapon: "시험의 도끼",
      },
      // 구하기 아주 어려운 만큼 강하게: 전체 능력치에 % 로 붙음
      bonuses: [
        { count: 2, text: "최대 HP +20%, 방어력 +20%", stats: { hpPct: 20, defPct: 20 } },
        { count: 4, text: "공격력 +25%, 치명타 확률 +10%", stats: { atkPct: 25, crit: 10 } },
        { count: 6, text: "치명타 피해 +100%, 흡혈 +8%, 적을 쓰러뜨리면 최대 HP의 15% 회복", stats: { critDmg: 100, lifesteal: 8, killHealPct: 15 } },
      ],
    },
  };

  // v1 아이템 생성 규칙에서 쓰던 함수 (예전 아이템 검증용으로 남겨 둠)
  function scaleBase(base, level) {
    const out = {};
    for (const [k, v] of Object.entries(base)) out[k] = Math.max(v, Math.round(v * (1 + (level - 1) * 0.2)));
    return out;
  }

  // 장비 1개 생성. source: "box" | "drop", level: 아이템 레벨(1 이상)
  function generate(seed, source, level) {
    const lv = Math.max(1, Math.floor(level));
    const rng = rngFrom(`v${RULES_VERSION}|${seed}`);
    const rarity = pickWeighted(rng, TABLES[source]);
    const slot = pick(rng, SLOTS);
    const set = rarity === "set" ? "trial" : null;
    let type = null;
    if (slot === "weapon") type = set ? SETS[set].weapon : pick(rng, Object.keys(WEAPONS));

    const item = { v: RULES_VERSION, seed, src: source, lvl: lv, slot, type, rarity, set };

    // 주 능력치: 첫 번째는 고정, 나머지는 후보에서 무작위
    const pool = MAIN.pool[slot === "weapon" ? "weapon" : "armor"];
    const [cLo, cHi] = MAIN.count[rarity];
    const count = randInt(rng, cLo, cHi);
    const rest = pool.slice(1);
    const mainStats = [pool[0]];
    while (mainStats.length < count) mainStats.push(rest.splice(Math.floor(rng() * rest.length), 1)[0]);
    item.main = mainStats.map(stat => [stat, rollRange(rng, mainRange(stat, item))]);

    // 추가 능력치: 등급별 줄 수만큼, 서로 다른 종류
    const stats = STAT_KEYS.slice();
    item.lines = [];
    for (let i = 0; i < RARITIES[rarity].lines; i++) {
      const stat = stats.splice(Math.floor(rng() * stats.length), 1)[0];
      item.lines.push([stat, rollRange(rng, lineRange(stat, lv))]);
    }

    const [h1, h2] = hashSeed(`${RULES_VERSION}|${source}|${lv}|${seed}`);
    item.id = h1.toString(16).padStart(8, "0") + h2.toString(16).padStart(8, "0");
    item.ancient = isAncient(item);
    return item;
  }

  // ---- 주 능력치 / 범위 / 고대 ----
  // v1 아이템은 main 대신 base(부위 고정 기본 능력치)를 씀
  const mainStatsOf = (item) => item.main || Object.entries(item.base || {});
  // 범위를 알 수 없으면(v1의 기본 능력치) null
  function statRange(item, kind, stat) {
    if (kind === "line") return STATS[stat]?.line ? lineRange(stat, item.lvl) : null;
    return item.main && MAIN.range[stat] ? mainRange(stat, item) : null;
  }
  // 모든 능력치가 범위의 최대치 (v2 아이템만)
  function isAncient(item) {
    if (!item.main) return false;
    return item.main.every(([k, v]) => v === mainRange(k, item)[1]) &&
      item.lines.every(([k, v]) => v === lineRange(k, item.lvl)[1]);
  }

  // 처치 1회(또는 포션 자리 1곳)의 결과: 드롭 없으면 null. kind: "normal" | "elite" | "boss"
  function rollDrop(seed, level, kind = "normal") {
    const { chance, table } = DROPS[kind];
    const rng = rngFrom(`v${RULES_VERSION}|drop?|${seed}`);
    return rng() < chance ? generate(seed, table, level) : null;
  }

  // ---- 판매 / 강화 ----
  // 판매하면 등급에 따라 강화석을 줌
  const SELL_STONES = { common: 1, uncommon: 2, rare: 4, epic: 8, legendary: 16, set: 12 };

  // +N으로 강화할 때: 강화석 N개. +4까지는 항상 성공, +5는 50%, 이후 10%p씩 낮아져 최저 5%.
  // 실패해도 장비와 강화 단계는 그대로이고 강화석만 사라짐. 강화 1단계마다 주 능력치 +10% (최소 +1, % 능력치는 2단계마다 +1)
  // +4, +6, +8, +10이 되면 추가 능력치 중 하나가 그 능력치 최대치의 50%(최소 +1)만큼 오름
  const ENHANCE = { max: 10, statPerLevel: 0.1, lineSteps: [4, 6, 8, 10], lineBoost: 0.5 };
  const enhanceCost = (to) => to;
  const enhanceChance = (to) => (to <= 4 ? 1 : Math.max(0.05, 0.5 - (to - 5) * 0.1));

  // 강화 결과는 (아이템 ID, 몇 번째 시도) 시드로 정해짐 → 새로고침해도 같은 결과, 서버에서 다시 계산 가능
  function rollEnhance(item) {
    const to = (item.enh || 0) + 1;
    const rng = rngFrom(`v${RULES_VERSION}|enh|${item.id}|${item.tries || 0}`);
    return { to, chance: enhanceChance(to), ok: rng() < enhanceChance(to) };
  }

  // 강화가 반영된 주 능력치 { stat: 값 }
  function enhancedBase(item) {
    const n = item.enh || 0;
    const out = {};
    for (const [k, v] of mainStatsOf(item)) {
      // 최소 보장: 수치 능력치는 단계마다 +1, % 능력치(치명타·흡혈)는 2단계마다 +1
      const floor = STATS[k].pct ? Math.floor(n / 2) : n;
      out[k] = v + (n ? Math.max(floor, Math.round(v * ENHANCE.statPerLevel * n)) : 0);
    }
    return out;
  }

  // 강화가 반영된 추가 능력치 [[stat, 값, 강화로 오른 양]]. 어느 줄이 오를지는 (아이템 ID, 단계) 시드로 정해짐
  function enhancedLines(item) {
    const lines = item.lines.map(([k, v]) => [k, v, 0]);
    if (!lines.length) return lines;
    for (const step of ENHANCE.lineSteps) {
      if ((item.enh || 0) < step) break;
      const i = Math.floor(rngFrom(`enhline|${item.id}|${step}`)() * lines.length);
      const add = Math.max(1, Math.round(lineRange(lines[i][0], item.lvl)[1] * ENHANCE.lineBoost));
      lines[i][1] += add;
      lines[i][2] += add;
    }
    return lines;
  }

  // ---- 상점 ----
  // 보스 층(5층마다) 바로 전 층(4, 9, 14…층)의 계단 방에 열림. 보스와 싸우기 전에 장비를 갖추라고
  // 황금 고블린과 같은 등급표(레어 이상)로 6개를 진열, 골드로 구매
  // 진열 바꾸기: 상점마다 최대 3번, 비용은 바꿀 때마다 2배이고 층마다 25%씩 비싸짐
  const SHOP = {
    every: 5, slots: 6, table: "golden",  // every: 보스 간격. 상점은 그 전 층
    prices: { rare: 60, epic: 150, legendary: 400, set: 300 },
    rerolls: { max: 3, costs: [40, 80, 160], perFloor: 0.25 },
  };

  const isShopFloor = (depth) => depth % SHOP.every === SHOP.every - 1;

  // 진열품은 (판 ID, 층, 진열 바꾼 횟수, 칸 번호) 시드로 정해짐. 처음 진열(0번)은 바꾼 횟수 없이 씀
  function shopStock(runId, depth, reroll = 0) {
    const r = reroll ? `|r${reroll}` : "";
    return Array.from({ length: SHOP.slots }, (_, i) => generate(`${runId}|shop|d${depth}${r}|s${i}`, SHOP.table, depth));
  }

  // 레벨(층)에 따른 보정: × (1 + 레벨당 10%), 5골드 단위
  const levelPrice = (base, lvl) => Math.round(base * (1 + (lvl - 1) * 0.1) / 5) * 5;
  const shopPrice = (item) => levelPrice(SHOP.prices[item.rarity], item.lvl);
  // n번째(0부터) 진열 바꾸기 비용: 기본값 × (1 + 0.25 × (층 − 1)), 5골드 단위. 더 바꿀 수 없으면 null
  const rerollCost = (depth, n) => (n < SHOP.rerolls.max
    ? Math.round(SHOP.rerolls.costs[n] * (1 + SHOP.rerolls.perFloor * (depth - 1)) / 5) * 5
    : null);

  // ---- 표시 ----
  function itemName(item) {
    const plus = (item.ancient ? "고대 " : "") + (item.enh ? `+${item.enh} ` : "");
    if (item.set) return plus + SETS[item.set].pieces[item.slot];
    const base = item.type ? WEAPONS[item.type].name : ARMOR[item.slot].name;
    return `${plus}${RARITIES[item.rarity].name} ${base}`;
  }

  function formatStat(stat, value) {
    const s = STATS[stat];
    return `${s.name} +${value}${s.pct ? "%" : ""}`;
  }
  // 범위 표시: "3~7" 또는 "2~5%"
  function formatRange(stat, range) {
    if (!range) return "";
    const unit = STATS[stat].pct ? "%" : "";
    return range[0] === range[1] ? `${range[1]}${unit}` : `${range[0]}~${range[1]}${unit}`;
  }

  // 장착한 아이템들의 능력치 합계와 세트 효과
  function totals(equipped) {
    const sum = { atk: 0, def: 0, hp: 0, crit: 0, lifesteal: 0, gold: 0, xp: 0, killHeal: 0,
      atkPct: 0, defPct: 0, hpPct: 0, critDmg: 0, killHealPct: 0 };
    const setCounts = {};
    for (const item of equipped) {
      if (!item) continue;
      for (const [k, v] of Object.entries(enhancedBase(item))) sum[k] += v;
      for (const [k, v] of enhancedLines(item)) sum[k] += v;
      if (item.set) setCounts[item.set] = (setCounts[item.set] || 0) + 1;
    }
    const activeSets = [];
    for (const [id, count] of Object.entries(setCounts)) {
      const set = SETS[id];
      const active = set.bonuses.filter(b => count >= b.count);
      for (const b of active) for (const [k, v] of Object.entries(b.stats)) sum[k] += v;
      activeSets.push({ id, name: set.name, count, bonuses: set.bonuses.map(b => ({ ...b, on: count >= b.count })) });
    }
    return { stats: sum, sets: activeSets };
  }

  const api = {
    RULES_VERSION, SLOTS, SLOT_NAMES, WEAPONS, ARMOR, RARITIES, TABLES, DROPS, DROP_CHANCE, STATS, STAT_KEYS, SETS,
    MAIN, MAX_ROLL_CHANCE, SELL_STONES, ENHANCE, SHOP, isShopFloor,
    rngFrom, generate, rollDrop, itemName, formatStat, formatRange, totals,
    mainStatsOf, statRange, mainRange, lineRange, isAncient,
    enhanceCost, enhanceChance, rollEnhance, enhancedBase, enhancedLines, shopStock, shopPrice, rerollCost,
  };
  if (typeof module === "object" && module.exports) module.exports = api;
  else root.RLItems = api;
})(typeof self !== "undefined" ? self : this);
