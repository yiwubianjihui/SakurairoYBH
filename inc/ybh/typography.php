<?php
/**
 * YBH · 中文排版：标点后的「可选换行点」（T33）
 *
 * 需求原话：首页的「季節の変わり目の服は、何着りゃいいんだろ」，请在“、”处增加可选换行
 *
 * 背景：首屏签名栏 `.header-info p` 是 `white-space: nowrap` + `text-overflow: ellipsis`
 *       （见 `style.css`）。窄屏放不下时**只会被省略号截断**，没有任何换行机会；
 *       而 CJK 的「、」「。」等标点之后**本来是可以断行的**，只是被 `nowrap` 一并禁掉了。
 *
 * 方案：在这些标点后面插入 `<wbr>`（HTML 标准的「可选换行点」）——
 *   · 空间够时**完全不显示**：不留空格、不改字距，文本仍是原来那一行；
 *   · 空间不够时允许在此处折行，避免整句被截断。
 *
 * 实现说明：用 `str_replace` 逐标点插入，而不是正则 ——
 *   本机 PHP 的 PCRE 对「多字符 UTF-8 交替 + 负向先行断言」组合会静默失配
 *   （`/(、。)(?!<\/?wbr)/u` 在 `甲、。乙` 上 match=0，无错误码），
 *   逐字符替换既无此坑，也更直观、可读。
 *
 * 注意：`<wbr>` 是 HTML 标签，**不能写进后台的签名文本框**（`signature_text` 走
 *       `esc_html()`，会被转义成字面 `&lt;wbr&gt;` 显示出来）。所以由代码统一后处理，
 *       后台文本保持纯文本，用户照旧编辑。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 在 CJK 标点后插入 `<wbr>`，给浏览器留出可选换行点。
 *
 * 幂等：已紧跟 `<wbr>` 的标点不会重复插入（先归一再去重）。
 *
 * @param string $text 已按 HTML 安全处理的文本
 * @return string
 */
function ybh_cjk_break_opportunities($text)
{
    if (!is_string($text) || $text === '') {
        return $text;
    }

    /**
     * 允许断行的标点：句读（、。，．！？）、顿逗分号冒号、以及全角右引号/右括号系。
     * 不含左引号与左括号 —— 中文避头尾规则禁止它们出现在行首，断在其后会造成行首标点。
     */
    static $punct = array(
        '、', '。', '，', '．', '！', '？',
        '；', '：', '）', '】', '》', '」', '』',
        '”', '’', '…', '—', '～',
    );

    // 先归一（把已有的 `<wbr>` 去掉），再统一插入 —— 保证幂等，不产生 `<wbr><wbr>`
    $text = str_replace(
        array_map(function ($p) { return $p . '<wbr>'; }, $punct),
        $punct,
        $text
    );

    return str_replace(
        $punct,
        array_map(function ($p) { return $p . '<wbr>'; }, $punct),
        $text
    );
}
