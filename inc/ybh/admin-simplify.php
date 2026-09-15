<?php
/**
 * SakurairoYBH · 后台精简层（admin-simplify.php）
 *
 * ## 要解决的问题
 *
 * 本站 `default_role = contributor`，26 个用户里绝大多数是投稿同学。对只需要
 * 「写稿 / 看自己的稿 / 改资料」的人来说，WordPress 完整的左侧菜单（文章、媒体、
 * 页面、评论、外观、插件、用户、工具、设置…）绝大部分点进去就是「抱歉，您不能
 * 访问此页面」，还会因为误点而困惑。T33 已经做过「编辑页把侧栏折成图标条」，
 * 但那只在文章/页面编辑页生效，且仍留着一列图标。
 *
 * ## 本模块做什么
 *
 * 1. **站点级完全隐藏侧边栏**（不只是折叠）——加 `ybh-admin-simple` body class，
 *    由 `css/ybh-admin-simple.css` 隐藏 `#adminmenumain` 整块并归零 `#wpcontent` 的左外边距；
 * 2. **顶部一条极简导航取而代之**：写文章 / 我的文章 / 待审核（带数量）/ 媒体库，
 *    右侧是当前身份、回到站点、退出。有这一条，侧边栏的存在就只剩干扰；
 * 3. **仪表盘只留「投稿 · 快捷面板」**，其余小工具（WordPress 新闻、活动、站点健康…）
 *    对这类账号没有任何可操作内容，全部移除；
 * 4. **顺手收掉几处噪音**：屏幕选项/帮助标签、核心更新提示；
 * 5. 把「个人资料」从 `wp-admin/profile.php` 改到前台 `[ybh_profile]` 资料页
 *    （与 T34 顶部菜单的处理保持一致）。
 *
 * ## 判定方式：能力，不是角色名
 *
 * 用 `current_user_can('edit_others_posts')` —— 编辑与管理员有，投稿者/作者/订阅者没有。
 * 好处：站点改角色显示名、装插件新增自定义角色，这个判定都自动正确；
 * 角色名一旦写死，改个名就全线失效。
 *
 * 管理员与编辑**完全不受影响**（本模块所有钩子都在开头 `return`）。
 *
 * 开关：`iro_opt('ybh_simple_admin', true)`，位置「YBH 魔改 → 管理后台」。
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

/** 是否启用后台精简（默认开）。 */
function ybh_simple_admin_on()
{
    return (bool) iro_opt('ybh_simple_admin', true);
}

/**
 * 当前登录用户是否套用「精简后台」。
 *
 * 注意判定顺序：先关掉开关、再排除有 `edit_others_posts` 的账号，
 * 最后才看是否登录 —— 任一环节短路都不做任何改动。
 */
function ybh_simple_admin_user()
{
    if (!ybh_simple_admin_on()) {
        return false;
    }
    if (!is_user_logged_in()) {
        return false;
    }
    // 编辑 / 管理员保留完整后台：他们要审别人的稿、管分类、装插件。
    if (current_user_can('edit_others_posts')) {
        return false;
    }
    return true;
}

/** 角色键 → 中文标签（只为顶部导航上那枚小徽章）。 */
function ybh_simple_role_label(WP_User $user)
{
    $map = array(
        'administrator' => '管理员',
        'editor'        => '编辑',
        'author'        => '作者',
        'contributor'   => '投稿者',
        'subscriber'    => '订阅者',
    );
    $role = empty($user->roles) ? '' : (string) $user->roles[0];
    if (isset($map[$role])) {
        return $map[$role];
    }
    // 未知角色（插件自定义）：把角色名当标签用，翻译表里没有就原样显示。
    return $role === '' ? '用户' : $role;
}

/* ---------------------------------------------------------------------------
 * 1) body class
 * ------------------------------------------------------------------------- */

add_filter('admin_body_class', 'ybh_simple_admin_body_class');
function ybh_simple_admin_body_class($classes)
{
    if (!ybh_simple_admin_user()) {
        return $classes;
    }
    // 去掉核心的 folded / auto-fold：侧栏整个不显示了，折叠态只会在
    // #wpcontent 上留下一段没有任何意义的左外边距。
    $classes = preg_replace('/\b(auto-fold|folded)\b/', '', (string) $classes);
    return trim($classes . ' ybh-admin-simple');
}

/* ---------------------------------------------------------------------------
 * 2) 样式层（只在精简账号下加载，管理员一个字节都不下）
 * ------------------------------------------------------------------------- */

add_action('admin_enqueue_scripts', 'ybh_simple_admin_assets');
function ybh_simple_admin_assets()
{
    if (!ybh_simple_admin_user()) {
        return;
    }
    wp_enqueue_style(
        'ybh-admin-simple',
        get_template_directory_uri() . '/css/ybh-admin-simple.css',
        array(),
        defined('YBH_VERSION') ? YBH_VERSION : '1'
    );
}

/* ---------------------------------------------------------------------------
 * 3) 顶部极简导航
 *
 * 挂 `in_admin_header`：核心输出顺序是
 *   #wpwrap > #wpcontent > [in_admin_header] > #wpbody > #wpbody-content
 * 所以这里打印的元素正好落在内容区最上方、侧栏原本占据的那条横带里。
 * ------------------------------------------------------------------------- */

