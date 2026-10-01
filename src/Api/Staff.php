<?php

namespace SLiMS\Plugins\Inventory\Api;

use PDO;

/**
 * The librarian behind a request, with the Stock Take privileges SLiMS gives their groups.
 *
 * Read fresh on every request rather than kept in the token: a librarian whose account is
 * switched off or whose group loses Stock Take is refused on the next request, not an hour later.
 */
final class Staff
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $name,
        public readonly bool $canRead,
        public readonly bool $canWrite,
        public readonly int $sessionId = 0,
    ) {}

    /** @param array<string, mixed> $user A row of SLiMS's user table. */
    public static function fromUser(PDO $db, array $user, int $sessionId = 0): self
    {
        [$read, $write] = self::privileges($db, (string) ($user['groups'] ?? ''));

        return new self((int) $user['user_id'], (string) $user['username'], (string) ($user['realname'] ?: $user['username']), $read, $write, $sessionId);
    }

    /**
     * Stock Take read and write, from any of the user's groups, as SLiMS's own login merges them.
     *
     * @return array{0: bool, 1: bool}
     */
    public static function privileges(PDO $db, string $groups): array
    {
        $ids = @unserialize($groups, ['allowed_classes' => false]);
        $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)) : [];
        if (!$ids) {
            return [false, false];
        }
        $statement = $db->prepare('SELECT MAX(ga.r) AS r, MAX(ga.w) AS w FROM group_access ga JOIN mst_module m ON m.module_id = ga.module_id WHERE m.module_path = ? AND ga.group_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $statement->execute(array_merge(['stock_take'], $ids));
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return [(bool) ($row['r'] ?? false), (bool) ($row['w'] ?? false)];
    }

    /** "Rina Wulandari" → "RW", for the avatar. */
    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim($this->name)) ?: [];
        $letters = array_map(static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), array_slice(array_filter($words), 0, 2));

        return implode('', $letters) ?: mb_strtoupper(mb_substr($this->username, 0, 2));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'initials' => $this->initials(),
            'can_write' => $this->canWrite,
        ];
    }
}
