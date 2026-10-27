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
exit( $failures ? 1 : 0 );
