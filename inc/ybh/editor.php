<?php
/**
 * YBH · 经典编辑器（Classic Editor）体验配置
 *
 * 背景：区块编辑器对投稿者门槛过高。改用经典编辑器后，这里做四件事：
 *   1) 精简工具栏 —— 只留写作真正用得到的按钮，去掉作者用不上的东西；
 *   2) 规整「回车/粘贴/空行」的行为 —— 回车与粘贴换行都成为独立段落，空行可自由保留；
 *   3) 编辑区样式与前台一致（css/editor-style.css）—— 做到真正的所见即所得；
 *   4) 脚注按钮（`ybh_footnote`）—— 按钮本体在 js/ybh-editor.js，
 *      渲染在 inc/ybh/footnotes.php，这里只负责「把按钮名写进工具栏数组」。
 *
 * 依赖：classic-editor 插件（已安装并激活，classic-editor-replace=classic）。
 * 注意：tinymce-advanced 插件**未激活**，因此这里的过滤器不会被它抢走。
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------------------------
 * 1) 编辑区样式：与前台同一套字体与段落排版
 *    - 直接把前台那份 ybh.css 挂进编辑器（同一个 URL，含同样的 ?ver），
 *      字体与段落规则天然同源 —— 以后改字体不用再改第二处（v1.2.2 吃过这个亏）；
 *    - editor-style.css 只负责编辑区外壳（宽度、内边距等）。
 * ------------------------------------------------------------------------- */
add_action('after_setup_theme', function () {
    add_editor_style('css/editor-style.css');
});

add_filter('mce_css', function ($mce_css) {
    $url = add_query_arg(
        'ver',
        IRO_VERSION . '-ybh' . YBH_VERSION,
        get_template_directory_uri() . '/css/ybh.css'
    );
    return $mce_css ? $mce_css . ',' . $url : $url;
});

/* ---------------------------------------------------------------------------
 * 1.5) 脚注按钮：外部 TinyMCE 插件 + 编辑器内可视化
 *
 *   插件文件 js/ybh-editor.js 做三件事：
 *     · 注册工具栏按钮 `ybh_footnote`（按钮名由下面 prio 999 的数组放行）；
 *     · 载入时把 `[fn]…[/fn]` 换成可视的行内标记，保存时换回来
 *       —— 数据库里始终只存 `[fn]…[/fn]`；
 *     · 编号靠 css/editor-style.css 的 CSS 计数器自动生成。
 *
 *   ⚠️ 不要把本插件名加进 `tiny_mce_plugins`：
 *      WP 核心（class-wp-editor.php）会把「已在 plugins 列表里的名字」
 *      从 external_plugins 中剔掉，结果是插件既不加载、也静默不报错。
 *      TinyMCE 自己会把 external_plugins 的名字追加进 plugins 列表（已实测）。
 * ------------------------------------------------------------------------- */
add_filter('mce_external_plugins', function ($plugins) {
    $plugins['ybh_footnote'] = add_query_arg(
        'ver',
        YBH_VERSION,
        get_template_directory_uri() . '/js/ybh-editor.js'
    );
    return $plugins;
});

/* ---------------------------------------------------------------------------
 * 1.6) 编辑页外壳增强（T33）
 *
 *   · js/ybh-post-editor.js —— 「查找/替换」面板 + Ctrl+S 就地保存 + 保存提示。
 *     它是**页面级**脚本（不在 TinyMCE iframe 内），因为要同时服务可视化与文本两个标签页；
 *     可视化区里的按钮/快捷键由 js/ybh-editor.js 转发到它暴露的 window.YBH_Editor。
 *   · css/admin-editor.css —— 面板与提示的样式（只进后台，不污染前台）。
 *   · 左侧 WordPress 菜单：在文章/页面编辑页**自动折叠成图标条**（悬停仍可展开子菜单），
 *     把横向空间让给正文。用 WP 原生的 `folded` body class 实现，
 *     因此折叠布局全部走核心 CSS，不需要自己模仿那套样式。
 * ------------------------------------------------------------------------- */
add_action('admin_enqueue_scripts', function ($hook) {
    if (!in_array($hook, array('post.php', 'post-new.php'), true)) {
        return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || 'post' !== $screen->base) {
        return;
    }

    wp_enqueue_style(
        'ybh-admin-editor',
        get_template_directory_uri() . '/css/admin-editor.css',
        array(),
        YBH_VERSION
    );
    wp_enqueue_script(
        'ybh-post-editor',
        get_template_directory_uri() . '/js/ybh-post-editor.js',
        array('jquery'),
        YBH_VERSION,
        true
    );
    wp_localize_script('ybh-post-editor', 'YBH_EditorData', array(
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('ybh_quick_save'),
        // 文章 ID：post.php 在 ?post= 里；post-new.php 的文章是刚建的 auto-draft，
        // 此时 $post 全局已就绪。取不到就交给 JS 读 #post_ID / URL —— 关键是
        // **不要下发一个 0**：JS 里 "0" 是真值，会把真正的 ID 挡掉（已踩过）。
        'postId'   => ybh_editor_current_post_id(),
        'showHint' => true,
    ));
});

/**
 * 取当前编辑页的文章 ID（取不到返回 0，由前端兜底）。
 *
 * @return int
 */
function ybh_editor_current_post_id()
{
    if (isset($_GET['post'])) {
        return (int) $_GET['post'];
    }
    if (isset($_POST['post_ID'])) {
        return (int) $_POST['post_ID'];
    }
    global $post;
    if ($post instanceof WP_Post) {
        return (int) $post->ID;
    }
    return 0;
}

