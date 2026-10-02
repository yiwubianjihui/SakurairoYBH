<?php
/**
 * YBH · 多语言（T66）
 *
 * ===================================================================
 * 设计取舍（先说要紧的三条）
 * ===================================================================
 * 1) **语言放在 URL 上**（`?lang=en`），同时写一份 cookie。
 *    URL 上带语言 → 分享出去的链接别人打开就是同一种语言，也对搜索引擎友好。
 *    cookie 只是"下次再来还记得你选过"。
 *
 * 2) **默认语言（简体中文）保持被页面缓存**；非默认语言 **不走页面缓存**
 *    （`DONOTCACHEPAGE` + `Vary: Cookie`）。
 *    为什么：页面缓存是按 URL 存的，而"选了英文"的用户下次访问的 URL 上没有 `?lang=`——
 *    若照常缓存，就会把英文页面喂给中文访客（或反过来）。让非默认语言动态渲染最省事、
 *    也不会出现"串语言"；代价是英文/日文访客每页多一次 PHP 渲染（站点规模小，可以接受）。
 *    想改成"路径式语言"（`/en/...`，可缓存）需要加 rewrite 规则并刷新，留作后续。
 *
 * 3) **文案以中文原文为 key**（`ybh_t('保存草稿')`），缺译时**回退中文**。
 *    好处：不必先给几百条文案起英文键名；漏译时页面显示中文而不是一串 key。
 *    代价：改中文原文就等于改 key（本层自己的文案，可控）。
 *
 * ===================================================================
 * 与 WordPress 自带 i18n 的关系
 * ===================================================================
 *   · 本层的界面文案走 `ybh_t()`（下面的 `inc/ybh/i18n-strings.php` 提供译文）；
 *   · **主题/核心**的文案（评论表单、分页等）走 WordPress 自己的 `locale` ——
 *     所以这里按当前语言把 `locale` 切成 `en_US` / `ja`，让核心文案跟着走。
 *     ⚠️ 只在前台切（`!is_admin()`）：否则站长自己的后台会被访客的语言设置带偏。
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

/** cookie 名（1 年） */
if (!defined('YBH_LANG_COOKIE')) {
    define('YBH_LANG_COOKIE', 'ybh_lang');
}

/**
 * 可选语言。键是 BCP-47 标签（进 `lang` 属性与 `hreflang`），
 * `locale` 是切换 WordPress 核心文案用的 WP locale。
 */
function ybh_languages()
{
    return apply_filters('ybh_languages', array(
        'zh-Hans' => array('native' => '简体中文', 'english' => 'Simplified Chinese', 'locale' => 'zh_CN', 'default' => true),
        'zh-Hant' => array('native' => '繁體中文', 'english' => 'Traditional Chinese', 'locale' => 'zh_TW'),
        'en'      => array('native' => 'English',  'english' => 'English',             'locale' => 'en_US'),
        'ja'      => array('native' => '日本語',    'english' => 'Japanese',            'locale' => 'ja'),
        'fr'      => array('native' => 'Français', 'english' => 'French',              'locale' => 'fr_FR'),
        'ru'      => array('native' => 'Русский',  'english' => 'Russian',             'locale' => 'ru_RU'),
        'es'      => array('native' => 'Español',  'english' => 'Spanish',             'locale' => 'es_ES'),
    ));
}

/** 默认语言 */
function ybh_default_language()
{
    foreach (ybh_languages() as $code => $info) {
        if (!empty($info['default'])) {
            return $code;
        }
    }
    return 'zh-Hans';
}

/** 语言是否合法 */
function ybh_language_valid($code)
{
    return array_key_exists((string) $code, ybh_languages());
}

/**
 * 当前语言。判定顺序：URL 参数 → cookie → 默认。
 *
 * 不按 `Accept-Language` 自动判断：那会让同一个 URL 对不同人渲染不同结果，
 * 与页面缓存天然冲突（也让"分享给别人的链接"变得不可预期）。
 */
function ybh_current_language()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $lang = '';
    if (isset($_GET['lang'])) {
        $maybe = sanitize_text_field(wp_unslash((string) $_GET['lang']));
        if (ybh_language_valid($maybe)) {
            $lang = $maybe;
        }
    }
    if ($lang === '' && isset($_COOKIE[YBH_LANG_COOKIE])) {
        $maybe = sanitize_text_field(wp_unslash((string) $_COOKIE[YBH_LANG_COOKIE]));
        if (ybh_language_valid($maybe)) {
            $lang = $maybe;
        }
    }
    if ($lang === '') {
        $lang = ybh_default_language();
    }
    $cached = $lang;
    return $cached;
}

