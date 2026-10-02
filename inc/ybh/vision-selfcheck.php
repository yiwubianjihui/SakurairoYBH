<?php
/**
 * YBH · vision 本地镜像自检
 *
 * ---------------------------------------------------------------
 * 为什么需要它
 *
 *   2026-09-25 连续两次踩同一个坑：
 *     ① 镜像只装了 4 个文件，却让 basepath 全面接管 → 82 个表情 404；
 *     ② 第一版修复补了表情，却**漏了 `ua/` 和 `comment_level/level_0`**
 *        → 表情好了，评论区的浏览器/系统标识还是破图。
 *
 *   两次的根因是同一个：**"镜像装没装全"这件事，只靠人眼排查是漏得掉的。**
 *   所以这里把清单固化成代码，让站点自己对着磁盘逐项核对。
 *
 *   本文件只读、不改任何东西，也不会因为缺文件就让站点变慢 —— 它就是一张体检表。
 *
 * ---------------------------------------------------------------
 * 用法
 *
 *   浏览器访问（需管理员登录）：
 *
 *       https://www.yibianhui.cn/wp-content/themes/SakurairoYBH/inc/ybh/vision-selfcheck.php
 *
 *   或 WP-CLI：
 *
 *       wp eval 'var_dump(ybh_vision_selfcheck());'
 *
 *   期望输出：`OK: 278 required paths present.`
 *
 * ---------------------------------------------------------------
 * 维护
 *
 *   ⚠️ 新增任何 `vision_resource_basepath . '...'` 的拼接，都要在下面补上对应条目。
 *      这份清单与 local-vision.php 的闸门清单是同一份事实的两种用途：
 *        · local-vision.php → 决定"要不要接管"（安全）
 *        · 本文件           → 决定"接管后会不会有坏图"（完整）
 *      两者必须同步，否则就会出现"接管了但没装全"。
 */


