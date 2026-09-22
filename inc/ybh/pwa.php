<?php
/**
 * YBH · 网页App（PWA）：可"添加到主屏幕" + 离线字体缓存
 *
 * 对应任务：`handoff/T48-PWA与Windows端-计划.md` 的 **P1（能装）+ P2（离线与字体缓存）**。
 * 用户 2026-09-22 决定的范围（**明确不做**）：Web Push 推送、Windows 打包、摇人器上网页。
 *
 * ## 为什么放在主题里、用 PHP 输出而不是放静态文件
 *
 * 1. **MIME 必须可控**。`manifest` 规范要求 `application/manifest+json`；
 *    宝塔/nginx 对未知后缀常回 `application/octet-stream`，浏览器会**静默忽略整个 manifest**
 *    （现象是"加了 manifest 却没变成 App"，且没有任何报错）。SW 同理，必须是 JS MIME。
 * 2. **`/sw.js` 必须在网站根作用域**。Service Worker 只能控制**它所在路径之下**的页面，
 *    若把 `sw.js` 放成 `/wp-content/themes/.../sw.js`，它就只能控制那个目录，**整站 PWA 能力静默失效**。
 *    这里用「根路径 → PHP 输出」的方式拿到 `/` 作用域，且**不依赖 WP 重写规则刷写**
 *    （直接比对 `REQUEST_URI`，只要 nginx 把不存在的路径 fallback 到 index.php 即可）。
 * 3. **版本号跟着文件修改时间走**。本主题已经因为"改了没生效"吃过亏（见 bootstrap.php §版本号），
 *    SW 会**再引入一层同类故障**，所以缓存名与注册 URL 都带 `filemtime`（复用 `ybh_asset_ver()`），
 *    并且**新版本要由用户点击才生效**（不静默 skipWaiting，避免"旧 HTML 配新 JS"）。
 *
 * ## 两个入口
 *   GET /manifest.webmanifest  → Web App Manifest
 *   GET /sw                    → Service Worker（**故意不带 .js**，见 ybh_pwa_sw_paths() 的说明）
 *
 * @package Sakurairo
 */

if (!defined('ABSPATH')) {
    exit;
}

/** 允许的 manifest 路径别名（有的浏览器/工具会按 .json 取）。 */
function ybh_pwa_manifest_paths()
{
    return array('/manifest.webmanifest', '/manifest.json');
}

/**
 * SW 的对外路径。**故意不带 `.js` 后缀**。
 *
 * ## 这一条是踩过坑之后的决定（2026-09-22 实测）
 *
 * 本站 nginx 有一条通用静态规则：
 *     location ~ .*\.(js|css)?$ { expires 12h; ... }
 * 凡是**以 .js / .css 结尾**的请求都被它接管、按静态文件去找，找不到就 404 ——
 * **不会 fallback 到 index.php**。于是 `/sw.js` 返回 404，而 `/manifest.webmanifest`
 * 因为不匹配那条正则反而正常。实测对照：
 *     /sw.js                 → 404（HTML，nginx 静态规则）
 *     /manifest.webmanifest  → 200 application/manifest+json ✅
 *
 * 所以 SW 走 `/sw`（无扩展名）：不匹配任何静态后缀规则，正常落到 PHP；
 * 且因为它位于**站点根**，Service Worker 的默认作用域天然就是 `/`，无需依赖
 * `Service-Worker-Allowed` 放宽（该头仍然发，作为双保险）。
 *
 * `/sw.js` 仍作为别名保留：万一将来那条 nginx 规则被改掉，两条路径都能用。
 */
function ybh_pwa_sw_paths()
{
    return array('/sw', '/sw.js');
}

/**
 * SW 的注册 URL（带版本号：文件一改，URL 就变，浏览器据此判定为更新）。
 *
 * @return string
 */
function ybh_pwa_sw_url()
{
    return home_url('/sw?v=' . rawurlencode(ybh_pwa_sw_version()));
}

