# QSM 汉化与去推广（T25）

Quiz And Survey Master（Text Domain `quiz-master-next`，版本 11.2.6）的**完整中文语言包**与**去付费/去推广 mu-plugin**。

本目录是源码归档与再生成管线；**线上生效靠 FTP 部署**，本目录本身不会被 WordPress 读取。

---

## 1. 目录内容

| 路径 | 说明 | 部署目标（相对 WP 根 `/ybhcn/`） |
|---|---|---|
| `ybh-qsm-clean.php` | 去推广 mu-plugin（v1.2.0） | `wp-content/mu-plugins/ybh-qsm-clean.php` |
| `lang/quiz-master-next-zh_CN.po` | 翻译源文件（可编辑，权威） | — |
| `lang/quiz-master-next-zh_CN.mo` | 编译产物（WP 用） | `wp-content/languages/plugins/` |
| `lang/quiz-master-next-zh_CN.l10n.php` | WP 6.5+ 加速格式 | `wp-content/languages/plugins/` |
| `workbench/index.html` | 协作翻译工作台（单文件，双击即用） | — |
| `tools/qsm-master.json` | 全量条目（原文 / 中文 / 引用位置 / 标记） | — |
| `tools/draft/*.txt` | 中文草稿（`序号\|译文`，可再回填） | — |
| `tools/qsm_build.py` | `.po` → `.mo` / `.l10n.php` 编译器（无 gettext 依赖） | — |
| `tools/qsm_workbench.py` | `qsm-master.json` → 工作台 HTML | — |
| `tools/qsm_final_verify.js` | 后台验收脚本（playwright，登测试账户跑 13 项） | — |
| `tools/verify-admin.json` | 最近一次验收结果（全绿） | — |

## 2. 覆盖情况

- 抽取：`171` 个文件 / `2480` 处 i18n 调用 / **`1699`** 条去重条目（付费推广类 78 条按其归属单独处理）。
- 语言包：**1699 / 1699 = 100%** 有中文，其中官方已译 228 条沿用、其余为本次补译。
- JS 翻译层：**不存在**。插件全包没有 `wp_set_script_translations`，4 个 blocks 文件里的 `wp.i18n` 只是字符串、无真实调用 ⇒ 无需生成 `-{md5}.json`。

## 3. mu-plugin 做了什么

放 `mu-plugins` 而非主题，是因为 `qsm_get_plugin_link()` / `qsm_get_utm_link()` 定义在
`php/classes/class-qsm-install.php`，**由插件构造函数 `include_once`，早于 `plugins_loaded`** ——
主题 `functions.php` 加载时函数已存在，来不及接管。

| 节 | 做什么 |
|---|---|
| 1 | **接管推广链接生成器** —— 全插件 100+ 处调用都经过这两个函数；现返回站内地址并去掉 UTM |
| 2 | **后台 CSS** —— 隐藏两类推销 markup 与 Paid·Pro 徽标、购买按钮 |
| 3 | **移除 3 个纯氪金菜单项** —— `qmn_addons`(Extensions) / `qsm-free-addon`(Free Add-ons) / `qsm-answer-label`(答案标签) |
| 4 | **文案兜底** —— 少数未包在隐藏容器里的推广词置空 |
| 5 | **移除结果页 3 个附加组件推销 tab** —— 按回调函数名匹配 |
| 6 | **修复汉化引入的功能回归（★ 勿删）** —— 见第 5 节 |
| 7 | **补译硬编码英文** —— 插件里未经 `__()` 的裸字面量 |

### 3.1 推销 markup 有两个渲染分支（都要挡）

`php/admin/functions.php:1169 qsm_admin_upgrade_popup( $args, $type )`：

- `$type='popup'`（默认）→ `<div class="qsm-popup … qsm-popup-upgrade …">`，内含 `.qsm-upgrade-box`
- `$type='page'` → `<div class="qsm-upgrade-page-content">` ← **整页推销块，最易漏**

### 3.2 推广面完整清单（逐条核对）

| 位置 | 类型 | 处理 |
|---|---|---|
| `admin-results-page.php:733/753/774` | page | 移除对应 3 个 tab（第 5 节） |
| `functions.php:1539`（答案标签页） | page | 移除菜单项（第 3 节） |
| `options-page-style-tab.php:292`（Ultimate 子 tab） | page | CSS 隐藏 |
| `admin-results-page.php:709` / `quiz-options-page.php:318` / `quizzes-page.php:503` | popup | CSS 隐藏 |
| `functions.php:1166/1563/1591` | popup | CSS 隐藏 |
| `php/admin/functions.php:1071/1103` `.qsm-badge`（Paid 徽标） | 内联 | CSS 隐藏 |
| `options-page-results-page-tab.php:423` `.qsm-webhooks-pricing-popup` | 内联 | CSS 隐藏 |
| `adverts-generate.php:45` `.help-decide` | 内联 | 插件自身已短路 + CSS 兜底 |

> 补充事实：插件自己在 `mlw_quizmaster2.php:27` 就 `define( 'hide_qsm_adv', true )`，
> 所以后台广告本就不渲染（`qsm_show_adverts()` 直接短路，连厂商远程 XML 都不会去拉）。

