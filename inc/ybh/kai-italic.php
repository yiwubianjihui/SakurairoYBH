<?php
/**
 * YBH · 中文斜体用霞鹜文楷（T71，方案乙：独立类名）
 *
 * ===================================================================
 * 背景与根因（全部实测，见交接文档）
 * ===================================================================
 *   站长反馈「WK（霞鹜文楷）没被当作斜体使用」。实测：斜体汉字实际渲染的是
 *   更纱自己的真斜体（浏览器请求的是 SarasaUiSC-Italic.subset.woff2），
 *   霞鹜文楷的文件**一次都没被下载**。
 *
 *   原因是两条 @font-face 打架：WK 的 CJK 斜体面（ybh.css 554/574）与更纱斜体面
 *   （3661/3662）**同族**（都挂在 'Sarasa UI SC' 下）、同字重、同 font-style，
 *   而且 **unicode-range 完全相同**（都含 U+4E00-9FFF）。
 *   实测把 WK 那两条移到更纱之后（按"后声明者胜"）**依然无效** —— 浏览器
 *   在 range 完全相同的重复 face 上并不按文档顺序选，所以那条路走不通。
 *
 * ===================================================================
 * 做法（方案乙：独立类名，影响面 = 0）
 * ===================================================================
 *   1) 新增**独立字体族** 'YBH Kai'（指向已托管的 LXGWWenKai-Regular.subset.woff2，
 *      不新上传任何字体文件）。不复用 'Sarasa UI SC' 族名 —— 那正是被遮蔽的原因。
 *      @font-face 声明写在 css/ybh.css 里。
 *   2) 这个过滤器只做一件事：给**含中文的斜体片段**加 class="ybh-kai-italic"，
 *      样式表里对应一条 .ybh-kai-italic 规则切到 'YBH Kai'。
 *
 *   为什么不用「给 em/i 写 font-family」那种全站映射：站内 <i> 有 178 处、
 *      其中 146 处是 FontAwesome / Dashicons / remixicon / iconfont 图标（全站扫描），
 *      一旦被 em/i 规则扫到就会掉字形。本方案**完全不碰 <i>/<em> 选择器**，
 *      图标字体天然不受影响（不需要豁免，因为它根本不在作用域内）。
 *
 * ===================================================================
 * 必须绕开的三个坑（照抄 footnotes.php 的既有做法）
 * ===================================================================
 *   a) **代码块不能动**：仓库里 inc/ybh/footnotes.php 已有
 *      ybh_fn_protect_code(10) / ybh_fn_restore_code(13) 的占位保护。
 *      本过滤器用**优先级 14**，排在 restore 之后 —— 这样占位符已被换回成真正的
 *      <code>/<pre>，我们再扫的时候必须**自己再挡一次**（不能简单地在 14 跑就以为
 *      安全：restore 已经把代码放回来了）。所以这里对代码块做同样的占位→还原。
 *   b) **不能重复加类**：pjax / REST / RSS 会让同一段内容反复过滤。
 *      加类前检查是否已含 ybh-kai-italic；已含则跳过。
 *   c) **不改语义**：只往标签的 class 属性里追加一个类名，不改标签名、
 *      属性顺序，不动标签内部文字。
 *
 * ===================================================================
 * 关于「日文/韩文」
 * ===================================================================
 *   判中文用的字符类里包含 CJK 汉字区 + CJK 标点 + 全角区，因此**日文假名里
 *   夹的汉字也会命中**（例如「詩を読む」里的「詩」）。这是**有意为之**：
 *   本站 <html lang="zh-Hans">，日文内容出现在正文里时，按全站中文版式渲染楷体
 *   斜体比突然切回无衬线更统一。若日后要出独立日文版式，给 :lang(ja) 单独一条
 *   覆盖即可（CSS 类选择器 + :lang() 组合，不需要动本文件）。
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

/** 是否含中日韩汉字 / CJK 标点 / 全角字符（含日文假名里夹的汉字，理由见文件头） */
function ybh_kai_has_cjk($text)
{
    return (bool) preg_match('/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]/u', $text);
}

/**
 * 挡代码块：把 <code>/<pre> 整段换成占位符，扫完再换回来。
 * 与 footnotes.php 的 ybh_fn_protect_code / ybh_fn_restore_code 同一手法 ——
 * 因为本过滤器排在 footnotes 的 restore_code(13) 之后，代码块此时已经被放回，
 * 必须自己再挡一次，否则代码示例里的 <em>/<i> 会被改。
 */
function ybh_kai_protect_code($content)
{
    if (stripos($content, '<code') === false && stripos($content, '<pre') === false) {
        return $content;
    }
    $GLOBALS['ybh_kai_code_store'] = array();
    return preg_replace_callback(
        '#<(code|pre)\b[^>]*>.*?</\1>#is',
        function ($m) {
            $i = count($GLOBALS['ybh_kai_code_store']);
            $GLOBALS['ybh_kai_code_store'][$i] = $m[0];
            return '{{YBH-KAICODE-' . $i . '}}';
        },
        $content
    );
}

function ybh_kai_restore_code($content)
{
    $store = isset($GLOBALS['ybh_kai_code_store']) ? (array) $GLOBALS['ybh_kai_code_store'] : array();
    if (!$store) {
        return $content;
    }
    foreach ($store as $i => $html) {
        $content = str_replace('{{YBH-KAICODE-' . $i . '}}', $html, $content);
    }
    $GLOBALS['ybh_kai_code_store'] = array();
    return $content;
}

/**
 * 给单个标签加类（幂等：已有 ybh-kai-italic 就不动）。
 */
function ybh_kai_tag($tag, $inner, $require_no_class = false)
{
    if (strpos($tag, 'ybh-kai-italic') !== false) { return $tag; }
    if (!ybh_kai_has_cjk($inner)) { return $tag; }
    if ($require_no_class && preg_match('/\sclass\s*=/i', $tag)) {
        return $tag;
    }
    if (preg_match('/\sclass\s*=\s*["\']/', $tag)) {
        return preg_replace('/(\sclass\s*=\s*["\'])([^"\']*)/', '$1ybh-kai-italic $2', $tag, 1);
    }
    return preg_replace('/^<(\w+)/', '<$1 class="ybh-kai-italic"', $tag, 1);
}

/**
 * 主过滤器：给含中文的 <em>…</em> 与「无 class 的 <i>…</i>」加 ybh-kai-italic。
 * 优先级 14 —— 排在 footnotes 的 protect_code(10)/restore_code(13) 之后。
 */
function ybh_kai_italic_filter($content)
{
    if (is_admin() || is_feed() || !is_string($content) || $content === '') {
        return $content;
    }
    if (stripos($content, '<em') === false && stripos($content, '<i') === false) {
        return $content;
    }
    $content = ybh_kai_protect_code($content);
    $content = preg_replace_callback(
        '#(<em\b[^>]*>)(.*?)(</em>)#is',
        function ($m) {
            return ybh_kai_tag($m[1], $m[2], false) . $m[2] . $m[3];
        },
        $content
    );
    $content = preg_replace_callback(
        '#(<i\b[^>]*>)(.*?)(</i>)#is',
        function ($m) {
            return ybh_kai_tag($m[1], $m[2], true) . $m[2] . $m[3];
        },
        $content
    );
    return ybh_kai_restore_code($content);
}

add_filter('the_content', 'ybh_kai_italic_filter', 14);
add_filter('the_excerpt', 'ybh_kai_italic_filter', 14);