/** 当前语言是不是默认语言 */
function ybh_is_default_language()
{
    return ybh_current_language() === ybh_default_language();
}

/** 当前语言对应的 WP locale */
function ybh_current_locale()
{
    $langs = ybh_languages();
    $cur = ybh_current_language();
    return isset($langs[$cur]['locale']) ? (string) $langs[$cur]['locale'] : 'zh_CN';
}

/**
 * 把 `?lang=` 写进 cookie 并做两件缓存相关的事。
 * 挂在 `init` 上（早于输出），只在前台跑。
 */
add_action('init', 'ybh_i18n_boot', 5);
function ybh_i18n_boot()
{
    if (is_admin() || is_feed() || is_robots()) {
        return;
    }

    // URL 上带了语言 ⇒ 记住它（1 年）。默认语言则清掉 cookie，回到"跟随站点"。
    if (isset($_GET['lang'])) {
        $maybe = sanitize_text_field(wp_unslash((string) $_GET['lang']));
        if (ybh_language_valid($maybe)) {
            $val = ($maybe === ybh_default_language()) ? '' : $maybe;
            $cookie = YBH_LANG_COOKIE . '=' . rawurlencode($val)
                . '; path=/; max-age=' . ($val === '' ? 0 : YEAR_IN_SECONDS)
                . '; SameSite=Lax' . (is_ssl() ? '; Secure' : '');
            if (!headers_sent()) {
                header('Set-Cookie: ' . $cookie, false);
            }
            $_COOKIE[YBH_LANG_COOKIE] = $val;
        }
    }

    // 非默认语言：不缓存 + 告诉缓存层"与 cookie 有关"
    if (!ybh_is_default_language()) {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('DONOTCACHEOBJECT')) {
            define('DONOTCACHEOBJECT', true);
        }
        if (!headers_sent()) {
            header('Vary: Cookie', false);
        }
    }
}

/** 核心/主题文案跟着当前语言走（只在前台）。 */
add_filter('locale', 'ybh_i18n_filter_locale', 20);
function ybh_i18n_filter_locale($locale)
{
    if (is_admin()) {
        return $locale;          // 后台保持站长自己的语言设置
    }
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return $locale;
    }
    return ybh_current_locale();
}

/** `<html lang="…">` */
add_filter('language_attributes', 'ybh_i18n_language_attributes', 20);
function ybh_i18n_language_attributes($output)
{
    $lang = ybh_current_language();
    if (preg_match('~lang=(["\'])[^"\']*\1~', $output)) {
        return preg_replace('~lang=(["\'])[^"\']*\1~', 'lang="' . esc_attr($lang) . '"', $output);
    }
    return trim($output . ' lang="' . esc_attr($lang) . '"');
}

/**
 * 正文容器的 `lang` 属性（T65 的文章语言）与页面语言是**两层**：
 *   · 页面语言 = 访客选的语言（本文件）；
 *   · 文章语言 = 作者标的语言（inc/ybh/post-language.php）。
 * 文章标了的以文章为准（`.entry-content` 上的 lang 会覆盖继承），没标的跟随页面语言 ✅。
 */
add_filter('ybh_post_language_fallback', 'ybh_i18n_post_language_fallback');
function ybh_i18n_post_language_fallback($fallback)
{
    return ybh_current_language();
}

/**
 * 导航菜单项的文案也跟着语言走。
 *
 * 为什么需要这一步：**菜单项标题是数据库里的内容**（外观 → 菜单），
 * 不属于主题文案，WordPress 的 `.mo` 管不到它。不处理的话，英文访客会看到
 * "英文正文 + 中文导航"（实测：政策页正文已全英文，但导航与页脚仍是中文）。
 * 做法：把菜单项标题过一遍 `ybh_t()` —— 文案表里有译文就换，没有就保持原样，
 * 所以**不改动任何数据库内容**，也不会影响没进译表的自定义菜单项。
 */
