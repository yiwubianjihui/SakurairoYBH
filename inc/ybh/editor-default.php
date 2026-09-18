<?php
/**
 * YBH · 「默认编辑器」用户偏好 + 编辑页自动进入 WangEditor
 *
 * ===================================================================
 * 需求
 * ===================================================================
 *   用户要求「将 WangEditor 作为用户的默认编辑器」。
 *
 *   但**不能把原编辑器拆掉**（上一轮明确要求保留入口），
 *   也不能强制所有人只能用 WangEditor（TinyMCE 的短代码按钮、
 *   「文本」标签页在某些场景仍是唯一办法）。
 *
 * ⇒ 做成**用户级偏好**：
 *     · 默认值 = `wangeditor`（新用户/没设过的都走这个，符合"默认编辑器"的诉求）；
 *     · 每个用户可以随时在编辑页切换，选择**存在 user meta 里**（换设备也跟着走）；
 *     · 选 `classic` 的人完全回到原样，WangEditor 只是那个按钮。
 *
 * ===================================================================
 * 为什么存 user meta 而不是 localStorage
 * ===================================================================
 *   localStorage 只跟浏览器走 —— 投稿者换台机器/换个浏览器就得重设一次。
 *   本站有 22 个用户、且投稿者会用不同设备，所以存服务端更合适。
 *
 * ===================================================================
 * 自动进入的实现要点
 * ===================================================================
 *   · 只在 **post.php / post-new.php**（文章与页面的编辑页）触发；
 *   · 等页面 **load** 之后再开，避免和经典编辑器的初始化抢时序；
 *   · **已经点过「用原编辑器」的人不再自动弹**（否则一切回原编辑器就被弹回来，
 *     等于没法退出）；
 *   · 用 `?ybh_editor=classic` 可以临时压过一次（排查用）。
 *
 * ===================================================================
 * 关闭方式：把 YBH_WANG_DEFAULT 定义为 false（回到"只有按钮"的形态）。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('YBH_WANG_DEFAULT')) {
    define('YBH_WANG_DEFAULT', true);
}

/** 用户 meta key */
if (!defined('YBH_EDITOR_META')) {
    define('YBH_EDITOR_META', 'ybh_default_editor');
}

/** 默认值（没设过的用户走这个） */
if (!defined('YBH_EDITOR_FALLBACK')) {
    define('YBH_EDITOR_FALLBACK', 'wangeditor');
}

/**
 * 取某个用户偏好的编辑器。
 * 只接受两个值，其它一律回落到默认 —— 避免 user meta 被写脏后出怪行为。
 */
function ybh_user_editor($user_id = 0)
{
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    if (!$user_id) {
        return YBH_EDITOR_FALLBACK;
    }
    $v = get_user_meta($user_id, YBH_EDITOR_META, true);
    return in_array($v, array('wangeditor', 'classic'), true) ? $v : YBH_EDITOR_FALLBACK;
}

/* ---------------------------------------------------------------------------
 * 编辑页顶部：在切换条上加「默认编辑器」开关
 *
 * 沿用 wangeditor.php 里的 `.ybh-wang-bar`（那边已经挂好了「用 WangEditor 编辑」
 * 按钮），这里只多插一个可持久化的选择。
 * ------------------------------------------------------------------------- */
add_action('edit_form_top', function ($post) {
    if (!YBH_WANG_DEFAULT || !function_exists('ybh_wangeditor_ready') || !ybh_wangeditor_ready()) {
        return;
    }
    $cur = ybh_user_editor();
    ?>
    <label class="ybh-editor-default" title="选择下次打开编辑页时默认用哪个编辑器">
        <input type="checkbox" id="ybh-editor-default-cb"
               value="wangeditor" <?php checked('wangeditor', $cur); ?>>
        <span>下次打开时默认用 WangEditor</span>
    </label>
    <?php
}, 11, 1);

/* ---------------------------------------------------------------------------
 * 把偏好值交给前端
 *
 * ⚠️ 优先级必须是 **25**：本模块的入队时机要比 wangeditor.php 晚 ——
 * 那边是在 `admin_enqueue_scripts` 的**优先级 20** 里 `wp_enqueue_script()`，
 * 而 `wp_localize_script` 只对**已经入队**的 handle 生效。
 * 用默认优先级 10 会跑在入队之前，localize **静默失效**（页面里根本没有那份配置），
 * 表现就是"开关渲染出来了、勾选状态也对，但自动进入不生效"（实测踩到）。
 * ------------------------------------------------------------------------- */
add_action('admin_enqueue_scripts', function () {
    if (!YBH_WANG_DEFAULT || !function_exists('ybh_wangeditor_screen') || !ybh_wangeditor_screen()) {
        return;
    }
    // 必须已经入队，否则 localize 会静默丢掉
    if (!wp_script_is('ybh-wangeditor-panel', 'enqueued')) {
        return;
    }
    wp_localize_script('ybh-wangeditor-panel', 'YBH_WANG_DEFAULT_CFG', array(
        'current'  => ybh_user_editor(),
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('ybh_editor_pref'),
        // 允许用 ?ybh_editor=classic 临时压过一次（排查用，不写库）
        'override' => isset($_GET['ybh_editor']) ? sanitize_key($_GET['ybh_editor']) : '',
    ));
}, 25);

/* ---------------------------------------------------------------------------
 * 保存偏好（AJAX）
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_ybh_editor_pref', function () {
    check_ajax_referer('ybh_editor_pref', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => '未登录。'), 403);
    }

    $want = isset($_POST['editor']) ? sanitize_key($_POST['editor']) : '';
    if (!in_array($want, array('wangeditor', 'classic'), true)) {
        wp_send_json_error(array('message' => '取值不合法。'), 400);
    }

    update_user_meta(get_current_user_id(), YBH_EDITOR_META, $want);
    wp_send_json_success(array('editor' => $want));
});

/* ---------------------------------------------------------------------------
 * 用户资料页也放一个（作者不在编辑页时也能改）
 * ------------------------------------------------------------------------- */
add_action('show_user_profile', 'ybh_editor_pref_profile_field');
add_action('edit_user_profile', 'ybh_editor_pref_profile_field');
function ybh_editor_pref_profile_field($user)
{
    if (!YBH_WANG_DEFAULT) {
        return;
    }
    ?>
    <h2>编辑器偏好</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="ybh_default_editor">默认编辑器</label></th>
            <td>
                <select name="ybh_default_editor" id="ybh_default_editor">
                    <option value="wangeditor" <?php selected('wangeditor', ybh_user_editor($user->ID)); ?>>
                        WangEditor（推荐，中文排版更顺手）
                    </option>
                    <option value="classic" <?php selected('classic', ybh_user_editor($user->ID)); ?>>
                        经典编辑器（TinyMCE）
                    </option>
                </select>
                <p class="description">
                    只影响「打开编辑页时默认用哪个」。两个编辑器随时可以互相切换，内容共用一份。
                </p>
            </td>
        </tr>
    </table>
    <?php
}

add_action('personal_options_update', 'ybh_editor_pref_profile_save');
add_action('edit_user_profile_update', 'ybh_editor_pref_profile_save');
function ybh_editor_pref_profile_save($user_id)
{
    if (!current_user_can('edit_user', $user_id)) {
        return;
    }
    if (!isset($_POST['ybh_default_editor'])) {
        return;
    }
    $v = sanitize_key($_POST['ybh_default_editor']);
    if (in_array($v, array('wangeditor', 'classic'), true)) {
        update_user_meta($user_id, YBH_EDITOR_META, $v);
    }
}
