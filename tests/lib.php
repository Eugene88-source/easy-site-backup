<?php
/**
 * Мінімальні засоби для тестів: check() рахує збої, finish() завершує з кодом виходу.
 */

$GLOBALS['esb_test_fails'] = 0;

function check( $condition, $message ) {
    echo ( $condition ? 'OK   ' : 'FAIL ' ) . $message . "\n";

    if ( ! $condition ) {
        $GLOBALS['esb_test_fails']++;
    }
}

function finish() {
    $fails = $GLOBALS['esb_test_fails'];
    echo $fails ? "\n{$fails} FAILED\n" : "\nALL PASSED\n";
    exit( $fails ? 1 : 0 );
}
