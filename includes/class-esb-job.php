<?php
/**
 * Стан одного завдання резервного копіювання.
 *
 * Стан зберігається у файлі job.json у теці копії, а не в базі даних,
 * щоб надалі його не зачепило відновлення бази.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ESB_Job {

    public $id;

    public $data = array();

    /**
     * Крок пропущено, бо це завдання вже обробляє інший процес.
     */
    public $busy = false;

    private $lock;

    /**
     * @return ESB_Job|WP_Error
     */
    public static function create( $type ) {
        $base = ESB_Storage::ensure_dir();
        if ( is_wp_error( $base ) ) {
            return $base;
        }

        $id  = ESB_Storage::new_id();
        $dir = $base . '/' . $id;

        if ( ! wp_mkdir_p( $dir ) || false === file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ) ) {
            /* translators: %s: шлях до теки */
            return new WP_Error( 'esb_mkdir', sprintf( __( 'Не вдалося створити теку %s.', 'easy-site-backup' ), $dir ) );
        }

        return new self(
            $id,
            array(
                'id'       => $id,
                'type'     => $type,
                'status'   => 'running',
                'phase'    => '',
                'created'  => time(),
                'updated'  => time(),
                'finished' => 0,
                'attempts' => 0,
                'error'    => '',
                'size'     => 0,
            )
        );
    }

    /**
     * @return ESB_Job|null
     */
    public static function load( $id ) {
        $dir = ESB_Storage::job_dir( $id );

        if ( ! $dir || ! is_readable( $dir . '/job.json' ) ) {
            return null;
        }

        $data = json_decode( (string) file_get_contents( $dir . '/job.json' ), true );

        return is_array( $data ) ? new self( $id, $data ) : null;
    }

    private function __construct( $id, array $data ) {
        $this->id   = $id;
        $this->data = $data;
    }

    /**
     * Перечитує стан з диска — потрібно після отримання блокування.
     */
    public function reload() {
        $fresh = self::load( $this->id );
        if ( $fresh ) {
            $this->data = $fresh->data;
        }
    }

    public function dir() {
        return ESB_Storage::job_dir( $this->id );
    }

    public function path( $name ) {
        return $this->dir() . '/' . $name;
    }

    public function save() {
        $this->data['updated'] = time();

        $tmp = $this->path( 'job.json.tmp' );
        if ( false === file_put_contents( $tmp, wp_json_encode( $this->data ) ) || ! rename( $tmp, $this->path( 'job.json' ) ) ) {
            throw new RuntimeException( __( 'Не вдалося зберегти стан резервного копіювання. Можливо, на диску закінчилося місце.', 'easy-site-backup' ) );
        }
    }

    /**
     * Час — за поясом сайту, з позначкою поясу (UTC, EEST, +03:00…).
     */
    public function log( $message ) {
        file_put_contents( $this->path( 'log.txt' ), self::site_time( 'Y-m-d H:i:s' ) . '  ' . $message . "\n", FILE_APPEND | LOCK_EX );
    }

    /**
     * Поточний час сайту з позначкою поясу.
     */
    public static function site_time( $format ) {
        // Нульове зміщення PHP позначає як «GMT+0000».
        $zone = 0 === wp_timezone()->getOffset( new DateTime() ) ? 'UTC' : wp_date( 'T' );

        return wp_date( $format ) . ' ' . $zone;
    }

    /**
     * Блокування через flock: якщо процес помре, система зніме його сама.
     */
    public function lock() {
        $fh = @fopen( $this->path( '.lock' ), 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $fh ) {
            return false;
        }

        if ( ! flock( $fh, LOCK_EX | LOCK_NB ) ) {
            fclose( $fh );
            return false;
        }

        $this->lock = $fh;

        return true;
    }

    public function unlock() {
        if ( $this->lock ) {
            flock( $this->lock, LOCK_UN );
            fclose( $this->lock );
            $this->lock = null;
        }
    }

    public function is_running() {
        return 'running' === $this->data['status'];
    }

    public function cancel_requested() {
        return file_exists( $this->path( 'cancel' ) );
    }

    public function size() {
        return isset( $this->data['size'] ) ? (int) $this->data['size'] : 0;
    }

    /**
     * Готовий архів можна завантажити.
     */
    public function has_archive() {
        return 'done' === $this->data['status'] && is_file( $this->path( ESB_Storage::ARCHIVE ) );
    }
}
