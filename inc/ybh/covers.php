<?php
/**
 * YBH · 文章封面取图（批量、按序分配）
 *
 * ---------------------------------------------------------------
 * 要解决的问题
 *
 * 原来每张卡片的封面都是 `rand-cover.php?img=w&<1~100 的随机数>`
 * （`get_random_url()` 追加随机数只为防浏览器缓存）。首页 10~14 张卡
 * 就是 10~14 次请求 —— 每次都要起一次 PHP、读一次索引、再 302 跳转，
 * 浏览器还要多走一个来回。而且那个随机数只有 100 种取值，
 * 同一页里撞图是常事。
 *
 * ---------------------------------------------------------------
 * 做法：渲染前一次抽好一批，卡片按顺序取
 *
 * 封面是**服务端渲染**的，所以不必再做一个「?n=10 返回 JSON」的接口
 * 让前端去要 —— PHP 本来就能直接读本机索引（`imglist.json`）：
 *
 *   · **零额外请求**：图片地址直接写进 HTML，连 302 都省了；
 *   · **同一页不重复**：一次抽 N 张互不相同的，按序发完再抽下一批；
 *   · 仍然每次刷新都换图（和原来"随机封面"的手感一致）。
 *
 * `rand-cover.php?n=10` 也支持了批量（返回 JSON），留给 App 与 AJAX 翻页用
 * —— 那两处拿不到服务端已经抽好的池子。
 *
 * ---------------------------------------------------------------
 * 与主题既有设置的关系
 *
 *   · 只在「用主题自带图库」时才接管（`random_graphs_options` 为
 *     ''/gallery/internal_api，或经 bootstrap.php 改写后的 external_api
 *     且链接指向本主题的 rand-cover.php）；
 *   · 站长若显式配了**别人的**外链封面，一律不动，仍走原逻辑；
 *   · 索引缺失/损坏时**自动退回**原来的 rand-cover.php 随机地址，
 *     不会因为本模块出问题就让全站封面变成空白。
 */

if (!defined('ABSPATH')) {
    exit;
}

/** 一批抽多少张。取比一页卡片数略多，翻页时接着用 */
if (!defined('YBH_COVER_BATCH')) {
    define('YBH_COVER_BATCH', 16);
}

/**
 * 读图库索引（带静态缓存，一次请求只读一次）。
 *
 * @return array{wide: string[], long: string[]}
 */
function ybh_cover_index()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = array('wide' => array(), 'long' => array());
    $file = WP_CONTENT_DIR . '/uploads/iro_gallery/imglist.json';
    if (!is_readable($file)) {
        return $cache;
    }

    $raw = file_get_contents($file);
    $list = json_decode((string) $raw, true);
    if (!is_array($list)) {
        return $cache;
    }

    /*
     * 防线：排除 `iro_gallery/img/封面`
     *
     * 该目录属主是 root:root，FTP 与 PHP 都删不掉；里面是转换前的原图与一套
     * 嵌套副本（实测 1000 个文件、909 MB，最大单张 12.9 MB）。
     * 一旦被抽中，访客要为一个封面下十几兆 —— 与 rand-cover.php 里那道防线同理。
     */
    $strip = static function ($paths) {
        $out = array();
        foreach ((array) $paths as $p) {
            if (strpos((string) $p, '/封面/') === false) {
                $out[] = (string) $p;
            }
        }
        return $out;
    };

    $cache['wide'] = $strip(isset($list['wide']) ? $list['wide'] : array());
    $cache['long'] = $strip(isset($list['long']) ? $list['long'] : array());
    return $cache;
}

/**
 * 索引里的相对路径 → 可直接放进 src 的绝对 URL。
 *
 * 逐段 rawurlencode：图库目录名是中文（杂图 / 风景现实…），
 * 不编码的话虽然浏览器多能容错，但**严格的 HTTP 客户端会拒绝**
 * （Flutter/Dart 的 HttpClient 就不接受非 ASCII 的 URL）。
 */
function ybh_cover_url($rel_path)
{
    $segments = explode('/', (string) $rel_path);
    $encoded = array();
    foreach ($segments as $seg) {
        $encoded[] = rawurlencode(rawurldecode($seg));
    }
    return content_url('/uploads' . implode('/', $encoded));
}

/**
 * 把「大图路径」换算成某个变体的路径。
 *
 * 图库现在每张图有两个文件（见 D:\Pictures\封面\convert-双变体.ps1）：
 *
 *     xxx.webp        大图  —— 大屏首屏封面用（1920 / q88）
 *     xxx-card.webp   卡片图 —— 文章列表卡片用（1200 / q82，约卡片显示宽度的 2 倍）
 *
 * 索引 `imglist.json` 里存的仍是**大图**路径，卡片变体在这里按后缀推导，
 * 所以**不用重建索引**，也不会因为多了一套文件而要维护两份清单。
 *
 * @param string $url     已经编码好的完整 URL
 * @param string $variant 'card' 取卡片图；其它（含 'big'）原样返回
 */
