<?php

namespace Saan\Modules\Health;

/**
 * Extension point for growth-standard classification (e.g. Thai Department of Health weight-for-height or BMI-for-age tables).
 * No reference table ships with the module, so nothing is classified. To add one, set GrowthReference::$resolver to
 * fn (array $student, float $bmi, ?float $weightKg, ?float $heightCm): ?string
 * where $student has id, sex (M/F/null) and birth_date (Y-m-d|null); return a short Thai label or null.
 * Measurements.php calls classify() and exposes the label as "growth" in the grid; with no resolver it is always null.
 */
final class GrowthReference
{
    /** @var null|callable */
    public static $resolver = null;

    public static function classify(array $student, ?float $bmi, ?float $weightKg, ?float $heightCm): ?string
    {
        if (self::$resolver === null || $bmi === null) return null;
        $label = (self::$resolver)($student, $bmi, $weightKg, $heightCm);
        return is_string($label) && $label !== '' ? $label : null;
    }
}
