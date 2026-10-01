<?php
/**
 * YBH · 文章源语言（T65 字形 + T68 翻译源语言）
 *
 * ===================================================================
 * 为什么需要
 * ===================================================================
 *   同一段汉字，在简体 / 繁体 / 日文下的**正确字形并不相同**（例如「直」「骨」
 *   「门」「发」的写法差异）。本站 `<html lang="zh-Hans">`，于是整站都按简体字形渲染。
 *   但站上确实有日文与繁体内容（签名栏就是日文句子、有作者写繁体），
 *   这些文字应当按自己的语言显示字形。
 *
 *   主题 CSS 早就写好了按语言切换的规则（`css/ybh.css` 的 `:lang(zh-Hant)` 一组），
 *   缺的是**告诉页面"这篇文章是什么语言"**，以及**在编辑器里选它**。
 *   本模块补的就是这两件事：
 *     · 文章级 `post_meta`：`_ybh_lang`
 *     · 编辑处：前台写作页与后台「发布」框各有一个语言选择
 *     · 输出：文章页的 `.entry-content` 带上 `lang="…"`，让 `:lang()` 规则命中
 *
 * ===================================================================
 * T68 语义升级：这个字段现在是"**源语言**"
 * ===================================================================
 *   以前它只管字形；翻译系统（i18n-pages.php）把它借用为语言条上的"原文语言"标签，
 *   但整条翻译链路仍硬编码"中文是源语言"——英文原文的文章配不了中文译文。
 *   现在：
 *     · 白名单与**站点语言注册表**（`ybh_languages()`）对齐，外加 T65 的旧值
 *       zh-HK / ko（已有文章可能标了它们）；
 *     · "源语言"决定翻译方向：语言条把源语言标为"原文"，其余语言（含简体中文，
 *       当源语言不是它时）都可以有译文；
 *     · 字形用途照旧（拉丁字母没有字形分歧，标 en/fr/ru/es 不产生额外字体成本）。
 *
 * ===================================================================
 * ⚠️ 字体成本（这是本项最容易被忽略的地方）
 * ===================================================================
 *   `:lang(zh-Hant)` 原来首选 `'Sarasa UI TC'`，而**那一族是未切片的整包字体**
 *   （线上实测 7.68 MB；J/K/HC 类似 8.4–8.5 MB）。也就是说：一旦某篇文章标成
 *   繁体，读者就要多下 7.7 MB —— 把首屏字体优化（19.2 MB → 3.9 MB）整个吃掉。
 *
 *   所以配套改了 CSS：**非简体语言优先用系统地区字体**（macOS/iOS 的 PingFang
 *   TC/HK、Hiragino，Windows 的微软正黑体、Yu Gothic，Android 的 Noto Sans CJK
 *   会按 lang 自动选地区变体），把未切片的 Sarasa 那几族**从字体栈里去掉**。
 *   结果是：字形正确、**零额外字节**；哪天真想要更纱的地区版本，按
 *   `docs/字体子集化与预加载.md` 的流程切子集后再加回字体栈即可。
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

/** meta 键（下划线前缀 = 不在自定义字段面板里露出） */
if (!defined('YBH_POST_LANG_META')) {
    define('YBH_POST_LANG_META', '_ybh_lang');
}

/**
 * 可选语言（= 这篇文章的**源语言**）。`''` = 默认按简体中文处理。
 *
 * T68：与站点语言注册表（`ybh_languages()`）对齐 —— 翻译系统按"源语言"决定
 * 译文方向，两边必须共用同一套语言清单；zh-HK / ko 是 T65 的旧值，保留兼容。
 * 值是**标准 BCP-47 语言标签**，直接进 `lang` 属性 —— CSS 的 `:lang()` 按它匹配，
 * 屏幕阅读器也按它选发音。
 */
function ybh_post_language_options()
{
    $options = array('' => '默认（简体中文）');
    if (function_exists('ybh_languages')) {
        foreach (ybh_languages() as $code => $info) {
            $options[$code] = (string) $info['native'];
        }
    }
    $options += array(
        'zh-HK' => '繁體中文（香港）',
        'ko'    => '한국어',
    );

    /**
     * 过滤：可选语言清单。
     *
     * @param array $options 语言标签 => 名称
     */
    return apply_filters('ybh_post_language_options', $options);
}

/** 值是否合法（白名单） */
function ybh_post_language_valid($value)
{
    $value = (string) $value;
    return array_key_exists($value, ybh_post_language_options());
}

/**
 * 取文章源语言（未设置或非法 → 空串，表示"按简体中文处理"）。
 *
 * @param int|null $post_id 默认当前文章。
 * @return string
 */
