<?php

/**
 * Test some helper utility functions of the test framework.
 *
 * @group testsuite
 */
class Tests_Utils extends WP_UnitTestCase {

	/**
	 * @covers ::strip_ws
	 */
	public function test_strip_ws() {
		$this->assertSame( '', strip_ws( '' ) );
		$this->assertSame( 'foo', strip_ws( 'foo' ) );
		$this->assertSame( '', strip_ws( "\r\n\t  \n\r\t" ) );

		$in  = "asdf\n";
		$in .= "asdf asdf\n";
		$in .= "asdf     asdf\n";
		$in .= "\tasdf\n";
		$in .= "\tasdf\t\n";
		$in .= "\t\tasdf\n";
		$in .= "foo bar\n\r\n";
		$in .= "foo\n";

		$expected  = "asdf\n";
		$expected .= "asdf asdf\n";
		$expected .= "asdf     asdf\n";
		$expected .= "asdf\n";
		$expected .= "asdf\n";
		$expected .= "asdf\n";
		$expected .= "foo bar\n";
		$expected .= 'foo';

		$this->assertSame( $expected, strip_ws( $in ) );
	}

	/**
	 * @covers ::mask_input_value
	 */
	public function test_mask_input_value() {
		$in = <<<EOF
<h2>Assign Authors</h2>
<p>To make it easier for you to edit and save the imported posts and drafts, you may want to change the name of the author of the posts. For example, you may want to import all the entries as <code>admin</code>s entries.</p>
<p>If a new user is created by WordPress, the password will be set, by default, to "changeme". Quite suggestive, eh? ;)</p>
        <ol id="authors"><form action="?import=wordpress&amp;step=2&amp;id=" method="post"><input type="hidden" name="_wpnonce" value="855ae98911" /><input type="hidden" name="_wp_http_referer" value="wp-test.php" /><li>Current author: <strong>Alex Shiels</strong><br />Create user  <input type="text" value="Alex Shiels" name="user[]" maxlength="30"> <br /> or map to existing<select name="userselect[0]">
EOF;
		// _wpnonce value should be replaced with 'xxx'.
		$expected = <<<EOF
<h2>Assign Authors</h2>
<p>To make it easier for you to edit and save the imported posts and drafts, you may want to change the name of the author of the posts. For example, you may want to import all the entries as <code>admin</code>s entries.</p>
<p>If a new user is created by WordPress, the password will be set, by default, to "changeme". Quite suggestive, eh? ;)</p>
        <ol id="authors"><form action="?import=wordpress&amp;step=2&amp;id=" method="post"><input type="hidden" name="_wpnonce" value="***" /><input type="hidden" name="_wp_http_referer" value="wp-test.php" /><li>Current author: <strong>Alex Shiels</strong><br />Create user  <input type="text" value="Alex Shiels" name="user[]" maxlength="30"> <br /> or map to existing<select name="userselect[0]">
EOF;
		$this->assertSame( $expected, mask_input_value( $in ) );
	}

	/**
	 * @covers WP_UnitTestCase_Base::reset_core_registrations
	 */
	public function test_core_registration_snapshot_restore_uses_clean_clones() {
		self::$core_registration_snapshots = array();

		$this->reset_core_registrations();

		$GLOBALS['wp_post_types']['post']->labels->name     = 'Mutated Posts';
		$GLOBALS['wp_taxonomies']['category']->labels->name = 'Mutated Categories';
		$GLOBALS['_wp_post_type_features']['post']['title'] = false;

		$this->reset_core_registrations();

		$this->assertNotSame( 'Mutated Posts', get_post_type_object( 'post' )->labels->name );
		$this->assertNotSame( 'Mutated Categories', get_taxonomy( 'category' )->labels->name );
		$this->assertNotFalse( $GLOBALS['_wp_post_type_features']['post']['title'] );
	}
}

/**
 * Tests registration reset behavior that must happen before parent setup.
 *
 * @group testsuite
 */
class Tests_Utils_Core_Registration_Reset extends WP_UnitTestCase {

	/**
	 * Primes the registration snapshot before this class adds label filters
	 * ahead of parent setup.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$core_registration_snapshots = array();

		$testcase = new self( 'test_core_registration_reset_cache_is_bypassed_for_label_filters_before_parent_setup' );
		$testcase->reset_core_registrations();
	}

	/**
	 * Adds label filters before the base setup reset runs.
	 */
	public function set_up() {
		if ( ! self::$hooks_saved ) {
			$this->_backup_hooks();
		}

		add_filter( 'post_type_labels_post', array( $this, 'filter_post_type_labels' ) );
		add_filter( 'taxonomy_labels_category', array( $this, 'filter_taxonomy_labels' ) );

		parent::set_up();

		remove_filter( 'post_type_labels_post', array( $this, 'filter_post_type_labels' ) );
		remove_filter( 'taxonomy_labels_category', array( $this, 'filter_taxonomy_labels' ) );
	}

	/**
	 * Removes filters after parent teardown restores the saved hooks.
	 */
	public function tear_down() {
		parent::tear_down();

		remove_filter( 'post_type_labels_post', array( $this, 'filter_post_type_labels' ) );
		remove_filter( 'taxonomy_labels_category', array( $this, 'filter_taxonomy_labels' ) );
	}

	/**
	 * @covers WP_UnitTestCase_Base::reset_core_registrations
	 */
	public function test_core_registration_reset_cache_is_bypassed_for_label_filters_before_parent_setup() {
		$this->assertSame( 'Filtered Posts', get_post_type_object( 'post' )->labels->name );
		$this->assertSame( 'Filtered Categories', get_taxonomy( 'category' )->labels->name );
	}

	/**
	 * Filters core post type labels.
	 *
	 * @param object $labels Post type labels.
	 * @return object Filtered labels.
	 */
	public function filter_post_type_labels( $labels ) {
		$labels->name = 'Filtered Posts';

		return $labels;
	}

	/**
	 * Filters core taxonomy labels.
	 *
	 * @param object $labels Taxonomy labels.
	 * @return object Filtered labels.
	 */
	public function filter_taxonomy_labels( $labels ) {
		$labels->name = 'Filtered Categories';

		return $labels;
	}
}
