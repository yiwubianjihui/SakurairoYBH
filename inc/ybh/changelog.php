<?php
/**
 * SakurairoYBH · 更新日志页（changelog.php）
 *
 * ## 用法
 *
 * 新建一个页面，正文里写 `[ybh_changelog]`，即成「更新日志」页。
 * （本站已建好：`/changelog/`，见文件末尾的自动建页逻辑。）
 *
 * ## 页面由两块组成
 *
 * 1. **版本时间线** —— 数据来自下面的 `ybh_changelog_entries()`。
 *    这是**权威数据源**：版本号、日期、条目都由它决定，不依赖数据库，
 *    所以换主题版本、迁移站点都不会丢。要加一条就往下加一个数组元素。
 *    也可以用过滤器 `ybh_changelog_entries` 从别处追加（例如子主题）。
 *
 * 2. **最近站务动态** —— 自动拉取「工作日志」分类下最新的若干篇，
 *    标题 + 日期 + 链接。这一块**不需要任何人手工维护**：
 *    编辑部每周发的工作日志一发布，这里就会跟着变 —— 这就是「实时」的部分。
 *
 * 两块的分工：手写的负责「主题/功能改了什么」，自动的负责「站里最近在做什么」。
 *
 * ## 条目字段
 *
 *   version  版本号（没有版本号就写日期或留空，会显示成普通节点）
 *   date     日期 YYYY-MM-DD（决定排序；也用于「最后更新于」）
 *   title    这一版的一句话主题
 *   items[]  条目数组，每项 { type, scope, text }
 *            type  → feat 新增 / fix 修复 / perf 优化 / content 内容 / ops 运维
 *            scope → 可选，标签上的小前缀，如「后台」「阅读」「服务端」
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 更新日志数据源（倒序由渲染端负责，这里按时间正序写反而不好维护，故直接倒序写）。
 *
 * ⚠️ 新增条目请加在**最前面**（数组第一项 = 最新）。
 * 版本号与日期取自主题仓库的 git 提交记录，不是凭印象写的。
 */
