<?php
/**
 * YBH · 界面文案表（T66）
 *
 * ===================================================================
 * 用法
 * ===================================================================
 *   `ybh_t('保存草稿')`            → 当前语言下的说法；没译就回退中文原文
 *   `ybh_e('保存草稿')`            → 直接输出（已 esc_html）
 *   `ybh_sprintf('共 %d 篇', $n)`  → 带占位符的（%s/%d），译文中占位符顺序可不同
 *
 * **key 就是中文原文**：不必先给几百条文案编英文键名；漏译时页面显示中文，
 * 而不是一串 `ybh_xxx`。要新增语言，就往 `ybh_i18n_strings()` 里加一层数组。
 *
 * ===================================================================
 * 边界（哪些**不**在这里）
 * ===================================================================
 *   · 文章/页面正文 —— 属于内容，不在界面文案层（L3 暂缓）；
 *   · 主题与 WordPress 核心的文案 —— 走核心自己的 `.mo`（见 i18n.php 里的 locale 切换）；
 *   · `admin-simplify` 等**只在后台**出现的文案 —— 站长自己看，不译。
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 译文表：语言码 => [中文原文 => 译文]。
 *
 * 加语言的步骤：① 在 `ybh_languages()` 里加一项；② 在这里加一层数组。
 */
