<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

/**
 * Library holidays from SLiMS's own `holiday` table (System → Holiday): weekly days off (rows with a
 * day name and no date, e.g. sat/sun) and dated holidays. Scheduled inspections never fall on them:
 * an occurrence on a holiday moves to the next working day, except daily schedules, which skip it
 * (moving would land on the next day's own occurrence).
 */
final class Holidays
{
    /** @var array<string,true> lower-case three-letter day names, e.g. ['sun' => true] */
    private array $weekly = [];
    /** @var array<string,string> Y-m-d => description */
    private array $dates = [];

    public function __construct(\PDO $db)
    {
        try {
            foreach ($db->query('SELECT holiday_dayname, holiday_date, description FROM holiday')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if ($row['holiday_date'] === null || $row['holiday_date'] === '' || str_starts_with((string) $row['holiday_date'], '0000')) {
                    $this->weekly[strtolower(substr(trim((string) $row['holiday_dayname']), 0, 3))] = true;
                } else {
                    $this->dates[substr((string) $row['holiday_date'], 0, 10)] = trim((string) ($row['description'] ?? '')) ?: 'Hari libur';
                }
            }
        } catch (\PDOException $e) {
            // Without the holiday table (non-SLiMS test databases) every day is a working day.
        }
        // A calendar that marks every weekday off would leave no working day to move to.
        if (count($this->weekly) >= 7) $this->weekly = [];
    }

    public function isOff(string $date): bool
    {
        return isset($this->dates[$date]) || isset($this->weekly[strtolower((new \DateTimeImmutable($date))->format('D'))]);
    }

    /** Why a date is off, for previews ("Minggu", or the holiday's description). */
    public function reason(string $date): ?string
    {
        if (isset($this->dates[$date])) return $this->dates[$date];
        $day = strtolower((new \DateTimeImmutable($date))->format('D'));
        return isset($this->weekly[$day]) ? ['sun' => 'Minggu', 'mon' => 'Senin', 'tue' => 'Selasa', 'wed' => 'Rabu', 'thu' => 'Kamis', 'fri' => 'Jumat', 'sat' => 'Sabtu'][$day] : null;
    }

    /**
     * The working date for a scheduled occurrence: unchanged on a working day, the next working day
     * otherwise, or null when it is skipped (daily schedules, or moved past the schedule's end).
     */
    public function shift(string $date, string $frequency, ?string $end = null): ?string
    {
        if (!$this->isOff($date)) return $date;
        if ($frequency === 'daily') return null;
        $day = new \DateTimeImmutable($date);
        for ($i = 0; $i < 60 && $this->isOff($day->format('Y-m-d')); $i++) $day = $day->modify('+1 day');
        $moved = $day->format('Y-m-d');
        return $end !== null && $end !== '' && $moved > $end ? null : $moved;
    }
}
