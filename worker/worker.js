/**
 * Farsanime media proxy — Cloudflare Worker v2
 *
 * Usage:
 *   GET /?url=<encoded-target>&range=bytes%3D0-1023
 *   GET /?url=<encoded-target>&range=0-1023
 *   GET /?url=<encoded-target>
 *
 * Environment:
 *   PROXY_KEY             required, sent as x-proxy-key
 *   ALLOWED_HOSTS         optional, default: farsanime.ir,di.farsanime.ir
 *   ALLOWED_CLIENT_IPS    optional, empty = allow every client IP
 *
 * The Worker never buffers the media body. It returns upstream status,
 * headers and stream unchanged, with no-store cache headers.
 */

const DEFAULT_ALLOWED_HOSTS = ['farsanime.ir', 'di.farsanime.ir'];

const HOP_BY_HOP = new Set([
  'connection',
  'keep-alive',
  'proxy-authenticate',
  'proxy-authorization',
  'te',
  'trailer',
  'transfer-encoding',
  'upgrade',
]);

export default {
  async fetch(request, env) {
    if (request.method === 'OPTIONS') {
      return corsPreflight();
    }

    if (request.method !== 'GET' && request.method !== 'HEAD') {
      return json({ error: 'method_not_allowed' }, 405, {
        allow: 'GET, HEAD, OPTIONS',
      });
    }

    if (!env.PROXY_KEY) {
      return json({ error: 'server_misconfigured' }, 500);
    }

    const suppliedKey = request.headers.get('x-proxy-key') || '';
    if (!safeEqual(suppliedKey, env.PROXY_KEY)) {
      return json({ error: 'forbidden' }, 403);
    }

    const allowedIps = parseList(env.ALLOWED_CLIENT_IPS);
    if (allowedIps.length > 0) {
      const clientIp =
        request.headers.get('cf-connecting-ip') ||
        request.headers.get('x-real-ip') ||
        '';

      if (!allowedIps.includes(clientIp)) {
        return json({ error: 'forbidden_ip' }, 403);
      }
    }

    const workerUrl = new URL(request.url);
    const targetRaw = workerUrl.searchParams.get('url');

    if (!targetRaw) {
      return json({ error: 'missing_url' }, 400);
    }

    if (targetRaw.length > 8192) {
      return json({ error: 'url_too_long' }, 400);
    }

    let target;
    try {
      target = new URL(targetRaw);
    } catch {
      return json({ error: 'invalid_url' }, 400);
    }

    if (target.protocol !== 'https:') {
      return json({ error: 'https_required' }, 400);
    }

    if (target.username || target.password) {
      return json({ error: 'credentials_not_allowed' }, 400);
    }

    if (target.port && target.port !== '443') {
      return json({ error: 'port_not_allowed' }, 400);
    }

    if (!isAllowedHost(target.hostname, env)) {
      return json({ error: 'host_not_allowed' }, 403);
    }

    const explicitRange = workerUrl.searchParams.get('range');
    const range = normalizeRange(
      explicitRange || request.headers.get('range') || ''
    );

    if (explicitRange !== null && !range) {
      return json({ error: 'invalid_range' }, 400);
    }

    const upstreamHeaders = new Headers();

    if (range) {
      upstreamHeaders.set('range', range);
    }

    upstreamHeaders.set('accept-encoding', 'identity');
    upstreamHeaders.set('accept', '*/*');
    upstreamHeaders.set(
      'user-agent',
      request.headers.get('user-agent') ||
        'Mozilla/5.0 (compatible; FlixyMediaRelay/2.0)'
    );
    upstreamHeaders.set('referer', 'https://farsanime.ir/');

    let upstream;
    try {
      upstream = await fetchFollowing(
        target,
        upstreamHeaders,
        request.method,
        env
      );
    } catch {
      return json({ error: 'upstream_fetch_failed' }, 502);
    }

    const responseHeaders = new Headers();

    for (const [name, value] of upstream.headers) {
      const lower = name.toLowerCase();

      if (HOP_BY_HOP.has(lower) || lower === 'set-cookie') {
        continue;
      }

      responseHeaders.set(name, value);
    }

    responseHeaders.set('cache-control', 'no-store');
    responseHeaders.set('cdn-cache-control', 'no-store');
    responseHeaders.set('x-proxy-host', target.hostname);
    responseHeaders.set('x-proxy-range', range || 'none');
    responseHeaders.set(
      'x-proxy-upstream-status',
      String(upstream.status)
    );
    responseHeaders.set('access-control-allow-origin', '*');
    responseHeaders.set(
      'access-control-expose-headers',
      'Content-Length, Content-Range, Accept-Ranges, ETag, Last-Modified, Content-Disposition, X-Proxy-Host, X-Proxy-Range, X-Proxy-Upstream-Status'
    );

    if (range && upstream.status === 206) {
      responseHeaders.set('x-proxy-range-ok', '1');
    } else if (range && upstream.status === 200) {
      responseHeaders.set('x-proxy-range-ignored', '1');
    }

    const body =
      request.method === 'HEAD' ||
      upstream.status === 204 ||
      upstream.status === 304
        ? null
        : upstream.body;

    return new Response(body, {
      status: upstream.status,
      statusText: upstream.statusText,
      headers: responseHeaders,
    });
  },
};

