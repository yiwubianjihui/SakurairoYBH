<?php
/**
 * YBH · 自建头像端点（T24）
 *
 * 为什么不走 REST：与 rand-cover.php 同样的理由 —— `/wp-json/...` 每次请求都要
 * **完整启动 WordPress**（内核 + 全部插件 + 主题）。头像在首页/评论列表里一次就是
 * 十几个请求，若每个都启动 WP，开销远超图片本身。本文件**不加载 WordPress**，
 * 只做「查本地文件 / 回源抓一次 / 输出字节」，耗时从数百毫秒降到几毫秒。
 *
 * ---------------------------------------------------------------
 * 用法（URL 由 inc/ybh/avatar.php 生成，正常无需手写）
 *
 *   ybh-avatar.php?u=17&s=96&v=1699999999   注册用户：优先「用户自己上传的」
 *   ybh-avatar.php?h=<md5>&s=48             任意邮箱 md5（评论访客）
 *   ybh-avatar.php?s=48                     无参数 → 站点默认头像
 *
 * ---------------------------------------------------------------
 * 取值优先链（自上而下，命中即返回）
 *
 *   1. uploads/ybh-avatars/u{user_id}.webp     ← 用户自己上传的（最高优先）
 *   2. uploads/ybh-avatars/{hash}.webp         ← 历史上已抓取落盘的
 *   3. 站点默认图 img/ybh-default-avatar.webp   ← 阿卡林；替代 WP 的 mystery
 *                                                 与 Cravatar 的 d=mm 占位图
 *
 * 🔴 **本端点永不发起任何外部请求**（2026-09-14 起，见下）。
 *
 *    原设计第 3 步是回源 `cravatar.com/avatar/<md5>?d=404`（404＝该邮箱无头像），
 *    按用户要求**已彻底取消**：头像只可能来自「用户自己传的」与「已经在本地的」，
 *    其它一律给站点默认图。好处是首字节不再受境外站点可用性影响，
 *    也不会再把访客的邮箱 md5 交给第三方。
 *
 *    因此 `{hash}.webp` 只会**减少不会增加**；要新增某个邮箱的真头像，
 *    只有两条路：本人在「个人资料」上传，或管理员手工把图片放进缓存目录。
 *
 * ⚠️ 保留 `ybh_av_fetch()` 但当前**没有调用方** —— 它记录了「带 d=404 判据的
 *    回源」该怎么正确实现（参数必须**小写 404**，写成 `d=D404` 会被当成未知取值
 *    而静默返回默认图，这个坑曾让第一轮验证得出「d=404 不可靠」的错误结论）。
 *    日后若要做「可选的外部头像源」，从这里接着写，别重新踩一遍。
 *
 * ---------------------------------------------------------------
 * 缓存策略（配合 inc/ybh/avatar.php 生成的 URL）
 *
 *   用户**自己上传**的真头像 → `max-age=31536000`
 *      换头像时 URL 上的 `v` 会变（见 ybh_avatar_url），所以可以放心长缓存。
 *   历史上**已落盘**的真头像 → `max-age=604800`（7 天）
 *      这类 URL 上没有 `v`，无法主动失效。由于已不再回源，7 天纯粹是
 *      「少一次条件请求」的考虑，可以放心调长。
 *   默认图 / .miss → `max-age=3600`
 *      用户随时可能上传头像，缓存短一点以尽快发现。
 *
 * ---------------------------------------------------------------
 * 安全
 *
 *   · `h` 参数**严格校验 32 位十六进制**，杜绝路径穿越；
 *   · `u` 参数强制转 int；
 *   · 尺寸 `s` 钳制在 16..512，防止被用来生成超大图打爆内存；
 *   · 只输出图片字节，不暴露真实磁盘路径。
 *
 * 由 inc/ybh/avatar.php 的 require 引入主题，文件本身随主题走版本控制。
 */

declare(strict_types=1);

/* ------------------------------------------------------------------ *
 * 常量与工具
 * ------------------------------------------------------------------ */

/** wp-content 目录（本文件位于 wp-content/themes/<主题>/） */
define('YBH_AV_DIR_CONTENT', dirname(__DIR__, 2));
define('YBH_AV_THEME_DIR', __DIR__);

/** 自建头像缓存目录 */
define('YBH_AV_CACHE', YBH_AV_DIR_CONTENT . '/uploads/ybh-avatars');

/** 「确认无头像」标记目录（避免反复回源 404） */
define('YBH_AV_MISS', YBH_AV_CACHE . '/.miss');

