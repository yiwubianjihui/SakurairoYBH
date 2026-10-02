<?php
/**
 * YBH · 上游 CDN 资源本地化（Sakurairo vision）
 *
 * ---------------------------------------------------------------
 * 问题
 *
 *   主题有一批基础图形资源（懒加载占位图、波浪、播放/暂停、社交图标…）默认从
 *   上游 CDN 取：
 *
 *       https://s.nmxc.ltd/sakurairo_vision/@3.0/
 *
 *   实测从本站到该 CDN：**TLS 0.75s、首字节 0.79s**，而首页引用了 **11 个** ——
 *   每个都要跨一次海外往返。这些文件合计只有 **35 KB**，完全是"路远"而不是"文件大"。
 *   站点首页的懒加载占位图（`basic/puff-load.svg`）也在其中，等于**每张懒加载图**
 *   都要先等一次海外往返。
 *
 * ---------------------------------------------------------------
 * 做法
 *
 *   把上游文件镜像到本地 `/wp-content/uploads/ybh-vision/`，再把主题选项
 *   `vision_resource_basepath` 指过去 —— 这正是主题自己留的口子
 *   （`iro_opt('vision_resource_basepath', 'https://s.nmxc.ltd/...')`），
 *   所以不需要改任何模板，只改一个选项值。
 *
 *   ⚠️ **只在"当前值是上游默认"时才接管**：站长若自己填过别的 CDN，一律不动
 *   —— 与 §9 改随机封面端点、§9.5 改图标地址同一个分寸。
 *
 * ---------------------------------------------------------------
 * ⚠️ 2026-09-25 事故修复：完整性闸门（本版新增）
 *
 *   上一版只用**一个**文件当探针：
 *
 *       $probe = ... . '/basic/puff-load.svg';
 *       if (!file_exists($probe)) return $value;   // 探针在 → 整个 basepath 全面接管
 *
 *   而 `vision_resource_basepath` 是**一棵树的根**，一个值同时决定了这些子目录：
 *
 *       basic/  background/  options/  series/  comment_level/  smilies/
 *
 *   当时镜像里其实只有 4 个文件（puff-load.svg / grid.png / dot.gif / bg1.png），
 *   `smilies/` 等**根本没建**。探针文件恰好存在 ⇒ 闸门放行 ⇒ 整站把表情、
 *   选项图、系列图全部指向不存在的本地路径 ⇒ **50 个 bilibili 表情 + 32 个
 *   贴吧表情全部 404**（HAR 实测 62 个图片请求里 50 个 404）。
 *
 *   教训：**用"某一份文件在不在"来判定"整棵树可不可用"是不成立的**。
 *   所以本版把探针换成**逐目录闸门**：basepath 会驱动到的每一个子目录
 *   都必须真实存在，否则**整体不接管**（保持上游，宁可慢、不可坏）。
 *   这样"镜像装了一半"永远不会再让整站图形挂掉。
 *
 *   缺目录时还会在后台发一条 notice，把缺什么直接说清楚。
 *
 * ---------------------------------------------------------------
 * 关闭方式：把 YBH_LOCAL_VISION 定义为 false。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('YBH_LOCAL_VISION')) {
    define('YBH_LOCAL_VISION', true);
}

/** 镜像目录（相对 wp-content） */
if (!defined('YBH_VISION_DIR')) {
    define('YBH_VISION_DIR', 'uploads/ybh-vision');
}

/** 上游默认值前缀 —— 只有当前值以它开头时才接管 */
if (!defined('YBH_VISION_UPSTREAM')) {
    define('YBH_VISION_UPSTREAM', 'https://s.nmxc.ltd/sakurairo_vision/');
}

/**
 * 接管前必须齐全的目录清单（相对镜像目录）：`目录 => 探针文件`。
 *
 * 这些目录都是被 `vision_resource_basepath` **这一个选项**拼出来的，少任何一个，
 * 主题都会拼出 404 的地址。所以每一项都要过闸门：
 *
 *   目录存在 **且** 探针文件也在  → 这一项才算可用
 *
 * （只判 `is_dir()` 不够：建了个空目录照样会被骗过去。）
 *
 *   basic/         占位图、favicon、播放/暂停、grid、dot
 *   background/    皮肤背景 bg1..bg8
 *   options/       后台选项页预览图（nav_menu_style_*、*_design_*、bangumi_tep_* …）
 *   series/        exhibition*、login_logo、avatar、admin_background
 *   comment_level/ 评论等级 level_0..6.svg
 *   ua/            评论里的浏览器 / 系统标识 ← 见下方 §UA 说明
 *   smilies/       评论表情 —— bilipng/ biliwebp/ tiebapng/ tiebawebp/
 *   display_icon/  社交图标四套皮肤 —— 见下方说明
 *
 *   ⚠️ 这份清单必须是**穷举**的：漏一个子目录，那个子目录就会在接管后 404。
 *      （2026-09-25 首版修复就漏了 `ua/` 和 `comment_level/level_0`，
 *        因为它们不在 functions.php 里，而在 inc/theme-plus.php 里拼接。）
 */
