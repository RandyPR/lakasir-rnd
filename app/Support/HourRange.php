<?php

namespace App\Support;

/**
 * Which hours of the day a chart or heatmap draws: the hours with sales, one hour of air on each side, and at least
 * eight hours, so a shop open 17.00 to 23.00 does not get a chart that is mostly empty.
 */
final class HourRange
{
    private function __construct()
    {
    }

    /**
     * @param  array<int, int|float>  $counts  per hour 0 to 23
     * @return list<int> consecutive hours; the whole evening-to-night when nothing was sold gives an empty list
     */
    public static function around(array $counts): array
    {
        $active = array_keys(array_filter($counts, fn ($count): bool => $count > 0));

        if ($active === []) {
            return [];
        }

        $from = max(0, min($active) - 1);
        $to = min(23, max($active) + 1);

        while ($to - $from < 7) {
            if ($from > 0) {
                $from--;
            }
            if ($to - $from < 7 && $to < 23) {
                $to++;
            }
        }

        return range($from, $to);
    }
}