add_action('in_admin_header', 'ybh_simple_admin_nav');
function ybh_simple_admin_nav()
{
    if (!ybh_simple_admin_user()) {
        return;
    }

    $user  = wp_get_current_user();
    $uid   = (int) $user->ID;
    $here  = isset($GLOBALS['pagenow']) ? (string) $GLOBALS['pagenow'] : '';
    $sub   = isset($_GET['post_status']) ? sanitize_key($_GET['post_status']) : '';

    // 自己的待审核数量：用 WP_Query 按 author 限制，不用 wp_count_posts()
    // ——后者统计全站，投稿者看到别人的数字只会更困惑。
    $pending_q = new WP_Query(array(
        'post_type'      => 'post',
        'post_status'    => 'pending',
        'author'         => $uid,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => false,
    ));
    $pending = (int) $pending_q->found_posts;

    $items = array();

    $items[] = array(
        'href'    => admin_url('post-new.php'),
        'icon'    => 'dashicons-edit-page',
        'label'   => '写文章',
        'primary' => true,
        'active'  => ($here === 'post-new.php'),
    );

    $items[] = array(
        'href'   => admin_url('edit.php'),
        'icon'   => 'dashicons-admin-post',
        'label'  => '我的文章',
        'active' => ($here === 'edit.php' && $sub === ''),
    );

    $items[] = array(
        'href'   => admin_url('edit.php?post_status=pending'),
        'icon'   => 'dashicons-clock',
        'label'  => '待审核',
        'badge'  => $pending > 0 ? (string) $pending : '',
        'active' => ($here === 'edit.php' && $sub === 'pending'),
    );

    // 媒体库：主题已放开投稿者上传（见 T34 的 YBH_ALLOW_CONTRIBUTOR_UPLOAD），
    // 但能力仍是最终判据 —— 站点把上传权限收回去时这里会自动消失。
    if (current_user_can('upload_files')) {
        $items[] = array(
            'href'   => admin_url('upload.php'),
            'icon'   => 'dashicons-format-image',
            'label'  => '媒体库',
            'active' => ($here === 'upload.php'),
        );
    }

    $profile = function_exists('ybh_profile_url')
        ? ybh_profile_url()
        : get_edit_profile_url($uid);

    ?>
    <div class="ybh-snav">
      <div class="ybh-snav-inner">

        <div class="ybh-snav-id">
          <span class="ybh-snav-avatar"><?php echo get_avatar($uid, 28, '', '', array('class' => 'ybh-snav-avatar-img')); ?></span>
          <span class="ybh-snav-idtext">
            <b><?php echo esc_html($user->display_name); ?></b>
            <small><?php echo esc_html(ybh_simple_role_label($user)); ?></small>
          </span>
        </div>

        <nav class="ybh-snav-links" aria-label="<?php esc_attr_e('后台导航', 'sakurairo'); ?>">
          <?php foreach ($items as $it) : ?>
            <a class="ybh-snav-link<?php echo !empty($it['primary']) ? ' is-primary' : ''; ?><?php echo !empty($it['active']) ? ' is-active' : ''; ?>"
               href="<?php echo esc_url($it['href']); ?>">
              <span class="dashicons <?php echo esc_attr($it['icon']); ?>"></span>
              <span><?php echo esc_html($it['label']); ?></span>
              <?php if (!empty($it['badge'])) : ?>
                <i class="ybh-snav-badge"><?php echo esc_html($it['badge']); ?></i>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </nav>

        <div class="ybh-snav-tail">
          <a class="ybh-snav-minor" href="<?php echo esc_url($profile); ?>">
            <span class="dashicons dashicons-admin-users"></span><span><?php esc_html_e('资料', 'sakurairo'); ?></span>
          </a>
          <a class="ybh-snav-minor" href="<?php echo esc_url(home_url('/')); ?>">
            <span class="dashicons dashicons-external"></span><span><?php esc_html_e('回到站点', 'sakurairo'); ?></span>
          </a>
          <a class="ybh-snav-minor is-quiet" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">
            <span class="dashicons dashicons-migrate"></span><span><?php esc_html_e('退出', 'sakurairo'); ?></span>
          </a>
        </div>

      </div>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * 4) 仪表盘：只留 YBH 快捷面板
 * ------------------------------------------------------------------------- */

add_action('wp_dashboard_setup', 'ybh_simple_admin_dashboard', 999);
function ybh_simple_admin_dashboard()
{
    if (!ybh_simple_admin_user()) {
        return;
    }

    // 欢迎面板（「欢迎使用 WordPress！」那一大块）对老用户是纯噪音。
    remove_action('welcome_panel', 'wp_welcome_panel');

    global $wp_meta_boxes;
    if (empty($wp_meta_boxes['dashboard']) || !is_array($wp_meta_boxes['dashboard'])) {
        return;
    }

    foreach ($wp_meta_boxes['dashboard'] as $context => $priorities) {
        if (!is_array($priorities)) {
            continue;
        }
        foreach ($priorities as $priority => $boxes) {
            if (!is_array($boxes)) {
                continue;
            }
            foreach ($boxes as $id => $box) {
                // 只留主题自己的快捷面板；它在 inc/ybh/admin.php 里注册。
                if ($id === 'ybh_quickpost') {
                    continue;
                }
                unset($wp_meta_boxes['dashboard'][$context][$priority][$id]);
            }
        }
    }
}

