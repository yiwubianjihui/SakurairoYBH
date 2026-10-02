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
 * 拼一个「把 action 放在查询串上」的表单提交地址。
 *
 * ===================================================================
 * ⚠️ 为什么不能用 <input type="hidden" name="action" value="...">
 * ===================================================================
 *
 * 2026-09-26 实测的线上故障：**个人资料页改昵称、上传头像全部 404**。
 *
 * 访问日志里的证据长这样：
 *
 *     POST /profile/[object%20HTMLInputElement]   404
 *
 * 也就是说表单提交到了 `/profile/[object HTMLInputElement]`。
 *
 * ## 真凶：HTMLFormElement 的 [LegacyOverrideBuiltIns]
 *
 * HTML 规范里 `HTMLFormElement` 被标了 `[LegacyOverrideBuiltIns]`，
 * 意思是：**表单内任何 name 与 form 自身属性同名的控件，会遮蔽那个属性**。
 * 而 `form.action` 正是这样一个属性。
 *
 * 于是 `<input type="hidden" name="action" value="x">` 一存在：
 *
 *     form.getAttribute('action')   → "https://…/wp-admin/admin-post.php"   ✅ 仍是原值
 *     form.action                   → <input> 元素本身                        ❌ 被遮蔽
 *     form['action']                → <input> 元素本身                        ❌ 同上
 *
 * （DOM 的 `getAttribute()` 走的是属性表，所以看不出问题；
 *   属性访问走的是「命名属性」查找，才会撞上遮蔽。**这正是这个坑难查的原因**。）
 *
 * ## 为什么偏偏在本站会炸
 *
 * 主题开启了 pjax（`iro_opt('pojax')` / `_iro.pjax=true`）。pjax 库
 * （`js/9308.js`）的表单拦截里有一个小工具类：
 *
 *     class g {
 *       getAttribute(e){ ...; return r[e] }        // ← 注意是 r[e]，属性访问！
 *       getRequestInfo(){ let e=this.getAttribute("action"); ... }
 *     }
 *
 * 它**没有**调用 DOM 的 `form.getAttribute('action')`，而是用方括号取值，
 * 于是拿到那个 input 元素；再 `new URL(元素, baseURI)` 时元素被转成字符串
 * `"[object HTMLInputElement]"`，拼出 `/profile/[object HTMLInputElement]`
 * → 服务器 404 → pjax 走 `pjax:error`，**用户看到的就是"保存没反应/报错"**。
 *
 * 为什么以前没暴露：
 *   · 只测 `curl` 或关掉 pjax 时**完全不触发**（curl 不执行 JS）；
 *   · 一旦开启 pjax 且真实点击提交，必现。
 *
 * ## 修法
 *
 * 把 action 从表单字段挪到 **URL 查询串**：
 *
 *     action="…/admin-post.php?action=ybh_profile_basic"
 *
 * 这样表单里再没有叫 `action` 的控件，`form.action` 恢复正常字符串，
 * pjax 拿到的就是正确的完整 URL。而 `wp-admin/admin-post.php` 第 29 行读的是
 *
 *     $action = ! empty( $_REQUEST['action'] ) ? … : '';
 *
 * `$_REQUEST` 同时包含 GET 与 POST，所以放 URL 上服务端照样认。
 *
 * 顺带一提：`<input name="submit">`（评论表单里有）会同样遮蔽 `form.submit()`
 * 这个方法 —— 不过那个位置不调用 `form.submit()`，暂时无碍，此处一并记下。
 *
 * @param string $action admin-post 的 action 名
 * @return string
 */
