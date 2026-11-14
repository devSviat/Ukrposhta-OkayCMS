<?php

namespace Okay\Modules\Sviat\Ukrposhta\Dictionaries;

use Okay\Modules\Sviat\Ukrposhta\Api\ApiErrorKind;
use Okay\Modules\Sviat\Ukrposhta\Api\ClassifierClient;
use Okay\Modules\Sviat\Ukrposhta\Api\UkrposhtaApiException;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Rows\NameNormalizer;
use Okay\Modules\Sviat\Ukrposhta\Storage\StateStorage;

/**
 * Обхід країни по областях: класифікатор віддає пункти лише з `region_id` і не має запиту «усі області».
 * Перелік областей сталий, тож id береться пошуком за назвою й кешується в стані.
 */
final class RegionSweep
{
    /** 24 області й Київ. АР Крим і Севастополь заблоковані в класифікаторі й не обходяться. */
    public const array REGIONS = [
        'Вінницька', 'Волинська', 'Дніпропетровська', 'Донецька', 'Житомирська', 'Закарпатська',
        'Запорізька', 'Івано-Франківська', 'Київська', 'Кіровоградська', 'Луганська', 'Львівська',
        'Миколаївська', 'Одеська', 'Полтавська', 'Рівненська', 'Сумська', 'Тернопільська',
        'Харківська', 'Херсонська', 'Хмельницька', 'Черкаська', 'Чернівецька', 'Чернігівська',
        'м. Київ',
    ];

    private const string STATE = 'region_ids';

    /** @var list<string> */
    private array $unresolved = [];

    /** @param list<string> $names */
    public function __construct(
        private readonly ClassifierClient $classifier,
        private readonly StateStorage $state,
        private readonly array $names = self::REGIONS,
    ) {
    }

    /**
     * Назва, яку класифікатор не впізнав, не кешується (наступний запуск спитає ще раз) і потрапляє
     * в unresolved(): тихо пропущена область лишилася б із застарілими пунктами.
     *
     * @return list<array{id:int, name:string}>
     */
    public function regions(): array
    {
        $cached = json_decode((string)$this->state->get(self::STATE), true);
        $ids = is_array($cached) ? array_filter($cached, is_int(...)) : [];
        $this->unresolved = [];

        $regions = [];
        $changed = false;
        try {
            foreach ($this->names as $name) {
                if (!isset($ids[$name])) {
                    $id = $this->resolveOrNull($name);
                    if ($id === null) {
                        $this->unresolved[] = $name;
                        continue;
                    }
                    $ids[$name] = $id;
                    $changed = true;
                }
                $regions[] = ['id' => $ids[$name], 'name' => $name];
            }
        } finally {
            // Зберігаємо вже знайдені id навіть тоді, коли наступна назва зупинила запуск.
            if ($changed) {
                $this->state->set(self::STATE, json_encode($ids, JSON_UNESCAPED_UNICODE));
            }
        }

        return $regions;
    }

    /** @return list<string> назви, які останній regions() не зміг перетворити на id */
    public function unresolved(): array
    {
        return $this->unresolved;
    }

    /** @return list<array<string, string>> */
    public function offices(int $regionId): array
    {
        return $this->classifier->rows('get_postoffices_by_city_id', ['region_id' => $regionId]);
    }

    /** Збій пошуку однієї назви не зупиняє обхід інших областей; невірний bearer зламав би кожну назву. */
    private function resolveOrNull(string $name): ?int
    {
        try {
            return $this->resolve($name);
        } catch (UkrposhtaApiException $e) {
            if (in_array($e->kind, [ApiErrorKind::BadBearer, ApiErrorKind::NotConfigured], true)) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * Пошук за назвою частковий: «Київ» знаходить і місто, і Київську область. Тому питаємо без «м.»
     * і беремо лише запис, чия назва збігається повністю: схожа область під чужою назвою дала б
     * подвійний обхід однієї області й жодного — потрібної.
     */
    private function resolve(string $name): ?int
    {
        $query = preg_replace('/^м\.\s*/u', '', $name) ?? $name;
        foreach ($this->classifier->rows('get_regions_by_region_ua', ['region_name' => $query]) as $row) {
            if (self::comparable((string)($row['REGION_UA'] ?? '')) === self::comparable($query)) {
                return self::id($row);
            }
        }

        return null;
    }

    private static function comparable(string $name): string
    {
        return preg_replace('/^м\.\s*/u', '', NameNormalizer::normalize($name)) ?? $name;
    }

    private static function id(array $row): ?int
    {
        $id = (int)($row['REGION_ID'] ?? 0);

        return $id > 0 ? $id : null;
    }
}
