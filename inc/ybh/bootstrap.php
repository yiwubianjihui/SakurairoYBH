<?php
/**
 * SakurairoYBH · YBH 增量层（fork 魔改入口）
 *
 * - 摘除上游"主题目录必须叫 Sakurairo"的强制检查（保住 fork 目录名）
 * - 默认字体注入更纱黑体（仅当选项仍为旧默认/空值时，用户显式自定义优先）
 * - 加载 YBH 样式层 css/ybh.css（字体系统/标题签名/展台/默认样式烘焙）
 * - 字体 CDN 预连接 + 首屏关键字重预加载
 * - 裁剪前端 Emoji 脚本（后台保留，dashboard-emoji-fix 不受影响）
 * - 注册友链批量导入工具
 * - 1.1：展台紧凑模式 / 文章列表摘要与列数 / 导航随机文章按钮（均可在「YBH 魔改」设置区切换）
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

define('YBH_FONT_CDN', 'https://www.yibianhui.cn/wp-content/uploads/ybh-fonts');
define('YBH_VERSION', '1.2.7');

/**
 * FontAwesome 本地化（双保险）：
 * - 主路径：下方 option_iro_options filter（functions.php 已改为先加载本文件再填充
 *   $GLOBALS['iro_options']，filter 会对 iro_opt 的数据源生效）；
 * - 保险路径：若本文件被移回全局数组填充之后加载（旧顺序），此处直接改写全局数组。
 * 两种加载顺序下，header / 404 / 编辑器样式里的 fontawesome_source 都走同源 ybh-fonts。
 */
if (isset($GLOBALS['iro_options']) && is_array($GLOBALS['iro_options'])
    && ($GLOBALS['iro_options']['ybh_local_fontawesome'] ?? true)) {
    $GLOBALS['iro_options']['fontawesome_source'] = YBH_FONT_CDN . '/fontawesome/css/all.min.css';
}

/**
 * 0) YBH 调整项开关 → body class（CSS 按类生效，全部可在「YBH 魔改」设置区切换）。
 */
add_filter('body_class', 'ybh_body_classes');
function ybh_body_classes($classes)
{
    if (iro_opt('ybh_exhibit_compact', true)) {
        $classes[] = 'ybh-exhibit-compact';
        $cols = (string) iro_opt('ybh_exhibit_cols', '6');
        $classes[] = 'ybh-exhibit-cols-' . (in_array($cols, array('3', '4', '6'), true) ? $cols : '6');
    }
    if (iro_opt('ybh_postlist_no_excerpt', true)) {
        $classes[] = 'ybh-postlist-noexcerpt';
    }
    if ((string) iro_opt('ybh_postlist_columns', '2') === '2') {
        $classes[] = 'ybh-postlist-2col';
    }
    if (iro_opt('ybh_exhibit_contain', false)) {
        $classes[] = 'ybh-exhibit-contain';
    }
    return $classes;
}

/**
 * 0.5) 随机文章：/?random_post=1 → 302 到一篇随机已发布文章。
 */
add_action('template_redirect', 'ybh_random_post_redirect');
function ybh_random_post_redirect()
{
    if (!isset($_GET['random_post'])) {
        return;
    }
    $posts = get_posts(array(
        'numberposts' => 1,
        'orderby' => 'rand',
        'post_type' => 'post',
        'post_status' => 'publish',
        'ignore_sticky_posts' => true,
    ));
    if (!empty($posts)) {
        wp_safe_redirect(get_permalink($posts[0]), 302);
        exit;
    }
    wp_safe_redirect(home_url('/'), 302);
    exit;
}

/**
 * 1) 上游在 admin_init 会把非 Sakurairo 目录强制改名回 Sakurairo，
 *    对 fork 而言这是破坏性行为，必须解除。
 *    （functions.php 中的钩子注册先于本文件加载，此处摘除即可生效）
 */
remove_action('admin_init', 'theme_folder_check_on_admin_init');

/**
 * 2) 默认字体：更纱黑体。
 *    站点数据库中多处字体选项仍存有旧默认 Noto Serif SC / Noto Sans SC
 *    （Google 字体，国内访客实际回退到系统字体），统一替换为更纱黑体全栈；
 *    用户显式设置的其他字体一律尊重（保留原格式选项）。
 *    覆盖键：全局默认/正文/导航菜单/页脚/换肤菜单/栏目标题/站名。
 */
