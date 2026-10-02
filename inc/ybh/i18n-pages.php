<?php
/**
 * YBH · 固定页面的多语言（T66 · L2）
 *
 * ===================================================================
 * 为什么用「一个页面 + 译文 meta」而不是「每种语言建一个页面」
 * ===================================================================
 *   隐私政策 / Cookie 政策 / 用户协议这类**固定文本**，正文是存在数据库里的
 *   （不像 Web 应用教程页那样由短代码现场生成）。
 *   两种做法：
 *     A. 每种语言建一个页面（`privacy-policy-en`…）+ 映射表；
 *     B. 一个页面，译文存在 meta 里，前台按当前语言替换。
 *   选 B 的理由：
 *     · 不会出现"中文改了、译文页面忘了改"的漂移；一处维护；
 *     · 首页/页脚/政策链接都指的同一个 slug，不必维护映射表；
 *     · 非默认语言**本来就不走页面缓存**（见 i18n.php），所以"同一 URL 不同语言"
 *       没有缓存风险；`?lang=` 的 hreflang 也照常工作。
 *
 *   译者怎么填：编辑页面时下方会多一个「多语言译文」框，每个语言一行标题 + 一个正文框。
 *   正文框里写 HTML 即可（与正文编辑器一致）；**留空 = 该语言回退中文**。
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

/** 译文 meta 键 */
function ybh_i18n_meta_key($lang, $part = 'content')
{
    $lang = sanitize_key((string) $lang);
    if ($part === 'title') { return '_ybh_i18n_' . $lang . '_title'; }
    if ($part === 'translator') { return '_ybh_i18n_' . $lang . '_translator'; }
    return '_ybh_i18n_' . $lang;
}

/**
 * 当前请求是否"明确要求看某语言的**文章**译文"。
 *
 * 站长要求：**文章默认显示原文**，只在读者自己点了切换按钮后才显示译文。
 * 所以文章不看站点语言（`?lang=`），只看这个**文章级的**参数：
 *   `?postlang=en`  → 这篇按英文译文显示
 *   `?postlang=orig`→ 强制回原文（按钮用）
 * 页面（隐私政策那类）不适用此规则：它们仍然跟随站点语言，
 * 因为"政策跟着站点语言走"是读者预期的。
 */
function ybh_i18n_post_display_lang($post_id = 0)
{
    $post_id = $post_id ? (int) $post_id : (int) get_the_ID();
    if ($post_id <= 0 || get_post_type($post_id) !== 'post') {
        return '';
    }
    $req = isset($_GET['postlang']) ? sanitize_text_field(wp_unslash((string) $_GET['postlang'])) : '';
    if ($req === 'orig' || $req === '') {
        return '';   // 默认原文
    }
    if (ybh_language_valid($req) && ybh_i18n_page_translation($post_id, $req, 'content') !== '') {
        return $req;
    }
    return '';
}

/** 这篇文章有哪些语言的译文（用于切换按钮）；源语言本身不算（T68） */
function ybh_i18n_post_available($post_id)
{
    $post_id = (int) $post_id;
    $out = array();
    if ($post_id <= 0) { return $out; }
    $orig = ybh_i18n_post_orig_lang($post_id);
    foreach (ybh_languages() as $code => $info) {
        if ($code === $orig) { continue; }
        $c = ybh_i18n_page_translation($post_id, $code, 'content');
        if ($c !== '') {
            $out[$code] = array(
                'title' => ybh_i18n_page_translation($post_id, $code, 'title'),
                'translator' => (string) get_post_meta($post_id, ybh_i18n_meta_key($code, 'translator'), true),
            );
        }
    }
    return $out;
}

/** 文章原文语言（T65 的 `_ybh_lang`，T68 起语义 = **源语言**；没标就是站点默认） */
function ybh_i18n_post_orig_lang($post_id)
{
    $v = (string) get_post_meta((int) $post_id, '_ybh_lang', true);
    if ($v !== '' && function_exists('ybh_post_language_valid') && ybh_post_language_valid($v)) {
        return $v;                      // 含 zh-HK/ko 等 T65 旧值：文章确实是这个语言写的
    }
    return ybh_language_valid($v) ? $v : ybh_default_language();
}