/* ---------------------------------------------------------------------------
 * 5) 收噪音：屏幕选项 / 帮助标签 / 核心更新提示
 * ------------------------------------------------------------------------- */

add_filter('screen_options_show_screen', 'ybh_simple_admin_no_screen_options');
function ybh_simple_admin_no_screen_options($show)
{
    return ybh_simple_admin_user() ? false : $show;
}

add_action('admin_head', 'ybh_simple_admin_trim_head');
function ybh_simple_admin_trim_head()
{
    if (!ybh_simple_admin_user()) {
        return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen) {
        // 帮助标签里讲的是核心功能用法，对投稿同学是「又一个可点的东西」。
        $screen->remove_help_tabs();
    }
    // 「WordPress x.y 已发布」——他们没有升级权限，提示只会制造焦虑。
    remove_action('admin_notices', 'update_nag', 3);
    remove_action('network_admin_notices', 'update_nag', 3);
}

/* ---------------------------------------------------------------------------
 * 6) 个人资料改到前台
 *
 * T34 已经做了前台 `[ybh_profile]` 资料页（头像/昵称/简介/密码/我的投稿），
 * 比 wp-admin 的 profile.php 轻得多。这里把后台入口也一并改指过去，
 * 保持「站内只有一个资料页」的直觉。
 *
 * 只在资料页确实存在时重定向，避免页面被删后把人送进 404。
 * ------------------------------------------------------------------------- */

add_action('load-profile.php', 'ybh_simple_admin_redirect_profile');
function ybh_simple_admin_redirect_profile()
{
    if (!ybh_simple_admin_user() || !function_exists('ybh_profile_url')) {
        return;
    }
    $url = ybh_profile_url();
    if (!$url || $url === get_edit_profile_url(get_current_user_id())) {
        return;
    }
    // 带 ?updated=true 之类的结果参数时不要拦：那是别的表单跳回来的，
    // 拦掉会把「已保存」的反馈吃掉。
    if (!empty($_GET)) {
        return;
    }
    wp_safe_redirect($url);
    exit;
}

/**
 * 管理条里的「编辑个人资料」也指向前台资料页。
 *
 * 内置节点的 href 是核心写死的，只能在 `admin_bar_menu` 里改。
 */
add_action('admin_bar_menu', 'ybh_simple_admin_fix_bar', 999);
function ybh_simple_admin_fix_bar($wp_admin_bar)
{
    if (!ybh_simple_admin_user() || !function_exists('ybh_profile_url')) {
        return;
    }
    $url = ybh_profile_url();
    if (!$url) {
        return;
    }
    // 节点 id 是核心写死的 `edit-profile`，href 指向 wp-admin/profile.php。
    // add_node() 内部会 (array) 转换，直接回传对象即可覆盖。
    $node = $wp_admin_bar->get_node('edit-profile');
    if ($node) {
        $node->href = $url;
        $wp_admin_bar->add_node($node);
    }
}

/* ---------------------------------------------------------------------------
 * 7) 落地页：/wp-admin/ → 「我的文章」
 *
 * 上游 Sakurairo 的 `remove_dashboard()`（functions.php，钩在 admin_menu 上）对
 * **所有非管理员**做了两件事：从菜单里摘掉「仪表盘」，并在请求正是
 * `/wp-admin/`（或 index.php）时 `wp_safe_redirect(admin_url('profile.php'))`。
 *
 * 所以投稿同学敲 /wp-admin/ 的默认落点是「个人资料」表单。对他们来说，
 * 最该看到的是自己的稿子（列表里有各状态、以及 T33 加过的「＋ 写文章」按钮），
 * 因此这里把落点改成文章列表。
 *
 * 三个实现要点：
 *   1. 挂 `admin_menu` 的 **999**（晚于上游的默认 10）——上游那次 `wp_safe_redirect()`
 *      后面**没有 `exit`**，所以第二次设置 Location 会覆盖它；
 *   2. 本轮**务必自己 `exit`**，否则页面会继续往下渲染（核心的跳转就不 exit，
 *      属于能跑但很脆的写法，别学）；
 *   3. 只认 `$pagenow === 'index.php'`，比拿 REQUEST_URI 做正则稳
 *      （带查询串时正则匹配不到，会漏）。
 * ------------------------------------------------------------------------- */

add_action('admin_menu', 'ybh_simple_admin_landing', 999);
function ybh_simple_admin_landing()
{
    if (!ybh_simple_admin_user()) {
        return;
    }
    if (!isset($GLOBALS['pagenow']) || $GLOBALS['pagenow'] !== 'index.php') {
        return;
    }
    wp_safe_redirect(admin_url('edit.php'), 302);
    exit;
}
