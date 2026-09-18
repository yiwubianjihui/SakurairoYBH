<?php
/**
 * YBH · WangEditor 5 编辑器（附加入口，不改动原 TinyMCE）
 *
 * ===================================================================
 * 定位
 * ===================================================================
 *
 * 用户要求「部署 WangEditor 5 为编辑器，**同时保留原编辑器入口**」。
 *
 * WangEditor 5 是国产开源富文本编辑器（MIT），对中文排版习惯更贴合。
 * 它的工作方式是**接管一个容器**、自己维护编辑面与工具栏 ——
 * 这与 WordPress 经典编辑器的 TinyMCE **无法共用同一个 `#content`**：
 * 两个编辑器会同时监听同一个 textarea，互相覆盖。
 *
 * 所以本模块采取的做法是「**并存、不替换**」：
 *   · 经典编辑器（TinyMCE / 文本）原样保留，是默认入口；
 *   · 编辑页顶部多一个「用 WangEditor 编辑」按钮；
 *   · 点开是一层**全屏面板**，在里面用 WangEditor 编辑同一篇内容；
 *   · 关闭/保存时把 HTML **写回 `#content`**，之后照常走 WordPress 的保存流程。
 *
 * 这样两边都不受损：习惯 TinyMCE 的继续用，想用 WangEditor 的点一下切过去；
 * 而且**不会**因为装了新编辑器而影响任何既有投稿/编辑路径。
 *
 * ===================================================================
 * 为什么自建资源而不引 CDN
 * ===================================================================
 *   本站面向国内访客，且此前刚把 s.nmxc.ltd 那批海外 CDN 资源本地化
 *   （首页 12.77MB → 4.06MB）。再引一个海外 CDN 是往回走。
 *   产物放在主题 `vendor/wangeditor/`，只在**编辑页**入队 ——
 *   它 1.3MB，但对访客零影响（访客根本不加载）。
 *
 * ===================================================================
 * 图片上传
 * ===================================================================
 *   WangEditor 的上传约定是：POST 到 `server`，拿回 JSON
 *       { "errno": 0, "data": { "url": "...", "alt": "", "href": "" } }
 *   WordPress 没有这个形状的接口，所以这里挂一个 admin-ajax 端点
 *   （`ybh_wangeditor_upload`）转发给 `media_handle_upload()`，
 *   把结果转成上面的格式。**权限沿用 WordPress 自己的**
 *   （`upload_files`），不另开一套判断。
 *
 * ===================================================================
 * 关闭方式：把 YBH_WANGEDITOR 定义为 false。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('YBH_WANGEDITOR')) {
    define('YBH_WANGEDITOR', true);
}

/** 产物目录（相对主题根） */
if (!defined('YBH_WANGEDITOR_DIR')) {
    define('YBH_WANGEDITOR_DIR', 'vendor/wangeditor');
}

/**
 * 资源是否就位（产物缺失时整个模块静默不启用，不影响编辑页）。
 */
function ybh_wangeditor_ready()
{
    if (!YBH_WANGEDITOR) {
        return false;
    }
    $base = get_template_directory() . '/' . YBH_WANGEDITOR_DIR;
    return file_exists($base . '/index.js') && file_exists($base . '/css/style.css');
}

/**
 * 只在「文章/页面的新建与编辑页」入队 —— 其余后台页面一律不加载。
 */
function ybh_wangeditor_screen()
{
    if (!function_exists('get_current_screen')) {
        return false;
    }
    $screen = get_current_screen();
    if (!$screen) {
        return false;
    }
    return in_array($screen->base, array('post'), true)
        && in_array($screen->post_type, array('post', 'page'), true);
}

