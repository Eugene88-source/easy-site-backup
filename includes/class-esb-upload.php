<?php
/**
 * Завантаження архіву копії з комп'ютера частинами.
 *
 * Браузер надсилає файл частинами (chunk_size), тож розмір архіву не впирається
 * в upload_max_filesize, post_max_size чи ліміт запиту Cloudflare (100 МБ). Частини
 * дописуються у backup.zip.part у новій теці копії; після останньої архів перевіряється
 * (має бути копією Easy Site Backup з manifest.json) і стає звичайною копією зі списку,
 * з якої можна відновити сайт.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ESB_Upload {

    const PART = 'backup.zip.part';

    const MAX_CHUNK = 8 * MB_IN_BYTES;

    /**
     * Запас під інші поля запиту, щоб частина вміщалася в post_max_size.
     */
    const REQUEST_OVERHEAD = 64 * KB_IN_BYTES;

    /**
     * @return int Розмір частини, яку прийме цей сервер.
     */
    public static function chunk_size() {
        return (int) max( 256 * KB_IN_BYTES, min( self::MAX_CHUNK, wp_max_upload_size() - self::REQUEST_OVERHEAD ) );
    }

    /**
     * @return ESB_Job|WP_Error Нова копія в стані uploading.
     */
    public static function start( $name, $size ) {
        $size = (int) $size;

        if ( ! preg_match( '/\.zip$/i', (string) $name ) ) {
            return new WP_Error( 'esb_upload_type', __( 'Потрібен ZIP-архів резервної копії Easy Site Backup.', 'easy-site-backup' ) );
        }

        if ( $size <= 0 ) {
            return new WP_Error( 'esb_upload_empty', __( 'Файл порожній.', 'easy-site-backup' ) );
        }

        try {
            ESB_Storage::require_space( $size + ESB_Storage::SPACE_MARGIN, __( 'завантаження архіву', 'easy-site-backup' ) );
        } catch ( RuntimeException $e ) {
            return new WP_Error( 'esb_space', $e->getMessage() );
        }

        $job = ESB_Job::create( '' );
        if ( is_wp_error( $job ) ) {
            return $job;
        }

        $job->data['status']   = 'uploading';
        $job->data['uploaded'] = true;
        $job->data['upload']   = array(
            'name'     => sanitize_file_name( $name ),
            'size'     => $size,
            'received' => 0,
        );

        try {
            $job->save();
        } catch ( Exception $e ) {
            ESB_Storage::delete_dir( $job->id );
            return new WP_Error( 'esb_save', $e->getMessage() );
        }

        $job->log( sprintf( 'Завантаження архіву з комп\'ютера: %s (%s)', $job->data['upload']['name'], size_format( $size, 1 ) ) );

        return $job;
    }

    /**
     * Дописує частину файлу з позиції $offset.
     *
     * Повтор уже надісланої частини (обірвалася відповідь) безпечний: файл обрізається до $offset
     * і частина записується наново. Пропуск даних ($offset більший за отримане) — помилка.
     *
     * @param string $source Файл із даними частини.
     * @return int|WP_Error Скільки байтів отримано загалом.
     */
    public static function append( $id, $offset, $source ) {
        $job = self::uploading_job( $id );
        if ( is_wp_error( $job ) ) {
            return $job;
        }

        $upload = &$job->data['upload'];
        $offset = (int) $offset;
        $length = (int) filesize( $source );

        if ( $offset < 0 || $offset > $upload['received'] ) {
            return new WP_Error( 'esb_upload_offset', __( 'Частину файлу пропущено. Почніть завантаження спочатку.', 'easy-site-backup' ) );
        }

        if ( $length <= 0 || $offset + $length > $upload['size'] ) {
            return new WP_Error( 'esb_upload_size', __( 'Розмір отриманих даних не збігається з розміром файлу.', 'easy-site-backup' ) );
        }

        $out = @fopen( $job->path( self::PART ), 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $in  = @fopen( $source, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $ok  = $out && $in && ftruncate( $out, $offset ) && 0 === fseek( $out, $offset )
            && stream_copy_to_stream( $in, $out ) === $length;

        if ( $in ) {
            fclose( $in );
        }
        if ( $out ) {
            fclose( $out );
        }

        if ( ! $ok ) {
            return new WP_Error( 'esb_upload_write', __( 'Не вдалося записати частину файлу. Можливо, на диску закінчилося місце.', 'easy-site-backup' ) );
        }

        $upload['received'] = $offset + $length;

        try {
            $job->save();
        } catch ( Exception $e ) {
            return new WP_Error( 'esb_save', $e->getMessage() );
        }

        return $upload['received'];
    }

    /**
     * Перевіряє отриманий архів і робить його звичайною копією.
     *
     * @return ESB_Job|WP_Error
     */
    public static function finish( $id ) {
        $job = self::uploading_job( $id );
        if ( is_wp_error( $job ) ) {
            return $job;
        }

        $upload = $job->data['upload'];
        if ( $upload['received'] !== $upload['size'] ) {
            return new WP_Error( 'esb_upload_incomplete', __( 'Файл отримано не повністю.', 'easy-site-backup' ) );
        }

        try {
            $manifest = self::read_manifest( $job->path( self::PART ) );
        } catch ( RuntimeException $e ) {
            $job->log( 'Архів відхилено: ' . $e->getMessage() );
            ESB_Storage::delete_dir( $job->id );
            return new WP_Error( 'esb_upload_invalid', $e->getMessage() );
        }

        if ( ! rename( $job->path( self::PART ), $job->path( ESB_Storage::ARCHIVE ) ) ) {
            return new WP_Error( 'esb_upload_write', __( 'Не вдалося зберегти архів.', 'easy-site-backup' ) );
        }

        $created = isset( $manifest['created'] ) ? strtotime( $manifest['created'] ) : false;

        $job->data['type']        = $manifest['type'];
        $job->data['status']      = 'done';
        // Дата самої копії, а не завантаження — так у списку видно, на який момент вона.
        $job->data['created']     = $created ? $created : $job->data['created'];
        $job->data['uploaded_at'] = time();
        $job->data['finished']    = time();
        $job->data['size']        = $upload['size'];

        try {
            $job->save();
        } catch ( Exception $e ) {
            return new WP_Error( 'esb_save', $e->getMessage() );
        }

        $job->log( sprintf( 'Архів завантажено й перевірено: тип %s, копію створено %s на %s', $manifest['type'], isset( $manifest['created'] ) ? $manifest['created'] : '?', isset( $manifest['home_url'] ) ? $manifest['home_url'] : '?' ) );

        return $job;
    }

    /**
     * @return array manifest.json архіву.
     */
    private static function read_manifest( $path ) {
        $reader = new ESB_Zip_Reader( $path );

        try {
            $position = $reader->cd_offset;
            while ( $entry = $reader->next_entry( $position ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
                if ( 'manifest.json' === $entry['name'] ) {
                    $manifest = json_decode( $reader->read_string( $entry, ESB_Restore::MANIFEST_MAX_BYTES ), true );
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        if ( empty( $manifest ) || ! is_array( $manifest ) || ! isset( $manifest['type'] ) || ! in_array( $manifest['type'], ESB_Backup::TYPES, true ) ) {
            throw new RuntimeException( __( 'Це не резервна копія Easy Site Backup: в архіві немає коректного manifest.json.', 'easy-site-backup' ) );
        }

        return $manifest;
    }

    /**
     * @return ESB_Job|WP_Error
     */
    private static function uploading_job( $id ) {
        $job = ESB_Job::load( $id );

        if ( ! $job || 'uploading' !== $job->data['status'] ) {
            return new WP_Error( 'esb_upload_not_found', __( 'Завантаження не знайдено. Почніть його спочатку.', 'easy-site-backup' ) );
        }

        return $job;
    }
}
