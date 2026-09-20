<?php

/**
 * Twenty labelled strings for the ContactDetails redactor: ten carrying
 * contact details, with the text expected after redaction, and ten that
 * must come back unchanged.
 *
 * @return array{positives: array<string, array{string, string}>, negatives: array<string, array{string}>}
 */
return [
    'positives' => [
        'email' => ['Email me at alice@example.com please.', 'Email me at [email] please.'],
        'uk mobile with country code' => ['Call +44 7700 900123 after lunch.', 'Call [phone] after lunch.'],
        'uk mobile before a comma' => ['My mobile is 07700 900123, any time.', 'My mobile is [phone], any time.'],
        'us bracketed area code' => ['Office: (555) 123-4567', 'Office: [phone]'],
        'email and landline' => ['Reach bob.smith+orders@shop.co.uk or 020 7946 0958.', 'Reach [email] or [phone].'],
        'us hyphenated' => ['US line 555-123-4567 is best.', 'US line [phone] is best.'],
        'email with subdomain' => ['Contact: j.doe@mail.example.org', 'Contact: [email]'],
        'uk landline' => ['Phone 0161 496 0000 during office hours.', 'Phone [phone] during office hours.'],
        'us with country code' => ['+1 (555) 123-4567 anytime', '[phone] anytime'],
        'unspaced mobile and upper-case email' => ['Text 07700900123 or email ALICE@EXAMPLE.COM', 'Text [phone] or email [email]'],
    ],
    'negatives' => [
        'booking reference' => ['Booking reference BK-2026-0415 confirmed.'],
        'iso date and time' => ['Arriving on 2026-04-15 at 14:30.'],
        'price' => ['The total is £1,299.00 including VAT.'],
        'times' => ['Check-in from 15:00, check-out by 11:00.'],
        'slashed date and times' => ['Delivered 15/04/2026 between 09:00 and 17:30.'],
        'order number' => ['Order 48213 shipped in 3 boxes.'],
        'prices' => ['Price dropped from $45.99 to $39.50.'],
        'room numbers' => ['Room 1204, floor 12, sleeps 4.'],
        'date, time and a bare at sign' => ['Meet at 2026-04-15 10:30 by the @reception desk.'],
        'version and long date' => ['Version 2.10.3 released on 1 April 2026.'],
    ],
];
