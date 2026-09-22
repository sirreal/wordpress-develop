<?php
namespace ComponentFuzz\Surfaces;

/**
 * Deterministic image editor double for image-edit request fuzzing.
 */
final class MediaImageEditRequestsFakeEditor extends \WP_Image_Editor {
	/** @var array<int,self> */
	public static array $instances = array();

	/** @var array<string,array{width:int,height:int}> */
	public static array $sizes_by_file = array();

	/** @var array<string,string> */
	public static array $mime_by_file = array();

	/** @var array<int,array<string,mixed>> */
	public array $operations = array();

	public static function reset(): void {
		self::$instances     = array();
		self::$sizes_by_file = array();
		self::$mime_by_file  = array();
	}

	public static function test( $args = array() ) {
		unset( $args );
		return true;
	}

	public static function supports_mime_type( $mime_type ) {
		return str_starts_with( (string) $mime_type, 'image/' );
	}

	public function __construct( $file ) {
		parent::__construct( $file );
		self::$instances[] = $this;
	}

	public function load() {
		$size            = self::$sizes_by_file[ (string) $this->file ] ?? array(
			'width'  => 1200,
			'height' => 800,
		);
		$this->mime_type = self::$mime_by_file[ (string) $this->file ] ?? 'image/jpeg';
		$this->update_size( $size['width'], $size['height'] );
		$this->operations[] = array(
			'method' => 'load',
			'file'   => $this->file,
		);

		return true;
	}

	public function save( $destfilename = null, $mime_type = null ) {
		$mime_type = $mime_type ?: $this->mime_type ?: 'image/jpeg';
		$dest      = $destfilename ?: $this->generate_filename( null, null, self::extension_for_mime( $mime_type ) );

		if ( ! is_dir( dirname( $dest ) ) ) {
			wp_mkdir_p( dirname( $dest ) );
		}

		$bytes = 'component-fuzz-image:' . $mime_type . ':' . $this->size['width'] . 'x' . $this->size['height'];
		file_put_contents( $dest, $bytes );

		$this->operations[] = array(
			'method' => 'save',
			'path'   => $dest,
			'mime'   => $mime_type,
		);

		return array(
			'path'      => $dest,
			'file'      => wp_basename( $dest ),
			'width'     => $this->size['width'],
			'height'    => $this->size['height'],
			'mime-type' => $mime_type,
			'filesize'  => filesize( $dest ),
		);
	}

	public function resize( $max_w, $max_h, $crop = false ) {
		$this->operations[] = array(
			'method' => 'resize',
			'width'  => $max_w,
			'height' => $max_h,
			'crop'   => $crop,
		);

		$this->update_size(
			null === $max_w ? $this->size['width'] : max( 1, (int) $max_w ),
			null === $max_h ? $this->size['height'] : max( 1, (int) $max_h )
		);

		return true;
	}

	public function multi_resize( $sizes ) {
		$created = array();
		foreach ( $sizes as $name => $size ) {
			$width  = max( 1, (int) ( $size['width'] ?? $this->size['width'] ) );
			$height = max( 1, (int) ( $size['height'] ?? $this->size['height'] ) );
			$file   = pathinfo( (string) $this->file, PATHINFO_FILENAME ) . '-' . $width . 'x' . $height . '.jpg';
			$path   = path_join( dirname( (string) $this->file ), $file );
			file_put_contents( $path, 'component-fuzz-subsize:' . $name );

			$created[ (string) $name ] = array(
				'file'      => $file,
				'width'     => $width,
				'height'    => $height,
				'mime-type' => 'image/jpeg',
				'filesize'  => filesize( $path ),
			);
		}

		$this->operations[] = array(
			'method' => 'multi_resize',
			'sizes'  => array_keys( $sizes ),
		);

		return $created;
	}

	public function crop( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false ) {
		$this->operations[] = array(
			'method' => 'crop',
			'x'      => (int) $src_x,
			'y'      => (int) $src_y,
			'w'      => (int) $src_w,
			'h'      => (int) $src_h,
			'dst_w'  => null === $dst_w ? null : (int) $dst_w,
			'dst_h'  => null === $dst_h ? null : (int) $dst_h,
			'abs'    => (bool) $src_abs,
		);

		$this->update_size(
			null === $dst_w ? (int) $src_w : (int) $dst_w,
			null === $dst_h ? (int) $src_h : (int) $dst_h
		);

		return true;
	}

	public function rotate( $angle ) {
		$this->operations[] = array(
			'method' => 'rotate',
			'angle'  => $angle,
		);

		$normalized = abs( (int) $angle ) % 180;
		if ( 90 === $normalized ) {
			$this->update_size( $this->size['height'], $this->size['width'] );
		}

		return true;
	}

	public function flip( $horz, $vert ) {
		$this->operations[] = array(
			'method' => 'flip',
			'horz'   => (bool) $horz,
			'vert'   => (bool) $vert,
		);

		return true;
	}

	public function stream( $mime_type = null ) {
		$mime_type          = $mime_type ?: $this->mime_type ?: 'image/jpeg';
		$this->operations[] = array(
			'method' => 'stream',
			'mime'   => $mime_type,
		);

		echo 'component-fuzz-stream:' . $mime_type;
		return true;
	}

	private static function extension_for_mime( string $mime_type ): string {
		if ( 'image/png' === $mime_type ) {
			return 'png';
		}
		if ( 'image/gif' === $mime_type ) {
			return 'gif';
		}
		if ( 'image/webp' === $mime_type ) {
			return 'webp';
		}
		return 'jpg';
	}
}
