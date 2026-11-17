<?php
/**
 * Unit tests covering the data_wp_style_processor functionality of the
 * WP_Interactivity_API class.
 *
 * @package WordPress
 * @subpackage Interactivity API
 *
 * @since 6.5.0
 *
 * @coversDefaultClass WP_Interactivity_API
 *
 * @group interactivity-api
 */
class Tests_WP_Interactivity_API_WP_Style extends WP_UnitTestCase {
	/**
	 * Instance of WP_Interactivity_API.
	 *
	 * @var WP_Interactivity_API
	 */
	protected $interactivity;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->interactivity = new WP_Interactivity_API();
		$this->interactivity->state(
			'myPlugin',
			array(
				'green' => 'green',
				'false' => false,
			)
		);
	}

	/**
	 * Invokes the `process_directives` method of WP_Interactivity_API class.
	 *
	 * @param string $html The HTML that needs to be processed.
	 * @return array An array containing an instance of the WP_HTML_Tag_Processor and the processed HTML.
	 */
	private function process_directives( $html ) {
		$new_html = $this->interactivity->process_directives( $html );
		$p        = new WP_HTML_Tag_Processor( $new_html );
		$p->next_tag();
		return array( $p, $new_html );
	}

	/**
	 * Processes a `div` element with one `data-wp-style` entry per property.
	 *
	 * @param string|null $style      Style attribute value, or null for no style attribute.
	 * @param array       $properties Values to evaluate, keyed by property name.
	 * @return string|true|null Style attribute value after processing.
	 */
	private function style_after_directives( ?string $style, array $properties ) {
		$interactivity = new WP_Interactivity_API();
		$state         = array();
		$html          = '<div';
		if ( null !== $style ) {
			$html .= ' style="' . esc_attr( $style ) . '"';
		}
		foreach ( array_keys( $properties ) as $i => $name ) {
			$state[ "p{$i}" ] = $properties[ $name ];
			$html            .= " data-wp-style--{$name}=\"styleTest::state.p{$i}\"";
		}
		$html .= '>Text</div>';

		$interactivity->state( 'styleTest', $state );
		$p = new WP_HTML_Tag_Processor( $interactivity->process_directives( $html ) );
		$p->next_tag();
		return $p->get_attribute( 'style' );
	}

	/**
	 * Tests that the `data-wp-style` directive sets style properties.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_sets_properties() {
		// Adds property on empty style attribute.
		$this->assertSame( 'color: green;', $this->style_after_directives( '', array( 'color' => 'green' ) ) );

		// Changes style property when there is an existing property.
		$this->assertSame( 'color: green;', $this->style_after_directives( 'color:red;', array( 'color' => 'green' ) ) );

		// Adds a new property when the existing one does not match.
		$this->assertSame( 'color:red; background: blue;', $this->style_after_directives( 'color:red;', array( 'background' => 'blue' ) ) );

		// Handles multiple existing properties.
		$this->assertSame( 'margin:5px; color: green;', $this->style_after_directives( 'color:red;margin:5px;', array( 'color' => 'green' ) ) );

		// Adds a new property when multiple existing properties do not match.
		$this->assertSame( 'color:red;margin:5px; padding: 10px;', $this->style_after_directives( 'color:red;margin:5px;', array( 'padding' => '10px' ) ) );

		// Keeps whitespace in existing declarations and trims the new value.
		$this->assertSame( ' color : red; margin : 5px;  padding: 10px;', $this->style_after_directives( ' color : red; margin : 5px; ', array( 'padding' => ' 10px ' ) ) );

		// Updates a property when it's not the first one in the value.
		$this->assertSame( 'color:red; margin: 15px;', $this->style_after_directives( 'color:red;margin:5px;', array( 'margin' => '15px' ) ) );

		// Adds missing trailing semicolon.
		$this->assertSame( 'color:red;margin:5px; padding: 10px;', $this->style_after_directives( 'color:red;margin:5px', array( 'padding' => '10px' ) ) );

		// Doesn't add double semicolons.
		$this->assertSame( 'color:red;margin:5px; padding: 10px;', $this->style_after_directives( 'color:red;margin:5px;', array( 'padding' => '10px;' ) ) );

		// Keeps empty declarations in the input.
		$this->assertSame( 'color:red;;margin:5px;; padding: 10px;', $this->style_after_directives( 'color:red;;margin:5px;;', array( 'padding' => '10px' ) ) );

		// Moves the modified property to the end.
		$this->assertSame( 'border: 3px solid red; border-style: inset;', $this->style_after_directives( 'border-style: dashed; border: 3px solid red;', array( 'border-style' => 'inset' ) ) );
	}

	/**
	 * Tests that the `data-wp-style` directive removes a property, or adds
	 * nothing, for falsy values.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_with_falsy_values() {
		foreach ( array( '', null, false, 0, 0.0, array() ) as $value ) {
			$this->assertSame( 'margin:5px;', $this->style_after_directives( 'color:red;margin:5px;', array( 'color' => $value ) ), 'Removes the property with ' . var_export( $value, true ) );
			$this->assertSame( 'color:red;', $this->style_after_directives( 'color:red;', array( 'padding' => $value ) ), 'Adds nothing with ' . var_export( $value, true ) );
		}
	}

	/**
	 * Tests that the `data-wp-style` directive writes non-falsy scalar values
	 * as strings.
	 *
	 * The string `'0'` sets the property, `true` is written as `true`, and a
	 * number is written as its string.
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_writes_scalar_values_as_strings() {
		$this->assertSame( 'opacity: 0;', $this->style_after_directives( 'opacity:1', array( 'opacity' => '0' ) ) );
		$this->assertSame( '--flag: true;', $this->style_after_directives( null, array( '--flag' => true ) ) );
		$this->assertSame( 'z-index: 5;', $this->style_after_directives( null, array( 'z-index' => 5 ) ) );
		$this->assertSame( 'opacity: 0.5;', $this->style_after_directives( null, array( 'opacity' => 0.5 ) ) );
	}

	/**
	 * Tests that the `data-wp-style` directive removes the property for a value
	 * that is not a string, number or boolean.
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_removes_property_for_non_scalar_values() {
		$this->assertSame( 'margin:5px;', $this->style_after_directives( 'color:red;margin:5px;', array( 'color' => array( 'green' ) ) ) );
		$this->assertSame( 'margin:5px;', $this->style_after_directives( 'color:red;margin:5px;', array( 'color' => (object) array( 'a' => 'green' ) ) ) );
	}

	/**
	 * Tests that the `data-wp-style` directive preserves values containing a colon.
	 *
	 * Only the first colon in a declaration separates the property name from its
	 * value, so a value may contain more of them.
	 *
	 * @ticket 63899
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_preserves_values_containing_colons() {
		// Keeps an untouched property whose value contains a URL.
		$this->assertSame(
			'background-image:url(https://example.com/a.png); color: green;',
			$this->style_after_directives( 'background-image:url(https://example.com/a.png)', array( 'color' => 'green' ) )
		);

		// Replaces a property whose previous value contained a URL.
		$this->assertSame(
			'background-image: none;',
			$this->style_after_directives( 'background-image:url(https://example.com/a.png)', array( 'background-image' => 'none' ) )
		);

		// Keeps every colon after the first, not only the second.
		$this->assertSame(
			'--fallback:url(https://a.test/x.png), url(https://b.test/y.png); color: green;',
			$this->style_after_directives( '--fallback:url(https://a.test/x.png), url(https://b.test/y.png);', array( 'color' => 'green' ) )
		);
	}

	/**
	 * Tests that the `data-wp-style` directive preserves values containing a
	 * semicolon.
	 *
	 * A semicolon inside a quoted string or a `url()` does not end a declaration.
	 *
	 * @ticket 63899
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_preserves_values_containing_semicolons() {
		// Keeps a quoted value containing a semicolon.
		$this->assertSame(
			'font-family:"Foo;Bar",serif; color: green;',
			$this->style_after_directives( 'font-family:"Foo;Bar",serif', array( 'color' => 'green' ) )
		);

		// Keeps a data URI, which contains both a colon and a semicolon.
		$this->assertSame(
			'background-image:url(data:image/gif;base64,R0lGODlhAQ); color: green;',
			$this->style_after_directives( 'background-image:url(data:image/gif;base64,R0lGODlhAQ)', array( 'color' => 'green' ) )
		);

		// Replaces a property whose value contains a quoted semicolon.
		$this->assertSame(
			'color:red; font-family: serif;',
			$this->style_after_directives( 'font-family:"Foo;Bar",serif;color:red', array( 'font-family' => 'serif' ) )
		);
	}

	/**
	 * Tests that the `data-wp-style` directive sets a value containing a
	 * semicolon inside a string or a `url()`.
	 *
	 * The appended value is written from its tokens, which escapes a semicolon
	 * in a string and a semicolon or comma in a `url()`.
	 *
	 * @ticket 63899
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_sets_values_containing_semicolons() {
		$this->assertSame(
			'color:red; font-family: "Foo\\3b Bar", serif;',
			$this->style_after_directives( 'color:red', array( 'font-family' => '"Foo;Bar", serif' ) )
		);

		$this->assertSame(
			'background-image: url(data:image/gif\\3b base64\\2c R0lGODlhAQ);',
			$this->style_after_directives( null, array( 'background-image' => 'url(data:image/gif;base64,R0lGODlhAQ)' ) )
		);
	}

	/**
	 * Tests that the `data-wp-style` directive keeps segments that contain no
	 * colon.
	 *
	 * @ticket 63899
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_handles_segments_without_a_colon() {
		$this->assertSame(
			'translate:none;translate3d(0px, 0px, 0px); color: green;',
			$this->style_after_directives( 'translate:none;translate3d(0px, 0px, 0px);', array( 'color' => 'green' ) )
		);
	}

	/**
	 * Tests that the `data-wp-style` directive matches property names as CSS
	 * does.
	 *
	 * @ticket 63899
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_matches_property_names() {
		// Replaces a standard property written in any case.
		$this->assertSame( 'margin:5px; color: green;', $this->style_after_directives( 'COLOR:red;margin:5px;', array( 'color' => 'green' ) ) );

		// Replaces only the custom property with the same case.
		$this->assertSame( '--MyVar:1; --myvar: 3;', $this->style_after_directives( '--MyVar:1;--myvar:2;', array( '--myvar' => '3' ) ) );

		// Removes every declaration of the property.
		$this->assertSame( 'margin:5px; color: green;', $this->style_after_directives( 'color:red;margin:5px;color:blue;', array( 'color' => 'green' ) ) );
	}

	/**
	 * Tests that the `data-wp-style` directive replaces a declaration with an
	 * `!important` priority.
	 *
	 * @ticket 63899
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_replaces_important_declaration() {
		$this->assertSame( 'margin:5px; color: blue;', $this->style_after_directives( 'color: red !important; margin:5px;', array( 'color' => 'blue' ) ) );
		$this->assertSame( 'margin:5px;', $this->style_after_directives( 'color: red !important; margin:5px;', array( 'color' => false ) ) );
	}

	/**
	 * Tests that the `data-wp-style` directive applies several entries on one
	 * element: it replaces, removes and adds properties in one style attribute.
	 *
	 * @ticket 63899
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_applies_several_entries() {
		$this->assertSame(
			'margin: 0 auto; padding: 1em; width: 50%; background: url(https://example.com/b.png); color: blue;',
			$this->style_after_directives(
				'color: red; margin: 0 auto; background: url(https://example.com/a.png); padding: 1em; height: 10px; width: 50%',
				array(
					'color'      => 'blue',
					'height'     => false,
					'background' => 'url(https://example.com/b.png)',
				)
			)
		);
	}

	/**
	 * Tests that the `data-wp-style` directive removes the property and adds
	 * nothing when the new value is not a valid declaration value.
	 *
	 * @ticket 63899
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_rejects_invalid_values() {
		// A value with a semicolon outside a string or block.
		$this->assertSame( 'margin:5px;', $this->style_after_directives( 'color:red;margin:5px;', array( 'color' => 'green; background: blue' ) ) );

		// A value with an unclosed function.
		$this->assertSame( 'margin:5px;', $this->style_after_directives( 'color:red;margin:5px;', array( 'color' => 'rgb(0 0 0' ) ) );

		// A value with an !important priority.
		$this->assertSame( 'margin:5px;', $this->style_after_directives( 'color:red;margin:5px;', array( 'color' => 'green !important' ) ) );

		// A value with a closing brace.
		$this->assertSame( 'margin:5px;', $this->style_after_directives( 'color:red;margin:5px;', array( 'color' => 'green}' ) ) );
	}

	/**
	 * Tests that the `data-wp-style` directive keeps the style attribute text
	 * when no declaration changes.
	 *
	 * @ticket 63899
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_keeps_style_attribute_text_when_unchanged() {
		$this->assertSame( ' padding : 10px ;; ', $this->style_after_directives( ' padding : 10px ;; ', array( 'color' => false ) ) );
	}

	/**
	 * Tests that the `data-wp-style` directive sets a style attribute with
	 * correct property and value.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_sets_style_attribute() {
		$html    = '<div data-wp-style--color="myPlugin::state.green">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertSame( 'color: green;', $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive applies multiple style properties
	 * correctly.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_sets_multiple_style_properties() {
		$html    = '
			<div
				data-wp-style--color="myPlugin::state.green"
				data-wp-style--background="myPlugin::state.green"
			>Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertSame( 'background: green; color: green;', $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive correctly handles different style
	 * property values.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_sets_multiple_style_properties_with_different_values() {
		$html    = '
			<div
				data-wp-style--color="myPlugin::state.green"
				data-wp-style--background="myPlugin::state.false"
			>Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertSame( 'color: green;', $p->get_attribute( 'style' ) );

		$html    = '
			<div
				style="background:red;"
				data-wp-style--color="myPlugin::state.green"
				data-wp-style--background="myPlugin::state.false"
			>Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertSame( 'color: green;', $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive sets a new style property when
	 * another already exists.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_sets_style_property_when_style_attribute_exists() {
		$html    = '<div style="padding:10px;" data-wp-style--color="myPlugin::state.green">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertSame( 'padding:10px; color: green;', $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive overwrites an existing style
	 * property with a new value.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_overwrites_style_property_when_style_property_exists() {
		$html    = '<div style="color:red;" data-wp-style--color="myPlugin::state.green">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertSame( 'color: green;', $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive doesn't add a style property when
	 * the directive value is false.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_doesnt_add_style_attribute_on_false() {
		$html    = '<div data-wp-style--color="myPlugin::state.false">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertNull( $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive doesn't modify existing style
	 * properties when directive value is false.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_doesnt_add_style_property_on_false() {
		$html    = '<div style="padding:10px;" data-wp-style--color="myPlugin::state.false">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertSame( 'padding:10px;', $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive keeps an existing style property
	 * with a matching value.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_keeps_style_property_when_style_property_exists() {
		$html    = '<div style="color:green;" data-wp-style--color="myPlugin::state.green">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertSame( 'color: green;', $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive maintains style properties even
	 * when they aren't the only ones present.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_keeps_style_property_when_style_property_exists_and_is_not_the_only_one() {
		$html    = '<div style="padding:10px;color:green;" data-wp-style--color="myPlugin::state.green">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertSame( 'padding:10px; color: green;', $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive removes the style attribute when
	 * it contains only one property which is being removed.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_removes_style_attribute_when_style_property_exists_and_is_the_only_one() {
		$html    = '<div style="color:green;" data-wp-style--color="myPlugin::state.false">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertNull( $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive removes a style property when it's
	 * not the only one present and the directive value is false.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_removes_style_property_when_style_property_exists_and_is_not_the_only_one() {
		$html    = '<div style="padding:10px;color:green;" data-wp-style--color="myPlugin::state.false">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertSame( 'padding:10px;', $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive does not remove an empty style
	 * attribute.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_doesnt_remove_empty_style_attribute() {
		$html    = '<div style data-wp-style--color="myPlugin::state.false">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertTrue( $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive does not change the style
	 * attribute when the directive suffix is empty.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_doesnt_change_style_attribute_with_empty_directive_suffix() {
		$html    = '<div style="padding:10px;" data-wp-style="myPlugin::state.green">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertSame( 'padding:10px;', $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive does not change the style
	 * attribute when the value of the directive is empty.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 * @expectedIncorrectUsage WP_Interactivity_API::evaluate
	 */
	public function test_wp_style_doesnt_change_style_attribute_with_empty_value() {
		$html    = '<div style="padding:10px" data-wp-style--color="">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertSame( 'padding:10px', $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive doesn't apply changes if no value
	 * is provided for the style property.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 * @expectedIncorrectUsage WP_Interactivity_API::evaluate
	 */
	public function test_wp_style_doesnt_change_style_attribute_without_value() {
		$html    = '<div style="padding: 10px;" data-wp-style--color>Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertSame( 'padding: 10px;', $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive functions correctly with multiple
	 * identical directives.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_works_with_multiple_directives() {
		$html    = '<div data-wp-style--color="myPlugin::state.green" data-wp-style--color="myPlugin::state.green">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertSame( 'color: green;', $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive doesn't apply any changes when the
	 * state value is true.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_doesnt_do_anything_on_true_values() {
		$this->interactivity->state( 'myPlugin', array( 'true' => true ) );
		$html    = '<div data-wp-style--color="myPlugin::state.text">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertNull( $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests that the `data-wp-style` directive doesn't add a style property for
	 * various falsy values in the state.
	 *
	 * @ticket 60356
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_doesnt_add_style_property_on_falsy_values() {
		$this->interactivity->state( 'myPlugin', array( 'text' => '' ) );
		$html    = '<div data-wp-style--color="myPlugin::state.text">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertNull( $p->get_attribute( 'style' ) );

		$this->interactivity->state( 'myPlugin', array( 'array' => array() ) );
		$html    = '<div data-wp-style--color="myPlugin::state.array">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertNull( $p->get_attribute( 'style' ) );

		$this->interactivity->state( 'myPlugin', array( 'number' => 0 ) );
		$html    = '<div data-wp-style--color="myPlugin::state.number">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertNull( $p->get_attribute( 'style' ) );

		$this->interactivity->state( 'myPlugin', array( 'null' => null ) );
		$html    = '<div data-wp-style--color="myPlugin::state.null">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertNull( $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests it can use CSS variables.
	 *
	 * @ticket 64106
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_can_use_CSS_variables() {
		$html    = '<div data-wp-style----text-color="myPlugin::state.green">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertSame( '--text-color: green;', $p->get_attribute( 'style' ) );
	}

	/**
	 * Tests it ignores unique IDs.
	 *
	 * @ticket 64106
	 *
	 * @covers ::process_directives
	 */
	public function test_wp_style_ignores_unique_ids() {
		$html    = '<div data-wp-style--color---unique-id="myPlugin::state.green">Text</div>';
		list($p) = $this->process_directives( $html );
		$this->assertNull( $p->get_attribute( 'style' ) );
	}
}