add_action('admin_enqueue_scripts', function ($hook) {
    if (!ybh_wangeditor_ready() || !ybh_wangeditor_screen()) {
        return;
    }

    $dir = get_template_directory_uri() . '/' . YBH_WANGEDITOR_DIR;
    $ver = function_exists('ybh_asset_ver') ? ybh_asset_ver(YBH_WANGEDITOR_DIR . '/index.js') : YBH_VERSION;

    wp_enqueue_style(
        'ybh-wangeditor',
        $dir . '/css/style.css',
        array(),
        function_exists('ybh_asset_ver') ? ybh_asset_ver(YBH_WANGEDITOR_DIR . '/css/style.css') : YBH_VERSION
    );

    wp_enqueue_script('ybh-wangeditor', $dir . '/index.js', array(), $ver, true);

    /*
     * ⚠️ 版本号走 wp_enqueue_* 的第 4 个参数，**不要**自己 add_query_arg('ver', …)：
     * WP 自己会拼 ?ver=<版本号>，两边都拼就会得到 `…?ver=X&ver=Y`（实测踩到）。
     * 这里用各自文件的 mtime 当版本号 —— 与 ybh_asset_ver 的约定一致，存盘即失效缓存。
     */
    wp_enqueue_style(
        'ybh-wangeditor-panel',
        get_template_directory_uri() . '/css/ybh-wangeditor.css',
        array(),
        function_exists('ybh_asset_ver') ? ybh_asset_ver('css/ybh-wangeditor.css') : YBH_VERSION
    );

    wp_enqueue_script(
        'ybh-wangeditor-panel',
        get_template_directory_uri() . '/js/ybh-wangeditor.js',
        array('ybh-wangeditor'),
        function_exists('ybh_asset_ver') ? ybh_asset_ver('js/ybh-wangeditor.js') : YBH_VERSION,
        true
    );

    wp_localize_script('ybh-wangeditor-panel', 'YBH_WANG', array(
        'uploadUrl' => admin_url('admin-ajax.php'),
        'nonce'     => wp_create_nonce('ybh_wangeditor_upload'),
        'action'    => 'ybh_wangeditor_upload',
        'canUpload' => current_user_can('upload_files'),
        'editorUrl' => '',   // 由 JS 自取
    ));
}, 20);

/* ---------------------------------------------------------------------------
 * 编辑页顶部的切换入口
 *
 * 放在 `edit_form_top`（标题上方、编辑器之外）——
 * 不侵入任何编辑器自身的 DOM，TinyMCE 完全察觉不到它。
 * ------------------------------------------------------------------------- */
add_action('edit_form_top', function ($post) {
    if (!ybh_wangeditor_ready() || !ybh_wangeditor_screen()) {
        return;
    }
    ?>
    <div class="ybh-wang-bar">
        <button type="button" class="button button-secondary" id="ybh-wang-open">
            <span class="dashicons dashicons-edit"></span>
            用 WangEditor 编辑
        </button>
        <span class="ybh-wang-bar__hint">
            原编辑器（可视化 / 文本）保持不变，随时可在上面或这里切换。
        </span>
    </div>
    <?php
}, 10, 1);

/* ---------------------------------------------------------------------------
 * 图片上传端点
 *
 * 形状对齐 WangEditor 的约定；权限沿用 WordPress 的 upload_files。
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_ybh_wangeditor_upload', function () {
    // 权限：沿用 WP 自己的判断，不另造一套
    if (!current_user_can('upload_files')) {
        wp_send_json(array('errno' => 1, 'message' => '当前账号没有上传文件的权限。'));
    }
    check_ajax_referer('ybh_wangeditor_upload', 'nonce');

    if (empty($_FILES['wangeditor'])) {
        wp_send_json(array('errno' => 1, 'message' => '没有收到文件。'));
    }

    // media_handle_upload 要求这个形状；它自己会做类型/大小校验
    $file = $_FILES['wangeditor'];
    if (is_array($file['name'])) {
        wp_send_json(array('errno' => 1, 'message' => '一次只接受一个文件。'));
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    $id = media_handle_upload('wangeditor', $post_id);

    if (is_wp_error($id)) {
        wp_send_json(array('errno' => 1, 'message' => $id->get_error_message()));
    }

    $url = wp_get_attachment_url($id);
    wp_send_json(array(
        'errno' => 0,
        'data'  => array(
            'url'  => $url,
            'alt'  => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'href' => '',
        ),
    ));
});

/* 投稿者上传时，media_handle_upload 会把附件挂到文章上；
   这里不需要额外放开 `unfiltered_upload` —— 交给 WP 自己的白名单。 */
