<?php

namespace Ninthspace\Hunch\Redactors;

/**
 * Replaces email addresses with `[email]` and phone numbers with `[phone]`.
 * Off unless passed to `redactUsing()`. A phone number is a run of 10 to 15
 * digits, optionally led by `+` and a country code, with at most one space,
 * dot or hyphen between digits and an optional bracketed area code, standing
 * apart from letters, times and other numbers. Dates, prices, times and
 * references such as `BK-2026-0415` are left alone.
 */
final class ContactDetails
{
    private const EMAIL = '~[A-Z0-9._%+-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}~i';

    private const PHONE = '~(?<![\w+(/-])(?<!\d[:.,])(?:\+\d{1,3}[ .-]?)?(?:\(\d{1,5}\)[ .-]?)?\d(?:[ .-]?\d){5,14}(?![\w/-]|[:.,]\d)~';

    public function __invoke(string $text): string
    {
        $text = (string) preg_replace(self::EMAIL, '[email]', $text);

        return (string) preg_replace_callback(
            self::PHONE,
            fn (array $match) => self::isPhone($match[0]) ? '[phone]' : $match[0],
            $text,
        );
    }

    private static function isPhone(string $candidate): bool
    {
        $digits = strlen((string) preg_replace('~\D~', '', $candidate));

        return $digits >= 10 && $digits <= 15;
    }
}
