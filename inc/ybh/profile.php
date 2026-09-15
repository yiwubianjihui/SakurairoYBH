<?php
/**
 * YBH · 前台个人资料页（T34）
 *
 * 背景：本站原先只有 wp-admin 的「个人资料」页，而顶部用户菜单直接把人送进后台。
 * 对投稿同学来说后台是个很重的地方（菜单全是编辑功能，还容易被误点），
 * 改个头像要穿过整个仪表盘。这里做一个**前台**的资料页：新建一个页面写
 * `[ybh_profile]`，用户从顶部菜单点自己名字进来即可。
 *
 * ---------------------------------------------------------------
 * 包含四个板块（按用户 2026-09-14 的勾选）
 *
 *   ① 头像      上传 / 恢复默认   ← 直接复用 T24 的 [ybh_avatar_upload]，不重写上传管线
 *   ② 基本资料  昵称 / 显示名 / 个人网站 / 个人简介
 *   ③ 修改密码  需当前密码；改完**保持登录**（wp_set_password 会把所有会话踢掉）
 *   ④ 我的投稿  自己名下文章 + 状态（草稿/待审核/已发布）
 *
 * **不含**修改邮箱（用户明确不要：改邮箱会牵动登录与头像 hash，得走确认邮件，
 * 放在这种"随手改一下"的页面上风险大于便利）。
 *
 * ---------------------------------------------------------------
 * 三个必须记住的实现要点
 *
 *   1. 🔴 **本页绝不能被页面缓存**。它是登录用户专属的，LiteSpeed 一旦缓存，
 *      A 同学会看到 B 同学的头像和昵称。靠 `DONOTCACHEPAGE` + `nocache_headers()`，
 *      且判定放在 `template_redirect` 最前面（LSCache 在那之后才决定缓存）。
 *      判定方式是「正文里有 [ybh_profile] 短代码」而不是写死 slug ——
 *      页面换 slug、或再造第二个资料页，都自动生效。
 *
 *   2. 三个板块三个独立表单（头像走 admin-post.php?action=ybh_avatar_upload，
 *      基本资料与密码各走自己的 action）。理由：密码填错不该把昵称的修改一起丢掉。
 *
 *   3. 密码改完要**重新发登录 cookie**。`wp_set_password()` 会清掉该用户所有会话，
 *      不补这一步用户会莫名其妙被登出，然后以为"改密码把账号搞坏了"。
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ===================================================================== *
 * 地址
 * ===================================================================== */

/**
 * 资料页地址。
 *
 * 按 slug `profile` 找页面；页面还没建时退回约定路径，这样菜单链接不会 404 到首页。
 */
function ybh_profile_url(): string
{
    static $url = null;
    if ($url !== null) {
        return $url;
    }
    $slug = (string) apply_filters('ybh_profile_slug', 'profile');
    $page = get_page_by_path($slug);
    $url  = $page ? (string) get_permalink($page) : home_url('/' . $slug . '/');
    return $url;
}

/**
 * 本页禁止被任何页面缓存。
 *
 * 见文件头要点 1。判定用短代码而不是 slug，页面换名字也不会漏。
 */
add_action('template_redirect', 'ybh_profile_no_cache', 0);
function ybh_profile_no_cache()
{
    if (is_admin() || !is_singular()) {
        return;
    }
    $post = get_queried_object();
    if (!$post instanceof WP_Post) {
        return;
    }
    if (!has_shortcode((string) $post->post_content, 'ybh_profile')) {
        return;
    }
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    // 顺带告诉 LiteSpeed（不同版本认的常量不一样，两个都给）
    if (!defined('LSCACHE_NO_CACHE')) {
        define('LSCACHE_NO_CACHE', true);
    }
    nocache_headers();
}

/* ===================================================================== *
 * 提示信息
 * ===================================================================== */

/**
 * 各种结果码 → 文案。集中在一处，省得散落在四个分支里。
 *
 * @return array{0:string,1:bool} [文案, 是否成功]
 */
function ybh_profile_message(string $code): array
{
    $map = array(
        'basic-ok'          => array('资料已保存。', true),
        'basic-err-empty'   => array('昵称不能为空。', false),
        'basic-err-url'     => array('个人网站要是一个网址（http:// 或 https:// 开头）。', false),
        'basic-err-fail'    => array('保存失败，请稍后再试。', false),
        'pwd-ok'            => array('密码已修改，当前登录状态已保留。', true),
        'pwd-err-current'   => array('当前密码不正确。', false),
        'pwd-err-short'     => array('新密码至少 8 位。', false),
        'pwd-err-same'      => array('新密码不能和当前密码相同。', false),
        'pwd-err-match'     => array('两次输入的新密码不一致。', false),
        'pwd-err-fail'      => array('密码修改失败，请稍后再试。', false),
    );
    return $map[$code] ?? array('', false);
}

