// 긴급 확인용: 이메일(또는 게스트 기기 ID)로 랭킹 DB의 행을 찾는 SQL을 만든다.
// DB에는 원본 이메일이 없고 HMAC만 있으므로, HASH_KEY를 가진 사람만 찾을 수 있다.
//
//   RANKING_HASH_KEY=... node tools/ranking-lookup.mjs someone@example.com
//   RANKING_HASH_KEY=... node tools/ranking-lookup.mjs --guest <기기 ID>
//
// HASH_KEY는 .env 의 RANKING_HASH_KEY 에서도 읽는다 (.env 는 커밋되지 않음).
// 출력된 명령을 ranking/ 폴더에서 실행하면 실제 DB를 조회한다.
import { createHmac } from "node:crypto";
import { readFileSync, existsSync } from "node:fs";

function hashKey() {
  if (process.env.RANKING_HASH_KEY) return process.env.RANKING_HASH_KEY;
  const envFile = new URL("../.env", import.meta.url);
  if (existsSync(envFile)) {
    const m = readFileSync(envFile, "utf8").match(/^RANKING_HASH_KEY=(.*)$/m);
    if (m) return m[1].trim().replace(/^["']|["']$/g, "");
  }
  console.error("RANKING_HASH_KEY가 없습니다 (환경변수 또는 .env). Worker의 HASH_KEY와 같은 값이어야 합니다.");
  process.exit(1);
}

const args = process.argv.slice(2);
const guest = args[0] === "--guest";
const value = guest ? args[1] : args[0];
if (!value) {
  console.error("사용법: node tools/ranking-lookup.mjs <이메일> | --guest <기기 ID>");
  process.exit(1);
}
const hmac = (text) => createHmac("sha256", hashKey()).update(text).digest("hex");
const sql = guest
  ? `SELECT * FROM players WHERE key = '${hmac(`g:${value}`)}'`
  : `SELECT * FROM players WHERE email_hmac = '${hmac(`e:${value.trim().toLowerCase()}`)}'`;
console.log(sql);
console.log(`\nnpx wrangler d1 execute dungeon-ranking --remote --command "${sql}"`);
