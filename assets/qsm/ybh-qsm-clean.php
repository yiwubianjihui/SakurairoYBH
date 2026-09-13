<?php
/**
 * Plugin Name: YBH · QSM 去推广与去付费引导
 * Description: 摘除 Quiz And Survey Master 后台的推广链接、升级弹窗/整页推销、Paid·Pro 徽标与氪金菜单项，并补译少量硬编码英文。不改动插件源码，插件升级不丢失。
 * Version: 1.2.0
 * Author: YBH
 *
 * ── 为什么必须放 mu-plugins ────────────────────────────────────────────────
 * qsm_get_plugin_link() / qsm_get_utm_link() 定义在
 * php/classes/class-qsm-install.php（文件末尾），该文件由插件构造函数
 * include_once（主文件 mlw_quizmaster2.php 约 279 行），**早于 plugins_loaded**。
 * 主题 functions.php 加载晚于插件 ⇒ 放主题里来不及（函数已被定义）。
 * mu-plugin 早于普通插件加载 ⇒ 可抢先定义同名函数，插件的定义被它自身的
 * if ( ! function_exists() ) 守卫跳过。
 *
 * ── 兼容性 ────────────────────────────────────────────────────────────────
 * 本文件复制了原函数的**完整签名（含默认值）**，调用方传参不会出错。
 * 插件升级后若函数签名变化，本文件需同步；若插件移除了 function_exists 守卫，
 * 会出现「函数重定义」致命错误 —— 此时把本文件改名为 ybh-qsm-clean.php.off 即可停用。
 *
 * ── 第 6 节存在的原因（汉化副作用修复，勿删）─────────────────────────────
 * QSM 的「统计」页 tab slug 是由**翻译后的标题**派生的：
 *   class-qmn-plugin-helper.php:935  $slug = strtolower( str_replace( ' ', '-', $title ) );
 * 而页面用硬编码英文 slug 做匹配：
 *   stats-page.php:23  $active_tab = ... : 'quiz-and-survey-submissions';
 * 英文标题 "Quiz And Survey Submissions" 恰好派生出该值 ⇒ 正常；
 * 汉化为「测验和调查提交」后 slug 不再匹配 ⇒ tab 内容（含统计图表）不渲染，
 * 并触发 qsm-admin.js:1978 的 `getContext of null` 报错。
 * 该页另有一处上游引号错位（stats-page.php:36 把 slug 写在 href 引号之外），
 * 导致点击 tab 时 URL 变成 `&tab=`（空值），同样会使面板变空。
 * 故第 6 节：① 把该 tab 的 slug 还原为约定值；② 归一化 $_GET['tab']。
 */

// 禁止直接访问：mu-plugin 由 WP 加载时 ABSPATH 已定义；直连则退出，
// 避免 file 被单独请求时调用未定义函数而产生致命错误并泄露服务器绝对路径。
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 彻底关闭插件自身的推广数据源（若插件未定义时才定义，避免与插件冲突）
if ( ! defined( 'YBH_QSM_KEEP_OFFICIAL_LINKS' ) ) {
	define( 'YBH_QSM_KEEP_OFFICIAL_LINKS', false );
}

/* ---------------------------------------------------------------------------
 * 1. 接管推广链接生成器（全插件 100+ 处调用都经过这两个函数）
 * ------------------------------------------------------------------------ */

if ( ! function_exists( 'qsm_get_plugin_link' ) ) {
	/**
	 * 原实现固定返回 https://quizandsurveymaster.com/{path}/?utm_*
	 * 现改为站内兜底，避免任何指向插件厂商购买页的跳转。
	 */
	function qsm_get_plugin_link( $path = '', $source = '', $medium = '', $content = '', $campaign = 'qsm_plugin' ) {
		if ( YBH_QSM_KEEP_OFFICIAL_LINKS ) {
			$link = 'https://quizandsurveymaster.com/';
			if ( ! empty( $path ) ) {
				$link .= $path;
			}
			return trailingslashit( $link );
		}
		return home_url( '/' );
	}
}