/** 站点默认头像（阿卡林） */
define('YBH_AV_DEFAULT', YBH_AV_THEME_DIR . '/img/ybh-default-avatar.webp');
define('YBH_AV_DEFAULT_PNG', YBH_AV_THEME_DIR . '/img/ybh-default-avatar.png');

/**
 * 外部头像源（**已停用**）。
 *
 * 2026-09-14 起本端点不再回源：头像只来自「用户上传」与「本地已有」。
 * 常量保留但值为空串，任何残留的 `YBH_AV_SOURCE . $hash` 拼出来都是相对路径，
 * 不会意外打到第三方；同时让「这里曾经有个源」这件事在代码里看得见。
 */
define('YBH_AV_SOURCE', '');

/** 抓取超时（秒，仅 ybh_av_fetch 使用；该函数当前无调用方） */
define('YBH_AV_TIMEOUT', 4);

/** 抓取/生成的基准边长 */
define('YBH_AV_BASE_SIZE', 256);

/**
 * `.miss` 标记的存活时间（秒）。
 *
 * 语义已变：以前它表示「回源确认过没有头像，N 天内不再回源」，
 * 现在没有回源了，它只剩一个作用 —— 让「这个邮箱没有头像」这件事**可被统计**，
 * 供后台「头像体检」报表用。所以这个值不参与任何缓存判据。
 */
define('YBH_AV_MISS_TTL', 259200); // 3 天

/**
 * 确保目录存在且带防护文件。
 */
function ybh_av_ensure_dir(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }
    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    return true;
}

/**
 * 在缓存目录放两个防护文件。
 *
 * `index.php` 防止 FTP/宝塔列目录时把头像文件暴露成索引；
 * `.htaccess` 禁止在本目录执行任何 PHP —— 虽然我们只写重编码后的 webp，
 * 但万一将来有人改了上传逻辑，这道墙能挡住最常见的「图片马」落地执行。
 */
function ybh_av_guard_dir(string $dir): void
{
    $index = $dir . '/index.php';
    if (!file_exists($index)) {
        @file_put_contents($index, "<?php\n// Silence is golden.\n");
    }
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents(
            $ht,
            "<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n"
            . "AddType text/plain .php .php3 .php4 .php5 .php7 .phtml .phar\n"
        );
    }
}

/**
 * 输出一张图片文件并结束请求（带 ETag / 304 支持）。
 *
 * @param string $file   绝对路径
 * @param int    $maxAge 浏览器缓存秒数
 * @param string $mime   MIME
 */
function ybh_av_send(string $file, int $maxAge, string $mime = 'image/webp'): void
{
    if (!is_readable($file)) {
        ybh_av_send_default($maxAge);
        return;
    }

    $mtime = (int) @filemtime($file);
    $size  = (int) @filesize($file);
    $etag  = '"' . md5($file . ':' . $mtime . ':' . $size) . '"';

    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=' . $maxAge);
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Vary: Accept-Encoding');
    header('X-YBH-Avatar: local');

    $imm = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) : '';
    if ($imm !== '' && $imm === $etag) {
        http_response_code(304);
        exit;
    }

    header('Content-Length: ' . $size);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }
    readfile($file);
    exit;
}

/**
 * 输出站点默认头像（阿卡林）。
 *
 * webp 优先（4.6 KB）；若服务器 GD 不支持 webp 或文件缺失，退回 PNG，
 * 保证任何环境下都有图可出，绝不会退回 WP 的 mystery 或 Cravatar 的 d=mm。
 */