function ybh_changelog_entries()
{
    $entries = array(

        array(
            'version' => '1.3.27',
            'date'    => '2026-09-25',
            'title'   => '字体样式瘦身 —— 主 CSS 比字体改造前还小',
            'items'   => array(
                array('type' => 'perf', 'scope' => '性能', 'text' => '**主样式表反而更小了**：首页 `ybh.css` 由字体改造后的 690 KB 压到 **424 KB**（gzip 63 KB，线上实测 64 KB），比这次字体改造**之前**的 427 KB 还小一点；样式表加载耗时 0.85–1.90 s → **0.70–1.21 s**，`@font-face` 由 820 条收敛回 226 条。'),
                array('type' => 'perf', 'scope' => '性能', 'text' => '剪掉 147 条**纯冗余的旧字体规则**：它们与新版规则覆盖完全相同的 72,679 个码位（交集 72,679、两边独有 0），且声明在新规则之后 ⇒ 永不生效，只剩体积。剪除时校验覆盖不变量（101,614 → 101,614，差异 0），校验不通过就放弃剪除，绝不丢字形。'),
                array('type' => 'perf', 'scope' => '性能', 'text' => '站点从未引用的三个族名（`Sarasa Gothic SC` / `Sarasa UI SC Light` / `Sarasa Gothic SC Light`，共 447 条规则）拆到独立文件 `ybh-fonts-ext.css`，**默认不加载**。哪天要用 Light 或 Gothic，只需在页头加一行 `<link>` 即可启用，不用重新生成字体。'),
                array('type' => 'feat', 'scope' => '阅读', 'text' => '这次字体改造同时**修好 93 个汉字**的显示（旧分片并集缺 93 字 → 新分片缺 0、重复 0），扩展 A/B 区的生僻字改为用到才下载；实测首页真实请求的字体仍只有原来那 7 个，新增分片请求数为 **0**。'),
            ),
        ),

        array(
            'version' => '1.3.26',
            'date'    => '2026-09-24',
            'title'   => '版心与正文宽度统一 · 标题加重 · pjax 页面类型同步 · 手机端滚动',
            'items'   => array(
                array('type' => 'fix', 'scope' => '排版', 'text' => '**有目录与没目录的文章，正文宽度统一了**：上一版只给「无目录的单栏」限了行宽，结果有目录的文章更宽、没目录的更窄；现在一视同仁，正文两侧各留 1.5 em 页边距、单栏行宽收到 46 em（此前宽达 1240 px，一行 70+ 汉字）。'),
                array('type' => 'fix', 'scope' => '排版', 'text' => '**标题加重**：主题核心样式把标题字重压到实测 300，现在按类名覆盖为加粗（`entry-title` / 分类页 / 归档页 / 卡片标题），标题与正文的层级一眼能分清。'),
                array('type' => 'fix', 'scope' => '站点', 'text' => '**分类 / 标签 / 日期 / 作者页与搜索页对齐版心**：它们此前只有主题基础值 860 px，现在与首页统一为 1150 px —— 翻分类页不再像换了个站。'),
                array('type' => 'fix', 'scope' => '站点', 'text' => '修 **「字体与页边距回退，刷新一下才恢复」**：站内跳转走 pjax 局部刷新时，`<body>` 上的页面类型类名不在替换范围内，从首页进文章页后仍带着 `home` 类，于是版心被压窄、排版按错的页面类型渲染。现在在导航完成后同步类名（用库自带的文档钩子，**不改 `selectors`** —— 实测把 body 加进去会让每次导航重跑 body 内全部内联脚本，重复样式反而更多）。'),
                array('type' => 'perf', 'scope' => '站点', 'text' => '**手机端滚动不再每帧强制重排**：滚动监听里每次都读一次 `window.innerWidth`（强制同步布局）并反复切换头部类名（每次切换都让布局失效），现加 100 ms 闸门。CPU 剖面确认耗时都在浏览器的样式重算与布局上，且**与网页 App（PWA）无关**。'),
                array('type' => 'perf', 'scope' => '站点', 'text' => '**pjax 之后内联样式不再累积**：此前每次站内跳转都会把目标页的内联 `<style>` 追加进来且从不清理（6 → 7 → 8 …，整页刷新才回落），现在会删掉内容重复的后出现者；实测 5 → 5 → 5，基线还从 6 降到 5。'),
                array('type' => 'fix', 'scope' => '排版', 'text' => '`font-display` 由 `optional` 改回 `fallback`：`optional` 若在加载窗口内拿不到字体会让整页**永久**使用回退字体（`Ctrl+F5` 硬刷新最容易触发），`fallback` 约 3 秒内就会换成网页字体。'),
            ),
        ),

        array(
            'version' => '1.3.25',
            'date'    => '2026-09-22',
            'title'   => '排版规范：空行保留 · 一个回车即分段 · 存量文章迁移',
            'items'   => array(
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '**空行不再被吃掉**：编辑器里敲的空行保存后原样保留。空行统一存成 `<p>&nbsp;</p>` —— 真正空的 `<p></p>` 上下外边距会自己塌陷、前台根本不占高度，只有带 `&nbsp;` 才真的显示成一行空行。服务端保存钩子同步改成"保留空行"，XML-RPC / REST / App 等不走编辑器的写入路径一并覆盖。'),
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '**粘贴规则统一为「1 个回车 = 分段、2 个回车 = 空行」**：TinyMCE 与 WangEditor 两个编辑器都接管了纯文本粘贴并自己排版；带格式的粘贴走结构化归约（来源的 `<br>` 换行按回车计、`div` 一律变段落）。'),
                array('type' => 'content', 'scope' => '内容', 'text' => '**存量文章迁移**：把原本靠换行排版的文章按新规范重排 —— 只有换行的改为逐行分段；既有换行又有分段的，换行改分段、分段改空行（原有视觉层次不变）。共 17 篇，改前已整表备份。'),
                array('type' => 'fix', 'scope' => '编辑器', 'text' => '撤掉 T44 的「清理空段落」按钮（TinyMCE 第二行工具栏与 WangEditor 工具栏各一处）：空行现在是要保留的内容，留着这个按钮只会误删。'),
            ),
        ),

        array(
            'version' => '1.3.24',
            'date'    => '2026-09-22',
            'title'   => '网页App（添加到主屏幕）· 投稿一键进编辑器 · 导航重叠修复',
            'items'   => array(
                array('type' => 'feat', 'scope' => '站点', 'text' => '**全站变成可安装的「网页App」**：新增 Web App Manifest 与 Service Worker。在手机浏览器里「添加到主屏幕」后，点图标**像 App 一样打开**（没有地址栏），有自己的图标与名称。'),
                array('type' => 'perf', 'scope' => '站点', 'text' => '**二次访问字体 0 网络请求**：网页字体改由 Service Worker 缓存优先供给，弱网与断网下字形照常显示（与 App 端把字体打包进安装包是同一个目标，但不占安装体积）。实测稳态下 9 个字体文件全部由缓存返回、走网络 0 个。'),
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '「投稿」按钮改为**直接进入写作界面**；**未登录时先登录、登录后直接落在编辑器**，不用登录完再找一次入口。已登录但没有投稿权限的账号仍回到投稿说明页，避免撞上「不能创建文章」的死路。'),
                array('type' => 'fix', 'scope' => '前端', 'text' => '修 **861–1000px 区间顶部导航三键与菜单文字重叠**：此前在 900px 左右，最后一个菜单项会压住「搜索 / 换封面 / 随机文章」三个按钮（搜索与换封面各重叠约 1089px²）。已按多视口实测收紧间距并验证 0 重叠。'),
                array('type' => 'fix', 'scope' => '站点', 'text' => '给 iOS 的 `apple-touch-icon` 之前是 **WebP**，在这个位置上并不可靠（可能退化成截图或首字母图标）——改为 PNG 180×180。'),
                array('type' => 'perf', 'scope' => '站点', 'text' => '首页减重约 6 个请求：组合样式去重、按需摘掉 jQuery UI、匿名访客不再加载后台图标字体。'),
                array('type' => 'fix', 'scope' => '站点', 'text' => '主题核心资源的 `?ver=` 改用**文件修改时间**，不再依赖人工升版本号 —— 根治「明明改了却没生效」这类假故障。'),
                array('type' => 'fix', 'scope' => '排版', 'text' => '`font-display` 由 `swap` 改为 `optional`，修掉「初次加载字体不对、刷新一下就正常」的现象。'),
                array('type' => 'perf', 'scope' => '排版', 'text' => '收窄彩色 emoji 字体的字符范围，把箭头与几何符号交回 FontAwesome 与系统字体 —— 只有真正出现彩色 emoji 的页面才会下载那 1.8 MB 的字体。'),
            ),
        ),

        array(
            'version' => '1.3.23',
            'date'    => '2026-09-20',
            'title'   => '编辑器回退到纯文字输入',
            'items'   => array(
                array('type' => 'fix', 'scope' => '编辑器', 'text' => '回退上一版的「发布按钮」与「默认编辑器」改动：WangEditor 退回纯文字输入工具，发布仍走原有流程（避免两套编辑器在发布链路上产生分歧）。'),
            ),
        ),

        array(
            'version' => '1.3.22',
            'date'    => '2026-09-19',
            'title'   => '两套编辑器的内容规范统一',
            'items'   => array(
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '统一 WangEditor 与 TinyMCE 两个编辑器的内容规范，同一篇文章在哪一套里写，落到前台的排版都一致。'),
                array('type' => 'feat', 'scope' => '编辑器', 'text' => 'WangEditor 增加发布入口。'),
            ),
        ),

        array(
            'version' => '1.3.21',
            'date'    => '2026-09-19',
            'title'   => 'WangEditor 更好用 · 设为默认',
            'items'   => array(
                array('type' => 'feat', 'scope' => '编辑器', 'text' => 'WangEditor 字号与图标增大、可在面板内直接保存，并设为投稿同学的默认编辑器。'),
            ),
        ),

        array(
            'version' => '1.3.20',
            'date'    => '2026-09-19',
            'title'   => 'WangEditor 三项优化',
            'items'   => array(
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '禁止在写作时误改整体排版、补齐 TinyMCE 上已有的功能、正文样式与前台对齐（所见即所得更接近最终效果）。'),
            ),
        ),

        array(
            'version' => '1.3.19',
            'date'    => '2026-09-18',
            'title'   => '新增 WangEditor 写作入口',
            'items'   => array(
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '部署 WangEditor 5 作为**附加**写作入口，原来的 TinyMCE 完整保留 —— 两条路都可用，投稿同学可以挑顺手的。'),
            ),
        ),

        array(
            'version' => '1.3.18',
            'date'    => '2026-09-18',
            'title'   => '文章目录改右栏 · 段落规范化',
            'items'   => array(
                array('type' => 'feat', 'scope' => '站点', 'text' => '文章目录改为「正文左栏 + 目录右栏」，长文查阅更方便；同时修掉在部分屏幕上目录溢出到屏幕外的问题。'),
                array('type' => 'fix', 'scope' => '编辑器', 'text' => '编辑器段落规范化：修行距异常变大、粘贴时的换行归约为段落（此前从别处粘一段话会被拆成很多空行）。'),
            ),
        ),

        array(
            'version' => '1.3.17',
            'date'    => '2026-09-18',
            'title'   => '卡片顶栏合并 · 分页可跳页',
            'items'   => array(
                array('type' => 'feat', 'scope' => '站点', 'text' => '文章卡片顶栏合成一行，信息更紧凑；分页增加「跳至某页」，翻到中间不必一页页点。'),
            ),
        ),

        array(
            'version' => '1.3.16',
            'date'    => '2026-09-18',
            'title'   => '首页体积减掉三分之二',
            'items'   => array(
                array('type' => 'perf', 'scope' => '站点', 'text' => '首页体积 **12.77 MB → 4.06 MB（−68%）**，加载时间降到 1166ms。主要来自字体分片的按需化。'),
            ),
        ),

        array(
            'version' => '1.3.15',
            'date'    => '2026-09-18',
            'title'   => '修首屏封面冷访问不显示',
            'items'   => array(
                array('type' => 'fix', 'scope' => '站点', 'text' => '修两个冷启动 bug：首次访问（缓存为空时）首屏封面不出图、以及图片加载失败回调未定义导致的控制台报错。'),
            ),
        ),

        array(
            'version' => '1.3.14',
            'date'    => '2026-09-17',
            'title'   => '分页锚点修正',
            'items'   => array(
                array('type' => 'fix', 'scope' => '站点', 'text' => '分页锚点只在第 2 页及以后附加 —— 此前点「回第一页」也会带着锚点，落到文章列表中间而不是页首。'),
            ),
        ),

        array(
            'version' => '1.3.13',
            'date'    => '2026-09-17',
            'title'   => '封面双变体 · 首屏提速',
            'items'   => array(
                array('type' => 'perf', 'scope' => '站点', 'text' => '封面改为**双变体**：大屏用大图、卡片用小图，卡片不再拖着整张大图；首屏图片去掉懒加载（越早开始下载越快看到）；并修掉「防裁切」逻辑漏掉卡片那一路的问题。'),
                array('type' => 'feat', 'scope' => '站点', 'text' => '首页卡片略缩小、标题字号调大，并重做卡片叠加层，修掉长标题留下的空白条。'),
            ),
        ),

        array(
            'version' => '1.3.12',
            'date'    => '2026-09-17',
            'title'   => '首页卡片加宽 · 换背景全站可用',
            'items'   => array(
                array('type' => 'feat', 'scope' => '站点', 'text' => '首页卡片区加宽；分页链接带上锚点（翻页后停在文章列表而不是页首）；「换背景」按钮改为全站可见。'),
            ),
        ),

        array(
            'version' => '1.3.11',
            'date'    => '2026-09-15',
            'title'   => '卡片顶栏改两行',
            'items'   => array(
                array('type' => 'feat', 'scope' => '站点', 'text' => '桌面端文章卡片顶栏改为两行：右上角那组元信息（分类 / 字数 / 浏览量）挪到第二行，标题不再被挤压。'),
            ),
        ),

        array(
            'version' => '1.3.10',
            'date'    => '2026-09-15',
            'title'   => '页脚去掉重复的更新日志入口',
            'items'   => array(
                array('type' => 'fix', 'scope' => '站点', 'text' => '删掉页脚里的「更新日志」入口 —— 它已经并进顶部导航菜单，页脚那个是重复的。'),
            ),
        ),

        array(
            'version' => '1.3.9',
            'date'    => '2026-09-15',
            'title'   => '主页改为真分页',
            'items'   => array(
                array('type' => 'feat', 'scope' => '站点', 'text' => '主页文章列表改为**真分页**（每页 12 篇），不再一次性把所有文章铺在一页里。'),
            ),
        ),

        array(
            'version' => '1.3.8',
            'date'    => '2026-09-15',
            'title'   => '编辑器增强 · 投稿者可改自己的作品',
            'items'   => array(
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '编辑器加上角标（上标 / 下标）、脚注改用独立标识、编辑区左右各留 2 个汉字的页边距（写作时不再贴边）。'),
                array('type' => 'feat', 'scope' => '后台', 'text' => '投稿者可以**修改自己已发布的作品**，改完进入二次审核 —— 此前发布之后就只能找管理员。'),
            ),
        ),

        array(
            'version' => '1.3.7',
            'date'    => '2026-09-15',
            'title'   => '投稿体验简化 · 更新日志上线',
            'items'   => array(
                array('type' => 'feat', 'scope' => '后台', 'text' => '投稿者 / 作者登录后台后**整块隐藏** WordPress 左侧菜单，改用顶部一条极简导航：写文章 / 我的文章 / 待审核 / 媒体库 / 资料 / 回到站点 / 退出。编辑与管理员不受影响。'),
                array('type' => 'feat', 'scope' => '后台', 'text' => '敲 `/wp-admin/` 的落点从「个人资料」改成「我的文章」——那里能看到自己各状态的稿子，也是发新稿的起点。'),
                array('type' => 'feat', 'scope' => '后台', 'text' => '管理条里的「编辑个人资料」以及 `wp-admin/profile.php` 都改指前台资料页，站内只剩一个资料入口。'),
                array('type' => 'feat', 'scope' => '站点', 'text' => '新增本更新日志页，并把主题与站点的历次更新整理成时间线。'),
                array('type' => 'content', 'scope' => '内容', 'text' => '为全部 **45 篇没有标签的文章**补齐标签（作者、连载作品、栏目、体裁），站内已无无标签文章。'),
                array('type' => 'ops', 'scope' => '服务端', 'text' => '服务器连续两次因磁盘读瓶颈失联的根因定位与处置：停掉闲置的邮件服务与容器底座、给 PHP-FPM 池加硬上限、把 WordPress 定时任务改为系统驱动、回收内核预留的 256 MB。详见站内公告。'),
            ),
        ),

        array(
            'version' => '1.3.6',
            'date'    => '2026-09-14',
            'title'   => '前台个人资料页',
            'items'   => array(
                array('type' => 'feat', 'scope' => '账号', 'text' => '新增前台「个人资料」页（`[ybh_profile]`）：头像上传、昵称与显示名、个人网站、个人简介、修改密码、我的投稿。'),
                array('type' => 'feat', 'scope' => '账号', 'text' => '顶部用户菜单的「个人资料」不再进 wp-admin，改指这个前台页 —— 改个头像不用再穿过整个后台。'),
            ),
        ),

        array(
            'version' => '1.3.5',
            'date'    => '2026-09-14',
            'title'   => '导航归类与主页快捷入口',
            'items'   => array(
                array('type' => 'feat', 'scope' => '导航', 'text' => '顶端菜单归类为 5 项，新增「随机文章」与「换封面」入口。'),
                array('type' => 'perf', 'scope' => '列表', 'text' => '文章卡片标题字号下调，一屏能看到更多条目。'),
                array('type' => 'feat', 'scope' => '后台', 'text' => '「默认启用紧凑模式」做成设置项，可在「YBH 魔改」里随时切换。'),
            ),
        ),

        array(
            'version' => '1.3.4',
            'date'    => '2026-09-14',
            'title'   => '编辑器增强六项',
            'items'   => array(
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '查找替换（`Ctrl+F` / `Ctrl+H`），按文本节点匹配，不会改坏链接与标签。'),
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '脚注预览改成与前台一致的圆形编号徽章，悬停展开、就地编辑。'),
                array('type' => 'fix', 'scope' => '编辑器', 'text' => '`Ctrl+S` 其实一直**没有保存**（已发布文章上等于什么都没发生）—— 现在会就地保存并给出明确提示。'),
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '工具栏新增「首行缩进」开关，存的是类名而非内联样式，前后台一致。'),
                array('type' => 'feat', 'scope' => '站点', 'text' => '主页加入更明显的行动按钮；新建「全部文章」「我要投稿」两个页面。'),
            ),
        ),

        array(
            'version' => '1.3.2',
            'date'    => '2026-09-14',
            'title'   => '签名栏排版',
            'items'   => array(
                array('type' => 'fix', 'scope' => '首页', 'text' => '首屏签名栏支持在标点后换行，窄屏不再被硬截断。'),
                array('type' => 'fix', 'scope' => '列表', 'text' => '文章卡片的浏览量只保留数字，去掉「热度」二字。'),
            ),
        ),

        array(
            'version' => '1.3.1',
            'date'    => '2026-09-13',
            'title'   => '紧凑模式 · 脚注 · 法务页面',
            'items'   => array(
                array('type' => 'feat', 'scope' => '列表', 'text' => '紧凑模式默认开启：桌面 3 列、窄屏 2 列。'),
                array('type' => 'feat', 'scope' => '阅读', 'text' => '正文支持 `[fn]注释[/fn]` 脚注，渲染成上标编号 + 文末注释列表。'),
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '解除编辑区 820px 宽度上限，把横向空间还给正文。'),
                array('type' => 'content', 'scope' => '法务', 'text' => '补齐隐私政策、用户协议、Cookie 政策三份文档，并在页脚加入口。'),
            ),
        ),

        array(
            'version' => '1.3.0',
            'date'    => '2026-09-13',
            'title'   => '头像全线自建',
            'items'   => array(
                array('type' => 'feat', 'scope' => '账号', 'text' => '自建头像端点与上传通道，支持用户上传自定义头像。'),
                array('type' => 'perf', 'scope' => '性能', 'text' => '头像不再向第三方（Cravatar / Gravatar）发起请求，全部走站内，也不再因为外部服务不可达而显示空白。'),
            ),
        ),

        array(
            'version' => '1.2.9',
            'date'    => '2026-09-12',
            'title'   => '标签区窄屏减量',
            'items'   => array(
                array('type' => 'fix', 'scope' => '首页', 'text' => '标签展示区在窄屏默认只显示前若干个，折叠点前移；展开后仍是全部标签，不丢内容。'),
            ),
        ),

        array(
            'version' => '1.2.7',
            'date'    => '2026-09-11',
            'title'   => '中文斜体与后台美化',
            'items'   => array(
                array('type' => 'feat', 'scope' => '阅读', 'text' => '中文斜体改用霞鹜文楷，不再用机械倾斜的伪斜体。'),
                array('type' => 'feat', 'scope' => '后台', 'text' => '管理后台统一到与前台一致的圆角、阴影与主题色；新增投稿快捷入口。'),
                array('type' => 'fix', 'scope' => '后台', 'text' => '控制台「紧凑」按钮点了没反应（监听器从未绑定）已修。'),
            ),
        ),

        array(
            'version' => '1.2.5',
            'date'    => '2026-09-11',
            'title'   => '主页精简与随机封面',
            'items'   => array(
                array('type' => 'feat', 'scope' => '首页', 'text' => '主页标签行上线，卡片精简。'),
                array('type' => 'perf', 'scope' => '性能', 'text' => '随机封面改走轻量端点，不再随机抽到几百 KB 的原图。'),
            ),
        ),

        array(
            'version' => '1.2.4',
            'date'    => '2026-09-10',
            'title'   => '改用经典编辑器',
            'items'   => array(
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '全站改用经典编辑器并重做写作体验 —— 投稿的同学不必面对块编辑器。'),
            ),
        ),

        array(
            'version' => '1.2.2',
            'date'    => '2026-09-09',
            'title'   => '字体子集化',
            'items'   => array(
                array('type' => 'perf', 'scope' => '性能', 'text' => '中文字体做子集化，首页字体体积从约 21 MB 降到 0.84 MB。'),
                array('type' => 'fix', 'scope' => '账号', 'text' => '修复重置密码链接提示「已失效」的问题。'),
            ),
        ),

        array(
            'version' => '1.2.0',
            'date'    => '2026-09-08',
            'title'   => 'YBH 分叉首个正式版',
            'items'   => array(
                array('type' => 'feat', 'scope' => '站点', 'text' => '基于 Sakurairo 建立 YBH 自有分支，处理首批 5 项站点问题：展台遮挡、WebP 上传、动效细节、已访问菜单配色、分叉标识。'),
            ),
        ),
    );

    /**
     * 允许外部追加 / 覆盖条目（子主题、插件、后续版本）。
     *
     * @param array $entries 条目数组，最新的在最前。
     */
    return apply_filters('ybh_changelog_entries', $entries);
}

