<?php
/**
 * Один крок бекапу чи відновлення в окремому процесі — щоб interrupt.php міг його «вбити»,
 * як це робить хостинг за лімітом часу чи пам'яті.
 *
 * php step-child.php /path/to/wordpress backup <id>
 * php step-child.php /path/to/wordpress restore <token>
 */

require $argv[1] . '/wp-load.php';

// Ті самі зменшені межі, що й у interrupt.php: більше кроків і частин — більше місць для обриву.
add_filter(
    'esb_step_seconds',
    function () {
        // Нормальні кроки — короткі (більше кроків і переходів між фазами), «вбиті» — на секунду.
        return getenv( 'ESB_STEP' ) ? (float) getenv( 'ESB_STEP' ) : 1.0;
    }
);
add_filter(
    'esb_dump_part_bytes',
    function () {
        return 256 * KB_IN_BYTES;
    }
);
add_filter(
    'esb_large_file_bytes',
    function () {
        return MB_IN_BYTES;
    }
);

// Обрив у найризикованішу мить: таблиці вже замінено, а стан ще не збережено.
if ( getenv( 'ESB_EXIT_AFTER_SWAP' ) ) {
    add_action(
        'esb_restore_tables_swapped',
        function () {
            exit( 0 );
        }
    );
}

if ( 'backup' === $argv[2] ) {
    $job = ESB_Backup::step( $argv[3] );
    echo is_wp_error( $job ) ? 'error' : $job->data['status'];
} else {
    $state = ESB_Restore::step( $argv[3] );
    echo is_wp_error( $state ) ? 'error' : $state['status'];
}
