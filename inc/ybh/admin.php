<?php
/**
 * SakurairoYBH · 后台层（admin.php）
 *
 * 两件事：
 *   1) 后台美化 —— 入队 css/ybh-admin.css，注入站点主题色，把管理后台的菜单/列表/卡片/
 *      表单/通知/按钮统一到与前台一致的圆角与阴影体系（只改观感，不动交互与信息层级）。
 *   2) 更易用的投稿入口 —— 管理条「投稿」按钮（前后台都在）、仪表盘快捷面板、
 *      文章菜单置顶「写文章」，让「新建一篇文章」从任何页面都只差一次点击。
 *
 * 全部行为可在「YBH 魔改」设置区开关（ybh_admin_skin / ybh_quick_post）。
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

/** 是否启用后台美化（默认开）。 */
function ybh_admin_skin_on()
{
    return (bool) iro_opt('ybh_admin_skin', true);
}

/** 是否启用投稿快捷入口（默认开）。 */
function ybh_quick_post_on()
{
    return (bool) iro_opt('ybh_quick_post', true);
}

/** 供入队与内联样式共用的版本串（与前台 ybh.css 用同一套版本号）。 */
function ybh_admin_asset_ver()
{
    return (defined('IRO_VERSION') ? IRO_VERSION : '3.0.11') . '-ybh' . (defined('YBH_VERSION') ? YBH_VERSION : '1');
}

/**
 * 1) 入队后台样式层。
 *
 * 用独立文件而不是把 CSS 塞进 wp_head：后台样式只在后台加载，
 * 前台访客不会为此多下一个字节。
 */
add_action('admin_enqueue_scripts', 'ybh_admin_enqueue');
function ybh_admin_enqueue()
{
    if (!ybh_admin_skin_on()) {
        return;
    }
    wp_enqueue_style(
        'ybh-admin',
        get_template_directory_uri() . '/css/ybh-admin.css',
        array(),
        ybh_admin_asset_ver()
    );
    // 主题色注入：CSS 变量在这里覆盖，避免把颜色散落进样式文件。
    wp_add_inline_style('ybh-admin', ybh_admin_color_css());
}

/** 登录页也用同一套变量（后台美化关闭时不影响登录页原有的主题化样式）。 */
add_action('login_enqueue_scripts', 'ybh_admin_enqueue');

/**
 * 主题色 → CSS 变量。
 *
 * 取 iro_opt('theme_skin')（前台同款强调色），派生一个 10% 透明度的柔色与 28% 的焦点环色。
 * 十六进制颜色可靠地拆成 RGB；若站点填的是 rgb()/颜色名等非 hex 值，则回退到默认粉色，
 * 保证变量永远合法（否则整份内联样式会因一个非法值被浏览器丢弃）。
 */
function ybh_admin_color_css()
{
    $raw = (string) iro_opt('theme_skin', '#FF69B4');
    $hex = preg_replace('/^#/', '', trim($raw));
    if (preg_match('/^[0-9a-fA-F]{3}$/', $hex)) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        $hex = 'FF69B4';
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $rgb = sprintf('%d, %d, %d', $r, $g, $b);

    return sprintf(
        ':root{--ybh-a:#%1$s;--ybh-a-soft:rgba(%2$s,0.10);--ybh-a-ring:rgba(%2$s,0.28)}',
        $hex,
        $rgb
    );
}

/**
 * 2) 管理条「投稿」按钮 —— 后台与前台都显示。
 *
 * 放在 site-name(0) 与 new-content(20) 之间（优先级 15），是左侧最靠前的位置：
 * 视线第一落点，「写文章」这件事不再需要在菜单树里找。
 * 用 edit_posts 能力判断而非角色名 —— 订阅者/读者不该看到这个入口。
 */
add_action('admin_bar_menu', 'ybh_admin_bar_post_button', 15);
function ybh_admin_bar_post_button($wp_admin_bar)
{
    if (!ybh_quick_post_on() || is_admin() && !is_user_logged_in()) {
        return;
    }
    if (!current_user_can('edit_posts')) {
        return;
    }

    $wp_admin_bar->add_node(array(
        'id'    => 'ybh-new-post',
        'title' => '<span class="ab-icon dashicons dashicons-edit-page"></span>'
                 . '<span class="ab-label">' . esc_html__('投稿', 'sakurairo') . '</span>',
        'href'  => admin_url('post-new.php'),
        'meta'  => array(
            'class' => 'ybh-ab-post',
            'title' => esc_attr__('写一篇新文章', 'sakurairo'),
        ),
    ));

    // 「投稿」下面挂几个最常用的去向，省得再跳一次菜单。
    $wp_admin_bar->add_node(array(
        'id'     => 'ybh-new-post-recent',
        'parent' => 'ybh-new-post',
        'title'  => esc_html__('最近的文章（继续编辑）', 'sakurairo'),
        'href'   => admin_url('edit.php'),
    ));
    $wp_admin_bar->add_node(array(
        'id'     => 'ybh-new-post-drafts',
        'parent' => 'ybh-new-post',
        'title'  => esc_html__('草稿箱', 'sakurairo'),
        'href'   => admin_url('edit.php?post_status=draft&post_type=post'),
    ));
}