if (!defined('YBH_VISION_REQUIRED')) {
    define('YBH_VISION_REQUIRED', array(
        // 目录 => 探针文件
        'basic'                         => 'puff-load.svg',
        'background'                    => 'bg1.png',
        'options'                       => 'nav_menu_style_Island.webp',
        'series'                        => 'exhibition2.webp',
        'comment_level'                 => 'level_6.svg',
        'ua'                            => 'chrome.svg',
        'smilies'                       => 'biliwebp/emoji_baiyan.webp',
        'smilies/bilipng'               => 'emoji_baiyan.png',
        'smilies/biliwebp'              => 'emoji_baiyan.webp',
        'smilies/tiebapng'              => 'icon_good.png',
        'smilies/tiebawebp'             => 'icon_good.webp',
        'display_icon'                  => 'fluent_design/wechat.webp',
        'display_icon/fluent_design'    => 'wechat.webp',
        'display_icon/muh2'             => 'wechat.webp',
        'display_icon/flat_colorful'    => 'wechat.webp',
        'display_icon/remix_iconfont'   => 'remix_social.css',
    ));
}

/**
 * ⚠️ §UA —— `ua/` 为什么必须进闸门（2026-09-25 第二次排查新增）
 *
 *   评论区每一条评论都会在 UA 后面挂两个小图标（浏览器 + 操作系统）：
 *
 *       inc/theme-plus.php:818  $imgurl = iro_opt('vision_resource_basepath').'ua/';
 *       inc/theme-plus.php:828  同上（移动端定制）
 *
 *   它们**同样**拼在这个 basepath 后面，所以 basepath 一旦本地化，
 *   `ua/chrome.svg`、`ua/linux.svg`、`ua/edge.svg`、`ua/win10-11.svg`、
 *   `ua/android.svg` … 全部 404。
 *
 *   实测该目录在上游是齐全的（13 个 svg 全 200），本地却整个不存在 ——
 *   首版修复包**漏掉了这个目录**（因为它不在 functions.php 里）。
 *   表现就是"表情修好了，但评论区的浏览器/系统标识还是破图"。
 *
 *   `comment_level/` 同理：`functions.php:766` 拼 `comment_level/level_{$Lv}.svg`，
 *   `$Lv` 取值 0..6，其中 `level_0.svg` 只在首版清单里漏掉（只补了 1..6）。
 */

/**
 * ⚠️ `display_icon/` 为什么也必须进闸门（2026-09-25 同一事故的隐藏分支）
 *
 *   社交图标走的是**另一个**选项 `social_display_icon`（默认 `display_icon/fluent_design`），
 *   但它同样拼在 `vision_resource_basepath` 后面：
 *
 *       layouts/imgbox.php:  iro_opt('vision_resource_basepath') . iro_opt('social_display_icon') . '/xx.webp'
 *       layouts/all_opt.php:  iro_opt('vision_resource_basepath') . iro_opt('social_display_icon') . '/'
 *
 *   也就是说：**两个选项共用一个 basepath**。basepath 一旦被接管成本地，
 *   `social_display_icon` 指向的那一套图标如果本地没镜像，社交图标就会 404。
 *
 *   当时首页只用到 fluent_design 的头三个文件（wechat/github/mail），恰好镜像里
 *   有这三个，所以**首页看不出来**；可后台一旦把社交图标皮肤切成 muh2 /
 *   flat_colorful / remix_iconfont，或前台用到第 4 个以外的图标，立刻整片坏掉。
 *   这类"默认皮肤恰好够用、换个设置就崩"的坑必须堵掉，所以 display_icon
 *   四套皮肤一并纳入闸门。
 */

/**
 * 逐项体检：目录存在**且**探针文件存在才算可用；返回缺失项列表（空数组 = 完整）。
 */
