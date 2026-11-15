<?php

namespace Modules\Sviat\Ukrposhta\Extenders;

use Okay\Core\Cart;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Modules\Module;
use Okay\Core\Money;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\DeliveriesEntity;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartSelectionValidator;
use Okay\Modules\Sviat\Ukrposhta\Cart\DictionaryReady;
use Okay\Modules\Sviat\Ukrposhta\Cart\UahTotal;
use Okay\Modules\Sviat\Ukrposhta\Entities\CityEntity;
use Okay\Modules\Sviat\Ukrposhta\Extenders\FrontExtender;
use Okay\Modules\Sviat\Ukrposhta\Groups\GroupsStore;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;
use PHPUnit\Framework\TestCase;

/**
 * Ланка ValidateHelper::getCartValidateError. Ланка контактів раніше в ланцюжку може зняти свою помилку
 * як null чи як '' (обидва дозволяє контракт ядра), і в обох випадках вибір пункту має перевірятися.
 */
class CartValidateChainTest extends TestCase
{
    private const int MODULE = 6;
    private const int OWN_DELIVERY = 5;

    public function testClearedAsEmptyStringStillValidatesThePoint(): void
    {
        self::assertSame('sviat__ukrposhta__err_city', $this->front()->getCartValidateError('', $this->order()));
    }

    public function testControlNullValidatesThePoint(): void
    {
        self::assertSame('sviat__ukrposhta__err_city', $this->front()->getCartValidateError(null, $this->order()));
    }

    public function testControlEarlierErrorIsKept(): void
    {
        self::assertSame('empty_phone', $this->front()->getCartValidateError('empty_phone', $this->order()));
    }

    private function order(): object
    {
        return (object)['id' => null, 'delivery_id' => self::OWN_DELIVERY, 'payment_method_id' => 0];
    }

    private static function blank(string $class): object
    {
        return new \ReflectionClass($class)->newInstanceWithoutConstructor();
    }

    private function front(): FrontExtender
    {
        $deliveries = $this->createStub(DeliveriesEntity::class);
        $deliveries->method('get')->willReturn((object)['id' => self::OWN_DELIVERY, 'module_id' => self::MODULE, 'settings' => '']);
        $cities = $this->createStub(CityEntity::class);
        $cities->method('get')->willReturn(false);
        $currencies = $this->createStub(CurrenciesEntity::class);
        $currencies->method('findOne')->willReturn(false);
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static fn(string $class) => match ($class) {
            DeliveriesEntity::class => $deliveries,
            CityEntity::class => $cities,
            CurrenciesEntity::class => $currencies,
        });
        $module = $this->createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(self::MODULE);
        $translations = $this->createStub(FrontTranslations::class);
        $translations->method('getTranslation')->willReturnArgument(0);

        $dictionary = self::blank(DictionaryReady::class);
        new \ReflectionProperty(DictionaryReady::class, 'ready')->setValue($dictionary, true);
        $validator = new CartSelectionValidator($factory, $module, new GroupsStore($factory), new CodPayments($factory), $dictionary);

        $front = self::blank(FrontExtender::class);
        foreach ([
            'module' => $module,
            'translations' => $translations,
            'validator' => $validator,
            'cart' => $this->createStub(Cart::class),
            'uahTotal' => new UahTotal($factory, $this->createStub(Money::class)),
        ] as $property => $value) {
            new \ReflectionProperty(FrontExtender::class, $property)->setValue($front, $value);
        }

        return $front;
    }
}
