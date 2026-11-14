<?php

namespace Okay\Modules\Sviat\Ukrposhta\Quote;

use Okay\Core\Security\ClientIp;

/**
 * Загальний для магазину ліміт нових розрахунків в Укрпошті. Обмеження частоти на сесію не зупинить
 * клієнта, який не надсилає cookie (щоразу нова сесія); цей ліміт спільний для всіх.
 */
final class QuoteBudget
{
    public const int LIMIT = 300;
    public const int WINDOW = 600;
    /** Частка одного IP у вікні: не більше третини спільного ліміту. */
    public const int VISITOR_LIMIT = 100;

    /**
     * @param ?string $visitor відбиток відвідувача (хеш IP): без нього рахується лише спільний ліміт
     * @return array{0: bool, 1: string} чи дозволено, і стан, який треба зберегти
     */
    public static function take(?string $state, int $now, int $limit = self::LIMIT, ?string $visitor = null, int $visitorLimit = self::VISITOR_LIMIT): array
    {
        $data = json_decode((string)$state, true);
        $start = is_array($data) && is_int($data['start'] ?? null) ? $data['start'] : null;
        $count = is_array($data) && is_int($data['count'] ?? null) ? $data['count'] : 0;
        $visitors = is_array($data) && is_array($data['visitors'] ?? null) ? $data['visitors'] : [];
        if ($start === null || $now - $start >= self::WINDOW) {
            $start = $now;
            $count = 0;
            $visitors = [];
        }
        $own = $visitor !== null && is_int($visitors[$visitor] ?? null) ? $visitors[$visitor] : 0;
        $allowed = $count < $limit && ($visitor === null || $own < $visitorLimit);
        if ($allowed) {
            $count++;
            if ($visitor !== null) {
                $visitors[$visitor] = $own + 1;
            }
        }
        $next = ['start' => $start, 'count' => $count];

        return [$allowed, json_encode($visitors === [] ? $next : $next + ['visitors' => $visitors])];
    }

    /**
     * Частку спільного ліміту рахуємо кожному клієнтові за IP: cookie сесії підробляється, тож ніщо,
     * що надсилає сам клієнт, не виводить його з-під цього ліміту. Один IP не бере більше третини
     * спільного ліміту. IPv6 рахується за /64, як у ClientIp. IP зберігається лише хешем.
     */
    public static function visitor(?string $ip): ?string
    {
        return $ip === null || $ip === '' ? null : substr(sha1('sviat_up|' . ClientIp::bucket($ip)), 0, 16);
    }
}