function ybh_av_send_default(int $maxAge = 3600): void
{
    if (is_readable(YBH_AV_DEFAULT)) {
        ybh_av_send(YBH_AV_DEFAULT, $maxAge, 'image/webp');
    }
    if (is_readable(YBH_AV_DEFAULT_PNG)) {
        ybh_av_send(YBH_AV_DEFAULT_PNG, $maxAge, 'image/png');
    }

    // 连默认图都没有：吐一个 1×1 透明 GIF，绝不让页面出现破图
    header('Content-Type: image/gif');
    header('Cache-Control: public, max-age=600');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

/**
 * 取默认头像的字节（用于回源失败时直接内联输出，避免二次读取）。
 */
function ybh_av_default_bytes(): string
{
    if (is_readable(YBH_AV_DEFAULT)) {
        return (string) @file_get_contents(YBH_AV_DEFAULT);
    }
    return '';
}

/* ------------------------------------------------------------------ *
 * GD：转码 / 缩放
 * ------------------------------------------------------------------ */

/**
 * GD 是否可用（本端点回源与缩放都依赖它）。
 */
function ybh_av_gd_ok(): bool
{
    return function_exists('imagewebp') && function_exists('imagecreatefromstring');
}

/**
 * 把任意图片字节转成方形 webp 落盘。
 *
 * 一律**居中裁成正方形**（头像容器都是等宽高 + 圆形裁切，非方图会被压扁变形），
 * 再缩放到目标边长。这样前台不必依赖 CSS 的 object-fit 兜底。
 *
 * @return bool 是否成功写出
 */
function ybh_av_write_square_webp(string $bytes, string $dest, int $targetSize = YBH_AV_BASE_SIZE, int $quality = 88): bool
{
    if (!ybh_av_gd_ok() || $bytes === '') {
        return false;
    }

    $src = @imagecreatefromstring($bytes);
    if (!$src) {
        return false;
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        imagedestroy($src);
        return false;
    }

    // 居中裁正方形
    $side = min($w, $h);
    $sx   = (int) (($w - $side) / 2);
    $sy   = (int) (($h - $side) / 2);

    $dst = imagecreatetruecolor($targetSize, $targetSize);

    // 保留 PNG 透明通道：先铺白底（头像默认用白底更稳，透明在深色下会露出页面底色）
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $targetSize, $targetSize, $white);
    imagesavealpha($dst, false);

    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $targetSize, $targetSize, $side, $side);

    $ok = @imagewebp($dst, $dest, $quality);
    imagedestroy($dst);
    imagedestroy($src);

    return (bool) $ok && is_file($dest) && filesize($dest) > 0;
}

/**
 * 从基准图生成指定边长的缓存变体；已存在则直接返回。
 *
 * 为什么要有变体：站点里头像尺寸跨 24px（评论）到 192px（作者页），
 * 全部下发 256 的图会浪费流量；按需生成一次后就永久命中。
 *
 * @return string|null 变体文件绝对路径
 */
function ybh_av_variant(string $baseFile, int $size): ?string
{
    if ($size === YBH_AV_BASE_SIZE || $size <= 0) {
        return $baseFile;
    }

    // ⚠️ 变体名里必须带基准文件的 mtime。
    //    只写 `u17-96.webp` 的话，用户换了头像、主文件已被重写，但这个变体还是旧的 ⇒
    //    前台会一直拿到旧图。带上 mtime 后变体天然按版本隔离，即使清理逻辑失败也不会用错图。
    $ver     = (int) @filemtime($baseFile);
    $variant = preg_replace('/\.webp$/', '', $baseFile) . '-' . $ver . '-' . $size . '.webp';
    if (is_file($variant) && filesize($variant) > 0) {
        return $variant;
    }
    if (!ybh_av_gd_ok()) {
        return $baseFile;      // 无 GD：退回基准图，浏览器自己缩放
    }

    $src = @imagecreatefromwebp($baseFile);
    if (!$src) {
        return $baseFile;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        imagedestroy($src);
        return $baseFile;
    }

    $dst   = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $size, $size, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, $w, $h);

    $ok = @imagewebp($dst, $variant, 88);
    imagedestroy($dst);
    imagedestroy($src);

    return ($ok && is_file($variant)) ? $variant : $baseFile;
}

/* ------------------------------------------------------------------ *
 * 回源
 * ------------------------------------------------------------------ */

/**
 * 拉取 URL 的原始字节，返回 [httpCode, body]。
 *
 * 不加载 WordPress ⇒ 不能用 wp_remote_get，这里手写一层：
 * cURL 优先，缺失时退回 stream context。超时严格受限。
 *
 * @return array{0:int,1:string}
 */
/**
 * 抓取远端图片（当前**无调用方**）。
 *
 * 保留原因见文件头：它把「带 `d=404` 判据的回源」的正确写法固定下来，
 * 包括「404 = 确实没有头像」以及与 Cravatar 打交道的参数坑。
 * 若日后要加一个**显式开启**的外部头像源，从这里接着写。
 *
 * @internal 目前没有调用方，端点本身不再访问任何外部站点。
 */
function ybh_av_fetch(string $url): array
{
    $ua = 'YBH-Avatar/1.0 (+https://www.yibianhui.cn)';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => YBH_AV_TIMEOUT,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_SSL_VERIFYPEER => false,   // 部分国内镜像证书链不完整
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING       => '',
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return array($code, is_string($body) ? $body : '');
    }

    if (function_exists('stream_context_create')) {
        $ctx  = stream_context_create(array(
            'http' => array(
                'method'        => 'GET',
                'timeout'       => YBH_AV_TIMEOUT,
                'user_agent'    => $ua,
                'ignore_errors' => true,
            ),
            'ssl'  => array('verify_peer' => false, 'verify_peer_name' => false),
        ));
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        return array($code, is_string($body) ? $body : '');
    }

    return array(0, '');
}

