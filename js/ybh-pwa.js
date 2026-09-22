/**
 * YBH · PWA 前端：注册 Service Worker + 安装引导 + 新版本提示（T48 P1）
 *
 * 三条原则（与站内其它脚本一致）：
 *  1. **失败必须静默**：任何一步出错都不能影响页面本身（全 try/catch，不抛到全局）
 *  2. **不打扰**：安装引导只提示一次，关掉后 30 天内不再出现
 *  3. **更新要经用户确认**：新版本 SW 装好后给一条"点击刷新"，不静默替换，
 *     避免出现"旧 HTML 配新 JS"这类最难排查的怪现象
 */
(function () {
  'use strict';

  var cfg = window.YbhPwa || {};
  var I18N = cfg.i18n || {};
  var LS_DISMISS = 'ybh_pwa_hint_dismissed_at';
  var HINT_COOLDOWN_DAYS = 30;

  function log() {
    try {
      if (window.console && console.log) {
        console.log.apply(console, ['[YBH PWA]'].concat([].slice.call(arguments)));
      }
    } catch (e) { /* 忽略 */ }
  }

  // ---------- 环境判定 ----------

  function isStandalone() {
    try {
      if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return true;
      if (window.navigator.standalone === true) return true; // iOS Safari 专有
    } catch (e) { /* 忽略 */ }
    return false;
  }

  function isIOS() {
    try {
      var ua = navigator.userAgent || '';
      if (/iPad|iPhone|iPod/.test(ua)) return true;
      // iPadOS 13+ 的 UA 伪装成 Mac，用触摸点数区分
      if (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1) return true;
    } catch (e) { /* 忽略 */ }
    return false;
  }

  function hintDismissedRecently() {
    try {
      var t = parseInt(localStorage.getItem(LS_DISMISS) || '0', 10);
      if (!t) return false;
      return (Date.now() - t) < HINT_COOLDOWN_DAYS * 86400000;
    } catch (e) {
      return true; // localStorage 不可用时不打扰
    }
  }

  function dismissHint() {
    try { localStorage.setItem(LS_DISMISS, String(Date.now())); } catch (e) { /* 忽略 */ }
  }

  // ---------- 提示条 ----------

  function makeBar(kind) {
    var el = document.createElement('div');
    el.className = 'ybh-pwa-bar ybh-pwa-bar--' + kind;
    el.setAttribute('role', 'status');
    return el;
  }

  function showInstallHint() {
    if (hintDismissedRecently()) return;
    var bar = makeBar('install');
    var how = isIOS() ? (I18N.iosHow || '') : (I18N.androidHow || '');
    bar.innerHTML =
      '<div class="ybh-pwa-bar__text">' +
      '<b></b>' +
      '<span></span>' +
      '</div>' +
      '<div class="ybh-pwa-bar__acts"></div>';
    // 用 textContent 填文案，避免任何注入风险
    bar.querySelector('b').textContent = I18N.installTitle || 'Install';
    bar.querySelector('span').textContent = (I18N.installBody || '') + (how ? '（' + how + '）' : '');

    var acts = bar.querySelector('.ybh-pwa-bar__acts');
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'ybh-pwa-bar__btn ybh-pwa-bar__btn--ghost';
    close.textContent = I18N.later || 'Later';
    close.addEventListener('click', function () {
      dismissHint();
      bar.remove();
    });
    acts.appendChild(close);

    document.body.appendChild(bar);
    // 延迟一帧加类，触发过渡
    requestAnimationFrame(function () { bar.classList.add('is-in'); });
  }

  function showUpdateHint(reg) {
    if (document.querySelector('.ybh-pwa-bar--update')) return;
    var bar = makeBar('update');
    bar.innerHTML = '<div class="ybh-pwa-bar__text"><b></b></div><div class="ybh-pwa-bar__acts"></div>';
    bar.querySelector('b').textContent = I18N.updateReady || 'Update ready';

    var acts = bar.querySelector('.ybh-pwa-bar__acts');
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ybh-pwa-bar__btn';
    btn.textContent = I18N.updateDo || 'Refresh';
    btn.addEventListener('click', function () {
      btn.disabled = true;
      try {
        if (reg.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' });
      } catch (e) { /* 忽略 */ }
    });
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'ybh-pwa-bar__btn ybh-pwa-bar__btn--ghost';
    close.textContent = I18N.later || 'Later';
    close.addEventListener('click', function () { bar.remove(); });
    acts.appendChild(btn);
    acts.appendChild(close);

    document.body.appendChild(bar);
    requestAnimationFrame(function () { bar.classList.add('is-in'); });
  }

  // ---------- Service Worker ----------

  function registerSW() {
    if (!('serviceWorker' in navigator) || !cfg.sw) return;
    // https 或 localhost 之外不注册（SW 的硬性要求）
    if (location.protocol !== 'https:' && location.hostname !== 'localhost') return;

    var reloading = false;

    navigator.serviceWorker.addEventListener('controllerchange', function () {
      // 只在"用户点了刷新"之后重载一次，避免刷新循环
      if (reloading) return;
      reloading = true;
      location.reload();
    });

    navigator.serviceWorker.register(cfg.sw, { scope: '/' }).then(function (reg) {
      log('SW 已注册，scope =', reg.scope);

      // 已经有 waiting 的（上次没刷新的）
      if (reg.waiting && navigator.serviceWorker.controller) {
        showUpdateHint(reg);
      }

      reg.addEventListener('updatefound', function () {
        var nw = reg.installing;
        if (!nw) return;
        nw.addEventListener('statechange', function () {
          if (nw.state === 'installed' && navigator.serviceWorker.controller) {
            showUpdateHint(reg);
          }
        });
      });
    }).catch(function (err) {
      log('SW 注册失败（忽略，不影响页面）：', err && err.message);
    });
  }

  // ---------- 安装引导（Android/桌面：用 beforeinstallprompt；iOS：给操作说明）----------

  var deferredPrompt = null;

  window.addEventListener('beforeinstallprompt', function (e) {
    try {
      e.preventDefault();
      deferredPrompt = e;
      if (hintDismissedRecently() || isStandalone()) return;
      // 有原生安装能力时，直接把"安装"按钮接上去
      var bar = document.querySelector('.ybh-pwa-bar--install');
      if (!bar) {
        showInstallHint();
        bar = document.querySelector('.ybh-pwa-bar--install');
      }
      if (!bar || bar.querySelector('.ybh-pwa-bar__btn--primary')) return;
      var acts = bar.querySelector('.ybh-pwa-bar__acts');
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ybh-pwa-bar__btn ybh-pwa-bar__btn--primary';
      btn.textContent = I18N.install || 'Install';
      btn.addEventListener('click', function () {
        btn.disabled = true;
        try {
          deferredPrompt.prompt();
          deferredPrompt.userChoice.then(function () {
            deferredPrompt = null;
            dismissHint();
            bar.remove();
          });
        } catch (err) {
          bar.remove();
        }
      });
      acts.insertBefore(btn, acts.firstChild);
    } catch (err) { /* 忽略 */ }
  });

  window.addEventListener('appinstalled', function () {
    try {
      dismissHint();
      var bar = document.querySelector('.ybh-pwa-bar--install');
      if (bar) bar.remove();
    } catch (e) { /* 忽略 */ }
    log('已安装');
  });

  // ---------- 启动 ----------

  function boot() {
    try {
      if (isStandalone()) {
        document.documentElement.classList.add('ybh-pwa-standalone');
      }
      registerSW();

      // 已安装 或 从主屏图标启动 → 不再提示安装
      if (!isStandalone() && !cfg.isApp && !hintDismissedRecently()) {
        // 桌面/Android 由 beforeinstallprompt 触发；iOS 没有该事件，直接给说明
        if (isIOS()) {
          // 页面加载完再出现，避免影响首屏
          window.setTimeout(showInstallHint, 2500);
        }
      }
    } catch (e) {
      log('初始化异常（忽略）：', e && e.message);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
