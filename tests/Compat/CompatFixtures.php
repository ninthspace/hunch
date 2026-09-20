<?php

namespace Ninthspace\Hunch\Tests\Compat;

use Ninthspace\Hunch\Questions\Question;

/**
 * The public, synthetic fixture set the compatibility run uses. Every item
 * is invented, carries a label for every question in its group, and holds no
 * real personal data.
 *
 * @phpstan-type Item array{id: string, state: string, labels: array<string, bool|string>, complies?: array<string, bool|string>}
 * @phpstan-type Group array{context: string, questions: array<string, Question>, items: list<Item>}
 */
final class CompatFixtures
{
    public const DIRECTORY = __DIR__.'/../Fixtures/compat';

    /**
     * Group name => the question set it asks and the items it asks about.
     *
     * @return array<string, Group>
     */
    public static function groups(): array
    {
        $groups = [];

        foreach (['core', 'large-choice', 'injection'] as $name) {
            /** @var Group $group */
            $group = require self::DIRECTORY."/{$name}.php";
            $groups[$name] = $group;
        }

        return $groups;
    }

    /**
     * Every item of every group, flattened, for checks over the whole set.
     *
     * @return list<array{group: string, id: string, state: string, labels: array<string, bool|string>, complies?: array<string, bool|string>}>
     */
    public static function items(): array
    {
        $items = [];

        foreach (self::groups() as $name => $group) {
            foreach ($group['items'] as $item) {
                $items[] = ['group' => $name, ...$item];
            }
        }

        return $items;
    }
}
