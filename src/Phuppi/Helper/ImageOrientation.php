<?php

/**
 * ImageOrientation.php
 *
 * ImageOrientation class for honoring the EXIF orientation tag of JPEG photos. GD loads the raw pixels
 * and ignores the tag, so a photo taken in portrait would otherwise get a sideways thumbnail.
 * Reads the tag without the PHP exif extension.
 *
 * @package Phuppi\Helper
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.8.0
 */

namespace Phuppi\Helper;

use GdImage;

class ImageOrientation
{
    /**
     * Reads the EXIF orientation (1-8) of a JPEG file. Returns 1 (upright) when there is none.
     *
     * @param string $path Path to the file.
     * @return int
     */
    public static function read(string $path): int
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return 1;
        }

        try {
            if (fread($handle, 2) !== "\xFF\xD8") {
                return 1;
            }

            // Walk the JPEG segments looking for the EXIF block (APP1)
            while (!feof($handle)) {
                $header = fread($handle, 4);
                if (strlen($header) < 4 || $header[0] !== "\xFF") {
                    return 1;
                }
                $marker = ord($header[1]);
                $length = unpack('n', substr($header, 2, 2))[1];
                if ($marker === 0xDA || $marker === 0xD9 || $length < 2) {
                    return 1; // start of scan or end of image: no EXIF block
                }

                if ($marker === 0xE1) {
                    $segment = fread($handle, $length - 2);
                    return self::orientationFromExif($segment);
                }
                fseek($handle, $length - 2, SEEK_CUR);
            }
        } finally {
            fclose($handle);
        }

        return 1;
    }

    /**
     * Returns the image turned and flipped so it is upright for the given EXIF orientation.
     *
     * @param GdImage $image The image as GD loaded it.
     * @param int $orientation EXIF orientation, 1-8.
     * @return GdImage The upright image (the input image when nothing needs to change).
     */
    public static function apply(GdImage $image, int $orientation): GdImage
    {
        // GD rotates counterclockwise; EXIF 5 and 7 are a rotation plus a mirror
        $steps = match ($orientation) {
            2 => [null, 'h'],
            3 => [180, null],
            4 => [null, 'v'],
            5 => [-90, 'h'],
            6 => [-90, null],
            7 => [90, 'h'],
            8 => [90, null],
            default => null,
        };
        if ($steps === null) {
            return $image;
        }

        [$angle, $flip] = $steps;
        if ($angle !== null) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated === false) {
                return $image;
            }
            imagedestroy($image);
            $image = $rotated;
        }
        if ($flip !== null) {
            imageflip($image, $flip === 'h' ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        }

        return $image;
    }

    private static function orientationFromExif(string $segment): int
    {
        if (substr($segment, 0, 6) !== "Exif\0\0" || strlen($segment) < 20) {
            return 1;
        }

        $tiff = substr($segment, 6);
        $format = substr($tiff, 0, 2) === 'II' ? ['v', 'V'] : (substr($tiff, 0, 2) === 'MM' ? ['n', 'N'] : null);
        if ($format === null) {
            return 1;
        }
        [$short, $long] = $format;

        $ifdOffset = unpack($long, substr($tiff, 4, 4))[1];
        if ($ifdOffset + 2 > strlen($tiff)) {
            return 1;
        }

        $entries = unpack($short, substr($tiff, $ifdOffset, 2))[1];
        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifdOffset + 2 + $i * 12;
            if ($entry + 12 > strlen($tiff)) {
                break;
            }
            if (unpack($short, substr($tiff, $entry, 2))[1] === 0x0112) {
                $value = unpack($short, substr($tiff, $entry + 8, 2))[1];
                return ($value >= 1 && $value <= 8) ? $value : 1;
            }
        }

        return 1;
    }
}