/**
 * 3) 仪表盘「快捷投稿」面板。
 *
 * 只在有投稿能力的账号下注册 —— 普通用户看不到这块。
 * 主卡片用主题色铺满，充当整页最醒目的行动点。
 */
add_action('wp_dashboard_setup', 'ybh_dashboard_quickpost');
function ybh_dashboard_quickpost()
{
    if (!ybh_quick_post_on() || !current_user_can('edit_posts')) {
        return;
    }
    wp_add_dashboard_widget(
        'ybh_quickpost',
        esc_html__('投稿 · 快捷面板', 'sakurairo'),
        'ybh_render_quickpost_widget'
    );
}

function ybh_render_quickpost_widget()
{
    $pending = (int) wp_count_comments()->moderated;
    $drafts  = (int) wp_count_posts('post')->draft;

    $cards = array(
        array(
            'href'  => admin_url('post-new.php'),
            'icon'  => 'dashicons-edit-page',
            'title' => __('写新文章', 'sakurairo'),
            'sub'   => __('直接进入编辑器', 'sakurairo'),
            'primary' => true,
        ),
        array(
            'href'  => admin_url('edit.php'),
            'icon'  => 'dashicons-admin-post',
            'title' => __('全部文章', 'sakurairo'),
            'sub'   => sprintf(__('草稿 %d 篇', 'sakurairo'), $drafts),
        ),
        array(
            'href'  => admin_url('edit-comments.php?comment_status=moderated'),
            'icon'  => 'dashicons-admin-comments',
            'title' => __('待审评论', 'sakurairo'),
            'sub'   => $pending > 0
                ? sprintf(__('%d 条待处理', 'sakurairo'), $pending)
                : __('暂无待处理', 'sakurairo'),
        ),
        array(
            'href'  => home_url('/'),
            'icon'  => 'dashicons-visibility',
            'title' => __('查看站点', 'sakurairo'),
            'sub'   => __('前台首页', 'sakurairo'),
        ),
    );
    ?>
    <p class="ybh-qp-lead"><?php esc_html_e('从这里开始今天的更新：', 'sakurairo'); ?></p>
    <div class="ybh-qp-grid">
      <?php foreach ($cards as $c): ?>
        <a class="ybh-qp-card<?php echo !empty($c['primary']) ? ' is-primary' : ''; ?>"
           href="<?php echo esc_url($c['href']); ?>">
          <span class="dashicons <?php echo esc_attr($c['icon']); ?>"></span>
          <span>
            <b><?php echo esc_html($c['title']); ?></b>
            <small><?php echo esc_html($c['sub']); ?></small>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php
}

/**
 * 4) 文章菜单里把「写文章」置顶并加一个「＋」标记，减少一次悬停。
 *
 * 只重排 / 改标题，不新增菜单项，避免与核心菜单产生重复页签。
 */
add_action('admin_menu', 'ybh_posts_menu_shortcut', 999);
function ybh_posts_menu_shortcut()
{
    global $submenu, $menu;
    if (!ybh_quick_post_on() || !current_user_can('edit_posts')) {
        return;
    }
    if (empty($submenu['edit.php'])) {
        return;
    }

    // 找到 post-new.php 那一项，挪到最前并加标记
    $found = null;
    foreach ($submenu['edit.php'] as $i => $item) {
        if (isset($item[2]) && $item[2] === 'post-new.php') {
            $found = $submenu['edit.php'][$i];
            unset($submenu['edit.php'][$i]);
            break;
        }
    }
    if ($found) {
        // 标题前缀「＋ 」，一眼分辨「新建」与「列表」
        $found[0] = '＋ ' . $found[0];
        array_unshift($submenu['edit.php'], $found);
        $submenu['edit.php'] = array_values($submenu['edit.php']);
    }
}

/**
 * 5) 后台页脚写明主题与来源。
 *
 * 上游 Sakurairo 的后台页脚只提 CSF 框架，看不出跑的是哪个 fork；
 * 这里补一行，方便日后回看站点到底部署的是哪一支。
 */
add_filter('admin_footer_text', 'ybh_admin_footer_text');
function ybh_admin_footer_text($text)
{
    if (!ybh_admin_skin_on()) {
        return $text;
    }
    return sprintf(
        '<span class="ybh-admin-credit">%s</span>',
        sprintf(
            /* translators: 1: theme name+version, 2: fork repo url, 3: upstream repo url */
            __('%1$s —— 由 Fuukei 的 <a href="%3$s" target="_blank" rel="noopener">Sakurairo</a> 二次开发，项目地址 <a href="%2$s" target="_blank" rel="noopener">GitHub</a>', 'sakurairo'),
            'Theme SakurairoYBH v' . esc_html(defined('IRO_VERSION') ? IRO_VERSION : ''),
            'https://github.com/yiwubianjihui/SakurairoYBH',
            'https://github.com/mirai-mamori/Sakurairo'
        )
    );
}
