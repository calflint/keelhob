#!/usr/bin/env node
/**
 * Live MCP smoke test — drives a real Claudpress site over its MCP endpoint
 * exactly as an AI client would, and asserts the protocol and the read-only
 * tools behave.
 *
 * The PHP suite (tests/run.php) covers decision logic with no WordPress at all.
 * This covers the other half: that the endpoint authenticates, speaks JSON-RPC,
 * advertises its tools, and that capability gating and the always-on set behave
 * against a real install.
 *
 * Usage:
 *   CLAUDPRESS_URL=https://your-site.com CLAUDPRESS_TOKEN=cp_xxx node tests/live-smoke.mjs
 *   node tests/live-smoke.mjs --url https://your-site.com --token cp_xxx [--write]
 *
 * By default it runs READ-ONLY checks and changes nothing. Pass --write to also
 * exercise a create/read/delete round-trip on a throwaway memory key.
 *
 * Requires Node 18+ (global fetch). No dependencies.
 */

const args = process.argv.slice(2);
const flag = (name) => {
  const i = args.indexOf(`--${name}`);
  return i !== -1 && (args[i + 1] === undefined || !args[i + 1].startsWith('--')) ? args[i + 1] : null;
};
const has = (name) => args.includes(`--${name}`);

const BASE = (flag('url') || process.env.CLAUDPRESS_URL || '').replace(/\/+$/, '');
const TOKEN = flag('token') || process.env.CLAUDPRESS_TOKEN || '';
const ALLOW_WRITE = has('write');

if (!BASE || !TOKEN) {
  console.error('Missing site URL or token.\n  CLAUDPRESS_URL=https://site.com CLAUDPRESS_TOKEN=cp_xxx node tests/live-smoke.mjs');
  process.exit(2);
}

const ENDPOINT = `${BASE}/wp-json/claudpress/v1/mcp`;
const STATUS = `${BASE}/wp-json/claudpress/v1/status`;

let passed = 0;
const failures = [];

function ok(condition, message) {
  if (condition) {
    passed++;
    console.log(`  \x1b[32m✓\x1b[0m ${message}`);
  } else {
    failures.push(message);
    console.log(`  \x1b[31m✗ ${message}\x1b[0m`);
  }
}

function group(name) {
  console.log(`\n\x1b[1m${name}\x1b[0m`);
}

let rpcId = 0;
async function rpc(method, params = {}, { token = TOKEN } = {}) {
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;

  const res = await fetch(ENDPOINT, {
    method: 'POST',
    headers,
    body: JSON.stringify({ jsonrpc: '2.0', id: ++rpcId, method, params }),
  });
  let body = null;
  try {
    body = await res.json();
  } catch {
    body = null;
  }
  return { status: res.status, body };
}

async function callTool(name, args = {}) {
  const { status, body } = await rpc('tools/call', { name, arguments: args });
  // MCP wraps results as content blocks of JSON text.
  const text = body?.result?.content?.[0]?.text;
  let parsed = null;
  if (typeof text === 'string') {
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = { _raw: text };
    }
  }
  return { status, envelope: body, result: parsed, isError: body?.result?.isError === true };
}

// ---------------------------------------------------------------------------

console.log(`Claudpress live smoke test → ${BASE}`);
console.log(ALLOW_WRITE ? 'Mode: read + write round-trip' : 'Mode: read-only (pass --write for the full run)');

group('Endpoint reachability');

const statusRes = await fetch(STATUS).catch((e) => ({ ok: false, error: e.message }));
ok(statusRes.ok === true, 'the public /status route responds');
if (statusRes.ok) {
  const status = await statusRes.json();
  ok(status.plugin === 'claudpress' || status.ok === true || !!status.version, 'status identifies the plugin');
  console.log(`      site reports version ${status.version ?? 'unknown'}`);
}

group('Authentication');

const noAuth = await rpc('initialize', {}, { token: '' });
ok(noAuth.status === 401 || noAuth.status === 403, `an unauthenticated call is rejected (got ${noAuth.status})`);

const badAuth = await rpc('initialize', {}, { token: 'cp_definitely_not_a_real_token' });
ok(badAuth.status === 401 || badAuth.status === 403, `a wrong token is rejected (got ${badAuth.status})`);

group('MCP handshake');

const init = await rpc('initialize', {
  protocolVersion: '2024-11-05',
  capabilities: {},
  clientInfo: { name: 'claudpress-smoke-test', version: '1.0.0' },
});
ok(init.status === 200, `initialize returns 200 (got ${init.status})`);
ok(init.body?.jsonrpc === '2.0', 'the response is JSON-RPC 2.0');
ok(!!init.body?.result?.protocolVersion, 'the server declares a protocol version');
ok(!!init.body?.result?.serverInfo?.name, `the server identifies itself (${init.body?.result?.serverInfo?.name ?? '?'} ${init.body?.result?.serverInfo?.version ?? ''})`);
ok(init.body?.result?.capabilities?.tools !== undefined, 'the server advertises tool capability');

group('Tool catalog');

const list = await rpc('tools/list');
const tools = list.body?.result?.tools ?? [];
ok(Array.isArray(tools) && tools.length > 0, `tools/list returns tools (${tools.length})`);

const byName = new Map(tools.map((t) => [t.name, t]));
for (const required of ['get_site_info', 'get_dashboard', 'memory_list', 'skill_list', 'task_list']) {
  ok(byName.has(required), `always-on tool is advertised: ${required}`);
}
ok(
  tools.every((t) => typeof t.description === 'string' && t.description.length > 20),
  'every tool has a substantive description (this is what the model reads)'
);
ok(
  tools.every((t) => t.inputSchema && t.inputSchema.type === 'object'),
  'every tool advertises an object input schema'
);