add_filter('option_iro_options', 'ybh_font_option_defaults');
function ybh_font_option_defaults($value)
{
    if (!is_array($value)) {
        return $value;
    }
    $sarasa = "'Sarasa UI SC','PingFang SC','Microsoft YaHei','TH-Tshyn',sans-serif";
    $legacy = array('', 'Noto Serif SC', 'Noto Sans SC', 'Sarasa UI SC');
    $keys = array(
        'global_default_font',
        'global_font_2',
        'nav_menu_font',
        'footer_text_font',
        'style_menu_font',
        'area_title_font',
    );
    foreach ($keys as $key) {
        $current = isset($value[$key]) ? trim((string) $value[$key]) : '';
        if (in_array($current, $legacy, true)) {
            $value[$key] = $sarasa;
        }
    }
    if (isset($value['nav_text_logo']) && is_array($value['nav_text_logo'])) {
        $current = isset($value['nav_text_logo']['font_name']) ? trim((string) $value['nav_text_logo']['font_name']) : '';
        if (in_array($current, $legacy, true)) {
            $value['nav_text_logo']['font_name'] = $sarasa;
        }
    }

    /**
     * 2.5) FontAwesome 本地化：zstatic CDN 在部分网络下不可达导致全站图标
     *     显示为豆腐/空白；图标 CSS+webfonts 已同源化至 ybh-fonts，此处整体切换
     *     （header 预加载/样式表、404 页、编辑器样式均走 fontawesome_source）。
     *     注意从 $value 原始数组读开关：全局 $GLOBALS['iro_options'] 此刻可能尚未填充，
     *     iro_opt 会走 default，导致开关无法关闭。
     */
    if (($value['ybh_local_fontawesome'] ?? true)) {
        $value['fontawesome_source'] = YBH_FONT_CDN . '/fontawesome/css/all.min.css';
    }
    return $value;
}

/**
 * 3) YBH 样式层：直接在 wp_head 打印，优先级 10 —— 保证排在主题组合 CSS（优先级 9）
 *    或 iro-* 系列（wp_print_styles=8）之后；不使用 wp_enqueue_style，
 *    避免因依赖 handle（iro-dark/iro-responsive 仅在非组合分支注册）缺失而被整体跳过。
 */
add_action('wp_head', 'ybh_enqueue_layer', 10);
function ybh_enqueue_layer()
{
    printf(
        '<link rel="stylesheet" id="ybh-layer-css" href="%s/css/ybh.css?ver=%s">' . "\n",
        esc_url(get_template_directory_uri()),
        esc_attr(IRO_VERSION . '-ybh' . YBH_VERSION)
    );
}

/**
 * 4) 字体预加载（只给「每页确定会用到」的字重）。
 *
 * ⚠️ 这里的路径**必须与 css/ybh.css 里的 @font-face 保持一致**，否则会 preload 一堆
 *    用不上的文件——浏览器 preload 是无条件下载的，控制台还会报
 *    "preloaded but not used within a few seconds"。
 *    v1.2.2 做完字体子集化后 CSS 已改指 `ybh-fonts/slice/*`，本函数一度仍指向
 *    `sarasa/SarasaUiSC-*.woff2` 全量文件，等于每页白下 21 MB，把子集化收益全抵消。
 *
 * 只预加载正文(400)与标题(600)两个字重：
 *   - 正文：:lang(zh) 规则下所有正文都走 Sarasa UI SC 400，必然用到；
 *   - 标题：h1/h2/h3 与卡片标题用 600，页面首屏必有标题。
 * 霞鹜文楷只在正文出现 <em>/<i>/<cite> 等时才用得到，属内容相关，不做预加载
 * （预加载了反而会再次触发同类警告）。
 */
add_action('wp_head', 'ybh_resource_hints', 2);
function ybh_resource_hints()
{
    // 字体已同源化（wp-content/uploads/ybh-fonts），无需跨域 preconnect。
    $preloads = array(
        'slice/SarasaUiSC-Regular.subset.woff2',  // 正文 400
        'slice/SarasaUiSC-SemiBold.subset.woff2', // 标题 600
    );
    foreach ($preloads as $file) {
        printf(
            '<link rel="preload" href="%s/%s" as="font" type="font/woff2" crossorigin>' . "\n",
            YBH_FONT_CDN,
            $file
        );
    }
}

/**
 * 5) 前端 Emoji 脚本裁剪（后台不动，dashboard-emoji-fix.css 依旧有效）。
 */
add_action('init', 'ybh_trim_front_emoji');
function ybh_trim_front_emoji()
{
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('embed_head', 'print_emoji_detection_script');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
}