/** 条目类型的显示名。 */
function ybh_changelog_type_label($type)
{
    $map = array(
        'feat'    => '新增',
        'fix'     => '修复',
        'perf'    => '优化',
        'content' => '内容',
        'ops'     => '运维',
    );
    return isset($map[$type]) ? $map[$type] : '更新';
}

/* ---------------------------------------------------------------------------
 * 样式：只在含短代码的页面上加载
 * ------------------------------------------------------------------------- */

add_action('wp_enqueue_scripts', 'ybh_changelog_assets', 20);
function ybh_changelog_assets()
{
    if (is_admin() || !is_singular()) {
        return;
    }
    $post = get_queried_object();
    if (!$post instanceof WP_Post || !has_shortcode((string) $post->post_content, 'ybh_changelog')) {
        return;
    }
    wp_enqueue_style(
        'ybh-changelog',
        get_template_directory_uri() . '/css/ybh-changelog.css',
        array(),
        // 版本号跟文件修改时间走（见 bootstrap.php 的 ybh_asset_ver 注释）
        function_exists('ybh_asset_ver') ? ybh_asset_ver('css/ybh-changelog.css')
            : (defined('YBH_VERSION') ? YBH_VERSION : '1')
    );
}

/* ---------------------------------------------------------------------------
 * 短代码
 * ------------------------------------------------------------------------- */

