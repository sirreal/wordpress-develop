<?php require_once __DIR__ . '/bootstrap.php'; ?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title>WordPress QUnit</title>
	<link rel="stylesheet" href="/qunit.css" type="text/css">
	<script src="/qunit.js"></script>
</head>
<body>
	<div id="qunit">
		<h1 id="qunit-header">…</h1>
		<h2 id="qunit-banner"></h2>
		<div id="qunit-testrunner-toolbar">…</div>
		<h2 id="qunit-userAgent">…</h2>
		<p id="qunit-testresult">…</p>
		<ol id="qunit-tests"></ol>
	</div>

	<div id="WP_CSS_Builder">
		<ul style="display:none"><li id="css-processing-tests@string" style="list-style-type: <?php echo esc_attr( WP_CSS_Builder::string( 'CSS & a "<style>" tag\'s strings' ) ); ?>;"></li></ul>
		<script type="module">
			QUnit.module( 'WP_CSS_Builder' );
			QUnit.test( '::string()', function ( assert ) {
				const el = document.getElementById( 'css-processing-tests@string' )
				assert.equal(
					el.style.listStyleType,
					String.raw`"CSS & a \"<style>\" tag's strings"`,
					'decodes correctly'
				);
			} );
			//# sourceURL=WP_CSS_Builder
		</script>
	</div>
</body>
</html>
