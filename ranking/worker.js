// 던전 크롤러 랭킹 서버 (Cloudflare Workers + D1)
//
//   POST /api/score    내 기록 올리기 { stage, item: { rarity, enh, lvl, name } | null }
//   GET  /api/ranking  ?type=stage|item&limit=50  랭킹 (가린 이름만)
//   GET  /api/me       내 순위
//
// 누구인지는 서버가 확인한다.
//   로그인 사용자: Authorization: Bearer <Xsolla 사용자 토큰> → 서명과 Login 프로젝트를 검증
//   게스트:       X-Guest-Id: <게임이 만든 기기 ID> (검증 불가, 조작될 수 있음)
// 개인정보:
//   원본 이메일은 저장하지 않는다. 화면용 가린 이메일과 HMAC(이메일)만 저장한다.
//   개발자 계정(DEV_EMAILS 비밀값)은 저장하지 않고 랭킹에서 빠진다.
//
// 설정 (wrangler.toml / 비밀값)
//   LOGIN_PROJECT_ID   Xsolla Login 프로젝트 ID (토큰의 xsolla_login_project_id와 같아야 함)
//   ALLOWED_ORIGINS    게임 주소, 쉼표로 구분
//   HASH_KEY           (비밀) HMAC 키. 잃어버리면 같은 사람을 다시 찾을 수 없음
//   DEV_EMAILS         (비밀) 랭킹에서 뺄 이메일, 쉼표로 구분
//   JWT_SECRET         (비밀, 선택) Login 프로젝트가 HS256이면 그 비밀 키. 없으면 Xsolla에 직접 검증을 요청
//   JWKS_URL, XSOLLA_VALIDATE_URL  (선택) 테스트용으로 바꿀 때만

const RARITY_RANK = { common: 0, uncommon: 1, rare: 2, epic: 3, set: 4, legendary: 5 };
const MAX_STAGE = 10000;
const MAX_ENH = 10;

export default {
  async fetch(request, env) {
    const cors = corsHeaders(request, env);
    if (request.method === "OPTIONS") return new Response(null, { status: 204, headers: cors });
    try {
      const { pathname } = new URL(request.url);
      if (pathname === "/api/score" && request.method === "POST") return json(await submitScore(request, env), 200, cors);
      if (pathname === "/api/ranking" && request.method === "GET") return json(await getRanking(request, env), 200, cors);
      if (pathname === "/api/me" && request.method === "GET") return json(await getMe(request, env), 200, cors);
      return json({ error: "not_found" }, 404, cors);
    } catch (e) {
      if (e instanceof HttpError) return json({ error: e.code }, e.status, cors);
      console.error(e);
      return json({ error: "server_error" }, 500, cors);
    }
  },
};

class HttpError extends Error {
  constructor(status, code) { super(code); this.status = status; this.code = code; }
}

function json(body, status, headers) {
  return new Response(JSON.stringify(body), { status, headers: { ...headers, "Content-Type": "application/json; charset=utf-8" } });
}

function corsHeaders(request, env) {
  const origin = request.headers.get("Origin") || "";
  const allowed = (env.ALLOWED_ORIGINS || "").split(",").map(s => s.trim()).filter(Boolean);
  const h = {
    "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
    "Access-Control-Allow-Headers": "Authorization, Content-Type, X-Guest-Id",
    "Access-Control-Max-Age": "86400",
    "Vary": "Origin",
  };
  if (allowed.includes(origin)) h["Access-Control-Allow-Origin"] = origin;
  return h;
}

// ---------------------------------------------------------------- 누구인가

async function identify(request, env) {
  const auth = request.headers.get("Authorization") || "";
  if (auth.startsWith("Bearer ")) {
    const claims = await verifyXsollaToken(auth.slice(7).trim(), env);
    const email = typeof claims.email === "string" ? claims.email.trim().toLowerCase() : "";
    const phone = typeof claims.phone_number === "string" ? claims.phone_number : (typeof claims.phone === "string" ? claims.phone : "");
    const devs = (env.DEV_EMAILS || "").split(",").map(s => s.trim().toLowerCase()).filter(Boolean);
    return {
      kind: "user",
      key: await hmac(env, `u:${claims.sub}`),
      display: email ? maskEmail(email) : phone ? maskPhone(phone) : `사용자 #${String(claims.sub).slice(0, 6)}`,
      emailHmac: email ? await hmac(env, `e:${email}`) : null,
      developer: !!email && devs.includes(email),
    };
  }
  const guest = request.headers.get("X-Guest-Id") || "";
  if (/^[0-9a-zA-Z-]{8,64}$/.test(guest)) {
    return { kind: "guest", key: await hmac(env, `g:${guest}`), display: `게스트 #${guest.slice(0, 6)}`, emailHmac: null, developer: false };
  }
  throw new HttpError(401, "unauthorized");
}