if ( ! function_exists( 'qsm_get_utm_link' ) ) {
	/**
	 * 原实现只负责给传入链接追加 UTM 参数。这里原样返回，保留链接本身、去掉跟踪参数。
	 */
	function qsm_get_utm_link( $link = '', $source = '', $medium = '', $content = '', $campaign = 'qsm_plugin' ) {
		if ( empty( $link ) ) {
			return $link;
		}
		return $link;
	}
}

/* ---------------------------------------------------------------------------
 * 2. 后台样式：隐藏推广容器 / 升级弹窗 / Paid·Pro 徽标 / 购买按钮
 *    选择器均为**独立类名 token**，已逐条核对不会误伤同前缀的功能类。
 *
 *    两类推销 markup（php/admin/functions.php:1169 qsm_admin_upgrade_popup）：
 *      · $type='popup' → .qsm-popup-upgrade（模态窗，内含 .qsm-upgrade-box）
 *      · $type='page'  → .qsm-upgrade-page-content（整页推销块）
 *    两者都要挡；page 型此前漏掉过，勿删。
 * ------------------------------------------------------------------------ */

add_action(
	'admin_head',
	function () {
		?>
		<style id="ybh-qsm-clean">
			/* 升级推销容器与弹窗 */
			.qsm-upgrade-page-content,
			.qsm-popup-upgrade,
			.qsm-upgrade-popup,
			.qsm-upgrade-box,
			.qsm-webhooks-pricing-popup,
			.qsm-ultimate-upgrade,
			/* 主题市场的付费标识与购买入口（保留 Live Preview） */
			.qsm-badge,
			.qsm-theme-buynow-btn,
			/* 通用广告位 */
			.help-decide,
			.qsm-upgrade-notice,
			#qsm-upgrade-notice { display: none !important; }

			/* 侧边栏氪金菜单（菜单项本身已在 admin_menu 移除，这里兜底） */
			#toplevel_page_qsm_dashboard .wp-submenu a[href*="page=qmn_addons"],
			#toplevel_page_qsm_dashboard .wp-submenu a[href*="page=qsm-free-addon"],
			#toplevel_page_qsm_dashboard .wp-submenu a[href*="page=qsm-answer-label"] { display: none !important; }

			/* 设置页里挂在 pro 选项上的「需升级」气泡提示 */
			.qsm-tooltips.qsm-upgrade-tooltip { display: none !important; }
		</style>
		<?php
	},
	99
);

/* ---------------------------------------------------------------------------
 * 3. 移除三个纯氪金菜单项（其页面内容 100% 是付费推销）
 *    qmn_addons      → mlw_quizmaster2.php:1082  Extensions 页
 *    qsm-free-addon  → mlw_quizmaster2.php:1083  Free Add-ons 页
 *    qsm-answer-label→ mlw_quizmaster2.php:1062  「答案标签」页，
 *                      内容由 functions.php:1515 qsm_advanced_assessment_quiz_page_content()
 *                      渲染，只有一个标题 + 整页推销块，无任何免费功能。
 *    父 slug：qsm_dashboard
 * ------------------------------------------------------------------------ */

add_action(
	'admin_menu',
	function () {
		remove_submenu_page( 'qsm_dashboard', 'qmn_addons' );
		remove_submenu_page( 'qsm_dashboard', 'qsm-free-addon' );
		remove_submenu_page( 'qsm_dashboard', 'qsm-answer-label' );
	},
	999
);

/* ---------------------------------------------------------------------------
 * 4. 文案兜底：少数没有包在隐藏容器里的推广词
 *    仅处理已确认「全插件只出现在推广语境」的字符串，避免误伤。
 * ------------------------------------------------------------------------ */

