#!/usr/bin/env node
// monsters.js와 items.js의 값으로 문서를 만든다. 수치를 바꾼 뒤 다시 실행하면 문서가 맞춰진다.
//   docs/monsters.md — 몬스터, 정예, 황금 고블린, 보스, 장비 드롭
//   docs/economy.md  — 골드, 상점, 판매와 강화
//   docs/items.md    — 장비 능력치 범위, 주/추가 능력치, 고대 아이템, 강화, 세트
//
//   node tools/gen-docs.mjs          # 문서 갱신
//   node tools/gen-docs.mjs --check  # 문서가 최신인지 확인만 (다르면 종료 코드 1)
import { createRequire } from "node:module";
import { readFileSync, writeFileSync, mkdirSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";

const require = createRequire(import.meta.url);
const M = require("../monsters.js");
const I = require("../items.js");

const pct = (p) => `${Math.round(p * 1000) / 10}%`;
const table = (head, rows) => [
  `| ${head.join(" | ")} |`,
  `|${head.map(() => "---").join("|")}|`,
  ...rows.map(r => `| ${r.join(" | ")} |`),
].join("\n");
const floors = (m) => (m.until ? `${m.from}~${m.until}` : `${m.from}~`);
const tableRates = (t) => Object.entries(t).map(([k, w]) => {
  const total = Object.values(t).reduce((s, x) => s + x, 0);
  return `${I.RARITIES[k].name} ${pct(w / total)}`;
}).join(", ");
const HEADER = "> 이 문서는 `node tools/gen-docs.mjs`로 만들어요. 직접 고치지 말고 `monsters.js`, `items.js`를 고친 뒤 다시 실행하세요.";

const E = M.ELITE, G = M.GOLDEN, GOLD = M.GOLD;
const FLOORS = [1, 2, 3, 4, 5, 6, 8, 10, 12, 14, 15, 17, 20, 25];
const BOSS_FLOORS = [5, 10, 15, 20, 25, 30];

// ---------------------------------------------------------------- monsters.md
const monstersMd = `# 몬스터 도감

${HEADER}

## 일반 몬스터 (${M.MONSTERS.length}종)

${table(["표시", "이름", "HP", "공격", "방어", "경험치", "나오는 층"],
  M.MONSTERS.map(m => [`\`${m.ch}\``, m.name, m.hp, m.atk, m.def, m.xp, floors(m)]))}

