// i18n.yibianhui.cn 工作台线上验收
const { chromium } = require('playwright');
const URL = 'https://i18n.yibianhui.cn/';
const OUT = 'E:/dsh/_probe_dir/';

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const ctx = await browser.newContext({
    viewport: { width: 1600, height: 1000 },
    ignoreHTTPSErrors: true,
    acceptDownloads: true,
    permissions: ['clipboard-read', 'clipboard-write'],
  });
  const page = await ctx.newPage();
  const errs = [];
  page.on('pageerror', (e) => errs.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errs.push('console: ' + m.text()); });

  const R = [];
  const ok = (n, v, extra) => R.push({ n, v: !!v, extra: extra === undefined ? '' : String(extra) });

  // ---------- 1. 加载 ----------
  const resp = await page.goto(URL, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(1200);
  ok('HTTP 200', resp && resp.status() === 200, resp && resp.status());
  ok('标题正确', (await page.title()).includes('QSM 汉化工作台'), await page.title());

  const total = await page.evaluate(() => DATA.length);
  ok('数据条数 = 1699', total === 1699, total);
  const totalShown = (await page.textContent('#s-total')).trim();
  ok('统计栏总数一致', totalShown === String(total), totalShown);

  const rows0 = await page.$$eval('.row', (r) => r.length);
  ok('首屏渲染 60 行', rows0 === 60, rows0);

  // ---------- 2. 搜索 ----------
  await page.fill('#q', 'Add New Quiz');
  await page.waitForTimeout(400);
  const hits = await page.$$eval('.row', (r) => r.length);
  const pagerTxt = (await page.textContent('#pager')).trim();
  ok('搜索命中数 > 0 且 < 总数', hits > 0 && hits < 60, hits + ' | ' + pagerTxt);
  const srcOk = await page.$$eval('.row .src', (els) => els.every((e) => /add new quiz/i.test(e.textContent)));
  ok('搜索结果全部匹配关键词', srcOk);
  await page.fill('#q', '');
  await page.waitForTimeout(300);

  // ---------- 3. flag 筛选 ----------
  await page.selectOption('#f-flag', 'promo');
  await page.waitForTimeout(400);
  const promoPager = (await page.textContent('#pager')).trim();
  const promoN = parseInt((promoPager.match(/共 (\d+) 条/) || [])[1] || '0', 10);
  ok('付费/推广标记筛选生效', promoN > 0 && promoN < total, promoN + ' 条 | ' + promoPager);
  await page.selectOption('#f-flag', '');
  await page.waitForTimeout(300);

  // ---------- 4. 模块筛选 ----------
  const modCount = await page.$$eval('#f-mod option', (o) => o.length);
  ok('模块下拉有选项', modCount > 10, modCount);

  // ---------- 5. 分页 ----------
  const firstBefore = (await page.textContent('.row .src')).trim();
  await page.click('#pager button[data-p="2"]');
  await page.waitForTimeout(400);
  const firstAfter = (await page.textContent('.row .src')).trim();
  ok('翻页内容变化', firstBefore !== firstAfter, firstBefore + ' -> ' + firstAfter);
  await page.click('#pager button[data-p="1"]');
  await page.waitForTimeout(300);

  // ---------- 6. 编辑 + 确认 ----------
  const row = page.locator('.row').first();
  await row.locator('textarea').fill('【验收】测试译文');
  await page.waitForTimeout(500); // 等 debounce
  const editedCls = await row.getAttribute('class');
  ok('编辑后行标记 edited', /edited/.test(editedCls), editedCls);
  const doneBefore = parseInt((await page.textContent('#s-done')).trim(), 10);
  await row.locator('button[data-act="ok"]').click();
  await page.waitForTimeout(400);
  const doneAfter = parseInt((await page.textContent('#s-done')).trim(), 10);
  ok('确认后完成数 +1', doneAfter === doneBefore + 1, doneBefore + ' -> ' + doneAfter);
  const savedTxt = (await row.locator('.saved').textContent()).trim();
  ok('行内显示已确认', savedTxt.includes('已确认'), savedTxt);

  // ---------- 7. 刷新持久化 ----------
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1000);
  const doneAfterReload = parseInt((await page.textContent('#s-done')).trim(), 10);
  ok('刷新后进度保留（localStorage）', doneAfterReload === doneAfter, doneAfterReload);

  // ---------- 8. 复制原文 ----------
  await page.locator('.row').first().locator('button[data-act="src"]').click();
  await page.waitForTimeout(500);
  const clip = await page.evaluate(() => navigator.clipboard.readText().catch(() => ''));
  ok('复制原文到剪贴板', clip && clip.length > 0, clip.slice(0, 40));

  // ---------- 9. 导出 ----------
  const dl1 = page.waitForEvent('download', { timeout: 20000 });
  await page.click('#btn-export-json');
  const d1 = await dl1;
  ok('导出 JSON 文件名', d1.suggestedFilename() === 'qsm-zh_CN-workbench.json', d1.suggestedFilename());
  const p1 = OUT + 'i18n_dl.json';
  await d1.saveAs(p1);

  const dl2 = page.waitForEvent('download', { timeout: 20000 });
  await page.click('#btn-export-po');
  const d2 = await dl2;
  ok('导出 PO 文件名', d2.suggestedFilename() === 'quiz-master-next-zh_CN.po', d2.suggestedFilename());
  await d2.saveAs(OUT + 'i18n_dl.po');

  const dl3 = page.waitForEvent('download', { timeout: 20000 });
  await page.click('#btn-export-csv');
  const d3 = await dl3;
  ok('导出 CSV 文件名', d3.suggestedFilename() === 'qsm-zh_CN.csv', d3.suggestedFilename());
  await d3.saveAs(OUT + 'i18n_dl.csv');

  // ---------- 10. 一键确认本页 ----------
  page.on('dialog', (d) => d.accept());
  const dBefore = parseInt((await page.textContent('#s-done')).trim(), 10);
  await page.click('#btn-confirm-page');
  await page.waitForTimeout(600);
  const dAfter = parseInt((await page.textContent('#s-done')).trim(), 10);
  ok('一键确认本页生效', dAfter > dBefore, dBefore + ' -> ' + dAfter);

  // ---------- 11. 重置 ----------
  await page.click('#btn-reset');
  await page.waitForTimeout(600);
  const dReset = parseInt((await page.textContent('#s-done')).trim(), 10);
  ok('重置后完成数归零', dReset === 0, dReset);

  // ---------- 12. 无报错 ----------
  ok('无 JS 报错', errs.length === 0, JSON.stringify(errs.slice(0, 3)));

  require('fs').writeFileSync(OUT + 'i18n_verify_out.json', JSON.stringify({ url: URL, results: R, errors: errs }, null, 1));
  const pass = R.filter((r) => r.v).length;
  console.log('==== ' + pass + '/' + R.length + ' 通过 ====');
  R.forEach((r) => console.log((r.v ? 'PASS' : 'FAIL') + '  ' + r.n + (r.extra ? '   [' + r.extra + ']' : '')));
  await browser.close();
})();
