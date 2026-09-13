// v1.2.0 最终验收：菜单/推广面/补译/功能页
const { chromium } = require('playwright');
const BASE = 'http://www.yibianhui.cn';
const OUT = 'E:/dsh/_probe_dir/qsm-i18n/workbench/';

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const ctx = await browser.newContext({ viewport: { width: 1600, height: 1000 }, ignoreHTTPSErrors: true });
  const page = await ctx.newPage();
  const errs = [];
  page.on('pageerror', (e) => errs.push(e.message));
  const R = [];
  const ok = (n, v, extra) => R.push({ n, v, extra: extra === undefined ? '' : String(extra) });

  await page.goto(BASE + '/wp-login.php', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.fill('#user_login', 'test-account');
  await page.fill('#user_pass', 'test');
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {}), page.click('#wp-submit')]);
  await page.waitForTimeout(1500);

  // ---- 1. 菜单 ----
  await page.goto(BASE + '/wp-admin/admin.php?page=qsm_dashboard', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2200);
  const sub = await page.$$eval('#toplevel_page_qsm_dashboard .wp-submenu a', (as) => as.map((a) => a.textContent.trim()).filter(Boolean));
  ok('QSM 子菜单', sub.length > 0, JSON.stringify(sub));
  ok('无 Extensions', !sub.some((t) => /Extensions/i.test(t)));
  ok('无 Free Add-ons', !sub.some((t) => /Free Add-ons/i.test(t)));
  ok('无 答案标签（纯推销页）', !sub.some((t) => t.includes('答案标签')), JSON.stringify(sub));

  // ---- 2. 结果页 tab ----
  await page.goto(BASE + '/wp-admin/admin.php?page=mlw_quiz_results', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2000);
  const rTabs = await page.$$eval('.nav-tab-wrapper a', (as) => as.map((a) => a.textContent.trim()));
  ok('结果页只剩概览 tab', rTabs.length === 1, JSON.stringify(rTabs));

  // ---- 3. 全后台可见推销容器扫描 ----
  const pages = [
    ['仪表盘', 'admin.php?page=qsm_dashboard'],
    ['题库', 'admin.php?page=qsm_question_bank'],
    ['结果', 'admin.php?page=mlw_quiz_results'],
    ['设置', 'admin.php?page=qmn_global_settings'],
    ['工具', 'admin.php?page=qsm_quiz_tools'],
    ['统计', 'admin.php?page=qmn_stats'],
    ['关于', 'admin.php?page=qsm_quiz_about'],
    ['测验列表', 'edit.php?post_type=qsm_quiz'],
    ['题目分类', 'edit-tags.php?taxonomy=qsm_category'],
  ];
  let upTotal = 0; const upDetail = [];
  for (const [nm, p] of pages) {
    await page.goto(BASE + '/wp-admin/' + p, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(1500);
    const r = await page.evaluate(() => {
      const sels = ['.qsm-upgrade-page-content', '.qsm-popup-upgrade', '.qsm-upgrade-box', '.qsm-badge',
        '.help-decide', '.qsm-theme-buynow-btn', '.qsm-webhooks-pricing-popup', '.qsm-ultimate-upgrade'];
      const up = [];
      sels.forEach((s) => {
        const els = Array.from(document.querySelectorAll(s));
        const vis = els.filter((e) => {
          const st = getComputedStyle(e); const b = e.getBoundingClientRect();
          return st.display !== 'none' && st.visibility !== 'hidden' && b.width > 0 && b.height > 0;
        });
        if (els.length) up.push(s + '(total' + els.length + '/visible' + vis.length + ')');
      });
      const txt = document.body.innerText;
      const paid = ['Buy Now', 'Buy Addon', 'Upgrade to Premium', 'Available in pro', '升级到高级版', '购买'].filter((w) => txt.includes(w));
      return { up, paid, fatal: /Fatal error|critical error/i.test(txt) };
    });
    upTotal += r.up.filter((x) => !/visible0\)$/.test(x)).length;
    if (r.up.length || r.paid.length || r.fatal) upDetail.push({ 页面: nm, 容器: r.up, 付费文案: r.paid, 致命错误: r.fatal });
  }
  ok('无可见推广容器', upTotal === 0, JSON.stringify(upDetail));
  ok('无付费文案', upDetail.every((d) => d.付费文案.length === 0), JSON.stringify(upDetail.map((d) => d.付费文案)));

  // ---- 4. 补译校验 ----
  await page.goto(BASE + '/wp-admin/admin.php?page=qsm_quiz_about', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2500);
  const aboutTxt = await page.evaluate(() => document.body.innerText);
  ok('关于页 tab 已汉化', ['关于', '帮助', '系统信息'].every((w) => aboutTxt.includes(w)), aboutTxt.slice(0, 160));
  ok('关于页无残留 About/Help/System Info', !/\bSystem Info\b/.test(aboutTxt), '');
  await page.screenshot({ path: OUT + 'v4-about.png', fullPage: true });

  await page.goto(BASE + '/wp-admin/admin.php?page=qmn_global_settings&tab=quiz-default-template', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2500);
  const setTxt = await page.evaluate(() => document.body.innerText);
  ok('模板变量提示已汉化', setTxt.includes('或输入 / 插入模板变量'), setTxt.match(/[^\n]*模板变量[^\n]*/g) ? setTxt.match(/[^\n]*模板变量[^\n]*/g).slice(0, 3).join(' | ') : '');
  ok('模板提示无英文残留', !/Or, Type/.test(setTxt));
  await page.screenshot({ path: OUT + 'v4-settings-template.png', fullPage: true });

  await page.goto(BASE + '/wp-admin/admin.php?page=qsm_question_bank', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2500);
  const qb = await page.evaluate(() => {
    const btn = document.querySelector('#save-popup-button');
    const sel = document.querySelector('select option[value=""]');
    return { saveBtn: btn ? btn.textContent.trim() : '(no #save-popup-button)', selOpt: sel ? sel.textContent.trim() : '(none)' };
  });
  ok('Save Question 已汉化', qb.saveBtn === '保存题目', JSON.stringify(qb));
  await page.screenshot({ path: OUT + 'v4-question-bank.png', fullPage: true });

  // ---- 5. 功能页可达 ----
  const codes = [];
  for (const [nm, p] of pages) {
    const resp = await page.goto(BASE + '/wp-admin/' + p, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(600);
    codes.push(nm + ':' + (resp ? resp.status() : '?'));
  }
  ok('全部 QSM 页 200', codes.every((c) => /:200$/.test(c)), codes.join(' '));

  console.log(JSON.stringify({ R, errs }, null, 1));
  await browser.close();
})();
