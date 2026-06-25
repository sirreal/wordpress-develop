<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes no-DB Interactivity API directive processing and data helpers.
 */
final class InteractivitySurface {
	public const NAME = 'interactivity';

	private const CASES = 10;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'interactivity.bootstrap-apis-available',
					'Required WordPress Interactivity APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$ob_level = ob_get_level();
		$rows     = array();

		try {
			$case = self::case_for_context( $ctx );

			$rows[] = self::check_state_config_helpers( $ctx, $case );
			$rows[] = self::check_directive_processing( $ctx, $case );
			$rows[] = self::check_namespaced_directive_evaluation( $ctx->fork( 'namespaced-directives' ), $case );
			$rows[] = self::check_context_and_element_helpers( $ctx, $case );
			$rows[] = self::check_context_namespace_stack_merge_sort_and_restore( $ctx->fork( 'context-stack' ), $case );
			$rows[] = self::check_derived_state_stack_recovery( $ctx->fork( 'derived-stack' ), $case );
			$rows[] = self::check_script_module_hooks( $ctx->fork( 'script-module-hooks' ), $case );
			$rows[] = self::check_each_edge_cases( $ctx->fork( 'each-edge-cases' ), $case );
			$rows[] = self::check_router_region( $ctx, $case );
			$rows[] = self::check_unbalanced_and_unsupported_fallbacks( $ctx, $case );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'interactivity.surface-no-throw',
				array(
					'throwable' => self::describe_throwable( $e ),
				)
			);
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
			self::restore_state( $snapshot );
		}

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'WP_HTML_Tag_Processor',
				'WP_Interactivity_API',
				'WP_Interactivity_API_Directives_Processor',
			) as $class
		) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'wp_interactivity',
				'wp_interactivity_process_directives',
				'wp_interactivity_state',
				'wp_interactivity_config',
				'wp_interactivity_data_wp_context',
				'wp_interactivity_get_context',
				'wp_interactivity_get_element',
				'wp_json_encode',
				'add_action',
				'add_filter',
				'get_self_link',
				'esc_attr',
				'esc_html',
				'has_action',
				'has_filter',
				'do_action',
				'remove_action',
				'remove_filter',
				'wp_add_inline_style',
				'wp_enqueue_style',
				'wp_register_style',
				'wp_styles',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_state_config_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$api = self::install_fresh_api();

		$state_first = array(
			'nested' => array(
				'kept'     => $case['stateKept'],
				'replaced' => 'old',
			),
			'list'   => array( 'first', 'second' ),
			'scalar' => 'before',
		);
		$state_next  = array(
			'nested' => array(
				'replaced' => $case['stateReplacement'],
				'added'    => $case['stateAdded'],
			),
			'list'   => array( 1 => $case['stateListReplacement'] ),
			'scalar' => array( 'after' => $case['stateScalarAfter'] ),
		);

		$config_first = array(
			'flags' => array(
				'enabled' => false,
				'stable'  => $case['configStable'],
			),
			'limits' => array(
				'page' => 10,
			),
		);
		$config_next  = array(
			'flags' => array(
				'enabled' => true,
			),
			'limits' => array(
				'page' => $case['configLimit'],
			),
		);

		$initial_state = \wp_interactivity_state( $case['namespace'], $state_first );
		$merged_state  = \wp_interactivity_state( $case['namespace'], $state_next );
		$read_state    = \wp_interactivity_state( $case['namespace'] );
		$other_state   = \wp_interactivity_state( $case['otherNamespace'], array( 'value' => $case['otherValue'] ) );

		$initial_config = \wp_interactivity_config( $case['namespace'], $config_first );
		$merged_config  = \wp_interactivity_config( $case['namespace'], $config_next );
		$read_config    = \wp_interactivity_config( $case['namespace'] );
		$client_data    = $api->filter_script_module_interactivity_data( array( 'existing' => true ) );

		$expected_state  = array_replace_recursive( $state_first, $state_next );
		$expected_config = array_replace_recursive( $config_first, $config_next );
		$context_attr    = \wp_interactivity_data_wp_context( $case['context'], $case['namespace'] );

		$ok = $initial_state === $state_first
			&& $merged_state === $expected_state
			&& $read_state === $expected_state
			&& array( 'value' => $case['otherValue'] ) === $other_state
			&& $initial_config === $config_first
			&& $merged_config === $expected_config
			&& $read_config === $expected_config
			&& isset( $client_data['state'][ $case['namespace'] ], $client_data['config'][ $case['namespace'] ] )
			&& $client_data['state'][ $case['namespace'] ] === $expected_state
			&& $client_data['config'][ $case['namespace'] ] === $expected_config
			&& str_starts_with( $context_attr, "data-wp-context='" . $case['namespace'] . '::' )
			&& ! str_contains( $context_attr, '<' )
			&& ! str_contains( $context_attr, '>' )
			&& ! str_contains( $context_attr, '&' );

		return self::result(
			$ctx,
			'interactivity.state-config.recursive-merge-and-client-data',
			$ok,
			array(
				'namespace'      => $case['namespace'],
				'initialState'   => $initial_state,
				'mergedState'    => $merged_state,
				'expectedState'  => $expected_state,
				'otherState'     => $other_state,
				'initialConfig'  => $initial_config,
				'mergedConfig'   => $merged_config,
				'expectedConfig' => $expected_config,
				'clientKeys'     => array_keys( $client_data ),
				'contextAttr'    => $context_attr,
			)
		);
	}

	private static function check_directive_processing( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::install_fresh_api();
		\wp_interactivity_state( $case['namespace'], $case['directiveState'] );

		$html        = self::directive_html( $case );
		$processed   = \wp_interactivity_process_directives( $html );
		$target      = self::find_first_tag_by_attribute( $processed, 'data-case', 'target' );
		$context     = self::find_element_body( $processed, 'span', 'data-case', 'context' );
		$target_body = self::find_element_body( $processed, 'a', 'data-case', 'target' );
		$items       = self::find_each_children( $processed, $case['namespace'] . '::state.items' );

		$target_style = is_array( $target ) ? self::parse_style( (string) ( $target['attributes']['style'] ?? '' ) ) : array();
		$item_failures = array();
		foreach ( $case['directiveState']['items'] as $index => $item ) {
			$rendered = $items[ $index ] ?? null;
			$class    = is_array( $rendered ) ? (string) ( $rendered['attributes']['class'] ?? '' ) : '';
			self::collect_failure(
				$item_failures,
				is_array( $rendered )
					&& esc_html( $item['title'] ) === $rendered['body']
					&& self::class_present( $class, 'selected' ) === (bool) $item['active'],
				"data-wp-each rendered item {$index}",
				array(
					'item'     => $item,
					'rendered' => $rendered,
				)
			);
		}

		$ok = is_array( $target )
			&& $target_body === esc_html( $case['directiveState']['text'] )
			&& $context === esc_html( $case['context']['localText'] )
			&& $case['directiveState']['href'] === ( $target['attributes']['href'] ?? null )
			&& ! array_key_exists( 'hidden', $target['attributes'] )
			&& ( $case['directiveState']['ariaOpen'] ? 'true' : 'false' ) === ( $target['attributes']['aria-expanded'] ?? null )
			&& ( $case['directiveState']['isActive'] ? 'true' : 'false' ) === ( $target['attributes']['data-active'] ?? null )
			&& self::class_present( (string) ( $target['attributes']['class'] ?? '' ), $case['baseClass'] )
			&& self::class_present( (string) ( $target['attributes']['class'] ?? '' ), $case['activeClass'] )
			&& self::class_present( (string) ( $target['attributes']['class'] ?? '' ), $case['uniqueClass'] . '---' . $case['uniqueId'] )
			&& ! self::class_present( (string) ( $target['attributes']['class'] ?? '' ), $case['staleClass'] )
			&& ( $target_style['color'] ?? null ) === $case['directiveState']['color']
			&& ( $target_style['background-color'] ?? null ) === $case['directiveState']['background']
			&& ( $target_style['border-color'] ?? null ) === $case['initialBorderColor']
			&& ! array_key_exists( 'margin', $target_style )
			&& count( $items ) === count( $case['directiveState']['items'] )
			&& array() === $item_failures
			&& 1 === substr_count( $processed, '<template ' )
			&& str_contains( $processed, 'data-wp-each--entry="state.items"' );

		return self::result(
			$ctx,
			'interactivity.directives.process-bind-class-style-text-context-each',
			$ok,
			array(
				'namespace'    => $case['namespace'],
				'input'        => self::preview( $html ),
				'processed'    => self::preview( $processed ),
				'target'       => $target,
				'targetBody'   => $target_body,
				'targetStyle'  => $target_style,
				'contextBody'  => $context,
				'items'        => $items,
				'itemFailures' => array_slice( $item_failures, 0, 5 ),
			)
		);
	}

	private static function check_namespaced_directive_evaluation( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::install_fresh_api();

		$primary_items = array(
			'first-' . self::safe_token( $ctx, 'first' ),
			'second-' . self::safe_token( $ctx, 'second' ),
			'third-' . self::safe_token( $ctx, 'third' ),
		);
		$primary_text  = 'Primary <' . self::safe_token( $ctx, 'primary' ) . '> & value';
		$other_text    = 'Other <' . self::safe_token( $ctx, 'other-text' ) . '> & value';
		$local_text    = 'Local <' . self::safe_token( $ctx, 'local' ) . '> & value';

		\wp_interactivity_state(
			$case['namespace'],
			array(
				'hide'        => true,
				'dataFlag'    => false,
				'emptyString' => '',
				'items'       => $primary_items,
				'text'        => $primary_text,
			)
		);
		\wp_interactivity_state(
			$case['otherNamespace'],
			array(
				'text'    => $other_text,
				'visible' => false,
			)
		);

		$interactive = \esc_attr( \wp_json_encode( array( 'namespace' => $case['namespace'] ) ) );
		$html        = '<div data-wp-interactive="' . $interactive . '" '
			. \wp_interactivity_data_wp_context( array( 'local' => $local_text ), $case['namespace'] )
			. '>'
			. '<button data-case="eval" hidden'
			. ' data-wp-bind--hidden="!state.hide"'
			. ' data-wp-bind--data-flag="state.dataFlag"'
			. ' data-wp-bind--data-empty="state.emptyString"'
			. ' data-wp-text="state.items.length">old count</button>'
			. '<span data-case="other"'
			. ' data-wp-bind--aria-hidden="' . \esc_attr( $case['otherNamespace'] . '::!state.visible' ) . '"'
			. ' data-wp-text="' . \esc_attr( $case['otherNamespace'] . '::state.text' ) . '">old other</span>'
			. '<span data-case="local" data-wp-text="context.local">old local</span>'
			. '<span data-case="string-length" data-wp-text="state.text.length">old string length</span>'
			. '</div>';

		$processed     = \wp_interactivity_process_directives( $html );
		$eval_tag      = self::find_first_tag_by_attribute( $processed, 'data-case', 'eval' );
		$other_tag     = self::find_first_tag_by_attribute( $processed, 'data-case', 'other' );
		$eval_body     = self::find_element_body( $processed, 'button', 'data-case', 'eval' );
		$other_body    = self::find_element_body( $processed, 'span', 'data-case', 'other' );
		$local_body    = self::find_element_body( $processed, 'span', 'data-case', 'local' );
		$length_body   = self::find_element_body( $processed, 'span', 'data-case', 'string-length' );
		$eval_attrs    = is_array( $eval_tag ) ? $eval_tag['attributes'] : array();
		$other_attrs   = is_array( $other_tag ) ? $other_tag['attributes'] : array();
		$expected_body = (string) count( $primary_items );

		$ok = $expected_body === $eval_body
			&& ! array_key_exists( 'hidden', $eval_attrs )
			&& 'false' === ( $eval_attrs['data-flag'] ?? null )
			&& array_key_exists( 'data-empty', $eval_attrs )
			&& '' === $eval_attrs['data-empty']
			&& 'true' === ( $other_attrs['aria-hidden'] ?? null )
			&& \esc_html( $other_text ) === $other_body
			&& \esc_html( $local_text ) === $local_body
			&& (string) strlen( $primary_text ) === $length_body;

		return self::result(
			$ctx,
			'interactivity.directives.namespace-negation-length-and-boolean-bindings',
			$ok,
			array(
				'namespace'      => $case['namespace'],
				'otherNamespace' => $case['otherNamespace'],
				'processed'      => self::preview( $processed ),
				'evalAttrs'      => $eval_attrs,
				'otherAttrs'     => $other_attrs,
				'evalBody'       => $eval_body,
				'otherBody'      => $other_body,
				'localBody'      => $local_body,
				'lengthBody'     => $length_body,
			)
		);
	}

	private static function check_context_namespace_stack_merge_sort_and_restore(
		\ComponentFuzz\FuzzContext $ctx,
		array $case
	): array {
		self::install_fresh_api();

		$parent_context = array(
			'outer'  => 'outer-' . self::safe_token( $ctx, 'outer' ),
			'order'  => 'parent-' . self::safe_token( $ctx, 'order' ),
			'nested' => array(
				'keep'    => 'keep-' . self::safe_token( $ctx, 'keep' ),
				'replace' => 'parent-replace-' . self::safe_token( $ctx, 'parent-replace' ),
			),
		);
		$alpha_context  = array(
			'order'  => 'alpha-' . self::safe_token( $ctx, 'alpha-order' ),
			'nested' => array(
				'replace' => 'alpha-replace-' . self::safe_token( $ctx, 'alpha-replace' ),
				'added'   => 'alpha-added-' . self::safe_token( $ctx, 'alpha-added' ),
			),
		);
		$omega_context  = array(
			'order'  => 'omega-' . self::safe_token( $ctx, 'omega-order' ),
			'nested' => array(
				'replace' => 'omega-replace-' . self::safe_token( $ctx, 'omega-replace' ),
			),
		);
		$other_context  = array(
			'marker' => 'other-marker-' . self::safe_token( $ctx, 'other-marker' ),
		);
		$other_default  = array(
			'local' => 'other-default-' . self::safe_token( $ctx, 'other-default' ),
		);

		$html = '<section data-wp-interactive="' . \esc_attr( \wp_json_encode( array( 'namespace' => $case['namespace'] ) ) ) . '" '
			. \wp_interactivity_data_wp_context( $parent_context, $case['namespace'] )
			. '>'
			. '<span data-case="parent"'
			. ' data-wp-bind--data-order="context.order"'
			. ' data-wp-bind--data-keep="context.nested.keep"'
			. ' data-wp-bind--data-added="context.nested.added"'
			. '></span>'
			. '<div data-case="merged"'
			. ' ' . self::context_attribute( 'data-wp-context---omega', $omega_context )
			. ' ' . self::context_attribute( 'data-wp-context---alpha', $alpha_context )
			. ' ' . self::context_attribute( 'data-wp-context---other', $other_context, $case['otherNamespace'] )
			. ' data-wp-bind--data-order="context.order"'
			. ' data-wp-bind--data-keep="context.nested.keep"'
			. ' data-wp-bind--data-replace="context.nested.replace"'
			. ' data-wp-bind--data-added="context.nested.added"'
			. ' data-wp-bind--data-other="' . \esc_attr( $case['otherNamespace'] . '::context.marker' ) . '"'
			. '></div>'
			. '<div data-wp-interactive="' . \esc_attr( $case['otherNamespace'] ) . '" '
			. \wp_interactivity_data_wp_context( $other_default )
			. '><span data-case="other-default"'
			. ' data-wp-bind--data-local="context.local"'
			. ' data-wp-bind--data-primary="' . \esc_attr( $case['namespace'] . '::context.order' ) . '"'
			. '></span></div>'
			. '<span data-case="after"'
			. ' data-wp-bind--data-order="context.order"'
			. ' data-wp-bind--data-replace="context.nested.replace"'
			. ' data-wp-bind--data-added="context.nested.added"'
			. ' data-wp-bind--data-other="' . \esc_attr( $case['otherNamespace'] . '::context.marker' ) . '"'
			. '></span>'
			. '</section>';

		$processed     = \wp_interactivity_process_directives( $html );
		$parent        = self::find_first_tag_by_attribute( $processed, 'data-case', 'parent' );
		$merged        = self::find_first_tag_by_attribute( $processed, 'data-case', 'merged' );
		$other_default_tag = self::find_first_tag_by_attribute( $processed, 'data-case', 'other-default' );
		$after         = self::find_first_tag_by_attribute( $processed, 'data-case', 'after' );
		$parent_attrs  = is_array( $parent ) ? $parent['attributes'] : array();
		$merged_attrs  = is_array( $merged ) ? $merged['attributes'] : array();
		$other_attrs   = is_array( $other_default_tag ) ? $other_default_tag['attributes'] : array();
		$after_attrs   = is_array( $after ) ? $after['attributes'] : array();

		$ok = $parent_context['order'] === ( $parent_attrs['data-order'] ?? null )
			&& $parent_context['nested']['keep'] === ( $parent_attrs['data-keep'] ?? null )
			&& ! array_key_exists( 'data-added', $parent_attrs )
			&& $omega_context['order'] === ( $merged_attrs['data-order'] ?? null )
			&& $parent_context['nested']['keep'] === ( $merged_attrs['data-keep'] ?? null )
			&& $omega_context['nested']['replace'] === ( $merged_attrs['data-replace'] ?? null )
			&& $alpha_context['nested']['added'] === ( $merged_attrs['data-added'] ?? null )
			&& $other_context['marker'] === ( $merged_attrs['data-other'] ?? null )
			&& $other_default['local'] === ( $other_attrs['data-local'] ?? null )
			&& $parent_context['order'] === ( $other_attrs['data-primary'] ?? null )
			&& $parent_context['order'] === ( $after_attrs['data-order'] ?? null )
			&& $parent_context['nested']['replace'] === ( $after_attrs['data-replace'] ?? null )
			&& ! array_key_exists( 'data-added', $after_attrs )
			&& ! array_key_exists( 'data-other', $after_attrs );

		return self::result(
			$ctx,
			'interactivity.context.namespace-stack-merge-sort-and-restore',
			$ok,
			array(
				'namespace'      => $case['namespace'],
				'otherNamespace' => $case['otherNamespace'],
				'processed'      => self::preview( $processed ),
				'parentAttrs'    => $parent_attrs,
				'mergedAttrs'    => $merged_attrs,
				'otherAttrs'     => $other_attrs,
				'afterAttrs'     => $after_attrs,
				'expected'       => array(
					'parent'       => $parent_context,
					'alpha'        => $alpha_context,
					'omega'        => $omega_context,
					'other'        => $other_context,
					'otherDefault' => $other_default,
				),
			)
		);
	}

	private static function check_derived_state_stack_recovery( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$api             = self::install_fresh_api();
		$primary_context = array(
			'local' => 'Primary context <' . self::safe_token( $ctx, 'primary-context' ) . '> & value',
		);
		$nested_context  = array(
			'local' => 'Nested context <' . self::safe_token( $ctx, 'nested-context' ) . '> & value',
		);
		$primary_state   = 'primary-state-' . self::safe_token( $ctx, 'primary-state' );
		$nested_state    = 'nested-state-' . self::safe_token( $ctx, 'nested-state' );

		\wp_interactivity_state(
			$case['namespace'],
			array(
				'label'       => $primary_state,
				'description' => static function () {
					$state   = \wp_interactivity_state();
					$context = \wp_interactivity_get_context();
					$element = \wp_interactivity_get_element();

					return ( $state['label'] ?? '' )
						. '|'
						. ( $context['local'] ?? '' )
						. '|'
						. ( $element['attributes']['data-case'] ?? '' );
				},
			)
		);
		\wp_interactivity_state(
			$case['otherNamespace'],
			array(
				'label'       => $nested_state,
				'description' => static function () {
					$state   = \wp_interactivity_state();
					$context = \wp_interactivity_get_context();
					$element = \wp_interactivity_get_element();

					return ( $state['label'] ?? '' )
						. '|'
						. ( $context['local'] ?? '' )
						. '|'
						. ( $element['attributes']['data-case'] ?? '' );
				},
				'broken'      => static function (): string {
					throw new \Error( 'Component fuzz derived state failure.' );
				},
			)
		);

		$interactive = \esc_attr( \wp_json_encode( array( 'namespace' => $case['namespace'] ) ) );
		$html        = '<section data-wp-interactive="' . $interactive . '" '
			. \wp_interactivity_data_wp_context( $primary_context, $case['namespace'] )
			. '>'
			. '<span data-case="outer-before" data-wp-text="state.description">outer before</span>'
			. '<div data-wp-interactive="' . \esc_attr( $case['otherNamespace'] ) . '" '
			. \wp_interactivity_data_wp_context( $nested_context, $case['otherNamespace'] )
			. '><span data-case="nested" data-wp-text="state.description">nested old</span></div>'
			. '<span data-case="outer-after" data-wp-text="state.description">outer after</span>'
			. '<span data-case="broken" data-wp-text="' . \esc_attr( $case['otherNamespace'] . '::state.broken' ) . '">broken old</span>'
			. '<span data-case="post-broken" data-wp-text="state.description">post broken old</span>'
			. '</section>';

		$capture     = self::capture_doing_it_wrong(
			static function () use ( $html ): string {
				return \wp_interactivity_process_directives( $html );
			}
		);
		$processed   = is_string( $capture['value'] ?? null ) ? $capture['value'] : '';
		$client_data = $api->filter_script_module_interactivity_data( array() );

		$expected = array(
			'outer-before' => $primary_state . '|' . $primary_context['local'] . '|outer-before',
			'nested'       => $nested_state . '|' . $nested_context['local'] . '|nested',
			'outer-after'  => $primary_state . '|' . $primary_context['local'] . '|outer-after',
			'post-broken'  => $primary_state . '|' . $primary_context['local'] . '|post-broken',
		);
		$bodies   = array(
			'outer-before' => self::find_element_body( $processed, 'span', 'data-case', 'outer-before' ),
			'nested'       => self::find_element_body( $processed, 'span', 'data-case', 'nested' ),
			'outer-after'  => self::find_element_body( $processed, 'span', 'data-case', 'outer-after' ),
			'broken'       => self::find_element_body( $processed, 'span', 'data-case', 'broken' ),
			'post-broken'  => self::find_element_body( $processed, 'span', 'data-case', 'post-broken' ),
		);
		$derived = $client_data['derivedStateClosures'] ?? array();

		$warning_functions = array_column( $capture['warnings'], 'function' );
		$warning           = $capture['warnings'][0] ?? array();
		$ok                = false === ( $capture['threw'] ?? true )
			&& \esc_html( $expected['outer-before'] ) === $bodies['outer-before']
			&& \esc_html( $expected['nested'] ) === $bodies['nested']
			&& \esc_html( $expected['outer-after'] ) === $bodies['outer-after']
			&& '' === $bodies['broken']
			&& \esc_html( $expected['post-broken'] ) === $bodies['post-broken']
			&& array( 'state.description' ) === ( $derived[ $case['namespace'] ] ?? null )
			&& array( 'state.description' ) === ( $derived[ $case['otherNamespace'] ] ?? null )
			&& ! in_array( 'state.broken', $derived[ $case['namespace'] ] ?? array(), true )
			&& ! in_array( 'state.broken', $derived[ $case['otherNamespace'] ] ?? array(), true )
			&& 1 === count( $capture['warnings'] )
			&& 'WP_Interactivity_API::evaluate' === ( $warning['function'] ?? null )
			&& '6.6.0' === ( $warning['version'] ?? null )
			&& str_contains( (string) ( $warning['message'] ?? '' ), 'state.broken' )
			&& str_contains( (string) ( $warning['message'] ?? '' ), $case['otherNamespace'] )
			&& false === \has_action( 'doing_it_wrong_run', $capture['listener'] ?? null )
			&& false === \has_filter( 'doing_it_wrong_trigger_error', $capture['suppressor'] ?? null );

		return self::result(
			$ctx,
			'interactivity.derived-state.stack-recovery-and-fail-closed',
			$ok,
			array(
				'namespace'        => $case['namespace'],
				'otherNamespace'   => $case['otherNamespace'],
				'processed'        => self::preview( $processed ),
				'bodies'           => $bodies,
				'expected'         => $expected,
				'derivedClosures'  => $derived,
				'warningFunctions' => $warning_functions,
				'warningCount'     => count( $capture['warnings'] ),
				'captureThrew'     => $capture['threw'] ?? null,
			)
		);
	}

	private static function check_context_and_element_helpers( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$api = self::install_fresh_api();

		$seen_context = null;
		$seen_element = null;
		\wp_interactivity_state(
			$case['namespace'],
			array(
				'contextEcho' => static function () use ( &$seen_context, &$seen_element, $case ) {
					$seen_context = \wp_interactivity_get_context( $case['namespace'] );
					$seen_element = \wp_interactivity_get_element();

					$current_state = \wp_interactivity_state();
					return ( $seen_context['localText'] ?? '' )
						. '|'
						. ( $seen_element['attributes']['data-case'] ?? '' )
						. '|'
						. ( $current_state['plain'] ?? '' );
				},
				'plain'       => $case['derivedPlain'],
			)
		);

		$html = '<div data-wp-interactive="' . esc_attr( $case['namespace'] ) . '" '
			. \wp_interactivity_data_wp_context( $case['context'], $case['namespace'] )
			. '><span data-case="derived" data-wp-text="state.contextEcho">placeholder</span></div>';

		$processed   = \wp_interactivity_process_directives( $html );
		$derived     = self::find_element_body( $processed, 'span', 'data-case', 'derived' );
		$client_data = $api->filter_script_module_interactivity_data( array() );
		$expected    = $case['context']['localText'] . '|derived|' . $case['derivedPlain'];

		$ok = esc_html( $expected ) === $derived
			&& $seen_context === $case['context']
			&& is_array( $seen_element )
			&& 'derived' === ( $seen_element['attributes']['data-case'] ?? null )
			&& isset( $client_data['derivedStateClosures'][ $case['namespace'] ] )
			&& in_array( 'state.contextEcho', $client_data['derivedStateClosures'][ $case['namespace'] ], true );

		return self::result(
			$ctx,
			'interactivity.context-element-and-derived-state.helpers',
			$ok,
			array(
				'namespace'       => $case['namespace'],
				'processed'       => self::preview( $processed ),
				'derivedBody'     => $derived,
				'expected'        => $expected,
				'seenContext'     => $seen_context,
				'seenElement'     => $seen_element,
				'derivedClosures' => $client_data['derivedStateClosures'][ $case['namespace'] ] ?? null,
			)
		);
	}

	private static function check_script_module_hooks( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$api       = self::install_fresh_api();
		$module_id = '@component-fuzz/interactivity-' . self::safe_token( $ctx, 'module' );

		$api->add_client_navigation_support_to_script_module( $module_id );
		$matching_attrs = $api->add_load_on_client_navigation_attribute_to_script_modules(
			array(
				'type' => 'module',
				'id'   => $module_id . '-js-module',
			)
		);
		$nonmatching_attrs = $api->add_load_on_client_navigation_attribute_to_script_modules(
			array(
				'type' => 'module',
				'id'   => $module_id . '-different-js-module',
			)
		);
		$classic_attrs = $api->add_load_on_client_navigation_attribute_to_script_modules(
			array(
				'type' => 'text/javascript',
				'id'   => $module_id . '-js-module',
			)
		);

		$router_data = $api->filter_script_module_interactivity_router_data( array( 'existing' => true ) );
		$empty_data  = $api->filter_script_module_interactivity_data( array( 'existing' => true ) );

		$api->add_hooks();
		$hooks_present = false !== \has_filter( 'script_module_data_@wordpress/interactivity', array( $api, 'filter_script_module_interactivity_data' ) )
			&& false !== \has_filter( 'script_module_data_@wordpress/interactivity-router', array( $api, 'filter_script_module_interactivity_router_data' ) )
			&& false !== \has_filter( 'wp_script_attributes', array( $api, 'add_load_on_client_navigation_attribute_to_script_modules' ) );

		\remove_filter( 'script_module_data_@wordpress/interactivity', array( $api, 'filter_script_module_interactivity_data' ) );
		\remove_filter( 'script_module_data_@wordpress/interactivity-router', array( $api, 'filter_script_module_interactivity_router_data' ) );
		\remove_filter( 'wp_script_attributes', array( $api, 'add_load_on_client_navigation_attribute_to_script_modules' ) );

		$hooks_removed = false === \has_filter( 'script_module_data_@wordpress/interactivity', array( $api, 'filter_script_module_interactivity_data' ) )
			&& false === \has_filter( 'script_module_data_@wordpress/interactivity-router', array( $api, 'filter_script_module_interactivity_router_data' ) )
			&& false === \has_filter( 'wp_script_attributes', array( $api, 'add_load_on_client_navigation_attribute_to_script_modules' ) );

		$options = isset( $matching_attrs['data-wp-router-options'] )
			? json_decode( (string) $matching_attrs['data-wp-router-options'], true )
			: null;

		$ok = array( 'loadOnClientNavigation' => true ) === $options
			&& ! array_key_exists( 'data-wp-router-options', $nonmatching_attrs )
			&& ! array_key_exists( 'data-wp-router-options', $classic_attrs )
			&& isset( $router_data['i18n']['loading'], $router_data['i18n']['loaded'] )
			&& true === $router_data['existing']
			&& array( 'existing' => true ) === $empty_data
			&& $hooks_present
			&& $hooks_removed;

		return self::result(
			$ctx,
			'interactivity.script-module-hooks.router-data-and-client-navigation-attributes',
			$ok,
			array(
				'moduleId'         => $module_id,
				'matchingAttrs'    => $matching_attrs,
				'nonmatchingAttrs' => $nonmatching_attrs,
				'classicAttrs'     => $classic_attrs,
				'routerData'       => $router_data,
				'emptyData'        => $empty_data,
				'hooksPresent'     => $hooks_present,
				'hooksRemoved'     => $hooks_removed,
			)
		);
	}

	private static function check_each_edge_cases( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::install_fresh_api();

		$rows = array(
			array( 'title' => 'Row 0 <' . self::safe_token( $ctx, 'row0' ) . '> & value' ),
			array( 'title' => 'Row 1 <' . self::safe_token( $ctx, 'row1' ) . '> & value' ),
		);
		\wp_interactivity_state(
			$case['namespace'],
			array(
				'manualList' => array( array( 'title' => 'Manual generated should not render' ) ),
				'assocList'  => array( 'alpha' => array( 'title' => 'Assoc generated should not render' ) ),
				'rows'       => $rows,
			)
		);

		$html = '<section data-wp-interactive="' . \esc_attr( $case['namespace'] ) . '">'
			. '<template data-case="manual" data-wp-each="state.manualList"><span data-wp-text="context.item.title">manual old</span></template>'
			. '<span data-wp-each-child="manual">manual server child</span>'
			. '<template data-case="assoc" data-wp-each="state.assocList"><span data-wp-text="context.item.title">assoc old</span></template>'
			. '<template data-case="toptext" data-wp-each="state.rows"> top text <span data-wp-text="context.item.title">top old</span></template>'
			. '<template data-case="named" data-wp-each--entry-row="state.rows"><span data-case="row" data-wp-text="context.entryRow.title">row old</span></template>'
			. '</section>';

		$processed       = \wp_interactivity_process_directives( $html );
		$manual_children = self::find_each_children( $processed, $case['namespace'] . '::state.manualList' );
		$assoc_children  = self::find_each_children( $processed, $case['namespace'] . '::state.assocList' );
		$top_children    = self::find_each_children( $processed, $case['namespace'] . '::state.rows' );
		$row_failures    = array();

		foreach ( $rows as $index => $row ) {
			$rendered = $top_children[ $index ] ?? null;
			self::collect_failure(
				$row_failures,
				is_array( $rendered ) && \esc_html( $row['title'] ) === $rendered['body'],
				"named data-wp-each rendered row {$index}",
				array(
					'row'      => $row,
					'rendered' => $rendered,
				)
			);
		}

		$ok = array() === $manual_children
			&& array() === $assoc_children
			&& count( $top_children ) === count( $rows )
			&& array() === $row_failures
			&& str_contains( $processed, 'manual server child' )
			&& str_contains( $processed, 'assoc old' )
			&& str_contains( $processed, 'top old' )
			&& ! str_contains( $processed, 'Manual generated should not render' )
			&& ! str_contains( $processed, 'Assoc generated should not render' );

		return self::result(
			$ctx,
			'interactivity.each.edge-cases-manual-associative-top-text-and-named-context',
			$ok,
			array(
				'namespace'      => $case['namespace'],
				'processed'      => self::preview( $processed ),
				'manualChildren' => $manual_children,
				'assocChildren'  => $assoc_children,
				'rowChildren'    => $top_children,
				'rowFailures'    => array_slice( $row_failures, 0, 5 ),
			)
		);
	}

	private static function check_router_region( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		$api         = self::install_fresh_api();
		$request_uri = '/component-fuzz/router/' . rawurlencode( $case['routerToken'] ) . '?view=' . rawurlencode( $case['uniqueId'] );

		$_SERVER['REQUEST_URI'] = $request_uri;

		$html      = '<main data-case="router" data-wp-interactive="core/router" data-wp-router-region>body</main>';
		$processed = \wp_interactivity_process_directives( $html );
		$state     = \wp_interactivity_state( 'core/router' );
		$styles    = \wp_styles();
		$priority  = \has_action( 'wp_footer', array( $api, 'print_router_markup' ) );

		ob_start();
		\do_action( 'wp_footer' );
		$footer = (string) ob_get_clean();

		$router_style = $styles->registered['wp-interactivity-router-animations'] ?? null;
		$inline_css   = is_object( $router_style ) ? ( $router_style->extra['after'] ?? array() ) : array();
		$expected_url = 'http://example.test' . $request_uri;

		$ok = $processed === $html
			&& $expected_url === ( $state['url'] ?? null )
			&& false !== $priority
			&& in_array( 'wp-interactivity-router-animations', $styles->queue, true )
			&& is_object( $router_style )
			&& array() !== $inline_css
			&& str_contains( implode( "\n", $inline_css ), 'wp-interactivity-router-loading-bar' )
			&& str_contains( $footer, 'wp-interactivity-router-loading-bar' )
			&& str_contains( $footer, 'core/router/private' );

		return self::result(
			$ctx,
			'interactivity.router-region.self-link-style-and-footer',
			$ok,
			array(
				'requestUri'  => $request_uri,
				'expectedUrl' => $expected_url,
				'state'       => $state,
				'processed'   => self::preview( $processed ),
				'styleQueue'  => $styles->queue,
				'inlineCss'   => $inline_css,
				'priority'    => $priority,
				'footer'      => self::preview( $footer ),
			)
		);
	}

	private static function check_unbalanced_and_unsupported_fallbacks( \ComponentFuzz\FuzzContext $ctx, array $case ): array {
		self::install_fresh_api();
		\wp_interactivity_state(
			$case['namespace'],
			array(
				'text' => $case['fallbackText'],
			)
		);

		$unbalanced = '<div data-wp-interactive="' . esc_attr( $case['namespace'] ) . '"><span data-wp-text="state.text">old</div>';
		$unsupported = '<div data-wp-interactive="' . esc_attr( $case['namespace'] ) . '">'
			. '<svg data-wp-text="state.text"><text data-wp-text="state.text">svg old</text></svg>'
			. '<span data-case="outside" data-wp-text="state.text">outside old</span>'
			. '</div>';
		$unknown = '<p data-wp-unknown="state.text" data-case="unknown">unknown old</p>';

		$unbalanced_processed  = \wp_interactivity_process_directives( $unbalanced );
		$unsupported_processed = \wp_interactivity_process_directives( $unsupported );
		$unknown_processed     = \wp_interactivity_process_directives( $unknown );
		$outside               = self::find_element_body( $unsupported_processed, 'span', 'data-case', 'outside' );

		$ok = $unbalanced === $unbalanced_processed
			&& str_contains( $unsupported_processed, '<text data-wp-text="state.text">svg old</text>' )
			&& esc_html( $case['fallbackText'] ) === $outside
			&& $unknown === $unknown_processed;

		return self::result(
			$ctx,
			'interactivity.fallbacks.unbalanced-unsupported-and-unknown',
			$ok,
			array(
				'namespace'            => $case['namespace'],
				'unbalancedInput'      => $unbalanced,
				'unbalancedProcessed'  => $unbalanced_processed,
				'unsupportedProcessed' => self::preview( $unsupported_processed ),
				'outsideBody'          => $outside,
				'unknownInput'         => $unknown,
				'unknownProcessed'     => $unknown_processed,
			)
		);
	}

	private static function directive_html( array $case ): string {
		$state = $case['directiveState'];

		return '<section data-wp-interactive="' . esc_attr( $case['namespace'] ) . '" '
			. \wp_interactivity_data_wp_context( $case['context'], $case['namespace'] )
			. '>'
			. '<a data-case="target"'
			. ' class="' . esc_attr( $case['baseClass'] . ' ' . $case['staleClass'] ) . '"'
			. ' style="' . esc_attr( 'color:' . $case['initialColor'] . ';margin:' . $case['initialMargin'] . ';border-color:' . $case['initialBorderColor'] . ';' ) . '"'
			. ' href="#old" hidden'
			. ' data-wp-bind--href="state.href"'
			. ' data-wp-bind--hidden="state.isHidden"'
			. ' data-wp-bind--aria-expanded="state.ariaOpen"'
			. ' data-wp-bind--data-active="state.isActive"'
			. ' data-wp-class--' . esc_attr( $case['activeClass'] ) . '="state.isActive"'
			. ' data-wp-class--' . esc_attr( $case['staleClass'] ) . '="state.staleActive"'
			. ' data-wp-class--' . esc_attr( $case['uniqueClass'] ) . '---' . esc_attr( $case['uniqueId'] ) . '="state.isActive"'
			. ' data-wp-style--color="state.color"'
			. ' data-wp-style--margin="state.margin"'
			. ' data-wp-style--background-color="state.background"'
			. ' data-wp-text="state.text">old <em>markup</em></a>'
			. '<span data-case="context" data-wp-text="context.localText">old context</span>'
			. '<template data-wp-each--entry="state.items"><span class="item" data-wp-class--selected="context.entry.active" data-wp-text="context.entry.title">placeholder</span></template>'
			. '<span data-count="' . esc_attr( (string) count( $state['items'] ) ) . '"></span>'
			. '</section>';
	}

	private static function case_for_context( \ComponentFuzz\FuzzContext $ctx ): array {
		$case = $ctx->fork( 'interactivity-case' );

		$namespace       = 'cfz/' . self::safe_token( $case, 'ns' );
		$other_namespace = 'cfz/' . self::safe_token( $case, 'other' );
		$token           = self::safe_token( $case, 'value' );
		$items           = array();
		$item_count      = $case->int( 2, 4 );

		for ( $i = 0; $i < $item_count; ++$i ) {
			$items[] = array(
				'title'  => 'Item ' . $i . ' <' . self::safe_token( $case, 'item' . $i ) . '> & value',
				'active' => 0 === $i % 2 ? true : $case->bool(),
			);
		}

		return array(
			'namespace'            => $namespace,
			'otherNamespace'       => $other_namespace,
			'context'              => array(
				'localText' => 'Context <' . $token . '> & "quoted"',
				'count'     => $item_count,
				'nested'    => array(
					'token' => $token,
				),
			),
			'directiveState'       => array(
				'href'        => '/component-fuzz/' . rawurlencode( $token ),
				'isHidden'    => false,
				'ariaOpen'    => $case->bool(),
				'isActive'    => true,
				'staleActive' => false,
				'color'       => self::css_color( $case ),
				'margin'      => '',
				'background'  => self::css_color( $case ),
				'text'        => 'State <' . $token . '> & text',
				'items'       => $items,
			),
			'baseClass'            => 'base-' . self::safe_token( $case, 'base' ),
			'activeClass'          => 'active-' . self::safe_token( $case, 'active' ),
			'staleClass'           => 'stale-' . self::safe_token( $case, 'stale' ),
			'uniqueClass'          => 'unique-' . self::safe_token( $case, 'unique' ),
			'uniqueId'             => 'id-' . self::safe_token( $case, 'id' ),
			'initialColor'         => '#123456',
			'initialMargin'        => $case->int( 1, 9 ) . 'px',
			'initialBorderColor'   => '#abcdef',
			'fallbackText'         => 'Fallback <' . $token . '> & text',
			'routerToken'          => 'router-' . $token,
			'derivedPlain'         => 'plain-' . $token,
			'stateKept'            => 'kept-' . $token,
			'stateReplacement'     => 'replaced-' . $token,
			'stateAdded'           => $case->int( 1, 1000 ),
			'stateListReplacement' => 'list-' . $token,
			'stateScalarAfter'     => 'scalar-' . $token,
			'configStable'         => 'stable-' . $token,
			'configLimit'          => $case->int( 1, 99 ),
			'otherValue'           => 'other-' . $token,
		);
	}

	private static function find_first_tag_by_attribute( string $html, string $attribute, string $value ): ?array {
		$processor = new \WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag() ) {
			if ( $value !== $processor->get_attribute( $attribute ) ) {
				continue;
			}

			$attributes = array();
			foreach ( $processor->get_attribute_names_with_prefix( '' ) ?? array() as $name ) {
				$attributes[ $name ] = $processor->get_attribute( $name );
			}

			return array(
				'tag'        => $processor->get_tag(),
				'attributes' => $attributes,
			);
		}

		return null;
	}

	private static function find_element_body( string $html, string $tag, string $attribute, string $value ): ?string {
		$pattern = '/<' . preg_quote( $tag, '/' ) . '\b(?=[^>]*\b' . preg_quote( $attribute, '/' ) . '="' . preg_quote( $value, '/' ) . '")[^>]*>(.*?)<\/' . preg_quote( $tag, '/' ) . '>/s';

		if ( 1 !== preg_match( $pattern, $html, $matches ) ) {
			return null;
		}

		return $matches[1];
	}

	private static function find_each_children( string $html, string $each_child_value ): array {
		$pattern = '/<span\b(?=[^>]*\bdata-wp-each-child="' . preg_quote( $each_child_value, '/' ) . '")[^>]*>.*?<\/span>/s';
		$count   = preg_match_all( $pattern, $html, $matches );

		if ( false === $count || 0 === $count ) {
			return array();
		}

		$children = array();
		foreach ( $matches[0] as $span ) {
			$tag = self::find_first_tag_by_attribute( $span, 'data-wp-each-child', $each_child_value );
			$children[] = array(
				'attributes' => $tag['attributes'] ?? array(),
				'body'       => self::find_element_body( $span, 'span', 'data-wp-each-child', $each_child_value ),
			);
		}

		return $children;
	}

	private static function parse_style( string $style ): array {
		$properties = array();

		foreach ( explode( ';', $style ) as $assignment ) {
			$assignment = trim( $assignment );
			if ( '' === $assignment || ! str_contains( $assignment, ':' ) ) {
				continue;
			}
			list( $name, $value ) = explode( ':', $assignment, 2 );
			$properties[ trim( strtolower( $name ) ) ] = trim( $value );
		}

		return $properties;
	}

	private static function class_present( string $class, string $needle ): bool {
		return in_array( $needle, preg_split( '/\s+/', trim( $class ), -1, PREG_SPLIT_NO_EMPTY ), true );
	}

	private static function context_attribute( string $attribute_name, array $context, string $namespace = '' ): string {
		return $attribute_name . "='"
			. ( '' === $namespace ? '' : $namespace . '::' )
			. \wp_json_encode( $context, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP )
			. "'";
	}

	private static function css_color( \ComponentFuzz\FuzzContext $ctx ): string {
		return sprintf( '#%02x%02x%02x', $ctx->int( 0, 255 ), $ctx->int( 0, 255 ), $ctx->int( 0, 255 ) );
	}

	private static function safe_token( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		$token = strtolower( $label . '-' . $ctx->identifier( 3, 12 ) . '-' . dechex( $ctx->seed() & 0xffff ) );
		$token = preg_replace( '/[^a-z0-9_-]+/', '-', $token );
		$token = trim( (string) $token, '-' );

		return '' === $token ? 'token-' . dechex( $ctx->seed() & 0xffff ) : substr( $token, 0, 48 );
	}

	private static function install_fresh_api(): \WP_Interactivity_API {
		$api                         = new \WP_Interactivity_API();
		$GLOBALS['wp_interactivity'] = $api;

		return $api;
	}

	private static function snapshot_state(): array {
		return array(
			'wp_interactivity'   => self::snapshot_global( 'wp_interactivity' ),
			'wp_filter'         => self::snapshot_global( 'wp_filter' ),
			'wp_filters'        => self::snapshot_global( 'wp_filters' ),
			'wp_actions'        => self::snapshot_global( 'wp_actions' ),
			'wp_current_filter' => self::snapshot_global( 'wp_current_filter' ),
			'wp_styles'         => self::snapshot_global( 'wp_styles' ),
			'wp_scripts'        => self::snapshot_global( 'wp_scripts' ),
			'_SERVER'           => self::snapshot_global( '_SERVER' ),
		);
	}

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			self::restore_global( $name, $entry );
		}
	}

	private static function snapshot_global( string $name ): array {
		return array(
			'exists' => array_key_exists( $name, $GLOBALS ),
			'value'  => $GLOBALS[ $name ] ?? null,
		);
	}

	private static function restore_global( string $name, array $entry ): void {
		if ( $entry['exists'] ) {
			$GLOBALS[ $name ] = $entry['value'];
			return;
		}

		unset( $GLOBALS[ $name ] );
	}

	private static function collect_failure( array &$failures, bool $condition, string $label, array $details ): void {
		if ( $condition ) {
			return;
		}

		$failures[] = array(
			'label'   => $label,
			'details' => $details,
		);
	}

	private static function capture_doing_it_wrong( callable $callback ): array {
		$warnings  = array();
		$listener  = static function ( $function_name, $message, $version ) use ( &$warnings ): void {
			$warnings[] = array(
				'function' => $function_name,
				'message'  => $message,
				'version'  => $version,
			);
		};
		$suppressor = static function (): bool {
			return false;
		};

		\add_action( 'doing_it_wrong_run', $listener, 10, 3 );
		\add_filter( 'doing_it_wrong_trigger_error', $suppressor );
		try {
			return array(
				'threw'      => false,
				'value'      => $callback(),
				'warnings'   => $warnings,
				'listener'   => $listener,
				'suppressor' => $suppressor,
			);
		} catch ( \Throwable $e ) {
			return array(
				'threw'      => true,
				'throwable'  => self::describe_throwable( $e ),
				'warnings'   => $warnings,
				'listener'   => $listener,
				'suppressor' => $suppressor,
			);
		} finally {
			\remove_filter( 'doing_it_wrong_trigger_error', $suppressor );
			\remove_action( 'doing_it_wrong_run', $listener, 10 );
		}
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data = array() ): array {
		return $ok ? $ctx->pass( $invariant, $data ) : $ctx->fail( $invariant, $data );
	}

	private static function preview( string $value ) {
		return \ComponentFuzz\preview_value( $value, 320 );
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}
}
