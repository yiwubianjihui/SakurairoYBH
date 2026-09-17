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
 *   把这 11 个文件镜像到本地 `/wp-content/uploads/ybh-vision/`（已上传），
 *   再把主题选项 `vision_resource_basepath` 指过去 —— 这正是主题自己留的口子
 *   （`iro_opt('vision_resource_basepath', 'https://s.nmxc.ltd/...')`），
 *   所以不需要改任何模板，只改一个选项值。
 *
 *   ⚠️ **只在"当前值是上游默认"时才接管**：站长若自己填过别的 CDN，一律不动
 *   —— 与 §9 改随机封面端点、§9.5 改图标地址同一个分寸。
 *
 *   镜像失效（目录被删）时自动不接管，避免整站图形全挂。
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

add_filter('option_iro_options', 'ybh_local_vision_basepath');
function ybh_local_vision_basepath($value)
{
    if (!YBH_LOCAL_VISION || !is_array($value)) {
        return $value;
    }

    // 首屏占位图在，才认为镜像可用（判一个最能代表"目录还在"的文件）
    $probe = WP_CONTENT_DIR . '/' . YBH_VISION_DIR . '/basic/puff-load.svg';
    if (!file_exists($probe)) {
        return $value;
    }

    $base = content_url(YBH_VISION_DIR) . '/';

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
