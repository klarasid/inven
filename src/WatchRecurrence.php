<?php
namespace SLiMS\Plugins\Inventory;

final class WatchRecurrence
{
    public const FREQUENCIES = ['daily'=>'Harian','weekly'=>'Mingguan','monthly'=>'Bulanan','quarterly'=>'Tiga bulanan','semiannual'=>'Enam bulanan','annual'=>'Tahunan'];
    public static function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value || $value < '1000-01-01') throw new \RuntimeException('Tanggal tidak valid.');
        return $date;
    }
    public static function at(string $start, string $frequency, int $index): string
    {
        $date = self::date($start);
        if ($index < 0 || !isset(self::FREQUENCIES[$frequency])) throw new \RuntimeException('Frekuensi tidak valid.');
        if ($frequency === 'daily' || $frequency === 'weekly') return $date->modify('+' . ($index * ($frequency === 'weekly' ? 7 : 1)) . ' days')->format('Y-m-d');
        $months = ['monthly'=>1,'quarterly'=>3,'semiannual'=>6,'annual'=>12][$frequency] * $index;
        $month = $date->modify('first day of this month')->modify("+$months months");
        return $month->setDate((int)$month->format('Y'), (int)$month->format('m'), min((int)$date->format('d'), (int)$month->format('t')))->format('Y-m-d');
    }
    /**
     * Occurrence dates within [from, to]. With a holiday calendar, dates are moved off holidays (or
     * skipped for daily schedules) exactly as sync() forms them, so projections match formed inspections.
     */
    public static function dates(string $start, string $frequency, string $from, string $to, ?Holidays $holidays = null, ?string $end = null): \Generator
    {
        if ($holidays) {
            $seen = [];
            // Start a little earlier: an occurrence just before $from may be moved into the period.
            foreach (self::dates($start, $frequency, self::date($from)->modify('-60 days')->format('Y-m-d'), $to) as $raw) {
                $date = $holidays->shift($raw, $frequency, $end);
                if ($date === null || $date < $from || $date > $to || isset($seen[$date])) continue;
                $seen[$date] = true;
                yield $date;
            }
            return;
        }
        $anchor = self::date($start); $first = self::date($from); self::date($to);
        if (!isset(self::FREQUENCIES[$frequency])) throw new \RuntimeException('Frekuensi tidak valid.');
        if ($frequency === 'daily' || $frequency === 'weekly') {
            $days = max(0, (int)$anchor->diff($first)->format('%r%a'));
            $index = (int) floor($days / ($frequency === 'weekly' ? 7 : 1));
        } else {
            $months = ((int)$first->format('Y')-(int)$anchor->format('Y'))*12+(int)$first->format('m')-(int)$anchor->format('m');
            $index = max(0, (int)floor($months / ['monthly'=>1,'quarterly'=>3,'semiannual'=>6,'annual'=>12][$frequency]));
        }
        while (($date = self::at($start, $frequency, $index++)) <= $to) {
            if ($date >= $from) yield $date;
        }
    }
}
