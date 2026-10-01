<?php
/**
 * Template part for displaying page content in page.php.
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 *
 * @package Sakurairo
 */

?>

<article id="post-<?php echo esc_attr(get_the_ID()); ?>" <?php post_class(); ?>>
	<?php 
	if (should_show_title()) { ?>
	<header class="entry-header">
		<?php 
		$title_style = get_post_meta(get_the_ID(), 'title_style', true);
		echo '<h1 class="entry-title" style="' . esc_attr($title_style) . '">' . esc_html(get_the_title()) . '</h1>'; 
		?>
	</header><!-- .entry-header -->
	<?php } ?>
	<?php get_template_part('layouts/sidebox'); ?>
	<!-- T65：正文容器按文章语言带 lang（页面同样适用；未设置则继承 <html lang>） -->
	<div class="entry-content"<?php echo ybh_post_language_attr(); ?>>
		<?php
			the_content('', true);
			wp_link_pages([
				'before' => '<div class="page-links">' . esc_html__('Pages:', 'sakurairo'),
				'after'  => '</div>',
			]);
		?>
	</div><!-- .entry-content -->

	<footer class="entry-footer">
		<?php
			edit_post_link(
				sprintf(
					/* translators: %s: Name of current post */
					esc_html__('Edit %s', 'sakurairo'),
					'<span class="screen-reader-text">' . esc_html(get_the_title()) . '</span>'
				),
				'<span class="edit-link">',
				'</span>'
			);
		?>
	</footer><!-- .entry-footer -->
</article><!-- #post-## -->
