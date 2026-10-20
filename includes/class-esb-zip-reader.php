<?php
/**
 * Читання ZIP-архіву: центральний каталог і розпакування окремих записів.
 *
 * Записи центрального каталогу читаються по одному від заданої позиції, тож обхід архіву
 * можна продовжити в наступному кроці. Підтримуються ZIP64 і методи stored та deflate —
 * саме такі архіви створює ESB_Zip_Writer. ZipArchive не потрібен.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ESB_Zip_Reader {

    /**
     * Порція стиснених даних за одне читання; після розпакування вона може вирости в рази.
     */
    const READ_BYTES = 262144;

    /**
     * Кінцевий запис (22 байти) плюс найдовший можливий коментар архіву.
     */
    const EOCD_SEARCH = 65557;

    const LIMIT = 0xFFFFFFFF;

    public $cd_offset;

    public $cd_size;

    public $entries;

    private $fh;

    private $size;

    public function __construct( $path ) {
        $fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        if ( ! $fh ) {
            /* translators: %s: назва файлу */
            throw new RuntimeException( sprintf( __( 'Не вдалося відкрити файл %s.', 'easy-site-backup' ), basename( $path ) ) );
        }

        $this->fh   = $fh;
        $stat       = fstat( $fh );
        $this->size = (int) $stat['size'];

        try {
            $this->read_eocd();
        } catch ( RuntimeException $e ) {
            // Інакше файл лишився б відкритим до кінця запиту, і на Windows його не вдалося б видалити.
            $this->close();
            throw $e;
        }
    }

    public function close() {
        if ( $this->fh ) {
            fclose( $this->fh );
            $this->fh = null;
        }
    }

    /**
     * Читає запис центрального каталогу з позиції $position і пересуває її на наступний.
     *
     * @return array|null name, method, crc, csize, size, offset, mtime; null — записи закінчилися.
     */
    public function next_entry( &$position ) {
        if ( $position >= $this->cd_offset + $this->cd_size ) {
            return null;
        }

        $fixed = $this->read_at( $position, 46 );
        if ( "PK\x01\x02" !== substr( $fixed, 0, 4 ) ) {
            throw self::corrupt();
        }

        $h   = unpack( 'vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vname_len/vextra_len/vcomment_len/vdisk/vint_attr/Vext_attr/Voffset', substr( $fixed, 8 ) );
        $var = $this->read_at( $position + 46, $h['name_len'] + $h['extra_len'] );

        $entry = array(
            'name'   => substr( $var, 0, $h['name_len'] ),
            'method' => $h['method'],
            'crc'    => $h['crc'],
            'csize'  => $h['csize'],
            'size'   => $h['size'],
            'offset' => $h['offset'],
            'mtime'  => self::unix_time( $h['time'], $h['date'] ),
        );

        if ( $h['flags'] & 1 ) {
            /* translators: %s: шлях у архіві */
            throw new RuntimeException( sprintf( __( 'Файл %s в архіві зашифровано.', 'easy-site-backup' ), $entry['name'] ) );
        }

        if ( self::LIMIT === $entry['size'] || self::LIMIT === $entry['csize'] || self::LIMIT === $entry['offset'] ) {
            self::read_zip64( $entry, substr( $var, $h['name_len'] ) );
        }

        $position += 46 + $h['name_len'] + $h['extra_len'] + $h['comment_len'];

        return $entry;
    }

    /**
     * Розпаковує запис у відкритий файл і перевіряє розмір та CRC.
     */
    public function extract( array $entry, $out ) {
        if ( 0 !== $entry['method'] && 8 !== $entry['method'] ) {
            /* translators: %s: шлях у архіві */
            throw new RuntimeException( sprintf( __( 'Непідтримуваний метод стиснення у файлі %s.', 'easy-site-backup' ), $entry['name'] ) );
        }

        if ( 0 !== fseek( $this->fh, $this->data_offset( $entry ) ) ) {
            throw self::corrupt();
        }

        $inflate   = 8 === $entry['method'] ? inflate_init( ZLIB_ENCODING_RAW ) : null;
        $crc       = hash_init( 'crc32b' );
        $written   = 0;
        $remaining = $entry['csize'];

        while ( $remaining > 0 ) {
            $chunk = fread( $this->fh, min( self::READ_BYTES, $remaining ) );
            if ( false === $chunk || '' === $chunk ) {
                throw self::corrupt();
            }

            $remaining -= strlen( $chunk );

            if ( $inflate ) {
                $chunk = @inflate_add( $inflate, $chunk ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                if ( false === $chunk ) {
                    throw self::damaged( $entry );
                }
            }

            self::write( $out, $chunk, $crc, $written );
        }

        if ( $inflate ) {
            $chunk = @inflate_add( $inflate, '', ZLIB_FINISH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if ( false === $chunk ) {
                throw self::damaged( $entry );
            }
            self::write( $out, $chunk, $crc, $written );
        }

        // sprintf, а не порівняння чисел: на 32-бітному PHP unpack('V') дає від'ємні значення.
        if ( $written !== $entry['size'] || sprintf( '%08x', $entry['crc'] ) !== hash_final( $crc ) ) {
            throw self::damaged( $entry );
        }
    }

    /**
     * Розпаковує частину запису без стиснення (stored) — для великих файлів, які не вміщаються
     * в один крок. Пише в $out з позиції $done до дедлайну, але щонайменше одну порцію.
     * $done і $crc (CRC32 уже записаного) оновлюються; коли записано все, CRC і розмір звіряються.
     *
     * @return bool true — запис розпаковано повністю.
     */
    public function extract_stored_part( array $entry, $out, &$done, &$crc, $deadline ) {
        if ( 0 !== $entry['method'] ) {
            throw self::damaged( $entry );
        }

        if ( 0 !== fseek( $this->fh, $this->data_offset( $entry ) + $done ) ) {
            throw self::corrupt();
        }

        do {
            $want = min( self::READ_BYTES * 4, $entry['size'] - $done );
            if ( $want <= 0 ) {
                break;
            }

            $chunk = fread( $this->fh, $want );
            if ( false === $chunk || '' === $chunk ) {
                throw self::corrupt();
            }

            if ( strlen( $chunk ) !== fwrite( $out, $chunk ) ) {
                throw new RuntimeException( __( 'Не вдалося записати розпакований файл. Можливо, на диску закінчилося місце.', 'easy-site-backup' ) );
            }

            $crc   = ESB_Crc32::combine( $crc, ESB_Crc32::of( $chunk ), strlen( $chunk ) );
            $done += strlen( $chunk );
        } while ( microtime( true ) < $deadline );

        if ( $done < $entry['size'] ) {
            return false;
        }

        if ( sprintf( '%08x', $entry['crc'] ) !== sprintf( '%08x', $crc ) ) {
            throw self::damaged( $entry );
        }

        return true;
    }

    /**
     * Позиція початку даних запису — після його локального заголовка.
     */
    private function data_offset( array $entry ) {
        $local = $this->read_at( $entry['offset'], 30 );
        if ( "PK\x03\x04" !== substr( $local, 0, 4 ) ) {
            throw self::corrupt();
        }

        $lens = unpack( 'vname/vextra', substr( $local, 26, 4 ) );

        return $entry['offset'] + 30 + $lens['name'] + $lens['extra'];
    }

    /**
     * @return string Вміст невеликого запису.
     */
    public function read_string( array $entry, $max_bytes ) {
        if ( $entry['size'] > $max_bytes ) {
            throw self::damaged( $entry );
        }

        $buffer = fopen( 'php://temp', 'w+b' );

        try {
            $this->extract( $entry, $buffer );
            rewind( $buffer );

            return (string) stream_get_contents( $buffer );
        } finally {
            fclose( $buffer );
        }
    }

    private function read_eocd() {
        $tail_size = min( $this->size, self::EOCD_SEARCH );
        $tail      = $this->read_at( $this->size - $tail_size, $tail_size );
        $pos       = strrpos( $tail, "PK\x05\x06" );

        if ( false === $pos || strlen( $tail ) - $pos < 22 ) {
            throw self::corrupt();
        }

        $eocd = unpack( 'ventries/Vcd_size/Vcd_offset', substr( $tail, $pos + 10, 10 ) );

        $this->entries   = $eocd['entries'];
        $this->cd_size   = $eocd['cd_size'];
        $this->cd_offset = $eocd['cd_offset'];

        if ( 0xFFFF === $this->entries || self::LIMIT === $this->cd_size || self::LIMIT === $this->cd_offset ) {
            // ZIP64: локатор лежить одразу перед кінцевим записом і вказує на розширений кінцевий запис.
            $locator = $pos - 20;
            if ( $locator < 0 || "PK\x06\x07" !== substr( $tail, $locator, 4 ) ) {
                throw self::corrupt();
            }

            $record = $this->read_at( self::uint64( substr( $tail, $locator + 8, 8 ) ), 56 );
            if ( "PK\x06\x06" !== substr( $record, 0, 4 ) ) {
                throw self::corrupt();
            }

            $this->entries   = self::uint64( substr( $record, 32, 8 ) );
            $this->cd_size   = self::uint64( substr( $record, 40, 8 ) );
            $this->cd_offset = self::uint64( substr( $record, 48, 8 ) );
        }

        if ( $this->cd_offset + $this->cd_size > $this->size ) {
            throw self::corrupt();
        }
    }

    /**
     * Справжні значення полів, що не вмістилися в 32 біти, лежать у додатковому полі 0x0001 саме в такому порядку.
     */
    private static function read_zip64( array &$entry, $extra ) {
        while ( strlen( $extra ) >= 4 ) {
            $block = unpack( 'vid/vlen', $extra );
            $data  = substr( $extra, 4, $block['len'] );
            $extra = (string) substr( $extra, 4 + $block['len'] );

            if ( 0x0001 !== $block['id'] ) {
                continue;
            }

            foreach ( array( 'size', 'csize', 'offset' ) as $field ) {
                if ( self::LIMIT === $entry[ $field ] ) {
                    if ( strlen( $data ) < 8 ) {
                        throw self::corrupt();
                    }
                    $entry[ $field ] = self::uint64( $data );
                    $data            = (string) substr( $data, 8 );
                }
            }

            return;
        }

        throw self::corrupt();
    }

    private function read_at( $offset, $length ) {
        if ( $length <= 0 ) {
            return '';
        }

        if ( 0 !== fseek( $this->fh, $offset ) ) {
            throw self::corrupt();
        }

        $data = '';
        while ( strlen( $data ) < $length ) {
            $chunk = fread( $this->fh, $length - strlen( $data ) );
            if ( false === $chunk || '' === $chunk ) {
                break;
            }
            $data .= $chunk;
        }

        if ( strlen( $data ) !== $length ) {
            throw self::corrupt();
        }

        return $data;
    }

    private static function write( $out, $data, $crc, &$written ) {
        if ( '' === $data ) {
            return;
        }

        hash_update( $crc, $data );

        if ( strlen( $data ) !== fwrite( $out, $data ) ) {
            throw new RuntimeException( __( 'Не вдалося записати розпакований файл. Можливо, на диску закінчилося місце.', 'easy-site-backup' ) );
        }

        $written += strlen( $data );
    }

    private static function uint64( $bytes ) {
        if ( PHP_INT_SIZE >= 8 ) {
            $value = unpack( 'P', $bytes );
            return $value[1];
        }

        $parts = unpack( 'Vlow/Vhigh', $bytes );
        if ( 0 !== $parts['high'] || $parts['low'] < 0 ) {
            throw new RuntimeException( __( 'На 32-бітному PHP архів не може бути більшим за 2 ГБ.', 'easy-site-backup' ) );
        }

        return $parts['low'];
    }

    /**
     * Час у форматі MS-DOS → Unix; часовий пояс той самий, що й при записі (ESB_Zip_Writer::dos_time).
     */
    private static function unix_time( $time, $date ) {
        return mktime(
            ( $time >> 11 ) & 0x1F,
            ( $time >> 5 ) & 0x3F,
            ( $time & 0x1F ) * 2,
            ( $date >> 5 ) & 0x0F,
            $date & 0x1F,
            ( ( $date >> 9 ) & 0x7F ) + 1980
        );
    }

    private static function corrupt() {
        return new RuntimeException( __( 'Архів резервної копії пошкоджено.', 'easy-site-backup' ) );
    }

    private static function damaged( array $entry ) {
        /* translators: %s: шлях у архіві */
        return new RuntimeException( sprintf( __( 'Файл %s в архіві пошкоджено.', 'easy-site-backup' ), $entry['name'] ) );
    }
}