/**
 * 取某篇内容某语言的译文。
 *
 * T68：守卫从"默认语言不能有译文"改为"**源语言**不能有译文"——
 * 简体中文源的文章照旧没有简中译文；英文源的文章从此**可以**配中文译文。
 * 页面一般不标源语言 → 源语言 = 默认语言，行为与从前完全一致。
 *
 * @return string '' 表示没有译文（调用方应回退原文）
 */
function ybh_i18n_page_translation($post_id, $lang, $part = 'content')
{
    $post_id = (int) $post_id;
    if ($post_id <= 0 || !ybh_language_valid($lang)) {
        return '';
    }
    if ($lang === ybh_i18n_post_orig_lang($post_id)) {
        return '';
    }
    $val = get_post_meta($post_id, ybh_i18n_meta_key($lang, $part), true);
    if (is_string($val) && trim($val) !== '') {
        return trim($val);
    }
    /*
     * T69 · 机器繁化回退（优先级最低）：
     *   目标语言是 zh-Hant、且**没有**任何人工/自动译文时，把原文做一次
     *   HTML 安全的简→繁转换直接交付 —— 站长要求"文章页繁体不必专门翻译"。
     *   它排在最后：一旦有人提交并批准了真正的译文，就自动接管（这个函数先返回 meta）。
     *   转换结果不写库（原文零改动），也支持 `ybh_zh_hant_auto_post` 过滤器关停。
     */
    if ($lang === 'zh-Hant' && function_exists('ybh_zh_hant_auto_post')) {
        $src = (string) get_post_field('post_content', $post_id);
        if ($part === 'title') {
            $src = (string) get_the_title($post_id);
        }
        $auto = ybh_zh_hant_auto_post($src, $lang, $part);
        if ($auto !== '') {
            return $auto;
        }
    }
    return '';
}

/* ---------------------------------------------------------------------------
 * 前台替换
 *   · 页面（privacy-policy 那类）：跟随**站点语言**（读者预期如此）
 *   · 文章（post）：**默认原文**，只有读者点了切换（`?postlang=xx`）才显示译文
 * ------------------------------------------------------------------------- */
add_filter('the_content', function ($content) {
    if (is_admin() || is_feed() || !function_exists('ybh_is_default_language')) {
        return $content;
    }
    $pid = (int) get_the_ID();
    if ($pid <= 0) {
        return $content;
    }
    if (get_post_type($pid) === 'post') {
        // 文章：默认原文；明确切了才换
        $lang = ybh_i18n_post_display_lang($pid);
    } else {
        // 页面：跟随站点语言
        if (ybh_is_default_language()) {
            return $content;
        }
        $lang = ybh_current_language();
    }
    if ($lang === '') {
        return $content;
    }
    /*
     * 有效译文 = 人工批准优先；没有批准时按站长定的规则**自动采用**（票高者胜，票同取更晚）。
     * 见 `ybh_i18n_effective_post_value()`。
     */
    $eff = function_exists('ybh_i18n_effective_post_value')
        ? ybh_i18n_effective_post_value($pid, $lang, 'content')
        : array('text' => ybh_i18n_page_translation($pid, $lang, 'content'), 'source' => 'approved');
    $tr = (string) ($eff['text'] ?? '');
    if ($tr === '') {
        return $content;
    }
    // 译文之后补一条"当前显示的是译文 + 译者"的说明条（只加在正文开头一次）
    if (get_post_type($pid) === 'post' && is_singular('post') && in_the_loop() && is_main_query()) {
        $tr = ybh_i18n_langbar($pid, $lang, true, $eff) . $tr;
    }
    return $tr;
}, 5);