add_shortcode('ybh_changelog', 'ybh_changelog_shortcode');
function ybh_changelog_shortcode($atts = array())
{
    $atts = shortcode_atts(array(
        'recent'    => 5,          // 自动「站务动态」条数，0 = 关闭
        'recent_cat' => '工作日志', // 取哪个分类的最近文章
    ), $atts, 'ybh_changelog');

    $entries = ybh_changelog_entries();
    if (!is_array($entries)) {
        $entries = array();
    }

    // 按日期倒序兜底排序（数据源里已是最新在前，这里防手工插入顺序不对）
    usort($entries, function ($a, $b) {
        return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
    });

    $latest = $entries ? (string) ($entries[0]['date'] ?? '') : '';

    ob_start();
    ?>
    <div class="ybh-cl">

      <header class="ybh-cl-head">
        <p class="ybh-cl-lead">
          这里记录 <strong>YBH 站点与主题</strong> 的每一次改动 ——
          修了什么、加了什么、为什么改。倒序排列，最新的在最前。
        </p>
        <?php if ($latest !== '') : ?>
          <p class="ybh-cl-stamp">
            <span class="dashicons dashicons-update"></span>
            最后更新：<time datetime="<?php echo esc_attr($latest); ?>"><?php echo esc_html($latest); ?></time>
          </p>
        <?php endif; ?>
      </header>

      <?php
      /* ---- 自动区块：最近的站务动态 ---- */
      $recent_n = (int) $atts['recent'];
      if ($recent_n > 0) :
          /*
           * 分类先用**名称**精确匹配，再退回 slug。
           * 中文分类的 slug 是 sanitize_title() 出来的编码串，直接按名称找更可靠；
           * 而英文分类（slug 干净）用名称找也一样命中。
           */
          $cat_name = (string) $atts['recent_cat'];
          $cat = get_term_by('name', $cat_name, 'category');
          if (!$cat) {
              $cat = get_category_by_slug(sanitize_title($cat_name));
          }
          $q = new WP_Query(array(
              'post_type'           => 'post',
              'posts_per_page'      => $recent_n,
              'ignore_sticky_posts' => true,
              'no_found_rows'       => true,
              'cat'                 => $cat ? (int) $cat->term_id : 0,
          ));
          if ($q->have_posts()) :
      ?>
      <section class="ybh-cl-recent">
        <h2 class="ybh-cl-h2">
          <span class="dashicons dashicons-megaphone"></span>
          最近站务动态
          <small>自动同步，无需维护</small>
        </h2>
        <ul class="ybh-cl-recent-list">
          <?php while ($q->have_posts()) : $q->the_post(); ?>
            <li>
              <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
              <time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('Y-m-d')); ?></time>
            </li>
          <?php endwhile; ?>
        </ul>
      </section>
      <?php
          endif;
          wp_reset_postdata();
      endif;
      ?>

      <?php /* ---- 手写区块：版本时间线 ---- */ ?>
      <section class="ybh-cl-timeline">
        <?php foreach ($entries as $e) :
            $ver   = (string) ($e['version'] ?? '');
            $date  = (string) ($e['date'] ?? '');
            $title = (string) ($e['title'] ?? '');
            $items = isset($e['items']) && is_array($e['items']) ? $e['items'] : array();
        ?>
          <article class="ybh-cl-node" id="v<?php echo esc_attr(str_replace('.', '-', $ver)); ?>">
            <div class="ybh-cl-dot" aria-hidden="true"></div>
            <div class="ybh-cl-card">
              <div class="ybh-cl-meta">
                <?php if ($ver !== '') : ?>
                  <span class="ybh-cl-ver">v<?php echo esc_html($ver); ?></span>
                <?php endif; ?>
                <?php if ($date !== '') : ?>
                  <time class="ybh-cl-date" datetime="<?php echo esc_attr($date); ?>"><?php echo esc_html($date); ?></time>
                <?php endif; ?>
                <?php if ($title !== '') : ?>
                  <span class="ybh-cl-title"><?php echo esc_html($title); ?></span>
                <?php endif; ?>
              </div>
              <?php if ($items) : ?>
                <ul class="ybh-cl-items">
                  <?php foreach ($items as $it) :
                      $type  = (string) ($it['type'] ?? 'feat');
                      $scope = (string) ($it['scope'] ?? '');
                      $text  = (string) ($it['text'] ?? '');
                      if ($text === '') {
                          continue;
                      }
                  ?>
                    <li class="ybh-cl-item is-<?php echo esc_attr($type); ?>">
                      <span class="ybh-cl-tag"><?php echo esc_html(ybh_changelog_type_label($type)); ?></span>
                      <?php if ($scope !== '') : ?>
                        <span class="ybh-cl-scope"><?php echo esc_html($scope); ?></span>
                      <?php endif; ?>
                      <?php
                      /*
                       * 条目文案里用了 `code` 风格的标记（反引号）来突出命令、选项名、类名。
                       * 这里把反引号转成 <code>，其余按纯文本转义 ——
                       * 既保留可读性，又不会让人能从这个数据源注入 HTML。
                       */
                      $safe = esc_html($text);
                      $safe = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $safe);
                      // 允许 **强调**（同样是先转义再补标签，安全）
                      $safe = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $safe);
                      echo $safe;
                      ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </section>

      <footer class="ybh-cl-foot">
        <p>想追更具体的过程，可以看
          <a href="<?php echo esc_url(home_url('/category/工作日志/')); ?>">工作日志</a> 分类；
          完整代码改动在 <a href="https://github.com/yiwubianjihui/SakurairoYBH" target="_blank" rel="noopener">GitHub</a>。
        </p>
      </footer>

    </div>
    <?php
    return ob_get_clean();
}

/* ---------------------------------------------------------------------------
 * 自动建页
 *
 * 「更新日志」这类页面漏建了就是 404，而建页动作本身没有技术含量。
 * 这里在主题切换到本版本后检查一次：没有 slug 为 changelog 的页面就建一个，
 * 正文写短代码。**只建一次**（用 option 记版本号），不会反复打扰。
 * ------------------------------------------------------------------------- */

add_action('admin_init', 'ybh_changelog_maybe_create_page');
function ybh_changelog_maybe_create_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $stamp = 'ybh_changelog_page_ver';
    $want  = defined('YBH_VERSION') ? YBH_VERSION : '1';
    if (get_option($stamp) === $want) {
        return;
    }

    $existing = get_page_by_path('changelog');
    if (!$existing) {
        $id = wp_insert_post(array(
            'post_title'   => '更新日志',
            'post_name'    => 'changelog',
            'post_content' => '[ybh_changelog]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => get_current_user_id(),
            'comment_status' => 'closed',
            'ping_status'  => 'closed',
        ));
        if (is_wp_error($id)) {
            return; // 建失败就下次再来，不记版本号
        }
    }
    update_option($stamp, $want, false);
}
