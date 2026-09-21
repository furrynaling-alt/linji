const http = require('http');
const fs = require('fs');
const path = require('path');

const DIR = process.env.LINJI_DIR || '/opt/linji-bridge';
const TOKEN_FILE = path.join(DIR, 'token.txt');
const STATE_FILE = path.join(DIR, 'state.json');
const CMDS_FILE = path.join(DIR, 'cmds.json');
const ACKS_FILE = path.join(DIR, 'acks.json');
const PORT = Number(process.env.LINJI_PORT || 16632);

function readJson(f, d) {
  try { return JSON.parse(fs.readFileSync(f, 'utf8')); } catch (e) { return d; }
}
function writeJson(f, v) {
  const t = f + '.tmp';
  fs.writeFileSync(t, JSON.stringify(v));
  fs.renameSync(t, f);
}
function token() {
  try { return fs.readFileSync(TOKEN_FILE, 'utf8').trim(); } catch (e) { return ''; }
}
function fmt(ms) {
  if (!ms) return '-';
  const d = new Date(Number(ms));
  const p = n => String(n).padStart(2, '0');
  return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
}

const server = http.createServer((req, res) => {
  const u = new URL(req.url, 'http://127.0.0.1');
  const ip = req.socket.remoteAddress || '';
  const local = ip === '127.0.0.1' || ip === '::1' || ip === '::ffff:127.0.0.1';

  if (req.method === 'GET' && (u.pathname === '/status' || u.pathname === '/sleep')) {
    if (!local) { res.writeHead(403); return res.end('local only\n'); }
    const st = readJson(STATE_FILE, null);
    if (!st) { res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' }); return res.end('{}'); }
    if (u.pathname === '/sleep') {
      const s = (st.status && st.status.sleeps) || [];
      const lines = s.map(x => fmt(x.start) + ' → ' + fmt(x.end) + '  ' + Math.round(x.min) + '分' +
        (x.broke ? ' (提前解锁)' : '') + (x.auto ? ' (自动补记)' : ''));
      const l = st.status || {};
      const head = [
        '设备: ' + st.device + '  版本: ' + st.version,
        '上报: ' + st.ts_str + '  (' + Math.round((Date.now() - st.ts) / 60000) + ' 分钟前)',
        '电池: ' + l.battery + '%' + (l.charging ? ' 充电中' : ''),
        '锁机开关: ' + (l.lock_on ? '开' : '关'),
        '上次一觉: ' + (l.last_min != null ? Math.round(l.last_min) + ' 分 (' + fmt(l.last_start) + ' → ' + fmt(l.last_end) + ')' : '无记录'),
        '近7次平均: ' + (l.sleep_avg7 || 0) + ' 分',
        '现在在睡? ' + (l.sleep_start ? '是, 入睡 ' + fmt(l.sleep_start) : '否'),
        '--- 最近记录 ---'
      ];
      res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' });
      return res.end(head.join('\n') + '\n' + lines.join('\n') + '\n');
    }
    res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
    return res.end(JSON.stringify(st));
  }

  if (req.method === 'GET' && u.pathname === '/cmd') {
    if (!local) { res.writeHead(403); return res.end('local only\n'); }
    const cmd = u.searchParams.get('cmd') || 'ping';
    const list = readJson(CMDS_FILE, []);
    const id = 'c' + Date.now() + '' + Math.floor(Math.random() * 1000);
    const item = { id: id, cmd: cmd, at: Date.now(), delivered: null };
    if (cmd === 'notify') { item.title = u.searchParams.get('title') || '棂记'; item.text = u.searchParams.get('text') || ''; }
    if (cmd === 'toast') { item.text = u.searchParams.get('text') || ''; }
    if (cmd === 'get') { item.what = u.searchParams.get('what') || 'status'; }
    if (cmd === 'flag') { item.key = u.searchParams.get('key') || ''; item.value = (u.searchParams.get('value') || 'true') === 'true'; }
    list.push(item);
    while (list.length > 50) list.shift();
    writeJson(CMDS_FILE, list);
    res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' });
    return res.end('queued ' + id + '\n');
  }

  if (req.method === 'GET' && u.pathname === '/acks') {
    if (!local) { res.writeHead(403); return res.end('local only\n'); }
    res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
    return res.end(JSON.stringify(readJson(ACKS_FILE, [])));
  }

  if (req.method === 'POST' && u.pathname === '/cmd') {
    if (!local) { res.writeHead(403); return res.end('local only\n'); }
    let cb = '';
    req.on('data', c => { cb += c; });
    req.on('end', () => {
      let o = {};
      try { o = JSON.parse(cb || '{}'); } catch (e) { o = {}; }
      if (!o.cmd) { res.writeHead(400); return res.end('need cmd\n'); }
      const list = readJson(CMDS_FILE, []);
      const item = Object.assign({ id: 'c' + Date.now(), at: Date.now(), delivered: null }, o);
      list.push(item);
      while (list.length > 50) list.shift();
      writeJson(CMDS_FILE, list);
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify({ queued: item.id, cmd: item.cmd }));
    });
    return;
  }

  if (req.method === 'POST') {
    const want = token();
    const got = (req.headers['x-linji-token'] || '').trim();
    if (u.pathname !== '/linji/bridge.php' && u.pathname !== '/linji/bridge' && u.pathname !== '/') {
      res.writeHead(404); return res.end('no\n');
    }
    if (!want || got !== want) {
      res.writeHead(401, { 'Content-Type': 'application/json; charset=utf-8' });
      try { fs.appendFileSync(path.join(DIR, 'reject.log'), new Date().toISOString() + ' ' + ip + ' bad-token\n'); } catch (e) {}
      return res.end('{"error":"bad token"}');
    }
    let body = '';
    req.on('data', c => { body += c; if (body.length > 262144) req.destroy(); });
    req.on('end', () => {
      let up = {};
      try { up = JSON.parse(body || '{}'); } catch (e) { up = {}; }
      const now = Date.now();
      const st = {
        ts: now, ts_str: fmt(now), device: up.device || '?', version: up.version || '?',
        v: up.v || 1, status: up.status || {}
      };
      writeJson(STATE_FILE, st);
      const acks = readJson(ACKS_FILE, []);
      const incoming = Array.isArray(up.acks) ? up.acks : [];
      for (const a of incoming) acks.push({ at: now, id: a.id, ok: a.ok, msg: a.msg });
      while (acks.length > 200) acks.shift();
      writeJson(ACKS_FILE, acks);

      const list = readJson(CMDS_FILE, []);
      const out = [];
      for (const c of list) {
        if (!c.delivered) {
          const o = { id: c.id, cmd: c.cmd };
          if (c.title !== undefined) o.title = c.title;
          if (c.text !== undefined) o.text = c.text;
          if (c.what !== undefined) o.what = c.what;
          if (c.key !== undefined) o.key = c.key;
          if (c.value !== undefined) o.value = c.value;
          out.push(o);
          c.delivered = now;
        }
      }
      if (out.length) writeJson(CMDS_FILE, list);
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify({ cmds: out }));
    });
    return;
  }

  res.writeHead(404);
  res.end('no\n');
});

server.listen(PORT, '127.0.0.1', () => console.log('linji-bridge on ' + PORT));