async function fetchFollowing(startUrl, headers, method, env) {
  let current = startUrl;

  for (let attempt = 0; attempt < 4; attempt++) {
    const response = await fetch(current.toString(), {
      method,
      headers,
      redirect: 'manual',
      cf: { cacheEverything: false },
    });

    if (response.status < 300 || response.status >= 400) {
      return response;
    }

    const location = response.headers.get('location');

    if (!location) {
      return response;
    }

    const next = new URL(location, current);

    if (
      next.protocol !== 'https:' ||
      !isAllowedHost(next.hostname, env)
    ) {
      throw new Error('redirect_not_allowed');
    }

    if (response.body) {
      try {
        await response.body.cancel();
      } catch {
        // already consumed or unavailable
      }
    }

    current = next;
  }

  throw new Error('too_many_redirects');
}

function normalizeRange(value) {
  const raw = String(value || '').trim();

  if (!raw || raw.length > 64) {
    return null;
  }

  const match = /^(?:bytes=)?(\d{0,18})-(\d{0,18})$/.exec(raw);

  if (!match) {
    return null;
  }

  const start = match[1];
  const end = match[2];

  if (start === '' && end === '') {
    return null;
  }

  if (
    start !== '' &&
    end !== '' &&
    Number(start) > Number(end)
  ) {
    return null;
  }

  return `bytes=${start}-${end}`;
}

function isAllowedHost(hostname, env) {
  const configured = parseList(env.ALLOWED_HOSTS);
  const allowed =
    configured.length > 0
      ? configured
      : DEFAULT_ALLOWED_HOSTS;

  const host = String(hostname || '').toLowerCase();

  return allowed.some(
    (entry) =>
      host === entry || host.endsWith('.' + entry)
  );
}

function parseList(value) {
  return String(value || '')
    .split(',')
    .map((item) => item.trim().toLowerCase())
    .filter(Boolean);
}

function safeEqual(a, b) {
  const left = String(a);
  const right = String(b);

  if (left.length !== right.length) {
    return false;
  }

  let diff = 0;

  for (let i = 0; i < left.length; i++) {
    diff |= left.charCodeAt(i) ^ right.charCodeAt(i);
  }

  return diff === 0;
}

function json(payload, status, extra = {}) {
  return new Response(JSON.stringify(payload), {
    status,
    headers: {
      'content-type': 'application/json; charset=utf-8',
      'cache-control': 'no-store',
      'access-control-allow-origin': '*',
      ...extra,
    },
  });
}

function corsPreflight() {
  return new Response(null, {
    status: 204,
    headers: {
      'access-control-allow-origin': '*',
      'access-control-allow-methods': 'GET, HEAD, OPTIONS',
      'access-control-allow-headers':
        'x-proxy-key, range, content-type',
      'access-control-max-age': '86400',
    },
  });
}