## 4. ★ 汉化副作用与其修复（第 6 节）

QSM 的「统计」页 tab slug 由**翻译后的标题**派生，而页面用**硬编码英文**做匹配：

```php
// class-qmn-plugin-helper.php:935
$slug = strtolower( str_replace( ' ', '-', $title ) );

// stats-page.php:23
$active_tab = ... : 'quiz-and-survey-submissions';
```

英文标题 `Quiz And Survey Submissions` 恰好派生出该值 ⇒ 正常。
汉化后 slug 变成「测验和调查提交」⇒ **不匹配，tab 内容（含统计图表）不渲染**，
并触发 `qsm-admin.js:1978` 的 `getContext of null` 报错。

该页还有一处**上游引号错位**（`stats-page.php:36` 把 slug 写在 `href` 引号之外），
点 tab 后 URL 变成 `&tab=`（空值），同样会让面板变空。

修复（按回调函数名匹配，与语言无关）：`6.1` 在 `init` 优先级 99 还原该 tab 的 slug；
`6.2` 在 `admin_init` 把空 `$_GET['tab']` 归一化为同一值。

**排查结论：只有统计页中招。** 其余 tab 体系要么显式传英文 slug（测验设置页 7 个 tab）、
要么比较两侧同源派生（结果页、Add-ons 页）、要么标题是未过 `__()` 的字面量（结果详情页、关于页、工具页），
均不受翻译影响。

## 5. 补译：硬编码英文（第 7 节）

以下字符串在插件源码里是**裸字面量**（没有 `__()`），`gettext` 过滤器覆盖不到，
且由 PHP 直接 `echo`，没有任何可过滤钩子 ⇒ **只能在前端用 JS 做整段精确替换**。
命中不到就保持英文，不会破坏功能。

| 原文 | 译文 | 出处 |
|---|---|---|
| `Save Question` | 保存题目 | `options-page-questions-tab.php:770` / `question-bank-page.php:761` |
| `Cancel` | 取消 | 同上 `:766` / `:757` |
| `Select Page` | 选择页面 | `class-qsm-fields.php:595` |
| `Or, Type /  to insert template variables` | 或输入 / 插入模板变量 | `settings-page.php:1743` |
| `About` / `Help` / `System Info` | 关于 / 帮助 / 系统信息 | `about-page.php:24-37` |
| `GitHub Contributors` / `View GitHub Repo` | GitHub 贡献者 / 查看 GitHub 仓库 | `about-page.php:86` / `:118` |

### 5.1 已知残留（未处理，待有真实测验后再评估）

- 前端 **fallback 分页模板**里的 `Previous` / `Next` / `Submit`
  （`renderer/frontend/template-loader.php:51-53`，仅在模板缺失时兜底）
- `renderer/frontend/class-qsm-render-pagination.php:733` 的
  `An error occurred while rendering the quiz.`
- 社交分享按钮 `Facebook` / `Linkedin`（品牌名，判定无需翻译）

> 之所以不动前端：站点当前**尚无任何测验**（结果页 0 条、题库空），无法验证；
> 且以上均为 fallback/异常路径，正常渲染路径的文案全部走 gettext、已被语言包覆盖。

## 6. 再生成 / 更新流程

```bash
# 1) 改翻译：编辑 workbench/index.html 后导出 JSON，或直接改 draft/*.txt
qsm_fill.py      # 回填 + 校验（占位符 %s/%d、HTML 标签、首尾空格）
qsm_build.py     # 编译出 .po / .mo / .l10n.php
# 2) 上传（FTP 根 = /www/wwwroot/，第二参数相对 /ybhcn/）
ftp_put_mkdir.py lang/quiz-master-next-zh_CN.mo          wp-content/languages/plugins/quiz-master-next-zh_CN.mo
ftp_put_mkdir.py lang/quiz-master-next-zh_CN.l10n.php    wp-content/languages/plugins/quiz-master-next-zh_CN.l10n.php
ftp_put_mkdir.py ybh-qsm-clean.php                       wp-content/mu-plugins/ybh-qsm-clean.php
```

管线脚本原件在 `E:\dsh\_probe_dir\`（`qsm_extract.py` 抽取 / `qsm_fill.py` 回填 / `qsm_build.py` 编译 /
`qsm_workbench.py` 生成工作台 / `ftp_put_mkdir.py` 上传+字节回读 / `qsm_final_verify.js` 验收）。

**注意**：站点启用了 LSCache；语言包与 mu-plugin 变更后若前台无变化，先刷缓存再判断。

## 7. 停机开关

mu-plugin 若因插件升级（函数签名变化 / 守卫被移除）导致冲突，
把远端 `wp-content/mu-plugins/ybh-qsm-clean.php` 改名为 `.php.off` 即可立即停用，无需动插件源码。

**注意**：若将来安装了付费附加组件（Advanced Assessment / Ultimate / Exporting 等），
第 3 节的菜单移除与第 5 节的 tab 移除会一起把对应入口摘掉 —— 届时按需停用本文件。
