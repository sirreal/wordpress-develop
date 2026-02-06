<?php
/**
 * HTML Templating API - Usage Examples
 *
 * This file documents real patterns from WordPress core that could benefit
 * from the WP_HTML_Template API. These are reference examples for API design.
 *
 * @see Trac #60229 - HTML API: Introduce HTML Templating
 */

die( 'demonstration - not for execution' );

/**
 * =============================================================================
 * PATTERN 1: Translation functions with embedded HTML
 * =============================================================================
 *
 * These patterns have HTML tags directly in the translatable string.
 * Translators see the HTML, which is problematic.
 */

// src/wp-includes/functions.php:1620
// Simple HTML tag in translation
wp_die( __( '<strong>Error:</strong> This is not a valid feed template.' ), '', array( 'response' => 404 ) );

// src/wp-includes/functions.php:1844-1845
// Link with placeholder inside translation
$wpdb->error = sprintf(
	/* translators: %s: Database repair URL. */
	__( 'One or more database tables are unavailable. The database may need to be <a href="%s">repaired</a>.' ),
	'maint/repair.php?referrer=is_blog_installed'
);

// src/wp-admin/edit-form-advanced.php:185
// HTML wrapper injected as sprintf parameter
$messages['post'][9] = sprintf( __( 'Post scheduled for: %s.' ), '<strong>' . $scheduled_date . '</strong>' ) . $scheduled_post_link_html;

// src/wp-admin/revision.php:145-147
// Multiple strong tags for emphasis in help text
$revisions_overview .= '<ul><li>' . __( 'To navigate between revisions, <strong>drag the slider handle left or right</strong> or <strong>use the Previous or Next buttons</strong>.' ) . '</li>';
$revisions_overview .= '<li>' . __( 'Compare two different revisions by <strong>selecting the &#8220;Compare any two revisions&#8221; box</strong> to the side.' ) . '</li>';
$revisions_overview .= '<li>' . __( 'To restore a revision, <strong>click Restore This Revision</strong>.' ) . '</li></ul>';

// src/wp-includes/theme.php:978-979
// Context translation (_x) with HTML error prefix
return new WP_Error(
	'theme_wp_php_incompatible',
	sprintf(
		/* translators: %s: Theme name. */
		_x( '<strong>Error:</strong> Current WordPress and PHP versions do not meet minimum requirements for %s.', 'theme' ),
		$theme->display( 'Name' )
	)
);

// src/wp-includes/widgets/class-wp-widget-text.php:542
// Complex: _e() with embedded link and HTML entities
_e( 'Did you know there is a &#8220;Custom HTML&#8221; widget now? You can find it by pressing the &#8220;<a class="add-widget" href="#">Add a Widget</a>&#8221; button and searching for &#8220;HTML&#8221;. Check it out to add some custom code to your site!' );

// src/wp-includes/blocks/comments-title.php:29
// HTML entities (curly quotes) in translation
$post_title = sprintf( __( '&#8220;%s&#8221;' ), get_the_title() );

// src/wp-includes/blocks/latest-posts.php:164-166
// Complex nested HTML with multiple escaped placeholders
$trimmed_excerpt .= sprintf(
	/* translators: 1: A URL to a post, 2: Hidden accessibility text: Post title */
	__( '… <a class="wp-block-latest-posts__read-more" href="%1$s" rel="noopener noreferrer">Read more<span class="screen-reader-text">: %2$s</span></a>' ),
	esc_url( $post_link ),
	esc_html( $title )
);

// src/wp-admin/includes/class-plugin-upgrader.php:60
// HTML span wrapping another placeholder (double sprintf)
$this->strings['downloading_package'] = sprintf( __( 'Downloading update from %s&#8230;' ), '<span class="code pre">%s</span>' );

// src/wp-admin/includes/privacy-tools.php:404
// Code tag wrapping technical reference
sprintf( __( 'The %s post meta must be an array.' ), '<code>_export_data_grouped</code>' );

// src/wp-admin/widgets.php:24
// Full paragraph with embedded documentation link
wp_die( __( 'The theme you are currently using is not widget-aware, meaning that it has no sidebars that you are able to change. For information on making your theme widget-aware, please <a href="https://developer.wordpress.org/themes/functionality/widgets/">follow these instructions</a>.' ) );

// src/wp-admin/revision.php:158
// Link in translation without placeholders
$revisions_sidebar .= '<p>' . __( '<a href="https://wordpress.org/documentation/article/revisions/">Revisions Management</a>' ) . '</p>';