// 이메일 가리기: 앞 몇 글자와 도메인만 남김. ab***@gmail.com
export function maskEmail(email) {
  const [local, domain = ""] = email.split("@");
  const keep = Math.min(3, Math.max(1, Math.floor(local.length / 3)));
  return `${local.slice(0, keep)}***@${domain}`;
}

// 휴대폰 번호 가리기: 마지막 4자리만. ***-5678
export function maskPhone(phone) {
  const digits = phone.replace(/\D/g, "");
  return `***-${digits.slice(-4)}`;
}

async function hmac(env, text) {
  if (!env.HASH_KEY) throw new Error("HASH_KEY 비밀값이 없습니다");
  const key = await crypto.subtle.importKey("raw", new TextEncoder().encode(env.HASH_KEY), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
  const sig = await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(text));
  return [...new Uint8Array(sig)].map(b => b.toString(16).padStart(2, "0")).join("");
}

// ---------------------------------------------------------------- Xsolla 토큰 검증

const b64urlToBytes = (s) => Uint8Array.from(atob(s.replace(/-/g, "+").replace(/_/g, "/")), c => c.charCodeAt(0));
const b64urlJson = (s) => JSON.parse(new TextDecoder().decode(b64urlToBytes(s)));

let jwksCache = { at: 0, keys: [] };
const validated = new Map();  // 토큰 해시 → 만료 시각 (Xsolla에 직접 검증한 결과를 재사용)

async function verifyXsollaToken(token, env) {
  const parts = token.split(".");
  if (parts.length !== 3) throw new HttpError(401, "bad_token");
  let header, claims;
  try { header = b64urlJson(parts[0]); claims = b64urlJson(parts[1]); } catch { throw new HttpError(401, "bad_token"); }

  const now = Math.floor(Date.now() / 1000);
  if (typeof claims.exp !== "number" || claims.exp <= now) throw new HttpError(401, "token_expired");
  if (!claims.sub) throw new HttpError(401, "bad_token");
  // 다른 Xsolla 프로젝트의 토큰은 받지 않음
  if (String(claims.xsolla_login_project_id || "") !== String(env.LOGIN_PROJECT_ID)) throw new HttpError(401, "wrong_project");

  const signed = new TextEncoder().encode(`${parts[0]}.${parts[1]}`);
  const sig = b64urlToBytes(parts[2]);

  if (header.alg === "RS256") {
    const jwk = (await getJwks(env)).find(k => k.kid === header.kid);
    if (!jwk) throw new HttpError(401, "unknown_key");
    const key = await crypto.subtle.importKey("jwk", jwk, { name: "RSASSA-PKCS1-v1_5", hash: "SHA-256" }, false, ["verify"]);
    if (!(await crypto.subtle.verify("RSASSA-PKCS1-v1_5", key, sig, signed))) throw new HttpError(401, "bad_signature");
    return claims;
  }

  if (header.alg === "HS256" && env.JWT_SECRET) {
    const key = await crypto.subtle.importKey("raw", new TextEncoder().encode(env.JWT_SECRET), { name: "HMAC", hash: "SHA-256" }, false, ["verify"]);
    if (!(await crypto.subtle.verify("HMAC", key, sig, signed))) throw new HttpError(401, "bad_signature");
    return claims;
  }

  // 서명 키가 없으면 Xsolla에 직접 확인 (요청 제한이 있어 결과를 토큰 만료까지 재사용)
  const tokenHash = await sha256(token);
  if ((validated.get(tokenHash) || 0) > now) return claims;
  const res = await fetch(env.XSOLLA_VALIDATE_URL || "https://login.xsolla.com/api/token/validate", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ token }),
  });
  if (!res.ok) throw new HttpError(401, "bad_signature");
  if (validated.size > 5000) validated.clear();
  validated.set(tokenHash, claims.exp);
  return claims;
}

async function getJwks(env) {
  if (Date.now() - jwksCache.at < 3600_000 && jwksCache.keys.length) return jwksCache.keys;
  const url = env.JWKS_URL || `https://login.xsolla.com/api/projects/${env.LOGIN_PROJECT_ID}/jwks.json`;
  const res = await fetch(url);
  if (!res.ok) throw new HttpError(503, "jwks_unavailable");
  const body = await res.json();
  jwksCache = { at: Date.now(), keys: Array.isArray(body.keys) ? body.keys : [] };
  return jwksCache.keys;
}

async function sha256(text) {
  const d = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text));
  return [...new Uint8Array(d)].map(b => b.toString(16).padStart(2, "0")).join("");
}

// ---------------------------------------------------------------- 기록 올리기

function readScore(body) {
  const stage = Number(body?.stage);
  if (!Number.isInteger(stage) || stage < 1 || stage > MAX_STAGE) throw new HttpError(400, "bad_stage");
  let item = null;
  if (body.item) {
    const { rarity, name } = body.item;
    const enh = Number(body.item.enh), lvl = Number(body.item.lvl);
    if (!(rarity in RARITY_RANK)) throw new HttpError(400, "bad_item");
    if (!Number.isInteger(enh) || enh < 0 || enh > MAX_ENH) throw new HttpError(400, "bad_item");
    // 아이템 레벨은 얻은 층이라 최고 층보다 높을 수 없음
    if (!Number.isInteger(lvl) || lvl < 1 || lvl > stage) throw new HttpError(400, "bad_item");
    const cleanName = String(name || "").replace(/[\u0000-\u001f<>]/g, "").slice(0, 40);
    item = { rarity, enh, lvl, name: cleanName, score: RARITY_RANK[rarity] * 100000 + enh * 1000 + lvl };
  }
  return { stage, item };
}

