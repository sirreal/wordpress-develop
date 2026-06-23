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
		public $is_mysql = false;
		public $posts = 'wp_posts';
		public $comments = 'wp_comments';
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
		private $component_fuzz_meta = array();
		private $component_fuzz_next_ids = array();

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

		public function component_fuzz_reset_content() {
			$this->component_fuzz_posts                 = array();
			$this->component_fuzz_terms                 = array();
			$this->component_fuzz_term_taxonomy_rows    = array();
			$this->component_fuzz_term_relationship_rows = array();
			$this->component_fuzz_users                 = array();
			$this->component_fuzz_comments              = array();
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
				'post_meta'     => 1,
				'term_meta'     => 1,
				'comment_meta'  => 1,
				'user_meta'     => 1,
			);
			$this->insert_id                            = 0;
			$this->rows_affected                        = 0;
			$this->num_rows                             = 0;
			$this->last_error                           = '';
		}

		public function component_fuzz_content_counts() {
			return array(
				'posts'              => count( $this->component_fuzz_posts ),
				'terms'              => count( $this->component_fuzz_terms ),
				'term_taxonomy'      => count( $this->component_fuzz_term_taxonomy_rows ),
				'term_relationships' => count( $this->component_fuzz_term_relationship_rows ),
				'users'              => count( $this->component_fuzz_users ),
				'comments'           => count( $this->component_fuzz_comments ),
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

			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sdFfi]/', "'" . $this->_escape( $arg ) . "'", $query, 1 );
			}
			return $query;
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

			$this->last_query = (string) $query;

			if ( preg_match( '/\bSELECT\s+COUNT\(\*\)/i', $this->last_query ) ) {
				return $this->component_fuzz_count_for_query( $this->last_query );
			}

			if ( preg_match( '/\bSELECT\s+MAX\(term_group\)\s+FROM\s+`?wp_terms`?/i', $this->last_query ) ) {
				$groups = array_column( $this->component_fuzz_terms, 'term_group' );
				return $groups ? max( array_map( 'intval', $groups ) ) : 0;
			}

			$row = $this->get_row( $query, ARRAY_A );
			if ( ! is_array( $row ) || array() === $row ) {
				return null;
			}

			return reset( $row );
		}

		public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
			unset( $y );

			$this->last_query = (string) $query;
			$rows             = $this->component_fuzz_select_rows( $this->last_query );
			$this->num_rows   = count( $rows );

			if ( array() === $rows ) {
				return null;
			}

			return $this->component_fuzz_format_row( reset( $rows ), $output );
		}

		public function get_results( $query = null, $output = OBJECT ) {
			$this->last_query = (string) $query;
			$rows             = $this->component_fuzz_select_rows( $this->last_query );
			$this->num_rows   = count( $rows );

			return $this->component_fuzz_format_results( $rows, $output );
		}

		public function get_col( $query = null, $x = 0 ) {
			$this->last_query = (string) $query;
			$rows             = $this->component_fuzz_select_rows( $this->last_query );
			$this->num_rows   = count( $rows );
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
			$this->last_query    = (string) $query;
			$this->rows_affected = 0;

			if ( preg_match( '/\bINSERT\s+INTO\s+`?wp_options`?\b/i', $this->last_query ) ) {
				return $this->component_fuzz_query_insert_option( $this->last_query );
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
			if ( preg_match( '/\bFROM\s+`?wp_options`?\b/i', $query ) ) {
				return $this->component_fuzz_select_options( $query );
			}

			if ( preg_match( '/\bFROM\s+`?wp_posts`?\b/i', $query ) ) {
				return $this->component_fuzz_select_posts( $query );
			}

			if ( preg_match( '/\bFROM\s+`?wp_users`?\b/i', $query ) ) {
				return $this->component_fuzz_select_users( $query );
			}

			if ( preg_match( '/\bFROM\s+`?wp_comments`?\b/i', $query ) ) {
				return $this->component_fuzz_select_comments( $query );
			}

			if ( preg_match( '/\bFROM\s+`?wp_terms`?\b/i', $query ) || preg_match( '/\bFROM\s+`?wp_term_taxonomy`?\b/i', $query ) ) {
				return $this->component_fuzz_select_terms( $query );
			}

			$table_key = $this->component_fuzz_table_key_from_query( $query );
			if ( null !== $this->component_fuzz_meta_type_for_table_key( $table_key ) ) {
				return $this->component_fuzz_select_meta( $query, $this->component_fuzz_meta_type_for_table_key( $table_key ) );
			}

			return array();
		}

		private function component_fuzz_select_options( $query ) {
			$rows = array();

			if ( preg_match( '/\boption_name\s+IN\s*\(/i', $query ) ) {
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

			foreach ( array( 'post_name', 'post_type', 'post_parent', 'post_status' ) as $column ) {
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

			usort(
				$rows,
				static function ( $a, $b ) use ( $query ) {
					if ( preg_match( '/ORDER\s+BY\s+comment_ID\s+DESC/i', $query ) ) {
						return (int) $b['comment_ID'] <=> (int) $a['comment_ID'];
					}
					return (int) $a['ID'] <=> (int) $b['ID'];
				}
			);

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

		private function component_fuzz_select_users( $query ) {
			$rows = array_values( $this->component_fuzz_users );

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

			$rows = $this->component_fuzz_apply_limit( $query, $rows );

			if ( preg_match( '/SELECT\s+ID\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'ID' ) );
			}

			return $rows;
		}

		private function component_fuzz_select_comments( $query ) {
			$rows = array_values( $this->component_fuzz_comments );

			foreach ( array( 'comment_ID', 'comment_post_ID', 'comment_parent', 'comment_author', 'comment_author_email', 'comment_content', 'comment_approved', 'user_id' ) as $column ) {
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

			if ( preg_match( '/SELECT\s+comment_ID\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'comment_ID' ) );
			}

			if ( preg_match( '/SELECT\s+comment_date_gmt\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'comment_date_gmt' ) );
			}

			return $rows;
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
						$tt_map[ (int) $relationship['term_taxonomy_id'] ] = (int) $relationship['object_id'];
					}
				}
				$rows = array_filter(
					$rows,
					static function ( $row ) use ( $tt_map ) {
						return isset( $tt_map[ (int) $row['term_taxonomy_id'] ] );
					}
				);
				foreach ( $rows as &$row ) {
					$row['object_id'] = $tt_map[ (int) $row['term_taxonomy_id'] ];
				}
				unset( $row );
			}

			$rows = $this->component_fuzz_sort_term_rows( $query, array_values( $rows ) );
			$rows = $this->component_fuzz_apply_limit( $query, $rows );

			if ( preg_match( '/SELECT\s+tt\.term_id\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'term_id' ) );
			}

			if ( preg_match( '/SELECT\s+t\.term_id\s*,\s*t\.slug\s*,\s*tt\.term_taxonomy_id\s*,\s*tt\.taxonomy/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( 'term_id', 'slug', 'term_taxonomy_id', 'taxonomy' ) );
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

			if ( preg_match( '/SELECT\s+' . preg_quote( $id_column, '/' ) . '\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( $id_column ) );
			}

			if ( preg_match( '/SELECT\s+' . preg_quote( $object_key, '/' ) . '\s*,\s*meta_key\s*,\s*meta_value\b/i', $query ) ) {
				return $this->component_fuzz_project_rows( $rows, array( $object_key, 'meta_key', 'meta_value' ) );
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

			if ( preg_match( '/\bFROM\s+`?wp_terms`?\b/i', $query ) || preg_match( '/\bFROM\s+`?wp_term_taxonomy`?\b/i', $query ) ) {
				return count( $this->component_fuzz_select_terms( $query ) );
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

		private function component_fuzz_apply_limit( $query, array $rows ) {
			if ( preg_match( '/\bLIMIT\s+(\d+)\s*,\s*(\d+)/i', $query, $matches ) ) {
				return array_slice( array_values( $rows ), (int) $matches[1], (int) $matches[2] );
			}

			if ( preg_match( '/\bLIMIT\s+(\d+)/i', $query, $matches ) ) {
				return array_slice( array_values( $rows ), 0, (int) $matches[1] );
			}

			return array_values( $rows );
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

		private function component_fuzz_compare_value( $query, $column ) {
			$column = preg_quote( $column, '/' );
			if ( preg_match( '/(?:`?[a-z_]+`?\.)?`?' . $column . '`?\s*=\s*(\'(?:\\\\.|[^\'\\\\])*\'|"[^"]*"|-?\d+)/i', (string) $query, $matches ) ) {
				return $this->component_fuzz_unquote_sql_value( $matches[1] );
			}

			return null;
		}

		private function component_fuzz_not_compare_value( $query, $column ) {
			$column = preg_quote( $column, '/' );
			if ( preg_match( '/(?:`?[a-z_]+`?\.)?`?' . $column . '`?\s*!=\s*(\'(?:\\\\.|[^\'\\\\])*\'|"[^"]*"|-?\d+)/i', (string) $query, $matches ) ) {
				return $this->component_fuzz_unquote_sql_value( $matches[1] );
			}

			return null;
		}

		private function component_fuzz_less_than_value( $query, $column ) {
			$column = preg_quote( $column, '/' );
			if ( preg_match( '/(?:`?[a-z_]+`?\.)?`?' . $column . '`?\s*<\s*(\'(?:\\\\.|[^\'\\\\])*\'|"[^"]*"|-?\d+)/i', (string) $query, $matches ) ) {
				return $this->component_fuzz_unquote_sql_value( $matches[1] );
			}

			return null;
		}

		private function component_fuzz_in_values( $query, $column ) {
			$column = preg_quote( $column, '/' );
			if ( ! preg_match( '/(?:`?[a-z_]+`?\.)?`?' . $column . '`?\s+IN\s*\(([^)]*)\)/i', (string) $query, $matches ) ) {
				return array();
			}

			return $this->component_fuzz_csv_values( $matches[1] );
		}

		private function component_fuzz_csv_values( $csv ) {
			if ( preg_match_all( '/\'((?:\\\\.|[^\'\\\\])*)\'|"([^"]*)"|(-?\d+)/', (string) $csv, $matches, PREG_SET_ORDER ) ) {
				return array_map(
					function ( $match ) {
						if ( isset( $match[1] ) && '' !== $match[1] ) {
							return stripslashes( $match[1] );
						}
						if ( isset( $match[2] ) && '' !== $match[2] ) {
							return $match[2];
						}
						return $match[3];
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

			return array_map( 'stripslashes', $matches[1] );
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