function ybh_vision_missing_dirs()
{
    // 静态记忆：`option_iro_options` 过滤器每次 `iro_opt()` 都可能触发，
    // 而这里要打十几次 file_exists。同一请求内结果不会变，缓存即可。
    static $cache = null;
    if (null !== $cache) {
        return $cache;
    }

    $missing = array();
    $root = WP_CONTENT_DIR . '/' . YBH_VISION_DIR;
    foreach (YBH_VISION_REQUIRED as $dir => $probe) {
        if (!is_dir($root . '/' . $dir) || !file_exists($root . '/' . $dir . '/' . $probe)) {
            $missing[] = $dir;
        }
    }
    $cache = $missing;
    return $cache;
}

/**
 * ⚠️ 2026-09-25 事故修复（二）：闸门必须**真正回改**，不能只是"不接管"
 *
 *   第一版闸门的写法是「不完整就 `return $value;`」，注释里写着"已回落到上游 CDN"。
 *   **这句话是错的，而且是个危险的错。**
 *
 *   `return $value` 只是"不改这个选项"。可 DB 里 `vision_resource_basepath`
 *   很可能**已经**就是本地地址了（实测线上正是
 *   `https://www.yibianhui.cn/wp-content/uploads/ybh-vision/`，是更早某次接管写进去的）。
 *   对这种情况，"不改" = 继续用本地 = 404 照旧，防护等于没有。
 *
 *   所以闸门必须区分两种情况：
 *     · DB 值是**我们自己的本地地址** → 主动改回上游（真回落）
 *     · DB 值是站长自填的别的 CDN   → 一个字都不动（守分寸）
 *
 *   除此之外，`load_out_svg` / `load_nextpage_svg` / `load_in_svg` / `skin_bg0..7`
 *   同样是"上一版接管时写进 DB 的本地地址"，也必须一起回改，否则占位图和皮肤
 *   仍指着不存在的文件。
 */

/**
 * 上游默认根路径（回落目标）。
 *
 * 主题自己 `visual_resource_updates()` 会把版本段改写成 `@3.0/`，
 * 所以回落值要与它一致，避免回落之后又被主题改成另一个版本号。
 */
if (!defined('YBH_VISION_UPSTREAM_DEFAULT')) {
    define('YBH_VISION_UPSTREAM_DEFAULT', 'https://s.nmxc.ltd/sakurairo_vision/@3.0/');
}

/**
 * 把**已经指向本地镜像**的选项改回上游（真回落）。站长自填的地址不动。
 *
 * @param array  $value 选项数组（按引用修改）
 * @param string $base  本地镜像根 URL
 * @return array 被改回的选项名列表
 */
function ybh_local_vision_restore(&$value, $base)
{
    $restored = array();
    $upstream = YBH_VISION_UPSTREAM_DEFAULT;

    /* ① 根路径 */
    $cur = isset($value['vision_resource_basepath']) ? (string) $value['vision_resource_basepath'] : '';
    if ('' !== $cur && 0 === strpos($cur, $base)) {
        $value['vision_resource_basepath'] = $upstream;
        $restored[] = 'vision_resource_basepath';
    }

    /* ② 三个懒加载占位图（完整 URL，与根路径无关）；保留 #fragment */
    foreach (array('load_out_svg', 'load_nextpage_svg', 'load_in_svg') as $key) {
        $v = isset($value[$key]) ? (string) $value[$key] : '';
        if ('' === $v || 0 !== strpos($v, $base)) {
            continue;
        }
        $frag = '';
        $hash = strpos($v, '#');
        if (false !== $hash) {
            $frag = substr($v, $hash);
        }
        $value[$key] = $upstream . 'basic/puff-load.svg' . $frag;
        $restored[] = $key;
    }

    /* ③ 皮肤背景 */
    for ($i = 0; $i <= 7; $i++) {
        $key = 'skin_bg' . $i;
        if (!isset($value[$key])) {
            continue;
        }
        $v = (string) $value[$key];
        if ('' === $v || 0 !== strpos($v, $base)) {
            continue;
        }
        $value[$key] = $upstream . 'background/bg' . $i . '.png';
        $restored[] = $key;
    }

    return $restored;
}

/**
 * 镜像不完整时的后台提示（前端不显示）。
 */
