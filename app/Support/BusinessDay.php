<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The shop's business day.
 *
 * Every datetime is stored and compared in UTC (config/app.php stays on UTC), but the daily order number,
 * the "Name (2)" suffix and "sales today" reset at local midnight of config('pos.business_timezone').
 * A business date is a 'Y-m-d' string in that timezone; a range of it is [startUtc, endUtc) in UTC.
 */
final class BusinessDay
{
    public const FALLBACK_TIMEZONE = 'Asia/Jakarta';

    private const UTC_FORMAT = 'Y-m-d H:i:s';

    /** @var array<string, bool> */
    private static array $validated = [];

    private function __construct()
    {
    }

    /**
     * The configured business timezone, or Asia/Jakarta when it is empty or not a known identifier.
     */
    public static function timezone(): string
    {
        $timezone = config('pos.business_timezone');
        if (! is_string($timezone) || $timezone === '') {
            return self::FALLBACK_TIMEZONE;
        }

        self::$validated[$timezone] ??= in_array($timezone, timezone_identifiers_list(), true);

        return self::$validated[$timezone] ? $timezone : self::FALLBACK_TIMEZONE;
    }

    /**
     * The current business date as 'Y-m-d'.
     */
    public static function today(?CarbonInterface $now = null): string
    {
        $now = $now ? Carbon::instance($now) : Carbon::now();

        return $now->setTimezone(self::timezone())->format('Y-m-d');
    }

    /**
     * One moment as a UTC 'Y-m-d H:i:s' string (the format the database stores).
     *
     * null, '' and unreadable input mean "now". A Carbon is converted to UTC. A string without a zone is UTC
     * (like stored values); 'Z' and '+07:00' offsets are honoured, then converted. A bare 'Y-m-d' is
     * 00:00:00 UTC. Microseconds are dropped.
     */
    public static function normalize(CarbonInterface|DateTimeInterface|string|null $moment): string
    {
        if ($moment instanceof DateTimeInterface) {
            return Carbon::instance($moment)->utc()->format(self::UTC_FORMAT);
        }

        $moment = is_string($moment) ? trim($moment) : null;
        if ($moment === null || $moment === '') {
            return Carbon::now()->utc()->format(self::UTC_FORMAT);
        }

        // Only ISO-like input is trusted; relative words ("tomorrow", "next friday") must not become a date.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}(?:$|[T\s])/', $moment)) {
            return Carbon::now()->utc()->format(self::UTC_FORMAT);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $moment)) {
            $moment .= ' 00:00:00';
        }

        try {
            return Carbon::parse($moment, 'UTC')->utc()->format(self::UTC_FORMAT);
        } catch (Throwable) {
            return Carbon::now()->utc()->format(self::UTC_FORMAT);
        }
    }

    /**
     * The business date ('Y-m-d') a moment belongs to.
     */
    public static function dateOf(CarbonInterface|DateTimeInterface|string|null $moment): string
    {
        return Carbon::parse(self::normalize($moment), 'UTC')
            ->setTimezone(self::timezone())
            ->format('Y-m-d');
    }

    /**
     * Start of a business date, as a UTC instant (inclusive).
     */
    public static function startUtc(string $businessDate): Carbon
    {
        return Carbon::parse($businessDate, self::timezone())->startOfDay()->utc();
    }

    /**
     * End of a business date, as a UTC instant (exclusive: the start of the next business date).
     */
    public static function endUtc(string $businessDate): Carbon
    {
        return Carbon::parse($businessDate, self::timezone())->startOfDay()->addDay()->utc();
    }

    /**
     * @return array{0: Carbon, 1: Carbon} [startUtc, endUtc) of a business date (default today)
     */
    public static function range(?string $businessDate = null): array
    {
        $businessDate ??= self::today();

        return [self::startUtc($businessDate), self::endUtc($businessDate)];
    }

    /**
     * Restricts $column to the business date: column >= start AND column < end.
     * Binds 'Y-m-d H:i:s' UTC strings so it behaves the same for DATETIME and TIMESTAMP columns on MySQL
     * and SQLite (never wraps the column in DATE(), so an index on it stays usable).
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Relations\Relation
     *
     * @param  T  $query
     * @return T
     */
    public static function scopeDay($query, string $column, string $businessDate)
    {
        [$start, $end] = self::range($businessDate);

        return $query
            ->where($column, '>=', $start->format(self::UTC_FORMAT))
            ->where($column, '<', $end->format(self::UTC_FORMAT));
    }

    /**
     * A short marker for a bill from another day: null for today, 'KEMARIN' for yesterday, otherwise 'd/m'.
     */
    public static function label(string $businessDate, ?string $today = null): ?string
    {
        $today ??= self::today();
        if ($businessDate === $today) {
            return null;
        }

        $yesterday = Carbon::parse($today, 'UTC')->subDay()->format('Y-m-d');
        if ($businessDate === $yesterday) {
            return 'KEMARIN';
        }

        return Carbon::parse($businessDate, 'UTC')->format('d/m');
    }
}
