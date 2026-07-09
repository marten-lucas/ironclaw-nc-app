#!/usr/bin/env node

import crypto from 'node:crypto';
import http from 'node:http';

const port = Number(process.env.PORT || '8788');
const sharedSecret = process.env.IRONCLAW_SHARED_SECRET || 'dev-secret';
const toleranceSeconds = Number(process.env.TOLERANCE_SECONDS || '300');
const seen = new Map();

function cleanup(nowEpoch) {
  for (const [nonce, ts] of seen.entries()) {
    if (Math.abs(nowEpoch - ts) > toleranceSeconds) {
      seen.delete(nonce);
    }
  }
}

function normalizeHex(value) {
  const raw = String(value || '').trim().toLowerCase().replace(/^sha256=/, '');
  if (!/^[a-f0-9]{64}$/.test(raw)) {
    return null;
  }
  return raw;
}

function verify(reqHeaders, rawBody) {
  const tsRaw = reqHeaders['x-ironclaw-timestamp'];
  const nonce = String(reqHeaders['x-ironclaw-nonce'] || '').trim();
  const signature = normalizeHex(reqHeaders['x-ironclaw-signature']);

  if (!tsRaw || !nonce || !signature) {
    return { ok: false, code: 401, error: 'missing_auth_headers' };
  }

  const timestamp = Number(tsRaw);
  if (!Number.isInteger(timestamp)) {
    return { ok: false, code: 401, error: 'invalid_timestamp' };
  }

  const now = Math.floor(Date.now() / 1000);
  if (Math.abs(now - timestamp) > toleranceSeconds) {
    return { ok: false, code: 401, error: 'stale_timestamp' };
  }

  if (nonce.length < 8 || nonce.length > 128) {
    return { ok: false, code: 401, error: 'invalid_nonce' };
  }

  cleanup(now);
  if (seen.has(nonce)) {
    return { ok: false, code: 409, error: 'replay_detected' };
  }

  const base = `${timestamp}\n${nonce}\n${rawBody}`;
  const expected = crypto.createHmac('sha256', sharedSecret).update(base).digest('hex');
  if (!crypto.timingSafeEqual(Buffer.from(expected, 'utf8'), Buffer.from(signature, 'utf8'))) {
    return { ok: false, code: 401, error: 'invalid_signature' };
  }

  seen.set(nonce, timestamp);
  return { ok: true, code: 200, error: null };
}

const server = http.createServer((req, res) => {
  if (req.method !== 'POST' || req.url !== '/api/channels/nextcloud/inbound') {
    res.writeHead(404, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ error: 'not_found' }));
    return;
  }

  let raw = '';
  req.setEncoding('utf8');
  req.on('data', (chunk) => {
    raw += chunk;
  });

  req.on('end', () => {
    const result = verify(req.headers, raw);
    if (!result.ok) {
      res.writeHead(result.code, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ error: result.error }));
      return;
    }

    let payload;
    try {
      payload = JSON.parse(raw);
    } catch {
      res.writeHead(400, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ error: 'invalid_json' }));
      return;
    }

    res.writeHead(202, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ status: 'accepted', eventId: payload.eventId || null }));
  });
});

server.listen(port, () => {
  process.stdout.write(`mock-ironclaw-server listening on :${port}\n`);
});