function ybh_local_vision_notice($missing, $restored)
{
    // 同一次请求里 option 过滤器会跑很多次，提示只挂一次
    static $added = false;
    if ($added) {
        return;
    }
    $added = true;

    add_action('admin_notices', function () use ($missing, $restored) {
        if (!current_user_can('manage_options')) {
            return;
        }
        $detail = '';
        if (!empty($restored)) {
            $detail = '已把 ' . count($restored) . ' 个选项改回上游：<code>'
                . esc_html(implode('</code>, <code>', $restored)) . '</code>。';
        } else {
            $detail = '当前选项未指向本地镜像，无需改动。';
        }
        echo '<div class="notice notice-warning"><p><strong>YBH 本地 vision 镜像不完整，已回落到上游 CDN。</strong><br>'
            . '缺失：<code>' . esc_html(implode('</code>, <code>', $missing)) . '</code><br>'
            . '镜像位置：<code>wp-content/' . esc_html(YBH_VISION_DIR) . '</code>。'
            . $detail
            . '补齐后本提示自动消失并自动切回本地。</p></div>';
    });
}

add_filter('option_iro_options', 'ybh_local_vision_basepath');
function ybh_local_vision_basepath($value)
{
    if (!YBH_LOCAL_VISION || !is_array($value)) {
        return $value;
    }

    $base = content_url(YBH_VISION_DIR) . '/';

    /* --- ⓪ 完整性闸门 -----------------------------------------------------
       ① 首屏占位图必须真实存在；② 清单里每个子目录的探针文件都必须存在。
       任一不满足 ⇒ **主动改回上游**（不是"不改"！见上面 ⚠️ 说明）。 */
    $probe = WP_CONTENT_DIR . '/' . YBH_VISION_DIR . '/basic/puff-load.svg';
    $missing = ybh_vision_missing_dirs();

    if (!file_exists($probe) || !empty($missing)) {
        if (empty($missing)) {
            $missing = array('basic/puff-load.svg');
        }
        $restored = ybh_local_vision_restore($value, $base);
        ybh_local_vision_notice($missing, $restored);
        return $value;
    }

    /* --- ① 基础图形根路径 ------------------------------------------------
       只有当前值还是上游默认时才接管；站长自己填过别的 CDN 一律不动。 */
    $current = isset($value['vision_resource_basepath'])
        ? (string) $value['vision_resource_basepath']
        : '';
    if ('' === $current || 0 === strpos($current, YBH_VISION_UPSTREAM)) {
        $value['vision_resource_basepath'] = $base;
    }

    /* --- ② 懒加载占位图（三个独立选项，都是完整 URL，跟上面的根路径无关）-
       这三个各自被写死在别处，实测**每一个都是上游地址**：
         · `load_out_svg`      → tpl/content-thumbcard.php 里每张懒加载图的 src
         · `load_nextpage_svg` → inc/decorate.php 的 CSS 变量 + footer.php 的预载 img
         · `load_in_svg`       → inc/swicher.php 写进 JS 配置的 `loading_ph`
       不换掉它们，等于每张图都还要跨一次海外往返（这是全站引用最多的外部资源）。

       ⚠️ 只在"值确实指向上游"时才替换，避免覆盖站长的自定义占位图；
       `#lazyload-blur` 之类的片段要保留 —— 主题用它做模糊占位。 */
    foreach (array('load_out_svg', 'load_nextpage_svg', 'load_in_svg') as $key) {
        $v = isset($value[$key]) ? (string) $value[$key] : '';
        if ('' === $v || 0 !== strpos($v, YBH_VISION_UPSTREAM)) {
            continue;
        }
        $frag = '';
        $hash = strpos($v, '#');
        if (false !== $hash) {
            $frag = substr($v, $hash);
        }
        $value[$key] = $base . 'basic/puff-load.svg' . $frag;
    }

    /* --- ③ 皮肤背景图 ---------------------------------------------------
       上游放的是 background/bg1..bg8.png。镜像里只有哪几张就换哪几张，
       缺的保持原样 —— 宁可慢一点，也不能变成坏图。 */
    for ($i = 0; $i <= 7; $i++) {
        $key = 'skin_bg' . $i;
        if (!isset($value[$key])) {
            continue;
        }
        $v = (string) $value[$key];
        if ('' === $v || 0 !== strpos($v, YBH_VISION_UPSTREAM)) {
            continue;
        }
        $file = WP_CONTENT_DIR . '/' . YBH_VISION_DIR . '/background/bg' . $i . '.png';
        if (file_exists($file)) {
            $value[$key] = $base . 'background/bg' . $i . '.png';
        }
    }

    return $value;
}
