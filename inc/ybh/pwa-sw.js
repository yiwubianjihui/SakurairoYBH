/**
 * YBH · Service Worker（T48 P1+P2）
 *
 * 这个文件由 `inc/ybh/pwa.php` 以 `/sw.js`（网站根作用域）输出，
 * 输出时会把下面两个占位符替换掉：
 *   __YBH_PWA_VERSION__ → SW 源文件的 filemtime（缓存名随文件变化，避免"改了没生效"）
 *   __YBH_PWA_SCOPE__   → 站点根 URL
 *
 * ## 缓存策略（刻意保守）
 *
 * | 类型 | 策略 | 理由 |
 * |---|---|---|
 * | **字体（woff2/woff/ttf/otf/eot）** | **Cache-First** | P2 的核心目标：二次访问**字体 0 网络请求**、断网也有字形 |
 * | 导航（HTML） | **Network-First，仅失败时回退缓存** | ⚠️ **不给超时**：宁可慢，也不让在线用户看到旧页面（本主题"改了没生效"的旧伤） |
 * | 主题 CSS/JS、uploads 图片 | Stale-While-Revalidate | 先给快的，同时后台更新 |
 * | 其它 | 直接放行，不缓存 | 保守：宁可少缓存，不要缓存出怪问题 |
 *
 * ## 明确不碰的东西
 * - 非 GET 请求（所有写操作）
 * - `/wp-admin`、`wp-login.php`、`wp-json` 的写请求
 * - 带 `preview=`、`ybh_nocache` 的 URL
 * - 响应头里带 `no-store` / `no-cache` / `Set-Cookie` 的内容
 * - Range 请求（视频等分段请求）
 *
 * ## 更新方式
 * **不自动 skipWaiting**：新版 SW 装好后进入 waiting，由页面提示"有新版本，点击刷新"，
 * 用户点击才 `SKIP_WAITING` 并重载。这样避免出现"旧 HTML + 新 JS"混用的怪现象。
 */

const VERSION = '__YBH_PWA_VERSION__';
const SCOPE = '__YBH_PWA_SCOPE__';
const THEME = '__YBH_PWA_THEME__';

const CACHE_SHELL = `ybh-shell-${VERSION}`;
const CACHE_FONT = `ybh-fonts-${VERSION}`;
const CACHE_STATIC = `ybh-static-${VERSION}`;
const CACHE_PAGE = `ybh-pages-${VERSION}`;
const CURRENT_CACHES = [CACHE_SHELL, CACHE_FONT, CACHE_STATIC, CACHE_PAGE];

// 预缓存：只放图标（数量少、必存在，装不上也不至于让 install 整体失败）
// ⚠️ 必须用主题目录的**绝对 URL**：这些图标的真实位置是
//    /wp-content/themes/<theme>/img/pwa/…，若按站点根拼成 /img/pwa/… 会全部 404，
//    而 install 里是逐个 try/catch 的 ⇒ **不会报错，只会静默地什么都没缓存**。
//    （2026-09-22 首轮真机验证就是踩了这个：ybh-shell 缓存条目数为 0。）
const SHELL_ASSETS = [
  THEME + 'img/pwa/icon-192.png',
  THEME + 'img/pwa/icon-512.png',
  THEME + 'img/pwa/apple-touch-icon.png',
];

const FONT_RE = /\.(woff2?|ttf|otf|eot)(\?|$)/i;
const STATIC_RE = /\.(css|js|png|jpe?g|gif|webp|svg|avif|ico)(\?|$)/i;

/** 这些路径一律不缓存、不拦截 */
function isExcluded(url) {
  const p = url.pathname;
  if (url.origin !== self.location.origin) return true; // 跨域交给默认行为（字体那类在同源）
  if (p === '/sw.js' || p === '/manifest.webmanifest' || p === '/manifest.json') return true;
  if (p.startsWith('/wp-admin') || p.startsWith('/wp-login.php') || p.startsWith('/wp-json')) return true;
  if (p.startsWith('/wp-content/plugins/')) return true;
  return false;
}