/**
 * 6) 友链批量导入工具（外观 → 友链批量导入）。
 */
require_once get_template_directory() . '/inc/ybh/friend-importer.php';

/**
 * 7) 经典编辑器体验（工具栏精简 / 回车与粘贴成段 / 空行保留 / 编辑区同前台样式）。
 *    依赖 classic-editor 插件；未激活时本文件的过滤器不生效也不报错。
 */
require_once get_template_directory() . '/inc/ybh/editor.php';

/**
 * 8) 主页标签行（文章数最多的前 30 个标签 + 「显示更多」折叠）。
 *    渲染函数由 index.php 的 primary 组件调用。
 */
require_once get_template_directory() . '/inc/ybh/home-tags.php';

/**
 * 8.5) 轻量 Cookie 同意横幅（替代 WPConsent）。
 *      自建：只有一个 PHP 文件 + 内联 CSS/JS，不连任何外部服务、不额外发请求。
 *      去 WPConsent 插件后由本文件接管。
 */
require_once get_template_directory() . '/inc/ybh/cookie-banner.php';

/**
 * 8.6) 后台层：后台美化（css/ybh-admin.css）+ 更易用的投稿入口
 *      （管理条「投稿」按钮 / 仪表盘快捷面板 / 文章菜单置顶）。
 *      两项均可在「YBH 魔改」设置区开关：ybh_admin_skin / ybh_quick_post。
 */
require_once get_template_directory() . '/inc/ybh/admin.php';

/**
 * 9) 随机封面默认改走主题自带的轻量端点 rand-cover.php
 *
 *    原先走主题内建 REST（/wp-json/sakura/v1/gallery?img=w）：每次请求都要**完整启动
 *    WordPress**（内核+插件+主题）再 302，而首页有 **10 张封面** ⇒ 一次访问 = 10 次重量级
 *    PHP 启动，是首页最大的性能黑洞。
 *    rand-cover.php **不加载 WordPress**，直读 imglist.json 后 302，耗时从数百毫秒降到几毫秒；
 *    该端点自带 `Cache-Control: max-age=60`，一分钟内复用同一次随机结果 ——
 *    既大幅减少请求，又保留「每次刷新基本都换图」的随机感。
 *
 *    只在设置**确实是「使用主题内建图库」**时才改写 —— 该开关（random_graphs_options）
 *    的取值实测为 `gallery`（另有 `internal_api` 与空值也算内建）；若你显式切成 `external_api`
 *    并填了自己的外链，则完全尊重你的设置。
 *    注意：`random_graphs_link` 里可能残留上游默认的外链（如 api.kuroko.cn / api.fuukei.org），
 *    那在 `gallery` 模式下并不会被使用，因此判断只看开关，不看那个链接。
 */
add_filter('option_iro_options', 'ybh_cover_api_endpoint');
function ybh_cover_api_endpoint($value)
{
    if (!is_array($value)) {
        return $value;
    }

    $opt = isset($value['random_graphs_options']) ? (string) $value['random_graphs_options'] : '';

    // '' / 'gallery' / 'internal_api' 都表示「用主题自带图库」；只有 external_api 表示自定义外链
    if (!in_array($opt, array('', 'gallery', 'internal_api'), true)) {
        return $value;   // 用户自定义过，不动
    }

    $base = get_template_directory_uri() . '/rand-cover.php';
    $value['random_graphs_options']     = 'external_api';
    $value['random_graphs_link']        = $base . '?img=w';   // 桌面：横图
    $value['random_graphs_mts']         = true;
    $value['random_graphs_link_mobile'] = $base . '?img=l';   // 移动：竖图（与原内建分支一致）

    return $value;
}

/**
 * 10) 前端 JS 的缓存键
 *
 *     主题用 IRO_VERSION 作 app.js 的版本参数，而那个值不随 YBH 的改动变化；
 *     偏偏宝塔给 `.js` 配了 12 小时缓存 ⇒ 改完 app.js，访客（含自己）会在半天内
 *     继续拿到旧文件，很难察觉。这里给 app / app-page 的 URL 追加 YBH 版本号，
 *     版本一动 URL 就变，缓存自然失效。
 *
 *     配套：`js/app.js` 中自动加载下一页的延时由 `1e3*parseInt(e,10)` 改为
 *     `1e3*parseFloat(e)`，这样后台「自动加载延时」可以填小数（如 0.5 秒）；
 *     原来用 parseInt 会把 0.5 截断成 0（等于立刻触发）。
 */