function ybh_i18n_strings()
{
    static $strings = null;
    if ($strings !== null) {
        return $strings;
    }

    $en = array(
        /* ---- Cookie 同意弹窗 ---- */
        'Cookie 同意'                   => 'Cookie consent',
        '我们使用 Cookie'               => 'We use cookies',
        '必要的 Cookie 用于登录与评论；其余用于统计与推广，可由你决定是否允许。详见' => 'Necessary cookies keep you signed in and remember your comments. The rest are for analytics and promotion — you decide whether to allow them. See',
        'Cookie 政策'                   => 'Cookie policy',
        'Cookie 设置'                   => 'Cookie settings',
        '稍后再说'                      => 'Later',
        '自定义'                        => 'Customize',
        '仅必要'                        => 'Necessary only',
        '接受全部'                      => 'Accept all',
        'Cookie 偏好'                   => 'Cookie preferences',
        '必要'                          => 'Necessary',
        '统计'                          => 'Analytics',
        '营销'                          => 'Marketing',
        '（必需）'                      => ' (required)',
        '登录状态、评论、Cookie 偏好本身。关掉网站就无法正常工作。' => 'Sign-in, comments and your cookie choice. The site cannot work without them.',
        '匿名统计访问量，帮助我们了解哪些文章更受欢迎。' => 'Anonymous page counts, so we know which posts people read.',
        '用于展示更相关的推广内容。本站目前尚未使用。' => 'Used for showing more relevant promotions. Not in use on this site.',
        '返回'                          => 'Back',
        '保存选择'                      => 'Save choices',

        /* ---- 前台写作页 ---- */
        '写文章'                        => 'Write a post',
        '编辑文章'                      => 'Edit post',
        '标题'                          => 'Title',
        '正文'                          => 'Body',
        '文章语言'                      => 'Post language',
        '源语言'                        => 'Source language',
        '标签'                          => 'Tags',
        '分类'                          => 'Categories',
        '保存草稿'                      => 'Save draft',
        '提交审核'                      => 'Submit for review',
        '发布'                          => 'Publish',
        '看看这篇'                      => 'View post',
        '我的文章'                      => 'My posts',
        '决定正文用哪一地区的字形；不影响站点其它部分' => 'Which regional glyph set to use for the body text; does not affect the rest of the site.',
        '用逗号分隔，方便读者找到同类文章；留空则不加' => 'Comma separated, so readers can find related posts; leave empty for none.',
        '分类由编辑在审核时指定；你可以先用标签标注主题。' => 'An editor picks the category during review; you can still tag the topic.',
        '给文章起个标题'                => 'Give your post a title',
        '还没有内容可保存 —— 写点什么再试。' => 'There is nothing to save yet — write something first.',
        '已保存为草稿'                  => 'Saved as a draft',
        '已提交审核'                    => 'Submitted for review',

        /* ---- T68：编辑器选项栏 / 标签组件 / 后台切换 ---- */
        '文章选项'                      => 'Post options',
        '源语言'                        => 'Source language',
        '这篇文章原文的语言；也决定正文的地区字形' => 'The language the article was written in; also picks the regional glyph set.',
        '输入以搜索或新建标签，回车添加'  => 'Type to search or create a tag; Enter adds it',
        '可选已有标签，也可以输入新标签（保存时自动创建）' => 'Pick an existing tag, or type a new one (created automatically on save).',
        '单选'                          => 'choose one',
        '不设分类'                      => 'No category',
        '更多（后台）'                  => 'More (admin)',
        '封面图、作者、讨论等设置需要到后台。' => 'Cover image, author and discussion settings live in the admin area.',
        '切换到后台编辑器（TinyMCE）'    => 'Switch to the admin editor (TinyMCE)',
        '收起选项栏'                    => 'Hide options panel',

        /* ---- 资料页 ---- */
        '资料'                          => 'Profile',
        '头像'                          => 'Avatar',
        '偏好'                          => 'Preferences',
        '账号安全'                      => 'Account',
        '基本资料'                      => 'Basic info',
        '修改密码'                      => 'Change password',
        '更换邮箱'                      => 'Change email',
        '保存资料'                      => 'Save profile',
        '保存偏好'                      => 'Save preferences',
        '看我的作者页'                  => 'View my author page',
        '显示名'                        => 'Display name',
        '昵称'                          => 'Nickname',
        '个人网站'                      => 'Website',
        '个人简介'                      => 'Bio',
        '社交账号'                      => 'Social accounts',
        '后台配色方案'                  => 'Admin color scheme',
        '界面语言'                      => 'Interface language',
        '可视化编辑器'                  => 'Visual editor',
        '代码语法高亮'                  => 'Syntax highlighting',
        '评论键盘快捷键'                => 'Comment keyboard shortcuts',
        '前台显示管理工具栏'            => 'Show admin bar on the front end',

        /* ---- 分页 ---- */
        '上一页'                        => 'Previous',
        '下一页'                        => 'Next',
        '文章分页'                      => 'Post navigation',
        '跳至'                          => 'Go to',
        '跳转'                          => 'Go',
        '输入页码后回车跳转'            => 'Type a page number and press Enter',
        '昵称：%s'                      => 'Nickname: %s',
        '%d 篇作品'                     => '%d posts',
        '链接'                          => 'Link',

        /* ---- 作者页 / 搜人卡片 ---- */
        '还没有填写个人简介。'          => 'No bio yet.',
        '作品'                          => 'posts',
        '语言'                          => 'Language',

        /* ---- Web 应用提示条 / 教程页入口 ---- */
        '把本站装到主屏幕'              => 'Add this site to your home screen',
        '装好后像 App 一样打开，没有地址栏，字体也已缓存，弱网也能看。' => 'It opens like an app — no address bar, fonts cached, and readable on a slow connection.',
        '点底部「分享」→「添加到主屏幕」' => 'Tap Share, then “Add to Home Screen”.',
        '点菜单「安装应用」/「添加到主屏幕」' => 'Open the menu and choose “Install app” / “Add to Home screen”.',
        '安装'                          => 'Install',
        '以后再说'                      => 'Not now',
        '已安装'                        => 'Installed',
        '有新版本'                      => 'New version',
        '点击刷新'                      => 'Refresh',
        '查看详情'                      => 'Learn more',

        /* ---- 文章页语言条（T66c） ---- */
        '原文'                          => 'Original',
        '帮助本地化'                    => 'Help translate',
        '在翻译工作台里帮这篇做本地化'  => 'Help localize this article in the translation workbench',
        '本页为%s译文'                  => 'This page is a %s translation',
        '译者：%s'                      => 'Translated by %s',
        '译者未署名'                    => 'translator not credited',
        '票数最高，自动采用'            => 'most voted, adopted automatically',
        '翻译工作室'                    => 'Translation studio',

        /* ---- Web 应用教程页（整句；带 %s 的是链接占位） ---- */
        '本站可以被「装」到手机或电脑的桌面上，之后像普通 App 一样打开：' => 'You can “install” this site onto your phone or computer and open it like an ordinary app:',
        '没有地址栏、字体与文章会被缓存' => 'no address bar, and fonts and posts are cached',
        '，弱网下也能读。'              => ', so it stays readable on a slow connection.',
        '它<b>不是</b>应用商店里的安装包 —— 不占几百 MB，也不用更新。' => 'It is <b>not</b> an app-store package — it does not take hundreds of MB and never needs updating.',
        '和「客户端」有什么区别？'      => 'How is this different from the app?',
        '客户端（%s）是独立的应用程序；装 Web 应用只是让浏览器把本站放到桌面快捷方式里，<b>不需要下载、不需要安装权限、随时删掉不留残留</b>。两者可以同时用。' => 'The app (%s) is a standalone program. Installing the web app only asks your browser to put a shortcut on your desktop — <b>no download, no install permission, and nothing left behind when you remove it</b>. You can use both.',
        '一、iPhone / iPad（Safari）'    => '1. iPhone / iPad (Safari)',
        '用 %s 打开 %s（微信内置浏览器不行）。' => 'Open %s with %s (the browser inside WeChat will not work).',
        '本站首页'                      => 'the site home page',
        '点屏幕底部中间的%s按钮（方框加向上箭头）。' => 'Tap the %s button at the bottom of the screen (a square with an arrow).',
        '在弹出的列表里往下滑，选%s。'  => 'Scroll down the list that appears and choose %s.',
        '给它起个名字（默认「YBH」即可），点右上角%s。' => 'Give it a name (the default “YBH” is fine) and tap %s in the top right.',
        '二、Android（Chrome / Edge）'   => '2. Android (Chrome / Edge)',
        '用 Chrome 或 Edge 打开本站。'  => 'Open the site in Chrome or Edge.',
        '点右上角%s，选%s或%s。'        => 'Tap %s in the top right, then %s or %s.',
        '有些机型会先弹一条「把本站装到主屏幕」的提示 —— 直接点%s即可。' => 'Some devices show an “Add this site to your home screen” prompt first — just tap %s.',
        '三、电脑（Chrome / Edge）'      => '3. Desktop (Chrome / Edge)',
        '打开本站，看地址栏右侧是否有一个%s（方框加箭头）。' => 'Open the site and look for the %s icon on the right of the address bar (a square with an arrow).',
        '「安装」小图标'                => '“Install” icon',
        '点它，确认安装，就会像桌面软件一样出现独立窗口。' => 'Click it and confirm; the site then opens in its own window like desktop software.',
        '没有图标时：菜单 →「应用」→「安装此站点」。' => 'No icon? Menu → “Apps” → “Install this site”.',
        '四、装好之后会有什么不同'      => '4. What changes once it is installed',
        '全屏打开'                      => 'Full screen',
        '%s：没有地址栏和标签栏，阅读区域更大；' => '%s: no address bar or tab bar, so there is more room to read;',
        '打开更快'                      => 'Opens faster',
        '%s：字体、样式与常读页面会被缓存，弱网下也能看；' => '%s: fonts, styles and the pages you read often are cached, so it works on a slow connection;',
        '桌面入口'                      => 'A desktop entry',
        '%s：和别的 App 放在一起，点开就是本站；' => '%s: it sits alongside your other apps; tap it to open this site;',
        '更新是自动的'                  => 'Updates are automatic',
        '%s：本站更新后，下次打开就是新版，不需要你去应用商店点更新。' => '%s: after we update the site, your next launch is the new version — no app store needed.',
        '五、怎么卸载'                  => '5. How to uninstall',
        '%s：长按桌面图标 →「移除书签」/「删除书签」；' => '%s: long-press the icon → “Remove Bookmark” / “Delete Bookmark”;',
        '%s：长按图标 →「卸载」或「移除」；' => '%s: long-press the icon → “Uninstall” or “Remove”;',
        '电脑'                          => 'Desktop',
        '%s：在应用窗口的菜单里选「卸载 YBH」，或从系统的「已安装应用」里卸载。' => '%s: choose “Uninstall YBH” in the app window menu, or remove it from your system install list.',
        '卸载只会清掉那个入口与本地缓存，<b>不会删除你的账号、文章或评论</b>。' => 'Uninstalling only removes that entry and the local cache — <b>your account, posts and comments are not deleted</b>.',
        '六、常见问题'                  => '6. Common questions',
        '为什么我没看到「安装」提示？'  => 'Why do I not see the install prompt?',
        '这条提示由浏览器自己决定何时给：微信/QQ 等内置浏览器不支持；有些浏览器需要你先访问两次，或你此前点过「以后再说」（本站会安静 30 天再问一次）。按上面第一到第三节手动添加同样有效。' => 'The browser decides when to show it. In-app browsers (WeChat, QQ) do not support it; some browsers want a second visit, or you tapped “Not now” before (we stay quiet for 30 days). Adding it manually as in steps 1–3 works just as well.',
        '装了以后还占空间吗？'          => 'Does it take up space?',
        '只占很少的缓存（主要是字体与最近读过的页面）。在浏览器设置里随时可以清掉。' => 'Only a small cache (mostly fonts and recently read pages). You can clear it any time in your browser settings.',
        '它会不会收集我的信息？'        => 'Does it collect my data?',
        '不会。Web 应用只是浏览器的打开方式，本站的数据处理与网页版完全一样 —— 详细说明见%s与%s；同意与否仍由你在 Cookie 面板里决定。' => 'No. The web app is only a way of opening the site in your browser; our data practices are exactly the same as the website — see %s and %s. Whether you consent is still up to you in the cookie panel.',
        '还有问题？写信到 %s，或在%s留言告诉我们。' => 'Still have questions? Write to %s, or tell us on the %s.',
        '投稿页'                        => 'submission page',
        '《Cookie 政策》'               => 'Cookie Policy',
        '《隐私政策》'                  => 'Privacy Policy',

        /* ---- 导航菜单项（数据库内容，走 wp_nav_menu_objects 过滤器） ---- */
        '首页'                          => 'Home',
        '小游戏'                        => 'Games',
        '项目'                          => 'Projects',
        '友情链接'                      => 'Links',
        '客户端'                        => 'App',
        '更新日志'                      => 'Changelog',
        '关于我们'                      => 'About',
        '我要投稿'                      => 'Submit',
        '投稿'                          => 'Submit',
        '作者页'                        => 'Authors',
        '搜索'                          => 'Search',
    );

    $ja = array(
        /* ---- Cookie 同意弹窗 ---- */
        'Cookie 同意'                   => 'Cookie の同意',
        '我们使用 Cookie'               => 'Cookie を使用しています',
        '必要的 Cookie 用于登录与评论；其余用于统计与推广，可由你决定是否允许。详见' => 'ログインとコメントには必須の Cookie を使います。それ以外は統計と宣伝のためのもので、許可するかどうかはあなたが決められます。詳しくは',
        'Cookie 政策'                   => 'Cookie ポリシー',
        'Cookie 设置'                   => 'Cookie 設定',
        '稍后再说'                      => '後で',
        '自定义'                        => 'カスタマイズ',
        '仅必要'                        => '必須のみ',
        '接受全部'                      => 'すべて許可',
        'Cookie 偏好'                   => 'Cookie の設定',
        '必要'                          => '必須',
        '统计'                          => '統計',
        '营销'                          => 'マーケティング',
        '（必需）'                      => '（必須）',
        '登录状态、评论、Cookie 偏好本身。关掉网站就无法正常工作。' => 'ログイン状態・コメント・Cookie の設定そのもの。無効にするとサイトが正常に動作しません。',
        '匿名统计访问量，帮助我们了解哪些文章更受欢迎。' => '匿名のアクセス数です。どの記事が読まれているかを知るために使います。',
        '用于展示更相关的推广内容。本站目前尚未使用。' => 'より関連性の高い宣伝を表示するためのものです。現在このサイトでは使用していません。',
        '返回'                          => '戻る',
        '保存选择'                      => '選択を保存',

        /* ---- 前台写作页 ---- */
        '写文章'                        => '記事を書く',
        '编辑文章'                      => '記事を編集',
        '标题'                          => 'タイトル',
        '正文'                          => '本文',
        '文章语言'                      => '記事の言語',
        '源语言'                        => '原文の言語',
        '标签'                          => 'タグ',
        '分类'                          => 'カテゴリー',
        '保存草稿'                      => '下書きを保存',
        '提交审核'                      => '審査に提出',
        '发布'                          => '公開',
        '看看这篇'                      => 'この記事を見る',
        '我的文章'                      => 'マイ記事',
        '决定正文用哪一地区的字形；不影响站点其它部分' => '本文で使う地域字形を選びます。サイトの他の部分には影響しません。',
        '用逗号分隔，方便读者找到同类文章；留空则不加' => 'カンマ区切り。同じ話題の記事を見つけやすくなります。空欄なら付けません。',
        '分类由编辑在审核时指定；你可以先用标签标注主题。' => 'カテゴリーは審査時に編集者が指定します。タグで話題を示しておくことはできます。',
        '给文章起个标题'                => '記事のタイトルを入力',
        '还没有内容可保存 —— 写点什么再试。' => 'まだ保存する内容がありません。何か書いてからもう一度。',
        '已保存为草稿'                  => '下書きとして保存しました',
        '已提交审核'                    => '審査に提出しました',

        /* ---- T68：エディター設定バー / タグ / 管理画面切替 ---- */
        '文章选项'                      => '記事の設定',
        '源语言'                        => '原文の言語',
        '这篇文章原文的语言；也决定正文的地区字形' => 'この記事の原文の言語。本文の地域字形もこれで決まります。',
        '输入以搜索或新建标签，回车添加'  => '入力してタグを検索・新規作成、Enter で追加',
        '可选已有标签，也可以输入新标签（保存时自动创建）' => '既存のタグを選ぶか、新しいタグを入力（保存時に自動作成）。',
        '单选'                          => '1 つだけ選択',
        '不设分类'                      => 'カテゴリーなし',
        '更多（后台）'                  => 'その他（管理画面）',
        '封面图、作者、讨论等设置需要到后台。' => 'アイキャッチ・作者・ディスカッションなどの設定は管理画面で行います。',
        '切换到后台编辑器（TinyMCE）'    => '管理画面エディター（TinyMCE）へ切替',
        '收起选项栏'                    => '設定バーを隠す',

        /* ---- 资料页 ---- */
        '资料'                          => 'プロフィール',
        '头像'                          => 'アイコン',
        '偏好'                          => '設定',
        '账号安全'                      => 'アカウント',
        '基本资料'                      => 'プロフィール',
        '修改密码'                      => 'パスワード変更',
        '更换邮箱'                      => 'メールアドレスの変更',
        '保存资料'                      => 'プロフィールを保存',
        '保存偏好'                      => '設定を保存',
        '看我的作者页'                  => '作者ページを見る',
        '显示名'                        => '表示名',
        '昵称'                          => 'ニックネーム',
        '个人网站'                      => 'ウェブサイト',
        '个人简介'                      => '自己紹介',
        '社交账号'                      => 'SNS アカウント',
        '后台配色方案'                  => '管理画面の配色',
        '界面语言'                      => '表示言語',
        '可视化编辑器'                  => 'ビジュアルエディター',
        '代码语法高亮'                  => 'コードのシンタックスハイライト',
        '评论键盘快捷键'                => 'コメントのキーボードショートカット',
        '前台显示管理工具栏'            => 'フロント側に管理バーを表示',

        /* ---- 分页 ---- */
        '上一页'                        => '前へ',
        '下一页'                        => '次へ',
        '文章分页'                      => '記事のページ送り',
        '跳至'                          => '移動',
        '跳转'                          => '移動',
        '输入页码后回车跳转'            => 'ページ番号を入れて Enter',
        '昵称：%s'                      => 'ニックネーム：%s',
        '%d 篇作品'                     => '%d 件の作品',
        '链接'                          => 'リンク',

        /* ---- 作者页 / 搜人卡片 ---- */
        '还没有填写个人简介。'          => '自己紹介はまだありません。',
        '作品'                          => '作品',
        '语言'                          => '言語',

        /* ---- Web 应用提示条 / 教程页入口 ---- */
        '把本站装到主屏幕'              => 'ホーム画面に追加',
        '装好后像 App 一样打开，没有地址栏，字体也已缓存，弱网也能看。' => 'アプリのように開けます。アドレスバーがなく、フォントもキャッシュ済みで、低速回線でも読めます。',
        '点底部「分享」→「添加到主屏幕」' => '下部の「共有」→「ホーム画面に追加」をタップ。',
        '点菜单「安装应用」/「添加到主屏幕」' => 'メニューの「アプリをインストール」/「ホーム画面に追加」を選びます。',
        '安装'                          => 'インストール',
        '以后再说'                      => '後で',
        '已安装'                        => 'インストール済み',
        '有新版本'                      => '新しいバージョン',
        '点击刷新'                      => '更新する',
        '查看详情'                      => '詳しく見る',

        /* ---- 文章页语言条（T66c） ---- */
        '原文'                          => '原文',
        '帮助本地化'                    => '翻訳に協力する',
        '在翻译工作台里帮这篇做本地化'  => '翻訳ワークベンチでこの記事の翻訳に協力する',
        '本页为%s译文'                  => 'このページは%sの翻訳です',
        '译者：%s'                      => '翻訳：%s',
        '译者未署名'                    => '翻訳者未記名',
        '票数最高，自动采用'            => '最多票のため自動採用',
        '翻译工作室'                    => '翻訳スタジオ',

        /* ---- Web 应用教程页（整句；带 %s 的是链接占位） ---- */
        '本站可以被「装」到手机或电脑的桌面上，之后像普通 App 一样打开：' => 'このサイトはスマホやパソコンのホーム画面に「インストール」でき、普通のアプリのように開けます：',
        '没有地址栏、字体与文章会被缓存' => 'アドレスバーがなく、フォントと記事はキャッシュされます',
        '，弱网下也能读。'              => '。低速回線でも読めます。',
        '它<b>不是</b>应用商店里的安装包 —— 不占几百 MB，也不用更新。' => 'アプリストアのインストールパッケージでは<b>ありません</b>。数百 MB も占有せず、更新も不要です。',
        '和「客户端」有什么区别？'      => '「クライアント」との違いは？',
        '客户端（%s）是独立的应用程序；装 Web 应用只是让浏览器把本站放到桌面快捷方式里，<b>不需要下载、不需要安装权限、随时删掉不留残留</b>。两者可以同时用。' => 'クライアント（%s）は独立したアプリです。Web アプリはブラウザにショートカットを置いてもらうだけで、<b>ダウンロードもインストール権限も不要、削除しても何も残りません</b>。両方同時に使えます。',
        '一、iPhone / iPad（Safari）'    => '1. iPhone / iPad（Safari）',
        '用 %s 打开 %s（微信内置浏览器不行）。' => '%s で %s を開きます（WeChat 内蔵ブラウザでは不可）。',
        '本站首页'                      => 'サイトのトップページ',
        '点屏幕底部中间的%s按钮（方框加向上箭头）。' => '画面下中央の%sボタン（四角に上向き矢印）をタップします。',
        '在弹出的列表里往下滑，选%s。'  => '表示されたリストを下へスクロールし、%sを選びます。',
        '给它起个名字（默认「YBH」即可），点右上角%s。' => '名前を付け（既定の「YBH」で可）、右上の%sをタップします。',
        '二、Android（Chrome / Edge）'   => '2. Android（Chrome / Edge）',
        '用 Chrome 或 Edge 打开本站。'  => 'Chrome または Edge でサイトを開きます。',
        '点右上角%s，选%s或%s。'        => '右上の%sをタップし、%sまたは%sを選びます。',
        '有些机型会先弹一条「把本站装到主屏幕」的提示 —— 直接点%s即可。' => '機種によっては「ホーム画面に追加」の案内が出ます。そのまま%sをタップしてください。',
        '三、电脑（Chrome / Edge）'      => '3. パソコン（Chrome / Edge）',
        '打开本站，看地址栏右侧是否有一个%s（方框加箭头）。' => 'サイトを開き、アドレスバー右側に%sアイコン（四角に矢印）があるか見ます。',
        '「安装」小图标'                => '「インストール」アイコン',
        '点它，确认安装，就会像桌面软件一样出现独立窗口。' => 'それをクリックして確認すると、デスクトップアプリのように独立したウィンドウで開きます。',
        '没有图标时：菜单 →「应用」→「安装此站点」。' => 'アイコンが無い場合：メニュー →「アプリ」→「このサイトをインストール」。',
        '四、装好之后会有什么不同'      => '4. インストール後の違い',
        '全屏打开'                      => '全画面で開く',
        '%s：没有地址栏和标签栏，阅读区域更大；' => '%s：アドレスバーもタブも無く、読む場所が広がります。',
        '打开更快'                      => '起動が速い',
        '%s：字体、样式与常读页面会被缓存，弱网下也能看；' => '%s：フォント・スタイル・よく読むページがキャッシュされ、低速回線でも読めます。',
        '桌面入口'                      => 'ホーム画面の入口',
        '%s：和别的 App 放在一起，点开就是本站；' => '%s：他のアプリと並び、タップすればこのサイトが開きます。',
        '更新是自动的'                  => '更新は自動',
        '%s：本站更新后，下次打开就是新版，不需要你去应用商店点更新。' => '%s：サイトを更新すると、次に開いたときが新しい版です。ストアで更新する必要はありません。',
        '五、怎么卸载'                  => '5. アンインストール方法',
        '%s：长按桌面图标 →「移除书签」/「删除书签」；' => '%s：アイコンを長押し →「ブックマークを削除」；',
        '%s：长按图标 →「卸载」或「移除」；' => '%s：アイコンを長押し →「アンインストール」または「削除」；',
        '电脑'                          => 'パソコン',
        '%s：在应用窗口的菜单里选「卸载 YBH」，或从系统的「已安装应用」里卸载。' => '%s：アプリウィンドウのメニューで「YBH をアンインストール」を選ぶか、OS の「インストール済みアプリ」から削除します。',
        '卸载只会清掉那个入口与本地缓存，<b>不会删除你的账号、文章或评论</b>。' => 'アンインストールで消えるのは入口とローカルキャッシュだけです。<b>アカウント・記事・コメントは削除されません</b>。',
        '六、常见问题'                  => '6. よくある質問',
        '为什么我没看到「安装」提示？'  => '「インストール」の案内が出ないのは？',
        '这条提示由浏览器自己决定何时给：微信/QQ 等内置浏览器不支持；有些浏览器需要你先访问两次，或你此前点过「以后再说」（本站会安静 30 天再问一次）。按上面第一到第三节手动添加同样有效。' => 'この案内を出すかどうかはブラウザが決めます。WeChat や QQ の内蔵ブラウザは非対応で、2 回目の訪問が必要な場合や、以前「後で」を押した場合（30 日間は再表示しません）もあります。1〜3 の手順で手動追加しても同じです。',
        '装了以后还占空间吗？'          => '容量は使いますか？',
        '只占很少的缓存（主要是字体与最近读过的页面）。在浏览器设置里随时可以清掉。' => 'わずかなキャッシュのみ（主にフォントと最近読んだページ）。ブラウザの設定でいつでも消せます。',
        '它会不会收集我的信息？'        => '情報を集めますか？',
        '不会。Web 应用只是浏览器的打开方式，本站的数据处理与网页版完全一样 —— 详细说明见%s与%s；同意与否仍由你在 Cookie 面板里决定。' => 'いいえ。Web アプリはブラウザでの開き方にすぎず、データの扱いはウェブ版とまったく同じです。詳しくは%sと%sをご覧ください。同意するかどうかは Cookie パネルで決められます。',
        '还有问题？写信到 %s，或在%s留言告诉我们。' => '不明な点は %s まで、または%sでお知らせください。',
        '投稿页'                        => '投稿ページ',
        '《Cookie 政策》'               => 'Cookie ポリシー',
        '《隐私政策》'                  => 'プライバシーポリシー',

        /* ---- 导航菜单项（数据库内容，走 wp_nav_menu_objects 过滤器） ---- */
        '首页'                          => 'ホーム',
        '小游戏'                        => 'ミニゲーム',
        '项目'                          => 'プロジェクト',
        '友情链接'                      => 'リンク',
        '客户端'                        => 'アプリ',
        '更新日志'                      => '更新履歴',
        '关于我们'                      => '私たちについて',
        '我要投稿'                      => '投稿する',
        '投稿'                          => '投稿',
        '作者页'                        => '作者',
        '搜索'                          => '検索',
    );

    /*
     * 西班牙文（T68）：对齐 en 的覆盖面 —— Cookie 同意弹窗、前台写作页、资料页、
     * 分页、作者卡、Web 应用提示条与教程页、文章语言条、导航菜单项。
     * 其余词条缺译回退中文，翻译工作室里补齐并批准后自动生效。
     */
    $es = array(
        /* ---- Cookie 同意弹窗 ---- */
        'Cookie 同意'                   => 'Consentimiento de cookies',
        '我们使用 Cookie'               => 'Usamos cookies',
        '必要的 Cookie 用于登录与评论；其余用于统计与推广，可由你决定是否允许。详见' => 'Las cookies necesarias mantienen tu sesión y recuerdan tus comentarios. Las demás son para estadísticas y promoción: tú decides si permitirlas. Consulta la',
        'Cookie 政策'                   => 'Política de cookies',
        'Cookie 设置'                   => 'Configuración de cookies',
        '稍后再说'                      => 'Más tarde',
        '自定义'                        => 'Personalizar',
        '仅必要'                        => 'Solo las necesarias',
        '接受全部'                      => 'Aceptar todo',
        'Cookie 偏好'                   => 'Preferencias de cookies',
        '必要'                          => 'Necesarias',
        '统计'                          => 'Estadísticas',
        '营销'                          => 'Marketing',
        '（必需）'                      => ' (obligatoria)',
        '登录状态、评论、Cookie 偏好本身。关掉网站就无法正常工作。' => 'Inicio de sesión, comentarios y tu elección de cookies. Sin ellas, el sitio no funciona.',
        '匿名统计访问量，帮助我们了解哪些文章更受欢迎。' => 'Recuentos anónimos de visitas, para saber qué artículos lee la gente.',
        '用于展示更相关的推广内容。本站目前尚未使用。' => 'Para mostrar promociones más relevantes. No se usa en este sitio.',
        '返回'                          => 'Volver',
        '保存选择'                      => 'Guardar la elección',

        /* ---- 前台写作页 ---- */
        '写文章'                        => 'Escribir un artículo',
        '编辑文章'                      => 'Editar artículo',
        '标题'                          => 'Título',
        '正文'                          => 'Cuerpo',
        '文章语言'                      => 'Idioma del artículo',
        '源语言'                        => 'Idioma original',
        '标签'                          => 'Etiquetas',
        '分类'                          => 'Categorías',
        '保存草稿'                      => 'Guardar borrador',
        '提交审核'                      => 'Enviar a revisión',
        '发布'                          => 'Publicar',
        '看看这篇'                      => 'Ver el artículo',
        '我的文章'                      => 'Mis artículos',
        '决定正文用哪一地区的字形；不影响站点其它部分' => 'Qué variante regional de glifos usar en el cuerpo; no afecta al resto del sitio.',
        '用逗号分隔，方便读者找到同类文章；留空则不加' => 'Separadas por comas, para que los lectores encuentren artículos afines; déjalo vacío si no quieres.',
        '分类由编辑在审核时指定；你可以先用标签标注主题。' => 'Un editor elige la categoría durante la revisión; mientras tanto puedes etiquetar el tema.',
        '给文章起个标题'                => 'Ponle un título al artículo',
        '还没有内容可保存 —— 写点什么再试。' => 'Aún no hay nada que guardar: escribe algo e inténtalo de nuevo.',
        '已保存为草稿'                  => 'Guardado como borrador',
        '已提交审核'                    => 'Enviado a revisión',

        /* ---- T68：barra de opciones del editor / etiquetas / cambio al panel ---- */
        '文章选项'                      => 'Opciones del artículo',
        '源语言'                        => 'Idioma original',
        '这篇文章原文的语言；也决定正文的地区字形' => 'El idioma en que se escribió el artículo; también elige la variante regional de glifos.',
        '输入以搜索或新建标签，回车添加'  => 'Escribe para buscar o crear una etiqueta; Enter para añadir',
        '可选已有标签，也可以输入新标签（保存时自动创建）' => 'Elige una etiqueta existente o escribe una nueva (se crea al guardar).',
        '单选'                          => 'elige una',
        '不设分类'                      => 'Sin categoría',
        '更多（后台）'                  => 'Más (administración)',
        '封面图、作者、讨论等设置需要到后台。' => 'La imagen de portada, el autor y los comentarios se configuran en el panel.',
        '切换到后台编辑器（TinyMCE）'    => 'Cambiar al editor del panel (TinyMCE)',
        '收起选项栏'                    => 'Ocultar el panel de opciones',

        /* ---- 资料页 ---- */
        '资料'                          => 'Perfil',
        '头像'                          => 'Avatar',
        '偏好'                          => 'Preferencias',
        '账号安全'                      => 'Cuenta',
        '基本资料'                      => 'Datos básicos',
        '修改密码'                      => 'Cambiar la contraseña',
        '更换邮箱'                      => 'Cambiar el correo',
        '保存资料'                      => 'Guardar el perfil',
        '保存偏好'                      => 'Guardar las preferencias',
        '看我的作者页'                  => 'Ver mi página de autor',
        '显示名'                        => 'Nombre visible',
        '昵称'                          => 'Apodo',
        '个人网站'                      => 'Sitio web',
        '个人简介'                      => 'Biografía',
        '社交账号'                      => 'Redes sociales',
        '后台配色方案'                  => 'Esquema de colores del panel',
        '界面语言'                      => 'Idioma de la interfaz',
        '可视化编辑器'                  => 'Editor visual',
        '代码语法高亮'                  => 'Resaltado de sintaxis',
        '评论键盘快捷键'                => 'Atajos de teclado para comentarios',
        '前台显示管理工具栏'            => 'Mostrar la barra de administración en el sitio',

        /* ---- 分页 ---- */
        '上一页'                        => 'Anterior',
        '下一页'                        => 'Siguiente',
        '文章分页'                      => 'Navegación de artículos',
        '跳至'                          => 'Ir a',
        '跳转'                          => 'Ir',
        '输入页码后回车跳转'            => 'Escribe un número de página y pulsa Enter',
        '昵称：%s'                      => 'Apodo: %s',
        '%d 篇作品'                     => '%d artículos',
        '链接'                          => 'Enlace',

        /* ---- 作者页 / 搜人卡片 ---- */
        '还没有填写个人简介。'          => 'Todavía no hay biografía.',
        '作品'                          => 'artículos',
        '语言'                          => 'Idioma',

        /* ---- Web 应用提示条 / 教程页入口 ---- */
        '把本站装到主屏幕'              => 'Añade este sitio a tu pantalla de inicio',
        '装好后像 App 一样打开，没有地址栏，字体也已缓存，弱网也能看。' => 'Se abre como una app: sin barra de direcciones, con las fuentes en caché y legible con una conexión lenta.',
        '点底部「分享」→「添加到主屏幕」' => 'Toca «Compartir» y luego «Añadir a pantalla de inicio».',
        '点菜单「安装应用」/「添加到主屏幕」' => 'Abre el menú y elige «Instalar aplicación» / «Añadir a pantalla de inicio».',
        '安装'                          => 'Instalar',
        '以后再说'                      => 'Ahora no',
        '已安装'                        => 'Instalada',
        '有新版本'                      => 'Versión nueva',
        '点击刷新'                      => 'Actualizar',
        '查看详情'                      => 'Más información',

        /* ---- 文章页语言条（T66c） ---- */
        '原文'                          => 'Original',
        '帮助本地化'                    => 'Ayudar a traducir',
        '在翻译工作台里帮这篇做本地化'  => 'Ayuda a traducir este artículo en el estudio de traducción',
        '本页为%s译文'                  => 'Esta página es una traducción al %s',
        '译者：%s'                      => 'Traducido por %s',
        '译者未署名'                    => 'traductor no acreditado',
        '票数最高，自动采用'            => 'la más votada, adoptada automáticamente',
        '翻译工作室'                    => 'Estudio de traducción',

        /* ---- Web 应用教程页（整句；带 %s 的是链接占位） ---- */
        '本站可以被「装」到手机或电脑的桌面上，之后像普通 App 一样打开：' => 'Puedes «instalar» este sitio en el escritorio de tu teléfono u ordenador y abrirlo como una app cualquiera:',
        '没有地址栏、字体与文章会被缓存' => 'sin barra de direcciones, y las fuentes y los artículos se guardan en caché',
        '，弱网下也能读。'              => ', así que se lee bien incluso con una conexión lenta.',
        '它<b>不是</b>应用商店里的安装包 —— 不占几百 MB，也不用更新。' => '<b>No es</b> un paquete de tienda de aplicaciones: no ocupa cientos de MB ni hay que actualizarlo.',
        '和「客户端」有什么区别？'      => '¿En qué se diferencia de la aplicación?',
        '客户端（%s）是独立的应用程序；装 Web 应用只是让浏览器把本站放到桌面快捷方式里，<b>不需要下载、不需要安装权限、随时删掉不留残留</b>。两者可以同时用。' => 'La aplicación (%s) es un programa independiente. Instalar la web app solo pide al navegador que ponga un acceso directo en tu escritorio: <b>sin descargas, sin permisos de instalación y sin dejar rastro al borrarla</b>. Puedes usar ambas.',
        '一、iPhone / iPad（Safari）'    => '1. iPhone / iPad (Safari)',
        '用 %s 打开 %s（微信内置浏览器不行）。' => 'Abre %s con %s (el navegador integrado de WeChat no sirve).',
        '本站首页'                      => 'la página principal del sitio',
        '点屏幕底部中间的%s按钮（方框加向上箭头）。' => 'Toca el botón %s en la parte inferior de la pantalla (un cuadrado con una flecha hacia arriba).',
        '在弹出的列表里往下滑，选%s。'  => 'Desplázate hacia abajo en la lista que aparece y elige %s.',
        '给它起个名字（默认「YBH」即可），点右上角%s。' => 'Ponle un nombre (con «YBH» basta) y toca %s en la esquina superior derecha.',
        '二、Android（Chrome / Edge）'   => '2. Android (Chrome / Edge)',
        '用 Chrome 或 Edge 打开本站。'  => 'Abre el sitio en Chrome o Edge.',
        '点右上角%s，选%s或%s。'        => 'Toca %s en la esquina superior derecha y elige %s o %s.',
        '有些机型会先弹一条「把本站装到主屏幕」的提示 —— 直接点%s即可。' => 'Algunos dispositivos muestran antes un aviso de «añadir a la pantalla de inicio»: simplemente pulsa %s.',
        '三、电脑（Chrome / Edge）'      => '3. Ordenador (Chrome / Edge)',
        '打开本站，看地址栏右侧是否有一个%s（方框加箭头）。' => 'Abre el sitio y busca el icono %s a la derecha de la barra de direcciones (un cuadrado con una flecha).',
        '「安装」小图标'                => 'el icono de «Instalar»',
        '点它，确认安装，就会像桌面软件一样出现独立窗口。' => 'Haz clic, confirma la instalación y el sitio se abrirá en su propia ventana, como un programa de escritorio.',
        '没有图标时：菜单 →「应用」→「安装此站点」。' => '¿No aparece el icono? Menú → «Aplicaciones» → «Instalar este sitio».',
        '四、装好之后会有什么不同'      => '4. Qué cambia una vez instalada',
        '全屏打开'                      => 'Pantalla completa',
        '%s：没有地址栏和标签栏，阅读区域更大；' => '%s: sin barra de direcciones ni pestañas, hay más espacio para leer;',
        '打开更快'                      => 'Abre más rápido',
        '%s：字体、样式与常读页面会被缓存，弱网下也能看；' => '%s: las fuentes, los estilos y las páginas que lees a menudo quedan en caché y funcionan con conexión lenta;',
        '桌面入口'                      => 'Un acceso directo',
        '%s：和别的 App 放在一起，点开就是本站；' => '%s: aparece junto a tus otras apps; al tocarlo se abre este sitio;',
        '更新是自动的'                  => 'Actualizaciones automáticas',
        '%s：本站更新后，下次打开就是新版，不需要你去应用商店点更新。' => '%s: cuando actualicemos el sitio, la próxima vez que lo abras ya será la versión nueva; no hace falta ninguna tienda de aplicaciones.',
        '五、怎么卸载'                  => '5. Cómo desinstalarla',
        '%s：长按桌面图标 →「移除书签」/「删除书签」；' => '%s: mantén pulsado el icono → «Eliminar marcador»;',
        '%s：长按图标 →「卸载」或「移除」；' => '%s: mantén pulsado el icono → «Desinstalar» o «Quitar»;',
        '电脑'                          => 'Ordenador',
        '%s：在应用窗口的菜单里选「卸载 YBH」，或从系统的「已安装应用」里卸载。' => '%s: elige «Desinstalar YBH» en el menú de la ventana de la aplicación, o desinstálala desde la lista de aplicaciones instaladas del sistema.',
        '卸载只会清掉那个入口与本地缓存，<b>不会删除你的账号、文章或评论</b>。' => 'Desinstalarla solo elimina ese acceso y la caché local: <b>no se borran tu cuenta, tus artículos ni tus comentarios</b>.',
        '六、常见问题'                  => '6. Preguntas frecuentes',
        '为什么我没看到「安装」提示？'  => '¿Por qué no veo el aviso de instalación?',
        '这条提示由浏览器自己决定何时给：微信/QQ 等内置浏览器不支持；有些浏览器需要你先访问两次，或你此前点过「以后再说」（本站会安静 30 天再问一次）。按上面第一到第三节手动添加同样有效。' => 'El navegador decide cuándo mostrarlo: los navegadores integrados (WeChat, QQ) no lo admiten; a algunos les hace falta una segunda visita, o quizá pulsaste «Ahora no» antes (el sitio se calla 30 días). Añadirla a mano como en los pasos 1 a 3 funciona igual.',
        '装了以后还占空间吗？'          => '¿Ocupa espacio?',
        '只占很少的缓存（主要是字体与最近读过的页面）。在浏览器设置里随时可以清掉。' => 'Solo una pequeña caché (sobre todo fuentes y páginas leídas hace poco). Puedes borrarla cuando quieras en los ajustes del navegador.',
        '它会不会收集我的信息？'        => '¿Recoge mis datos?',
        '不会。Web 应用只是浏览器的打开方式，本站的数据处理与网页版完全一样 —— 详细说明见%s与%s；同意与否仍由你在 Cookie 面板里决定。' => 'No. La web app es solo una forma de abrir el sitio en tu navegador; el tratamiento de datos es exactamente el mismo que el de la web: consulta %s y %s. Si das tu consentimiento sigue siendo decisión tuya en el panel de cookies.',
        '还有问题？写信到 %s，或在%s留言告诉我们。' => '¿Todavía tienes dudas? Escribe a %s o déjanos un mensaje en la %s.',
        '投稿页'                        => 'página de colaboraciones',
        '《Cookie 政策》'               => 'Política de cookies',
        '《隐私政策》'                  => 'Política de privacidad',

        /* ---- 导航菜单项（数据库内容，走 wp_nav_menu_objects 过滤器） ---- */
        '首页'                          => 'Inicio',
        '小游戏'                        => 'Juegos',
        '项目'                          => 'Proyectos',
        '友情链接'                      => 'Enlaces',
        '客户端'                        => 'App',
        '更新日志'                      => 'Novedades',
        '关于我们'                      => 'Sobre nosotros',
        '我要投稿'                      => 'Colaborar',
        '投稿'                          => 'Colaborar',
        '作者页'                        => 'Autores',
        '搜索'                          => 'Buscar',
    );

    /*
     * 法文：先覆盖**最常看到的界面**（同意弹窗 / 分页 / 写作页 / 资料页 / 文章语言条）。
     * 其余词条留空 → 回退中文，等 i18n 工作台里翻译并批准后自动生效。
     */
    $fr = array(
        'Cookie 同意' => 'Consentement aux cookies',
        '我们使用 Cookie' => 'Nous utilisons des cookies',
        '必要的 Cookie 用于登录与评论；其余用于统计与推广，可由你决定是否允许。详见' => 'Les cookies nécessaires servent à la connexion et aux commentaires ; les autres servent aux statistiques et à la promotion, et c’est vous qui décidez de les autoriser. Voir',
        'Cookie 政策' => 'Politique relative aux cookies',
        '稍后再说' => 'Plus tard',
        '自定义' => 'Personnaliser',
        '仅必要' => 'Nécessaires uniquement',
        '接受全部' => 'Tout accepter',
        'Cookie 偏好' => 'Préférences de cookies',
        '必要' => 'Nécessaires',
        '统计' => 'Statistiques',
        '营销' => 'Marketing',
        '（必需）' => ' (obligatoire)',
        '登录状态、评论、Cookie 偏好本身。关掉网站就无法正常工作。' => 'Connexion, commentaires et votre choix de cookies. Sans eux, le site ne peut pas fonctionner.',
        '匿名统计访问量，帮助我们了解哪些文章更受欢迎。' => 'Des compteurs de visites anonymes, pour savoir quels articles sont lus.',
        '用于展示更相关的推广内容。本站目前尚未使用。' => 'Pour afficher des promotions plus pertinentes. Non utilisé sur ce site.',
        '返回' => 'Retour',
        '保存选择' => 'Enregistrer mes choix',
        '语言' => 'Langue',
        '原文' => 'Original',
        '帮助本地化' => 'Aider à traduire',
        '本页为%s译文' => 'Cette page est une traduction en %s',
        '译者：%s' => 'Traduction : %s',
        '译者未署名' => 'traducteur non crédité',
        '上一页' => 'Précédent',
        '下一页' => 'Suivant',
        '标题' => 'Titre',
        '正文' => 'Contenu',
        '文章语言' => 'Langue de l’article',
        '源语言' => 'Langue d’origine',
        '标签' => 'Étiquettes',
        '分类' => 'Catégories',
        '保存草稿' => 'Enregistrer le brouillon',
        '提交审核' => 'Envoyer pour relecture',
        '发布' => 'Publier',
        '写文章' => 'Écrire un article',
        '编辑文章' => 'Modifier l’article',
        '看看这篇' => 'Voir l’article',
        '我的文章' => 'Mes articles',
        '资料' => 'Profil',
        '头像' => 'Avatar',
        '偏好' => 'Préférences',
        '账号安全' => 'Compte',
        '修改密码' => 'Changer le mot de passe',
        '更换邮箱' => 'Changer d’adresse e-mail',
        '保存资料' => 'Enregistrer le profil',
        '保存偏好' => 'Enregistrer les préférences',
        '看我的作者页' => 'Voir ma page d’auteur',
        '显示名' => 'Nom affiché',
        '昵称' => 'Surnom',
        '个人网站' => 'Site web',
        '个人简介' => 'Présentation',
        '社交账号' => 'Réseaux sociaux',
        '查看详情' => 'En savoir plus',
        '安装' => 'Installer',
        '以后再说' => 'Plus tard',
        '已安装' => 'Installé',
        '有新版本' => 'Nouvelle version',
        '点击刷新' => 'Actualiser',
        '把本站装到主屏幕' => 'Ajouter ce site à votre écran d’accueil',
        '还没有填写个人简介。' => 'Pas encore de présentation.',
        '文章分页' => 'Navigation des articles',
        '跳至' => 'Aller à',
        '跳转' => 'Aller',
        '链接' => 'Lien',
    );

    /*
     * 俄文：同上，先覆盖最常看到的界面。
     */
    $ru = array(
        'Cookie 同意' => 'Согласие на использование cookie',
        '我们使用 Cookie' => 'Мы используем cookie',
        '必要的 Cookie 用于登录与评论；其余用于统计与推广，可由你决定是否允许。详见' => 'Необходимые cookie нужны для входа и комментариев; остальные — для статистики и продвижения, и вы сами решаете, разрешать ли их. Подробнее:',
        'Cookie 政策' => 'Политика в отношении cookie',
        '稍后再说' => 'Позже',
        '自定义' => 'Настроить',
        '仅必要' => 'Только необходимые',
        '接受全部' => 'Принять все',
        'Cookie 偏好' => 'Настройки cookie',
        '必要' => 'Необходимые',
        '统计' => 'Статистика',
        '营销' => 'Маркетинг',
        '（必需）' => ' (обязательно)',
        '登录状态、评论、Cookie 偏好本身。关掉网站就无法正常工作。' => 'Вход, комментарии и ваш выбор cookie. Без них сайт не сможет работать.',
        '匿名统计访问量，帮助我们了解哪些文章更受欢迎。' => 'Анонимный подсчёт посещений, чтобы понимать, какие статьи читают.',
        '用于展示更相关的推广内容。本站目前尚未使用。' => 'Для показа более подходящих материалов. На этом сайте пока не используется.',
        '返回' => 'Назад',
        '保存选择' => 'Сохранить выбор',
        '语言' => 'Язык',
        '原文' => 'Оригинал',
        '帮助本地化' => 'Помочь с переводом',
        '本页为%s译文' => 'Эта страница — перевод на %s',
        '译者：%s' => 'Перевод: %s',
        '译者未署名' => 'переводчик не указан',
        '上一页' => 'Назад',
        '下一页' => 'Вперёд',
        '标题' => 'Заголовок',
        '正文' => 'Текст',
        '文章语言' => 'Язык статьи',
        '源语言' => 'Исходный язык',
        '标签' => 'Метки',
        '分类' => 'Рубрики',
        '保存草稿' => 'Сохранить черновик',
        '提交审核' => 'Отправить на проверку',
        '发布' => 'Опубликовать',
        '写文章' => 'Написать статью',
        '编辑文章' => 'Редактировать статью',
        '看看这篇' => 'Открыть статью',
        '我的文章' => 'Мои статьи',
        '资料' => 'Профиль',
        '头像' => 'Аватар',
        '偏好' => 'Настройки',
        '账号安全' => 'Аккаунт',
        '修改密码' => 'Сменить пароль',
        '更换邮箱' => 'Сменить адрес почты',
        '保存资料' => 'Сохранить профиль',
        '保存偏好' => 'Сохранить настройки',
        '看我的作者页' => 'Моя страница автора',
        '显示名' => 'Отображаемое имя',
        '昵称' => 'Псевдоним',
        '个人网站' => 'Сайт',
        '个人简介' => 'О себе',
        '社交账号' => 'Соцсети',
        '查看详情' => 'Подробнее',
        '安装' => 'Установить',
        '以后再说' => 'Позже',
        '已安装' => 'Установлено',
        '有新版本' => 'Новая версия',
        '点击刷新' => 'Обновить',
        '把本站装到主屏幕' => 'Добавить сайт на главный экран',
        '还没有填写个人简介。' => 'Описание пока не заполнено.',
        '文章分页' => 'Навигация по записям',
        '跳至' => 'Перейти к',
        '跳转' => 'Перейти',
        '链接' => 'Ссылка',
    );

    $strings = array('en' => $en, 'ja' => $ja, 'es' => $es, 'fr' => $fr, 'ru' => $ru);

    /*
     * 三层合并，**顺序就是优先级**（后者覆盖前者）：
     *   ① 内置默认表（上面那几个数组，随主题版本走）
     *   ② **自动采用**（站长定的规则）：没有人工批准时，取票数最高的提案；票数相同取时间更靠后的一条
     *   ③ **人工批准**（覆盖层 `uploads/ybh-i18n/<lang>.json`）：优先级最高
     *
     * ⚠️ 这里踩过坑：曾经把 ② 写成 `array_merge($auto, $strings[$code])` ——
     *    那样**内置默认值会盖过自动采用**，结果是"工作室里显示某条已生效、前台却还是旧文案"。
     *    正确写法是先 merge 自动采用（覆盖内置），再 merge 覆盖层（覆盖自动采用）。
     *
     * ⚠️ 另一个坑：遍历必须用**语言注册表**的键，不能用内置表的键 ——
     *    内置表只有 en/ja/fr/ru，照它遍历的话 zh-Hant 的译文/覆盖层永远读不到。
     */
    $codes = function_exists('ybh_languages') ? array_keys(ybh_languages()) : array_keys($strings);

    // ② 自动采用（覆盖内置默认值）
    if (function_exists('ybh_i18n_auto_adopted')) {
        foreach ($codes as $code) {
            $auto = ybh_i18n_auto_adopted($code);
            if ($auto) {
                $strings[$code] = array_merge(isset($strings[$code]) ? $strings[$code] : array(), $auto);
            }
        }
    }

    // ③ 人工批准（覆盖层，最高优先级）
    if (function_exists('ybh_i18n_overlay_get')) {
        foreach ($codes as $code) {
            $over = ybh_i18n_overlay_get($code);
            if ($over) {
                $strings[$code] = array_merge(isset($strings[$code]) ? $strings[$code] : array(), $over);
            }
        }
    }

    $strings = apply_filters('ybh_i18n_strings', $strings);
    return $strings;
}