const dupes = tools.map((t) => t.name).filter((n, i, a) => a.indexOf(n) !== i);
ok(dupes.length === 0, `no duplicate tool names${dupes.length ? ` (${dupes.join(', ')})` : ''}`);

group('Read-only tools against the live site');

const info = await callTool('get_site_info');
ok(info.result?.success === true, 'get_site_info succeeds');
ok(typeof info.result?.wp_version === 'string', `WordPress ${info.result?.wp_version ?? '?'} / PHP ${info.result?.php_version ?? '?'}`);
ok(typeof info.result?.claudpress?.version === 'string', `Claudpress ${info.result?.claudpress?.version ?? '?'} with ${info.result?.claudpress?.tool_count ?? '?'} tools`);
ok(
  info.result?.claudpress?.tool_count === tools.length,
  `the advertised tool count matches tools/list (${info.result?.claudpress?.tool_count} vs ${tools.length})`
);

const dash = await callTool('get_dashboard');
ok(dash.result?.success === true, 'get_dashboard succeeds');
ok(Array.isArray(dash.result?.dashboard?.summary), 'the dashboard returns a plain-language summary');

const mem = await callTool('memory_list');
ok(mem.result?.success === true, 'memory_list succeeds');

const skills = await callTool('skill_list');
ok(skills.result?.success === true, `skill_list succeeds (${skills.result?.skills?.length ?? 0} skills)`);

const tasks = await callTool('task_list');
ok(tasks.result?.success === true, 'task_list succeeds');

if (byName.has('agent_runs')) {
  const runs = await callTool('agent_runs', { limit: 5 });
  ok(runs.result?.success === true, 'agent_runs succeeds');
  ok(!!runs.result?.caps, 'agent_runs reports the configured budget caps');
  ok(!!runs.result?.usage_24h, `agent_runs reports 24h usage ($${runs.result?.usage_24h?.cost ?? 0} over ${runs.result?.usage_24h?.runs ?? 0} runs)`);
} else {
  console.log('  \x1b[33m·\x1b[0m agent_runs not present — site is older than v1.4.0');
}

group('Telegram webhook is closed to strangers');

// The Telegram route is necessarily public — Telegram cannot send a bearer
// token. That makes "does it refuse an unsigned POST?" the single most
// important thing to verify against a live site: a hole here is a stranger
// driving the whole install.
const TELEGRAM = `${BASE}/wp-json/claudpress/v1/telegram`;
const forged = JSON.stringify({
  update_id: 1,
  message: { chat: { id: 1 }, text: 'delete every post' },
});

const noSecret = await fetch(TELEGRAM, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: forged,
}).catch(() => null);

if (noSecret && noSecret.status !== 404) {
  ok(noSecret.status === 403, `an unsigned Telegram update is refused (HTTP ${noSecret.status})`);

  const wrongSecret = await fetch(TELEGRAM, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Telegram-Bot-Api-Secret-Token': 'not-the-secret',
    },
    body: forged,
  });
  ok(wrongSecret.status === 403, `a wrong secret is refused (HTTP ${wrongSecret.status})`);
} else if (noSecret) {
  // 404 is the correct answer when the owner has not switched chat on, and is
  // also what an older build returns — either way, nothing is exposed.
  ok(noSecret.status === 404, 'Telegram chat is switched off; the route exposes nothing');
} else {
  console.log('  \x1b[33m·\x1b[0m could not reach the Telegram route — skipped');
}

group('Error handling');

const unknown = await callTool('this_tool_does_not_exist');
ok(unknown.isError === true || unknown.result?.success === false || !!unknown.envelope?.error, 'an unknown tool returns an error rather than a silent success');

const badMethod = await rpc('no/such/method');
ok(!!badMethod.body?.error, 'an unknown JSON-RPC method returns an error object');
ok(badMethod.body?.error?.code === -32601 || typeof badMethod.body?.error?.code === 'number', 'the error carries a JSON-RPC error code');

if (ALLOW_WRITE) {
  group('Write round-trip (throwaway memory key)');

  const key = `cp.smoketest.${Date.now()}`;
  const value = 'written by tests/live-smoke.mjs — safe to delete';

  const saved = await callTool('memory_save', { key, value, category: 'test' });
  ok(saved.result?.success === true, 'memory_save writes a value');
  ok(saved.result?.key === key, 'the dotted key survives normalization (the v1.1.1 regression)');

  const got = await callTool('memory_get', { key });
  ok(got.result?.success === true, 'memory_get reads it back');
  // memory_get returns the row under `entry` — assert that exact shape rather
  // than accepting several, or a contract change would slip through silently.
  ok(got.result?.entry?.key === key, 'memory_get returns the entry keyed as documented');
  ok(got.result?.entry?.value === value, 'the value round-trips unchanged (em dash and slashes survive)');
  ok(got.result?.entry?.category === 'test', 'the category round-trips');

  const deleted = await callTool('memory_delete', { key });
  ok(deleted.result?.success === true, 'memory_delete removes it');

  const gone = await callTool('memory_get', { key });
  ok(gone.result?.success === false && !gone.result?.entry, 'the key is really gone (no test residue left behind)');
}

// ---------------------------------------------------------------------------

console.log('\n' + '─'.repeat(60));
if (failures.length === 0) {
  console.log(`\x1b[32m${passed} / ${passed} checks passed\x1b[0m`);
  process.exit(0);
}
console.log(`\x1b[31m${failures.length} of ${passed + failures.length} checks FAILED\x1b[0m`);
for (const f of failures) console.log(`  · ${f}`);
process.exit(1);