add_filter('script_loader_src', function ($src, $handle) {
    if (in_array($handle, array('app', 'app-page'), true)) {
        $src = add_query_arg('ybh', YBH_VERSION, $src);
    }
    return $src;
}, 10, 2);

/**
 * 7) 上传图片自动转 WebP（GitHub issue #2）。
 *    - 拦截 wp_handle_upload：jpg/png 落盘即用 GD 转为 WebP 并替换文件，
 *      url/类型同步改写；原图不保留（避免双份占用）。
 *    - 动画图（GIF/APNG）、SVG 等非 GD 可处理类型自动跳过。
 *    - 可在「YBH 魔改 → 性能」关闭或调整质量（默认开，质量 82）。
 *    - GD 无 WebP 支持时静默降级为原格式，不影响上传。
 */
add_filter('wp_handle_upload', 'ybh_upload_to_webp');
function ybh_upload_to_webp($upload)
{
    if (!iro_opt('ybh_webp_convert', true)) {
        return $upload;
    }
    if (empty($upload['file']) || empty($upload['type'])) {
        return $upload;
    }
    // 仅处理 jpeg/png；gif（可能含动画）/webp(已是)/svg 等跳过
    if (!in_array($upload['type'], array('image/jpeg', 'image/png'), true)) {
        return $upload;
    }
    if (!function_exists('imagecreatefromjpeg') && !function_exists('imagecreatefrompng')) {
        return $upload;
    }
    // GD WebP 支持检测（imagewebp 存在且 gd info 声明 WebP）
    if (!function_exists('imagewebp')
        || !function_exists('gd_info')
        || stripos(implode('', gd_info()), 'webp') === false) {
        return $upload;
    }

    $path = $upload['file'];
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg', 'jpeg', 'png'), true)) {
        return $upload;
    }

    // PNG 走无歧义加载（透明通道），JPEG 走 imagecreatefromjpeg
    if ($ext === 'png' && function_exists('imagecreatefrompng')) {
        $img = @imagecreatefrompng($path);
    } elseif (function_exists('imagecreatefromjpeg')) {
        $img = @imagecreatefromjpeg($path);
    } else {
        return $upload;
    }
    if (!$img) {
        return $upload; // 解码失败保留原文件
    }

    if (function_exists('imagepalettetotruecolor')) {
        imagepalettetotruecolor($img);
    }
    if (function_exists('imagealphablending')) {
        imagealphablending($img, true);
        imagesavealpha($img, true);
    }

    $quality = (int) iro_opt('ybh_webp_quality', 82);
    if ($quality < 50 || $quality > 95) {
        $quality = 82;
    }

    $webp_path = $path . '.webp_tmp';
    if (!@imagewebp($img, $webp_path, $quality)) {
        imagedestroy($img);
        if (is_file($webp_path)) {
            @unlink($webp_path);
        }
        return $upload; // 编码失败保留原文件
    }
    imagedestroy($img);

    // webp 反而更大（如低色彩 png）→ 放弃转换，保留原图
    if (filesize($webp_path) >= filesize($path)) {
        @unlink($webp_path);
        return $upload;
    }

    // 目标文件名：替换扩展名为 .webp，同步改写 url 与 MIME
    $new_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
    if ($new_path === $path) {
        @unlink($webp_path);
        return $upload;
    }
    @unlink($path);                 // 原图不保留，避免双份占用
    rename($webp_path, $new_path);
    $upload['file'] = $new_path;
    $upload['type'] = 'image/webp';
    $upload['url']  = preg_replace('/\.(jpe?g|png)$/i', '.webp', $upload['url']);
    return $upload;
}

/* ---------------------------------------------------------------------------
 * 登录 / 重置密码
 * ------------------------------------------------------------------------- */

/**
 * 重置密码链接的有效期。
 *
 * WordPress 默认 `password_reset_expiration` = DAY_IN_SECONDS，也就是**只有 24 小时**。
 * 超时后 wp-login.php 会把用户 302 到
 * `wp-login.php?action=lostpassword&error=expiredkey`，显示「密码重置链接已过期」。
 * 投稿者往往隔天才翻邮件，因此这条「链接失效」会反复出现。
 *
 * 这里放宽到 7 天。要收紧（或配合安全插件调整）就改 YBH_PASSWORD_RESET_DAYS。
 * 注：重置成功后 WordPress 会立刻清空 user_activation_key，链接随即失效；
 * 重复申请也会覆盖旧密钥，使更早那封邮件里的链接失效 —— 这两种属于设计行为。
 */
