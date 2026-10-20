<?php
/**
 * ESB_Zip_Writer → ESB_Zip_Reader без WordPress: вміст, CRC, mtime, сумісність із ZipArchive,
 * виявлення пошкодженого й обрізаного архіву.
 *
 * Запуск: php tests/unit/zip.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MB_IN_BYTES', 1048576 );

function __( $text ) {
    return $text;
}

require dirname( __DIR__ ) . '/lib.php';
require dirname( __DIR__, 2 ) . '/includes/class-esb-zip-writer.php';
require dirname( __DIR__, 2 ) . '/includes/class-esb-zip-reader.php';

$dir = sys_get_temp_dir() . '/esb-zip-test-' . getmypid();
@mkdir( $dir );
$zip   = "$dir/a.zip";
$mtime = time() - 3600;

$data = array(
    'files/empty.txt'           => array( '', true ),
    'files/text.txt'            => array( str_repeat( "Привіт, світ; line\n", 5000 ), true ),
    'files/bin.png'             => array( random_bytes( 300000 ), false ),
    'files/big/дані.bin'        => array( random_bytes( 5 * MB_IN_BYTES ), true ),
    'files/sub dir/empty-store' => array( '', false ),
);

// Запис у кілька «кроків», як у бекапі: щоразу новий writer зі збереженим станом.
$state = array( 'size' => 0, 'cd_size' => 0, 'entries' => 0 );
foreach ( $data as $name => $item ) {
    $writer = new ESB_Zip_Writer( $zip, "$dir/a.cd", $state );
    $src    = fopen( 'php://temp', 'w+b' );
    fwrite( $src, $item[0] );
    rewind( $src );
    $writer->add_stream( $src, $name, $item[1], strlen( $item[0] ), $mtime );
    fclose( $src );
    $state = $writer->state();
    $writer->close();
}
$writer = new ESB_Zip_Writer( $zip, "$dir/a.cd", $state );
$writer->add_string( 'manifest.json', '{"type":"full"}' );
$writer->finish();
$writer->close();

$reader = new ESB_Zip_Reader( $zip );
check( 6 === $reader->entries, 'entries = ' . $reader->entries );

$position = $reader->cd_offset;
$seen     = array();
while ( $entry = $reader->next_entry( $position ) ) {
    $seen[ $entry['name'] ] = $entry;
    $out                    = fopen( 'php://temp', 'w+b' );
    $reader->extract( $entry, $out );
    rewind( $out );
    $content = stream_get_contents( $out );
    fclose( $out );

    if ( isset( $data[ $entry['name'] ] ) ) {
        check( $content === $data[ $entry['name'] ][0], "content {$entry['name']} (method {$entry['method']})" );
        check( abs( $entry['mtime'] - $mtime ) <= 2, "mtime {$entry['name']}" );
    }
}
check( 6 === count( $seen ), 'iterated all entries' );
check( '{"type":"full"}' === $reader->read_string( $seen['manifest.json'], 1000 ), 'read_string manifest' );
$reader->close();

if ( class_exists( 'ZipArchive' ) ) {
    // Архів від ZipArchive читається нашим рідером.
    $other = "$dir/b.zip";
    $za    = new ZipArchive();
    $za->open( $other, ZipArchive::CREATE );
    $za->addFromString( 'files/x.txt', str_repeat( 'abc', 10000 ) );
    $za->addFromString( 'files/y.txt', 'yy' );
    $za->setCompressionName( 'files/y.txt', ZipArchive::CM_STORE );
    $za->close();

    $reader   = new ESB_Zip_Reader( $other );
    $position = $reader->cd_offset;
    $count    = 0;
    while ( $entry = $reader->next_entry( $position ) ) {
        $out = fopen( 'php://temp', 'w+b' );
        $reader->extract( $entry, $out );
        rewind( $out );
        check( stream_get_contents( $out ) === ( 'files/x.txt' === $entry['name'] ? str_repeat( 'abc', 10000 ) : 'yy' ), "ZipArchive entry {$entry['name']}" );
        $count++;
    }
    check( 2 === $count, 'ZipArchive entries' );
    $reader->close();

    // І навпаки: ZipArchive читає наш архів.
    $za = new ZipArchive();
    check( true === $za->open( $zip, ZipArchive::CHECKCONS ), 'ZipArchive CHECKCONS opens our archive' );
    check( $za->getFromName( 'files/text.txt' ) === $data['files/text.txt'][0], 'ZipArchive reads our text.txt' );
    $za->close();
} else {
    echo "SKIP ZipArchive checks: no zip extension\n";
}

// Пошкоджений байт у даних: CRC має не зійтися.
$bad = "$dir/bad.zip";
copy( $zip, $bad );
$entry = $seen['files/bin.png'];
$at    = $entry['offset'] + 30 + strlen( $entry['name'] ) + 1000;
$fh    = fopen( $bad, 'r+b' );
fseek( $fh, $at );
$byte = fread( $fh, 1 );
fseek( $fh, $at );
fwrite( $fh, chr( ord( $byte ) ^ 0xFF ) );
fclose( $fh );

$reader = new ESB_Zip_Reader( $bad );
try {
    $reader->extract( $entry, fopen( 'php://temp', 'w+b' ) );
    check( false, 'corrupt data detected' );
} catch ( RuntimeException $e ) {
    check( false !== strpos( $e->getMessage(), 'пошкоджено' ), 'corrupt data detected: ' . $e->getMessage() );
}
$reader->close();

// Обрізаний архів.
file_put_contents( "$dir/cut.zip", substr( file_get_contents( $zip ), 0, 1000 ) );
try {
    new ESB_Zip_Reader( "$dir/cut.zip" );
    check( false, 'truncated archive detected' );
} catch ( RuntimeException $e ) {
    check( true, 'truncated archive detected: ' . $e->getMessage() );
}

array_map( 'unlink', glob( "$dir/*" ) );
rmdir( $dir );

finish();
