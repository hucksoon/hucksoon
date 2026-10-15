#!/usr/bin/env node
// monsters.js와 items.js의 값으로 docs/monsters.md를 만든다. 수치를 바꾼 뒤 다시 실행하면 문서가 맞춰진다.
//
//   node tools/gen-monster-docs.mjs          # docs/monsters.md 갱신
//   node tools/gen-monster-docs.mjs --check  # 문서가 최신인지 확인만 (다르면 종료 코드 1)
import { createRequire } from "node:module";
import { readFileSync, writeFileSync, mkdirSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";

const require = createRequire(import.meta.url);
const M = require("../monsters.js");
const I = require("../items.js");
const OUT = fileURLToPath(new URL("../docs/monsters.md", import.meta.url));

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

const E = M.ELITE, G = M.GOLDEN;
const FLOORS = [1, 2, 3, 4, 5, 6, 8, 10, 12, 14, 15, 17, 20, 25];
const BOSS_FLOORS = [5, 10, 15, 20, 25, 30];

const md = `# 몬스터 도감

> 이 문서는 \`node tools/gen-monster-docs.mjs\`로 만들어요. 직접 고치지 말고 \`monsters.js\`, \`items.js\`를 고친 뒤 다시 실행하세요.

## 일반 몬스터 (${M.MONSTERS.length}종)

${table(["표시", "이름", "HP", "공격", "방어", "경험치", "나오는 층"],
  M.MONSTERS.map(m => [`\`${m.ch}\``, m.name, m.hp, m.atk, m.def, m.xp, floors(m)]))}

- 플레이어가 주는 피해: 공격력 + (0~1) − 몬스터 방어력 (최소 1, 치명타면 공격력 부분이 2배)
- 몬스터가 주는 피해: 공격력 + (0~1) − 플레이어 방어력 (최소 1)
- 한 층의 마릿수: (4 + 층) ~ (6 + 층×2)

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
  [`상점 (${I.SHOP.every}층마다, 골드)`, `진열 ${I.SHOP.slots}개`, tableRates(I.TABLES[I.SHOP.table])],
  ["장비 상자 (캐시)", "-", tableRates(I.TABLES.box)],
])}

## 상점

- ${I.SHOP.every}층마다 계단 옆 \`&\` 칸. 밟으면 열려요
- 황금 고블린과 같은 등급표로 ${I.SHOP.slots}개를 진열해요. 진열품은 (판 ID, 층)으로 정해져서 다시 열어도 같아요
- 가격: 등급 기본값 × (1 + 아이템 레벨당 10%), 5골드 단위

${table(["등급", "기본 가격", "5층", "10층", "20층"],
  Object.keys(I.SHOP.prices).map(r => [I.RARITIES[r].name, I.SHOP.prices[r], ...[5, 10, 20].map(l => I.shopPrice({ rarity: r, lvl: l }))]))}

## 판매와 강화

- 판매하면 등급에 따라 강화석을 받아요: ${Object.entries(I.SELL_STONES).map(([r, n]) => `${I.RARITIES[r].name} ${n}`).join(", ")}
- +N으로 강화할 때 강화석 N개가 들어요. 실패해도 아이템과 강화 단계는 그대로이고 강화석만 사라져요
- 강화 1단계마다 기본 능력치가 +10% 올라요 (능력치마다 최소 +1). 추가 능력치 줄은 그대로예요
- 결과는 (아이템 ID, 몇 번째 시도)로 정해져서 새로고침해도 바뀌지 않아요

${table(["강화", ...Array.from({ length: I.ENHANCE.max }, (_, i) => `+${i + 1}`)], [
  ["성공 확률", ...Array.from({ length: I.ENHANCE.max }, (_, i) => pct(I.enhanceChance(i + 1)))],
  ["강화석", ...Array.from({ length: I.ENHANCE.max }, (_, i) => I.enhanceCost(i + 1))],
])}
`;

if (process.argv.includes("--check")) {
  const current = existsSync(OUT) ? readFileSync(OUT, "utf8") : "";
  if (current !== md) { console.error("docs/monsters.md가 최신이 아닙니다. node tools/gen-monster-docs.mjs 를 실행하세요."); process.exit(1); }
  console.log("docs/monsters.md 최신");
} else {
  mkdirSync(new URL("../docs/", import.meta.url), { recursive: true });
  writeFileSync(OUT, md);
  console.log("docs/monsters.md 생성");
}