if (!defined('YBH_PASSWORD_RESET_DAYS')) {
    define('YBH_PASSWORD_RESET_DAYS', 7);
}

add_filter('password_reset_expiration', function ($expiration) {
    $days = (int) YBH_PASSWORD_RESET_DAYS;
    if ($days < 1) {
        $days = 1;
    }
    // 用 max()：若安全插件已经把有效期放宽得更长，就不要反而缩短它。
    return max((int) $expiration, $days * DAY_IN_SECONDS);
}, 20);

/* ---------------------------------------------------------------------------
 * 10) 低端设备探测：在 <html> 上打 ybh-lite，交给 CSS 削弱/移除动效
 * ------------------------------------------------------------------------- */

/**
 * 为什么必须是内联脚本：
 * 若等外部 JS 加载后再加类，页面会先按「完整动效」渲染一帧再切换，
 * 低端设备上这一帧恰好最贵（毛玻璃 + 滤镜 + 位移动画同时上演），
 * 观感是明显的闪烁与卡顿。所以放在 wp_head 最靠前的位置同步执行。
 *
 * 判定依据（任一命中即降级）：
 *   · navigator.deviceMemory ≤ 4       —— 设备内存小（仅 Chromium 系支持）
 *   · navigator.hardwareConcurrency ≤ 2 —— 极低端兜底（非 Chromium 时唯一可用信号）
 *   · connection.saveData              —— 用户主动开启省流
 *   · effectiveType 为 2g             —— 网络极慢
 * 不把「4 核」单独当作低端依据：桌面四核（i3 / 老 U）配 8G 内存并不算低端，
 * 只看核心数会误伤一大批正常设备。
 *
 * 逃生舱：localStorage['ybh_force_full'] = '1' 强制完整动效，
 * '0' 强制降级 —— 便于按设备实测对比。
 */
add_action('wp_head', 'ybh_client_prefs', 1);
function ybh_client_prefs()
{
    if (is_admin() || is_feed() || is_robots()) {
        return;
    }
    ?>
    <script>
    (function () {
      var h = document.documentElement;
      /* --- 11) 紧凑模式：必须同步应用，否则会先按大卡片渲染一帧再跳变 --- */
      try {
        if (localStorage.getItem('ybh_compact') === '1') h.classList.add('ybh-compact');
      } catch (e) {}

      /* --- 10) 低端设备探测 --- */
      try {
        var force = null;
        try { force = localStorage.getItem('ybh_force_full'); } catch (e) {}
        if (force === '1') { h.classList.remove('ybh-lite'); return; }
        if (force === '0') { h.classList.add('ybh-lite'); return; }

        var nav = navigator;
        var mem = nav.deviceMemory || 0;                 // 仅 Chromium 系
        var cores = nav.hardwareConcurrency || 0;
        var conn = nav.connection || nav.mozConnection || nav.webkitConnection || {};
        var et = conn.effectiveType || '';

        var lite = (mem && mem <= 4) ||
                   (cores && cores <= 2) ||
                   conn.saveData === true ||
                   /(^|-)2g$/.test(et);
        if (lite) h.classList.add('ybh-lite');
      } catch (e) { /* 探测失败则维持完整动效，不干扰页面 */ }
    })();
    </script>
    <?php
}

/**
 * 紧凑模式开关：右下角控制台（#changskin → .skin-menu）里的按钮。
 *
 * 为什么不改 js/app.js：那是 webpack 打包的压缩产物，手改会在下次
 * 构建/升级时被覆盖，也无法在源码层维护。这里用一小段独立脚本绑定，
 * 与字体/日夜模式共用同一套 localStorage 记忆习惯（ybh_compact）。
 */
add_action('wp_footer', 'ybh_compact_toggle_script', 99);
function ybh_compact_toggle_script()
{
    if (is_admin() || is_feed() || is_robots()) {
        return;
    }
    ?>
    <script>
    (function () {
      var btn = document.querySelector('.skin-menu .ybh-compact-toggle');
      var h = document.documentElement;
      if (!btn) return;

      function sync() {
        btn.classList.toggle('selected', h.classList.contains('ybh-compact'));
      }
      sync();

      btn.addEventListener('click', function () {
        var on = h.classList.toggle('ybh-compact');
        try { localStorage.setItem('ybh_compact', on ? '1' : '0'); } catch (e) {}
        sync();
      });
    })();
    </script>
    <?php
}
