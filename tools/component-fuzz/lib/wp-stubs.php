<?php

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'OBJECT_K' ) ) {
	define( 'OBJECT_K', 'OBJECT_K' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return 'en_US';
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'is_rtl' ) ) {
	function is_rtl() {
		return false;
	}
}

if ( ! function_exists( 'wp_get_word_count_type' ) ) {
	function wp_get_word_count_type() {
		return 'words';
	}
}

if ( ! function_exists( 'user_can_richedit' ) ) {
	function user_can_richedit() {
		return false;
	}
}

if ( ! class_exists( 'Component_Fuzz_WPDB_Stub', false ) ) {
	class Component_Fuzz_WPDB_Stub {
		public $suppress_errors = false;
		public $last_query = '';
		public $last_error = '';
		public $rows_affected = 0;
		public $insert_id = 0;
		public $num_rows = 0;
		public $num_queries = 0;
		public $is_mysql = false;
		public $posts = 'wp_posts';
		public $comments = 'wp_comments';
		public $links = 'wp_links';
		public $terms = 'wp_terms';
		public $term_taxonomy = 'wp_term_taxonomy';
		public $term_relationships = 'wp_term_relationships';
		public $options = 'wp_options';
		public $postmeta = 'wp_postmeta';
		public $users = 'wp_users';
		public $usermeta = 'wp_usermeta';
		public $commentmeta = 'wp_commentmeta';
		public $termmeta = 'wp_termmeta';
		public $prefix = 'wp_';
		public $base_prefix = 'wp_';
		public $charset = 'utf8mb4';

		private $component_fuzz_options = array();
		private $component_fuzz_posts = array();
		private $component_fuzz_terms = array();
		private $component_fuzz_term_taxonomy_rows = array();
		private $component_fuzz_term_relationship_rows = array();
		private $component_fuzz_users = array();
		private $component_fuzz_comments = array();
		private $component_fuzz_links = array();
		private $component_fuzz_meta = array();
		private $component_fuzz_next_ids = array();
		private $component_fuzz_queries = array();
		private $component_fuzz_last_found_rows = 0;

		public function __construct( array $options = array() ) {
			$this->component_fuzz_reset_options( $options );
			$this->component_fuzz_reset_content();
		}

		public function component_fuzz_reset_options( array $options = array() ) {
			$this->component_fuzz_options = array();

			foreach ( $options as $option => $entry ) {
				if ( is_array( $entry ) && array_key_exists( 'option_value', $entry ) ) {
					$value    = $entry['option_value'];
					$autoload = $entry['autoload'] ?? 'auto';
				} else {
					$value    = function_exists( 'maybe_serialize' ) ? maybe_serialize( $entry ) : $entry;
					$autoload = 'auto';
				}

				$this->component_fuzz_options[ (string) $option ] = array(
					'option_value' => $value,
					'autoload'     => (string) $autoload,
				);
			}
		}

		public function component_fuzz_get_options() {
			return $this->component_fuzz_options;
		}

		public function component_fuzz_get_queries() {
			return $this->component_fuzz_queries;
		}

		public function component_fuzz_get_runtime_state() {
			return array(
				'last_query'      => $this->last_query,
				'last_error'      => $this->last_error,
				'rows_affected'   => $this->rows_affected,
				'insert_id'       => $this->insert_id,
				'num_rows'        => $this->num_rows,
				'num_queries'     => $this->num_queries,
				'queries'         => $this->component_fuzz_queries,
				'last_found_rows' => $this->component_fuzz_last_found_rows,
				'next_ids'        => $this->component_fuzz_next_ids,
			);
		}

		public function component_fuzz_restore_runtime_state( array $state ) {
			$this->last_query                     = (string) ( $state['last_query'] ?? '' );
			$this->last_error                     = (string) ( $state['last_error'] ?? '' );
			$this->rows_affected                  = (int) ( $state['rows_affected'] ?? 0 );
			$this->insert_id                      = (int) ( $state['insert_id'] ?? 0 );
			$this->num_rows                       = (int) ( $state['num_rows'] ?? 0 );
			$this->num_queries                    = (int) ( $state['num_queries'] ?? 0 );
			$this->component_fuzz_queries         = is_array( $state['queries'] ?? null ) ? array_values( $state['queries'] ) : array();
			$this->component_fuzz_last_found_rows = (int) ( $state['last_found_rows'] ?? 0 );
			$this->component_fuzz_next_ids        = is_array( $state['next_ids'] ?? null ) ? array_map( 'intval', $state['next_ids'] ) : $this->component_fuzz_next_ids;
		}

		public function component_fuzz_reset_content() {
			$this->component_fuzz_posts                 = array();
			$this->component_fuzz_terms                 = array();
			$this->component_fuzz_term_taxonomy_rows    = array();
			$this->component_fuzz_term_relationship_rows = array();
			$this->component_fuzz_users                 = array();
			$this->component_fuzz_comments              = array();
			$this->component_fuzz_links                 = array();
			$this->component_fuzz_meta                  = array(
				'post'    => array(),
				'term'    => array(),
				'comment' => array(),
				'user'    => array(),
			);
			$this->component_fuzz_next_ids              = array(
				'posts'         => 1,
				'terms'         => 1,
				'term_taxonomy' => 1,
				'users'         => 1,
				'comments'      => 1,
				'links'         => 1,
				'post_meta'     => 1,
				'term_meta'     => 1,
				'comment_meta'  => 1,
				'user_meta'     => 1,
			);
			$this->insert_id                            = 0;
			$this->rows_affected                        = 0;
			$this->num_rows                             = 0;
			$this->last_error                           = '';
			$this->component_fuzz_last_found_rows        = 0;
		}

		public function component_fuzz_content_counts() {
			return array(
				'posts'              => count( $this->component_fuzz_posts ),
				'terms'              => count( $this->component_fuzz_terms ),
				'term_taxonomy'      => count( $this->component_fuzz_term_taxonomy_rows ),
				'term_relationships' => count( $this->component_fuzz_term_relationship_rows ),
				'users'              => count( $this->component_fuzz_users ),
				'comments'           => count( $this->component_fuzz_comments ),
				'links'              => count( $this->component_fuzz_links ),
				'post_meta'          => count( $this->component_fuzz_meta['post'] ),
				'term_meta'          => count( $this->component_fuzz_meta['term'] ),
				'comment_meta'       => count( $this->component_fuzz_meta['comment'] ),
				'user_meta'          => count( $this->component_fuzz_meta['user'] ),
			);
		}

		public function _escape( $data ) {
			if ( is_array( $data ) ) {
				return array_map( array( $this, '_escape' ), $data );
			}

			return addslashes( (string) $data );
		}

		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}

			$index = 0;
			return preg_replace_callback(
				'/%[sdFfi]/',
				function ( $match ) use ( $args, &$index ) {
					if ( ! array_key_exists( $index, $args ) ) {
						return $match[0];
					}

					$placeholder = $match[0];
					$arg         = $args[ $index++ ];

					if ( '%d' === $placeholder ) {
						return (string) (int) $arg;
					}

					if ( '%f' === $placeholder || '%F' === $placeholder ) {
						return (string) (float) $arg;
					}

					if ( '%i' === $placeholder ) {
						return preg_replace( '/[^A-Za-z0-9_$\.]/', '', (string) $arg );
					}

					return "'" . $this->_escape( $arg ) . "'";
				},
				$query
			);
		}

		public function esc_like( $text ) {
			return addcslashes( (string) $text, '_%\\' );
		}

		public function placeholder_escape() {
			static $placeholder;

			if ( ! $placeholder ) {
				$placeholder = '{component-fuzz-placeholder-escape}';
			}

			if ( function_exists( 'has_filter' ) && function_exists( 'add_filter' ) && false === has_filter( 'query', array( $this, 'remove_placeholder_escape' ) ) ) {
				add_filter( 'query', array( $this, 'remove_placeholder_escape' ), 0 );
			}

			return $placeholder;
		}

		public function add_placeholder_escape( $query ) {
			return str_replace( '%', $this->placeholder_escape(), (string) $query );
		}

		public function remove_placeholder_escape( $query ) {
			return str_replace( $this->placeholder_escape(), '%', (string) $query );
		}

		public function suppress_errors( $suppress = true ) {
			$previous = $this->suppress_errors;
			$this->suppress_errors = (bool) $suppress;
			return $previous;
		}

		public function get_var( $query = null, $x = 0, $y = 0 ) {
			unset( $x, $y );

			$this->component_fuzz_record_query( $query );

			if ( preg_match( '/\bSELECT\s+COUNT\(\*\)/i', $this->last_query ) ) {
				return $this->component_fuzz_count_for_query( $this->last_query );
			}

			if ( preg_match( '/^\s*SELECT\s+FOUND_ROWS\s*\(\s*\)/i', $this->last_query ) ) {
				return $this->component_fuzz_last_found_rows;
			}

			if ( preg_match( '/\bSELECT\s+MAX\(term_group\)\s+FROM\s+`?wp_terms`?/i', $this->last_query ) ) {
				$groups = array_column( $this->component_fuzz_terms, 'term_group' );
				return $groups ? max( array_map( 'intval', $groups ) ) : 0;
			}

			$rows           = $this->component_fuzz_select_rows( $this->last_query );
			$this->num_rows = count( $rows );
			if ( array() === $rows ) {
				return null;
			}

			$row = reset( $rows );
			return reset( $row );
		}

		public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
			unset( $y );

			$this->component_fuzz_record_query( $query );
			$rows           = $this->component_fuzz_select_rows( $this->last_query );
			$this->num_rows = count( $rows );

			if ( array() === $rows ) {
				return null;
			}

			return $this->component_fuzz_format_row( reset( $rows ), $output );
		}

		public function get_results( $query = null, $output = OBJECT ) {
			$this->component_fuzz_record_query( $query );
			$rows           = $this->component_fuzz_select_rows( $this->last_query );
			$this->num_rows = count( $rows );

			return $this->component_fuzz_format_results( $rows, $output );
		}

		public function get_col( $query = null, $x = 0 ) {
			$this->component_fuzz_record_query( $query );
			$rows           = $this->component_fuzz_select_rows( $this->last_query );
			$this->num_rows = count( $rows );
			$values           = array();

			foreach ( $rows as $row ) {
				$row_values = array_values( $row );
				if ( array_key_exists( $x, $row_values ) ) {
					$values[] = $row_values[ $x ];
				}
			}

			return $values;
		}

		public function query( $query ) {
			$this->component_fuzz_record_query( $query );
			$this->rows_affected = 0;

			if ( preg_match( '/\bINSERT\s+INTO\s+`?wp_options`?\b/i', $this->last_query ) ) {
				return $this->component_fuzz_query_insert_option( $this->last_query );
			}

			if ( preg_match( '/\bUPDATE\s+`?wp_options`?\s+SET\s+`?autoload`?\s*=/i', $this->last_query ) ) {
				return $this->component_fuzz_query_update_option_autoload( $this->last_query );
			}

			if ( preg_match( '/\bDELETE\s+FROM\s+`?wp_(post|term|comment)meta`?\s+WHERE\s+`?meta_id`?\s+IN\s*\(([^)]*)\)/i', $this->last_query, $matches ) ) {
				return $this->component_fuzz_delete_meta_ids( $matches[1], $this->component_fuzz_csv_int_values( $matches[2] ) );
			}

			if ( preg_match( '/\bDELETE\s+FROM\s+`?wp_usermeta`?\s+WHERE\s+`?umeta_id`?\s+IN\s*\(([^)]*)\)/i', $this->last_query, $matches ) ) {
				return $this->component_fuzz_delete_meta_ids( 'user', $this->component_fuzz_csv_int_values( $matches[1] ) );
			}

			if ( preg_match( '/\bDELETE\s+FROM\s+`?wp_term_relationships`?\s+WHERE\b/i', $this->last_query ) ) {
				return $this->component_fuzz_query_delete_term_relationships( $this->last_query );
			}

			if ( preg_match( '/\bINSERT\s+INTO\s+`?wp_term_relationships`?\b/i', $this->last_query ) ) {
				return $this->component_fuzz_query_insert_term_relationships( $this->last_query );
			}

			if ( preg_match( '/\bUPDATE\s+`?wp_comments`?\s+SET\s+comment_approved\s*=/i', $this->last_query ) ) {
				return $this->component_fuzz_query_update_comment_statuses( $this->last_query );
			}

			return 0;
		}

		public function insert( $table, $data, $format = null ) {
			unset( $format );

			$this->rows_affected = 0;
			$table_key           = $this->component_fuzz_table_key( $table );
			$data                = is_array( $data ) ? $data : array();

			if ( 'options' === $table_key ) {
				if ( ! isset( $data['option_name'] ) ) {
					return false;
				}

				$option = (string) $data['option_name'];
				if ( isset( $this->component_fuzz_options[ $option ] ) ) {
					return false;
				}

				$this->component_fuzz_options[ $option ] = array(
					'option_value' => (string) ( $data['option_value'] ?? '' ),
					'autoload'     => (string) ( $data['autoload'] ?? 'auto' ),
				);
				$this->insert_id                        = $this->component_fuzz_next_id( 'options' );
				$this->rows_affected                    = 1;
				return 1;
			}

			if ( 'posts' === $table_key ) {
				$id                                = $this->component_fuzz_row_id( $data, 'ID', 'posts' );
				$row                               = array_merge( $this->component_fuzz_post_defaults(), $data, array( 'ID' => $id ) );
				$this->component_fuzz_posts[ $id ] = $row;
				return $this->component_fuzz_finish_insert( $id );
			}

			if ( 'terms' === $table_key ) {
				$id                                = $this->component_fuzz_row_id( $data, 'term_id', 'terms' );
				$row                               = array_merge( $this->component_fuzz_term_defaults(), $data, array( 'term_id' => $id ) );
				$this->component_fuzz_terms[ $id ] = $row;
				return $this->component_fuzz_finish_insert( $id );
			}

			if ( 'term_taxonomy' === $table_key ) {
				$id                                            = $this->component_fuzz_row_id( $data, 'term_taxonomy_id', 'term_taxonomy' );
				$row                                           = array_merge( $this->component_fuzz_term_taxonomy_defaults(), $data, array( 'term_taxonomy_id' => $id ) );
				$this->component_fuzz_term_taxonomy_rows[ $id ] = $row;
				return $this->component_fuzz_finish_insert( $id );
			}

			if ( 'term_relationships' === $table_key ) {
				if ( ! isset( $data['object_id'], $data['term_taxonomy_id'] ) ) {
					return false;
				}

				$key = (int) $data['object_id'] . ':' . (int) $data['term_taxonomy_id'];
				if ( isset( $this->component_fuzz_term_relationship_rows[ $key ] ) ) {
					return false;
				}

				$this->component_fuzz_term_relationship_rows[ $key ] = array(
					'object_id'        => (int) $data['object_id'],
					'term_taxonomy_id' => (int) $data['term_taxonomy_id'],
					'term_order'       => (int) ( $data['term_order'] ?? 0 ),
				);
				$this->rows_affected                              = 1;
				return 1;
			}

			if ( 'users' === $table_key ) {
				$id                                = $this->component_fuzz_row_id( $data, 'ID', 'users' );
				$row                               = array_merge( $this->component_fuzz_user_defaults(), $data, array( 'ID' => $id ) );
				$this->component_fuzz_users[ $id ] = $row;
				return $this->component_fuzz_finish_insert( $id );
			}

			if ( 'comments' === $table_key ) {
				$id                                   = $this->component_fuzz_row_id( $data, 'comment_ID', 'comments' );
				$row                                  = array_merge( $this->component_fuzz_comment_defaults(), $data, array( 'comment_ID' => $id ) );
				$row['comment_approved']              = (string) $row['comment_approved'];
				$this->component_fuzz_comments[ $id ] = $row;
				return $this->component_fuzz_finish_insert( $id );
			}

			if ( 'links' === $table_key ) {
				$id                                = $this->component_fuzz_row_id( $data, 'link_id', 'links' );
				$row                               = array_merge( $this->component_fuzz_link_defaults(), $data, array( 'link_id' => $id ) );
				$this->component_fuzz_links[ $id ] = $row;
				return $this->component_fuzz_finish_insert( $id );
			}

			$meta_type = $this->component_fuzz_meta_type_for_table_key( $table_key );
			if ( null !== $meta_type ) {
				$id_column  = 'user' === $meta_type ? 'umeta_id' : 'meta_id';
				$object_key = $this->component_fuzz_meta_object_column( $meta_type );
				$id         = $this->component_fuzz_row_id( $data, $id_column, $meta_type . '_meta' );
				$row        = array_merge(
					array(
						$id_column    => $id,
						$object_key   => 0,
						'meta_key'    => '',
						'meta_value'  => '',
					),
					$data,
					array( $id_column => $id )
				);

				$this->component_fuzz_meta[ $meta_type ][ $id ] = $row;
				return $this->component_fuzz_finish_insert( $id );
			}

			return false;
		}

		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			unset( $format, $where_format );

			$this->rows_affected = 0;
			$table_key           = $this->component_fuzz_table_key( $table );
			$data                = is_array( $data ) ? $data : array();
			$where               = is_array( $where ) ? $where : array();

			if ( 'options' === $table_key ) {
				$option = isset( $where['option_name'] ) ? (string) $where['option_name'] : null;

				if ( null === $option || ! isset( $this->component_fuzz_options[ $option ] ) ) {
					return 0;
				}

				foreach ( array( 'option_value', 'autoload' ) as $column ) {
					if ( array_key_exists( $column, $data ) ) {
						$this->component_fuzz_options[ $option ][ $column ] = (string) $data[ $column ];
					}
				}

				$this->rows_affected = 1;
				return 1;
			}

			if ( 'posts' === $table_key ) {
				return $this->component_fuzz_update_rows( $this->component_fuzz_posts, $data, $where );
			}

			if ( 'terms' === $table_key ) {
				return $this->component_fuzz_update_rows( $this->component_fuzz_terms, $data, $where );
			}

			if ( 'term_taxonomy' === $table_key ) {
				return $this->component_fuzz_update_rows( $this->component_fuzz_term_taxonomy_rows, $data, $where );
			}

			if ( 'users' === $table_key ) {
				return $this->component_fuzz_update_rows( $this->component_fuzz_users, $data, $where );
			}

			if ( 'comments' === $table_key ) {
				return $this->component_fuzz_update_rows( $this->component_fuzz_comments, $data, $where );
			}

			if ( 'links' === $table_key ) {
				return $this->component_fuzz_update_rows( $this->component_fuzz_links, $data, $where );
			}

			$meta_type = $this->component_fuzz_meta_type_for_table_key( $table_key );
			if ( null !== $meta_type ) {
				return $this->component_fuzz_update_rows( $this->component_fuzz_meta[ $meta_type ], $data, $where );
			}

			return false;
		}

		public function delete( $table, $where, $where_format = null ) {
			unset( $where_format );

			$this->rows_affected = 0;
			$table_key           = $this->component_fuzz_table_key( $table );
			$where               = is_array( $where ) ? $where : array();

			if ( 'options' === $table_key ) {
				$option = isset( $where['option_name'] ) ? (string) $where['option_name'] : null;

				if ( null === $option || ! isset( $this->component_fuzz_options[ $option ] ) ) {
					return 0;
				}

				unset( $this->component_fuzz_options[ $option ] );
				$this->rows_affected = 1;
				return 1;
			}

			if ( 'posts' === $table_key ) {
				return $this->component_fuzz_delete_rows( $this->component_fuzz_posts, $where );
			}

			if ( 'terms' === $table_key ) {
				return $this->component_fuzz_delete_rows( $this->component_fuzz_terms, $where );
			}

			if ( 'term_taxonomy' === $table_key ) {
				return $this->component_fuzz_delete_rows( $this->component_fuzz_term_taxonomy_rows, $where );
			}

			if ( 'users' === $table_key ) {
				return $this->component_fuzz_delete_rows( $this->component_fuzz_users, $where );
			}

			if ( 'comments' === $table_key ) {
				return $this->component_fuzz_delete_rows( $this->component_fuzz_comments, $where );
			}

			if ( 'links' === $table_key ) {
				return $this->component_fuzz_delete_rows( $this->component_fuzz_links, $where );
			}

			$meta_type = $this->component_fuzz_meta_type_for_table_key( $table_key );
			if ( null !== $meta_type ) {
				return $this->component_fuzz_delete_rows( $this->component_fuzz_meta[ $meta_type ], $where );
			}

			return false;
		}

		public function get_blog_prefix( $blog_id = null ) {
			return 'wp_';
		}

		public function get_col_charset( $table, $column ) {
			unset( $table, $column );
			return 'utf8mb4';
		}

		public function get_col_length( $table, $column ) {
			$lengths = array(
				'comment_author'       => 245,
				'comment_author_email' => 100,
				'comment_author_url'   => 200,
				'comment_content'      => 65525,
				'user_login'           => 60,
				'user_nicename'        => 50,
				'user_email'           => 100,
				'user_url'             => 100,
				'post_title'           => 65535,
				'post_name'            => 200,
			);

			unset( $table );
			return $lengths[ $column ] ?? 255;
		}

		public function strip_invalid_text_for_column( $table, $column, $value ) {
			unset( $table, $column );
			return (string) $value;
		}

		private function component_fuzz_query_insert_option( $query ) {
			$values = $this->component_fuzz_quoted_values( $query );
			if ( count( $values ) < 3 ) {
				return false;
			}

			$option   = (string) $values[0];
			$exists   = isset( $this->component_fuzz_options[ $option ] );
			$autoload = (string) $values[2];

			$this->component_fuzz_options[ $option ] = array(
				'option_value' => $values[1],
				'autoload'     => $autoload,
			);
			$this->rows_affected                     = $exists ? 2 : 1;
			$this->insert_id++;

			return $this->rows_affected;
		}

		private function component_fuzz_query_update_option_autoload( $query ) {
			$autoload = $this->component_fuzz_compare_value( $query, 'autoload' );
			$names    = $this->component_fuzz_in_values( $query, 'option_name' );

			if ( null === $autoload || array() === $names ) {
				return 0;
			}

			foreach ( $names as $option ) {
				$option = (string) $option;
				if ( ! isset( $this->component_fuzz_options[ $option ] ) ) {
					continue;
				}

				if ( (string) $autoload === $this->component_fuzz_options[ $option ]['autoload'] ) {
					continue;
				}

				$this->component_fuzz_options[ $option ]['autoload'] = (string) $autoload;
				++$this->rows_affected;
			}

			return $this->rows_affected;
		}

		private function component_fuzz_record_query( $query ) {
			$this->last_query = (string) $query;
			$this->component_fuzz_queries[] = $this->last_query;
			++$this->num_queries;

			return $this->last_query;
		}

		private function component_fuzz_finish_insert( $id ) {
			$this->insert_id     = (int) $id;
			$this->rows_affected = 1;
			return 1;
		}

		private function component_fuzz_row_id( array $data, $column, $bucket ) {
			if ( isset( $data[ $column ] ) && (int) $data[ $column ] > 0 ) {
				$id = (int) $data[ $column ];
				$this->component_fuzz_bump_next_id( $bucket, $id );
				return $id;
			}

			return $this->component_fuzz_next_id( $bucket );
		}

		private function component_fuzz_next_id( $bucket ) {
			if ( ! isset( $this->component_fuzz_next_ids[ $bucket ] ) ) {
				$this->component_fuzz_next_ids[ $bucket ] = 1;
			}

			return $this->component_fuzz_next_ids[ $bucket ]++;
		}

		private function component_fuzz_bump_next_id( $bucket, $id ) {
			if ( ! isset( $this->component_fuzz_next_ids[ $bucket ] ) || $this->component_fuzz_next_ids[ $bucket ] <= $id ) {
				$this->component_fuzz_next_ids[ $bucket ] = $id + 1;
			}
		}

		private function component_fuzz_update_rows( array &$rows, array $data, array $where ) {
			foreach ( $rows as &$row ) {
				if ( ! $this->component_fuzz_row_matches_where( $row, $where ) ) {
					continue;
				}

				foreach ( $data as $column => $value ) {
					$row[ $column ] = $value;
				}
				++$this->rows_affected;
			}
			unset( $row );

			return $this->rows_affected;
		}

		private function component_fuzz_delete_rows( array &$rows, array $where ) {
			foreach ( $rows as $id => $row ) {
				if ( $this->component_fuzz_row_matches_where( $row, $where ) ) {
					unset( $rows[ $id ] );
					++$this->rows_affected;
				}
			}

			return $this->rows_affected;
		}

		private function component_fuzz_row_matches_where( array $row, array $where ) {
			foreach ( $where as $column => $value ) {
				if ( ! array_key_exists( $column, $row ) || (string) $row[ $column ] !== (string) $value ) {
					return false;
				}
			}

			return true;
		}

		private function component_fuzz_select_rows( $query ) {
			$first_table = $this->component_fuzz_query_first_from_table( $query );

			if ( 'wp_options' === $first_table ) {
				return $this->component_fuzz_select_options( $query );
			}

			if ( 'wp_posts' === $first_table ) {
				return $this->component_fuzz_select_posts( $query );
			}

			if ( 'wp_users' === $first_table ) {
				return $this->component_fuzz_select_users( $query );
			}

			if ( 'wp_comments' === $first_table ) {
				return $this->component_fuzz_select_comments( $query );
			}

			if ( 'wp_links' === $first_table ) {
				return $this->component_fuzz_select_links( $query );
			}

			if ( 'wp_term_relationships' === $first_table ) {
				return $this->component_fuzz_select_term_relationships( $query );
			}

			if ( 'wp_terms' === $first_table || 'wp_term_taxonomy' === $first_table ) {
				return $this->component_fuzz_select_terms( $query );
			}

			$table_key = $this->component_fuzz_table_key_from_query( $query );
			if ( null !== $this->component_fuzz_meta_type_for_table_key( $table_key ) ) {
				return $this->component_fuzz_select_meta( $query, $this->component_fuzz_meta_type_for_table_key( $table_key ) );
			}

			return array();
		}

		private function component_fuzz_query_first_from_table( $query ) {
			if ( ! preg_match( '/^\s*SELECT\b.*?\bFROM\s+`?([A-Za-z0-9_]+)`?\b/is', (string) $query, $matches ) ) {
				return '';
			}

			return strtolower( $matches[1] );
		}

		private function component_fuzz_select_options( $query ) {
			$rows = array();

			if ( preg_match_all( '/\bautoload\s*!=\s*(\'{1,2}(?:\\\\.|[^\'\\\\])*\'{1,2}|"[^"]*"|-?\d+)\s+AND\s+`?option_name`?\s+IN\s*\(([^)]*)\)/i', (string) $query, $matches, PREG_SET_ORDER ) ) {
				$seen = array();
				foreach ( $matches as $match ) {
					$excluded_autoload = (string) $this->component_fuzz_unquote_sql_value( $match[1] );
					$names             = array_fill_keys( $this->component_fuzz_csv_values( $match[2] ), true );
					foreach ( $this->component_fuzz_options as $option => $entry ) {
						if ( isset( $names[ $option ] ) && $excluded_autoload !== (string) $entry['autoload'] && ! isset( $seen[ $option ] ) ) {
							$rows[]          = $this->component_fuzz_option_row( $option, $entry );
							$seen[ $option ] = true;
						}
					}
				}
			} elseif ( preg_match( '/\boption_name\s+IN\s*\(/i', $query ) ) {
				$names = array_fill_keys( $this->component_fuzz_in_values( $query, 'option_name' ), true );
				foreach ( $this->component_fuzz_options as $option => $entry ) {
					if ( isset( $names[ $option ] ) ) {
						$rows[] = $this->component_fuzz_option_row( $option, $entry );
					}
				}
			} elseif ( preg_match( '/\bautoload\s+IN\s*\(/i', $query ) ) {
				$autoload_values = array_fill_keys( $this->component_fuzz_in_values( $query, 'autoload' ), true );
				foreach ( $this->component_fuzz_options as $option => $entry ) {
					if ( isset( $autoload_values[ $entry['autoload'] ] ) ) {
						$rows[] = $this->component_fuzz_option_row( $option, $entry );
					}
				}
			} else {
				$option = $this->component_fuzz_compare_value( $query, 'option_name' );
				if ( null !== $option && isset( $this->component_fuzz_options[ $option ] ) ) {
					$rows[] = $this->component_fuzz_option_row( $option, $this->component_fuzz_options[ $option ] );
				} elseif ( null === $option ) {
					foreach ( $this->component_fuzz_options as $option_name => $entry ) {
						$rows[] = $this->component_fuzz_option_row( $option_name, $entry );
					}
				}
			}

			if ( preg_match( '/SELECT\s+autoload\b/i', $query ) ) {
				return array_map(
					static function ( $row ) {
						return array( 'autoload' => $row['autoload'] );
					},
					$rows
				);
			}

			if ( preg_match( '/SELECT\s+option_value\b/i', $query ) ) {
				return array_map(
					static function ( $row ) {
						return array( 'option_value' => $row['option_value'] );
					},
					$rows
				);
			}

			return $rows;
		}

		private function component_fuzz_option_row( $option, array $entry ) {
			return array(
				'option_name'  => $option,
				'option_value' => $entry['option_value'],
				'autoload'     => $entry['autoload'],
			);
		}

		private function component_fuzz_select_posts( $query ) {
			$rows = array_values( $this->component_fuzz_posts );

			$id = $this->component_fuzz_compare_value( $query, 'ID' );
			if ( null !== $id ) {
				$rows = array_filter(
					$rows,
					static function ( $row ) use ( $id ) {
						return (int) $row['ID'] === (int) $id;
					}
				);
			}

			$ids = $this->component_fuzz_in_values( $query, 'ID' );
			if ( array() !== $ids ) {
				$id_map = array_fill_keys( array_map( 'intval', $ids ), true );
				$rows   = array_filter(
					$rows,
					static function ( $row ) use ( $id_map ) {
						return isset( $id_map[ (int) $row['ID'] ] );
					}
				);
			}

			$id_not = $this->component_fuzz_not_compare_value( $query, 'ID' );
			if ( null !== $id_not ) {
				$rows = array_filter(
					$rows,
					static function ( $row ) use ( $id_not ) {
						return (int) $row['ID'] !== (int) $id_not;
					}
				);
			}

			$status_or_rows    = $this->component_fuzz_filter_posts_by_status_or_branches( $query, array_values( $rows ) );
			$status_or_handled = null !== $status_or_rows;
			if ( $status_or_handled ) {
				$rows = $status_or_rows;
			}

			$author_filter_sql = $status_or_handled ? $this->component_fuzz_top_level_author_filter_sql( $query ) : $query;
			$rows              = $this->component_fuzz_filter_posts_by_author_constraints( $author_filter_sql, $rows );

			foreach ( array( 'post_name', 'post_type', 'post_parent', 'post_status', 'post_password' ) as $column ) {
				if ( $status_or_handled && 'post_status' === $column ) {
					continue;
				}

				if ( 'post_password' === $column ) {
					$values       = $this->component_fuzz_compare_values( $query, $column );
					$conjunctive  = true;
				} elseif ( 'post_status' === $column ) {
					$values       = $this->component_fuzz_compare_values( $query, $column );
					$conjunctive  = false;
				} else {
					$values       = array_filter(
						array( $this->component_fuzz_compare_value( $query, $column ) ),
						static function ( $value ) {
							return null !== $value;
						}
					);
					$conjunctive = true;
				}
				if ( array() === $values ) {
					continue;
				}
				if ( ! $conjunctive ) {
					$value_map = array_fill_keys( array_map( 'strval', $values ), true );
					$rows = array_filter(
						$rows,
						static function ( $row ) use ( $column, $value_map ) {
							return isset( $value_map[ (string) $row[ $column ] ] );
						}
					);
					continue;
				}
				foreach ( $values as $value ) {
					$rows = array_filter(
						$rows,
						static function ( $row ) use ( $column, $value ) {
							return (string) $row[ $column ] === (string) $value;
						}
					);
				}
			}

			foreach ( array( 'post_name', 'post_parent', 'post_status' ) as $column ) {
				if ( $status_or_handled && 'post_status' === $column ) {
					continue;
				}

				$values = $this->component_fuzz_in_values( $query, $column );
				if ( array() === $values ) {
					continue;
				}
				$value_map = array_fill_keys( array_map( 'strval', $values ), true );
				$rows      = array_filter(
					$rows,
					static function ( $row ) use ( $column, $value_map ) {
						return isset( $value_map[ (string) $row[ $column ] ] );
					}
				);
			}

			$post_types = $this->component_fuzz_in_values( $query, 'post_type' );
			if ( array() !== $post_types ) {
				$type_map = array_fill_keys( $post_types, true );
				$rows     = array_filter(
					$rows,
					static function ( $row ) use ( $type_map ) {
						return isset( $type_map[ (string) $row['post_type'] ] );
					}
				);
			}

			$rows = $this->component_fuzz_filter_posts_by_mime_constraints( $query, array_values( $rows ) );

			$rows = $this->component_fuzz_filter_posts_by_search_like( $query, array_values( $rows ) );

			$rows = $this->component_fuzz_sort_post_rows( $query, array_values( $rows ) );

			if ( preg_match( '/\bSQL_CALC_FOUND_ROWS\b/i', $query ) ) {
				$this->component_fuzz_last_found_rows = count( $rows );
			}

			$rows = $this->component_fuzz_apply_limit( $query, $rows );

			if ( preg_match( '/SELECT\s+post_name\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'post_name' ) );
			}

			if ( preg_match( '/SELECT\s+post_author\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'post_author' ) );
			}

			if ( preg_match( '/SELECT\s+ID\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'ID' ) );
			}

			return $rows;
		}

		private function component_fuzz_filter_posts_by_status_or_branches( $query, array $rows ) {
			$where = $this->component_fuzz_where_clause( $query );
			if ( '' === $where || ! preg_match( '/\bOR\b/i', $where ) || ! preg_match( '/post_status/i', $where ) ) {
				return null;
			}

			$status_branches = array();
			foreach ( $this->component_fuzz_split_sql_or_terms( $where ) as $branch ) {
				if ( ! preg_match( '/post_status/i', $branch ) ) {
					continue;
				}

				$statuses = array_values(
					array_unique(
						array_merge(
							$this->component_fuzz_compare_values( $branch, 'post_status' ),
							$this->component_fuzz_in_values( $branch, 'post_status' )
						)
					)
				);

				if ( array() === $statuses ) {
					continue;
				}

				$status_branches[] = array(
					'author_equals' => $this->component_fuzz_compare_values( $branch, 'post_author' ),
					'author_in'     => $this->component_fuzz_in_values( $branch, 'post_author' ),
					'statuses'      => $statuses,
				);
			}

			if ( array() === $status_branches ) {
				return null;
			}

			return array_filter(
				$rows,
				static function ( $row ) use ( $status_branches ) {
					foreach ( $status_branches as $branch ) {
						if ( ! in_array( (string) $row['post_status'], array_map( 'strval', $branch['statuses'] ), true ) ) {
							continue;
						}

						foreach ( $branch['author_equals'] as $author ) {
							if ( (int) $row['post_author'] !== (int) $author ) {
								continue 2;
							}
						}

						if ( array() !== $branch['author_in'] ) {
							$author_map = array_fill_keys( array_map( 'intval', $branch['author_in'] ), true );
							if ( ! isset( $author_map[ (int) $row['post_author'] ] ) ) {
								continue;
							}
						}

						return true;
					}

					return false;
				}
			);
		}

		private function component_fuzz_filter_posts_by_author_constraints( $query, array $rows ) {
			foreach ( $this->component_fuzz_compare_values( $query, 'post_author' ) as $author ) {
				$rows = array_filter(
					$rows,
					static function ( $row ) use ( $author ) {
						return (int) $row['post_author'] === (int) $author;
					}
				);
			}

			$authors = $this->component_fuzz_in_values( $query, 'post_author' );
			if ( array() === $authors ) {
				return $rows;
			}

			$author_map = array_fill_keys( array_map( 'intval', $authors ), true );
			return array_filter(
				$rows,
				static function ( $row ) use ( $author_map ) {
					return isset( $author_map[ (int) $row['post_author'] ] );
				}
			);
		}

		private function component_fuzz_top_level_author_filter_sql( $query ) {
			$where = $this->component_fuzz_where_clause( $query );
			if ( '' === $where ) {
				$where = (string) $query;
			}

			$terms = array();
			foreach ( $this->component_fuzz_split_sql_top_level_terms( $where, 'AND' ) as $term ) {
				if ( ! preg_match( '/post_author/i', $term ) ) {
					continue;
				}

				if ( preg_match( '/\bOR\b/i', $term ) ) {
					continue;
				}

				$terms[] = $term;
			}

			return implode( ' AND ', $terms );
		}

		private function component_fuzz_filter_posts_by_mime_constraints( $query, array $rows ) {
			$where = $this->component_fuzz_where_clause( $query );
			if ( '' === $where || ! preg_match( '/post_mime_type/i', $where ) ) {
				return $rows;
			}

			$exact_values = $this->component_fuzz_compare_values( $where, 'post_mime_type' );
			$like_values  = array();
			if ( preg_match_all( '/(?<![A-Za-z0-9_])(?:`?wp_posts`?\.)?`?post_mime_type`?(?![A-Za-z0-9_])\s+LIKE\s+(\'(?:\\\\.|[^\'\\\\])*\'|"[^"]*")/i', $where, $matches ) ) {
				$like_values = array_map( array( $this, 'component_fuzz_unquote_sql_value' ), $matches[1] );
			}

			if ( array() === $exact_values && array() === $like_values ) {
				return $rows;
			}

			$exact_map = array_fill_keys( array_map( 'strval', $exact_values ), true );
			return array_filter(
				$rows,
				function ( $row ) use ( $exact_map, $like_values ) {
					$mime_type = (string) ( $row['post_mime_type'] ?? '' );
					if ( isset( $exact_map[ $mime_type ] ) ) {
						return true;
					}

					foreach ( $like_values as $pattern ) {
						if ( $this->component_fuzz_sql_like_match( $mime_type, (string) $pattern ) ) {
							return true;
						}
					}

					return false;
				}
			);
		}

		private function component_fuzz_filter_posts_by_search_like( $query, array $rows ) {
			$where = $this->component_fuzz_where_clause( $query );
			if ( '' === $where ) {
				return $rows;
			}

			$patterns = array();
			if ( preg_match_all( '/(?<![A-Za-z0-9_])(?:`?wp_posts`?\.)?`?(post_title|post_excerpt|post_content)`?(?![A-Za-z0-9_])\s+(NOT\s+LIKE|LIKE)\s+(\'(?:\\\\.|[^\'\\\\])*\'|"[^"]*")/i', $where, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$key = strtoupper( preg_replace( '/\s+/', ' ', $match[2] ) ) . "\0" . $this->component_fuzz_unquote_sql_value( $match[3] );
					if ( ! isset( $patterns[ $key ] ) ) {
						$patterns[ $key ] = array(
							'operator' => strtoupper( preg_replace( '/\s+/', ' ', $match[2] ) ),
							'pattern'  => $this->component_fuzz_unquote_sql_value( $match[3] ),
							'columns'  => array(),
						);
					}
					$patterns[ $key ]['columns'][ $match[1] ] = true;
				}
			}

			if ( preg_match_all( '/(?<![A-Za-z0-9_])(?:`?sq1`?\.)?`?meta_value`?(?![A-Za-z0-9_])\s+(NOT\s+LIKE|LIKE)\s+(\'(?:\\\\.|[^\'\\\\])*\'|"[^"]*")/i', $where, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$key = strtoupper( preg_replace( '/\s+/', ' ', $match[1] ) ) . "\0" . $this->component_fuzz_unquote_sql_value( $match[2] );
					if ( ! isset( $patterns[ $key ] ) ) {
						$patterns[ $key ] = array(
							'operator' => strtoupper( preg_replace( '/\s+/', ' ', $match[1] ) ),
							'pattern'  => $this->component_fuzz_unquote_sql_value( $match[2] ),
							'columns'  => array(),
						);
					}
					$patterns[ $key ]['columns']['sq1.meta_value'] = true;
				}
			}

			if ( array() === $patterns ) {
				return $rows;
			}

			return array_filter(
				$rows,
				function ( $row ) use ( $patterns ) {
					foreach ( $patterns as $pattern ) {
						$matched      = false;
						$missing_meta = false;
						foreach ( array_keys( $pattern['columns'] ) as $column ) {
							$values = 'sq1.meta_value' === $column
								? $this->component_fuzz_post_meta_values( (int) $row['ID'], '_wp_attached_file' )
								: array( (string) ( $row[ $column ] ?? '' ) );

							if ( 'sq1.meta_value' === $column && array() === $values ) {
								$missing_meta = true;
							}

							foreach ( $values as $value ) {
								if ( $this->component_fuzz_sql_like_match( (string) $value, (string) $pattern['pattern'] ) ) {
									$matched = true;
									break 2;
								}
							}
						}

						if ( 'LIKE' === $pattern['operator'] && ! $matched ) {
							return false;
						}

						if ( 'NOT LIKE' === $pattern['operator'] && ( $matched || $missing_meta ) ) {
							return false;
						}
					}

					return true;
				}
			);
		}

		private function component_fuzz_sort_post_rows( $query, array $rows ) {
			usort(
				$rows,
				function ( $a, $b ) use ( $query ) {
					if ( preg_match( '/ORDER\s+BY\s+FIELD\s*\(\s*(?:`?wp_posts`?\.)?`?ID`?\s*,\s*([^)]+)\)/i', $query, $matches ) ) {
						$ordered_ids = array_values( array_unique( array_map( 'intval', $this->component_fuzz_csv_values( $matches[1] ) ) ) );
						$positions   = array_flip( $ordered_ids );
						$a_position  = $positions[ (int) $a['ID'] ] ?? PHP_INT_MAX;
						$b_position  = $positions[ (int) $b['ID'] ] ?? PHP_INT_MAX;

						if ( $a_position !== $b_position ) {
							return $a_position <=> $b_position;
						}
					}

					if ( preg_match( '/ORDER\s+BY\s+(?:`?wp_posts`?\.)?`?post_date`?\s+DESC/i', $query ) ) {
						$comparison = strcmp( (string) $b['post_date'], (string) $a['post_date'] );
						if ( 0 !== $comparison ) {
							return $comparison;
						}
						return (int) $b['ID'] <=> (int) $a['ID'];
					}

					if ( preg_match( '/ORDER\s+BY\s+(?:`?wp_posts`?\.)?`?ID`?\s+DESC/i', $query ) ) {
						return (int) $b['ID'] <=> (int) $a['ID'];
					}

					return (int) $a['ID'] <=> (int) $b['ID'];
				}
			);

			return $rows;
		}

		private function component_fuzz_select_users( $query ) {
			$rows = array_values( $this->component_fuzz_users );
			$rows = $this->component_fuzz_filter_users_by_published_posts_subquery( $query, $rows );

			foreach ( array( 'ID', 'user_login', 'user_nicename', 'user_email' ) as $column ) {
				$value = $this->component_fuzz_compare_value( $query, $column );
				if ( null === $value ) {
					continue;
				}

				$rows = array_filter(
					$rows,
					static function ( $row ) use ( $column, $value ) {
						if ( 'user_email' === $column ) {
							return 0 === strcasecmp( (string) $row[ $column ], (string) $value );
						}
						return (string) $row[ $column ] === (string) $value;
					}
				);
			}

			$not_login = $this->component_fuzz_not_compare_value( $query, 'user_login' );
			if ( null !== $not_login ) {
				$rows = array_filter(
					$rows,
					static function ( $row ) use ( $not_login ) {
						return (string) $row['user_login'] !== (string) $not_login;
					}
				);
			}

			$rows = $this->component_fuzz_filter_users_by_search_like( $query, $rows );

			$rows = $this->component_fuzz_sort_user_rows( $query, array_values( $rows ) );

			if ( preg_match( '/\bSQL_CALC_FOUND_ROWS\b/i', $query ) ) {
				$this->component_fuzz_last_found_rows = count( $rows );
			}

			$rows = $this->component_fuzz_apply_limit( $query, $rows );

			if ( preg_match( '/SELECT\s+(?:SQL_CALC_FOUND_ROWS\s+)?(?:`?wp_users`?\.)?`?ID`?\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'ID' ) );
			}

			return $rows;
		}

		private function component_fuzz_filter_users_by_published_posts_subquery( $query, array $rows ) {
			if ( ! preg_match( '/\b(?:`?wp_users`?\.)?`?ID`?\s+IN\s*\(\s*SELECT\s+DISTINCT\s+(?:`?wp_posts`?\.)?`?post_author`?\s+FROM\s+`?wp_posts`?/is', (string) $query ) ) {
				return $rows;
			}

			$post_types = $this->component_fuzz_in_values( $query, 'post_type' );
			$type_map   = array_fill_keys( array_map( 'strval', $post_types ), true );
			$status     = $this->component_fuzz_compare_value( $query, 'post_status' );
			$status     = null === $status ? 'publish' : (string) $status;
			$authors    = array();

			foreach ( $this->component_fuzz_posts as $post ) {
				if ( (string) $post['post_status'] !== $status ) {
					continue;
				}
				if ( array() !== $post_types && ! isset( $type_map[ (string) $post['post_type'] ] ) ) {
					continue;
				}

				$authors[ (int) $post['post_author'] ] = true;
			}

			return array_filter(
				$rows,
				static function ( $row ) use ( $authors ) {
					return isset( $authors[ (int) $row['ID'] ] );
				}
			);
		}

		private function component_fuzz_sort_user_rows( $query, array $rows ) {
			usort(
				$rows,
				static function ( $a, $b ) use ( $query ) {
					$column    = 'ID';
					$direction = 'ASC';

					if ( preg_match( '/ORDER\s+BY\s+(?:`?wp_users`?\.)?`?(ID|user_login|user_nicename|user_email|display_name)`?(?:\s+(ASC|DESC))?/i', (string) $query, $matches ) ) {
						$column    = $matches[1];
						$direction = strtoupper( $matches[2] ?? 'ASC' );
					}

					if ( 'ID' === $column ) {
						$comparison = (int) $a['ID'] <=> (int) $b['ID'];
					} else {
						$comparison = strcasecmp( (string) ( $a[ $column ] ?? '' ), (string) ( $b[ $column ] ?? '' ) );
						if ( 0 === $comparison ) {
							$comparison = (int) $a['ID'] <=> (int) $b['ID'];
						}
					}

					return 'DESC' === $direction ? -$comparison : $comparison;
				}
			);

			return $rows;
		}

		private function component_fuzz_filter_users_by_search_like( $query, array $rows ) {
			if ( ! preg_match_all( '/(?<![A-Za-z0-9_])(?:`?[a-z_][a-z0-9_]*`?\.)?`?(user_login|user_url|user_email|user_nicename|display_name)`?(?![A-Za-z0-9_])\s+LIKE\s+(\'(?:\\\\.|[^\'\\\\])*\'|"[^"]*")/i', (string) $query, $matches, PREG_SET_ORDER ) ) {
				return $rows;
			}

			$patterns = array();
			foreach ( $matches as $match ) {
				$patterns[] = array(
					'column'  => $match[1],
					'pattern' => $this->component_fuzz_unquote_sql_value( $match[2] ),
				);
			}

			return array_filter(
				$rows,
				function ( $row ) use ( $patterns ) {
					foreach ( $patterns as $pattern ) {
						$column = $pattern['column'];
						if ( array_key_exists( $column, $row ) && $this->component_fuzz_sql_like_match( (string) $row[ $column ], (string) $pattern['pattern'] ) ) {
							return true;
						}
					}

					return false;
				}
			);
		}

		private function component_fuzz_select_comments( $query ) {
			$rows = array_values( $this->component_fuzz_comments );

			foreach ( array( 'comment_ID', 'comment_post_ID', 'comment_parent', 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_content', 'comment_approved', 'user_id' ) as $column ) {
				$value = $this->component_fuzz_compare_value( $query, $column );
				if ( null === $value ) {
					continue;
				}
				$rows = array_filter(
					$rows,
					static function ( $row ) use ( $column, $value ) {
						return (string) $row[ $column ] === (string) $value;
					}
				);
			}

			$ids = $this->component_fuzz_in_values( $query, 'comment_ID' );
			if ( array() !== $ids ) {
				$id_map = array_fill_keys( array_map( 'intval', $ids ), true );
				$rows   = array_filter(
					$rows,
					static function ( $row ) use ( $id_map ) {
						return isset( $id_map[ (int) $row['comment_ID'] ] );
					}
				);
			}

			if ( preg_match( '/comment_approved\s*!=\s*([^\s)]+)/i', $query, $matches ) ) {
				$not_approved = $this->component_fuzz_unquote_sql_value( $matches[1] );
				$rows         = array_filter(
					$rows,
					static function ( $row ) use ( $not_approved ) {
						return (string) $row['comment_approved'] !== (string) $not_approved;
					}
				);
			}

			if ( preg_match( '/comment_type\s*!=\s*([^\s)]+)/i', $query, $matches ) ) {
				$not_type = $this->component_fuzz_unquote_sql_value( $matches[1] );
				$rows     = array_filter(
					$rows,
					static function ( $row ) use ( $not_type ) {
						return (string) $row['comment_type'] !== (string) $not_type;
					}
				);
			}

			usort(
				$rows,
				static function ( $a, $b ) use ( $query ) {
					if ( preg_match( '/ORDER\s+BY\s+comment_ID\s+DESC/i', $query ) ) {
						return (int) $b['comment_ID'] <=> (int) $a['comment_ID'];
					}
					return (int) $a['comment_ID'] <=> (int) $b['comment_ID'];
				}
			);

			$rows = $this->component_fuzz_apply_limit( $query, $rows );

			if ( preg_match( '/SELECT\s+comment_ID\s*,\s*comment_approved\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'comment_ID', 'comment_approved' ) );
			}

			if ( preg_match( '/SELECT\s+comment_ID\s*,\s*comment_agent\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'comment_ID', 'comment_agent' ) );
			}

			if ( preg_match( '/SELECT\s+comment_author_url\s*,\s*comment_content\s*,\s*comment_author_IP\s*,\s*comment_type\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'comment_author_url', 'comment_content', 'comment_author_IP', 'comment_type' ) );
			}

			if ( preg_match( '/SELECT\s+comment_ID\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'comment_ID' ) );
			}

			if ( preg_match( '/SELECT\s+comment_date_gmt\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'comment_date_gmt' ) );
			}

			return $rows;
		}

		private function component_fuzz_select_links( $query ) {
			$rows = array_values( $this->component_fuzz_links );
			$rows = $this->component_fuzz_maybe_join_links_to_link_categories( $query, $rows );

			if ( preg_match( '/\bCHAR_LENGTH\s*\(\s*link_name\s*\)\s+AS\s+length\b/i', $query ) ) {
				foreach ( $rows as &$row ) {
					$row['length'] = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $row['link_name'], 'UTF-8' ) : strlen( (string) $row['link_name'] );
				}
				unset( $row );
			}

			if ( preg_match( '/\brecently_updated\b/i', $query ) || preg_match( '/\blink_updated_f\b/i', $query ) ) {
				foreach ( $rows as &$row ) {
					$updated_timestamp      = strtotime( (string) $row['link_updated'] );
					$row['link_updated_f']  = false === $updated_timestamp ? '0' : (string) $updated_timestamp;
					$row['recently_updated'] = false !== $updated_timestamp && $updated_timestamp + 120 * MINUTE_IN_SECONDS >= time() ? '1' : '0';
				}
				unset( $row );
			}

			foreach ( array( 'link_id', 'link_owner', 'link_visible' ) as $column ) {
				$values = $this->component_fuzz_all_compare_values( $query, $column, '=' );
				if ( array() === $values ) {
					continue;
				}

				$value_map = array_fill_keys( array_map( 'strval', $values ), true );
				$rows      = array_filter(
					$rows,
					static function ( $row ) use ( $column, $value_map ) {
						return isset( $value_map[ (string) $row[ $column ] ] );
					}
				);
			}

			$excluded_ids = $this->component_fuzz_all_compare_values( $query, 'link_id', '<>' );
			if ( array() === $excluded_ids ) {
				$excluded_ids = $this->component_fuzz_all_compare_values( $query, 'link_id', '!=' );
			}
			if ( array() !== $excluded_ids ) {
				$excluded_map = array_fill_keys( array_map( 'intval', $excluded_ids ), true );
				$rows         = array_filter(
					$rows,
					static function ( $row ) use ( $excluded_map ) {
						return ! isset( $excluded_map[ (int) $row['link_id'] ] );
					}
				);
			}

			$term_ids = $this->component_fuzz_all_compare_values( $query, 'term_id', '=' );
			if ( array() !== $term_ids ) {
				$term_map = array_fill_keys( array_map( 'intval', $term_ids ), true );
				$rows     = array_filter(
					$rows,
					static function ( $row ) use ( $term_map ) {
						return isset( $row['term_id'] ) && isset( $term_map[ (int) $row['term_id'] ] );
					}
				);
			}

			$taxonomy = $this->component_fuzz_compare_value( $query, 'taxonomy' );
			if ( null !== $taxonomy ) {
				$rows = array_filter(
					$rows,
					static function ( $row ) use ( $taxonomy ) {
						return isset( $row['taxonomy'] ) && (string) $row['taxonomy'] === (string) $taxonomy;
					}
				);
			}

			$rows = $this->component_fuzz_filter_links_by_search_like( $query, $rows );
			$rows = $this->component_fuzz_sort_link_rows( $query, array_values( $rows ) );
			$rows = $this->component_fuzz_apply_limit( $query, $rows );

			if ( preg_match( '/SELECT\s+link_id\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'link_id' ) );
			}

			return $rows;
		}

		private function component_fuzz_maybe_join_links_to_link_categories( $query, array $link_rows ) {
			if ( ! preg_match( '/\bwp_term_relationships\b/i', $query ) && ! preg_match( '/\bwp_term_taxonomy\b/i', $query ) ) {
				return $link_rows;
			}

			$joined = array();
			foreach ( $link_rows as $link_row ) {
				foreach ( $this->component_fuzz_term_relationship_rows as $relationship ) {
					if ( (int) $relationship['object_id'] !== (int) $link_row['link_id'] ) {
						continue;
					}

					$tt_id = (int) $relationship['term_taxonomy_id'];
					if ( ! isset( $this->component_fuzz_term_taxonomy_rows[ $tt_id ] ) ) {
						continue;
					}

					$joined[] = array_merge( $link_row, $relationship, $this->component_fuzz_term_taxonomy_rows[ $tt_id ] );
				}
			}

			return $joined;
		}

		private function component_fuzz_filter_links_by_search_like( $query, array $rows ) {
			if ( ! preg_match( '/\blink_url\s+LIKE\s+(\'(?:\\\\.|[^\'\\\\])*\')/i', $query, $matches ) ) {
				return $rows;
			}

			$needle = $this->component_fuzz_unquote_sql_value( $matches[1] );
			$needle = str_replace( array( '\\%', '\\_' ), array( '%', '_' ), trim( $needle, '%' ) );

			return array_filter(
				$rows,
				static function ( $row ) use ( $needle ) {
					foreach ( array( 'link_url', 'link_name', 'link_description' ) as $column ) {
						if ( false !== stripos( (string) $row[ $column ], $needle ) ) {
							return true;
						}
					}
					return false;
				}
			);
		}

		private function component_fuzz_sql_like_match( $value, $pattern ) {
			$regex  = '';
			$length = strlen( (string) $pattern );
			for ( $i = 0; $i < $length; $i++ ) {
				$char = $pattern[ $i ];
				if ( '\\' === $char && $i + 1 < $length ) {
					$regex .= preg_quote( $pattern[ ++$i ], '/' );
					continue;
				}

				if ( '%' === $char ) {
					$regex .= '.*';
					continue;
				}

				if ( '_' === $char ) {
					$regex .= '.';
					continue;
				}

				$regex .= preg_quote( $char, '/' );
			}

			return 1 === preg_match( '/\A' . $regex . '\z/is', (string) $value );
		}

		private function component_fuzz_sort_link_rows( $query, array $rows ) {
			if ( ! preg_match( '/\bORDER\s+BY\s+(.+?)(?:\s+LIMIT\s+\d+|\z)/is', $query, $matches ) ) {
				return array_values( $rows );
			}

			$order_expression = trim( $matches[1] );
			if ( preg_match( '/\brand\s*\(\s*\)/i', $order_expression ) ) {
				usort(
					$rows,
					static function ( $a, $b ) use ( $query ) {
						return strcmp(
							md5( $query . ':' . (string) $a['link_id'] ),
							md5( $query . ':' . (string) $b['link_id'] )
						);
					}
				);
				return $rows;
			}

			$order = 'ASC';
			if ( preg_match( '/\s+(ASC|DESC)\s*$/i', $order_expression, $order_match ) ) {
				$order            = strtoupper( $order_match[1] );
				$order_expression = trim( substr( $order_expression, 0, -strlen( $order_match[0] ) ) );
			}

			$columns = array_filter(
				array_map(
					array( $this, 'component_fuzz_normalize_link_orderby_column' ),
					explode( ',', $order_expression )
				)
			);

			if ( array() === $columns ) {
				$columns = array( 'link_name' );
			}

			usort(
				$rows,
				static function ( $a, $b ) use ( $columns, $order ) {
					foreach ( $columns as $column ) {
						if ( in_array( $column, array( 'link_id', 'link_owner', 'link_rating', 'length' ), true ) ) {
							$comparison = (int) ( $a[ $column ] ?? 0 ) <=> (int) ( $b[ $column ] ?? 0 );
						} else {
							$comparison = strcasecmp( (string) ( $a[ $column ] ?? '' ), (string) ( $b[ $column ] ?? '' ) );
						}

						if ( 0 !== $comparison ) {
							return 'DESC' === $order ? -$comparison : $comparison;
						}
					}

					$comparison = (int) $a['link_id'] <=> (int) $b['link_id'];
					return 'DESC' === $order ? -$comparison : $comparison;
				}
			);

			return $rows;
		}

		private function component_fuzz_normalize_link_orderby_column( $column ) {
			$column = trim( (string) $column, "` \t\n\r\0\x0B" );
			$column = preg_replace( '/^`?wp_links`?\./i', '', $column );
			$column = trim( (string) $column, "` \t\n\r\0\x0B" );

			if ( 'length' === $column ) {
				return 'length';
			}

			$allowed = array(
				'link_id',
				'link_name',
				'link_url',
				'link_visible',
				'link_rating',
				'link_owner',
				'link_updated',
				'link_notes',
				'link_description',
			);

			return in_array( $column, $allowed, true ) ? $column : '';
		}

		private function component_fuzz_select_term_relationships( $query ) {
			$rows = array_values( $this->component_fuzz_term_relationship_rows );

			if (
				preg_match( '/\bwp_term_taxonomy\b/i', $query )
				|| preg_match( '/\btt\./i', $query )
				|| preg_match( '/\bterm_id\b/i', $query )
				|| preg_match( '/\btaxonomy\b/i', $query )
			) {
				$joined = array();
				foreach ( $rows as $row ) {
					$tt_id = (int) $row['term_taxonomy_id'];
					if ( ! isset( $this->component_fuzz_term_taxonomy_rows[ $tt_id ] ) ) {
						continue;
					}
					$joined[] = array_merge( $this->component_fuzz_term_taxonomy_rows[ $tt_id ], $row );
				}
				$rows = $joined;
			}

			$rows = $this->component_fuzz_filter_relationships_by_posts( $query, $rows );

			foreach ( array( 'object_id', 'term_taxonomy_id', 'term_id', 'taxonomy' ) as $column ) {
				$value = $this->component_fuzz_compare_value( $query, $column );
				if ( null === $value ) {
					continue;
				}

				$rows = array_filter(
					$rows,
					static function ( $row ) use ( $column, $value ) {
						return array_key_exists( $column, $row ) && (string) $row[ $column ] === (string) $value;
					}
				);
			}

			foreach ( array( 'object_id', 'term_taxonomy_id', 'term_id', 'taxonomy' ) as $column ) {
				$values = $this->component_fuzz_in_values( $query, $column );
				if ( array() === $values ) {
					continue;
				}

				$value_map = array_fill_keys( array_map( 'strval', $values ), true );
				$rows      = array_filter(
					$rows,
					static function ( $row ) use ( $column, $value_map ) {
						return array_key_exists( $column, $row ) && isset( $value_map[ (string) $row[ $column ] ] );
					}
				);
			}

			usort(
				$rows,
				static function ( $a, $b ) use ( $query ) {
					$comparison = (int) $a['object_id'] <=> (int) $b['object_id'];
					if ( 0 === $comparison ) {
						$comparison = (int) $a['term_taxonomy_id'] <=> (int) $b['term_taxonomy_id'];
					}

					if ( preg_match( '/ORDER\s+BY\s+(?:`?tr`?\.)?`?object_id`?\s+DESC/i', $query ) ) {
						return -$comparison;
					}

					return $comparison;
				}
			);

			$rows = $this->component_fuzz_apply_limit( $query, $rows );

			if ( preg_match( '/SELECT\s+(?:`?[a-z_]+`?\.)?`?object_id`?\s*,\s*(?:`?[a-z_]+`?\.)?`?term_taxonomy_id`?\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'object_id', 'term_taxonomy_id' ) );
			}

			if ( preg_match( '/SELECT\s+(?:DISTINCT\s+)?(?:`?[a-z_]+`?\.)?`?object_id`?\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'object_id' ) );
			}

			if ( preg_match( '/SELECT\s+(?:DISTINCT\s+)?(?:`?[a-z_]+`?\.)?`?term_taxonomy_id`?\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'term_taxonomy_id' ) );
			}

			return $rows;
		}

		private function component_fuzz_filter_relationships_by_posts( $query, array $rows ) {
			if ( ! preg_match( '/\bwp_posts\b/i', $query ) ) {
				return $rows;
			}

			$post_statuses = $this->component_fuzz_in_values( $query, 'post_status' );
			$post_types    = $this->component_fuzz_in_values( $query, 'post_type' );
			$post_status   = $this->component_fuzz_compare_value( $query, 'post_status' );
			$post_type     = $this->component_fuzz_compare_value( $query, 'post_type' );
			$status_map    = array_fill_keys( array_map( 'strval', $post_statuses ), true );
			$type_map      = array_fill_keys( array_map( 'strval', $post_types ), true );

			return array_values(
				array_filter(
					$rows,
					function ( $row ) use ( $post_status, $post_type, $post_statuses, $post_types, $status_map, $type_map ) {
						$object_id = (int) $row['object_id'];
						if ( ! isset( $this->component_fuzz_posts[ $object_id ] ) ) {
							return false;
						}

						$post = $this->component_fuzz_posts[ $object_id ];
						if ( null !== $post_status && (string) $post['post_status'] !== (string) $post_status ) {
							return false;
						}
						if ( null !== $post_type && (string) $post['post_type'] !== (string) $post_type ) {
							return false;
						}
						if ( array() !== $post_statuses && ! isset( $status_map[ (string) $post['post_status'] ] ) ) {
							return false;
						}
						if ( array() !== $post_types && ! isset( $type_map[ (string) $post['post_type'] ] ) ) {
							return false;
						}

						return true;
					}
				)
			);
		}

		private function component_fuzz_select_terms( $query ) {
			$rows = $this->component_fuzz_joined_term_rows();

			$taxonomy = $this->component_fuzz_compare_value( $query, 'taxonomy' );
			if ( null !== $taxonomy ) {
				$rows = array_filter(
					$rows,
					static function ( $row ) use ( $taxonomy ) {
						return (string) $row['taxonomy'] === (string) $taxonomy;
					}
				);
			}

			$taxonomies = $this->component_fuzz_in_values( $query, 'taxonomy' );
			if ( array() !== $taxonomies ) {
				$taxonomy_map = array_fill_keys( $taxonomies, true );
				$rows         = array_filter(
					$rows,
					static function ( $row ) use ( $taxonomy_map ) {
						return isset( $taxonomy_map[ (string) $row['taxonomy'] ] );
					}
				);
			}

			foreach ( array( 'term_id', 'term_taxonomy_id', 'name', 'slug', 'parent' ) as $column ) {
				$value = $this->component_fuzz_compare_value( $query, $column );
				if ( null === $value ) {
					continue;
				}
				$rows = array_filter(
					$rows,
					static function ( $row ) use ( $column, $value ) {
						return (string) $row[ $column ] === (string) $value;
					}
				);
			}

			foreach ( array( 'term_id', 'term_taxonomy_id', 'name', 'slug' ) as $column ) {
				$values = $this->component_fuzz_in_values( $query, $column );
				if ( array() === $values ) {
					continue;
				}
				$value_map = array_fill_keys( array_map( 'strval', $values ), true );
				$rows      = array_filter(
					$rows,
					static function ( $row ) use ( $column, $value_map ) {
						return isset( $value_map[ (string) $row[ $column ] ] );
					}
				);
			}

			$term_id_less_than = $this->component_fuzz_less_than_value( $query, 'term_id' );
			if ( null !== $term_id_less_than ) {
				$rows = array_filter(
					$rows,
					static function ( $row ) use ( $term_id_less_than ) {
						return (int) $row['term_id'] < (int) $term_id_less_than;
					}
				);
			}

			$tt_not = $this->component_fuzz_not_compare_value( $query, 'term_taxonomy_id' );
			if ( null !== $tt_not ) {
				$rows = array_filter(
					$rows,
					static function ( $row ) use ( $tt_not ) {
						return (int) $row['term_taxonomy_id'] !== (int) $tt_not;
					}
				);
			}

			if ( preg_match( '/tt\.count\s*>\s*0/i', $query ) ) {
				$rows = array_filter(
					$rows,
					static function ( $row ) {
						return (int) $row['count'] > 0;
					}
				);
			}

			$object_ids = $this->component_fuzz_in_values( $query, 'object_id' );
			if ( array() !== $object_ids ) {
				$object_map = array_fill_keys( array_map( 'intval', $object_ids ), true );
				$tt_map     = array();
				foreach ( $this->component_fuzz_term_relationship_rows as $relationship ) {
					if ( isset( $object_map[ (int) $relationship['object_id'] ] ) ) {
						$tt_map[ (int) $relationship['term_taxonomy_id'] ][] = (int) $relationship['object_id'];
					}
				}

				$expanded_rows = array();
				foreach ( $rows as $row ) {
					$tt_id = (int) $row['term_taxonomy_id'];
					if ( ! isset( $tt_map[ $tt_id ] ) ) {
						continue;
					}

					foreach ( array_unique( $tt_map[ $tt_id ] ) as $object_id ) {
						$row['object_id'] = $object_id;
						$expanded_rows[]  = $row;
					}
				}
				$rows = $expanded_rows;
			}

			$rows = $this->component_fuzz_sort_term_rows( $query, array_values( $rows ) );
			if ( preg_match( '/SELECT\s+DISTINCT\s+t\.term_id\b/i', $query )
				&& ! preg_match( '/SELECT\s+DISTINCT\s+t\.term_id\s*,\s*tr\.object_id\b/i', $query )
			) {
				$rows = $this->component_fuzz_distinct_rows( $rows, array( 'term_id' ) );
			}
			$rows = $this->component_fuzz_apply_limit( $query, $rows );

			if ( preg_match( '/SELECT\s+tt\.term_id\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'term_id' ) );
			}

			if ( preg_match( '/SELECT\s+t\.term_id\s*,\s*t\.slug\s*,\s*tt\.term_taxonomy_id\s*,\s*tt\.taxonomy/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'term_id', 'slug', 'term_taxonomy_id', 'taxonomy' ) );
			}

			if ( preg_match( '/SELECT\s+(?:DISTINCT\s+)?t\.term_id\s*,\s*tr\.object_id\b/i', $query ) ) {
				return $this->component_fuzz_project_rows(
					$rows,
					array(
						'term_id',
						'name',
						'slug',
						'term_group',
						'term_taxonomy_id',
						'taxonomy',
						'description',
						'parent',
						'count',
						'object_id',
					)
				);
			}

			if ( preg_match( '/SELECT\s+t\.term_id\b/i', $query ) || preg_match( '/SELECT\s+DISTINCT\s+t\.term_id\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'term_id' ) );
			}

			if ( preg_match( '/SELECT\s+tt\.term_taxonomy_id\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'term_taxonomy_id' ) );
			}

			return $rows;
		}

		private function component_fuzz_select_meta( $query, $meta_type ) {
			$rows        = array_values( $this->component_fuzz_meta[ $meta_type ] );
			$id_column   = 'user' === $meta_type ? 'umeta_id' : 'meta_id';
			$object_key  = $this->component_fuzz_meta_object_column( $meta_type );
			$meta_key    = $this->component_fuzz_compare_value( $query, 'meta_key' );
			$object_id   = $this->component_fuzz_compare_value( $query, $object_key );
			$meta_id     = $this->component_fuzz_compare_value( $query, $id_column );
			$meta_value  = $this->component_fuzz_compare_value( $query, 'meta_value' );
			$object_ids  = $this->component_fuzz_in_values( $query, $object_key );
			$meta_ids    = $this->component_fuzz_in_values( $query, $id_column );
			$filter_spec = array(
				'meta_key'   => $meta_key,
				$object_key  => $object_id,
				$id_column   => $meta_id,
				'meta_value' => $meta_value,
			);

			foreach ( $filter_spec as $column => $value ) {
				if ( null === $value ) {
					continue;
				}
				$rows = array_filter(
					$rows,
					static function ( $row ) use ( $column, $value ) {
						return (string) $row[ $column ] === (string) $value;
					}
				);
			}

			if ( array() !== $object_ids ) {
				$object_map = array_fill_keys( array_map( 'intval', $object_ids ), true );
				$rows       = array_filter(
					$rows,
					static function ( $row ) use ( $object_key, $object_map ) {
						return isset( $object_map[ (int) $row[ $object_key ] ] );
					}
				);
			}

			if ( array() !== $meta_ids ) {
				$meta_map = array_fill_keys( array_map( 'intval', $meta_ids ), true );
				$rows     = array_filter(
					$rows,
					static function ( $row ) use ( $id_column, $meta_map ) {
						return isset( $meta_map[ (int) $row[ $id_column ] ] );
					}
				);
			}

			usort(
				$rows,
				static function ( $a, $b ) use ( $id_column ) {
					return (int) $a[ $id_column ] <=> (int) $b[ $id_column ];
				}
			);

			if ( preg_match( '/SELECT\s+COUNT\(\s*' . preg_quote( $object_key, '/' ) . '\s*\)\s+AS\s+cnt\b/i', $query ) ) {
				return array( array( 'cnt' => count( $rows ) ) );
			}

			$rows = $this->component_fuzz_apply_limit( $query, $rows );

			if ( preg_match( '/SELECT\s+' . preg_quote( $id_column, '/' ) . '\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( $id_column ) );
			}

			if ( preg_match( '/SELECT\s+' . preg_quote( $object_key, '/' ) . '\s*,\s*meta_key\s*,\s*meta_value\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( $object_key, 'meta_key', 'meta_value' ) );
			}

			if ( preg_match( '/SELECT\s+' . preg_quote( $object_key, '/' ) . '\s*,\s*meta_value\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( $object_key, 'meta_value' ) );
			}

			if ( preg_match( '/SELECT\s+' . preg_quote( $object_key, '/' ) . '\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( $object_key ) );
			}

			return $rows;
		}

		private function component_fuzz_count_for_query( $query ) {
			if ( preg_match( '/\bFROM\s+`?wp_comments`?\b/i', $query ) ) {
				return count( $this->component_fuzz_select_comments( $query ) );
			}

			if ( preg_match( '/\bFROM\s+`?wp_links`?\b/i', $query ) ) {
				return count( $this->component_fuzz_select_links( $query ) );
			}

			if ( preg_match( '/\bFROM\s+`?wp_term_relationships`?\b/i', $query ) ) {
				return count( $this->component_fuzz_select_term_relationships( $query ) );
			}

			if ( preg_match( '/\bFROM\s+`?wp_terms`?\b/i', $query ) || preg_match( '/\bFROM\s+`?wp_term_taxonomy`?\b/i', $query ) ) {
				return count( $this->component_fuzz_select_terms( $this->component_fuzz_without_limit_clause( $query ) ) );
			}

			$table_key = $this->component_fuzz_table_key_from_query( $query );
			$meta_type = $this->component_fuzz_meta_type_for_table_key( $table_key );
			if ( null !== $meta_type ) {
				return count( $this->component_fuzz_select_meta( $query, $meta_type ) );
			}

			return 0;
		}

		private function component_fuzz_joined_term_rows() {
			$rows = array();

			foreach ( $this->component_fuzz_term_taxonomy_rows as $tt_row ) {
				$term_id = (int) $tt_row['term_id'];
				if ( ! isset( $this->component_fuzz_terms[ $term_id ] ) ) {
					continue;
				}
				$rows[] = array_merge( $this->component_fuzz_terms[ $term_id ], $tt_row );
			}

			return $rows;
		}

		private function component_fuzz_sort_term_rows( $query, array $rows ) {
			usort(
				$rows,
				static function ( $a, $b ) use ( $query ) {
					if ( preg_match( '/ORDER\s+BY\s+t\.name\b/i', $query ) ) {
						$comparison = strcasecmp( (string) $a['name'], (string) $b['name'] );
					} elseif ( preg_match( '/ORDER\s+BY\s+t\.slug\b/i', $query ) ) {
						$comparison = strcmp( (string) $a['slug'], (string) $b['slug'] );
					} elseif ( preg_match( '/ORDER\s+BY\s+tt\.term_taxonomy_id\b/i', $query ) ) {
						$comparison = (int) $a['term_taxonomy_id'] <=> (int) $b['term_taxonomy_id'];
					} else {
						$comparison = (int) $a['term_id'] <=> (int) $b['term_id'];
					}

					if ( preg_match( '/\bDESC\b/i', $query ) ) {
						return -$comparison;
					}

					return $comparison;
				}
			);

			return $rows;
		}

		private function component_fuzz_project_rows( array $rows, array $columns ) {
			$projected = array();

			foreach ( $rows as $row ) {
				$out = array();
				foreach ( $columns as $column ) {
					$out[ $column ] = $row[ $column ] ?? null;
				}
				$projected[] = $out;
			}

			return $projected;
		}

		private function component_fuzz_distinct_rows( array $rows, array $columns ) {
			$seen     = array();
			$distinct = array();

			foreach ( $rows as $row ) {
				$key_values = array();
				foreach ( $columns as $column ) {
					$key_values[] = $row[ $column ] ?? null;
				}
				$key = serialize( $key_values );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				$distinct[]   = $row;
			}

			return $distinct;
		}

		private function component_fuzz_apply_limit( $query, array $rows ) {
			if ( preg_match( '/\bLIMIT\s+[\'"]?(\d+)[\'"]?\s*,\s*[\'"]?(\d+)[\'"]?/i', $query, $matches ) ) {
				return array_slice( array_values( $rows ), (int) $matches[1], (int) $matches[2] );
			}

			if ( preg_match( '/\bLIMIT\s+[\'"]?(\d+)[\'"]?/i', $query, $matches ) ) {
				return array_slice( array_values( $rows ), 0, (int) $matches[1] );
			}

			return array_values( $rows );
		}

		private function component_fuzz_without_limit_clause( $query ) {
			return preg_replace( '/\s+LIMIT\s+[\'"]?\d+[\'"]?(?:\s*,\s*[\'"]?\d+[\'"]?)?\s*$/i', '', (string) $query );
		}

		private function component_fuzz_table_key( $table ) {
			$table = trim( (string) $table, "` \t\n\r\0\x0B" );

			$map = array(
				$this->options            => 'options',
				$this->posts              => 'posts',
				$this->terms              => 'terms',
				$this->term_taxonomy      => 'term_taxonomy',
				$this->term_relationships => 'term_relationships',
				$this->users              => 'users',
				$this->comments           => 'comments',
				$this->links              => 'links',
				$this->postmeta           => 'post_meta',
				$this->termmeta           => 'term_meta',
				$this->commentmeta        => 'comment_meta',
				$this->usermeta           => 'user_meta',
			);

			if ( isset( $map[ $table ] ) ) {
				return $map[ $table ];
			}

			if ( str_starts_with( $table, $this->prefix ) ) {
				return str_replace( 'wp_', '', $table );
			}

			return $table;
		}

		private function component_fuzz_table_key_from_query( $query ) {
			if ( preg_match( '/\bFROM\s+`?(wp_[a-z_]+)`?/i', $query, $matches ) ) {
				return $this->component_fuzz_table_key( $matches[1] );
			}

			return '';
		}

		private function component_fuzz_where_clause( $query ) {
			$sql = (string) $query;
			if ( ! preg_match( '/\bWHERE\b/i', $sql, $matches, PREG_OFFSET_CAPTURE ) ) {
				return '';
			}

			$start = $matches[0][1] + strlen( $matches[0][0] );
			$end   = $this->component_fuzz_sql_clause_boundary( $sql, $start, array( 'GROUP BY', 'ORDER BY', 'LIMIT' ) );

			return substr( $sql, $start, $end - $start );
		}

		private function component_fuzz_sql_clause_boundary( $sql, $start, array $keywords ) {
			$length    = strlen( (string) $sql );
			$in_string = false;
			$quote     = '';
			$escaped   = false;

			for ( $i = (int) $start; $i < $length; $i++ ) {
				$char = $sql[ $i ];

				if ( $in_string ) {
					if ( '\\' === $char && ! $escaped ) {
						$escaped = true;
						continue;
					}

					if ( $quote === $char && ! $escaped ) {
						$in_string = false;
					}

					$escaped = false;
					continue;
				}

				if ( "'" === $char || '"' === $char ) {
					$in_string = true;
					$quote     = $char;
					$escaped   = false;
					continue;
				}

				foreach ( $keywords as $keyword ) {
					if ( null !== $this->component_fuzz_sql_keyword_match_end_at( $sql, $i, $keyword ) ) {
						return $i;
					}
				}
			}

			return $length;
		}

		private function component_fuzz_split_sql_or_terms( $sql ) {
			$terms     = array();
			$current   = '';
			$length    = strlen( (string) $sql );
			$in_string = false;
			$quote     = '';
			$escaped   = false;

			for ( $i = 0; $i < $length; $i++ ) {
				$char = $sql[ $i ];

				if ( $in_string ) {
					$current .= $char;
					if ( '\\' === $char && ! $escaped ) {
						$escaped = true;
						continue;
					}

					if ( $quote === $char && ! $escaped ) {
						$in_string = false;
					}

					$escaped = false;
					continue;
				}

				if ( "'" === $char || '"' === $char ) {
					$in_string = true;
					$quote     = $char;
					$escaped   = false;
					$current  .= $char;
					continue;
				}

				$end = $this->component_fuzz_sql_keyword_match_end_at( $sql, $i, 'OR' );
				if ( null !== $end ) {
					$terms[] = $current;
					$current = '';
					$i       = $end - 1;
					continue;
				}

				$current .= $char;
			}

			$terms[] = $current;
			return $terms;
		}

		private function component_fuzz_split_sql_top_level_terms( $sql, $keyword ) {
			$terms     = array();
			$current   = '';
			$length    = strlen( (string) $sql );
			$depth     = 0;
			$in_string = false;
			$quote     = '';
			$escaped   = false;

			for ( $i = 0; $i < $length; $i++ ) {
				$char = $sql[ $i ];

				if ( $in_string ) {
					$current .= $char;
					if ( '\\' === $char && ! $escaped ) {
						$escaped = true;
						continue;
					}

					if ( $quote === $char && ! $escaped ) {
						$in_string = false;
					}

					$escaped = false;
					continue;
				}

				if ( "'" === $char || '"' === $char ) {
					$in_string = true;
					$quote     = $char;
					$escaped   = false;
					$current  .= $char;
					continue;
				}

				if ( '(' === $char ) {
					++$depth;
					$current .= $char;
					continue;
				}

				if ( ')' === $char ) {
					$depth = max( 0, $depth - 1 );
					$current .= $char;
					continue;
				}

				$end = 0 === $depth ? $this->component_fuzz_sql_keyword_match_end_at( $sql, $i, $keyword ) : null;
				if ( null !== $end ) {
					$terms[] = $current;
					$current = '';
					$i       = $end - 1;
					continue;
				}

				$current .= $char;
			}

			$terms[] = $current;
			return $terms;
		}

		private function component_fuzz_sql_keyword_match_end_at( $sql, $offset, $keyword ) {
			if ( $offset > 0 && preg_match( '/[A-Za-z0-9_]/', $sql[ $offset - 1 ] ) ) {
				return null;
			}

			$parts   = preg_split( '/\s+/', trim( (string) $keyword ) );
			$pattern = '/\A' . implode( '\s+', array_map( 'preg_quote', $parts ) ) . '\b/i';
			if ( ! preg_match( $pattern, substr( (string) $sql, (int) $offset ), $matches ) ) {
				return null;
			}

			return (int) $offset + strlen( $matches[0] );
		}

		private function component_fuzz_meta_type_for_table_key( $table_key ) {
			$map = array(
				'post_meta'    => 'post',
				'term_meta'    => 'term',
				'comment_meta' => 'comment',
				'user_meta'    => 'user',
				'postmeta'     => 'post',
				'termmeta'     => 'term',
				'commentmeta'  => 'comment',
				'usermeta'     => 'user',
			);

			return $map[ $table_key ] ?? null;
		}

		private function component_fuzz_meta_object_column( $meta_type ) {
			return $meta_type . '_id';
		}

		private function component_fuzz_post_meta_values( $post_id, $meta_key ) {
			$values = array();
			foreach ( $this->component_fuzz_meta['post'] as $row ) {
				if ( (int) $row['post_id'] === (int) $post_id && (string) $row['meta_key'] === (string) $meta_key ) {
					$values[] = (string) $row['meta_value'];
				}
			}

			return $values;
		}

		private function component_fuzz_compare_value( $query, $column ) {
			$values = $this->component_fuzz_compare_values( $query, $column );
			return $values[0] ?? null;
		}

		private function component_fuzz_compare_values( $query, $column ) {
			$column = preg_quote( $column, '/' );
			if ( ! preg_match_all( '/(?<![A-Za-z0-9_])(?:`?[a-z_][a-z0-9_]*`?\.)?`?' . $column . '`?(?![A-Za-z0-9_])\s*=\s*(\'(?:\\\\.|[^\'\\\\])*\'|"[^"]*"|-?\d+)/i', (string) $query, $matches ) ) {
				return array();
			}

			return array_map( array( $this, 'component_fuzz_unquote_sql_value' ), $matches[1] );
		}

		private function component_fuzz_not_compare_value( $query, $column ) {
			$column = preg_quote( $column, '/' );
			if ( preg_match( '/(?<![A-Za-z0-9_])(?:`?[a-z_][a-z0-9_]*`?\.)?`?' . $column . '`?(?![A-Za-z0-9_])\s*!=\s*(\'(?:\\\\.|[^\'\\\\])*\'|"[^"]*"|-?\d+)/i', (string) $query, $matches ) ) {
				return $this->component_fuzz_unquote_sql_value( $matches[1] );
			}

			return null;
		}

		private function component_fuzz_less_than_value( $query, $column ) {
			$column = preg_quote( $column, '/' );
			if ( preg_match( '/(?<![A-Za-z0-9_])(?:`?[a-z_][a-z0-9_]*`?\.)?`?' . $column . '`?(?![A-Za-z0-9_])\s*<\s*(\'(?:\\\\.|[^\'\\\\])*\'|"[^"]*"|-?\d+)/i', (string) $query, $matches ) ) {
				return $this->component_fuzz_unquote_sql_value( $matches[1] );
			}

			return null;
		}

		private function component_fuzz_in_values( $query, $column ) {
			$column = preg_quote( $column, '/' );
			if ( ! preg_match( '/(?<![A-Za-z0-9_])(?:`?[a-z_][a-z0-9_]*`?\.)?`?' . $column . '`?(?![A-Za-z0-9_])\s+IN\s*\(([^)]*)\)/i', (string) $query, $matches ) ) {
				return array();
			}

			return $this->component_fuzz_csv_values( $matches[1] );
		}

		private function component_fuzz_all_compare_values( $query, $column, $operator ) {
			$column   = preg_quote( $column, '/' );
			$operator = preg_quote( $operator, '/' );

			if ( ! preg_match_all( '/(?<![A-Za-z0-9_])(?:`?[a-z_][a-z0-9_]*`?\.)?`?' . $column . '`?(?![A-Za-z0-9_])\s*' . $operator . '\s*(\'(?:\\\\.|[^\'\\\\])*\'|"[^"]*"|-?\d+)/i', (string) $query, $matches ) ) {
				return array();
			}

			return array_map( array( $this, 'component_fuzz_unquote_sql_value' ), $matches[1] );
		}

		private function component_fuzz_csv_values( $csv ) {
			if ( preg_match_all( '/\'((?:\\\\.|[^\'\\\\])*)\'|"([^"]*)"|(-?\d+)/', (string) $csv, $matches, PREG_SET_ORDER ) ) {
				return array_map(
					function ( $match ) {
						$token = (string) $match[0];
						if ( "'" === $token[0] ) {
							return stripslashes( substr( $token, 1, -1 ) );
						}
						if ( '"' === $token[0] ) {
							return substr( $token, 1, -1 );
						}

						return $match[3] ?? $token;
					},
					$matches
				);
			}

			return array();
		}

		private function component_fuzz_csv_int_values( $csv ) {
			return array_map( 'intval', $this->component_fuzz_csv_values( $csv ) );
		}

		private function component_fuzz_unquote_sql_value( $value ) {
			$value = trim( (string) $value );
			if ( strlen( $value ) >= 4 && "''" === substr( $value, 0, 2 ) && "''" === substr( $value, -2 ) ) {
				return stripslashes( substr( $value, 2, -2 ) );
			}
			if ( strlen( $value ) >= 2 && "'" === $value[0] && "'" === $value[ strlen( $value ) - 1 ] ) {
				return stripslashes( substr( $value, 1, -1 ) );
			}
			if ( strlen( $value ) >= 2 && '"' === $value[0] && '"' === $value[ strlen( $value ) - 1 ] ) {
				return substr( $value, 1, -1 );
			}

			return $value;
		}

		private function component_fuzz_quoted_values( $query ) {
			if ( ! preg_match_all( '/\'((?:\\\\.|[^\'\\\\])*)\'/s', (string) $query, $matches ) ) {
				return array();
			}

			return array_map( array( $this, 'component_fuzz_unescape_addslashes_sql_string' ), $matches[1] );
		}

		private function component_fuzz_unescape_addslashes_sql_string( $value ) {
			$value  = (string) $value;
			$out    = '';
			$length = strlen( $value );

			for ( $i = 0; $i < $length; $i++ ) {
				if ( '\\' !== $value[ $i ] || $i + 1 >= $length ) {
					$out .= $value[ $i ];
					continue;
				}

				$next = $value[ ++$i ];
				if ( '0' === $next ) {
					$out .= "\0";
				} elseif ( in_array( $next, array( '\\', "'", '"' ), true ) ) {
					$out .= $next;
				} else {
					$out .= '\\' . $next;
				}
			}

			return $out;
		}

		private function component_fuzz_delete_meta_ids( $meta_type, array $ids ) {
			if ( ! isset( $this->component_fuzz_meta[ $meta_type ] ) ) {
				return 0;
			}

			$id_map = array_fill_keys( array_map( 'intval', $ids ), true );
			foreach ( $this->component_fuzz_meta[ $meta_type ] as $id => $row ) {
				if ( isset( $id_map[ (int) $id ] ) ) {
					unset( $this->component_fuzz_meta[ $meta_type ][ $id ] );
					++$this->rows_affected;
				}
			}

			return $this->rows_affected;
		}

		private function component_fuzz_query_delete_term_relationships( $query ) {
			$object_id = $this->component_fuzz_compare_value( $query, 'object_id' );
			$tt_ids    = $this->component_fuzz_in_values( $query, 'term_taxonomy_id' );
			$tt_map    = array_fill_keys( array_map( 'intval', $tt_ids ), true );

			foreach ( $this->component_fuzz_term_relationship_rows as $id => $relationship ) {
				if ( null !== $object_id && (int) $relationship['object_id'] !== (int) $object_id ) {
					continue;
				}
				if ( array() !== $tt_ids && ! isset( $tt_map[ (int) $relationship['term_taxonomy_id'] ] ) ) {
					continue;
				}

				unset( $this->component_fuzz_term_relationship_rows[ $id ] );
				++$this->rows_affected;
			}

			return $this->rows_affected;
		}

		private function component_fuzz_query_insert_term_relationships( $query ) {
			if ( ! preg_match( '/\bVALUES\s+(.+?)(?:\s+ON\s+DUPLICATE|\z)/is', $query, $matches ) ) {
				return false;
			}

			if ( ! preg_match_all( '/\(\s*\'?(\d+)\'?\s*,\s*\'?(\d+)\'?\s*,\s*\'?(\d+)\'?\s*\)/', $matches[1], $rows, PREG_SET_ORDER ) ) {
				return false;
			}

			foreach ( $rows as $row ) {
				$key = (int) $row[1] . ':' . (int) $row[2];
				$this->component_fuzz_term_relationship_rows[ $key ] = array(
					'object_id'        => (int) $row[1],
					'term_taxonomy_id' => (int) $row[2],
					'term_order'       => (int) $row[3],
				);
				++$this->rows_affected;
			}

			return $this->rows_affected;
		}

		private function component_fuzz_query_update_comment_statuses( $query ) {
			$status = $this->component_fuzz_compare_value( $query, 'comment_approved' );
			$ids    = $this->component_fuzz_in_values( $query, 'comment_ID' );
			$id_map = array_fill_keys( array_map( 'intval', $ids ), true );

			foreach ( $this->component_fuzz_comments as &$comment ) {
				if ( array() !== $ids && ! isset( $id_map[ (int) $comment['comment_ID'] ] ) ) {
					continue;
				}

				$comment['comment_approved'] = $status;
				++$this->rows_affected;
			}
			unset( $comment );

			return $this->rows_affected;
		}

		private function component_fuzz_post_defaults() {
			return array(
				'ID'                    => 0,
				'post_author'           => 0,
				'post_date'             => '0000-00-00 00:00:00',
				'post_date_gmt'         => '0000-00-00 00:00:00',
				'post_content'          => '',
				'post_title'            => '',
				'post_excerpt'          => '',
				'post_status'           => 'draft',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => '',
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => '0000-00-00 00:00:00',
				'post_modified_gmt'     => '0000-00-00 00:00:00',
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => '',
				'menu_order'            => 0,
				'post_type'             => 'post',
				'post_mime_type'        => '',
				'comment_count'         => '0',
			);
		}

		private function component_fuzz_term_defaults() {
			return array(
				'term_id'    => 0,
				'name'       => '',
				'slug'       => '',
				'term_group' => 0,
			);
		}

		private function component_fuzz_term_taxonomy_defaults() {
			return array(
				'term_taxonomy_id' => 0,
				'term_id'          => 0,
				'taxonomy'         => '',
				'description'      => '',
				'parent'           => 0,
				'count'            => 0,
			);
		}

		private function component_fuzz_user_defaults() {
			return array(
				'ID'                  => 0,
				'user_login'          => '',
				'user_pass'           => '',
				'user_nicename'       => '',
				'user_email'          => '',
				'user_url'            => '',
				'user_registered'     => '0000-00-00 00:00:00',
				'user_activation_key' => '',
				'user_status'         => 0,
				'display_name'        => '',
				'spam'                => '0',
				'deleted'             => '0',
			);
		}

		private function component_fuzz_comment_defaults() {
			return array(
				'comment_ID'           => 0,
				'comment_post_ID'      => 0,
				'comment_author'       => '',
				'comment_author_email' => '',
				'comment_author_url'   => '',
				'comment_author_IP'    => '',
				'comment_date'         => '0000-00-00 00:00:00',
				'comment_date_gmt'     => '0000-00-00 00:00:00',
				'comment_content'      => '',
				'comment_karma'        => 0,
				'comment_approved'     => '1',
				'comment_agent'        => '',
				'comment_type'         => 'comment',
				'comment_parent'       => 0,
				'user_id'              => 0,
			);
		}

		private function component_fuzz_link_defaults() {
			return array(
				'link_id'          => 0,
				'link_url'         => '',
				'link_name'        => '',
				'link_image'       => '',
				'link_target'      => '',
				'link_description' => '',
				'link_visible'     => 'Y',
				'link_owner'       => 0,
				'link_rating'      => 0,
				'link_updated'     => '0000-00-00 00:00:00',
				'link_rel'         => '',
				'link_notes'       => '',
				'link_rss'         => '',
			);
		}

		private function component_fuzz_format_row( array $row, $output ) {
			if ( ARRAY_A === $output ) {
				return $row;
			}

			if ( ARRAY_N === $output ) {
				return array_values( $row );
			}

			return (object) $row;
		}

		private function component_fuzz_format_results( array $rows, $output ) {
			if ( ARRAY_A === $output || ARRAY_N === $output ) {
				return array_map(
					function ( $row ) use ( $output ) {
						return $this->component_fuzz_format_row( $row, $output );
					},
					$rows
				);
			}

			$objects = array_map(
				function ( $row ) {
					return (object) $row;
				},
				$rows
			);

			if ( OBJECT_K === $output ) {
				$keyed = array();
				foreach ( $objects as $object ) {
					$values = get_object_vars( $object );
					$key    = reset( $values );
					if ( ! isset( $keyed[ $key ] ) ) {
						$keyed[ $key ] = $object;
					}
				}
				return $keyed;
			}

			return $objects;
		}
	}
}

$GLOBALS['wpdb'] = $GLOBALS['wpdb'] ?? new Component_Fuzz_WPDB_Stub();