function isCacheableResponse(res) {
  if (!res || !res.ok) return false;
  if (res.status !== 200) return false;
  const cc = (res.headers.get('cache-control') || '').toLowerCase();
  if (cc.includes('no-store') || cc.includes('no-cache') || cc.includes('private')) return false;
  // 有 Set-Cookie 的响应不缓存（登录态页面等）
  if (res.headers.get('set-cookie')) return false;
  return true;
}

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_SHELL);
    await Promise.all(SHELL_ASSETS.map(async (rel) => {
      try {
        await cache.add(new Request(new URL(rel, SCOPE).toString(), { cache: 'reload' }));
      } catch (e) {
        // 单个资源失败不影响整体安装
      }
    }));
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names.map((n) => {
      // 只删本 SW 自己的旧缓存，不动别人的
      if (n.startsWith('ybh-') && !CURRENT_CACHES.includes(n)) return caches.delete(n);
      return Promise.resolve(false);
    }));
    if (self.registration.navigationPreload) {
      // 不用 preload：我们不需要额外的超时语义
    }
    await self.clients.claim();
  })());
});

self.addEventListener('message', (event) => {
  const data = event.data || {};
  if (data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

/** 离线兜底页（内联，避免额外资源请求） */
function offlineResponse() {
  const html = `<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>现在离线</title>
<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
background:#f5f6f8;color:#333;font:16px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:24px}
.box{max-width:22em;text-align:center}.t{font-size:18px;font-weight:600;margin:0 0 .6em}
.s{color:#777;margin:0}</style></head>
<body><div class="box"><p class="t">现在处于离线状态</p>
<p class="s">已访问过的页面和字体仍可查看。联网后下拉刷新即可。</p></div></body></html>`;
  return new Response(html, {
    status: 200,
    headers: { 'Content-Type': 'text/html; charset=utf-8' },
  });
}

self.addEventListener('fetch', (event) => {
  const req = event.request;

  if (req.method !== 'GET') return;
  if (req.headers.get('range')) return;

  let url;
  try {
    url = new URL(req.url);
  } catch (e) {
    return;
  }
  if (isExcluded(url)) return;

  // 带显式不缓存标识
  if (url.searchParams.has('ybh_nocache') || url.searchParams.has('preview')) return;

  // ---- 字体：Cache-First（P2 核心）----
  if (FONT_RE.test(url.pathname)) {
    event.respondWith((async () => {
      const cache = await caches.open(CACHE_FONT);
      const hit = await cache.match(req, { ignoreVary: true });
      if (hit) return hit;
      try {
        const res = await fetch(req);
        if (isCacheableResponse(res)) cache.put(req, res.clone());
        return res;
      } catch (e) {
        // 断网且未缓存 → 交给浏览器，字形回退系统字体（与站点原行为一致）
        return Response.error();
      }
    })());
    return;
  }

  // ---- 导航：Network-First，仅在失败时回退缓存 ----
  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const res = await fetch(req);
        if (isCacheableResponse(res)) {
          const cache = await caches.open(CACHE_PAGE);
          cache.put(req, res.clone());
        }
        return res;
      } catch (e) {
        const cache = await caches.open(CACHE_PAGE);
        const hit = await cache.match(req, { ignoreVary: true });
        if (hit) return hit;
        return offlineResponse();
      }
    })());
    return;
  }

  // ---- 主题 CSS/JS 与图片：SWR ----
  if (STATIC_RE.test(url.pathname)) {
    event.respondWith((async () => {
      const cache = await caches.open(CACHE_STATIC);
      const hit = await cache.match(req, { ignoreVary: true });
      const network = fetch(req).then((res) => {
        if (isCacheableResponse(res)) cache.put(req, res.clone());
        return res;
      }).catch(() => null);
      if (hit) {
        // 先给缓存，同时后台更新
        event.waitUntil(network.then(() => undefined).catch(() => undefined));
        return hit;
      }
      const res = await network;
      if (res) return res;
      return Response.error();
    })());
    return;
  }

  // 其它：放行
});
