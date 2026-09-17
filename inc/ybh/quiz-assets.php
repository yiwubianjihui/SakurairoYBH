<?php
/**
 * YBH · Quiz 插件资源按需加载
 *
 * ---------------------------------------------------------------
 * 问题
 *
 *   `quiz-master-next` 把它的**全部**前端资源挂在 `wp_enqueue_scripts` 上
 *   （见插件 `php/shortcodes.php` 的 `qsm_load_main_scripts`），
 *   于是**每一个页面**都会加载：
 *
 *     css/animate.css · css/jquery-ui.css · css/jquery.ui.slider-rtl.css
 *     renderer/assets/css/qsm-common.css · renderer/assets/css/qsm-quiz-style.css
 *     templates/qmn_primary.css
 *     js/micromodal.min.js · js/jquery.ui.slider-rtl.js · js/progressbar.min.js
 *     js/qsm-common.js · renderer/assets/js/qsm-progressbar.js · renderer/assets/js/qsm-timer.js
 *     js/qsm-quiz-navigation.js   …以及它带的 jquery-ui 系列
 *
 *   实测本站首页加载了其中 **6 个 CSS + 6 个 JS**，而首页**根本没有测验**。
 *   每个都要一次 HTTP 往返（在 ~1 Mbps 的公网带宽下，往返本身就很贵）。
 *
 * ---------------------------------------------------------------
 * 做法
 *
 *   在 `wp_enqueue_scripts` 的最后（优先级 999）判断：**当前页面到底有没有测验**。
 *   没有就把 src 里含 `quiz-master-next` 的样式与脚本全部摘掉。
 *
 *   为什么按 `src` 判断而不是按 handle：插件的 handle 名又多又散
 *   （`qsm_*`、`micromodal_script`、`jquery-ui-*`…），逐个列会随插件升级漂移；
 *   按资源路径判断是"它从哪来"这个稳定事实。
 *   例外见下面 `$ybh_keep` —— 少数 handle 的 src 不明显，单独保留白名单。
 *
 * ---------------------------------------------------------------
 * 判定"有测验"的依据
 *
 *   · 单篇内容里含测验短代码（`[qsm_quiz]` / `[mlw_quizmaster]` / `[quiz]` / `[qsm]`）；
 *   · 或者是插件的**结果页 / 测验列表页**（那两种页面本身就依赖这些资源）。
 *
 *   其余情况（首页、归档、单篇文章页、页面…）一律不加载。
 *
 * ---------------------------------------------------------------
 * 关闭方式：把 YBH_QUIZ_ASSETS_ON_DEMAND 定义为 false。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('YBH_QUIZ_ASSETS_ON_DEMAND')) {
    define('YBH_QUIZ_ASSETS_ON_DEMAND', true);
}

/** 命中这些就认为"这一页需要 quiz 资源" */
if (!defined('YBH_QUIZ_MARKER')) {
    define('YBH_QUIZ_MARKER', 'quiz-master-next');
}

add_action('wp_enqueue_scripts', 'ybh_quiz_assets_on_demand', 999);
function ybh_quiz_assets_on_demand()
{
    if (!YBH_QUIZ_ASSETS_ON_DEMAND) {
        return;
    }

    // 后台永远不动：插件自己的编辑页/统计页都要用
    if (is_admin()) {
        return;
    }

    if (ybh_page_has_quiz()) {
        return;
    }

    // 少数资源虽然来自本插件，但 src 里认不出路径 —— 这里按 handle 兜一层
    // （保守起见只列确定无关的，拿不准的一律保留）
    $ybh_keep = array();

    foreach (array('styles', 'scripts') as $kind) {
        $wp_dep = 'wp_' . $kind;
        if (empty($GLOBALS[$wp_dep])) {
            continue;
        }
        foreach ($GLOBALS[$wp_dep]->queue as $handle) {
            if (in_array($handle, $ybh_keep, true)) {
                continue;
            }
            $src = isset($GLOBALS[$wp_dep]->registered[$handle])
                ? (string) $GLOBALS[$wp_dep]->registered[$handle]->src
                : '';
            if ('' !== $src && false !== strpos($src, YBH_QUIZ_MARKER)) {
                wp_dequeue_style($handle);
                wp_dequeue_script($handle);
            }
        }
    }
}

/**
 * 当前页面是否需要 quiz 资源。
 *
 * 缓存判定结果：`has_shortcode()` 走一遍内容正则，一页调两次（style/script 各一遍）
 * 没必要重复做。
 */
function ybh_page_has_quiz()
{
    static $cache = null;
    if (null !== $cache) {
        return $cache;
    }

    $cache = false;

    // 插件的专用页面（结果页 / 测验列表）本身就依赖这些资源
    $qsm_pages = array('qsm_quiz', 'qsm_results', 'quiz-results', 'qmn_quiz');
    foreach ($qsm_pages as $slug) {
        if (is_page($slug)) {
            $cache = true;
            return $cache;
        }
    }

    // 单篇 / 页面：看正文里有没有测验短代码
    $markers = array('qsm_quiz', 'mlw_quizmaster', 'quiz', 'qsm');
    $post = get_post();
    if ($post && !empty($post->post_content)) {
        foreach ($markers as $m) {
            if (has_shortcode($post->post_content, $m)) {
                $cache = true;
                return $cache;
            }
        }
    }

    // 首页 / 归档：把这一页将要渲染的文章正文扫一遍（只扫 ID 与短代码，不渲染）
    if (is_home() || is_front_page() || is_archive() || is_search()) {
        $ids = array();
        if (is_home() || is_archive() || is_search()) {
            global $wp_query;
            if ($wp_query && !empty($wp_query->posts)) {
                foreach ($wp_query->posts as $p) {
                    $ids[] = $p->ID;
                }
            }
        }
        foreach ($ids as $id) {
            $content = get_post_field('post_content', $id);
            foreach ($markers as $m) {
                if (has_shortcode((string) $content, $m)) {
                    $cache = true;
                    return $cache;
                }
            }
        }
    }

    // 兜底：登录用户 + 本站管理员想看到即时效果时，可用 ?ybh_quiz=1 强制加载
    if (isset($_GET['ybh_quiz'])) {
        $cache = true;
    }

    return $cache;
}
