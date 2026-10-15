// 몬스터 규칙: 종류, 출현 층, 정예, 보스, 황금 고블린.
// 게임(index.html), 도감, 문서 생성기(tools/gen-monster-docs.mjs)가 모두 이 파일을 읽는다.
// 브라우저(window.RLMonsters)와 Node(require) 양쪽에서 쓸 수 있게 작성.
(function (root) {
  "use strict";

  // from~until 층에서 나옴. 그 층에 나올 수 있는 종류 중 뒤(강한) 종류일수록 2배씩 자주 나옴
  const MONSTERS = [
    { id: "rat",        ch: "r", name: "쥐",          color: "#a58b6f", hp: 3,  atk: 1,  def: 0, xp: 2,  from: 1,  until: 5 },
    { id: "bat",        ch: "b", name: "박쥐",        color: "#9a9ab5", hp: 4,  atk: 2,  def: 0, xp: 3,  from: 1,  until: 6 },
    { id: "kobold",     ch: "k", name: "코볼트",      color: "#c9a26b", hp: 5,  atk: 2,  def: 0, xp: 4,  from: 2,  until: 7 },
    { id: "goblin",     ch: "g", name: "고블린",      color: "#7bce6b", hp: 6,  atk: 2,  def: 1, xp: 5,  from: 2,  until: 8 },
    { id: "skeleton",   ch: "s", name: "해골 병사",   color: "#e8e2cf", hp: 8,  atk: 3,  def: 1, xp: 7,  from: 3,  until: 10 },
    { id: "zombie",     ch: "z", name: "좀비",        color: "#8fa86b", hp: 12, atk: 3,  def: 1, xp: 8,  from: 4,  until: 11 },
    { id: "orc",        ch: "o", name: "오크",        color: "#5fae4e", hp: 10, atk: 3,  def: 1, xp: 9,  from: 4,  until: 12 },
    { id: "wolf",       ch: "w", name: "늑대",        color: "#b0b0b0", hp: 11, atk: 4,  def: 1, xp: 10, from: 5,  until: 12 },
    { id: "spider",     ch: "S", name: "거대 거미",   color: "#a0522d", hp: 13, atk: 5,  def: 2, xp: 13, from: 6,  until: 14 },
    { id: "troll",      ch: "T", name: "트롤",        color: "#9b7bce", hp: 16, atk: 5,  def: 2, xp: 16, from: 6,  until: 15 },
    { id: "harpy",      ch: "h", name: "하피",        color: "#e39bd1", hp: 14, atk: 6,  def: 2, xp: 18, from: 7,  until: 16 },
    { id: "wraith",     ch: "W", name: "망령",        color: "#7fd0e8", hp: 18, atk: 7,  def: 3, xp: 22, from: 8,  until: 18 },
    { id: "dark_knight", ch: "A", name: "암흑 기사",  color: "#6f7fa8", hp: 22, atk: 8,  def: 4, xp: 27, from: 9,  until: 20 },
    { id: "minotaur",   ch: "M", name: "미노타우로스", color: "#c8743c", hp: 28, atk: 9,  def: 4, xp: 32, from: 10, until: 22 },
    { id: "chimera",    ch: "C", name: "키메라",      color: "#d4a83c", hp: 26, atk: 10, def: 4, xp: 36, from: 11, until: 24 },
    { id: "lich",       ch: "L", name: "리치",        color: "#c27bff", hp: 24, atk: 12, def: 5, xp: 42, from: 12 },
    { id: "fire_spirit", ch: "E", name: "화염 정령", color: "#ff8a3d", hp: 30, atk: 12, def: 6, xp: 48, from: 13 },
    { id: "vampire",    ch: "V", name: "뱀파이어",    color: "#d23c5a", hp: 32, atk: 13, def: 6, xp: 52, from: 14 },
    { id: "drake",      ch: "D", name: "어린 드래곤", color: "#ff6b4a", hp: 40, atk: 14, def: 7, xp: 60, from: 15 },
    { id: "hydra",      ch: "H", name: "히드라",      color: "#3cc8a0", hp: 50, atk: 16, def: 8, xp: 75, from: 17 },
  ];

  // 층 보정: 같은 몬스터도 깊은 층일수록 강해짐. from층부터 n = 층 − from + 1 단계
  //   HP × (1 + hp × n), 공격력 × (1 + atk × n), 방어력 + 내림(def × n)
  // 몬스터가 플레이어를 때릴 때 방어력은 비율로 줄임: 피해 = 공격 × 2공격 / (2공격 + 방어력)
  const DEFENSE_K = 2;
  const FLOOR = { from: 3, hp: 0.2, atk: 0.15, def: 0.4 };
  const floorScale = (d) => {
    const n = Math.max(0, d - FLOOR.from + 1);
    return { hp: 1 + FLOOR.hp * n, atk: 1 + FLOOR.atk * n, def: Math.floor(FLOOR.def * n) };
  };

  // 방어력이 공격력과 같으면 피해 2/3, 2배면 1/2, 4배면 1/3. 방어력이 아무리 높아도 최소 1
  const damageToPlayer = (atk, def) => Math.max(1, Math.round(atk * DEFENSE_K * atk / (DEFENSE_K * atk + Math.max(0, def))));

  // 정예: 2층부터 8%, 층마다 1%p씩 올라 최대 20%
  const ELITE = { from: 2, base: 0.08, perFloor: 0.01, max: 0.2, hpMul: 2, atkMul: 1.5, defAdd: 1, xpMul: 3 };

  // 보스: 5층마다 계단 방에서 계단을 지킴. 준비된 보스를 다 지나면 마지막 보스가 5층마다 30%씩 강해짐
  const BOSS_EVERY = 5;
  const BOSS_SCALE = 0.3;
  const BOSS_COLOR = "#ff5a5a";
  const BOSSES = [
    { id: "goblin_king", ch: "G", name: "고블린 왕",   hp: 45,  atk: 6,  def: 2, xp: 60 },
    { id: "ogre_chief",  ch: "O", name: "오우거 족장", hp: 90,  atk: 10, def: 4, xp: 140 },
    { id: "lich_lord",   ch: "K", name: "리치 군주",   hp: 150, atk: 15, def: 7, xp: 260 },
  ];

  // 황금 고블린: 2층부터 층마다 12% 확률로 1마리. 공격하지 않고 플레이어에게서 달아나며(2턴에 1번 이동),
  // 처음 보인 뒤 30턴이 지나면 도망침. 방어력은 그 층 몬스터 중 가장 높은 방어력 +2
  const GOLDEN = {
    id: "golden_goblin", ch: "g", name: "황금 고블린", color: "#ffd700",
    from: 2, chance: 0.12, fleeTurns: 30, moveEvery: 2, defAdd: 2, drops: 2,
    hp: (d) => 6 + d,
    xp: (d) => 10 + d * 4,
  };

  // 골드: 바닥의 골드 더미와 몬스터 처치로 얻음. 층이 깊을수록 많아짐 (층 배율 = 1 + 0.15 × (층 − 1))
  // 범위 [최소, 최대]에서 무작위로 정한 뒤 층 배율을 곱함. 장비의 "골드 획득 +%"는 그 위에 더해짐
  const GOLD = {
    perFloor: 0.15,
    piles: { count: [2, 4], amount: [2, 8] },
    kill: { normal: [1, 2], elite: [6, 12], boss: [50, 80], golden: [20, 30] },
  };
  const goldMultiplier = (d) => 1 + GOLD.perFloor * (d - 1);
  function rollGold(range, d, rand) {
    const [lo, hi] = range;
    return Math.round((lo + Math.floor(rand() * (hi - lo + 1))) * goldMultiplier(d));
  }

  function pool(d) {
    return MONSTERS.filter(m => d >= m.from && d <= (m.until ?? Infinity));
  }

  // 그 층의 출현 비율 [{ monster, p }]
  function spawnRates(d) {
    const list = pool(d);
    const total = 2 ** list.length - 1;
    return list.map((m, i) => ({ monster: m, p: 2 ** i / total }));
  }

  // rand: 0 이상 1 미만을 돌려주는 함수
  function pickType(d, rand) {
    let r = rand();
    const rates = spawnRates(d);
    for (const { monster, p } of rates) {
      if ((r -= p) < 0) return monster;
    }
    return rates[rates.length - 1].monster;
  }

  const eliteChance = (d) => (d < ELITE.from ? 0 : Math.min(ELITE.max, ELITE.base + (d - ELITE.from) * ELITE.perFloor));

  // d: 지금 층 (층 보정을 적용)
  function makeMonster(t, kind = "normal", d = 1) {
    const f = floorScale(d);
    const m = {
      id: t.id, ch: t.ch, name: t.name, color: t.color, kind,
      hp: Math.round(t.hp * f.hp), atk: Math.round(t.atk * f.atk), def: t.def + f.def, xp: t.xp,
    };
    if (kind === "elite") {
      Object.assign(m, {
        name: `정예 ${t.name}`,
        hp: m.hp * ELITE.hpMul,
        atk: Math.ceil(m.atk * ELITE.atkMul),
        def: m.def + ELITE.defAdd,
        xp: t.xp * ELITE.xpMul,
      });
    }
    m.maxHp = m.hp;
    return m;
  }

  const isBossFloor = (d) => d > 0 && d % BOSS_EVERY === 0;

  // 계단: 플레이어 주변 이 거리(가로 칸 + 세로 칸) 안에 몬스터가 있으면 내려갈 수 없음
  const STAIRS_BLOCK_RADIUS = 4;

  function makeBoss(d) {
    const i = d / BOSS_EVERY - 1;
    const base = BOSSES[Math.min(i, BOSSES.length - 1)];
    const scale = 1 + BOSS_SCALE * Math.max(0, i - (BOSSES.length - 1));
    // 보스도 일반 몬스터와 같은 층 보정을 받음
    const f = floorScale(d);
    const stat = (v, mul = 1) => Math.round(v * scale * mul);
    const hp = stat(base.hp, f.hp);
    return {
      id: base.id, ch: base.ch, name: base.name, color: BOSS_COLOR, kind: "boss",
      hp, maxHp: hp, atk: stat(base.atk, f.atk), def: stat(base.def) + f.def, xp: stat(base.xp),
    };
  }

  function makeGolden(d) {
    const def = Math.max(0, ...pool(d).map(m => m.def)) + floorScale(d).def + GOLDEN.defAdd;
    const hp = GOLDEN.hp(d);
    return {
      id: GOLDEN.id, ch: GOLDEN.ch, name: GOLDEN.name, color: GOLDEN.color, kind: "golden",
      hp, maxHp: hp, atk: 0, def, xp: GOLDEN.xp(d),
    };
  }

  const api = {
    MONSTERS, FLOOR, floorScale, DEFENSE_K, damageToPlayer, ELITE, BOSSES, BOSS_EVERY, BOSS_SCALE, BOSS_COLOR, GOLDEN, GOLD, STAIRS_BLOCK_RADIUS,
    pool, spawnRates, pickType, eliteChance, makeMonster, isBossFloor, makeBoss, makeGolden,
    goldMultiplier, rollGold,
  };
  if (typeof module === "object" && module.exports) module.exports = api;
  else root.RLMonsters = api;
})(typeof self !== "undefined" ? self : this);
