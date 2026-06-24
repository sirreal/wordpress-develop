<?php

abstract class WP_Test_REST_Controller_Testcase extends WP_Test_REST_TestCase {

	protected $server;

	public function set_up() {
		parent::set_up();

		if ( ! $this->should_register_rest_routes() ) {
			return;
		}

		add_filter( 'rest_url', array( $this, 'filter_rest_url_for_leading_slash' ), 10, 2 );
		/** @var WP_REST_Server $wp_rest_server */
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		if ( $this->should_create_initial_rest_routes() ) {
			do_action( 'rest_api_init', $wp_rest_server );
		} else {
			$this->do_rest_api_init_without_initial_routes();
			$this->register_initial_rest_routes_for_test();
		}
	}

	public function tear_down() {
		if ( $this->should_register_rest_routes() ) {
			remove_filter( 'rest_url', array( $this, 'filter_rest_url_for_leading_slash' ), 10, 2 );
			/** @var WP_REST_Server $wp_rest_server */
			global $wp_rest_server;
			$wp_rest_server = null;
		}
		parent::tear_down();
	}

	protected function should_register_rest_routes() {
		return true;
	}

	protected function should_create_initial_rest_routes() {
		return true;
	}

	protected function register_initial_rest_routes_for_test() {}

	abstract public function test_register_routes();

	abstract public function test_context_param();

	abstract public function test_get_items();

	abstract public function test_get_item();

	abstract public function test_create_item();

	abstract public function test_update_item();

	abstract public function test_delete_item();

	abstract public function test_prepare_item();

	abstract public function test_get_item_schema();

	public function filter_rest_url_for_leading_slash( $url, $path ) {
		if ( is_multisite() || get_option( 'permalink_structure' ) ) {
			return $url;
		}

		// Make sure path for rest_url has a leading slash for proper resolution.
		if ( 0 !== strpos( $path, '/' ) ) {
			$this->fail(
				sprintf(
					'REST API URL "%s" should have a leading slash.',
					$path
				)
			);
		}

		return $url;
	}
}
