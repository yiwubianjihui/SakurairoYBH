<?php
/**
 * Template part for displaying posts.
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 *
 * @package Sakurairo
 */
$post_id = get_the_ID();
$ai_excerpt = get_post_meta($post_id, "ai_summon_excerpt", true); 
?>

<?php
$post = get_post();
if (iro_opt('article_auto_toc', 'true') && check_title_tags($post->post_content)) {
	echo '<div class="has-toc have-toc"></div>';
}
?>

<article id="post-<?php echo esc_attr($post_id); ?>" <?php post_class(); ?>>
	<?php if (should_show_title()) { 
		get_template_part('tpl/single-entry-header');
	} ?>
	<?php if ($ai_excerpt) { ?>
	<div class="ai-excerpt">
		<h4><i class="fa-solid fa-atom"></i><?php esc_html_e("AI Excerpt", "sakurairo"); ?></h4><?php echo esc_html($ai_excerpt); ?>
	</div>
	<?php } ?>
	<!-- T65：正文容器按文章语言带 lang，`:lang()` 规则据此选地区字形（不改 <html lang>） -->
	<div class="entry-content"<?php echo ybh_post_language_attr(); ?>>
		<?php the_content('', true); ?>
		<?php
			wp_link_pages(array(
				'before' => '<div class="page-links">' . esc_html__('Pages:', 'ondemand'),
				'after'  => '</div>',
			));
		?>
	</div><!-- .entry-content -->
	<?php get_template_part('tpl/section-article-function'); ?>
</article><!-- #post-## -->
