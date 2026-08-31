<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

use InvalidArgumentException;

final readonly class Dimension
{
    public const int SCALE = 4;

    public const array VALID_UNITS = ['mm', 'cm', 'm', 'in'];

    /** @var numeric-string */
    private string $length; // cm

    /** @var numeric-string */
    private string $width;  // cm

    /** @var numeric-string */
    private string $height; // cm

    public function __construct(string|int $length, string|int $width, string|int $height, string $unit = 'cm')
    {
        $cleanUnit = strtolower(trim($unit));
        if (! in_array($cleanUnit, self::VALID_UNITS, true)) {
            throw new InvalidArgumentException("Invalid dimension unit [{$unit}]. Valid units: ".implode(', ', self::VALID_UNITS));
        }

        $lStr = (string) $length;
        $wStr = (string) $width;
        $hStr = (string) $height;

        if (! is_numeric($lStr) || ! is_numeric($wStr) || ! is_numeric($hStr)) {
            throw new InvalidArgumentException('Dimensions must be numeric.');
        }

        /** @var numeric-string $l */
        $l = bcadd($lStr, '0', self::SCALE);
        /** @var numeric-string $w */
        $w = bcadd($wStr, '0', self::SCALE);
        /** @var numeric-string $h */
        $h = bcadd($hStr, '0', self::SCALE);

        if (bccomp($l, '0', self::SCALE) < 0 || bccomp($w, '0', self::SCALE) < 0 || bccomp($h, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException('Dimensions cannot be negative.');
        }

        /** @var numeric-string $toCmFactor */
        $toCmFactor = match ($cleanUnit) {
            'cm' => '1',
            'mm' => '0.1',
            'm' => '100',
            'in' => '2.54',
        };

        /** @var numeric-string $lRes */
        $lRes = bcmul($l, $toCmFactor, self::SCALE);
        /** @var numeric-string $wRes */
        $wRes = bcmul($w, $toCmFactor, self::SCALE);
        /** @var numeric-string $hRes */
        $hRes = bcmul($h, $toCmFactor, self::SCALE);

        $this->length = $lRes;
        $this->width = $wRes;
        $this->height = $hRes;
    }

    /**
     * @return numeric-string
     */
    public function getLengthCm(): string
    {
        return $this->length;
    }

    /**
     * @return numeric-string
     */
    public function getWidthCm(): string
    {
        return $this->width;
    }

    /**
     * @return numeric-string
     */
    public function getHeightCm(): string
    {
        return $this->height;
    }

    /**
     * @return numeric-string
     */
    public function getVolumeCm3(): string
    {
        /** @var numeric-string $lw */
        $lw = bcmul($this->length, $this->width, self::SCALE);
        /** @var numeric-string $vol */
        $vol = bcmul($lw, $this->height, self::SCALE);

        return $vol;
    }
}