add_filter(
	'gettext',
	function ( $translation, $text, $domain ) {
		if ( 'quiz-master-next' !== $domain ) {
			return $translation;
		}
		static $map = null;
		if ( null === $map ) {
			$map = array(
				'Paid'          => '',   // 主题卡片付费徽标（元素已被 CSS 隐藏）
				'PRO'           => '',   // 设置页 pro 分组标题
				'Upgrade'       => '',   // 独立使用的升级按钮文字
				'Upgrade Plan'  => '',
				'Buy Now'       => '',
				'Buy Addon'     => '',
				'Get this add-on ' => '',
				'Available in pro' => '',
			);
		}
		return isset( $map[ $text ] ) ? $map[ $text ] : $translation;
	},
	20,
	3
);

/* ---------------------------------------------------------------------------
 * 5. 移除「结果」页的三个附加组件推销 tab
 *    admin-results-page.php:76-84 在付费插件类不存在时注册：
 *      qsm_export_results_tabs_content      → 'Export Results'      Addon
 *      qsm_reporting_analysis_tabs_content  → 'Reporting & Analysis' Addon
 *      qsm_proctor_quiz_tabs_content        → 'Quiz Proctor'         Addon
 *    三者内容都是纯推销面板（无任何功能），点击只会看到「升级到高级版」。
 *    按**回调函数名**匹配，与翻译无关；保留 'Quiz Results' 概览 tab。
 * ------------------------------------------------------------------------ */

add_action(
	'init',
	function () {
		global $mlwQuizMasterNext;
		if ( empty( $mlwQuizMasterNext )
			|| empty( $mlwQuizMasterNext->pluginHelper )
			|| empty( $mlwQuizMasterNext->pluginHelper->admin_results_tabs )
			|| ! is_array( $mlwQuizMasterNext->pluginHelper->admin_results_tabs ) ) {
			return;
		}
		$upsell = array(
			'qsm_export_results_tabs_content',
			'qsm_reporting_analysis_tabs_content',
			'qsm_proctor_quiz_tabs_content',
		);
		foreach ( $mlwQuizMasterNext->pluginHelper->admin_results_tabs as $i => $tab ) {
			if ( isset( $tab['function'] ) && in_array( $tab['function'], $upsell, true ) ) {
				unset( $mlwQuizMasterNext->pluginHelper->admin_results_tabs[ $i ] );
			}
		}
		// 索引被 unset 后留空洞，get_admin_results_tabs() 会 array_multisort，需重排。
		$mlwQuizMasterNext->pluginHelper->admin_results_tabs = array_values(
			$mlwQuizMasterNext->pluginHelper->admin_results_tabs
		);
	},
	99
);

/* ---------------------------------------------------------------------------
 * 6. 修复汉化在「统计」页引入的功能回归（详见文件头说明）
 * ------------------------------------------------------------------------ */

/**
 * 该 tab 的约定 slug（插件 stats-page.php:23 硬编码的默认值）。
 * 匹配依据是回调函数名，而非标题 —— 这样无论标题被翻译成什么语言都不会失配。
 */
if ( ! defined( 'YBH_QSM_STATS_SLUG' ) ) {
	define( 'YBH_QSM_STATS_SLUG', 'quiz-and-survey-submissions' );
}

// 6.1 把 slug 由「翻译后标题派生值」还原为约定值。
//     注册发生在 init(默认 10)，故本钩子排在 99 之后执行。
add_action(
	'init',
	function () {
		global $mlwQuizMasterNext;
		if ( empty( $mlwQuizMasterNext )
			|| empty( $mlwQuizMasterNext->pluginHelper )
			|| empty( $mlwQuizMasterNext->pluginHelper->stats_tabs )
			|| ! is_array( $mlwQuizMasterNext->pluginHelper->stats_tabs ) ) {
			return;
		}
		foreach ( $mlwQuizMasterNext->pluginHelper->stats_tabs as $i => $tab ) {
			if ( isset( $tab['function'] ) && 'qmn_stats_overview_content' === $tab['function'] ) {
				$mlwQuizMasterNext->pluginHelper->stats_tabs[ $i ]['slug'] = YBH_QSM_STATS_SLUG;
			}
		}
	},
	99
);