/**
 * =============================================================================
 * PATTERN 2: sprintf with manual escaping (non-translation)
 * =============================================================================
 *
 * These patterns require developers to choose the correct escape function
 * for each context (esc_url, esc_attr, esc_html).
 */

// src/wp-includes/blocks/post-title.php:41
// Link with multiple escaped attributes
$rel   = ! empty( $attributes['rel'] ) ? 'rel="' . esc_attr( $attributes['rel'] ) . '"' : '';
$title = sprintf(
	'<a href="%1$s" target="%2$s" %3$s>%4$s</a>',
	esc_url( get_the_permalink( $block->context['postId'] ) ),
	esc_attr( $attributes['linkTarget'] ),
	$rel,    // Note: $rel already has esc_attr inside
	$title   // Note: $title escaping unclear
);

// src/wp-includes/blocks/avatar.php:68
// Similar link pattern for avatar
$avatar_block = sprintf(
	'<a href="%1$s" target="%2$s" %3$s class="wp-block-avatar__link">%4$s</a>',
	esc_url( get_author_posts_url( $author_id ) ),
	esc_attr( $attributes['linkTarget'] ),
	$label,        // aria-label attribute, already escaped
	$avatar_block  // Inner HTML
);

// src/wp-includes/formatting.php:3476
// Image tag with esc_url and esc_attr
return sprintf(
	'<img src="%s" alt="%s" class="wp-smiley" style="height: 1em; max-height: 1em;" />',
	esc_url( $src_url ),
	esc_attr( $smiley )
);

// src/wp-includes/class-wp-styles.php:204-211
// Link tag with nested sprintf for conditional attribute
$tag = sprintf(
	"<link rel='%s' id='%s-css'%s href='%s' media='%s' />\n",
	$rel,                                                    // Not escaped
	esc_attr( $handle ),
	$title ? sprintf( " title='%s'", esc_attr( $title ) ) : '',
	$href,                                                   // Not escaped
	esc_attr( $media )
);


/**
 * =============================================================================
 * PATTERN 3: Building aria-label attributes
 * =============================================================================
 */

// src/wp-includes/blocks/avatar.php:65
// Aria label with translation and escaping
$label = 'aria-label="' . esc_attr( sprintf( __( '(%s author archive, opens in a new tab)' ), $author_name ) ) . '"';


/**
 * =============================================================================
 * POTENTIAL TEMPLATING ALTERNATIVES
 * =============================================================================
 *
 * How these patterns could look with WP_HTML_Template.
 */

/*
 * OPTION A: Template wraps translation (structure in code, text translated)
 *
 * HTML structure stays in PHP code, only text content is translated.
 */

// Instead of: __( '<strong>Error:</strong> This is not a valid feed template.' )
WP_HTML_Template::render(
	'<strong></%label></strong> </%message>',
	array(
		'label'   => __( 'Error:' ),
		'message' => __( 'This is not a valid feed template.' ),
	)
);

// Instead of: sprintf( __( 'Post scheduled for: %s.' ), '<strong>' . $date . '</strong>' )
WP_HTML_Template::render(
	__( 'Post scheduled for: %s.', 'template-placeholder' ), // Special handling?
	// Or:
	'</%before><strong></%date></strong></%after>',
	array(
		'before' => __( 'Post scheduled for: ' ),
		'date'   => $scheduled_date,
		'after'  => '',
	)
);

/*
 * OPTION B: Translation contains template placeholders
 *
 * Translated string includes the </%name> syntax directly.
 * This is closer to how WordPress translations work today.
 */

// Instead of: sprintf( __( '... <a href="%s">repaired</a>.' ), $url )
WP_HTML_Template::render(
	__( 'One or more database tables are unavailable. The database may need to be <a href="</%url>">repaired</a>.' ),
	array( 'url' => 'maint/repair.php?referrer=is_blog_installed' )
);

// Instead of: sprintf( __( '... href="%1$s" ... %2$s ...' ), esc_url($url), esc_html($title) )
WP_HTML_Template::render(
	__( '… <a class="wp-block-latest-posts__read-more" href="</%url>" rel="noopener noreferrer">Read more<span class="screen-reader-text">: </%title></span></a>' ),
	array(
		'url'   => $post_link,   // Auto-escaped as URL in href context
		'title' => $title,       // Auto-escaped as text in text context
	)
);
