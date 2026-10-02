<?php
/**
 * YBH · Web 应用（PWA）教程页 —— `/pwa-guide/`（T67c；T66 起英文/日文）
 *
 * ===================================================================
 * 为什么要有这一页
 * ===================================================================
 *   站长反馈：吸底那条「把本站装到主屏幕」的提示只给了「以后再说」，
 *   想弄清楚"装了什么、会不会占空间、怎么卸载"的人**没有出口**。
 *   于是：提示条加「查看详情」→ 这一页；客户端下载页也链过来。
 *
 * ===================================================================
 * 实现方式
 * ===================================================================
 *   页面内容是**短代码** `[ybh_pwa_guide]`，正文里只有这么一行 ——
 *   与更新日志页（`[ybh_changelog]`）同一套路：排版与文案随主题版本走。
 *   页面由 `ybh_pwa_guide_maybe_create_page()` **幂等自动创建**。
 *
 *   T66：整页文案过 `ybh_t()`；**带链接的句子用 `%s` 占位整句翻译**
 *   （按链接把句子切成碎片再逐片翻译，中文/英文/日文的语序会拼不通顺）。
 *   内容由短代码现场生成 ⇒ **不需要**为这一页在库里存译文。
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

/** 教程页 slug */
function ybh_pwa_guide_slug()
{
    return 'pwa-guide';
}

/** 教程页地址（没建页时也返回一个可用地址，供提示条链接） */
function ybh_pwa_guide_url()
{
    $page = get_page_by_path(ybh_pwa_guide_slug());
    return $page ? get_permalink($page->ID) : home_url('/' . ybh_pwa_guide_slug() . '/');
}

/* ---------------------------------------------------------------------------
 * 自动建页（幂等）
 * ------------------------------------------------------------------------- */
add_action('admin_init', 'ybh_pwa_guide_maybe_create_page');
function ybh_pwa_guide_maybe_create_page()
{
    if (get_page_by_path(ybh_pwa_guide_slug())) {
        return;
    }
    $id = wp_insert_post(array(
        'post_title'   => '把本站装成 Web 应用',
        'post_name'    => ybh_pwa_guide_slug(),
        'post_content' => '[ybh_pwa_guide]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_author'  => get_current_user_id(),
        'comment_status' => 'closed',
        'ping_status'  => 'closed',
    ), true);
    if (!is_wp_error($id)) {
        update_post_meta($id, '_ybh_auto_created', 'pwa-guide');
    }
}

/** 带链接的整句：`%s` 处填 HTML（自己负责转义） */
function ybh_guide_t($key, ...$args)
{
    return vsprintf(ybh_t($key), $args);
}