// 6.2 归一化 $_GET['tab']：上游在 stats-page.php:36 把 slug 写在 href 引号之外，
//     产生的链接是 `?page=qmn_stats&tab=`（空值）。空值会让 isset() 为真却取到 ''，
//     于是与任何 slug 都不匹配、面板变空。这里把空值补成约定 slug。
add_action(
	'admin_init',
	function () {
		if ( ! isset( $_GET['page'] ) || 'qmn_stats' !== $_GET['page'] ) {
			return;
		}
		if ( empty( $_GET['tab'] ) ) {
			$_GET['tab']     = YBH_QSM_STATS_SLUG;
			$_REQUEST['tab'] = YBH_QSM_STATS_SLUG;
		}
	}
);

/* ---------------------------------------------------------------------------
 * 7. 补译：插件里**未经 __() 的硬编码英文**（gettext 过滤器覆盖不到）
 *    仅做「整段文本完全相同」的精确替换，命中不到就保持英文，不会破坏功能。
 *    已核对来源：
 *      options-page-questions-tab.php:770 / question-bank-page.php:761  Save Question
 *      同上 :766 / :757                                                 Cancel
 *      class-qsm-fields.php:595                                         Select Page
 *      settings-page.php:1743                                          Or, Type /  to insert template variables
 *      about-page.php:24-37 / :86 / :118                                关于页 5 处
 *    JS 是唯一不改插件源码的途径（这些串由 PHP 直接 echo，无任何可过滤钩子）。
 * ------------------------------------------------------------------------ */

add_action(
	'admin_print_footer_scripts',
	function () {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		// 7.A 全后台通用（这些串在 QSM 源码里都是裸字面量）
		?>
		<script id="ybh-qsm-i18n">
		(function () {
			var MAP = {
				'Save Question': '保存题目',
				'Cancel': '取消',
				'Select Page': '选择页面',
				'Or, Type /  to insert template variables': '或输入 / 插入模板变量'
			};
			function swap(root) {
				if (!root) { return; }
				var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
				var n, jobs = [];
				while ((n = w.nextNode())) {
					var t = (n.nodeValue || '').trim();
					if (t && Object.prototype.hasOwnProperty.call(MAP, t)) { jobs.push([n, MAP[t]]); }
				}
				jobs.forEach(function (j) {
					var v = j[0].nodeValue;
					j[0].nodeValue = v.replace(v.trim(), j[1]);
				});
				// submit / button 的 value 属性不是文本节点，单独处理
				document.querySelectorAll('input[type="submit"], input[type="button"]').forEach(function (el) {
					var v = (el.value || '').trim();
					if (Object.prototype.hasOwnProperty.call(MAP, v)) { el.value = MAP[v]; }
				});
			}
			swap(document.body);
			// 弹窗等 AJAX 内容晚到，补跑两次
			setTimeout(function () { swap(document.body); }, 700);
			setTimeout(function () { swap(document.body); }, 2000);
		})();
		</script>
		<?php

		// 7.B 仅「关于」页（about-page.php:24-37 的 $tab_array 与 :86 / :118 都是裸字面量）
		if ( 'qsm_quiz_about' === $page ) {
			?>
			<script id="ybh-qsm-i18n-about">
			(function () {
				var MAP = {
					'About': '关于',
					'Help': '帮助',
					'System Info': '系统信息',
					'GitHub Contributors': 'GitHub 贡献者',
					'View GitHub Repo': '查看 GitHub 仓库'
				};
				var root = document.querySelector('.qsm-about-us-page');
				if (!root) { return; }
				var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
				var n, jobs = [];
				while ((n = w.nextNode())) {
					var t = (n.nodeValue || '').trim();
					if (t && Object.prototype.hasOwnProperty.call(MAP, t)) { jobs.push([n, MAP[t]]); }
				}
				jobs.forEach(function (j) {
					var v = j[0].nodeValue;
					j[0].nodeValue = v.replace(v.trim(), j[1]);
				});
			})();
			</script>
			<?php
		}
	}
);