/** 从当前 URL 上取结果码（表单提交后重定向回来带的）。 */
function ybh_profile_current_message(): array
{
    if (empty($_GET['ybh_p'])) {
        return array('', false);
    }
    return ybh_profile_message(sanitize_key((string) $_GET['ybh_p']));
}

/* ===================================================================== *
 * 表单处理
 * ===================================================================== */

/**
 * 保存基本资料（昵称 / 显示名 / 个人网站 / 个人简介）。
 */
add_action('admin_post_ybh_profile_basic', 'ybh_profile_save_basic');
function ybh_profile_save_basic()
{
    $uid = get_current_user_id();
    if ($uid <= 0) {
        wp_safe_redirect(home_url('/'));
        exit;
    }

    $back = wp_get_referer() ?: ybh_profile_url();

    $nonce = isset($_POST['ybh_profile_nonce']) ? (string) $_POST['ybh_profile_nonce'] : '';
    if (!wp_verify_nonce($nonce, 'ybh_profile_basic_' . $uid)) {
        wp_safe_redirect(add_query_arg('ybh_p', 'basic-err-fail', $back));
        exit;
    }

    $display = isset($_POST['display_name']) ? sanitize_text_field(wp_unslash((string) $_POST['display_name'])) : '';
    $nick    = isset($_POST['nickname']) ? sanitize_text_field(wp_unslash((string) $_POST['nickname'])) : '';
    $desc    = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash((string) $_POST['description'])) : '';
    $site    = isset($_POST['user_url']) ? trim(wp_unslash((string) $_POST['user_url'])) : '';

    if ($display === '') {
        wp_safe_redirect(add_query_arg('ybh_p', 'basic-err-empty', $back));
        exit;
    }
    // 昵称留空就跟着显示名走，省得用户填两遍一样的东西
    if ($nick === '') {
        $nick = $display;
    }

    if ($site !== '') {
        // 用户往往只写 "example.com"，补上协议比报错友好
        if (!preg_match('#^https?://#i', $site)) {
            $site = 'https://' . $site;
        }
        $site = esc_url_raw($site);
        if ($site === '' || !preg_match('#^https?://[^\s.]+\.#i', $site)) {
            wp_safe_redirect(add_query_arg('ybh_p', 'basic-err-url', $back));
            exit;
        }
    }

    $res = wp_update_user(array(
        'ID'           => $uid,
        'display_name' => $display,
        'nickname'     => $nick,
        'description'  => $desc,
        'user_url'     => $site,
    ));

    wp_safe_redirect(add_query_arg(
        'ybh_p',
        is_wp_error($res) ? 'basic-err-fail' : 'basic-ok',
        $back
    ));
    exit;
}

/**
 * 修改密码。
 *
 * 必须验当前密码：本站账号是老师手工开通的，被盗号改密码的代价很高。
 */
add_action('admin_post_ybh_profile_pwd', 'ybh_profile_save_pwd');
function ybh_profile_save_pwd()
{
    $uid = get_current_user_id();
    if ($uid <= 0) {
        wp_safe_redirect(home_url('/'));
        exit;
    }

    $back = wp_get_referer() ?: ybh_profile_url();
    $fail = function (string $code) use ($back) {
        wp_safe_redirect(add_query_arg('ybh_p', $code, $back));
        exit;
    };

    $nonce = isset($_POST['ybh_pwd_nonce']) ? (string) $_POST['ybh_pwd_nonce'] : '';
    if (!wp_verify_nonce($nonce, 'ybh_profile_pwd_' . $uid)) {
        $fail('pwd-err-fail');
    }

    // 密码不过 wp_unslash —— 反斜杠可能是密码的一部分，转义会把用户的密码改掉
    $cur  = isset($_POST['current_pass']) ? (string) $_POST['current_pass'] : '';
    $new  = isset($_POST['new_pass']) ? (string) $_POST['new_pass'] : '';
    $new2 = isset($_POST['new_pass2']) ? (string) $_POST['new_pass2'] : '';

    $user = get_userdata($uid);
    if (!$user || !wp_check_password($cur, (string) $user->user_pass, $uid)) {
        $fail('pwd-err-current');
    }
    if (strlen($new) < 8) {
        $fail('pwd-err-short');
    }
    if ($new === $cur) {
        $fail('pwd-err-same');
    }
    if ($new !== $new2) {
        $fail('pwd-err-match');
    }

    wp_set_password($new, $uid);

    /*
     * wp_set_password() 会销毁该用户的所有会话（包括当前这个）。
     * 不补下面几步，用户改完密码会看到"已保存"然后下一秒变成未登录。
     */
    clean_user_cache($uid);
    wp_clear_auth_cookie();
    wp_set_current_user($uid);
    wp_set_auth_cookie($uid, true, is_ssl());

    wp_safe_redirect(add_query_arg('ybh_p', 'pwd-ok', $back));
    exit;
}