/** 短代码：`[ybh_pwa_guide]` */
add_shortcode('ybh_pwa_guide', 'ybh_pwa_guide_shortcode');
function ybh_pwa_guide_shortcode($atts = array())
{
    $home    = esc_url(home_url('/'));
    $submit  = esc_url(home_url('/submit/'));
    $policy  = esc_url(function_exists('ybh_consent_policy_url') ? ybh_consent_policy_url() : home_url('/cookie-policy/'));
    $privacy = esc_url(home_url('/privacy-policy/'));
    $a_home    = '<a href="' . $home . '">' . esc_html(ybh_t('本站首页')) . '</a>';
    $a_policy  = '<a href="' . $policy . '">' . esc_html(ybh_t('《Cookie 政策》')) . '</a>';
    $a_privacy = '<a href="' . $privacy . '">' . esc_html(ybh_t('《隐私政策》')) . '</a>';
    $a_submit  = '<a href="' . $submit . '">' . esc_html(ybh_t('投稿页')) . '</a>';

    ob_start();
    ?>
    <div class="ybh-guide">

      <p class="ybh-guide__lead">
        <?php echo wp_kses_post(ybh_t('本站可以被「装」到手机或电脑的桌面上，之后像普通 App 一样打开：')); ?>
        <strong><?php ybh_e('没有地址栏、字体与文章会被缓存'); ?></strong><?php ybh_e('，弱网下也能读。'); ?>
        <?php echo wp_kses_post(ybh_t('它<b>不是</b>应用商店里的安装包 —— 不占几百 MB，也不用更新。')); ?>
      </p>

      <div class="ybh-guide__note">
        <strong><?php ybh_e('和「客户端」有什么区别？'); ?></strong>
        <?php echo wp_kses_post(ybh_guide_t(
            '客户端（%s）是独立的应用程序；装 Web 应用只是让浏览器把本站放到桌面快捷方式里，<b>不需要下载、不需要安装权限、随时删掉不留残留</b>。两者可以同时用。',
            '<a href="https://app.yibianhui.cn" rel="noopener">app.yibianhui.cn</a>'
        )); ?>
      </div>

      <h2><?php ybh_e('一、iPhone / iPad（Safari）'); ?></h2>
      <ol class="ybh-guide__steps">
        <li><?php echo wp_kses_post(ybh_guide_t('用 %s 打开 %s（微信内置浏览器不行）。', '<b>Safari</b>', $a_home)); ?></li>
        <li><?php echo wp_kses_post(ybh_guide_t('点屏幕底部中间的%s按钮（方框加向上箭头）。', '<b>「分享」</b>')); ?></li>
        <li><?php echo wp_kses_post(ybh_guide_t('在弹出的列表里往下滑，选%s。', '<b>「添加到主屏幕」</b>')); ?></li>
        <li><?php echo wp_kses_post(ybh_guide_t('给它起个名字（默认「YBH」即可），点右上角%s。', '<b>「添加」</b>')); ?></li>
      </ol>

      <h2><?php ybh_e('二、Android（Chrome / Edge）'); ?></h2>
      <ol class="ybh-guide__steps">
        <li><?php ybh_e('用 Chrome 或 Edge 打开本站。'); ?></li>
        <li><?php echo wp_kses_post(ybh_guide_t('点右上角%s，选%s或%s。', '<b>「⋮」菜单</b>', '<b>「安装应用」</b>', '<b>「添加到主屏幕」</b>')); ?></li>
        <li><?php echo wp_kses_post(ybh_guide_t('有些机型会先弹一条「把本站装到主屏幕」的提示 —— 直接点%s即可。', '<b>「安装」</b>')); ?></li>
      </ol>

      <h2><?php ybh_e('三、电脑（Chrome / Edge）'); ?></h2>
      <ol class="ybh-guide__steps">
        <li><?php echo wp_kses_post(ybh_guide_t('打开本站，看地址栏右侧是否有一个%s（方框加箭头）。', '<b>「安装」小图标</b>')); ?></li>
        <li><?php ybh_e('点它，确认安装，就会像桌面软件一样出现独立窗口。'); ?></li>
        <li><?php ybh_e('没有图标时：菜单 →「应用」→「安装此站点」。'); ?></li>
      </ol>

      <h2><?php ybh_e('四、装好之后会有什么不同'); ?></h2>
      <ul class="ybh-guide__list">
        <li><?php echo wp_kses_post(ybh_guide_t('%s：没有地址栏和标签栏，阅读区域更大；', '<b>' . esc_html(ybh_t('全屏打开')) . '</b>')); ?></li>
        <li><?php echo wp_kses_post(ybh_guide_t('%s：字体、样式与常读页面会被缓存，弱网下也能看；', '<b>' . esc_html(ybh_t('打开更快')) . '</b>')); ?></li>
        <li><?php echo wp_kses_post(ybh_guide_t('%s：和别的 App 放在一起，点开就是本站；', '<b>' . esc_html(ybh_t('桌面入口')) . '</b>')); ?></li>
        <li><?php echo wp_kses_post(ybh_guide_t('%s：本站更新后，下次打开就是新版，不需要你去应用商店点更新。', '<b>' . esc_html(ybh_t('更新是自动的')) . '</b>')); ?></li>
      </ul>

      <h2><?php ybh_e('五、怎么卸载'); ?></h2>
      <ul class="ybh-guide__list">
        <li><?php echo wp_kses_post(ybh_guide_t('%s：长按桌面图标 →「移除书签」/「删除书签」；', '<b>iPhone / iPad</b>')); ?></li>
        <li><?php echo wp_kses_post(ybh_guide_t('%s：长按图标 →「卸载」或「移除」；', '<b>Android</b>')); ?></li>
        <li><?php echo wp_kses_post(ybh_guide_t('%s：在应用窗口的菜单里选「卸载 YBH」，或从系统的「已安装应用」里卸载。', '<b>' . esc_html(ybh_t('电脑')) . '</b>')); ?></li>
      </ul>
      <p><?php echo wp_kses_post(ybh_t('卸载只会清掉那个入口与本地缓存，<b>不会删除你的账号、文章或评论</b>。')); ?></p>

      <h2><?php ybh_e('六、常见问题'); ?></h2>
      <p><strong><?php ybh_e('为什么我没看到「安装」提示？'); ?></strong><br>
        <?php ybh_e('这条提示由浏览器自己决定何时给：微信/QQ 等内置浏览器不支持；有些浏览器需要你先访问两次，或你此前点过「以后再说」（本站会安静 30 天再问一次）。按上面第一到第三节手动添加同样有效。'); ?></p>
      <p><strong><?php ybh_e('装了以后还占空间吗？'); ?></strong><br>
        <?php ybh_e('只占很少的缓存（主要是字体与最近读过的页面）。在浏览器设置里随时可以清掉。'); ?></p>
      <p><strong><?php ybh_e('它会不会收集我的信息？'); ?></strong><br>
        <?php echo wp_kses_post(ybh_guide_t('不会。Web 应用只是浏览器的打开方式，本站的数据处理与网页版完全一样 —— 详细说明见%s与%s；同意与否仍由你在 Cookie 面板里决定。', $a_policy, $a_privacy)); ?></p>

      <p class="ybh-guide__foot">
        <?php echo wp_kses_post(ybh_guide_t('还有问题？写信到 %s，或在%s留言告诉我们。', '<a href="mailto:ybh@yibianhui.cn">ybh@yibianhui.cn</a>', $a_submit)); ?>
      </p>
    </div>
    <?php
    return (string) ob_get_clean();
}
