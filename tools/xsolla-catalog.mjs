#!/usr/bin/env node
// Xsolla 카탈로그(캐시, 캐시 패키지, 장비 상자, 보너스 프로모션)를 만든다.
//
//   node tools/xsolla-catalog.mjs          # 무엇이 있고 무엇을 만들지 보여주기만 함
//   node tools/xsolla-catalog.mjs --apply  # 없는 항목만 생성 (이미 있는 항목은 건드리지 않음)
//
// 필요한 값은 저장소 루트의 .env에서 읽는다:
//   XSOLLA_MERCHANT_ID, XSOLLA_PROJECT_ID, XSOLLA_PROJECT_API_KEY
// 프록시 뒤에서 실행할 때는 NODE_USE_ENV_PROXY=1 을 붙인다 (Node 내장 fetch가 HTTPS_PROXY를 쓰도록).
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

const APPLY = process.argv.includes("--apply");

// ---- 카탈로그 정의 ----
const CASH = "cash";
// $1당 현지 가격 (환율 기준, ₩100 / ¥10 단위 버림). 모든 유료 상품이 같은 통화 목록을 써야 함
const LOCAL_PER_USD = [
  { currency: "KRW", country_iso: "KR", rate: 1400 },
  { currency: "JPY", country_iso: "JP", rate: 150 },
];
const USD = (amount) => [
  { currency: "USD", amount, is_default: true, is_enabled: true },
  ...LOCAL_PER_USD.map(({ currency, country_iso, rate }) => ({
    currency, country_iso, amount: Math.round(amount * rate), is_default: false, is_enabled: true,
  })),
];
const CASH_PRICE = (amount) => [{ sku: CASH, amount, is_default: true, is_enabled: true }];

const GROUPS = [
  { external_id: "cash_packs", name: { en: "Cash", ko: "캐시 충전" }, order: 1 },
  { external_id: "equipment_boxes", name: { en: "Equipment boxes", ko: "장비 상자" }, order: 2 },
];

// 1캐시 = $0.10. 패키지로만 팔기 때문에 캐시 자체는 상점에 노출하지 않음
const CURRENCY = {
  sku: CASH,
  name: { en: "Cash", ko: "캐시" },
  description: { en: "Premium currency for equipment boxes.", ko: "장비 상자를 살 때 쓰는 캐시예요." },
  prices: USD(0.1),
  is_enabled: true,
  is_show_in_store: false,
};

const PACKAGES = [10, 50, 100, 200].map((qty, i) => ({
  sku: `cash_${qty}`,
  name: { en: `${qty} Cash`, ko: `캐시 ${qty}개` },
  description: { en: `${qty} Cash for equipment boxes.`, ko: `장비 상자에 쓸 캐시 ${qty}개예요.` },  // API상 필수
  groups: ["cash_packs"],
  prices: USD(qty / 10),
  content: [{ sku: CASH, quantity: qty }],
  is_enabled: true,
  is_show_in_store: true,
  order: i + 1,
}));

// 1회 뽑기 = 10캐시, 묶음 할인 없음. 결과는 게임 안에서 주문번호를 시드로 결정
const BOXES = [1, 10, 50].map((draws, i) => ({
  sku: `box_equipment_${draws}`,
  name: { en: `Equipment Box x${draws}`, ko: `장비 상자 ${draws}회 뽑기` },
  description: {
    en: `${draws} random weapon or armor piece(s).`,
    ko: `무기 또는 방어구 ${draws}개가 무작위로 나와요.`,
  },
  groups: ["equipment_boxes"],
  virtual_item_type: "consumable",
  vc_prices: CASH_PRICE(draws * 10),
  is_paid_randomized_reward: true,
  is_enabled: true,
  is_show_in_store: true,
  order: i + 1,
  custom_attributes: { draws },
}));

// 패키지 보너스: +10% / +20% / +25%
const PROMOTIONS = [
  { pack: "cash_50", bonus: 5, pct: 10 },
  { pack: "cash_100", bonus: 20, pct: 20 },
  { pack: "cash_200", bonus: 50, pct: 25 },
].map(({ pack, bonus, pct }) => ({
  name: { "en-US": `${pack} +${pct}% bonus`, "ko-KR": `${pack} +${pct}% 보너스` },
  condition: [{ sku: pack }],
  bonus: [{ sku: CASH, quantity: bonus }],
  promotion_periods: [{ date_from: new Date().toISOString().replace(/\.\d+Z$/, "+00:00"), date_until: null }],
  is_enabled: true,
}));

// ---- API ----
function loadEnv() {
  const path = fileURLToPath(new URL("../.env", import.meta.url));
  const env = {};
  for (const line of readFileSync(path, "utf8").split("\n")) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
    if (m) env[m[1]] = m[2];
  }
  for (const k of ["XSOLLA_MERCHANT_ID", "XSOLLA_PROJECT_ID", "XSOLLA_PROJECT_API_KEY"])
    if (!env[k]) throw new Error(`.env에 ${k}가 없습니다`);
  return env;
}

