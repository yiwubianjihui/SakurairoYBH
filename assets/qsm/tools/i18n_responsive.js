const { chromium } = require('playwright');
const URL = 'https://i18n.yibianhui.cn/';
const OUT = 'E:/dsh/_probe_dir/';
const VPS = [
  { n: 'desktop-1600', w: 1600, h: 1000 },
  { n: 'laptop-1280', w: 1280, h: 900 },
  { n: 'tablet-768', w: 768, h: 1024 },
  { n: 'mobile-390', w: 390, h: 844 },
  { n: 'mobile-360', w: 360, h: 780 },
];
(async () => {
  const b = await chromium.launch({ channel: 'msedge', headless: true });
  const R = [];
  for (const v of VPS) {
    const ctx = await b.newContext({ viewport: { width: v.w, height: v.h }, ignoreHTTPSErrors: true, deviceScaleFactor: 1 });
    const p = await ctx.newPage();
    const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.goto(URL, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await p.waitForTimeout(900);
    const m = await p.evaluate(() => ({
      docW: document.documentElement.scrollWidth,
      winW: window.innerWidth,
      rows: document.querySelectorAll('.row').length,
      rowCols: getComputedStyle(document.querySelector('.row')).gridTemplateColumns,
      taW: Math.round(document.querySelector('.row textarea').getBoundingClientRect().width),
      btnVisible: !!document.querySelector('#btn-export-po') &&
                  document.querySelector('#btn-export-po').getBoundingClientRect().width > 0,
    }));
    const overflow = m.docW - m.winW;
    R.push({ vp: v.n, overflow, ...m, err: errs.length });
    await p.screenshot({ path: OUT + 'i18n_' + v.n + '.png', fullPage: false });
    await ctx.close();
  }
  console.log(JSON.stringify(R, null, 1));
  await b.close();
})();