if (!function_exists('ybh_vision_selfcheck')) {
    /**
     * 对着磁盘核对全部必需资源。
     *
     * @return array{ok:bool,total:int,missing:array,missing_count:int}
     */
    function ybh_vision_selfcheck()
    {
        $root = WP_CONTENT_DIR . '/' . (defined('YBH_VISION_DIR') ? YBH_VISION_DIR : 'uploads/ybh-vision');
        $missing = array();
        $total = 0;

        /* --- ① 目录级：只作"目录是否还在"的前置粗筛，**不计入 total** -------
           下面 ② 的文件级清单已经逐文件覆盖了这些目录里的内容，若这里再 `$total++`
           就会把同一个文件数两遍（实测 total 会虚报成 294，而真实需求是 278）。
           真正的判据是 ②；这里只负责在"整个目录被删"时给出更好读的错误信息。 */
        $dirs = defined('YBH_VISION_REQUIRED') ? YBH_VISION_REQUIRED : array(
            'basic' => 'puff-load.svg',
            'background' => 'bg1.png',
            'options' => 'nav_menu_style_Island.webp',
            'series' => 'exhibition2.webp',
            'comment_level' => 'level_6.svg',
            'ua' => 'chrome.svg',
            'smilies' => 'biliwebp/emoji_baiyan.webp',
            'display_icon' => 'fluent_design/wechat.webp',
        );
        foreach ($dirs as $dir => $probe) {
            if (!is_dir($root . '/' . $dir)) {
                // 目录整个不在：报一次目录级错误即可（细项由 ② 补全）
                $missing[] = "$dir/ (目录缺失)";
            }
        }

        /* --- ② 文件级：穷举每个会出现在页面上的资源 -------------------------
           下面的清单由 vision-required-final.json 机械生成，与 local-vision.php
           的闸门清单同源，避免人工维护时漏项（2026-09-25 就漏过 ua/ 与 level_0）。 */

        // 静态字面量 —— opt/options/theme-options.php、header/footer/exhibition 等
        $static = array(
            'background/bg1.png', 'background/bg2.png', 'background/bg3.png',
            'background/bg4.png', 'basic/favicon.ico', 'basic/puff-load.svg',
            'options/admin_left_style_v1.webp', 'options/admin_left_style_v2.webp', 'options/area_title_text_center.webp',
            'options/area_title_text_left.webp', 'options/area_title_text_right.webp', 'options/bangumi_tep_bgm.webp',
            'options/bangumi_tep_bili.webp', 'options/bangumi_tep_mal.webp', 'options/display_icon_fc.gif',
            'options/display_icon_fd.gif', 'options/display_icon_h2.gif', 'options/display_icon_svg.webp',
            'options/friend_link_center.webp', 'options/friend_link_left.webp', 'options/friend_link_right.webp',
            'options/infor_bar_style_v2.webp', 'options/nav_menu_style_Island.webp', 'options/nav_menu_style_bar.webp',
            'options/post_list_design_letter.webp', 'options/post_list_design_ticket.webp', 'options/post_list_design_ticket_2.webp',
            'options/update_source_github.webp', 'options/update_source_iro.webp', 'options/update_source_jsd.webp',
            'options/update_source_wafpro.webp', 'series/admin_background.webp', 'series/avatar.webp',
            'series/exhibition1.webp', 'series/exhibition2.webp', 'series/exhibition3.webp',
            'series/login_logo.webp',
        );
        $total += count($static);
        $missing = ybh_vision_check_files($root, $missing, $static);

        // 评论表情（bilibili）—— functions.php push_bili_smilies，webp + png 两套
        $bili = array(
            'baiyan', 'bishi', 'bizui',
            'chan', 'dai', 'daku',
            'dalao', 'dalian', 'dianzan',
            'doge', 'facai', 'fanu',
            'ganga', 'guilian', 'guzhang',
            'haixiu', 'heirenwenhao', 'huaixiao',
            'jingxia', 'keai', 'koubizi',
            'kun', 'lengmo', 'liubixue',
            'liuhan', 'liulei', 'miantian',
            'mudengkoudai', 'nanguo', 'outu',
            'qinqin', 'se', 'shengbing',
            'shengqi', 'shuizhao', 'sikao',
            'tiaokan', 'tiaopi', 'touxiao',
            'tuxue', 'weiqu', 'weixiao',
            'wunai', 'xiaoku', 'xieyanxiao',
            'yiwen', 'yun', 'zaijian',
            'zhoumei', 'zhuakuang',
        );
        // 评论表情（贴吧）—— functions.php push_tieba_smilies，webp + png 两套
        $tieba = array(
            'Grievance', 'Happy', 'aa',
            'anger', 'awesome', 'bbd',
            'britan', 'doubt', 'good',
            'haha', 'han', 'hu',
            'huaji', 'ku', 'naive',
            'niconiconi', 'niconiconi_t', 'niconiconit',
            'rbq', 'reluctantly', 'rmb',
            'se', 'shame', 'shui',
            'smilingeyes', 'spit', 'spray',
            'surprised', 'surprised2', 'tear',
            'theblackline', 'tongue',
        );
        foreach ($bili as $n) { $total += 2; $missing = ybh_vision_check_files($root, $missing, array("smilies/biliwebp/emoji_$n.webp", "smilies/bilipng/emoji_$n.png")); }
        foreach ($tieba as $n) { $total += 2; $missing = ybh_vision_check_files($root, $missing, array("smilies/tiebawebp/icon_$n.webp", "smilies/tiebapng/icon_$n.png")); }

        // 浏览器 / 系统标识 —— inc/theme-plus.php siren_get_browsers / siren_get_os
        $ua = array(
            '360se', 'android', 'apple',
            'chrome', 'edge', 'firefox',
            'linux', 'opera', 'safari',
            'unknown', 'win10-11', 'win7',
            'win8',
        );
        $total += count($ua);
        foreach ($ua as $n) { $missing = ybh_vision_check_files($root, $missing, array("ua/$n.svg")); }

        // 评论等级 —— functions.php user_level_icon，$Lv 取 0..6
        $levels = array(
            'comment_level/level_0.svg', 'comment_level/level_1.svg', 'comment_level/level_2.svg',
            'comment_level/level_3.svg', 'comment_level/level_4.svg', 'comment_level/level_5.svg',
            'comment_level/level_6.svg',
        );
        $total += count($levels);
        $missing = ybh_vision_check_files($root, $missing, $levels);

        // 社交图标 —— layouts/all_opt.php + layouts/imgbox.php（真实短名：tg/st/ig/dy/lk/tw/fb/ncm）
        $social = array(
            'bilibili', 'discord', 'dy',
            'fb', 'github', 'ig',
            'lk', 'mail', 'ncm',
            'qq', 'st', 'tg',
            'tw', 'wechat', 'weibo',
            'xiaohongshu', 'youtube', 'zhihu',
        );
        foreach (array('display_icon/flat_colorful', 'display_icon/fluent_design', 'display_icon/muh2') as $d) {
            $total += count($social);
            foreach ($social as $n) { $missing = ybh_vision_check_files($root, $missing, array("$d/$n.webp")); }
        }

        // remix 图标字体（CSS 已改为相对路径引用字体）
        $remix = array(
            'display_icon/remix_iconfont/remix_social.css', 'display_icon/remix_iconfont/remix_social.ttf', 'display_icon/remix_iconfont/remix_social.woff2',
        );
        $total += count($remix);
        $missing = ybh_vision_check_files($root, $missing, $remix);

        return array(
            'ok' => empty($missing),
            'total' => $total,
            'missing' => $missing,
            'missing_count' => count($missing),
        );
    }
}

if (!function_exists('ybh_vision_check_files')) {
    /**
     * @param string $root
     * @param array  $missing
     * @param array  $rels
     * @return array
     */
    function ybh_vision_check_files($root, $missing, $rels)
    {
        foreach ($rels as $rel) {
            if (!file_exists($root . '/' . $rel)) {
                $missing[] = $rel;
            }
        }
        return $missing;
    }
}

/* ---------------------------------------------------------------------------
 * 直接访问入口（人工体检用）
 *
 * ⚠️ 必须放在**两个函数定义之后**：PHP 的「条件式函数定义」不是编译期提升，
 *    在定义语句执行前调用会抛 "Call to undefined function"。
 *    （这正是本文件第一版写错的地方：入口写在了文件开头。）
 * ------------------------------------------------------------------------- */
if (!defined('ABSPATH')) {
    $wp_load = dirname(__DIR__, 5) . '/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    }
    if (!defined('ABSPATH')) {
        exit('WordPress not found.');
    }
    header('Content-Type: text/plain; charset=utf-8');
    if (!current_user_can('manage_options')) {
        exit('需要管理员权限。');
    }
    $r = ybh_vision_selfcheck();
    echo $r['ok']
        ? "OK: {$r['total']} required paths present.\n"
        : "FAIL: {$r['missing_count']} of {$r['total']} paths missing:\n" . implode("\n", $r['missing']) . "\n";
    exit;
}