/** 文章页的语言条（原文/译文切换 + 译者 + 帮助本地化） */
add_filter('the_content', function ($content) {
    if (is_admin() || is_feed()) {
        return $content;
    }
    if (!is_singular('post') || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    $pid = (int) get_the_ID();
    if ($pid <= 0 || ybh_i18n_post_display_lang($pid) !== '') {
        return $content;   // 已在译文分支里加过
    }
    return ybh_i18n_langbar($pid, '', false) . $content;
}, 6);

/**
 * 渲染语言条。
 *
 * @param int    $pid      文章 ID
 * @param string $cur      当前显示的语言（'' = 原文）
 * @param bool   $is_trans 当前显示的是译文
 */
function ybh_i18n_langbar($pid, $cur = '', $is_trans = false, $eff = array())
{
    $orig_lang = ybh_i18n_post_orig_lang($pid);
    $avail = ybh_i18n_post_available($pid);
    $base = get_permalink($pid);
    /*
     * 「帮助本地化」指向**主站内的翻译工作室**（原先是 i18n.yibianhui.cn 那个静态站，
     * 已停用）。带上文章标题作为搜索词，落地就能看到这篇的卡片。
     */
    $studio = function_exists('ybh_studio_url')
        ? ybh_studio_url(array('tab' => 'articles', 'q' => get_the_title($pid)))
        : home_url('/i18n/');

    $out = '<div class="ybh-article-lang" data-post-id="' . (int) $pid . '">';
    $out .= '<div class="ybh-article-lang__row">';
    $out .= '<span class="ybh-article-lang__label">'
        . '<i class="fa-solid fa-language" aria-hidden="true"></i>'
        . esc_html(ybh_t('语言')) . '</span>';

    // 原文按钮
    $is_orig = ($cur === '');
    $out .= '<a class="ybh-article-lang__btn' . ($is_orig ? ' is-active' : '') . '"'
        . ' data-no-pjax href="' . esc_url(add_query_arg('postlang', 'orig', $base)) . '"'
        . ($is_orig ? ' aria-current="true"' : '') . '>'
        . esc_html(ybh_t('原文')) . '（' . esc_html(ybh_language_label($orig_lang)) . '）</a>';

    // 已有译文
    foreach ($avail as $code => $info) {
        $is = ($cur === $code);
        $out .= '<a class="ybh-article-lang__btn' . ($is ? ' is-active' : '') . '"'
            . ' data-no-pjax href="' . esc_url(add_query_arg('postlang', $code, $base)) . '"'
            . ' lang="' . esc_attr($code) . '"'
            . ($is ? ' aria-current="true"' : '') . '>'
            . esc_html(ybh_language_label($code)) . '</a>';
    }

    // 还没有译文：给出"帮助本地化"（T68：排除源语言而不是默认语言——
    // 英文源的文章，"缺译"清单里应该出现简体中文）
    $missing = array();
    $langs = ybh_languages();
    foreach ($langs as $code => $info) {
        if (isset($avail[$code]) || $code === $orig_lang) { continue; }
        $missing[] = $code;
    }
    if ($missing) {
        // 目标语言 = 第一个还没有译文的语言；工作室里直接筛到这篇文章
        $target_lang = $missing[0];
        // T69：工作室目标语言用私有参数 `tl`，避免把整站界面语言一起切走
        $help_url = add_query_arg(
            array('tl' => $target_lang, 'tab' => 'articles', 'q' => get_the_title($pid)),
            $studio
        );
        $out .= '<a class="ybh-article-lang__btn ybh-article-lang__btn--help"'
            . ' data-no-pjax'
            . ' href="' . esc_url($help_url) . '"'
            . ' title="' . esc_attr(ybh_t('在翻译工作台里帮这篇做本地化')) . '">'
            . '<i class="fa-solid fa-hands-helping" aria-hidden="true"></i>'
            . esc_html(ybh_t('帮助本地化')) . '</a>';
    }
    $out .= '</div>';

    // 译文时给出译者署名（自动采用的会注明"票数最高，自动采用"）
    if ($is_trans) {
        $who = (string) get_post_meta((int) $pid, ybh_i18n_meta_key($cur, 'translator'), true);
        $auto = (($eff['source'] ?? '') === 'auto');
        if ($auto && empty($eff['author']) === false && $who === '') {
            $who = (string) $eff['author'];
        }
        $out .= '<p class="ybh-article-lang__credit">'
            . '<i class="fa-solid fa-pen-nib" aria-hidden="true"></i>'
            . esc_html(ybh_sprintf('本页为%s译文', ybh_language_label($cur)))
            . ($who !== '' ? '·' . esc_html(ybh_sprintf('译者：%s', $who)) : '·' . esc_html(ybh_t('译者未署名')))
            . ($auto ? '·' . esc_html(ybh_t('票数最高，自动采用')) : '')
            . '</p>';
    }
    $out .= '</div>';
    return $out;
}

add_filter('the_title', function ($title, $post_id = 0) {
    if (is_admin() || is_feed() || !function_exists('ybh_is_default_language')) {
        return $title;
    }
    if (!is_singular()) {
        return $title;
    }
    // 只换"主循环里那篇"的标题：菜单项、小工具里的标题不能跟着变
    if (!in_the_loop() || (int) $post_id !== (int) get_queried_object_id()) {
        return $title;
    }
    // 文章默认原文（要读者点了切换才换标题）；页面跟随站点语言
    if (get_post_type((int) $post_id) === 'post') {
        $lang = ybh_i18n_post_display_lang((int) $post_id);
    } else {
        $lang = ybh_is_default_language() ? '' : ybh_current_language();
    }
    if ($lang === '') {
        return $title;
    }
    if (function_exists('ybh_i18n_effective_post_value')) {
        $eff = ybh_i18n_effective_post_value((int) $post_id, $lang, 'title');
        if (!empty($eff['text'])) {
            return (string) $eff['text'];
        }
    }
    $tr = ybh_i18n_page_translation((int) $post_id, $lang, 'title');
    return ($tr !== '') ? $tr : $title;
}, 10, 2);

/** 文档标题（浏览器标签）也跟着换 —— T68：文章看 `?postlang=`，页面看站点语言 */
add_filter('document_title_parts', function ($parts) {
    if (is_admin() || !is_singular() || !function_exists('ybh_is_default_language')) {
        return $parts;
    }
    $pid = (int) get_queried_object_id();
    if (!$pid) {
        return $parts;
    }
    if (get_post_type($pid) === 'post') {
        $lang = ybh_i18n_post_display_lang($pid);
    } else {
        $lang = ybh_is_default_language() ? '' : ybh_current_language();
    }
    $tr = $lang !== '' ? ybh_i18n_page_translation($pid, $lang, 'title') : '';
    if ($tr !== '') {
        $parts['title'] = $tr;
    }
    return $parts;
});

/*
 * `the_content` 能换掉正文，但**主题自己的 SEO 描述**是直接读 `$post->post_content`
 * 拼出来的（`iro_get_description()`，没有过滤器可挂）—— 不处理的话，英文页面的
 * `<meta name="description">` 里仍是中文原文（实测抓到过）。
 * 做法：在 `wp_head` 里开一段缓冲、在本段末尾收掉，把这**一条 meta** 换成译文的摘要。
 * 只在"非默认语言 + 有译文 + 单篇/页面"时启用，缓冲区只覆盖 wp_head 这一段。
 */
add_action('wp_head', function () {
    if (is_admin() || !is_singular() || !function_exists('ybh_is_default_language')) {
        return;
    }
    $pid = (int) get_queried_object_id();
    if (!$pid) {
        return;
    }
    // 与正文同口径：文章看 `?postlang=`，页面看站点语言
    if (get_post_type($pid) === 'post') {
        $lang = ybh_i18n_post_display_lang($pid);
    } else {
        $lang = ybh_is_default_language() ? '' : ybh_current_language();
    }
    $tr = $lang !== '' ? ybh_i18n_page_translation($pid, $lang, 'content') : '';
    if ($tr === '') {
        return;
    }
    $plain = trim(mb_strimwidth(preg_replace('/\s+/', ' ', strip_tags(strip_shortcodes($tr))), 0, 240, '…'));
    if ($plain === '') {
        return;
    }
    ob_start();
    add_action('wp_head', function () use ($plain) {
        $html = ob_get_clean();
        echo preg_replace_callback(
            '~<meta name="description" content="[^"]*">~u',
            function () use ($plain) {
                return '<meta name="description" content="' . esc_attr($plain) . '">';
            },
            (string) $html
        );
    }, 999);
}, 0);

/* ---------------------------------------------------------------------------
 * 后台编辑框
 * ------------------------------------------------------------------------- */
add_action('add_meta_boxes', function () {
    if (!function_exists('ybh_languages') || !current_user_can('edit_posts')) {
        return;
    }
    // T68：框标题按注册表动态生成（以前写死"英文 / 日文"，加语言就漏）
    $names = array();
    foreach (ybh_languages() as $code => $info) {
        if (!empty($info['default'])) { continue; }
        $names[] = (string) $info['native'];
    }
    add_meta_box('ybh_i18n_pages', '多语言译文（' . implode(' / ', $names) . '）', 'ybh_i18n_meta_box', array('page', 'post'), 'normal', 'default');
});

function ybh_i18n_meta_box($post)
{
    $langs = ybh_languages();
    // T68：跳过**这篇文章的源语言**（页面不标源语言 → 默认语言，与从前一致；
    // 英文源的文章从此能看到"简体中文"一行）
    $orig = function_exists('ybh_i18n_post_orig_lang') ? ybh_i18n_post_orig_lang($post->ID) : ybh_default_language();
    wp_nonce_field('ybh_i18n_pages_' . $post->ID, 'ybh_i18n_nonce');
    echo '<p style="margin:6px 0 12px;color:#646970;">留空表示该语言回退原文；正文框里可以直接写 HTML。'
        . '文章在前台默认显示原文，读者点语言条或选了对应语言（<code>?lang=xx</code> / <code>?postlang=xx</code>）时使用这些译文。</p>';
    foreach ($langs as $code => $info) {
        if ($code === $orig) {
            continue;
        }
        $title = (string) get_post_meta($post->ID, ybh_i18n_meta_key($code, 'title'), true);
        $body  = (string) get_post_meta($post->ID, ybh_i18n_meta_key($code, 'content'), true);
        $who   = (string) get_post_meta($post->ID, ybh_i18n_meta_key($code, 'translator'), true);
        ?>
        <div style="margin:0 0 14px;padding:10px 12px;border:1px solid #dcdcde;border-radius:6px;background:#fbfbfc;">
            <p style="margin:0 0 6px;font-weight:600;">
                <?php echo esc_html($info['native']); ?> <span style="font-weight:400;color:#646970;">（<?php echo esc_html($code); ?>）</span>
            </p>
            <p style="margin:0 0 6px;">
                <label style="display:block;font-size:12px;color:#646970;">标题</label>
                <input type="text" name="ybh_i18n[<?php echo esc_attr($code); ?>][title]"
                       value="<?php echo esc_attr($title); ?>" style="width:100%;" />
            </p>
            <p style="margin:0 0 6px;">
                <label style="display:block;font-size:12px;color:#646970;">译者署名（显示在文章页语言条上；留空显示"译者未署名"）</label>
                <input type="text" name="ybh_i18n[<?php echo esc_attr($code); ?>][translator]"
                       value="<?php echo esc_attr($who); ?>" style="width:100%;" />
            </p>
            <label style="display:block;font-size:12px;color:#646970;">正文（HTML 可）</label>
            <textarea name="ybh_i18n[<?php echo esc_attr($code); ?>][content]" rows="8" style="width:100%;font-family:ui-monospace,Consolas,monospace;font-size:12px;"><?php
                echo esc_textarea($body);
            ?></textarea>
        </div>
        <?php
    }
}

add_action('save_post', function ($post_id, $post) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (empty($_POST['ybh_i18n_nonce']) || !wp_verify_nonce((string) $_POST['ybh_i18n_nonce'], 'ybh_i18n_pages_' . $post_id)) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    $in = isset($_POST['ybh_i18n']) && is_array($_POST['ybh_i18n']) ? wp_unslash($_POST['ybh_i18n']) : array();
    // T68：与编辑框同口径——跳过这篇文章的源语言（页面 = 默认语言，行为不变）
    $orig = function_exists('ybh_i18n_post_orig_lang') ? ybh_i18n_post_orig_lang($post_id) : ybh_default_language();
    foreach (ybh_languages() as $code => $info) {
        if ($code === $orig) {
            continue;
        }
        $t = isset($in[$code]['title']) ? sanitize_text_field((string) $in[$code]['title']) : '';
        $w = isset($in[$code]['translator']) ? sanitize_text_field((string) $in[$code]['translator']) : '';
        $c = isset($in[$code]['content']) ? (string) $in[$code]['content'] : '';
        // 正文按页面内容同等级别处理：管理员有 unfiltered_html，其他人过 KSES
        $c = current_user_can('unfiltered_html') ? $c : wp_kses_post($c);
        foreach (array('title' => $t, 'translator' => $w, 'content' => $c) as $part => $val) {
            $key = ybh_i18n_meta_key($code, $part);
            if (trim($val) === '') {
                delete_post_meta($post_id, $key);
            } else {
                update_post_meta($post_id, $key, $val);
            }
        }
    }
}, 25, 2);