/**
 * 判断某邮箱是否处于「已确认无头像」状态（且标记未过期）。
 */
function ybh_av_is_missed(string $hash): bool
{
    $f = YBH_AV_MISS . '/' . $hash;
    if (!is_file($f)) {
        return false;
    }
    return (time() - (int) @filemtime($f)) < YBH_AV_MISS_TTL;
}

/**
 * 记下「确认无头像」。写失败不影响主流程。
 */
function ybh_av_mark_miss(string $hash): void
{
    if (!ybh_av_ensure_dir(YBH_AV_MISS)) {
        return;
    }
    @file_put_contents(YBH_AV_MISS . '/' . $hash, (string) time());
}

/* ------------------------------------------------------------------ *
 * 主流程
 * ------------------------------------------------------------------ */

$rawSize = isset($_GET['s']) ? (int) $_GET['s'] : 96;
$size    = max(16, min(512, $rawSize > 0 ? $rawSize : 96));

$userId = isset($_GET['u']) ? (int) $_GET['u'] : 0;
$hash   = isset($_GET['h']) ? strtolower(trim((string) $_GET['h'])) : '';

/**
 * 兼容主题前端 JS 的 URL 形态。
 *
 * `js/page.js` 里评论表单的「邮箱 → 头像实时预览」是把地址**硬拼**出来的：
 *     "https://" + _iro.gravatar_url + "/" + md5(email) + ".jpg?s=" + size + "&d=mm"
 * 我们无法改打包产物，于是让 `_iro.gravatar_url` 指向本文件，拼出来就是
 *     …/ybh-avatar.php/<md5>.jpg?s=80&d=mm
 * 这里从 PATH_INFO 里把 <md5> 捞出来，前端无需任何改动即可走本站端点。
 * （另有 MutationObserver 兜底，见 inc/ybh/avatar.php；两者不冲突。）
 */
if ($hash === '' && !empty($_SERVER['PATH_INFO'])) {
    if (preg_match('#([a-fA-F0-9]{32})#', (string) $_SERVER['PATH_INFO'], $m)) {
        $hash = strtolower($m[1]);
    }
}

// 严格校验：只接受 32 位十六进制，杜绝 ../ 之类的路径穿越
if ($hash !== '' && !preg_match('/^[a-f0-9]{32}$/', $hash)) {
    $hash = '';
}

ybh_av_ensure_dir(YBH_AV_CACHE);
ybh_av_guard_dir(YBH_AV_CACHE);

/* ---------- 1) 用户自己上传的（最高优先） ---------- */

if ($userId > 0) {
    $uploaded = YBH_AV_CACHE . '/u' . $userId . '.webp';
    if (is_file($uploaded) && filesize($uploaded) > 0) {
        ybh_av_send(ybh_av_variant($uploaded, $size) ?: $uploaded, 31536000);
    }
    // 用户没上传过 → 继续往下走 hash 链路（该用户可能在自己的 Gravatar 有头像）
    if ($hash === '' && $userId > 0) {
        // URL 里没带 hash，只能直接给默认图（正常情况下 PHP 侧一定会带 h）
        ybh_av_send_default();
    }
}

/* ---------- 无 hash：直接默认图 ---------- */

if ($hash === '') {
    ybh_av_send_default();
}

/* ---------- 2) 本地已落盘的 ---------- */

$cached = YBH_AV_CACHE . '/' . $hash . '.webp';
if (is_file($cached) && filesize($cached) > 0) {
    ybh_av_send(ybh_av_variant($cached, $size) ?: $cached, 604800);
}

/* ---------- 3) 没有本地文件 ⇒ 站点默认图 ---------- */

/*
 * 🔴 2026-09-14（T24b）：**不再回源任何第三方头像站**。
 *
 * 原来这里会去 cravatar.com 抓一次（`d=404` 判据），拿不到就给默认图。
 * 按用户要求取消之后，「无本地文件」就是**终点** —— 直接给阿卡林，并且：
 *   · 首次请求从「几百毫秒 + 一次境外网络往返」变成一次本地读盘；
 *   · 访客的邮箱 md5 不再发给任何第三方；
 *   · 境外站挂了也不会再拖慢本站评论区的首屏。
 *
 * 顺带记一个 .miss 标记：它不再影响取值（下面已经没有任何分支会读它），
 * 只是留给后台「头像体检」统计「有多少人是默认图」。
 * ⚠️ 只在**还没有标记**时写盘 —— 默认图是现在最常见的分支，
 *    每次请求都 `file_put_contents` 会把这个热路径变成写盘路径。
 */
if (!ybh_av_is_missed($hash)) {
    ybh_av_mark_miss($hash);
}
ybh_av_send_default(3600);
