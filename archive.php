<?php

/**
 * The template for displaying archive pages.
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 *
 * @package Sakurairo
 */

get_header(); ?>

<div id="primary" class="content-area">
	<main id="main" class="site-main" role="main">

		<?php
		if (have_posts()) : ?>

			<?php if (!iro_opt('patternimg') || !z_taxonomy_image_url()) { ?>
				<header class="page-header">
					<h1 class="cat-title"><?php single_cat_title('', true); ?></h1>
					<span class="cat-des">
						<?php
						if (category_description() != "") {
							echo "" . category_description();
						}
						?>
					</span>
				</header><!-- .page-header -->
			<?php } // page-header 
			// TODO： 'image_category'功能待实现
			while (have_posts()) : the_post();
				get_template_part('tpl/content', 'thumbcard');
			endwhile; ?>
			<div class="clearer"></div>
		<?php else :
			get_template_part('tpl/content', 'none');
		endif; ?>

	</main><!-- #main -->
	<?php if (iro_opt('pagenav_style') == 'ajax') { ?>
		<div id="pagination" <?php if (iro_opt('image_category') && is_category(explode(',', iro_opt('image_category')))) echo 'class="pagination-archive"'; ?>><?php next_posts_link(__(' Previous', 'sakurairo')); ?></div>
		<div id="add_post"><span id="add_post_time" style="visibility: hidden;" title="<?php echo iro_opt('page_auto_load', ''); ?>"></span></div>
	<?php } else { ?>
		<?php
		/*
		 * T62：归档页（分类 / 标签 / 日期）原来只有「上一页 / 下一页」两个箭头，
		 * 与主页、作者页的页码条不是一套。现在统一到 ybh_render_pagination()。
		 *
		 * ⚠️ 这是**可见的行为变化**：归档页会多出页码条与跳页框（回滚方式见
		 * handoff/T62-作者页与分页统一.md）。
		 */
		echo ybh_render_pagination(array(
			'total'   => (int) $wp_query->max_num_pages,
			'current' => max(1, (int) get_query_var('paged')),
			'label'   => __('归档分页', 'sakurairo'),
			'context' => 'archive',
		));
		?>
	<?php } ?>
</div><!-- #primary -->

<?php
get_footer();