/**
 * 当前请求的路径（不含查询串）。用于精确比对两个入口。
 *
 * @return string
 */
function ybh_pwa_request_path()
{
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = wp_parse_url($uri, PHP_URL_PATH);
    return is_string($path) ? $path : '';
}

/**
 * SW 的版本串：取 SW 源文件的修改时间，取不到就退回主题版本。
 *
 * @return string
 */
function ybh_pwa_sw_version()
{
    $rel = 'inc/ybh/pwa-sw.js';
    return function_exists('ybh_asset_ver') ? ybh_asset_ver($rel) : (defined('YBH_VERSION') ? YBH_VERSION : '1');
}

/**
 * 两个入口的实际输出。挂在 `init` 上，抢在 WP 主查询之前 `exit`，
 * 这样**不需要注册重写规则、也不需要 flush**（flush 在多站点/nginx 下很容易失效）。
 */
function ybh_pwa_serve_endpoints()
{
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    if ($method !== 'GET' && $method !== 'HEAD') {
        return;
    }

    $path = ybh_pwa_request_path();

    // ---- /manifest.webmanifest ----
    if (in_array($path, ybh_pwa_manifest_paths(), true)) {
        $icon = get_template_directory_uri() . '/img/pwa/';
        $manifest = array(
            'id' => '/',
            'name' => get_bloginfo('name') . ' · 义编会',
            'short_name' => 'YBH',
            // start_url 带标识参数：便于后续区分"从主屏图标启动"与"浏览器访问"
            'start_url' => home_url('/?ybh_app=1'),
            'scope' => home_url('/'),
            'display' => 'standalone',
            'orientation' => 'any',
            'lang' => get_bloginfo('language') ?: 'zh-CN',
            'dir' => 'ltr',
            'description' => get_bloginfo('description') ?: '义编会（YBH）博客客户端',
            // 与 header.php 的 <meta name="theme-color"> 保持同一来源
            'theme_color' => function_exists('iro_opt') && iro_opt('theme_skin') ? iro_opt('theme_skin') : '#505050',
            'background_color' => '#ffffff',
            'icons' => array(
                array(
                    'src' => $icon . 'icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ),
                array(
                    'src' => $icon . 'icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ),
                array(
                    'src' => $icon . 'icon-maskable-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ),
                array(
                    'src' => $icon . 'icon-maskable-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ),
            ),
        );

        nocache_headers();
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo wp_json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- /sw 或 /sw.js ----
    if (in_array($path, ybh_pwa_sw_paths(), true)) {
        $file = get_template_directory() . '/inc/ybh/pwa-sw.js';
        if (!is_readable($file)) {
            status_header(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'sw source missing';
            exit;
        }
        $js = (string) file_get_contents($file);
        // 版本占位符 → 实际版本（缓存名带版本，是"改了没生效"的第一道闸）
        $js = str_replace('__YBH_PWA_VERSION__', ybh_pwa_sw_version(), $js);
        // 站点根，供 SW 判断同源与排除路径
        $js = str_replace('__YBH_PWA_SCOPE__', home_url('/'), $js);
        // 主题目录 URL，供 SW 预缓存主题内资源（**必须带主题路径**，否则拼成站点根会 404）
        $js = str_replace('__YBH_PWA_THEME__', trailingslashit(get_template_directory_uri()), $js);

        // SW 自身**不能**被长缓存：浏览器更新检查虽会绕过 HTTP 缓存，但老旧实现有 24h 上限
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate, max-age=0');
        // 允许把作用域放到根（本入口本来就是 /sw.js，显式声明更稳）
        header('Service-Worker-Allowed: /');
        echo $js;
        exit;
    }
}
add_action('init', 'ybh_pwa_serve_endpoints', 1);

/**
 * 用 PNG 图标替换 WordPress 站点图标输出（P1 的关键修复）。
 *
 * ## 这里修的是一个**实际影响 iOS 的缺陷**
 *
 * 线上现状（2026-09-22 实测首页 HTML）：`<link rel="apple-touch-icon">` 指向
 * **`.webp`**（`cropped-...-180x180.webp`）。iOS 对 apple-touch-icon 的 WebP 支持并不可靠，
 * 结果是"添加到主屏幕"时**图标可能不生效**（退化成截图或首字母图标）。
 * 这里撤掉 WP 的输出，改成主题内的 **PNG**（180/192/512），并且：
 *   - 给 `apple-touch-icon` 显式写 `sizes="180x180"`（Apple 按 sizes 择优）
 *   - 同时补 `mobile-web-app-capable` —— iOS 上"点开无地址栏"要靠它/manifest 一起生效
 */
function ybh_pwa_head_meta()
{
    if (is_admin()) {
        return;
    }
    // 撤掉 WP 默认的站点图标输出（webp 的 apple-touch-icon 就是它发的）
    remove_action('wp_head', 'wp_site_icon', 99);

    $base = get_template_directory_uri() . '/img/pwa/';
    $ver = ybh_pwa_sw_version();

    echo "\n<!-- YBH PWA（T48 P1） -->\n";
    printf(
        '<link rel="manifest" href="%s">' . "\n",
        esc_url(home_url('/manifest.webmanifest?v=' . rawurlencode($ver)))
    );
    // iOS：PNG + 显式尺寸
    printf('<link rel="apple-touch-icon" sizes="180x180" href="%s">' . "\n", esc_url($base . 'apple-touch-icon.png'));
    printf('<link rel="icon" type="image/png" sizes="192x192" href="%s">' . "\n", esc_url($base . 'icon-192.png'));
    printf('<link rel="icon" type="image/png" sizes="512x512" href="%s">' . "\n", esc_url($base . 'icon-512.png'));
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
    echo '<meta name="apple-mobile-web-app-title" content="YBH">' . "\n";
    printf('<meta name="ybh-pwa-sw" content="%s">' . "\n", esc_url(ybh_pwa_sw_url()));
}
add_action('wp_head', 'ybh_pwa_head_meta', 1);

/**
 * 前端资源：安装引导样式 + 注册脚本。
 *
 * 只在前台、非登录页加载；**不碰后台**。
 */
function ybh_pwa_enqueue()
{
    if (is_admin()) {
        return;
    }
    $dir = get_template_directory();
    $uri = get_template_directory_uri();

    $css = $dir . '/css/ybh-pwa.css';
    $js = $dir . '/js/ybh-pwa.js';

    wp_enqueue_style(
        'ybh-pwa',
        $uri . '/css/ybh-pwa.css',
        array(),
        is_readable($css) ? (string) filemtime($css) : '1'
    );
    wp_enqueue_script(
        'ybh-pwa',
        $uri . '/js/ybh-pwa.js',
        array(),
        is_readable($js) ? (string) filemtime($js) : '1',
        true
    );

    // 传给前端的少量配置（不引额外请求）
    wp_localize_script('ybh-pwa', 'YbhPwa', array(
        'sw' => ybh_pwa_sw_url(),
        'isApp' => (isset($_GET['ybh_app']) && (string) $_GET['ybh_app'] === '1'),
        'i18n' => array(
            'installTitle' => '把本站装到主屏幕',
            'installBody' => '装好后像 App 一样打开，没有地址栏，字体也已缓存，弱网也能看。',
            'iosHow' => '点底部「分享」→「添加到主屏幕」',
            'androidHow' => '点菜单「安装应用」/「添加到主屏幕」',
            'install' => '安装',
            'later' => '以后再说',
            'installed' => '已安装',
            'updateReady' => '有新版本',
            'updateDo' => '点击刷新',
        ),
    ));
}
add_action('wp_enqueue_scripts', 'ybh_pwa_enqueue', 20);