const env = loadEnv();
const AUTH = "Basic " + Buffer.from(`${env.XSOLLA_MERCHANT_ID}:${env.XSOLLA_PROJECT_API_KEY}`).toString("base64");
const V2 = `https://store.xsolla.com/api/v2/project/${env.XSOLLA_PROJECT_ID}/admin`;
const V3 = `https://store.xsolla.com/api/v3/project/${env.XSOLLA_PROJECT_ID}/admin`;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Admin API는 호출 빈도 제한이 있어 간격을 두고, 429면 잠시 뒤 다시 시도
async function api(method, url, body) {
  for (let attempt = 0; ; attempt++) {
    await sleep(500);
    const res = await fetch(url, {
      method,
      headers: { Authorization: AUTH, ...(body ? { "Content-Type": "application/json" } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
    const text = await res.text();
    if (res.status === 429 && attempt < 4) { await sleep(2000 * 2 ** attempt); continue; }
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch { data = text; }
    return { status: res.status, data };
  }
}

const exists = async (url) => (await api("GET", url)).status === 200;

async function ensure(label, isPresent, create) {
  if (await isPresent()) { console.log(`  = ${label} (이미 있음)`); return; }
  if (!APPLY) { console.log(`  + ${label} (생성 예정)`); return; }
  const { status, data } = await create();
  if (status >= 200 && status < 300) console.log(`  ✓ ${label} 생성됨`);
  else { console.log(`  ✗ ${label} 실패 (${status})`, JSON.stringify(data)); process.exitCode = 1; }
}

// 이미 있는 항목은 가격이 정의와 다를 때만 정의 전체로 교체 (Xsolla 업데이트는 병합이 아니라 교체)
const priceKey = (prices) =>
  (prices || []).map((p) => `${p.currency}:${Number(p.amount)}:${p.country_iso || ""}:${!!p.is_default}`).sort().join(",");

async function ensurePriced(label, url, createUrl, def) {
  const { status, data } = await api("GET", url);
  if (status !== 200) return ensure(label, async () => false, () => api("POST", createUrl, def));
  if (priceKey(data.prices) === priceKey(def.prices)) { console.log(`  = ${label} (이미 있음)`); return; }
  if (!APPLY) { console.log(`  ~ ${label} (가격 갱신 예정)`); return; }
  const res = await api("PUT", url, def);
  if (res.status >= 200 && res.status < 300) console.log(`  ✓ ${label} 가격 갱신됨`);
  else { console.log(`  ✗ ${label} 가격 갱신 실패 (${res.status})`, JSON.stringify(res.data)); process.exitCode = 1; }
}

// ---- 실행 ----
console.log(APPLY ? "카탈로그 생성\n" : "확인만 합니다 (생성하려면 --apply)\n");

console.log("그룹");
const groups = (await api("GET", `${V2}/items/groups`)).data?.groups || [];
for (const g of GROUPS)
  await ensure(g.external_id, async () => groups.some((x) => x.external_id === g.external_id),
    () => api("POST", `${V2}/items/groups`, g));

console.log("가상 화폐");
await ensurePriced(CURRENCY.sku, `${V2}/items/virtual_currency/sku/${CURRENCY.sku}`,
  `${V2}/items/virtual_currency`, CURRENCY);

console.log("캐시 패키지");
for (const p of PACKAGES)
  await ensurePriced(`${p.sku}  ${p.prices.map((x) => `${x.amount} ${x.currency}`).join(" / ")}`,
    `${V2}/items/virtual_currency/package/sku/${p.sku}`, `${V2}/items/virtual_currency/package`, p);

console.log("장비 상자");
for (const b of BOXES)
  await ensure(`${b.sku}  ${b.vc_prices[0].amount}캐시`, () => exists(`${V2}/items/virtual_items/sku/${b.sku}`),
    () => api("POST", `${V2}/items/virtual_items`, b));

console.log("보너스 프로모션");
const listPromos = async () => {
  const d = (await api("GET", `${V3}/promotion/bonus?limit=100`)).data;
  return Array.isArray(d) ? d : d?.items || d?.promotions || [];
};
const findPromo = (list, p) => list.find((x) => x.name?.["en-US"] === p.name["en-US"] || x.name?.en === p.name["en-US"]);
const before = await listPromos();
for (const p of PROMOTIONS)
  await ensure(`${p.name["en-US"]}  (+${p.bonus[0].quantity}캐시)`, async () => !!findPromo(before, p),
    () => api("POST", `${V3}/promotion/bonus`, p));

// 생성 요청의 is_enabled는 반영되지 않아서, 꺼져 있으면 따로 활성화
const after = APPLY ? await listPromos() : before;
for (const p of PROMOTIONS) {
  const found = findPromo(after, p);
  if (!found || found.is_enabled) continue;
  if (!APPLY) { console.log(`  ! ${p.name["en-US"]} 꺼져 있음 (활성화 예정)`); continue; }
  const { status, data } = await api("PUT", `${V2}/promotion/${found.id}/activate`);
  if (status >= 200 && status < 300) console.log(`  ✓ ${p.name["en-US"]} 활성화됨`);
  else { console.log(`  ✗ ${p.name["en-US"]} 활성화 실패 (${status})`, JSON.stringify(data)); process.exitCode = 1; }
}