add_filter('admin_body_class', function ($classes) {
    if (!function_exists('get_current_screen')) {
        return $classes;
    }
    $screen = get_current_screen();
    if (!$screen || 'post' !== $screen->base) {
        return $classes;
    }
    if (!in_array($screen->post_type, array('post', 'page'), true)) {
        return $classes;
    }
    return trim($classes . ' folded');
});

/* ---------------------------------------------------------------------------
 * 2) 精简工具栏（优先级 999 = 最后执行，确保结果就是我们定义的样子）
 *    其它插件（如 ruby-markup-converter 的注音按钮）会往工具栏里塞按钮，
 *    这里显式保留 ruby，其余第三/四行一律清空。
 *    第一行 = 常用写作按钮；第二行默认收起（点最右「工具栏切换」展开）。
 * ------------------------------------------------------------------------- */
add_filter('mce_buttons', function ($buttons) {
    return array(
        // 注音按钮（'ruby'）由 ruby-markup-converter 插件自己挂载，且它的过滤器
        // 在本过滤器之后执行，所以这里**不要**再写一遍，否则会出现两个注音按钮。
        'formatselect',   // 段落 / 各级标题 / 引用 / 代码
        'bold', 'italic', 'underline', 'strikethrough',
        'bullist', 'numlist', 'blockquote',
        // YBH 脚注：按钮本体注册在 js/ybh-editor.js（mce_external_plugins），
        // 名字必须写在这个数组里 —— 本过滤器 prio 999 会**整体替换**工具栏，
        // 另挂一个低优先级过滤器去追加是无效的。
        'ybh_footnote',
        'alignleft', 'aligncenter', 'alignright',
        'link', 'unlink',
        'wp_add_media',   // 插入图片/媒体
        'wp_more',        // 阅读更多标记
        'fullscreen',
        'wp_adv',         // 展开/收起第二行
    );
}, 999);

add_filter('mce_buttons_2', function ($buttons) {
    return array(
        'pastetext',      // 粘贴为纯文本（需要清格式时用）
        'removeformat',   // 清除格式
        // T33 新增：段首缩进（开关式，作用于光标所在段落）
        'ybh_indent',
        // T33 新增：查找 / 替换（Ctrl+F / Ctrl+H 同效）
        'ybh_findreplace',
        'hr',
        'charmap',
        'forecolor',
        'outdent', 'indent',
        'undo', 'redo',
        'wp_help',
    );
}, 999);

// 第三、四行留空：按钮堆砌正是要避免的
add_filter('mce_buttons_3', function () {
    return array();
}, 999);
add_filter('mce_buttons_4', function () {
    return array();
}, 999);

/* ---------------------------------------------------------------------------
 * 3) 编辑器行为：让「回车 / 粘贴换行 / 空行」符合中文写作直觉
 * ------------------------------------------------------------------------- */
add_filter('tiny_mce_before_init', function ($init) {
    // 回车生成真正的 <p> 段落（而不是 <br>），保存后由 wpautop 一致处理
    $init['forced_root_block'] = 'p';
    $init['wpautop'] = true;

    // 纯文本粘贴时，换行 → 段落（而不是 <br>）。粘贴自 Word/微信/备忘录的分段能原样保留。
    $init['paste_text_linebreaktype'] = 'p';
    // 粘贴时不带入来源文档的字体/颜色等内联样式，避免文章排版被污染
    $init['keep_styles'] = false;
    $init['paste_webkit_styles'] = 'none';
    $init['paste_merge_formats'] = true;

    // 保留行尾 <br>：作者手动敲的空行不会被编辑器吞掉
    $init['remove_trailing_brs'] = false;

    // 默认收起第二行工具栏，界面更清爽
    $init['wordpress_adv_hidden'] = true;

    // 格式下拉只留作者需要的，并去掉 H1（文章标题已是 H1，正文再用会破坏结构）
    $init['block_formats'] = '段落=p;标题=h2;小标题=h3;更小标题=h4;引用=blockquote;代码=pre';

    return $init;
});

/* ---------------------------------------------------------------------------
 * 4) 投稿者也允许上传图片（否则「插入媒体」只能挑已有图，不能传自己的插图）
 *
 *    WordPress 的 contributor 角色**默认没有** upload_files 权限。对投稿体验来说
 *    这是硬伤：作者写文章想配图，却传不上去。这里只补这一项权限，不涉及
 *    发布文章、编辑他人文章等其它能力。
 *    想收回就把 YBH_ALLOW_CONTRIBUTOR_UPLOAD 定义为 false（或注释掉本段）。
 * ------------------------------------------------------------------------- */
if (!defined('YBH_ALLOW_CONTRIBUTOR_UPLOAD')) {
    define('YBH_ALLOW_CONTRIBUTOR_UPLOAD', true);
}
add_filter('user_has_cap', function ($allcaps, $caps, $args, $user = null) {
    if (!YBH_ALLOW_CONTRIBUTOR_UPLOAD) {
        return $allcaps;
    }
    if (!in_array('upload_files', (array) $caps, true)) {
        return $allcaps;
    }
    // 第 4 个参数才是 WP_User（$args[0] 是能力名，别拿它去 get_userdata）
    if ($user instanceof WP_User && in_array('contributor', (array) $user->roles, true)) {
        $allcaps['upload_files'] = true;
    }
    return $allcaps;
}, 10, 4);
