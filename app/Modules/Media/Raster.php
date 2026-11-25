<?php

namespace App\Modules\Media;

use GdImage;
use RuntimeException;

final class Raster
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** @return array{mime_type:string,byte_size:int,width:int,height:int} */
    public static function inspect(string $bytes): array
    {
        $size = strlen($bytes);
        if ($size === 0 || $size > self::MAX_BYTES) {
            throw new InvalidImage;
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new InvalidImage;
        }

        [$width, $height, $type] = $info;
        if ($width < 1 || $height < 1 || $width > 4096 || $height > 4096 || $width * $height > 16_000_000) {
            throw new InvalidImage;
        }
        if ($type === IMAGETYPE_PNG) {
            self::checkChunks($bytes, png: true);
        }
        if ($type === IMAGETYPE_WEBP) {
            self::checkChunks($bytes, png: false);
        }
        if ($type === IMAGETYPE_JPEG) {
            self::checkJpeg($bytes);
        }

        return ['mime_type' => $info['mime'], 'byte_size' => $size, 'width' => $width, 'height' => $height];
    }

    private static function checkChunks(string $bytes, bool $png): void
    {
        $end = strlen($bytes);
        $offset = $png ? 8 : 12;
        $headerSize = $png ? 12 : 8;
        if (! $png && (int) (unpack('V', $bytes, 4)[1] ?? throw new InvalidImage) + 8 !== $end) {
            throw new InvalidImage;
        }
        while ($offset + $headerSize <= $end) {
            $type = substr($bytes, $offset + ($png ? 4 : 0), 4);
            $size = (int) (unpack($png ? 'N' : 'V', $bytes, $offset + ($png ? 0 : 4))[1] ?? throw new InvalidImage);
            $next = $offset + $headerSize + $size + ($png ? 0 : $size % 2);
            if ($next > $end || in_array($type, ['acTL', 'fcTL', 'fdAT', 'ANIM', 'ANMF'], true)) {
                throw new InvalidImage;
            }
            if ($png && $type === 'IEND' && $size === 0 && $next === $end) {
                return;
            }
            if (! $png && $type === 'VP8X' && ($size !== 10 || (ord($bytes[$offset + 8]) & 0x02) !== 0)) {
                throw new InvalidImage;
            }
            $offset = $next;
        }
        if ($png || $offset !== $end) {
            throw new InvalidImage;
        }
    }

    private static function checkJpeg(string $bytes): void
    {
        $length = strlen($bytes);
        if (! str_ends_with($bytes, "\xff\xd9")) {
            throw new InvalidImage;
        }
        for ($offset = 2; $offset + 3 < $length;) {
            if (ord($bytes[$offset++]) !== 0xFF) {
                throw new InvalidImage;
            }
            while ($offset < $length && ord($bytes[$offset]) === 0xFF) {
                $offset++;
            }
            if ($offset + 2 >= $length) {
                break;
            }
            $marker = ord($bytes[$offset++]);
            if ($marker === 0xDA) {
                return;
            }
            $size = (int) (unpack('n', $bytes, $offset)[1] ?? throw new InvalidImage);
            if ($size < 2 || $offset + $size > $length || ($marker === 0xE2 && substr($bytes, $offset + 2, 4) === "MPF\0")) {
                throw new InvalidImage;
            }
            $offset += $size;
        }

        throw new InvalidImage;
    }

    public static function decode(string $bytes): GdImage
    {
        $info = self::inspect($bytes);
        $image = @imagecreatefromstring($bytes);
        if ($image === false || imagesx($image) !== $info['width'] || imagesy($image) !== $info['height']) {
            throw new InvalidImage;
        }

        return $image;
    }

    /** @return array{bytes:string,width:int,height:int} */
    public static function variant(GdImage $image, int $side): array
    {
        $ratio = min(1, $side / max(imagesx($image), imagesy($image)));
        $width = max(1, (int) round(imagesx($image) * $ratio));
        $height = max(1, (int) round(imagesy($image) * $ratio));
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        ob_start();
        try {
            $success = imagewebp($canvas, null, 82);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (! $success || ! is_string($bytes) || ! str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
            throw new RuntimeException('Media encoding failed.');
        }

        return ['bytes' => $bytes, 'width' => $width, 'height' => $height];
    }
}