/**
 * 取当前语言下某条文案。缺译 → 返回中文原文（不会出现裸露的 key）。
 *
 * @param string $zh 中文原文（key）
 * @return string
 */
function ybh_t($zh)
{
    $zh = (string) $zh;
    $lang = function_exists('ybh_current_language') ? ybh_current_language() : 'zh-Hans';
    if ($lang === 'zh-Hans') {
        return $zh;
    }
    $all = ybh_i18n_strings();
    if (isset($all[$lang][$zh]) && (string) $all[$lang][$zh] !== '') {
        return (string) $all[$lang][$zh];
    }
    /*
     * 繁体中文没有显式译文时，把中文原文**转成繁体**再返回。
     * 不这么做的话，繁体读者看到的会是简体字（比"英文夹中文"更刺眼）；
     * 工作台里人工校订并批准的繁体译文（覆盖层）优先级更高，会盖掉这个转换结果。
     */
    if ($lang === 'zh-Hant' && function_exists('ybh_zh_hant')) {
        return ybh_zh_hant($zh);
    }
    return $zh;
}

/** 输出（转义过的）文案 */
function ybh_e($zh)
{
    echo esc_html(ybh_t($zh));
}

/**
 * 带占位符的文案。
 *
 * 译文里占位符顺序可以不同（例如日文把「%d 篇」放句末），
 * 所以这里按**参数出现顺序**替换 `%s`/`%d`，不做位置绑定。
 *
 * @param string $zh    中文原文（key，同时作为 sprintf 模板的兜底）
 * @param mixed  ...$args
 * @return string
 */
function ybh_sprintf($zh, ...$args)
{
    $tpl = ybh_t($zh);
    // 中文原文里的占位符若在译文中缺失，就用中文原文兜底，避免丢参数
    if (substr_count($tpl, '%') < substr_count((string) $zh, '%')) {
        $tpl = (string) $zh;
    }
    return $args ? vsprintf($tpl, $args) : $tpl;
}

/** 当前语言下已翻译的条数（给自检用） */
function ybh_i18n_translated_count($lang)
{
    $all = ybh_i18n_strings();
    return isset($all[$lang]) ? count($all[$lang]) : 0;
}
