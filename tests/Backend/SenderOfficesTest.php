<?php

namespace Modules\Sviat\Ukrposhta\Backend;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderOffices;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderSettings;
use Okay\Modules\Sviat\Ukrposhta\Entities\OfficeEntity;
use PHPUnit\Framework\TestCase;

/** Відправник здає посилки у відділенні: індекс поштомата чи PUDO, надісланий вручну, не проходить. */
class SenderOfficesTest extends TestCase
{
    /** @var list<array{postcode:string, kind:string}> */
    private const array ROWS = [
        ['postcode' => '01001', 'kind' => 'office'],
        ['postcode' => '01999', 'kind' => 'locker'],
        ['postcode' => '02999', 'kind' => 'pudo'],
    ];

    private function offices(): SenderOffices
    {
        $entity = $this->createStub(OfficeEntity::class);
        $entity->method('cols')->willReturnSelf();
        $entity->method('findOne')->willReturnCallback(static function (array $filter) {
            foreach (self::ROWS as $row) {
                if (array_intersect_key($row, $filter) == array_intersect_key($filter, $row)) {
                    return '1';
                }
            }

            return false;
        });
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity);

        return new SenderOffices($factory);
    }

    public function testOnlyPostOfficesCount(): void
    {
        $offices = $this->offices();

        self::assertTrue($offices->exists('01001'));
        self::assertFalse($offices->exists('01999'));
        self::assertFalse($offices->exists('02999'));
        self::assertFalse($offices->exists('99999'));
    }

    public function testLockerPostcodeIsUnknownForTheSender(): void
    {
        $settings = $this->createStub(Settings::class);
        $sender = new SenderSettings($settings, $this->offices());
        $post = [
            'sender_type' => 'COMPANY',
            'sender_name' => 'ТОВ «Приклад»',
            'sender_edrpou' => '12345678',
            'sender_phone' => '+380670000001',
            'sender_iban' => 'UA213052990000000026001234567',
        ];

        self::assertSame([], $sender->validate($post + ['sender_postcode' => '01001']));
        self::assertSame(
            ['sender_postcode' => 'sviat__ukrposhta__sender_error_postcode_unknown'],
            $sender->validate($post + ['sender_postcode' => '01999']),
        );
    }
}