add_filter('wp_nav_menu_objects', 'ybh_i18n_nav_menu_titles', 20);
function ybh_i18n_nav_menu_titles($items)
{
    if (is_admin() || !function_exists('ybh_t') || ybh_is_default_language()) {
        return $items;
    }
    foreach ($items as $item) {
        if (!isset($item->title)) {
            continue;
        }
        $t = ybh_t((string) $item->title);
        if ($t !== $item->title) {
            $item->title = $t;
        }
    }
    return $items;
}

/** 页脚那几行文案（站点描述/版权等由主题选项输出，这里只兜住我们自己的部分） */
add_filter('ybh_footer_text', 'ybh_i18n_footer_text');
function ybh_i18n_footer_text($text)
{
    return function_exists('ybh_t') ? ybh_t((string) $text) : (string) $text;
}

/** hreflang：告诉搜索引擎同一页有哪几种语言版本 */
add_action('wp_head', 'ybh_i18n_hreflang', 3);
function ybh_i18n_hreflang()
{
    if (is_admin() || is_feed() || is_404()) {
        return;
    }
    $current_url = home_url(add_query_arg(array(), $GLOBALS['wp']->request ? '/' . $GLOBALS['wp']->request . '/' : '/'));
    // 用当前请求的原始 URL 更稳妥（保留查询串，去掉已有的 lang）
    $raw = (is_ssl() ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? wp_unslash($_SERVER['HTTP_HOST']) : '')
        . (isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/');
    $base = remove_query_arg('lang', $raw);

    foreach (ybh_languages() as $code => $info) {
        $href = ($code === ybh_default_language()) ? $base : add_query_arg('lang', $code, $base);
        echo '<link rel="alternate" hreflang="' . esc_attr($code) . '" href="' . esc_url($href) . '" />' . "\n";
    }
    echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($base) . '" />' . "\n";
}

/**
 * 某个语言的切换链接（保留当前页与查询串，只换 `lang`）。
 *
 * ⚠️ **默认语言也必须带上 `?lang=`**（站长反馈的 bug：切不回简体中文）。
 *    原因：切到英文时写下了 `ybh_lang=en` 的 cookie，而"切回中文"如果只给一个
 *    **不带参数**的地址，`ybh_i18n_boot()` 就没有机会清掉那个 cookie
 *    ⇒ 当前语言仍从 cookie 读成 `en` ⇒ 看起来"切不回中文"。
 *    带上 `?lang=zh-Hans` 后，boot 会把 cookie 置空（max-age=0），一切照旧。
 */
function ybh_language_url($code, $base_url = '')
{
    $code = (string) $code;
    if (!ybh_language_valid($code)) {
        return $base_url !== '' ? $base_url : home_url('/');
    }
    if ($base_url === '') {
        $raw = (is_ssl() ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? wp_unslash($_SERVER['HTTP_HOST']) : '')
            . (isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/');
        $base_url = remove_query_arg('lang', $raw);
    } else {
        $base_url = remove_query_arg('lang', $base_url);
    }
    return add_query_arg('lang', $code, $base_url);
}

/** 当前语言名称（给切换器用） */
function ybh_language_label($code = '')
{
    $langs = ybh_languages();
    $code = $code !== '' ? $code : ybh_current_language();
    return isset($langs[$code]['native']) ? (string) $langs[$code]['native'] : $code;
}

/**
 * 语言切换器（页脚 + 短代码 `[ybh_lang_switch]` 都能用）。
 *
 * 做成一组链接而不是下拉框：没有 JS 也能用、pjax 换页天然正常、
 * 搜索引擎也能顺着 `hreflang` 找到各语言版本。
 */
add_shortcode('ybh_lang_switch', 'ybh_lang_switch_html');
function ybh_lang_switch_html($atts = array())
{
    $atts = shortcode_atts(array('class' => ''), $atts, 'ybh_lang_switch');
    $cur = ybh_current_language();
    $out = '<nav class="ybh-lang' . ($atts['class'] !== '' ? ' ' . esc_attr($atts['class']) : '') . '"'
        . ' aria-label="' . esc_attr('语言 / Language') . '">';
    foreach (ybh_languages() as $code => $info) {
        $is = ($code === $cur);
        $out .= '<a class="ybh-lang__item' . ($is ? ' is-active' : '') . '"'
            . ' href="' . esc_url(ybh_language_url($code)) . '"'
            . ' lang="' . esc_attr($code) . '"'
            . ($is ? ' aria-current="true"' : '')
            . ' hreflang="' . esc_attr($code) . '">'
            . '<i class="fa-solid fa-language" aria-hidden="true"></i>'
            . esc_html(isset($info['native']) ? $info['native'] : $code)
            . '</a>';
    }
    $out .= '</nav>';
    return $out;
}

/**
 * 顶部导航里的语言**胶囊图标**（放在 `.nav-search-wrapper` 里，与搜索/随机并列）。
 *
 * 站长反馈两点：① 页脚那个切换器会被**底部吸底条挡住**；② 塞进菜单列表的写法
 * **与其他元素对不齐**（菜单项有自己的行高/间距，而那排图标是 33×33 的圆钮）。
 * 所以这里只输出一个**同款圆钮**，样式与 `.searchbox i` / `.bg-switch i` 完全一致；
 * 语言列表是页脚里的浮层（见 `ybh_lang_popover()`）—— 胶囊容器是 overflow:hidden，
 * 浮层放里面会被裁掉。
 */
function ybh_lang_pill_button()
{
    if (is_admin() || !function_exists('ybh_languages')) {
        return '';
    }
    if (!apply_filters('ybh_lang_switch_in_nav', true)) {
        return '';
    }
    $cur = ybh_current_language();
    return '<button type="button" class="ybh-lang-pill" id="ybh-lang-pill-btn"'
        . ' aria-haspopup="true" aria-expanded="false" aria-controls="ybh-lang-pop"'
        . ' title="' . esc_attr(ybh_t('语言')) . '：' . esc_attr(ybh_language_label($cur)) . '">'
        . '<i class="fa-solid fa-language" aria-hidden="true"></i>'
        // 「文A」这个图标本身就是"语言"的通行符号；无障碍文案另给
        . '<span class="screen-reader-text">' . esc_html(ybh_t('语言')) . '</span>'
        . '</button>';
}

/**
 * 语言浮层（页脚输出，`position: fixed`，不受导航胶囊的 overflow 裁切）。
 * 链接一律带 `data-ybh-lang` + `data-no-pjax`：**pjax 只替换 #page**，
 * 顶部菜单不在替换范围内 —— 不整页刷新就会出现"页面内容换了、菜单没换"（站长反馈的 bug）。
 */
function ybh_lang_popover()
{
    if (is_admin() || is_feed() || is_robots()) {
        return;
    }
    $cur = ybh_current_language();
    echo '<div class="ybh-lang-pop" id="ybh-lang-pop" hidden role="dialog" aria-modal="false"'
        . ' aria-label="' . esc_attr(ybh_t('语言')) . '">';
    echo '<p class="ybh-lang-pop__title"><i class="fa-solid fa-language" aria-hidden="true"></i>'
        . esc_html(ybh_t('语言')) . '</p><ul class="ybh-lang-pop__list">';
    foreach (ybh_languages() as $code => $info) {
        $is = ($code === $cur);
        echo '<li><a href="' . esc_url(ybh_language_url($code)) . '"'
            . ' data-ybh-lang="' . esc_attr($code) . '"'
            . ' data-no-pjax'
            . ' lang="' . esc_attr($code) . '" hreflang="' . esc_attr($code) . '"'
            . ($is ? ' aria-current="true"' : '') . '>'
            . '<span class="ybh-lang-pop__native">' . esc_html(isset($info['native']) ? $info['native'] : $code) . '</span>'
            . '<span class="ybh-lang-pop__code">' . esc_html($code) . '</span>'
            . ($is ? '<i class="fa-solid fa-check ybh-lang-pop__tick" aria-hidden="true"></i>' : '')
            . '</a></li>';
    }
    echo '</ul></div>';
}
add_action('wp_footer', 'ybh_lang_popover', 97);

/* 脚本：浮层开关 + 给语言链接打 `data-no-pjax`（pjax 只换 #page，顶部菜单不在其内） */
add_action('wp_enqueue_scripts', 'ybh_i18n_enqueue', 20);
function ybh_i18n_enqueue()
{
    if (is_admin()) {
        return;
    }
    wp_enqueue_script(
        'ybh-lang',
        get_template_directory_uri() . '/js/ybh-lang.js',
        array(),
        defined('YBH_VERSION') ? YBH_VERSION : null,
        true
    );
    /*
     * 语言链接改写（T66i）：只有**非默认语言**才需要 ——
     * 默认语言的页面要继续吃页面缓存，不能给站内链接都加上 `?lang=`。
     */
    if (!ybh_is_default_language()) {
        wp_enqueue_script(
            'ybh-lang-links',
            get_template_directory_uri() . '/js/ybh-lang-links.js',
            array(),
            defined('YBH_VERSION') ? YBH_VERSION : null,
            true
        );
        wp_localize_script('ybh-lang-links', 'YBH_CURRENT_LANG', ybh_current_language());
        // T68：把默认语言也下发，别让 JS 硬编码猜（此前靠一个不存在的 body class 判断）
        wp_localize_script('ybh-lang-links', 'YBH_DEFAULT_LANG', ybh_default_language());
    }
}

/**
 * 把"带非默认语言 cookie、但 URL 上没有 `?lang=`"的访问**重定向到带参数的地址**。
 *
 * 为什么必须重定向（站长反馈的真因）：
 *   本站用 Cache Enabler，缓存在 `advanced-cache.php` 阶段就已输出，**主题代码还没跑**，
 *   所以 `DONOTCACHEPAGE` 拦不住它 —— 实测带 `ybh_lang=en` 的 cookie 请求 `/changelog/`
 *   拿到的是 `X-YBH-Cache: HIT` 的**中文**页面。重定向之后，每种语言都有自己的 URL，
 *   与页面缓存天然兼容（非默认语言的 URL 本来就不缓存）。
 */
add_action('template_redirect', 'ybh_i18n_redirect_to_lang_url', 0);
function ybh_i18n_redirect_to_lang_url()
{
    if (is_admin() || is_robots() || is_feed() || is_404()) {
        return;
    }
    if (isset($_GET['lang'])) {
        return;   // URL 上已经带了，不必重定向
    }
    if (empty($_COOKIE[YBH_LANG_COOKIE])) {
        return;   // 没有语言 cookie = 默认语言，保持"无参数"以继续吃缓存
    }
    $cookie_lang = sanitize_text_field(wp_unslash((string) $_COOKIE[YBH_LANG_COOKIE]));
    if (!ybh_language_valid($cookie_lang) || $cookie_lang === ybh_default_language()) {
        return;
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return;   // 只处理 GET，别把表单提交也重定向掉
    }
    $url = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '')
        . ($_SERVER['REQUEST_URI'] ?? '/');
    wp_safe_redirect(add_query_arg('lang', $cookie_lang, $url), 302);
    exit;
}

/**
 * 移动抽屉里的那一项语言菜单（这里的对齐是正常的：抽屉本来就是竖排列表）。
 * 桌面导航**不再**注入 —— 改由 `ybh_lang_pill_button()` 输出同款圆钮。
 */
add_filter('wp_nav_menu_items', 'ybh_lang_nav_menu_item', 20, 2);
function ybh_lang_nav_menu_item($items, $args = null)
{
    if (is_admin() || !function_exists('ybh_languages')) {
        return $items;
    }
    $loc = is_object($args) && isset($args->theme_location) ? (string) $args->theme_location : '';
    $cls = is_object($args) && isset($args->container_class) ? (string) $args->container_class : '';
    // 只注入移动抽屉（header.php 里 container_class = mo_nav_item），桌面交给胶囊图标
    if ($loc !== 'primary' || $cls !== 'mo_nav_item') {
        return $items;
    }
    if (!apply_filters('ybh_lang_switch_in_nav', true)) {
        return $items;
    }

    $cur = ybh_current_language();
    $sub = '';
    foreach (ybh_languages() as $code => $info) {
        $is = ($code === $cur);
        $sub .= '<li class="menu-item' . ($is ? ' ybh-lang-menu__current' : '') . '">'
            . '<a href="' . esc_url(ybh_language_url($code)) . '"'
            . ' data-ybh-lang="' . esc_attr($code) . '" data-no-pjax'
            . ' lang="' . esc_attr($code) . '" hreflang="' . esc_attr($code) . '"'
            . ($is ? ' aria-current="true"' : '') . '>'
            . esc_html(isset($info['native']) ? $info['native'] : $code)
            . ($is ? ' ✓' : '')
            . '</a></li>';
    }

    $item = '<li class="menu-item menu-item-has-children ybh-lang-menu">'
        . '<a href="#" role="button" aria-haspopup="true" aria-expanded="false"'
        . ' title="' . esc_attr('语言 / Language') . '">'
        . '<i class="fa-solid fa-language" aria-hidden="true"></i>'
        . '<span class="ybh-lang-menu__label">' . esc_html('语言') . '</span>'
        . '</a>'
        . '<ul class="sub-menu ybh-lang-menu__list">' . $sub . '</ul>'
        . '</li>';

    return $items . $item;
}

/**
 * 「翻译工作室」进主导航 —— T68b 改为挂在「YBH」下拉子菜单里（站长要求），
 * 不再是顶级项。找到标题为 YBH（slug `关于-ybh`）的顶级菜单项，把工作室
 * 追加为它的最后一个子项；找不到就退回顶级追加。
 *
 * 桌面与移动端共用同一个 `primary` 菜单，所以注入一次两端都有。
 * 游客也能看（工作室对游客是只读的）；不想要就 `add_filter('ybh_studio_in_nav', '__return_false')`。
 */
add_filter('wp_nav_menu_objects', 'ybh_studio_nav_submenu_item', 30, 2);
function ybh_studio_nav_submenu_item($items, $args = null)
{
    if (is_admin() || !function_exists('ybh_studio_url')) {
        return $items;
    }
    $loc = is_object($args) && isset($args->theme_location) ? (string) $args->theme_location : '';
    if ($loc !== '' && $loc !== 'primary') {
        return $items;
    }
    if (!apply_filters('ybh_studio_in_nav', true)) {
        return $items;
    }
    foreach ((array) $items as $it) {
        if (!empty($it->ybh_is_studio)) {
            return $items;                     // 已注入过（幂等）
        }
    }
    $label = function_exists('ybh_t') ? ybh_t('翻译工作室') : '翻译工作室';

    $new = new stdClass();
    $new->ID = 0;
    $new->menu_item_parent = 0;                // 找不到 YBH 项就放顶级
    $new->title = $label;
    $new->url = ybh_studio_url();
    $new->type = 'custom';
    $new->object = 'custom';
    $new->db_id = 0;
    $new->classes = array('ybh-studio-link');
    $new->menu_order = PHP_INT_MAX;
    $new->current = false;
    $new->current_item_ancestor = false;
    $new->current_item_parent = false;
    $new->ybh_is_studio = true;
    // 找「YBH」顶级项（标题或 slug 命中），把工作室挂为它的子项
    foreach ((array) $items as $it) {
        $slug_ok = strtolower((string) $it->post_name) === strtolower(rawurlencode('关于-ybh'));
        if ((int) $it->menu_item_parent === 0
            && ((string) $it->title === 'YBH' || $slug_ok)) {
            $new->menu_item_parent = (int) $it->ID;
            break;
        }
    }
    // 排在 YBH 子菜单的最后：子项链结束处插入（wp_nav_menu_objects 按数组序渲染）
    if ($new->menu_item_parent) {
        $out = array();
        $pending = false;
        foreach ((array) $items as $it) {
            $out[] = $it;
            if ((int) $it->ID === (int) $new->menu_item_parent) {
                $pending = true;               // 父项之后跟着它的子项
                continue;
            }
            if ($pending && (int) $it->menu_item_parent !== (int) $new->menu_item_parent) {
                array_pop($out);               // 子项链结束：插在最后一条子项之后
                $out[] = $new;
                $out[] = $it;
                $pending = false;
            }
        }
        if ($pending) { $out[] = $new; }       // 子项链一直排到末尾
        return $out;
    }
    $items[] = $new;                           // 顶级兜底：放最后
    return $items;
}

/**
 * 页脚那一条**默认不再输出**了（站长要求搬到顶部导航；它原来会被底部吸底条挡住）。
 * 仍然保留短代码 `[ybh_lang_switch]`，想在哪一页放就在正文里贴；
 * 确实想恢复页脚版，加一句 `add_filter('ybh_lang_switch_in_footer', '__return_true');`。
 */
add_action('wp_footer', 'ybh_lang_switch_footer', 98);
function ybh_lang_switch_footer()
{
    if (is_admin() || is_feed() || is_robots()) {
        return;
    }
    if (!apply_filters('ybh_lang_switch_in_footer', false)) {
        return;
    }
    echo '<div class="ybh-lang-foot">' . ybh_lang_switch_html() . '</div>';
}
