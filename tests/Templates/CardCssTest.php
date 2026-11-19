<?php

namespace Modules\Sviat\Ukrposhta\Templates;

use PHPUnit\Framework\TestCase;

/** Картка в замовленні виглядає як блоки ядра й картки інших перевізників. */
class CardCssTest extends TestCase
{
    private static function css(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta/Backend/design/css/shipment.css');
    }

    /** Від заголовка до картки — 15px, як у блоку ядра «Параметри замовлення» (нижній відступ .heading_box). */
    public function testCardHeadingGapMatchesCore(): void
    {
        self::assertStringContainsString('.up-ship--card .up-ship__head { margin-bottom: 15px; }', self::css());
    }

    /**
     * Знаки всіх перевізників у картці заввишки з цифру номера (Inter 600 26px: 19px) і стоять на його базовій
     * лінії; проміжок до номера 8px. viewBox обрізано по фарбі, тож рамка елемента й є знаком.
     */
    public function testCardLogoMatchesDigitHeight(): void
    {
        $tpl = (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/Ukrposhta/Backend/design/html/shipment.tpl');

        self::assertStringContainsString('<svg class="up-card__logo" width="20.07" height="19" viewBox="79 87 356 337" aria-hidden="true">', $tpl);
        self::assertStringContainsString('.up-card__logo { flex: none; display: block; width: auto; height: 19px; align-self: baseline; }', self::css());
        self::assertStringContainsString('.up-card__head .up-card__number { align-self: baseline; padding-left: 0; }', self::css());
        // .boxed svg ядра дає 3px з обох боків; два класи переважують його специфічність. -4px: 8px до номера замість gap 12px.
        self::assertStringContainsString('.up-card .up-card__logo { margin: 0 -4px 0 0; }', self::css());
        self::assertStringContainsString('.up-card__head { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; }', self::css());
    }

    /** Знак у рядку списку замовлень: 14px фарби, як у інших перевізників; проміжок до номера — gap 5px рядка. */
    public function testListLogoInkIs14pxTall(): void
    {
        self::assertStringContainsString('.up-list-status__logo { flex: none; display: block; width: auto; height: 14px; }', self::css());
        // Рядок списку теж у .boxed: без цього svg отримав би 3px з обох боків від ядра.
        self::assertStringContainsString('.up-list-status .up-list-status__logo { margin: 0; }', self::css());
        self::assertStringContainsString('.up-list-status { margin-top: 4px; display: flex; flex-wrap: wrap; align-items: center; gap: 5px;', self::css());
    }

    /** Значок посилання й бейдж статусу центровані на цифрах номера, а не на його рядку: у Inter 26px/1.2 центр цифр на 1.35px вище. */
    public function testRowItemsCentredOnDigits(): void
    {
        self::assertStringContainsString('.up-card__head .up-ship__badge { transform: translateY(-1.35px); }', self::css());
    }
}
