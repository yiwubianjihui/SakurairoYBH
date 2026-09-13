// T25 后台验收：登录测试账户 → 检查 QSM 菜单/汉化/去推广/功能页可达
const { chromium } = require('playwright');

const BASE = 'http://www.yibianhui.cn';
const OUT = 'E:/dsh/_probe_dir/qsm-i18n/workbench/';

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const ctx = await browser.newContext({
    viewport: { width: 1600, height: 1000 },
    ignoreHTTPSErrors: true,
  });
  const page = await ctx.newPage();
  const errs = [];
  page.on('pageerror', (e) => errs.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') errs.push('console: ' + m.text()); });

  const R = [];
  const ok = (n, v, extra) => R.push({ n, v, extra: extra === undefined ? '' : String(extra) });

  // ---- 1. 登录 ----
  await page.goto(BASE + '/wp-login.php', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.fill('#user_login', 'test-account');
  await page.fill('#user_pass', 'test');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {}),
    page.click('#wp-submit'),
  ]);
  await page.waitForTimeout(1500);
  const loggedIn = /wp-admin/.test(page.url());
  ok('登录成功', loggedIn, page.url());
  if (!loggedIn) {
    await page.screenshot({ path: OUT + 'admin-login-fail.png', fullPage: true });
    console.log(JSON.stringify({ R, errs }, null, 1));
    await browser.close();
    return;
  }

  // ---- 2. QSM 主面板 ----
  await page.goto(BASE + '/wp-admin/admin.php?page=qsm_dashboard', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2000);
  await page.screenshot({ path: OUT + 'admin-qsm-dashboard.png', fullPage: true });

  // 侧边栏子菜单
  const sub = await page.$$eval('#toplevel_page_qsm_dashboard .wp-submenu a', (as) => as.map((a) => a.textContent.trim()));
  ok('QSM 子菜单项', sub.length > 0, JSON.stringify(sub));
  ok('无 Extensions 菜单', !sub.some((t) => /Extensions/i.test(t)), '');
  ok('无 Free Add-ons 菜单', !sub.some((t) => /Free Add-ons/i.test(t)), '');

  // 页面正文中出现的英文菜单名（若仍在，说明未移除）
  const bodyTxt = await page.evaluate(() => document.body.innerText);
  ok('正文无 "Free Add-ons"', !/Free Add-ons/.test(bodyTxt), '');
  ok('正文无独立 "Extensions" 链接文字', !/\bExtensions\b/.test(bodyTxt), '');

  // ---- 3. 汉化抽查 ----
  const zhHits = ['仪表盘', '设置', '题库', '测验', '创建', '添加'].filter((w) => bodyTxt.includes(w));
  ok('后台出现中文字样', zhHits.length >= 3, JSON.stringify(zhHits));

  // 若页面仍有大量英文行，粗略统计
  const enTokens = ['Dashboard', 'Question Bank', 'Create New Quiz', 'Settings'].filter((w) => bodyTxt.includes(w));
  ok('关键英文未被残留', enTokens.length === 0, JSON.stringify(enTokens));

  // ---- 4. 去付费：可见的推广容器 ----
  const upsell = await page.evaluate(() => {
    const sels = ['.qsm-upgrade-box', '.qsm-popup-upgrade', '.qsm-badge', '.help-decide',
      '.qsm-theme-buynow-btn', '.qsm-webhooks-pricing-popup', '.qsm-upgrade-notice'];
    return sels.map((s) => {
      const els = Array.from(document.querySelectorAll(s));
      const visible = els.filter((e) => {
        const st = getComputedStyle(e);
        const r = e.getBoundingClientRect();
        return st.display !== 'none' && st.visibility !== 'hidden' && r.width > 0 && r.height > 0;
      });
      return { s, total: els.length, visible: visible.length };
    });
  });
  ok('推广容器均不可见', upsell.every((u) => u.visible === 0), JSON.stringify(upsell));

  const paidWords = ['Buy Now', 'Buy Addon', 'Available in pro', 'Upgrade Plan'].filter((w) => bodyTxt.includes(w));
  ok('无购买类文案', paidWords.length === 0, JSON.stringify(paidWords));

  // ---- 5. 功能页可达 ----
  const pages = [
    ['题库/测验列表', '/wp-admin/admin.php?page=mlw_quiz_list'],
    ['结果页', '/wp-admin/admin.php?page=mlw_quiz_results'],
    ['设置页', '/wp-admin/admin.php?page=qsm_settings'],
  ];
  for (const [name, p] of pages) {
    let code = 0, title = '', hasFatal = false;
    try {
      const resp = await page.goto(BASE + p, { waitUntil: 'domcontentloaded', timeout: 60000 });
      code = resp ? resp.status() : 0;
      await page.waitForTimeout(1200);
      title = await page.title();
      const t = await page.evaluate(() => document.body.innerText);
      hasFatal = /Fatal error|There has been a critical error/i.test(t);
    } catch (e) {
      errs.push('goto ' + p + ': ' + e.message);
    }
    ok('页面可打开 · ' + name, code === 200 && !hasFatal, 'HTTP ' + code + ' / ' + title);
  }

  await page.screenshot({ path: OUT + 'admin-qsm-settings.png', fullPage: true });

  // ---- 6. 前端（测验页）抽查 ----
  let feTxt = '';
  try {
    const resp = await page.goto(BASE + '/quiz/', { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(1500);
    feTxt = await page.evaluate(() => document.body.innerText);
    ok('前端 /quiz/ 可访问', resp && resp.status() === 200, 'HTTP ' + (resp ? resp.status() : '?'));
  } catch (e) {
    ok('前端 /quiz/ 可访问', false, e.message);
  }

  console.log(JSON.stringify({ R, errs, feSnippet: feTxt.slice(0, 300) }, null, 1));
  await browser.close();
})();
