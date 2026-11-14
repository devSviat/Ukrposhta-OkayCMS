<?php

namespace Okay\Modules\Sviat\Ukrposhta\Cart;

use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Modules\Sviat\Ukrposhta\Entities\OfficeEntity;
use Okay\Modules\Sviat\Ukrposhta\Groups\GroupsStore;

/**
 * Пункти одного міста для кошика: вкладки груп із кількістю й одна сторінка обраної групи.
 * Місто тягне всі свої пункти одним легким запитом: у найбільшому їх сотні, тож пошук і порядок рахуються тут.
 */
final class OfficeList
{
    public const int PAGE = 100;
    private const int MAX_SEARCH = 64;
    private const int MAX_WORDS = 5;
    private const int MIN_POSTCODE_DIGITS = 3;

    public function __construct(
        private readonly Database $db,
        private readonly QueryFactory $queryFactory,
        private readonly GroupsStore $groups,
    ) {
    }

    /** @return array{groups: list<array{id:int, name:string, count:int}>, group: ?int, points: list<array{id:int, postcode:string, kind:string, title:string, address:string, disabled:bool, reason:?string}>} */
    public function forCity(int $cityId, CartMetrics $cart, bool $cod, ?int $groupId, string $query): array
    {
        $select = $this->queryFactory->newSelect()
            ->cols(['o.id', 'o.postcode', 'o.kind', 'o.name_ua', 'o.address_ua'])
            ->from(OfficeEntity::getTable() . ' AS o')
            ->where('o.city_id = :city')
            ->bindValue('city', $cityId)
            ->orderBy(['o.postcode', 'o.id']);
        $offices = $this->db->query($select) === false ? [] : $this->db->results();

        return self::build($offices, $this->groups->all(), $cart, $cod, $groupId, $query);
    }

    /**
     * @param list<object> $offices id, postcode, kind, name_ua, address_ua
     * @param list<object> $groupRows рядки GroupEntity
     * @return array{groups: list<array{id:int, name:string, count:int}>, group: ?int, points: list<array{id:int, postcode:string, kind:string, title:string, address:string, disabled:bool, reason:?string}>}
     */
    public static function build(array $offices, array $groupRows, CartMetrics $cart, bool $cod, ?int $groupId, string $query): array
    {
        $counts = [];
        foreach ($offices as $office) {
            $counts[$office->kind] = ($counts[$office->kind] ?? 0) + 1;
        }
        $groups = self::groupCounts($groupRows, $counts);
        $ids = array_column($groups, 'id');
        $chosen = in_array($groupId, $ids, true) ? $groupId : ($ids[0] ?? null);
        if ($chosen === null) {
            return ['groups' => $groups, 'group' => null, 'points' => []];
        }

        $kinds = self::kindsOf($groupRows, $chosen);
        $words = self::searchWords($query);
        $ranked = [];
        foreach ($offices as $office) {
            if (!in_array($office->kind, $kinds, true)) {
                continue;
            }
            $rank = $words === null ? 0 : self::matchRank($office, $words);
            if ($rank === null) {
                continue;
            }
            $problems = OfficeFit::problems($cart, $cod, $office);
            $ranked[$rank][$problems === [] ? 0 : 1][] = [$office, $problems];
        }
        ksort($ranked);

        $points = [];
        foreach ($ranked as $parts) {
            foreach ([...($parts[0] ?? []), ...($parts[1] ?? [])] as [$office, $problems]) {
                $points[] = [
                    'id' => (int)$office->id,
                    'postcode' => (string)$office->postcode,
                    'kind' => (string)$office->kind,
                    'title' => (string)$office->name_ua,
                    'address' => (string)($office->address_ua ?? ''),
                    'disabled' => $problems !== [],
                    'reason' => $problems[0]->value ?? null,
                ];
            }
        }

        return ['groups' => $groups, 'group' => $chosen, 'points' => array_slice($points, 0, self::PAGE)];
    }

    /**
     * @param list<object> $groupRows рядки GroupEntity
     * @param array<string, int> $counts пункти за видами
     * @return list<array{id:int, name:string, count:int}> групи, які покупець може обрати, по порядку
     */
    public static function groupCounts(array $groupRows, array $counts): array
    {
        $groups = [];
        foreach ($groupRows as $group) {
            $count = 0;
            foreach (self::kindsOf([$group], (int)$group->id) as $kind) {
                $count += $counts[$kind] ?? 0;
            }
            if ($count > 0) {
                $groups[] = ['id' => (int)$group->id, 'name' => (string)$group->name, 'count' => $count];
            }
        }

        return $groups;
    }

    /** @return list<string> види, показані в увімкненій групі */
    public static function kindsOf(array $groupRows, ?int $groupId): array
    {
        foreach ($groupRows as $group) {
            if ((int)$group->id !== $groupId || !(int)$group->enabled) {
                continue;
            }
            $kinds = json_decode((string)$group->kinds, true);

            return is_array($kinds) ? array_values(array_filter($kinds, is_string(...))) : [];
        }

        return [];
    }

    /**
     * Введений пошук як слова, щонайбільше 5. "№" і коми лише розділяють: "№ 13" — це пункт 13.
     * @return ?non-empty-list<string>
     */
    public static function searchWords(string $raw): ?array
    {
        $raw = str_replace(['’', 'ʼ', '`', '‘'], "'", $raw);
        $text = mb_substr(trim(preg_replace('/[\s,№#]+/u', ' ', $raw) ?? ''), 0, self::MAX_SEARCH);
        $words = array_slice(array_values(array_filter(explode(' ', $text), static fn(string $w): bool => $w !== '')), 0, self::MAX_WORDS);

        return $words === [] ? null : $words;
    }

    /**
     * Кожне слово має знайтися в індексі, назві чи адресі ("Соборна 1"). Порядок: точний індекс,
     * індекси з цього початку, решта. Null — пункт не підходить.
     *
     * @param non-empty-list<string> $words
     */
    private static function matchRank(object $office, array $words): ?int
    {
        $postcode = (string)$office->postcode;
        $haystack = (string)$office->name_ua . ' ' . (string)($office->address_ua ?? '');
        foreach ($words as $word) {
            if (!self::wordMatches($word, $postcode, $haystack)) {
                return null;
            }
        }

        return match (true) {
            $postcode === $words[0] => 0,
            str_starts_with($postcode, $words[0]) && ctype_digit($words[0]) => 1,
            default => 2,
        };
    }

    /**
     * Число — це або початок індексу (від трьох цифр), або номер у назві чи будинок в адресі з початку числа:
     * "2" не повинне знаходити індекс 21001 і номер 12.
     */
    private static function wordMatches(string $word, string $postcode, string $haystack): bool
    {
        if (!ctype_digit($word)) {
            return mb_stripos($haystack, $word) !== false;
        }

        return (strlen($word) >= self::MIN_POSTCODE_DIGITS && str_starts_with($postcode, $word))
            || preg_match('/(?<!\d)' . $word . '/', $haystack) === 1;
    }
}
