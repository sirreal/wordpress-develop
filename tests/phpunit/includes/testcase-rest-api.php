<?php

abstract class WP_Test_REST_TestCase extends WP_UnitTestCase {

	/**
	 * Asserts that the REST API response has the specified error.
	 *
	 * @since 4.4.0
	 * @since 6.6.0 Added the `$message` parameter.
	 *
	 * @param string|int                $code     Expected error code.
	 * @param WP_REST_Response|WP_Error $response REST API response.
	 * @param int                       $status   Optional. Status code.
	 * @param string                    $message  Optional. Message to display when the assertion fails.
	 */
	protected function assertErrorResponse( $code, $response, $status = null, $message = '' ) {

		if ( $response instanceof WP_REST_Response ) {
			$response = $response->as_error();
		}

		$this->assertWPError( $response, $message . ' Passed $response is not a WP_Error object.' );
		$this->assertSame( $code, $response->get_error_code(), $message . ' The expected error code does not match.' );

		if ( null !== $status ) {
			$data = $response->get_error_data();
			$this->assertArrayHasKey( 'status', $data, $message . ' Passed $response does not include a status code.' );
			$this->assertSame( $status, $data['status'], $message . ' The expected status code does not match.' );
		}
	}

	protected function do_rest_api_init_without_initial_routes() {
		$priority = has_action( 'rest_api_init', 'create_initial_rest_routes' );

		if ( false !== $priority ) {
			remove_action( 'rest_api_init', 'create_initial_rest_routes', $priority );
		}

		try {
			do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );
		} finally {
			if ( false !== $priority ) {
				add_action( 'rest_api_init', 'create_initial_rest_routes', $priority );
			}
		}
	}

	protected function register_post_type_rest_routes_for_test( $post_type_names ) {
		foreach ( $post_type_names as $post_type_name ) {
			$post_type = get_post_type_object( $post_type_name );

			if ( ! $post_type ) {
				continue;
			}

			$controller = $post_type->get_rest_controller();

			if ( ! $controller ) {
				continue;
			}

			if ( ! $post_type->late_route_registration ) {
				$controller->register_routes();
			}

			$revisions_controller = $post_type->get_revisions_rest_controller();
			if ( $revisions_controller ) {
				$revisions_controller->register_routes();
			}

			$autosaves_controller = $post_type->get_autosave_rest_controller();
			if ( $autosaves_controller ) {
				$autosaves_controller->register_routes();
			}

			if ( $post_type->late_route_registration ) {
				$controller->register_routes();
			}
		}
	}

	protected function register_taxonomy_rest_routes_for_test( $taxonomy_names ) {
		foreach ( $taxonomy_names as $taxonomy_name ) {
			$taxonomy = get_taxonomy( $taxonomy_name );

			if ( ! $taxonomy ) {
				continue;
			}

			$controller = $taxonomy->get_rest_controller();

			if ( ! $controller ) {
				continue;
			}

			$controller->register_routes();
		}
	}
}