function ybh_cover_variant($url, $variant = 'card')
{
    if ($variant !== 'card') {
        return $url;
    }
    // 只在扩展名前插 -card：.../2003.webp -> .../2003-card.webp
    return preg_replace('/(\.[a-z0-9]{2,5})$/i', '-card$1', (string) $url, 1);
}

/**
 * 某个相对路径的卡片变体在磁盘上是否真的存在。
 *
 * 为什么要查：服务器上有极少数图（历史遗留、没有源图重转过）**没有** -card 变体，
 * 直接发出去就是 404、卡片开天窗。这里查一次文件系统（每请求至多 16 次 stat，
 * 可忽略），不存在就退回大图 —— 宁可大一点，也不要空图。
 */
function ybh_cover_card_exists($rel_path)
{
    $rel = (string) $rel_path;
    $card = preg_replace('/(\.[a-z0-9]{2,5})$/i', '-card$1', $rel, 1);
    return ($card !== $rel) && file_exists(WP_CONTENT_DIR . '/uploads' . $card);
}

/**
 * 一次抽 $n 张互不相同的封面。
 *
 * @param int    $n      要几张
 * @param string $kind   'w' 横图（卡片默认）｜'l' 竖图｜其它 = 全部
 * @param string $size   'card' 返回卡片小图（默认，卡片场景）｜'big' 返回大图
 * @return string[] 绝对 URL
 */
function ybh_pick_covers($n, $kind = 'w', $size = 'card')
{
    $idx = ybh_cover_index();
    if ($kind === 'w' && !empty($idx['wide'])) {
        $pool = $idx['wide'];
    } elseif ($kind === 'l' && !empty($idx['long'])) {
        $pool = $idx['long'];
    } else {
        $pool = array_merge($idx['long'], $idx['wide']);
    }

    $n = max(1, (int) $n);
    $total = count($pool);
    if ($total === 0) {
        return array();
    }

    // 要的比库存还多：先把全部打乱发一轮，剩下的允许重复（总比发不出来强）
    if ($n >= $total) {
        shuffle($pool);
        $picked = array();
        for ($i = 0; $i < $n; $i++) {
            $picked[] = $pool[$i % $total];
        }
    } else {
        $keys = (array) array_rand($pool, $n);   // array_rand 保证不重复
        $picked = array();
        foreach ($keys as $k) {
            $picked[] = $pool[$k];
        }
    }

    // 逐张决定用哪个变体：默认卡片小图；没有 -card 文件的（历史遗留）退回大图
    $out = array();
    foreach ($picked as $rel) {
        if ($size === 'card' && !ybh_cover_card_exists($rel)) {
            $out[] = ybh_cover_url($rel);
        } else {
            $out[] = ybh_cover_variant(ybh_cover_url($rel), $size);
        }
    }
    return $out;
}

/**
 * 大图变体（大屏 / 首屏封面用）。
 *
 * @param int    $n
 * @param string $kind
 * @return string[]
 */
function ybh_pick_covers_big($n, $kind = 'w')
{
    return ybh_pick_covers($n, $kind, 'big');
}

/**
 * 按顺序取下一张封面（同一请求内维护一个队列，取完自动补货）。
 *
 * @param string $kind 'w' | 'l'
 * @return string 取不到时返回空串，调用方自行兜底
 */
function ybh_next_cover($kind = 'w')
{
    static $queue = array();
    $key = ($kind === 'l') ? 'l' : 'w';

    if (empty($queue[$key])) {
        $queue[$key] = ybh_pick_covers(YBH_COVER_BATCH, $key);
    }
    if (empty($queue[$key])) {
        return '';
    }
    return array_shift($queue[$key]);
}

/**
 * 当前站点是否在用「主题自带图库」当封面源。
 *
 * 站长若显式配了别人的外链，就完全尊重，不接管。
 */
function ybh_cover_uses_gallery()
{
    $opt = (string) iro_opt('random_graphs_options', '');

    // 内建图库的三种取值
    if (in_array($opt, array('', 'gallery', 'internal_api'), true)) {
        return true;
    }
    if ($opt === 'external_api') {
        $link = (string) iro_opt('random_graphs_link', '');
        // 只接管指向本主题 rand-cover.php 的那种（由 bootstrap.php 改写而来）
        return (strpos($link, '/themes/SakurairoYBH/rand-cover.php') !== false);
    }
    return false;
}

/**
 * 给 `DEFAULT_FEATURE_IMAGE()` 用的入口：能接管就发池子里的图，
 * 否则返回空串让调用方走原来的逻辑。
 *
 * @param string $kind
 * @return string
 */
function ybh_cover_for_card($kind = 'w')
{
    if (!ybh_cover_uses_gallery()) {
        return '';
    }
    return ybh_next_cover($kind);
}
