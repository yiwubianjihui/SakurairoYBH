<?php
/**
 * YBH · 轻量随机封面端点
 *
 * 为什么不走 REST：`/wp-json/sakura/v1/gallery` 每次请求都会**完整启动 WordPress**
 * （内核 + 全部插件 + 主题），再 302 跳转。首页有 10 张封面 ⇒ 每次访问 = 10 次重量级
 * PHP 启动。本文件**不加载 WordPress**，只读索引 JSON 后 302，耗时从数百毫秒降到几毫秒。
 *
 * 用法：rand-cover.php?img=w|l
 *   w = 横图（宽卡用，索引里的 wide）  l = 竖图（长卡用，索引里的 long）  缺省 = 全部
 *
 * 缓存：响应带 Cache-Control: max-age=60 —— 浏览器一分钟内复用这次随机结果，
 * 既明显减少请求，又保持「每次刷新基本都换图」的随机感。
 *
 * 索引由主题后台「随机图 API → 初始化索引」生成：wp-content/uploads/iro_gallery/imglist.json
 */

declare(strict_types=1);

$upload_dir = dirname(__DIR__, 2) . '/uploads/iro_gallery';   // wp-content/uploads/iro_gallery
$list_file  = $upload_dir . '/imglist.json';

// 一分钟缓存：同一访客一分钟内命中同一次随机结果
header('Cache-Control: public, max-age=60');
header('Vary: Accept-Encoding');

if (!is_readable($list_file)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'image index not found';
    exit;
}

$raw = file_get_contents($list_file);
$list = json_decode((string) $raw, true);

if (!is_array($list)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'image index broken';
    exit;
}

$wide = isset($list['wide']) && is_array($list['wide']) ? $list['wide'] : [];
$long = isset($list['long']) && is_array($list['long']) ? $list['long'] : [];

/**
 * 防线：排除 iro_gallery/img/封面
 *
 * 该目录属主是 root:root（当初用宝塔在线解压 封面.zip 生成的），FTP 与 PHP 都没有权限删除/改名；
 * 里面是**转换前的原图**与一套嵌套副本（实测 1000 个文件、909 MB，均值 611 KB、最大 12.9 MB）。
 * 一旦被抽中，用户要为一个封面下十几兆，属于最坏情况。
 * 索引已重建为不含它；这里再加一道 —— 即使后台重新点「初始化索引」把它扫回来，本端点也不会发出去。
 */
$strip_originals = static function (array $paths): array {
    return array_values(array_filter($paths, static function ($p) {
        return strpos((string) $p, '/封面/') === false;
    }));
};
$wide = $strip_originals($wide);
$long = $strip_originals($long);

$kind = isset($_GET['img']) ? strtolower((string) $_GET['img']) : '';

if ($kind === 'w' && $wide) {
    $pool = $wide;
} elseif ($kind === 'l' && $long) {
    $pool = $long;
} else {
    $pool = array_merge($long, $wide);
}

if (!$pool) {
    http_response_code(404);
    exit;
}

$pick = $pool[random_int(0, count($pool) - 1)];

// 索引里存的是 /iro_gallery/img/... 这样的相对路径，拼绝对地址
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'www.yibianhui.cn';

/**
 * ⚠️ Location 头必须是**纯 ASCII**（RFC 7230）。
 *
 * 图库目录名是中文（`杂图` / `风景现实` / `风景二次元`），索引里的路径因此带非 ASCII 字节。
 * 直接把原始 UTF-8 塞进 Location，浏览器与 curl 容错能过，但**严格的 HTTP 客户端会拒绝**：
 * Flutter/Dart 的 HttpClient 就是直接失败，表现为「封面图永远加载不出来、只剩兜底背景」
 * （App 侧真机上实测撞到过）。
 *
 * 所以逐段做规范化编码：先 rawurldecode 再 rawurlencode ——
 * ① 只编码段内容、保留 `/` 分隔符；② 索引里已是 %XX 的不会被二次编码；③ 幂等。
 */
$encode_path = static function (string $path): string {
    $segments = explode('/', $path);
    $out = [];
    foreach ($segments as $seg) {
        $out[] = rawurlencode(rawurldecode($seg));
    }
    return implode('/', $out);
};

$abs = static function (string $rel) use ($scheme, $host, $encode_path): string {
    return $scheme . '://' . $host . '/wp-content/uploads' . $encode_path($rel);
};

/**
 * 变体：图库每张图有两个文件（见 D:\Pictures\封面\convert-双变体.ps1）
 *
 *     xxx.webp        大图  —— **默认**，供大屏首屏封面用
 *     xxx-card.webp   卡片图 —— `?size=card` 时用，供文章卡片 / App 列表用
 *
 * 默认给大图，是因为这个端点主要服务首屏那张全宽封面（bootstrap.php §9 把
 * `random_graphs_link` 指到这里）。卡片那条路走的是 covers.php 的批量池，
 * 那边已经默认取 -card，不经过这里。
 *
 * 没有 -card 文件时（历史遗留、没重转过的少数图）自动退回大图，避免 404 开天窗。
 */
$size = isset($_GET['size']) ? strtolower((string) $_GET['size']) : '';
$variant = static function (string $rel) use ($size): string {
    if ($size !== 'card') {
        return $rel;
    }
    $card = preg_replace('/(\.[a-z0-9]{2,5})$/i', '-card$1', $rel, 1);
    $card_path = dirname(__DIR__, 2) . '/uploads' . $card;
    return is_file($card_path) ? $card : $rel;
};

/**
 * 批量模式：`?n=10` → 一次返回 10 个**互不相同**的图片地址（JSON）。
 *
 * 为什么要它：网站的文章卡片是**服务端渲染**的，那边已经改成一页只抽一批
 * （见 inc/ybh/covers.php），用不着这个接口。但有两处拿不到服务端抽好的池子：
 *   · App —— 列表是客户端渲染的，原来每张卡各发一次请求；
 *   · AJAX「更早的文章」—— 追加的卡片需要新的地址。
 * 这两处用一个请求换一批，比一次一张省掉 N-1 个来回。
 *
 * 注意：返回的是**最终图片地址**，客户端拿到后直接加载即可，不必再走 302。
 * 卡片场景请带 `&size=card`，拿到的才是小图。
 */
$n = isset($_GET['n']) ? (int) $_GET['n'] : 0;
if ($n > 1) {
    $n = min($n, 60);                 // 一次最多 60 张，别让人拿它当爬虫用
    $total = count($pool);
    if ($n >= $total) {
        shuffle($pool);
        $keys = array_keys($pool);
        $keys = array_slice(array_merge($keys, $keys), 0, $n);   // 不够就允许重复
    } else {
        $keys = (array) array_rand($pool, $n);
    }

    $urls = [];
    foreach ($keys as $k) {
        $urls[] = $abs($variant((string) $pool[$k]));
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');   // 每次要都该是新的一批
    echo json_encode(['urls' => $urls], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$target = $abs($variant($pick));

header('Location: ' . $target, true, 302);
exit;
