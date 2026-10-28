<?php
/**
 * Запис ZIP-архіву, який можна дописувати між кроками.
 *
 * ZipArchive при кожному відкритті переписує весь архів, тому для покрокового бекапу
 * він не годиться. Тут записи лише дописуються в кінець файлу, а центральний каталог
 * накопичується в окремому файлі й переноситься в архів у finish().
 *
 * Стан (розміри обох файлів і кількість записів) зберігається після кожного кроку;
 * при наступному відкритті файли обрізаються до цих розмірів, тож дані перерваного
 * кроку відкидаються.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ESB_Zip_Writer {

    /**
     * Межа 32-бітних полів ZIP; більші значення пишуться в ZIP64-поля.
     */
    const LIMIT = 0xFFFFFFFF;

    /**
     * З якого розміру файлу одразу резервувати ZIP64-поля в локальному заголовку
     * (із запасом на можливе збільшення даних при стисненні).
     */
    const ZIP64_THRESHOLD = 0xF0000000;

    /**
     * На 32-бітному PHP fseek() і ftell() не працюють за межею 2 ГБ.
     */
    const LIMIT_32BIT = 0x7F000000;

    const READ_BYTES = 1048576;

    private $fh;

    private $cd;

    private $offset;

    private $cd_size;

    private $entries;

    /**
     * @param string $path    Файл архіву.
     * @param string $cd_path Тимчасовий файл центрального каталогу.
     * @param array  $state   Стан після останнього збереженого кроку: size, cd_size, entries.
     */
    public function __construct( $path, $cd_path, array $state ) {
        $this->offset  = (int) $state['size'];
        $this->cd_size = (int) $state['cd_size'];
        $this->entries = (int) $state['entries'];

        $this->fh = self::open_at( $path, $this->offset );

        try {
            $this->cd = self::open_at( $cd_path, $this->cd_size );
        } catch ( RuntimeException $e ) {
            // Інакше архів лишився б відкритим до кінця запиту, і на Windows його не вдалося б видалити.
            $this->close();
            throw $e;
        }
    }

    public function state() {
        return array(
            'size'    => $this->offset,
            'cd_size' => $this->cd_size,
            'entries' => $this->entries,
        );
    }

    /**
     * @return bool false, якщо файл не вдалося прочитати — тоді його пропущено.
     */
    public function add_file( $path, $name, $compress ) {
        $src = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $src ) {
            return false;
        }

        try {
            return $this->add_stream( $src, $name, $compress, (int) filesize( $path ), (int) filemtime( $path ) );
        } finally {
            fclose( $src );
        }
    }

    public function add_string( $name, $data ) {
        $src = fopen( 'php://temp', 'w+b' );
        fwrite( $src, $data );
        rewind( $src );

        try {
            return $this->add_stream( $src, $name, true, strlen( $data ), time() );
        } finally {
            fclose( $src );
        }
    }

    /**
     * @param resource $src      Джерело даних.
     * @param string   $name     Шлях усередині архіву (UTF-8).
     * @param bool     $compress Стискати (deflate) чи зберігати як є.
     * @param int      $size     Очікуваний розмір даних — за ним вирішується, чи потрібен ZIP64.
     * @param int      $mtime    Час зміни файлу.
     * @return bool false, якщо дані не вдалося прочитати — тоді запис відкочено.
     */
    public function add_stream( $src, $name, $compress, $size, $mtime ) {
        if ( PHP_INT_SIZE < 8 && $this->offset + $size > self::LIMIT_32BIT ) {
            throw new RuntimeException( __( 'На 32-бітному PHP архів не може бути більшим за 2 ГБ.', 'easy-site-backup' ) );
        }

        $start  = $this->offset;
        $zip64  = $size >= self::ZIP64_THRESHOLD;
        $method = $compress ? 8 : 0;

        list( $time, $date ) = self::dos_time( $mtime );

        $this->write_local_header( $name, $method, $time, $date, $zip64 );

        $crc     = hash_init( 'crc32b' );
        $deflate = $compress ? deflate_init( ZLIB_ENCODING_RAW, array( 'level' => 6 ) ) : null;
        $raw     = 0;
        $packed  = 0;

        while ( ! feof( $src ) ) {
            $chunk = @fread( $src, self::READ_BYTES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

            if ( false === $chunk ) {
                // Файл не читається (наприклад, заблокований іншою програмою).
                $this->rollback( $start );
                return false;
            }

            if ( '' === $chunk ) {
                break;
            }

            hash_update( $crc, $chunk );
            $raw += strlen( $chunk );

            $out     = $deflate ? deflate_add( $deflate, $chunk, ZLIB_NO_FLUSH ) : $chunk;
            $packed += strlen( $out );
            $this->write( $out );
        }

        if ( $deflate ) {
            $out     = deflate_add( $deflate, '', ZLIB_FINISH );
            $packed += strlen( $out );
            $this->write( $out );
        }

        // Файл виріс під час читання настільки, що без ZIP64 його вже не записати.
        if ( ! $zip64 && ( $raw >= self::LIMIT || $packed >= self::LIMIT ) ) {
            $this->rollback( $start );
            return false;
        }

        // hash_final() повертає CRC у big-endian, а ZIP зберігає little-endian.
        $this->finish_entry( $start, $name, $method, $time, $date, $zip64, strrev( hash_final( $crc, true ) ), $packed, $raw );

        return true;
    }

    /**
     * Великий файл без стиснення — частинами між кроками.
     *
     * begin_large() пише заголовок і повертає стан запису; continue_large() дописує дані до
     * дедлайну; end_large() завершує запис. Стан зберігається між кроками разом зі state()
     * записувача: якщо крок переб'ють, архів обріжеться до збереженого розміру, і копіювання
     * продовжиться з тієї самої позиції. CRC частин поєднується через ESB_Crc32 (лише 64-бітний PHP).
     *
     * @return array Стан запису.
     */
    public function begin_large( $name, $size, $mtime ) {
        list( $time, $date ) = self::dos_time( $mtime );

        $entry = array(
            'start' => $this->offset,
            'name'  => $name,
            'size'  => (int) $size,
            'done'  => 0,
            'crc'   => 0,
            'zip64' => $size >= self::ZIP64_THRESHOLD,
            'time'  => $time,
            'date'  => $date,
        );

        $this->write_local_header( $name, 0, $time, $date, $entry['zip64'] );

        return $entry;
    }

    /**
     * Дописує дані файлу до дедлайну, але щонайменше одну порцію.
     *
     * @return bool|null true — дописано повністю (або файл скоротився й закінчився раніше);
     *                   false — ще не все; null — файл не читається (тоді запис треба скасувати).
     */
    public function continue_large( array &$entry, $path, $deadline ) {
        $src = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $src ) {
            return null;
        }

        try {
            if ( 0 !== fseek( $src, $entry['done'] ) ) {
                return null;
            }

            do {
                $want = min( self::READ_BYTES, $entry['size'] - $entry['done'] );
                if ( $want <= 0 ) {
                    return true;
                }

                $chunk = @fread( $src, $want ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                if ( false === $chunk ) {
                    return null;
                }
                if ( '' === $chunk ) {
                    return true; // файл скоротився під час копіювання
                }

                $this->write( $chunk );
                $entry['crc']   = ESB_Crc32::combine( $entry['crc'], ESB_Crc32::of( $chunk ), strlen( $chunk ) );
                $entry['done'] += strlen( $chunk );
            } while ( microtime( true ) < $deadline );
        } finally {
            fclose( $src );
        }

        return $entry['done'] >= $entry['size'];
    }

    public function end_large( array $entry ) {
        $this->finish_entry( $entry['start'], $entry['name'], 0, $entry['time'], $entry['date'], $entry['zip64'], pack( 'V', $entry['crc'] ), $entry['done'], $entry['done'] );
    }

    /**
     * Прибирає недописаний великий файл з архіву.
     */
    public function abort_large( array $entry ) {
        $this->rollback( $entry['start'] );
    }

    /**
     * Локальний заголовок запису. Розміри й CRC поки невідомі: пишемо нулі, finish_entry() їх виправить.
     */
    private function write_local_header( $name, $method, $time, $date, $zip64 ) {
        $extra = $zip64 ? pack( 'vv', 0x0001, 16 ) . str_repeat( "\0", 16 ) : '';
        $this->write(
            pack( 'VvvvvvVVVvv', 0x04034b50, $zip64 ? 45 : 20, 0x0800, $method, $time, $date, 0, 0, 0, strlen( $name ), strlen( $extra ) )
            . $name . $extra
        );
    }

    /**
     * Виправляє розміри й CRC у локальному заголовку і додає запис до центрального каталогу.
     *
     * @param string $crc CRC32 у little-endian (4 байти).
     */
    private function finish_entry( $start, $name, $method, $time, $date, $zip64, $crc, $packed, $raw ) {
        $needed = $zip64 ? 45 : 20;

        if ( $zip64 ) {
            $this->patch( $start + 14, $crc . "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF" );
            $this->patch( $start + 30 + strlen( $name ) + 4, self::pack64( $raw ) . self::pack64( $packed ) );
        } else {
            $this->patch( $start + 14, $crc . pack( 'VV', $packed, $raw ) );
        }

        // ZIP64-поля в центральному каталозі — лише для значень, що не вміщаються в 32 біти, саме в такому порядку.
        $cd_extra = '';
        if ( $raw >= self::LIMIT ) {
            $cd_extra .= self::pack64( $raw );
        }
        if ( $packed >= self::LIMIT ) {
            $cd_extra .= self::pack64( $packed );
        }
        if ( $start >= self::LIMIT ) {
            $cd_extra .= self::pack64( $start );
        }
        if ( '' !== $cd_extra ) {
            $cd_extra = pack( 'vv', 0x0001, strlen( $cd_extra ) ) . $cd_extra;
            $needed   = 45;
        }

        $record = pack( 'Vvvvvvv', 0x02014b50, 45, $needed, 0x0800, $method, $time, $date )
            . $crc . self::pack32( $packed ) . self::pack32( $raw )
            . pack( 'vvvvvV', strlen( $name ), strlen( $cd_extra ), 0, 0, 0, 0 )
            . self::pack32( $start ) . $name . $cd_extra;

        if ( strlen( $record ) !== fwrite( $this->cd, $record ) ) {
            throw new RuntimeException( __( 'Не вдалося записати архів. Можливо, на диску закінчилося місце.', 'easy-site-backup' ) );
        }

        $this->cd_size += strlen( $record );
        $this->entries++;
    }

    /**
     * Переносить центральний каталог у кінець архіву і записує кінцеві записи.
     */
    public function finish() {
        $cd_offset = $this->offset;

        fseek( $this->cd, 0 );
        while ( ! feof( $this->cd ) ) {
            $chunk = fread( $this->cd, self::READ_BYTES );
            if ( false === $chunk ) {
                throw new RuntimeException( __( 'Не вдалося прочитати центральний каталог архіву.', 'easy-site-backup' ) );
            }
            if ( '' === $chunk ) {
                break;
            }
            $this->write( $chunk );
        }

        $cd_size = $this->offset - $cd_offset;

        if ( $this->entries >= 0xFFFF || $cd_offset >= self::LIMIT || $cd_size >= self::LIMIT ) {
            $eocd64 = $this->offset;

            $this->write(
                pack( 'V', 0x06064b50 ) . self::pack64( 44 ) . pack( 'vvVV', 45, 45, 0, 0 )
                . self::pack64( $this->entries ) . self::pack64( $this->entries )
                . self::pack64( $cd_size ) . self::pack64( $cd_offset )
            );
            $this->write( pack( 'VV', 0x07064b50, 0 ) . self::pack64( $eocd64 ) . pack( 'V', 1 ) );
        }

        $entries = min( $this->entries, 0xFFFF );
        $this->write(
            pack( 'Vvvvv', 0x06054b50, 0, 0, $entries, $entries )
            . self::pack32( $cd_size ) . self::pack32( $cd_offset ) . pack( 'v', 0 )
        );
    }

    public function close() {
        if ( $this->fh ) {
            fclose( $this->fh );
            $this->fh = null;
        }
        if ( $this->cd ) {
            fclose( $this->cd );
            $this->cd = null;
        }
    }

    private function write( $data ) {
        if ( '' === $data ) {
            return;
        }

        if ( strlen( $data ) !== fwrite( $this->fh, $data ) ) {
            throw new RuntimeException( __( 'Не вдалося записати архів. Можливо, на диску закінчилося місце.', 'easy-site-backup' ) );
        }

        $this->offset += strlen( $data );
    }

    private function patch( $position, $data ) {
        if ( 0 !== fseek( $this->fh, $position ) || strlen( $data ) !== fwrite( $this->fh, $data ) || 0 !== fseek( $this->fh, $this->offset ) ) {
            throw new RuntimeException( __( 'Не вдалося записати архів.', 'easy-site-backup' ) );
        }
    }

    private function rollback( $position ) {
        if ( ! ftruncate( $this->fh, $position ) || 0 !== fseek( $this->fh, $position ) ) {
            throw new RuntimeException( __( 'Не вдалося записати архів.', 'easy-site-backup' ) );
        }

        $this->offset = $position;
    }

    /**
     * Відкриває файл для дописування, відкинувши все після $size.
     */
    private static function open_at( $path, $size ) {
        // 'c+b' не обрізає наявний файл і створює новий, якщо його немає.
        $fh = @fopen( $path, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        if ( ! $fh ) {
            /* translators: %s: назва файлу */
            throw new RuntimeException( sprintf( __( 'Не вдалося відкрити файл %s.', 'easy-site-backup' ), basename( $path ) ) );
        }

        // Файл коротший за збережений стан (його видалили чи обрізали): ftruncate() доповнив би
        // його нулями, і архів вийшов би зіпсованим без жодної помилки.
        $stat = fstat( $fh );
        if ( ! $stat || $stat['size'] < $size || ! ftruncate( $fh, $size ) || 0 !== fseek( $fh, $size ) ) {
            fclose( $fh );
            /* translators: %s: назва файлу */
            throw new RuntimeException( sprintf( __( 'Файл %s пошкоджено або видалено посеред запису.', 'easy-site-backup' ), basename( $path ) ) );
        }

        return $fh;
    }

    private static function pack32( $value ) {
        return $value >= self::LIMIT ? "\xFF\xFF\xFF\xFF" : pack( 'V', $value );
    }

    private static function pack64( $value ) {
        // На 32-бітному PHP значення завжди менші за 2 ГБ (див. LIMIT_32BIT).
        return PHP_INT_SIZE >= 8 ? pack( 'P', $value ) : pack( 'VV', $value, 0 );
    }

    /**
     * @return int[] Час і дата у форматі MS-DOS.
     */
    private static function dos_time( $timestamp ) {
        // Формат ZIP не вміщує дат раніше 1980 року.
        $t = getdate( max( (int) $timestamp, 315532800 ) );

        return array(
            ( $t['hours'] << 11 ) | ( $t['minutes'] << 5 ) | ( $t['seconds'] >> 1 ),
            ( ( $t['year'] - 1980 ) << 9 ) | ( $t['mon'] << 5 ) | $t['mday'],
        );
    }
}