function ybh_post_language($post_id = null)
{
    $post_id = $post_id ? (int) $post_id : (int) get_the_ID();
    if ($post_id <= 0) {
        return '';
    }
    $v = (string) get_post_meta($post_id, YBH_POST_LANG_META, true);
    return ybh_post_language_valid($v) ? $v : '';
}

/**
 * 渲染用：该文章的 `lang` 属性值。空串表示"不覆盖，继承 `<html lang>`"。
 *
 * 模板里这样用：`<div class="entry-content"<?php echo ybh_post_language_attr(); ?>>`
 * —— 只给正文容器加属性，**不动 `<html lang>`**：整页语言（导航/页脚）仍是站点语言，
 * 只有正文按文章语言渲染，语义与字形都更准。
 *
 * @param int|null $post_id
 * @return string 形如 ` lang="zh-Hant"`（含前导空格）或空串
 */
function ybh_post_language_attr($post_id = null)
{
    $lang = ybh_post_language($post_id);
    return '' === $lang ? '' : ' lang="' . esc_attr($lang) . '"';
}

/**
 * 写入文章语言。
 *
 * @param int    $post_id
 * @param string $value
 * @return bool 是否发生变更
 */
function ybh_post_language_save($post_id, $value)
{
    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return false;
    }
    $value = (string) $value;
    if (!ybh_post_language_valid($value)) {
        $value = '';                       // 非法值一律当"默认（简体中文）"，不写脏数据
    }
    $old = (string) get_post_meta($post_id, YBH_POST_LANG_META, true);
    if ($old === $value) {
        return false;
    }
    if ('' === $value) {
        delete_post_meta($post_id, YBH_POST_LANG_META);
    } else {
        update_post_meta($post_id, YBH_POST_LANG_META, $value);
    }
    return true;
}

/* ---------------------------------------------------------------------------
 * 保存（后台）：任意编辑器保存时若带了 `ybh_lang` 就记下来
 *
 * 为什么挂在 `save_post` 而不是某个具体编辑器的钩子：站点同时存在经典编辑器、
 * 区块编辑器与前台写作页三种保存路径，`save_post` 是它们都会经过的那一个点。
 * ⚠️ 只在**字段确实出现**时处理 —— 没有这个字段的保存路径（例如前台就地点保存）
 *    不会把语言清空。
 * ------------------------------------------------------------------------- */
add_action('save_post', function ($post_id, $post, $update) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (!isset($_POST['ybh_lang'])) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    ybh_post_language_save($post_id, wp_unslash((string) $_POST['ybh_lang']));
}, 10, 3);

/* ---------------------------------------------------------------------------
 * 后台「发布」框里的语言选择（经典编辑器与区块编辑器共用这个位置）
 * ------------------------------------------------------------------------- */
add_action('post_submitbox_misc_actions', function ($post) {
    if (!$post instanceof WP_Post || 'post' !== $post->post_type) {
        return;
    }
    if (!current_user_can('edit_post', $post->ID)) {
        return;
    }
    $current = ybh_post_language($post->ID);
    ?>
    <div class="misc-pub-section ybh-lang-pub">
        <label for="ybh-lang-select" style="font-weight:600;">
            <span class="dashicons dashicons-translation" style="font-size:15px;width:15px;height:15px;line-height:1.6;"></span>
            源语言
        </label>
        <select name="ybh_lang" id="ybh-lang-select" style="max-width:100%;margin-top:4px;">
            <?php foreach (ybh_post_language_options() as $val => $label) : ?>
                <option value="<?php echo esc_attr($val); ?>" <?php selected($current, $val); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description" style="margin:4px 0 0;font-size:12px;color:#646970;">
            这篇文章的原文语言：决定正文的地区字形（简繁/日文的「直、骨、门」等写法不同），
            也是翻译系统认定的"原文"（其余语言都可以为它配译文）。
        </p>
    </div>
    <?php
}, 10, 1);

/**
 * 「发布」框的语言选择也要显示在「我的文章」列表里（给作者一个直观反馈）。
 * 用一列显示语言标签，未设置的不显示。
 */
add_filter('manage_posts_columns', function ($columns) {
    $columns['ybh_lang'] = '源语言';
    return $columns;
});
add_action('manage_posts_custom_column', function ($column, $post_id) {
    if ('ybh_lang' !== $column) {
        return;
    }
    $lang = ybh_post_language($post_id);
    echo '' === $lang
        ? '<span style="color:#8c8f94;">（默认/简体中文）</span>'
        : esc_html($lang);
}, 10, 2);