위 표는 **1~${M.FLOOR.from - 1}층 기준** 수치예요. 더 깊은 층에서는 [층 보정](#층-보정)만큼 강해져요.

- 플레이어가 주는 피해: 공격력 + (0~1) − 몬스터 방어력 (최소 1, 치명타면 공격력 부분이 2배 + 장비의 치명타 피해)
- 몬스터가 주는 피해: 방어력이 **비율로** 줄여요. 피해 = 공격 × ${M.DEFENSE_K}공격 ÷ (${M.DEFENSE_K}공격 + 방어력), 최소 1
  - 방어력이 공격력과 같으면 피해 ${pct(M.damageToPlayer(100, 100) / 100)}, 2배면 ${pct(M.damageToPlayer(100, 200) / 100)}, 4배면 ${pct(M.damageToPlayer(100, 400) / 100)}
- 포션: 최대 HP의 30% 회복 (최소 8)
- 한 층의 마릿수: (4 + 층) ~ (6 + 층×2)
- 계단: 주변 ${M.STAIRS_BLOCK_RADIUS}칸(가로 칸 + 세로 칸) 안에 몬스터가 있으면 내려갈 수 없어요. 정리한 뒤 다시 밟으면 내려가요
- 처치 골드는 [경제 문서](economy.md#골드)를 보세요

## 층 보정

같은 몬스터도 깊은 층일수록 강해져요. ${M.FLOOR.from}층부터 한 층마다 HP +${pct(M.FLOOR.hp)}, 공격력 +${pct(M.FLOOR.atk)}, 방어력 +${M.FLOOR.def}씩 (방어력은 내림).
정예와 보스도 같은 보정을 받아요 (정예 배율은 보정한 값에 곱해요).

${table(["층", "HP 배율", "공격력 배율", "방어력 +", "예: 그 층에서 가장 강한 몬스터"],
  FLOORS.map(d => {
    const f = M.floorScale(d);
    const top = M.spawnRates(d).at(-1).monster;
    const m = M.makeMonster(top, "normal", d);
    return [d, `×${Math.round(f.hp * 100) / 100}`, `×${Math.round(f.atk * 100) / 100}`, f.def, `${m.name} HP ${m.hp} · 공격 ${m.atk} · 방어 ${m.def}`];
  }))}

## 층별 출현 비율

그 층에 나올 수 있는 종류 중 강한(표에서 아래쪽) 종류일수록 2배씩 자주 나와요.

${table(["층", "종류 수", "출현 비율 (높은 순)"],
  FLOORS.map(d => {
    const r = M.spawnRates(d).slice().reverse();
    return [d, r.length, r.map(x => `${x.monster.name} ${pct(x.p)}`).join(", ")];
  }))}

## 정예

- ${E.from}층부터 ${pct(E.base)}, 층마다 ${pct(E.perFloor)}p씩 올라 최대 ${pct(E.max)}
- 이름 앞에 "정예", 금색 테두리
- HP ${E.hpMul}배, 공격력 ${E.atkMul}배(올림), 방어력 +${E.defAdd}, 경험치 ${E.xpMul}배
- 장비 드롭 ${pct(I.DROPS.elite.chance)} (일반 몬스터 등급표)

## 황금 고블린

- ${G.from}층부터 층마다 ${pct(G.chance)} 확률로 1마리. 시작 지점에서 떨어진 곳에 있고, 금빛 칸으로 보여요
- 플레이어를 공격하지 않아요. 들키면 ${G.moveEvery}턴에 1번씩 플레이어에게서 멀어지는 쪽으로 움직여요
- 처음 보인 뒤 **${G.fleeTurns}턴** 안에 잡지 못하면 도망쳐서 사라져요 (10턴 남으면 경고)
- 잡으면 **레어 이상 장비 ${G.drops}개를 반드시** 떨어뜨려요: ${tableRates(I.TABLES.golden)}

${table(["층", "HP", "방어 (그 층 최고 방어 +" + G.defAdd + ")", "경험치"],
  FLOORS.filter(d => d >= G.from).map(d => { const g = M.makeGolden(d); return [d, g.hp, g.def, g.xp]; }))}

## 보스

- ${M.BOSS_EVERY}층마다 계단 방에서 계단을 지켜요. 쓰러뜨려야 계단이 열려요. 빨간 테두리
- 장비를 반드시 떨어뜨려요: ${tableRates(I.TABLES.boss)}
- 준비된 보스를 다 지나면 마지막 보스가 ${M.BOSS_EVERY}층마다 ${pct(M.BOSS_SCALE)}씩 강해져요

${table(["층", "표시", "이름", "HP", "공격", "방어", "경험치"],
  BOSS_FLOORS.map(d => { const b = M.makeBoss(d); return [d, `\`${b.ch}\``, b.name, b.hp, b.atk, b.def, b.xp]; }))}

## 장비 드롭 요약

${table(["어디서", "확률", "등급 확률"], [
  ["일반 몬스터, 포션 자리", pct(I.DROPS.normal.chance), tableRates(I.TABLES.drop)],
  ["정예", pct(I.DROPS.elite.chance), tableRates(I.TABLES.drop)],
  ["보스", pct(I.DROPS.boss.chance), tableRates(I.TABLES.boss)],
  ["황금 고블린", `${pct(I.DROPS.golden.chance)} × ${G.drops}개`, tableRates(I.TABLES.golden)],
  [`상점 (보스 전 층, 골드)`, `진열 ${I.SHOP.slots}개`, tableRates(I.TABLES[I.SHOP.table])],
  ["장비 상자 (캐시)", "-", tableRates(I.TABLES.box)],
])}
`;

// ---------------------------------------------------------------- economy.md
const range = ([lo, hi], d) => {
  const a = Math.round(lo * M.goldMultiplier(d)), b = Math.round(hi * M.goldMultiplier(d));
  return a === b ? `${a}` : `${a}~${b}`;
};
const avg = ([lo, hi]) => (lo + hi) / 2;

// 그 층의 몬스터를 모두 잡고 골드 더미를 모두 주웠을 때의 평균 (장비 보너스 제외)
function expectedGold(d) {
  const mul = M.goldMultiplier(d);
  const piles = avg(GOLD.piles.count) * avg(GOLD.piles.amount) * mul;
  const monsters = ((4 + d) + (6 + d * 2)) / 2;
  const elite = M.eliteChance(d);
  const kills = monsters * ((1 - elite) * avg(GOLD.kill.normal) + elite * avg(GOLD.kill.elite)) * mul;
  const boss = M.isBossFloor(d) ? avg(GOLD.kill.boss) * mul : 0;
  const golden = d >= G.from ? G.chance * avg(GOLD.kill.golden) * mul : 0;
  return { piles, kills, boss, golden, total: piles + kills + boss + golden };
}
let running = 0;
const cumulative = Array.from({ length: 25 }, (_, i) => { running += expectedGold(i + 1).total; return running; });
const SHOP_FLOORS = Array.from({ length: 30 }, (_, i) => i + 1).filter(I.isShopFloor).slice(0, 5);  // 4, 9, 14, 19, 24

const economyMd = `# 경제: 골드, 상점, 판매와 강화

${HEADER}

## 골드

골드는 **판(run)마다 처음부터** 모아요. 죽으면 0이 되고, 상점에서 장비를 살 때 써요.

### 얻는 곳

- **골드 더미 \`$\`**: 층마다 ${GOLD.piles.count.join("~")}개, 하나에 ${GOLD.piles.amount.join("~")} × 층 배율
- **몬스터 처치**: 쓰러뜨리는 즉시 들어와요

${table(["처치한 몬스터", "골드 (1층 기준)", "일반 대비"],
  [["일반", "normal"], ["정예", "elite"], ["보스", "boss"], ["황금 고블린", "golden"]].map(([name, k]) =>
    [name, GOLD.kill[k].join("~"), `${Math.round(avg(GOLD.kill[k]) / avg(GOLD.kill.normal) * 10) / 10}배`]))}

- **층 배율** = 1 + ${GOLD.perFloor} × (층 − 1). 층이 하나 깊어질 때마다 ${pct(GOLD.perFloor)}씩 늘어요
- 장비의 **골드 획득 +%**는 그 위에 더해져요 (더미와 처치 골드 모두)

### 층별 골드

${table(["층", "층 배율", "더미 1개", "일반 처치", "정예 처치", "보스 처치", "황금 고블린"],
  FLOORS.map(d => [d, `×${M.goldMultiplier(d)}`, range(GOLD.piles.amount, d), range(GOLD.kill.normal, d), range(GOLD.kill.elite, d),
    M.isBossFloor(d) ? range(GOLD.kill.boss, d) : "-", d >= G.from ? range(GOLD.kill.golden, d) : "-"]))}

### 층마다 평균 얼마나 모일까

그 층의 몬스터를 모두 잡고 더미를 모두 주웠을 때의 **평균**이에요 (장비 보너스 제외, 황금 고블린은 등장 확률 반영).

${table(["층", "더미", "처치", "보스", "황금 고블린", "합계", "1층부터 누적"],
  [1, 2, 3, 4, 5, 6, 8, 10, 15, 20, 25].map(d => {
    const e = expectedGold(d);
    return [d, Math.round(e.piles), Math.round(e.kills), Math.round(e.boss) || "-", Math.round(e.golden) || "-", Math.round(e.total), Math.round(cumulative[d - 1])];
  }))}

## 상점

- 보스 층(${I.SHOP.every}층마다) 바로 전 층(${SHOP_FLOORS.slice(0, 3).join(", ")}…층) 계단 옆 \`&\` 칸. 밟으면 열려요
- 황금 고블린과 같은 등급표로 ${I.SHOP.slots}개를 진열해요: ${tableRates(I.TABLES[I.SHOP.table])}
- 진열품은 (판 ID, 층)으로 정해져서 다시 열어도 같아요. 산 칸은 "판매 완료"로 남아요
- 가격: 등급 기본값 × (1 + 아이템 레벨당 10%), 5골드 단위. 상점 아이템 레벨 = 그 층

${table(["등급", "기본 가격", ...SHOP_FLOORS.map(d => `${d}층`)],
  Object.keys(I.SHOP.prices).map(r => [I.RARITIES[r].name, I.SHOP.prices[r], ...SHOP_FLOORS.map(l => I.shopPrice({ rarity: r, lvl: l }))]))}

### 진열 바꾸기

- 상점마다 최대 ${I.SHOP.rerolls.max}번. 바꾸면 ${I.SHOP.slots}개가 모두 새로 진열되고, 산 칸 표시도 초기화돼요
- 비용은 바꿀 때마다 2배, 층이 깊을수록 비싸져요: 기본 ${I.SHOP.rerolls.costs.join(" → ")} × (1 + ${I.SHOP.rerolls.perFloor} × (층 − 1)), 5골드 단위
- 바뀐 진열도 (판 ID, 층, 바꾼 횟수)로 정해져서 새로고침해도 같아요

${table(["층", ...I.SHOP.rerolls.costs.map((_, n) => `${n + 1}번째`), "3번 모두"],
  SHOP_FLOORS.map(d => { const c = I.SHOP.rerolls.costs.map((_, n) => I.rerollCost(d, n)); return [d, ...c, c.reduce((x, y) => x + y, 0)]; }))}

1층부터 상점 층까지 모은 평균 골드: ${SHOP_FLOORS.map(d => `${d}층 약 ${Math.round(cumulative[d - 1])}`).join(", ")} (상점에서 쓴 골드는 빼지 않은 값)

## 판매와 강화

- 판매하면 등급에 따라 강화석을 받아요: ${Object.entries(I.SELL_STONES).map(([r, n]) => `${I.RARITIES[r].name} ${n}`).join(", ")}
- +N으로 강화할 때 강화석 N개가 들어요. 실패해도 아이템과 강화 단계는 그대로이고 강화석만 사라져요
- 강화 1단계마다 주 능력치가 +10% 올라요 (최소 +1, 치명타·흡혈 같은 % 능력치는 2단계마다 +1)
- +${I.ENHANCE.lineSteps.join(", +")}이 되면 추가 능력치 중 하나가 그 능력치 최대치의 ${pct(I.ENHANCE.lineBoost)}(최소 +1)만큼 올라요. 자세한 건 [장비 문서](items.md#강화)
- 결과는 (아이템 ID, 몇 번째 시도)로 정해져서 새로고침해도 바뀌지 않아요

${table(["강화", ...Array.from({ length: I.ENHANCE.max }, (_, i) => `+${i + 1}`)], [
  ["성공 확률", ...Array.from({ length: I.ENHANCE.max }, (_, i) => pct(I.enhanceChance(i + 1)))],
  ["강화석", ...Array.from({ length: I.ENHANCE.max }, (_, i) => I.enhanceCost(i + 1))],
])}
`;

// ---------------------------------------------------------------- items.md
const LEVELS = [1, 3, 5, 10, 15, 20, 30];
const fmtR = (stat, r) => I.formatRange(stat, r);
const sample = (slot, rarity, lvl, type = slot === "weapon" ? "dagger" : null) => ({ slot, rarity, lvl, type, main: [] });
const itemsMd = `# 장비: 능력치, 고대 아이템, 강화, 세트

${HEADER}

## 능력치가 붙는 방식

- 모든 능력치는 아이템 레벨(얻은 층)로 정해지는 **[최소~최대] 범위**에서 정해져요
- **최대치가 나올 확률은 능력치마다 ${pct(I.MAX_ROLL_CHANCE)}**, 나머지 ${pct(1 - I.MAX_ROLL_CHANCE)}는 최소 ~ (최대 − 1)에서 고르게 나와요
- 인벤토리에서 값 옆에 범위가 보이고, 최대치면 ▲ 표시가 붙어요
- 결과는 아이템 시드로 정해져서 서버에서 똑같이 다시 계산할 수 있어요 (규칙 버전 v${I.RULES_VERSION})

## 주 능력치 ★

강화하면 함께 오르는 중요한 능력치예요.

${table(["등급", "주 능력치 개수", "추가 능력치 줄 수", "범위 배율"],
  Object.entries(I.RARITIES).map(([r, x]) => [x.name, I.MAIN.count[r].join("~") + "개", x.lines + "줄", `×${I.MAIN.rarityMul[r]}`]))}

- 무기는 **공격력**, 방어구는 **방어력**이 항상 첫 번째로 붙고, 나머지는 후보에서 무작위
  - 무기 후보: ${I.MAIN.pool.weapon.map(k => I.STATS[k].name).join(", ")}
  - 방어구 후보: ${I.MAIN.pool.armor.map(k => I.STATS[k].name).join(", ")}
- 방어구의 공격력·치명타, 무기의 최대 HP는 범위가 절반이에요. 도끼의 공격력은 ×${I.WEAPONS.axe.atkMul}

### 주 능력치 범위 (커먼 기준, 등급 배율을 곱함)

${table(["아이템 레벨", ...Object.keys(I.MAIN.range).map(k => I.STATS[k].name + (["atk", "crit", "lifesteal"].includes(k) ? " (무기)" : " (방어구)"))],
  LEVELS.map(l => [l, ...Object.keys(I.MAIN.range).map(k => fmtR(k, I.mainRange(k, sample(["atk", "crit", "lifesteal"].includes(k) ? "weapon" : "chest", "common", l))))]))}

### 예: 레벨 10 장비의 주 능력치 범위

${table(["등급", "무기 공격력", "도끼 공격력", "방어구 방어력", "방어구 최대 HP"],
  Object.keys(I.RARITIES).map(r => [I.RARITIES[r].name,
    fmtR("atk", I.mainRange("atk", sample("weapon", r, 10))), fmtR("atk", I.mainRange("atk", sample("weapon", r, 10, "axe"))),
    fmtR("def", I.mainRange("def", sample("chest", r, 10))), fmtR("hp", I.mainRange("hp", sample("chest", r, 10)))]))}

## 추가 능력치 ◆

등급별 줄 수만큼, 서로 다른 종류로 붙어요. 범위는 등급과 상관없이 아이템 레벨로만 정해져요.

${table(["아이템 레벨", ...I.STAT_KEYS.map(k => I.STATS[k].name)],
  LEVELS.map(l => [l, ...I.STAT_KEYS.map(k => fmtR(k, I.lineRange(k, l)))]))}

## 고대 아이템

- **모든 능력치(주 + 추가)가 최대치**로 붙은 아이템이에요. 이름 앞에 **"고대"**가 붙고, 인벤토리에서 금빛으로 빛나요
- 능력치가 많을수록 어려워요: 능력치 ${[2, 3, 5, 7, 9].join("·")}개일 때 각각 ${[2, 3, 5, 7, 9].map(n => { const p = I.MAX_ROLL_CHANCE ** n; return p >= 0.001 ? pct(p) : `약 ${Math.round(1 / p).toLocaleString("en-US")}분의 1`; }).join(" · ")}
  (범위의 최소와 최대가 같은 능력치는 항상 최대치라 실제로는 조금 더 자주 나와요)

## 강화

- 강화 1단계마다 **주 능력치 +${pct(I.ENHANCE.statPerLevel)}** (최소 +1, 치명타·흡혈 같은 % 능력치는 2단계마다 +1)
- **+${I.ENHANCE.lineSteps.join(", +")}**이 되면 추가 능력치 중 하나가 그 능력치 **최대치의 ${pct(I.ENHANCE.lineBoost)}**(최소 +1)만큼 올라요
  - 어느 줄이 오를지는 (아이템 ID, 강화 단계)로 정해져요. 같은 줄이 여러 번 오를 수도 있어요
- 성공 확률과 강화석은 [경제 문서](economy.md#판매와-강화)를 보세요

## 세트

구하기 아주 어려운 만큼(장비 상자 ${pct(I.TABLES.box.set / 100)}, 몬스터 드롭 ${pct(I.TABLES.drop.set / 100)}) 효과가 강해요. 같은 세트를 2·4·6개 입을 때마다 효과가 하나씩 켜져요.

${Object.values(I.SETS).map(set => `### ${set.name}

${table(["입은 개수", "효과"], set.bonuses.map(b => [`${b.count}개`, b.text]))}`).join("\n\n")}

- % 효과는 레벨과 장비로 얻은 능력치 전체에 곱해져요
`;

// ---------------------------------------------------------------- 쓰기 / 확인
const DOCS = { "monsters.md": monstersMd, "economy.md": economyMd, "items.md": itemsMd };
const path = (name) => fileURLToPath(new URL(`../docs/${name}`, import.meta.url));

if (process.argv.includes("--check")) {
  const stale = Object.entries(DOCS).filter(([name, md]) => (existsSync(path(name)) ? readFileSync(path(name), "utf8") : "") !== md);
  if (stale.length) { console.error(`최신이 아닌 문서: ${stale.map(([n]) => "docs/" + n).join(", ")} — node tools/gen-docs.mjs 를 실행하세요.`); process.exit(1); }
  console.log("문서 최신");
} else {
  mkdirSync(new URL("../docs/", import.meta.url), { recursive: true });
  for (const [name, md] of Object.entries(DOCS)) writeFileSync(path(name), md);
  console.log(`생성: ${Object.keys(DOCS).map(n => "docs/" + n).join(", ")}`);
}