function ybh_profile_form_action(string $action): string
{
    return add_query_arg('action', $action, admin_url('admin-post.php'));
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
        'basic-err-url'     => array('个人网站要是一个网址（http:// 或 https:// 开头）；社交栏目的 UID / 用户名也要按提示格式填。', false),
        'basic-err-fail'    => array('保存失败，请稍后再试。', false),
        'pwd-ok'            => array('密码已修改，当前登录状态已保留。', true),
        'pwd-err-current'   => array('当前密码不正确。', false),
        'pwd-err-short'     => array('新密码至少 8 位。', false),
        'pwd-err-same'      => array('新密码不能和当前密码相同。', false),
        'pwd-err-match'     => array('两次输入的新密码不一致。', false),
        'pwd-err-fail'      => array('密码修改失败，请稍后再试。', false),
        /* C6：偏好 */
        'prefs-ok'          => array('偏好已保存（下次打开后台时生效）。', true),
        /* C6：邮箱修改 */
        'email-sent'        => array('确认邮件已发到新邮箱。请点开邮件里的链接完成更换（24 小时内有效）。', true),
        'email-err-invalid' => array('这不是一个有效的邮箱地址。', false),
        'email-err-same'    => array('新邮箱和当前邮箱一样，不用改。', false),
        'email-err-used'    => array('这个邮箱已经被本站其它账号使用了。', false),
        'email-err-pass'    => array('当前密码不正确，为安全起见没有发出确认邮件。', false),
        'email-err-send'    => array('确认邮件发送失败，请稍后再试或联系管理员。', false),
        'email-err-fail'    => array('邮箱更换失败，请稍后再试。', false),
        'email-ok'          => array('邮箱已更换。以后就用新邮箱登录与收信了。', true),
        'email-err-bad'     => array('确认链接无效或已过期（确认链接 24 小时内有效）。', false),
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

    /*
     * T61：社交账号（与「基本资料」同一个表单、同一个 nonce）。
     * 先全部校验再落库：任一项填了不合法的链接就整体退回并提示，
     * 与既有的 `user_url` 处理方式保持一致（不要"静默丢掉一半"）。
     */
    $social_clean = array();
    if (isset($_POST['ybh_social']) && is_array($_POST['ybh_social'])) {
        foreach (ybh_profile_social_fields() as $skey => $scfg) {
            if (!array_key_exists($skey, $_POST['ybh_social'])) {
                continue;
            }
            $raw_in = (string) $_POST['ybh_social'][$skey];   // 逐项在下面的函数里清洗
            $val    = ybh_profile_social_sanitize($skey, $raw_in);
            if (trim($raw_in) !== '' && $val === '' && !empty($scfg['url'])) {
                wp_safe_redirect(add_query_arg('ybh_p', 'basic-err-url', $back));
                exit;
            }
            $social_clean[$skey] = $val;
        }
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

    // T61：社交账号落库（user_meta `ybh_social_{平台}`；空值就删掉这条 meta）
    if (!is_wp_error($res) && $social_clean) {
        foreach ($social_clean as $skey => $sval) {
            $meta_key = ybh_profile_social_meta_key($skey);
            if ($sval === '') {
                delete_user_meta($uid, $meta_key);
            } else {
                update_user_meta($uid, $meta_key, $sval);
            }
        }
    }

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
 * =====================================================================
 * T61：资料页分区（Tab）与社交字段
 * =====================================================================
 * 分区走 **URL 参数**（`?ybh_tab=`）而不是 JS：
 *   · 纯 `<a>` 链接 ⇒ 没有 JS 也能用、pjax 换页天然正常、还能直接分享/收藏某一分区；
 *   · 每个分区仍是**独立表单 + 独立 nonce**（不合并：一个 nonce 覆盖三种权限语义，
 *     而且密码填错会把昵称一起丢掉）。
 * 保存后落在哪个分区由 referer 决定，所以表单 action 里要把分区参数带上。
 */
function ybh_profile_tabs()
{
    /**
     * 过滤：资料页分区。加分区只需加一项（前台导航与分支渲染都读它）。
     *
     * @param array $tabs 键 => array( 'label', 'icon' )
     */
    return apply_filters('ybh_profile_tabs', array(
        'profile'  => array('label' => ybh_t('资料'),     'icon' => 'fa-solid fa-id-card'),
        'avatar'   => array('label' => ybh_t('头像'),     'icon' => 'fa-solid fa-image-portrait'),
        /* C6：WordPress 自带的个人偏好（后台配色 / 键盘快捷键 / 可视化编辑器 / 语言…） */
        'prefs'    => array('label' => ybh_t('偏好'),     'icon' => 'fa-solid fa-sliders'),
        'security' => array('label' => ybh_t('账号安全'), 'icon' => 'fa-solid fa-key'),
        'posts'    => array('label' => ybh_t('我的文章'), 'icon' => 'fa-solid fa-pen-nib'),
    ));
}

/** 当前分区（白名单校验，非法值回落到「资料」） */
function ybh_profile_current_tab()
{
    $tabs = ybh_profile_tabs();
    $raw  = isset($_GET['ybh_tab']) ? sanitize_key(wp_unslash((string) $_GET['ybh_tab'])) : '';
    return isset($tabs[$raw]) ? $raw : 'profile';
}

/** 某个分区的链接（保留其它查询参数，别把 `ybh_p` 之类的提示弄丢） */
function ybh_profile_tab_url($tab)
{
    $tab  = sanitize_key((string) $tab);
    $base = ybh_profile_url();
    return ($tab === '' || $tab === 'profile') ? $base : add_query_arg('ybh_tab', $tab, $base);
}

/* =====================================================================
 * C6：资料页「偏好」分区（WordPress 自带的个人选项）
 * ===================================================================== */

/**
 * 后台配色方案（键 => 名称 + 一个代表色点）。
 *
 * 为什么写死而不用 `$GLOBALS['_wp_admin_css_colors']`：那些配色是在**后台**页面里
 * 由 `register_admin_color_schemes()` 注册的，前台取不到。这里列出核心的九套，
 * 键名与 WordPress 的 `admin_color` 一致（改错键名只会导致回落到默认，不会报错）。
 */
function ybh_profile_color_schemes()
{
    return apply_filters('ybh_profile_color_schemes', array(
        'fresh'     => array('label' => '默认（Fresh）', 'color' => '#1d2327'),
        'light'     => array('label' => '浅色（Light）', 'color' => '#e5e5e5'),
        'modern'    => array('label' => '现代（Modern）', 'color' => '#1e1e1e'),
        'blue'      => array('label' => '蓝色（Blue）',  'color' => '#096484'),
        'coffee'    => array('label' => '咖啡（Coffee）', 'color' => '#46403c'),
        'ectoplasm' => array('label' => '灵质（Ectoplasm）', 'color' => '#413256'),
        'midnight'  => array('label' => '午夜（Midnight）', 'color' => '#363b3f'),
        'ocean'     => array('label' => '海洋（Ocean）',  'color' => '#627c83'),
        'sunrise'   => array('label' => '日出（Sunrise）', 'color' => '#b43c38'),
    ));
}

/**
 * 开关型偏好：meta 键 => [显示名, 说明, 默认值, 可见性]。
 *
 * 键名与 WordPress 的 `user_edit.php` / `personal_options` 完全一致 ——
 * 所以在资料页改完，进后台看到的就是同一个开关（不是本站另造的一套）。
 *
 * ⚠️ T72：不是所有项都该给普通用户。逐项核实过「写入后谁能看到效果」：
 *
 * | 键 | 写入 | 效果范围 | 普通用户可用？ |
 * |---|---|---|---|
 * | `rich_editing` | user_meta | 仅 wp-admin 区块编辑器 | ❌ 本站前台写作页用 WangEditor，此开关对普通用户**无任何效果**；且 classic-editor 插件已启用，进后台也看不到区块编辑器 |
 * | `syntax_highlighting` | user_meta | 仅 wp-admin 代码编辑器 | ❌ 普通用户不进后台 ⇒ 无效果 |
 * | `comment_shortcuts` | user_meta | wp-admin 评论列表（需先开 admin_bar） | ❌ 同上 |
 * | `admin_bar_front` | user_meta | **前台**顶部管理工具栏 | ✅ 唯一真正作用于前台的开关，保留 |
 *
 * 处理：前三项对普通用户「点了没反应」→ **对无 `manage_options` 的人隐藏**；
 * 管理员在同一页里仍能看到并开关它们（不是删掉定义）。
 * 想恢复给所有人：把对应项的 'visible' 改成 'all'。
 *
 * @param array|null $flags 传入数组时直接作为清单（便于测试/扩展）
 * @param int        $uid   >0 时按可见性过滤
 * @return array
 */
function ybh_profile_pref_flags($flags = null, $uid = 0)
{
    // ⚠️ 缓存必须**按 $uid 分桶**：清单要按能力过滤，普通用户与管理员看到的不同。
    //    早先用一个静态变量缓存，导致先渲染的资料页把结果固定下来（管理员也会少看到项）。
    static $cache = array();
    if (is_array($flags)) {
        $all_defs = $flags;
        $cache    = array();                 // 外部注入时清空缓存
    } else {
        $all_defs = null;
    }
    if ($all_defs === null) {
        if (isset($cache['__defs'])) {
            $all_defs = $cache['__defs'];
        } else {
            $all_defs = apply_filters('ybh_profile_pref_flags', array(
                'admin_bar_front' => array(
                    'label'   => '前台显示管理工具栏',
                    'hint'    => '登录后访问前台时，顶部显示一条快捷工具栏（账号需能进后台）。',
                    'default' => 'true',
                    'visible' => 'all',
                ),
                'rich_editing' => array(
                    'label'   => '可视化编辑器',
                    'hint'    => '仅影响 wp-admin 的区块编辑器；本站前台写作页不受它影响。',
                    'default' => 'true',
                    'visible' => 'caps',
                    'caps'    => array('manage_options'),
                ),
                'syntax_highlighting' => array(
                    'label'   => '代码语法高亮',
                    'hint'    => '仅影响 wp-admin 的代码编辑器。',
                    'default' => 'true',
                    'visible' => 'caps',
                    'caps'    => array('manage_options'),
                ),
                'comment_shortcuts' => array(
                    'label'   => '评论键盘快捷键',
                    'hint'    => '仅影响 wp-admin 的评论列表。',
                    'default' => 'false',
                    'visible' => 'caps',
                    'caps'    => array('manage_options'),
                ),
            ));
            $cache['__defs'] = $all_defs;
        }
    }
    if ($uid <= 0) {
        return $all_defs;
    }
    if (isset($cache[$uid])) {
        return $cache[$uid];
    }
    $out = array_filter($all_defs, function ($def) use ($uid) {
        if (($def['visible'] ?? 'all') !== 'caps') {
            return true;
        }
        foreach ((array) ($def['caps'] ?? array()) as $cap) {
            if (user_can($uid, $cap)) {
                return true;
            }
        }
        return false;
    });
    $cache[$uid] = $out;
    return $out;
}

/**
 * 时区：普通用户该有的个人设置（T72 新增）。
 *
 * ⚠️ 先查了本站实际情况再动手，**没有自造字段**：
 *   · 全站渲染时间用的是 `date_i18n()`（见本文件加入时间、`quick-save.php` 的
 *     `human`、`user/page-archive.php` 的归档日期），它本身就**读当前用户的时区**：
 *     WP 核心 `date_i18n()` → `wp_timezone()` → 当前用户 usermeta `timezone_string`，
 *     为空才回落到站点 `gmt_offset`；
 *   · 所以**只要把值存进 WP 标准的 `timezone_string`，前台时间显示就自动跟着走**，
 *     不需要额外接线（下面 `ybh_profile_render_timezone()` 的注释里也写了这一点）。
 *
 * 字段选择：**usermeta `timezone_string`**（与 wp-admin/user-edit.php 完全一致）。
 * 不用 `gmt_offset`：那个字段在核心里表示"手动设定的 UTC 偏移"，与时区是两套并存机制，
 * 混用会和 `timezone_string` 互相覆盖。
 */
function ybh_profile_timezones()
{
    return array(
        ''                => '跟随站点',
        'Asia/Shanghai'   => '中国标准时间（UTC+8）',
        'Asia/Hong_Kong'  => '香港时间（UTC+8）',
        'Asia/Taipei'     => '台北时间（UTC+8）',
        'Asia/Tokyo'      => '日本标准时间（UTC+9）',
        'Asia/Singapore'  => '新加坡时间（UTC+8）',
        'Asia/Seoul'      => '韩国标准时间（UTC+9）',
        'Australia/Sydney'=> '悉尼时间',
        'Europe/London'   => '伦敦时间',
        'Europe/Paris'    => '巴黎时间（ CET / CEST ）',
        'America/New_York'=> '纽约时间',
        'America/Los_Angeles' => '洛杉矶时间',
        'UTC'             => '协调世界时（UTC）',
    );
}

/** 读当前用户的时区字符串（'' = 跟随站点） */
function ybh_profile_timezone_get(int $uid)
{
    $v = (string) get_user_meta($uid, 'timezone_string', true);
    return in_array($v, array_keys(ybh_profile_timezones()), true) ? $v : '';
}

/**
 * 读一个偏好项的当前值。
 *
 * ⚠️ 缺省值不能一律用 `''`：WordPress 对这几个开关的判断是**字符串** 'true'/'false'，
 * 而"从未设置过"的账号在核心里的默认分别是 true / true / false / true
 * （见 wp-admin/user-edit.php）。所以这里按 $default 兜底，读出来也是一致的。
 */
function ybh_profile_pref_get(int $uid, string $key, string $default = '')
{
    $val = get_user_meta($uid, $key, true);
    if ($val === '' || $val === null) {
        return $default;
    }
    return (string) $val;
}

/* =====================================================================
 * C6：邮箱更换（确认链接走前台）
 * ===================================================================== */

/** 待确认的新邮箱 meta 键 */
function ybh_profile_new_email_key()
{
    return '_new_email';
}

/** 确认链接（前台，直接指回资料页的账号安全分区） */
function ybh_profile_email_confirm_url(int $uid, string $hash)
{
    return add_query_arg(
        array('ybh_email_confirm' => $hash, 'u' => $uid),
        add_query_arg('ybh_tab', 'security', ybh_profile_url())
    );
}

/**
 * 处理确认链接（在 `init` 上做，早于渲染，便于之后重定向掉参数）。
 * 只处理**当前登录用户自己**的确认链接：链接里的 u 必须等于当前用户。
 */
add_action('init', 'ybh_profile_maybe_confirm_email', 20);
function ybh_profile_maybe_confirm_email()
{
    if (is_admin() || empty($_GET['ybh_email_confirm'])) {
        return;
    }
    $uid = get_current_user_id();
    if ($uid <= 0) {
        return;   // 未登录：让页面自己提示去登录
    }
    /*
     * ⚠️ 这里只能过滤成**字母数字**，不能按十六进制过滤：
     *    哈希是 `wp_generate_password(20, false, false)` 生成的，字符集是
     *    `a-zA-Z0-9` —— 里面会出现 a–f 之外的字母（L、R、Y、q…）。
     *    早先版本按 `[^a-f0-9]` 过滤，等于把这些字母**删掉**，
     *    hash_equals 必然不相等 ⇒ 正确的确认链接也会被判成"无效"（实测踩到）。
     */
    $hash = preg_replace('~[^A-Za-z0-9]~', '', (string) wp_unslash($_GET['ybh_email_confirm']));
    $link_uid = isset($_GET['u']) ? (int) $_GET['u'] : 0;
    $pending = get_user_meta($uid, ybh_profile_new_email_key(), true);
    $target = add_query_arg('ybh_tab', 'security', ybh_profile_url());

    if ($link_uid !== $uid || !is_array($pending) || empty($pending['hash']) || empty($pending['email'])) {
        wp_safe_redirect(add_query_arg('ybh_p', 'email-err-bad', $target));
        exit;
    }
    if (!hash_equals((string) $pending['hash'], (string) $hash)) {
        wp_safe_redirect(add_query_arg('ybh_p', 'email-err-bad', $target));
        exit;
    }
    // 24 小时有效期
    if (!empty($pending['time']) && (time() - (int) $pending['time']) > DAY_IN_SECONDS) {
        delete_user_meta($uid, ybh_profile_new_email_key());
        wp_safe_redirect(add_query_arg('ybh_p', 'email-err-bad', $target));
        exit;
    }
    // 这期间可能被别人注册走了
    $exists = email_exists((string) $pending['email']);
    if ($exists && (int) $exists !== $uid) {
        delete_user_meta($uid, ybh_profile_new_email_key());
        wp_safe_redirect(add_query_arg('ybh_p', 'email-err-used', $target));
        exit;
    }

    $res = wp_update_user(array('ID' => $uid, 'user_email' => (string) $pending['email']));
    delete_user_meta($uid, ybh_profile_new_email_key());
    wp_safe_redirect(add_query_arg('ybh_p', is_wp_error($res) ? 'email-err-fail' : 'email-ok', $target));
    exit;
}

/**
 * 提交邮箱更换申请：验证 → 记下待确认信息 → 给**新邮箱**发确认信。
 */
add_action('admin_post_ybh_profile_email', 'ybh_profile_save_email');
function ybh_profile_save_email()
{
    $uid = get_current_user_id();
    if ($uid <= 0) {
        wp_safe_redirect(home_url('/'));
        exit;
    }
    $target = add_query_arg('ybh_tab', 'security', wp_get_referer() ?: ybh_profile_url());

    $nonce = isset($_POST['ybh_email_nonce']) ? (string) $_POST['ybh_email_nonce'] : '';
    if (!wp_verify_nonce($nonce, 'ybh_profile_email_' . $uid)) {
        wp_safe_redirect(add_query_arg('ybh_p', 'email-err-fail', $target));
        exit;
    }

    $new  = isset($_POST['new_email']) ? sanitize_email(wp_unslash((string) $_POST['new_email'])) : '';
    $pass = isset($_POST['email_pass']) ? (string) wp_unslash($_POST['email_pass']) : '';
    $user = wp_get_current_user();

    // 安全：换邮箱必须验证当前密码（核心后台也要求一次身份确认，这里更严一点）
    if ($pass === '' || !wp_check_password($pass, $user->user_pass, $uid)) {
        wp_safe_redirect(add_query_arg('ybh_p', 'email-err-pass', $target));
        exit;
    }
    if ($new === '' || !is_email($new)) {
        wp_safe_redirect(add_query_arg('ybh_p', 'email-err-invalid', $target));
        exit;
    }
    if (strtolower($new) === strtolower((string) $user->user_email)) {
        wp_safe_redirect(add_query_arg('ybh_p', 'email-err-same', $target));
        exit;
    }
    $exists = email_exists($new);
    if ($exists && (int) $exists !== $uid) {
        wp_safe_redirect(add_query_arg('ybh_p', 'email-err-used', $target));
        exit;
    }

    $hash = wp_generate_password(20, false, false);
    update_user_meta($uid, ybh_profile_new_email_key(), array(
        'email' => $new,
        'hash'  => $hash,
        'time'  => time(),
    ));

    $link = ybh_profile_email_confirm_url($uid, $hash);
    $subject = '【' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '】确认更换邮箱';
    $body = "你好，{$user->display_name}：\n\n"
        . "我们收到了把这个邮箱设为「" . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . "」账号邮箱的请求。\n"
        . "如果这是你本人操作，请点开下面的链接完成确认（24 小时内有效）：\n\n"
        . $link . "\n\n"
        . "确认后，登录与通知邮件都会使用新邮箱。\n"
        . "如果这不是你发起的，可以忽略这封邮件 —— 邮箱不会改变，建议顺手改一下密码。\n\n"
        . home_url('/');
    $sent = wp_mail($new, $subject, $body);

    wp_safe_redirect(add_query_arg('ybh_p', $sent ? 'email-sent' : 'email-err-send', $target));
    exit;
}

/**
 * 保存「偏好」（WordPress 自带的个人选项）。
 */
add_action('admin_post_ybh_profile_prefs', 'ybh_profile_save_prefs');
function ybh_profile_save_prefs()
{
    $uid = get_current_user_id();
    if ($uid <= 0) {
        wp_safe_redirect(home_url('/'));
        exit;
    }
    $back = add_query_arg('ybh_tab', 'prefs', wp_get_referer() ?: ybh_profile_url());

    $nonce = isset($_POST['ybh_prefs_nonce']) ? (string) $_POST['ybh_prefs_nonce'] : '';
    if (!wp_verify_nonce($nonce, 'ybh_profile_prefs_' . $uid)) {
        wp_safe_redirect(add_query_arg('ybh_p', 'basic-err-fail', $back));
        exit;
    }

    // ① 后台配色：白名单
    $scheme = isset($_POST['admin_color']) ? sanitize_key(wp_unslash((string) $_POST['admin_color'])) : '';
    if (isset(ybh_profile_color_schemes()[$scheme])) {
        update_user_meta($uid, 'admin_color', $scheme);
    }

    // ② 语言：必须是站点可用的语言（或 en_US），空字符串 = 跟随站点
    $locale = isset($_POST['locale']) ? preg_replace('~[^A-Za-z_]~', '', (string) wp_unslash($_POST['locale'])) : '';
    if ($locale === '') {
        delete_user_meta($uid, 'locale');
    } else {
        $valid = array_merge(array('en_US'), function_exists('get_available_languages') ? get_available_languages() : array());
        if (in_array($locale, array_unique($valid), true)) {
            update_user_meta($uid, 'locale', $locale);
        }
    }

    // ②-2 时区（T72）：WP 标准 usermeta `timezone_string`，空 = 跟随站点
    $tz = isset($_POST['timezone_string']) ? (string) wp_unslash($_POST['timezone_string']) : '';
    if ($tz !== '' && !array_key_exists($tz, ybh_profile_timezones())) {
        $tz = '';                 // 白名单外一律当"跟随站点"，不让任意字符串进 meta
    }
    if ($tz === '') {
        delete_user_meta($uid, 'timezone_string');
    } else {
        update_user_meta($uid, 'timezone_string', $tz);
    }

    // ③ 开关：核心用的是字符串 'true'/'false'，这里照抄（只写**该用户可见**的那些，
    //    否则普通用户提交时会把管理员专属项一并写成 false —— T72 修）
    foreach (array_keys(ybh_profile_pref_flags(null, $uid)) as $key) {
        $on = (isset($_POST[$key]) && (string) $_POST[$key] === '1');
        update_user_meta($uid, $key, $on ? 'true' : 'false');
    }

    wp_safe_redirect(add_query_arg('ybh_p', 'prefs-ok', $back));
    exit;
}

/**
 * 短代码 `[ybh_profile]` —— 渲染整个资料页。
 */
add_shortcode('ybh_profile', 'ybh_profile_shortcode');function ybh_profile_shortcode($atts = array())
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

    /*
     * C6：顶部身份区。数据来自 T61 写的 `ybh_author_profile()`（读取器，不写库），
     * 所以这里不会再算一遍头像/作品数，展示口径与作者页完全一致。
     */
    $ybh_me = function_exists('ybh_author_profile') ? ybh_author_profile($uid) : array();

    ob_start();
    ?>
    <div class="ybh-pf">

      <header class="ybh-pf-head">
        <span class="ybh-pf-head__avatar"><?php
            echo !empty($ybh_me['avatar_html'])
                ? $ybh_me['avatar_html']                                  // 已由 inc/ybh/avatar.php 接管，直接输出
                : get_avatar($uid, 96);
        ?></span>
        <div class="ybh-pf-head__main">
          <h1 class="ybh-pf-head__name"><?php echo esc_html($user->display_name); ?></h1>
          <p class="ybh-pf-head__meta">
            <?php if (!empty($ybh_me['nickname']) && $ybh_me['nickname'] !== $user->display_name) : ?>
              <span><i class="fa-solid fa-at" aria-hidden="true"></i><?php echo esc_html($ybh_me['nickname']); ?></span>
            <?php endif; ?>
            <span><i class="fa-solid fa-user-tag" aria-hidden="true"></i><?php
                echo esc_html(user_can($uid, 'edit_others_posts') ? '编辑' : (user_can($uid, 'publish_posts') ? '作者' : '投稿者'));
            ?></span>
            <span><i class="fa-solid fa-pen-nib" aria-hidden="true"></i><?php echo (int) ($ybh_me['post_count'] ?? 0); ?> 篇作品</span>
            <span><i class="fa-solid fa-clock" aria-hidden="true"></i><?php
                $reg = $user->user_registered ? strtotime((string) $user->user_registered) : 0;
                echo esc_html($reg ? date_i18n('Y-m-d', $reg) . ' 加入' : '');
            ?></span>
          </p>
          <?php if (!empty($ybh_me['bio'])) : ?>
            <p class="ybh-pf-head__bio"><?php echo esc_html(wp_trim_words(wp_strip_all_tags((string) $ybh_me['bio']), 40, '…')); ?></p>
          <?php endif; ?>
          <p class="ybh-pf-head__acts">
            <a class="ybh-pf-btn" href="<?php echo esc_url(get_author_posts_url($uid)); ?>"><?php ybh_e('看我的作者页'); ?></a>
            <a class="ybh-pf-btn" href="<?php echo esc_url(ybh_profile_tab_url('posts')); ?>"><?php ybh_e('我的文章'); ?></a>
          </p>
        </div>
      </header>

      <?php if ($msg !== '') : ?>
        <div class="ybh-pf-msg <?php echo $msgOk ? 'ok' : 'err'; ?>"><?php echo esc_html($msg); ?></div>
      <?php endif; ?>

      <?php
      /*
       * T61：资料页改为**分区**（纯 URL 参数，不用 JS）——
       * 见本文件上方 ybh_profile_tabs() 的说明。
       */
      $ybh_tab = ybh_profile_current_tab();
      ?>
      <nav class="ybh-pf-tabs" aria-label="<?php esc_attr_e('资料页分区', 'sakurairo'); ?>">
        <?php foreach (ybh_profile_tabs() as $ybh_tkey => $ybh_t) : ?>
          <a class="ybh-pf-tab<?php echo $ybh_tab === $ybh_tkey ? ' is-active' : ''; ?>"
             href="<?php echo esc_url(ybh_profile_tab_url($ybh_tkey)); ?>"
             <?php echo $ybh_tab === $ybh_tkey ? 'aria-current="page"' : ''; ?>>
            <i class="<?php echo esc_attr($ybh_t['icon']); ?>" aria-hidden="true"></i>
            <span><?php echo esc_html($ybh_t['label']); ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <?php if ($ybh_tab === 'avatar') : ?>

        <!-- 头像 -->
        <section class="ybh-pf-card" id="ybh-pf-avatar">
          <h2 class="ybh-pf-title"><i class="fa-solid fa-image-portrait"></i> <?php ybh_e('头像'); ?></h2>
          <?php echo ybh_avatar_upload_shortcode(); // 复用 T24 的上传管线，见文件头说明 ?>
        </section>

      <?php elseif ($ybh_tab === 'prefs') : ?>

        <!-- 偏好：WordPress 自带的个人选项，站内直改，不必进后台 -->
        <section class="ybh-pf-card">
          <h2 class="ybh-pf-title"><i class="fa-solid fa-sliders"></i> <?php ybh_e('偏好'); ?></h2>
          <p class="ybh-pf-hint">这些都是 WordPress 自带的个人选项；改完保存即可，不必进后台。</p>
          <form method="post" action="<?php echo esc_url(ybh_profile_form_action('ybh_profile_prefs')); ?>">
            <?php wp_nonce_field('ybh_profile_prefs_' . $uid, 'ybh_prefs_nonce'); ?>

            <div class="ybh-pf-row">
              <label><?php ybh_e('后台配色方案'); ?></label>
              <div class="ybh-pf-schemes">
                <?php foreach (ybh_profile_color_schemes() as $ybh_skey => $ybh_s) : ?>
                  <label class="ybh-pf-scheme">
                    <input type="radio" name="admin_color" value="<?php echo esc_attr($ybh_skey); ?>"
                      <?php checked(ybh_profile_pref_get($uid, 'admin_color', 'modern'), $ybh_skey); ?> />
                    <span class="ybh-pf-scheme__dot" style="background:<?php echo esc_attr($ybh_s['color']); ?>" aria-hidden="true"></span>
                    <span><?php echo esc_html($ybh_s['label']); ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <p class="ybh-pf-hint">只影响你登录后台时看到的配色。</p>
            </div>

            <div class="ybh-pf-row">
              <label for="ybh-pf-locale"><?php ybh_e('界面语言'); ?></label>
              <select id="ybh-pf-locale" name="locale" class="ybh-pf-select">
                <option value="">跟随站点</option>
                <?php
                $ybh_locales = array_merge(array('en_US'), function_exists('get_available_languages') ? get_available_languages() : array());
                $ybh_locale_now = (string) ybh_profile_pref_get($uid, 'locale', '');
                foreach (array_unique($ybh_locales) as $ybh_loc) :
                    $ybh_name = $ybh_loc;
                    if (function_exists('wp_get_available_translations')) {
                        $ybh_tr = wp_get_available_translations();
                        if (isset($ybh_tr[$ybh_loc]['native_name'])) { $ybh_name = $ybh_tr[$ybh_loc]['native_name']; }
                    }
                ?>
                  <option value="<?php echo esc_attr($ybh_loc); ?>" <?php selected($ybh_locale_now, $ybh_loc); ?>>
                    <?php echo esc_html($ybh_name . '（' . $ybh_loc . '）'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="ybh-pf-hint">只影响后台与系统提示的语言，文章内容不受影响。</p>
            </div>

            <div class="ybh-pf-row">
              <label for="ybh-pf-timezone"><?php ybh_e('时区'); ?></label>
              <select id="ybh-pf-timezone" name="timezone_string" class="ybh-pf-select">
                <?php $ybh_tz_now = ybh_profile_timezone_get($uid); ?>
                <?php foreach (ybh_profile_timezones() as $ybh_tz_key => $ybh_tz_label) : ?>
                  <option value="<?php echo esc_attr($ybh_tz_key); ?>" <?php selected($ybh_tz_now, $ybh_tz_key); ?>>
                    <?php echo esc_html($ybh_tz_label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="ybh-pf-hint"><?php ybh_e('影响你看到的时间显示（文章发布时间、保存时间等）。默认跟随站点设置。'); ?></p>
            </div>

            <?php foreach (ybh_profile_pref_flags(null, $uid) as $ybh_fkey => $ybh_flag) : ?>
              <div class="ybh-pf-row ybh-pf-row--check">
                <label class="ybh-pf-check">
                  <input type="checkbox" name="<?php echo esc_attr($ybh_fkey); ?>" value="1"
                    <?php checked(ybh_profile_pref_get($uid, $ybh_fkey, $ybh_flag['default']), 'true'); ?> />
                  <span><b><?php echo esc_html($ybh_flag['label']); ?></b><em><?php echo esc_html($ybh_flag['hint']); ?></em></span>
                </label>
              </div>
            <?php endforeach; ?>

            <p class="ybh-pf-actions">
              <button type="submit" class="ybh-pf-btn primary"><?php ybh_e('保存偏好'); ?></button>
            </p>
          </form>
        </section>

      <?php elseif ($ybh_tab === 'security') : ?>

        <!-- 账号安全 -->
        <section class="ybh-pf-card">
          <h2 class="ybh-pf-title"><i class="fa-solid fa-key"></i> <?php ybh_e('修改密码'); ?></h2>
          <form method="post" action="<?php echo esc_url(ybh_profile_form_action('ybh_profile_pwd')); ?>" autocomplete="off">
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
              <button type="submit" class="ybh-pf-btn primary"><?php ybh_e('修改密码'); ?></button>
            </p>
          </form>
        </section>

        <!-- C6：更换邮箱（两步：提交后给新邮箱发确认信，点链接才生效） -->
        <section class="ybh-pf-card">
          <h2 class="ybh-pf-title"><i class="fa-solid fa-envelope"></i> <?php ybh_e('更换邮箱'); ?></h2>
          <p class="ybh-pf-hint">
            当前邮箱：<b><?php echo esc_html(antispambot((string) $user->user_email)); ?></b><br />
            换邮箱要<b>两步</b>：提交后我们会给新邮箱发一封确认信，点开里面的链接才会真正生效（24 小时内有效）。
            这样做是为了防止别人拿到你的登录状态就把邮箱改走。
          </p>
          <?php $ybh_pending_email = get_user_meta($uid, ybh_profile_new_email_key(), true); ?>
          <?php if (is_array($ybh_pending_email) && !empty($ybh_pending_email['email'])) : ?>
            <p class="ybh-pf-hint">
              <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
              有一封确认信已发往 <b><?php echo esc_html(antispambot((string) $ybh_pending_email['email'])); ?></b>，
              请点开邮件里的链接完成更换。
            </p>
          <?php endif; ?>
          <form method="post" action="<?php echo esc_url(ybh_profile_form_action('ybh_profile_email')); ?>" autocomplete="off">
            <?php wp_nonce_field('ybh_profile_email_' . $uid, 'ybh_email_nonce'); ?>
            <div class="ybh-pf-row">
              <label for="ybh-pf-newmail">新邮箱 <span class="ybh-pf-req">*</span></label>
              <input type="email" id="ybh-pf-newmail" name="new_email" required maxlength="100"
                     placeholder="name@example.com" />
            </div>
            <div class="ybh-pf-row">
              <label for="ybh-pf-emailpass">当前密码 <span class="ybh-pf-req">*</span></label>
              <input type="password" id="ybh-pf-emailpass" name="email_pass" required autocomplete="current-password" />
              <p class="ybh-pf-hint">为确认是你本人操作。</p>
            </div>
            <p class="ybh-pf-actions">
              <button type="submit" class="ybh-pf-btn primary">发确认邮件到新邮箱</button>
            </p>
          </form>
        </section>

      <?php elseif ($ybh_tab === 'posts') : ?>

        <!-- 我的文章 -->
        <section class="ybh-pf-card">
          <h2 class="ybh-pf-title"><i class="fa-solid fa-pen-nib"></i> <?php ybh_e('我的文章'); ?></h2>
          <?php echo ybh_profile_submissions_html($uid); ?>
        </section>

      <?php else : ?>

        <!-- 资料（含社交账号） -->
        <section class="ybh-pf-card">
          <h2 class="ybh-pf-title"><i class="fa-solid fa-id-card"></i> <?php ybh_e('基本资料'); ?></h2>
          <?php /* action 放在 URL 上，不要用 <input name="action">：见 ybh_profile_form_action() 的说明 */ ?>
          <form method="post" action="<?php echo esc_url(ybh_profile_form_action('ybh_profile_basic')); ?>">
            <?php wp_nonce_field('ybh_profile_basic_' . $uid, 'ybh_profile_nonce'); ?>

            <div class="ybh-pf-row">
              <label for="ybh-pf-display"><?php ybh_e('显示名'); ?> <span class="ybh-pf-req">*</span></label>
              <input type="text" id="ybh-pf-display" name="display_name" required maxlength="60"
                     value="<?php echo esc_attr($user->display_name); ?>" />
              <p class="ybh-pf-hint">评论区、文章署名显示的就是这个名字。</p>
            </div>

            <div class="ybh-pf-row">
              <label for="ybh-pf-nick"><?php ybh_e('昵称'); ?></label>
              <input type="text" id="ybh-pf-nick" name="nickname" maxlength="60"
                     value="<?php echo esc_attr($user->nickname); ?>" />
              <p class="ybh-pf-hint">留空就跟显示名一致。</p>
            </div>

            <div class="ybh-pf-row">
              <label for="ybh-pf-site"><?php ybh_e('个人网站'); ?></label>
              <input type="text" id="ybh-pf-site" name="user_url" maxlength="200"
                     value="<?php echo esc_attr($user->user_url); ?>" placeholder="example.com" />
              <p class="ybh-pf-hint">填了会显示在你评论的昵称旁边，不填也没关系。</p>
            </div>

            <div class="ybh-pf-row">
              <label for="ybh-pf-desc"><?php ybh_e('个人简介'); ?></label>
              <textarea id="ybh-pf-desc" name="description" rows="4" maxlength="500"><?php echo esc_textarea($user->description); ?></textarea>
            </div>

            <?php
            /*
             * T61：社交账号。
             * 清单来自 inc/ybh/profile-fields.php 的注册表 —— 那里加一行，这里就多一个输入框，
             * 作者页/搜人卡片也会跟着展示（通过 ybh_author_social_links 过滤器）。
             * 微信号、QQ 号这类没有主页的存纯文本，展示时不给链接。
             */
            $ybh_social_fields = ybh_profile_social_fields();
            $ybh_social_saved  = ybh_profile_social_values($uid);
            ?>
            <fieldset class="ybh-pf-social">
              <legend class="ybh-pf-legend"><?php ybh_e('社交账号'); ?></legend>
              <p class="ybh-pf-hint">都会显示在你的作者页上，留空就不显示。</p>
              <div class="ybh-pf-social-grid">
                <?php foreach ($ybh_social_fields as $ybh_skey => $ybh_scfg) : ?>
                  <div class="ybh-pf-row">
                    <label for="ybh-pf-soc-<?php echo esc_attr($ybh_skey); ?>">
                      <i class="<?php echo esc_attr($ybh_scfg['icon']); ?>" aria-hidden="true"></i>
                      <?php echo esc_html($ybh_scfg['label']); ?>
                    </label>
                    <input type="text" id="ybh-pf-soc-<?php echo esc_attr($ybh_skey); ?>"
                           name="ybh_social[<?php echo esc_attr($ybh_skey); ?>]"
                           maxlength="200" placeholder="<?php echo esc_attr($ybh_scfg['ph']); ?>"
                           value="<?php echo esc_attr(isset($ybh_social_saved[$ybh_skey]) ? $ybh_social_saved[$ybh_skey]['value'] : ''); ?>" />
                  </div>
                <?php endforeach; ?>
              </div>
            </fieldset>

            <p class="ybh-pf-actions">
              <button type="submit" class="ybh-pf-btn primary"><?php ybh_e('保存资料'); ?></button>
            </p>
          </form>
        </section>

      <?php endif; ?>

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
            // T68：**所有用户统一走前台编辑器** `/write/?post=ID`（管理员在编辑器下方
            // 有"切换到后台编辑器"的链接）；前台编辑器没启用时才退回后台。
            $edit_url = function_exists('ybh_front_editor_url')
                ? ybh_front_editor_url((int) $p->ID)
                : admin_url('post.php?post=' . (int) $p->ID . '&action=edit');
            $link = '<span class="ybh-pf-sub-title">' . esc_html($title) . '</span>';
            $act  = '<a class="ybh-pf-sub-act" href="' . esc_url($edit_url) . '">继续编辑</a>';
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
