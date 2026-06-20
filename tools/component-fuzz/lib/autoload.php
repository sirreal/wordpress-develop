<?php
require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/Prng.php';
require_once __DIR__ . '/FuzzContext.php';
require_once __DIR__ . '/WpBootstrap.php';
require_once __DIR__ . '/SurfaceRunner.php';

foreach ( glob( __DIR__ . '/../surfaces/*Surface.php' ) as $surface_file ) {
	require_once $surface_file;
}

