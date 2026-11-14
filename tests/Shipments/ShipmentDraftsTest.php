<?php

namespace Modules\Sviat\Ukrposhta\Shipments;

require_once __DIR__ . '/../Support/OrderWorld.php';

use Modules\Sviat\Ukrposhta\Support\OrderWorld;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ChainExtender;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\Money;
use Okay\Core\Settings;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\PurchasesEntity;
use Okay\Entities\VariantsEntity;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartMetrics;
use Okay\Modules\Sviat\Ukrposhta\Cart\UahTotal;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderParcel;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Ukrposhta\Shipments\AmountDue;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDraft;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentConfig;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDrafts;
use PHPUnit\Framework\TestCase;

class ShipmentDraftsTest extends TestCase
{
    use OrderWorld;

    private bool $hasUah = true;
    private ?string $descriptionMode = null;

    protected function setUp(): void
    {
        $this->hasUah = true;
        AmountDueSpy::$received = [];
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ChainExtender::class, 'triggers'))->setValue(null, []);
    }

    private function drafts(): ShipmentDrafts
    {
        $base = $this->entityFactory();
        $purchases = self::createStub(PurchasesEntity::class);
        $purchases->method('noLimit')->willReturnSelf();
        $purchases->method('find')->willReturn([(object)['variant_id' => 5, 'amount' => 2, 'product_name' => 'Чаша для тестової мультиварки, ART-0001', 'variant_name' => '']]);
        // mappedBy() у ядрі final, тож PHPUnit його не підмінить.
        $variants = new class {
            public function mappedBy(string $column): static
            {
                return $this;
            }

            public function noLimit(): static
            {
                return $this;
            }

            public function find(array $filter): array
            {
                return [5 => (object)['id' => 5, 'weight' => 0.75]];
            }
        };
        $currencies = self::createStub(CurrenciesEntity::class);
        $currencies->method('findOne')->willReturnCallback(fn() => $this->hasUah ? (object)['id' => 2] : null);

        $factory = self::createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static fn(string $class) => match ($class) {
            PurchasesEntity::class => $purchases,
            VariantsEntity::class => $variants,
            CurrenciesEntity::class => $currencies,
            default => $base->get($class),
        });
        $money = self::createStub(Money::class);
        $money->method('convert')->willReturnCallback(static fn($amount) => (string)($amount * 40));
        $settings = self::createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn(string $key) => [
            'sviat__ukrposhta__shipment_type' => 'EXPRESS',
            'sviat__ukrposhta__default_weight' => '1',
            'sviat__ukrposhta__box_length' => '40',
            'sviat__ukrposhta__box_width' => '30',
            'sviat__ukrposhta__box_height' => '10',
            ShipmentConfig::PAYER => 'recipient',
            ShipmentConfig::COD_PAYER => 'sender',
            ShipmentConfig::ON_FAIL => 'PROCESS_AS_REFUSAL',
            ShipmentConfig::DESCRIPTION => 'Одяг',
            ShipmentConfig::DESCRIPTION_MODE => $this->descriptionMode,
            ShipmentConfig::SMS => '1',
        ][$key] ?? null);
        $uah = new UahTotal($factory, $money);

        return new ShipmentDrafts(
            $factory,
            $this->lines($factory),
            new OrderDeliveryStore($factory),
            new OrderParcel($factory, $uah),
            $uah,
            new CodPayments($factory),
            $settings,
            new AmountDue(),
        );
    }

    public function testForeignDeliveryRefused(): void
    {
        $this->switchedOrder();

        self::assertSame('sviat__ukrposhta__ship_err_not_own', $this->drafts()->forOrder(77));
    }

    public function testNoSnapshotRefused(): void
    {
        $this->ownOrder();
        $this->snapshot = null;

        self::assertSame('sviat__ukrposhta__ship_err_no_point', $this->drafts()->forOrder(77));
    }

    public function testNoUahRefused(): void
    {
        $this->ownOrder();
        $this->hasUah = false;

        self::assertSame('sviat__ukrposhta__ship_err_no_uah', $this->drafts()->forOrder(77));
    }

    public function testDefaultsFromOrderAndSettings(): void
    {
        $this->ownOrder();
        $this->order->name = 'Олена';
        $this->order->last_name = 'Шевченко';
        $this->order->phone = '+38 067 000-00-01';
        $this->snapshot->office_kind = 'office';

        $draft = $this->drafts()->forOrder(77);

        self::assertInstanceOf(ShipmentDraft::class, $draft);
        self::assertSame('EXPRESS', $draft->type);
        self::assertSame(1500, $draft->weightG);
        self::assertSame([40, 30, 10], [$draft->lengthCm, $draft->widthCm, $draft->heightCm]);
        self::assertTrue($draft->cod);
        self::assertSame(42400.0, $draft->codAmount);
        self::assertSame(42400.0, $draft->declaredPrice);
        self::assertTrue($draft->paidByRecipient);
        self::assertFalse($draft->codPaidByRecipient);
        self::assertSame('PROCESS_AS_REFUSAL', $draft->onFail);
        self::assertSame('Чаша для тестової мультиварки, ART-0001 ×2', $draft->description, 'by default the description lists the products');
        self::assertTrue($draft->sms);
        self::assertSame('Шевченко', $draft->name->last);
        self::assertSame('380670000001', $draft->phone);
        self::assertSame('23222', $draft->officePostcode);
        self::assertSame('office', $draft->officeKind);
        self::assertSame([], $draft->issues());
    }

    public function testDescriptionModes(): void
    {
        $this->ownOrder();

        $this->descriptionMode = 'order';
        self::assertSame('Замовлення №77', $this->drafts()->forOrder(77)->description);
        $this->descriptionMode = 'text';
        self::assertSame('Одяг', $this->drafts()->forOrder(77)->description);
        $this->descriptionMode = 'products';
        self::assertSame('Чаша для тестової мультиварки, ART-0001 ×2', $this->drafts()->forOrder(77)->description);
    }

    public function testCodSwitchedAfterLockerChosenIsRefusedByIssues(): void
    {
        $this->ownOrder();
        $this->order->phone = '0670000001';
        $this->order->name = 'Олена';
        $this->order->last_name = 'Шевченко';
        $this->snapshot->office_kind = 'locker';

        $draft = $this->drafts()->forOrder(77);

        self::assertInstanceOf(ShipmentDraft::class, $draft);
        self::assertContains('sviat__ukrposhta__ship_err_cod_locker', $draft->issues());
        self::assertFalse($draft->paidByRecipient);
    }

    public function testPaidOrderHasNoCod(): void
    {
        $this->ownOrder();
        $this->order->paid = 1;

        $draft = $this->drafts()->forOrder(77);

        self::assertInstanceOf(ShipmentDraft::class, $draft);
        self::assertFalse($draft->cod);
        self::assertSame(0.0, $draft->codAmount);
    }

    public function testAmountDueReducesCod(): void
    {
        $draft = self::built(static fn(float $total): float => 1255.0);

        self::assertTrue($draft->cod);
        self::assertSame(1255.0, $draft->codAmount);
        self::assertSame(2300.0, $draft->declaredPrice, 'declared value stays the goods, not the smaller COD');
    }

    public function testForOrderAsksTheAmountDueChain(): void
    {
        $this->ownOrder();
        $extension = new \stdClass();
        $extension->class = AmountDueSpy::class;
        $extension->method = 'onPickup';
        (new \ReflectionProperty(ChainExtender::class, 'triggers'))->setValue(null, [AmountDue::class . '::onPickup' => [$extension]]);

        $draft = $this->drafts()->forOrder(77);

        self::assertInstanceOf(ShipmentDraft::class, $draft);
        self::assertSame(1000.0, $draft->codAmount);
        self::assertSame(40000.0, $draft->declaredPrice, 'declared value falls back to the goods');
        self::assertSame(77, (int)AmountDueSpy::$received[1]->id);
        self::assertSame(42400.0, AmountDueSpy::$received[2]);
    }

    public function testZeroDueDropsCod(): void
    {
        $draft = self::built(static fn(float $total): float => 0.0);

        self::assertFalse($draft->cod);
        self::assertSame(0.0, $draft->codAmount);
        self::assertArrayNotHasKey('postPay', $draft->toRequest('sender', 'recipient', 1, 77));
    }

    public function testNegativeDueDropsCod(): void
    {
        self::assertFalse(self::built(static fn(float $total): float => -5.0)->cod);
    }

    public function testWithoutAmountDueCodIsOrderTotal(): void
    {
        self::assertSame(2455.0, self::built(null)->codAmount);
    }

    /** Замовлення 2455 грн з доставкою, товари 2300 грн, оплата післяплатою. */
    private static function built(?\Closure $amountDue): ShipmentDraft
    {
        return ShipmentDrafts::build(
            (object)['id' => 77, 'paid' => 0, 'name' => 'Олена', 'last_name' => 'Шевченко', 'phone' => '0670000001'],
            (object)['office_postcode' => '23222', 'office_kind' => 'office'],
            ShipmentConfig::fromSettings(static fn(string $key) => null),
            new CartMetrics(1.0, false, 2300.0, 2300.0),
            2455.0,
            true,
            $amountDue,
        );
    }
}

/** Модуль передоплати в ланцюжку AmountDue::onPickup: аванс покрив частину замовлення. */
class AmountDueSpy implements ExtensionInterface
{
    public static array $received = [];

    public function onPickup(...$args): float
    {
        self::$received = $args;

        return 1000.0;
    }
}
