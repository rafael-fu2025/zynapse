<?php

declare(strict_types=1);

namespace App\Services\Kiosk;

use App\Exceptions\ApiException;

/** Destination-keyed kiosk purpose validation shared by API dispatch. */
final class CheckinPurposeCatalog
{
    public const MAX_LENGTH = 120;

    /** @var array<string, list<string>> */
    public const PURPOSES = [
        'clinic' => [
            'Consultation',
            'Medical Certificate',
            'Dental',
            'Physical Exam',
            'Vaccination',
            'Laboratory',
            'Pharmacy',
            'Injury',
        ],
        // PRODUCT ASSUMPTION: the approved Guidance wording was not supplied.
        // Keep this provisional list isolated here and mirrored in the SPA so
        // product owners can replace labels without changing queue behavior.
        'counselling' => [
            'Initial Consultation',
            'Follow-up Session',
            'Crisis Support',
            'Referral Follow-up',
        ],
    ];

    public static function validate(string $destination, string $purpose, bool $customPurpose): string
    {
        if (! isset(self::PURPOSES[$destination])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Destination must be clinic or counselling.', 'field' => 'destination'],
            ]);
        }

        $purpose = trim($purpose);
        if ($purpose === '' || mb_strlen($purpose) > self::MAX_LENGTH) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'A purpose of at most 120 characters is required.', 'field' => 'purpose'],
            ]);
        }

        if ($customPurpose) {
            // A custom value still belongs to the selected destination and may
            // not impersonate a predefined option from either list.
            foreach (self::PURPOSES as $options) {
                if (in_array($purpose, $options, true)) {
                    throw ApiException::validationFailure([
                        ['code' => 'validation.field', 'message' => 'Select the predefined purpose card instead of submitting it as Other.', 'field' => 'purpose'],
                    ]);
                }
            }
            return $purpose;
        }

        if (! in_array($purpose, self::PURPOSES[$destination], true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'The selected purpose is not valid for this destination.', 'field' => 'purpose'],
            ]);
        }

        return $purpose;
    }
}