/* ===================================================================== *
 * 页面渲染
 * ===================================================================== */

/**
 * 短代码 `[ybh_profile]` —— 渲染整个资料页。
 */
add_shortcode('ybh_profile', 'ybh_profile_shortcode');
function ybh_profile_shortcode($atts = array())
{
    if (!is_user_logged_in()) {
        $here = get_permalink() ?: home_url('/');
        return '<div class="ybh-pf-guest">'
            . '<p>登录后就能在这里改头像、昵称和密码，还能看到自己投稿的审核进度。</p>'
            . '<p><a class="ybh-pf-btn" href="' . esc_url(wp_login_url($here)) . '">去登录</a></p>'
            . '</div>';
    }

    $uid  = get_current_user_id();
    $user = wp_get_current_user();
    list($msg, $msgOk) = ybh_profile_current_message();

    ob_start();
    ?>
    <div class="ybh-pf">

      <?php if ($msg !== '') : ?>
        <div class="ybh-pf-msg <?php echo $msgOk ? 'ok' : 'err'; ?>"><?php echo esc_html($msg); ?></div>
      <?php endif; ?>

      <!-- ① 头像 -->
      <section class="ybh-pf-card" id="ybh-pf-avatar">
        <h2 class="ybh-pf-title"><i class="fa-solid fa-image-portrait"></i> 头像</h2>
        <?php echo ybh_avatar_upload_shortcode(); // 复用 T24 的上传管线，见文件头说明 ?>
      </section>

      <!-- ② 基本资料 -->
      <section class="ybh-pf-card">
        <h2 class="ybh-pf-title"><i class="fa-solid fa-id-card"></i> 基本资料</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="ybh_profile_basic" />
          <?php wp_nonce_field('ybh_profile_basic_' . $uid, 'ybh_profile_nonce'); ?>

          <div class="ybh-pf-row">
            <label for="ybh-pf-display">显示名 <span class="ybh-pf-req">*</span></label>
            <input type="text" id="ybh-pf-display" name="display_name" required maxlength="60"
                   value="<?php echo esc_attr($user->display_name); ?>" />
            <p class="ybh-pf-hint">评论区、文章署名显示的就是这个名字。</p>
          </div>

          <div class="ybh-pf-row">
            <label for="ybh-pf-nick">昵称</label>
            <input type="text" id="ybh-pf-nick" name="nickname" maxlength="60"
                   value="<?php echo esc_attr($user->nickname); ?>" />
            <p class="ybh-pf-hint">留空就跟显示名一致。</p>
          </div>

          <div class="ybh-pf-row">
            <label for="ybh-pf-site">个人网站</label>
            <input type="text" id="ybh-pf-site" name="user_url" maxlength="200"
                   value="<?php echo esc_attr($user->user_url); ?>" placeholder="example.com" />
            <p class="ybh-pf-hint">填了会显示在你评论的昵称旁边，不填也没关系。</p>
          </div>

          <div class="ybh-pf-row">
            <label for="ybh-pf-desc">个人简介</label>
            <textarea id="ybh-pf-desc" name="description" rows="4" maxlength="500"><?php echo esc_textarea($user->description); ?></textarea>
          </div>

          <p class="ybh-pf-actions">
            <button type="submit" class="ybh-pf-btn primary">保存资料</button>
          </p>
        </form>
      </section>

      <!-- ③ 修改密码 -->
      <section class="ybh-pf-card">
        <h2 class="ybh-pf-title"><i class="fa-solid fa-key"></i> 修改密码</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off">
          <input type="hidden" name="action" value="ybh_profile_pwd" />
          <?php wp_nonce_field('ybh_profile_pwd_' . $uid, 'ybh_pwd_nonce'); ?>

          <div class="ybh-pf-row">
            <label for="ybh-pf-cur">当前密码 <span class="ybh-pf-req">*</span></label>
            <input type="password" id="ybh-pf-cur" name="current_pass" required autocomplete="current-password" />
          </div>
          <div class="ybh-pf-row">
            <label for="ybh-pf-new">新密码 <span class="ybh-pf-req">*</span></label>
            <input type="password" id="ybh-pf-new" name="new_pass" required minlength="8" autocomplete="new-password" />
            <p class="ybh-pf-hint">至少 8 位。</p>
          </div>
          <div class="ybh-pf-row">
            <label for="ybh-pf-new2">再输一次 <span class="ybh-pf-req">*</span></label>
            <input type="password" id="ybh-pf-new2" name="new_pass2" required minlength="8" autocomplete="new-password" />
          </div>

          <p class="ybh-pf-actions">
            <button type="submit" class="ybh-pf-btn primary">修改密码</button>
          </p>
        </form>
      </section>

      <!-- ④ 我的投稿 -->
      <section class="ybh-pf-card">
        <h2 class="ybh-pf-title"><i class="fa-solid fa-pen-nib"></i> 我的投稿</h2>
        <?php echo ybh_profile_submissions_html($uid); ?>
      </section>

    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * 「我的投稿」列表。
 *
 * 状态用中文标签 + 颜色点，不用 WP 的英文 slug —— 投稿同学看不懂 "pending"。
 */
function ybh_profile_submissions_html(int $uid): string
{
    $labels = array(
        'publish' => array('已发布', 'pub'),
        'pending' => array('待审核', 'wait'),
        'draft'   => array('草稿', 'draft'),
        'future'  => array('定时发布', 'wait'),
        'private' => array('私密', 'draft'),
    );

    $posts = get_posts(array(
        'author'        => $uid,
        'post_type'     => 'post',
        'post_status'   => array_keys($labels),
        'numberposts'   => 50,
        'orderby'       => 'modified',
        'order'         => 'DESC',
        'no_found_rows' => true,
    ));

    if (!$posts) {
        return '<p class="ybh-pf-empty">还没有投过稿。'
            . '<a href="' . esc_url(home_url('/submit/')) . '">看看怎么投稿 →</a></p>';
    }

    $out = '<ul class="ybh-pf-subs">';
    foreach ($posts as $p) {
        $st = $labels[$p->post_status] ?? array($p->post_status, 'draft');
        $isPub = ($p->post_status === 'publish');
        $title = get_the_title($p) ?: '（无标题）';

        if ($isPub) {
            $link = '<a href="' . esc_url((string) get_permalink($p)) . '">' . esc_html($title) . '</a>';
            $act  = '';
        } else {
            // 未发布：给「继续编辑」，直接落到编辑器（草稿不给链接等于看不见）
            $link = '<span class="ybh-pf-sub-title">' . esc_html($title) . '</span>';
            $act  = '<a class="ybh-pf-sub-act" href="'
                  . esc_url(admin_url('post.php?post=' . (int) $p->ID . '&action=edit')) . '">继续编辑</a>';
        }

        $out .= '<li>'
              . '<span class="ybh-pf-sub-status ' . esc_attr($st[1]) . '">' . esc_html($st[0]) . '</span>'
              . '<span class="ybh-pf-sub-main">' . $link . '</span>'
              . '<span class="ybh-pf-sub-date">' . esc_html(get_the_modified_date('Y-m-d', $p)) . '</span>'
              . $act
              . '</li>';
    }
    $out .= '</ul>';

    $out .= '<p class="ybh-pf-hint">未发布的文章点「继续编辑」回到编辑器；'
          . '审核通过后会自动出现在站点上。</p>';

    return $out;
}

/* ===================================================================== *
 * 入口
 * ===================================================================== */

/**
 * 评论区给已登录用户的一个小提示条（评论表单上方）。
 *
 * 为什么放这里：登录用户在评论表单里**看不到任何字段**（主题对登录用户隐藏了
 * 昵称/邮箱输入框），所以那是唯一会让人产生"我想换个头像/改个名字，去哪改？"的地方。
 */
function ybh_profile_comment_hint(): string
{
    if (!is_user_logged_in()) {
        return '';
    }
    $u = wp_get_current_user();
    return '<div class="ybh-pf-cmt-hint">'
        . '<img src="' . esc_url(ybh_avatar_build_url((int) $u->ID, 40)) . '" alt="" width="28" height="28" />'
        . '<span>以 <b>' . esc_html($u->display_name) . '</b> 的身份评论</span>'
        . '<a href="' . esc_url(ybh_profile_url()) . '">改头像 / 昵称</a>'
        . '</div>';
}
