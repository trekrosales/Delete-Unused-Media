<?php
require_once __DIR__ . '/../unused-image-cleaner/includes/classification.php';

$tests = array(
	array( true, true, true, 'used', 'reference wins even when file/metadata are incomplete' ),
	array( false, true, true, 'potentially_unused', 'complete scan absence is only potentially unused' ),
	array( false, false, true, 'uncertain', 'missing file is uncertain' ),
	array( false, true, false, 'uncertain', 'missing metadata is uncertain' ),
	array( true, false, false, 'used', 'reference is always classified as used' ),
);
$failures = 0;
foreach ( $tests as $test ) {
	$actual = uic_classify_reference_status( $test[0], $test[1], $test[2] );
	if ( $actual !== $test[3] ) {
		fwrite( STDERR, 'FAIL: ' . $test[4] . ' (expected ' . $test[3] . ', got ' . $actual . ")\n" );
		$failures++;
	} else {
		echo 'PASS: ' . $test[4] . "\n";
	}
}
if ( 'potentially_unused' === uic_classify_reference_status( false, true, true ) ) {
	echo "PASS: absent references never produce a definite-unused state\n";
} else {
	fwrite( STDERR, "FAIL: absence must not claim definite non-use\n" );
	$failures++;
}

$plugin_source = file_get_contents( __DIR__ . '/../unused-image-cleaner/unused-image-cleaner.php' );
$safety_checks = array(
	array( false !== strpos( $plugin_source, "current_user_can( 'manage_options' )" ), 'admin capability is required' ),
	array( false !== strpos( $plugin_source, "check_admin_referer( 'uic_confirm_delete', 'uic_nonce' )" ), 'deletion request verifies a nonce' ),
	array( false !== strpos( $plugin_source, 'empty( $_POST[\'uic_final_confirm\'] )' ), 'deletion requires a separate final confirmation' ),
	array( false !== strpos( $plugin_source, '\'potentially_unused\' !== $check[\'status\']' ), 'fresh scan excludes used and uncertain images' ),
	array( false !== strpos( $plugin_source, 'wp_trash_post( $id )' ), 'selected image is moved to WordPress Trash' ),
	array( false === strpos( $plugin_source, 'wp_delete_attachment(' ) && false === strpos( $plugin_source, 'unlink(' ), 'plugin does not call permanent file deletion' ),
);
foreach ( $safety_checks as $check ) {
	if ( $check[0] ) {
		echo 'PASS: ' . $check[1] . "\n";
	} else {
		fwrite( STDERR, 'FAIL: ' . $check[1] . "\n" );
		$failures++;
	}
}
exit( $failures ? 1 : 0 );
