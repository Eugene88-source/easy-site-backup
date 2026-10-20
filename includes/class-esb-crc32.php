<?php
/**
 * CRC32 частинами між кроками.
 *
 * Стан hash_init() не можна зберегти між запитами, тому для великого файлу CRC кожної
 * частини рахується окремо (hash('crc32b')), а потім частини поєднуються — так само,
 * як crc32_combine() у zlib (множення матриць над GF(2)).
 *
 * Потрібен 64-бітний PHP: значення CRC — беззнакові 32-бітні числа.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ESB_Crc32 {

    /**
     * @return int CRC32 рядка як беззнакове число.
     */
    public static function of( $data ) {
        return (int) hexdec( hash( 'crc32b', $data ) );
    }

    /**
     * CRC32 послідовності A+B за CRC32 частини A, CRC32 частини B і довжиною B.
     */
    public static function combine( $crc1, $crc2, $len2 ) {
        if ( $len2 <= 0 ) {
            return $crc1;
        }

        // Оператор «один нульовий біт» для поліному CRC32 (0xEDB88320).
        $odd    = array( 0xEDB88320 );
        $row    = 1;
        for ( $n = 1; $n < 32; $n++ ) {
            $odd[ $n ] = $row;
            $row     <<= 1;
        }

        $even = self::square( $odd );  // два нульові біти
        $odd  = self::square( $even ); // чотири

        // Застосовуємо до crc1 оператор «len2 нульових байтів», підносячи до квадрата по бітах len2.
        do {
            $even = self::square( $odd );
            if ( $len2 & 1 ) {
                $crc1 = self::times( $even, $crc1 );
            }
            $len2 >>= 1;
            if ( 0 === $len2 ) {
                break;
            }

            $odd = self::square( $even );
            if ( $len2 & 1 ) {
                $crc1 = self::times( $odd, $crc1 );
            }
            $len2 >>= 1;
        } while ( 0 !== $len2 );

        return ( $crc1 ^ $crc2 ) & 0xFFFFFFFF;
    }

    private static function times( array $matrix, $vector ) {
        $sum = 0;
        $i   = 0;
        while ( $vector ) {
            if ( $vector & 1 ) {
                $sum ^= $matrix[ $i ];
            }
            $vector >>= 1;
            $i++;
        }

        return $sum;
    }

    private static function square( array $matrix ) {
        $square = array();
        for ( $n = 0; $n < 32; $n++ ) {
            $square[ $n ] = self::times( $matrix, $matrix[ $n ] );
        }

        return $square;
    }
}
