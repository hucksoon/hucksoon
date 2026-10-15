// 장비 규칙: 부위, 무기 종류, 등급, 추가 능력치, 세트 효과, 등급 확률.
// 같은 시드로 만들면 항상 같은 아이템이 나온다. 획득 로그의 시드로 서버에서 다시 계산해 검증할 수 있도록
// 브라우저(window.RLItems)와 Node(require) 양쪽에서 쓸 수 있게 작성.
(function (root) {
  "use strict";

  // 생성 규칙이 바뀌면 올린다. 검증할 때는 아이템에 기록된 버전의 규칙으로 다시 계산해야 함
  const RULES_VERSION = 1;

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
  const WEAPONS = {
    dagger: { name: "단검", base: { atk: 2, crit: 6 } },
    staff: { name: "지팡이", base: { atk: 2, hp: 6 } },
    axe: { name: "도끼", base: { atk: 4 } },
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
  };
  // 몬스터를 쓰러뜨렸을 때 장비를 떨어뜨릴 확률
  const DROP_CHANCE = 0.08;

  // ---- 추가 능력치 ----
  const STATS = {
    atk: { name: "공격력", pct: false, roll: (rng, lv) => randInt(rng, 1, 1 + Math.floor(lv / 2)) },
    def: { name: "방어력", pct: false, roll: (rng, lv) => randInt(rng, 1, 1 + Math.floor(lv / 3)) },
    hp: { name: "최대 HP", pct: false, roll: (rng, lv) => randInt(rng, 2, 4 + lv * 2) },
    crit: { name: "치명타 확률", pct: true, roll: (rng, lv) => randInt(rng, 1, 2 + Math.floor(lv / 3)) },
    lifesteal: { name: "흡혈", pct: true, roll: (rng, lv) => randInt(rng, 1, 2 + Math.floor(lv / 4)) },
    gold: { name: "골드 획득", pct: true, roll: (rng, lv) => randInt(rng, 3, 8 + lv) },
    xp: { name: "경험치 획득", pct: true, roll: (rng, lv) => randInt(rng, 3, 8 + lv) },
  };
  const STAT_KEYS = Object.keys(STATS);

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
      bonuses: [
        { count: 2, text: "최대 HP +10", stats: { hp: 10 } },
        { count: 4, text: "공격력 +3, 방어력 +2", stats: { atk: 3, def: 2 } },
        { count: 6, text: "적을 쓰러뜨리면 HP 5 회복", stats: { killHeal: 5 } },
      ],
    },
  };

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

    const stats = STAT_KEYS.slice();
    const lines = [];
    for (let i = 0; i < RARITIES[rarity].lines; i++) {
      const stat = stats.splice(Math.floor(rng() * stats.length), 1)[0];
      lines.push([stat, STATS[stat].roll(rng, lv)]);
    }

    const [h1, h2] = hashSeed(`${RULES_VERSION}|${source}|${lv}|${seed}`);
    return {
      id: h1.toString(16).padStart(8, "0") + h2.toString(16).padStart(8, "0"),
      v: RULES_VERSION,
      seed,
      src: source,
      lvl: lv,
      slot,
      type,
      rarity,
      set,
      base: scaleBase(type ? WEAPONS[type].base : ARMOR[slot].base, lv),
      lines,
    };
  }

  // 몬스터 처치 1회의 결과: 드롭 없으면 null
  function rollDrop(seed, level) {
    const rng = rngFrom(`v${RULES_VERSION}|drop?|${seed}`);
    return rng() < DROP_CHANCE ? generate(seed, "drop", level) : null;
  }

  // ---- 표시 ----
  function itemName(item) {
    if (item.set) return SETS[item.set].pieces[item.slot];
    const base = item.type ? WEAPONS[item.type].name : ARMOR[item.slot].name;
    return `${RARITIES[item.rarity].name} ${base}`;
  }

  function formatStat(stat, value) {
    const s = STATS[stat];
    return `${s.name} +${value}${s.pct ? "%" : ""}`;
  }

  // 장착한 아이템들의 능력치 합계와 세트 효과
  function totals(equipped) {
    const sum = { atk: 0, def: 0, hp: 0, crit: 0, lifesteal: 0, gold: 0, xp: 0, killHeal: 0 };
    const setCounts = {};
    for (const item of equipped) {
      if (!item) continue;
      for (const [k, v] of Object.entries(item.base)) sum[k] += v;
      for (const [k, v] of item.lines) sum[k] += v;
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
    RULES_VERSION, SLOTS, SLOT_NAMES, WEAPONS, ARMOR, RARITIES, TABLES, DROP_CHANCE, STATS, SETS,
    rngFrom, generate, rollDrop, itemName, formatStat, totals,
  };
  if (typeof module === "object" && module.exports) module.exports = api;
  else root.RLItems = api;
})(typeof self !== "undefined" ? self : this);
