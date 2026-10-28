<?php
/** Pure classification helper, intentionally free of WordPress dependencies. */
function uic_classify_reference_status( $has_reference, $file_exists, $metadata_valid, $scan_complete = true ) {
	if ( $has_reference ) {
		return 'used';
	}
	if ( ! $file_exists || ! $metadata_valid || ! $scan_complete ) {
		return 'uncertain';
	}
	// This is only a potential match, never a claim of definite non-use.
	return 'potentially_unused';
}