async function submitScore(request, env) {
  const who = await identify(request, env);
  if (who.developer) {
    // 개발자 계정은 저장하지 않음 (예전에 저장된 게 있으면 지움)
    await env.DB.prepare("DELETE FROM players WHERE key = ?1").bind(who.key).run();
    return { ok: true, ranked: false, reason: "developer" };
  }
  let body;
  try { body = await request.json(); } catch { throw new HttpError(400, "bad_json"); }
  const { stage, item } = readScore(body);
  const now = Date.now();
  // 최고 기록만 남김: 층은 더 높을 때, 아이템은 점수가 더 높을 때만 바꿈
  await env.DB.prepare(`
    INSERT INTO players (key, kind, display, email_hmac, best_stage, stage_at, item_score, item_rarity, item_enh, item_lvl, item_name, item_at, updated_at)
    VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?6)
    ON CONFLICT(key) DO UPDATE SET
      display = excluded.display,
      email_hmac = excluded.email_hmac,
      updated_at = excluded.updated_at,
      stage_at = CASE WHEN excluded.best_stage > players.best_stage THEN excluded.stage_at ELSE players.stage_at END,
      best_stage = MAX(players.best_stage, excluded.best_stage),
      item_at = CASE WHEN excluded.item_score > players.item_score THEN excluded.item_at ELSE players.item_at END,
      item_rarity = CASE WHEN excluded.item_score > players.item_score THEN excluded.item_rarity ELSE players.item_rarity END,
      item_enh = CASE WHEN excluded.item_score > players.item_score THEN excluded.item_enh ELSE players.item_enh END,
      item_lvl = CASE WHEN excluded.item_score > players.item_score THEN excluded.item_lvl ELSE players.item_lvl END,
      item_name = CASE WHEN excluded.item_score > players.item_score THEN excluded.item_name ELSE players.item_name END,
      item_score = MAX(players.item_score, excluded.item_score)
  `).bind(
    who.key, who.kind, who.display, who.emailHmac, stage, now,
    item ? item.score : -1, item?.rarity ?? null, item?.enh ?? null, item?.lvl ?? null, item?.name ?? null, item ? now : null,
  ).run();
  return { ok: true, ranked: true };
}

// ---------------------------------------------------------------- 랭킹

const BOARDS = {
  stage: { where: "best_stage > 0", order: "best_stage DESC, stage_at ASC" },
  item: { where: "item_score >= 0", order: "item_score DESC, item_at ASC" },
};

function row(r, rank) {
  return {
    rank,
    name: r.display,
    guest: r.kind === "guest",
    stage: r.best_stage,
    item: r.item_score >= 0 ? { rarity: r.item_rarity, enh: r.item_enh, lvl: r.item_lvl, name: r.item_name } : null,
  };
}

async function getRanking(request, env) {
  const url = new URL(request.url);
  const type = url.searchParams.get("type") === "item" ? "item" : "stage";
  const limit = Math.min(100, Math.max(1, Number(url.searchParams.get("limit")) || 50));
  const b = BOARDS[type];
  const { results } = await env.DB.prepare(
    `SELECT display, kind, best_stage, item_score, item_rarity, item_enh, item_lvl, item_name FROM players WHERE ${b.where} ORDER BY ${b.order} LIMIT ?1`,
  ).bind(limit).all();
  const total = (await env.DB.prepare(`SELECT COUNT(*) AS n FROM players WHERE ${b.where}`).first()).n;
  return { type, total, updatedAt: Date.now(), rows: results.map((r, i) => row(r, i + 1)) };
}

async function getMe(request, env) {
  const who = await identify(request, env);
  if (who.developer) return { developer: true };
  const r = await env.DB.prepare("SELECT * FROM players WHERE key = ?1").bind(who.key).first();
  if (!r) return { name: who.display, stageRank: null, itemRank: null };
  const ahead = async (sql, ...args) => (await env.DB.prepare(sql).bind(...args).first()).n;
  const stageRank = r.best_stage > 0
    ? 1 + await ahead("SELECT COUNT(*) AS n FROM players WHERE best_stage > ?1 OR (best_stage = ?1 AND stage_at < ?2)", r.best_stage, r.stage_at)
    : null;
  const itemRank = r.item_score >= 0
    ? 1 + await ahead("SELECT COUNT(*) AS n FROM players WHERE item_score > ?1 OR (item_score = ?1 AND item_at < ?2)", r.item_score, r.item_at)
    : null;
  return { ...row(r, null), stageRank, itemRank };
}
