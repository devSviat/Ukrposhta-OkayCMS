<?php

namespace Okay\Modules\Sviat\Ukrposhta\Init;

use Okay\Modules\Sviat\Ukrposhta\Orders\DeliveryFilterHub;
use Okay\Admin\Helpers\BackendDeliveriesHelper;
use Okay\Admin\Helpers\BackendOrdersHelper;
use Okay\Admin\Helpers\BackendPaymentsHelper;
use Okay\Admin\Requests\BackendPaymentsRequest;
use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\Languages;
use Okay\Core\Modules\AbstractInit;
use Okay\Core\Modules\EntityField;
use Okay\Core\QueryFactory;
use Okay\Core\Scheduler\Schedule;
use Okay\Core\ServiceLocator;
use Okay\Core\Settings;
use Okay\Entities\LanguagesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Entities\PaymentsEntity;
use Okay\Helpers\DeliveriesHelper;
use Okay\Helpers\NotifyHelper;
use Okay\Helpers\OrdersHelper;
use Okay\Helpers\ValidateHelper;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\Ukrposhta\Entities\CityEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\GroupEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\OfficeEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\Ukrposhta\Entities\ShipmentEntity;
use Okay\Modules\Sviat\Ukrposhta\Extenders\BackendExtender;
use Okay\Modules\Sviat\Ukrposhta\Extenders\FrontExtender;
use Okay\Modules\Sviat\Ukrposhta\Groups\DefaultGroups;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderRowsCleanup;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrdersEntityFilters;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentTracking;
use Okay\Modules\Sviat\Ukrposhta\Storage\StateEntity;

class Init extends AbstractInit
{
    public function install()
    {
        $this->setBackendMainController('UkrposhtaSettingsAdmin');
        $this->setModuleType(MODULE_TYPE_DELIVERY);

        $this->createMissingTables(self::tables());
        $this->addDefaultGroups();
        $this->addCoreFields();
        // Таблиця могла лишитися від видаленого модуля старішої версії: createMissingTables() її не чіпає.
        $this->addLateShipmentColumns();
    }

    public function update_1_3_0(): void
    {
        $this->addLateShipmentColumns();
    }

    /** Повторний запуск безпечний: migrateEntityField() додає лише відсутню колонку. */
    private function addLateShipmentColumns(): void
    {
        $serviceLocator = ServiceLocator::getInstance();
        /** @var Database $db */
        $db = $serviceLocator->getService(Database::class);
        $queryFactory = $serviceLocator->getService(QueryFactory::class);
        self::addLateShipmentColumnsWith(
            fn(EntityField $field) => $this->migrateEntityField(ShipmentEntity::class, $field),
            static function () use ($db, $queryFactory): array {
                $db->query($queryFactory->newSqlQuery()->setStatement('SHOW COLUMNS FROM `' . ShipmentEntity::getTable() . '`'));

                return array_map(static fn(object $column): string => (string)$column->Field, $db->results() ?: []);
            },
            static fn() => $serviceLocator->getService(Settings::class)->set(ShipmentEntity::LATE_FIELDS_SETTING, '1'),
        );
    }

    /**
     * Ядро ковтає помилку ALTER, тож прапорець, з яким entity починає читати колонки, ставиться лише після того,
     * як таблиця їх справді показала: інакше SELECT упав би, і відправлення зникли б з карток.
     *
     * @param \Closure(EntityField): void $migrate
     * @param \Closure(): list<string> $columns назви колонок таблиці відправлень
     * @param \Closure(): void $markReady
     * @return bool false — колонок немає, прапорець не поставлено
     */
    public static function addLateShipmentColumnsWith(\Closure $migrate, \Closure $columns, \Closure $markReady): bool
    {
        foreach (self::lateShipmentColumns() as $field) {
            $migrate($field);
        }
        if (array_diff(ShipmentEntity::LATE_FIELDS, $columns()) !== []) {
            return false;
        }
        $markReady();

        return true;
    }

    /** @return list<EntityField> */
    public static function lateShipmentColumns(): array
    {
        return [
            new EntityField('details')->setTypeText()->setNullable(),
            new EntityField('history')->setTypeMediumText()->setNullable(),
        ];
    }

    /**
     * Поля, які модуль тримає в таблицях ядра під власними назвами. init() реєструє їх без
     * перевірки: їх створює install(), тож пізніша версія з новим полем не повинна реєструвати його до свого оновлення.
     */
    private function addCoreFields(): void
    {
        $this->migrateEntityField(PaymentsEntity::class, new EntityField(CodPayments::FIELD)->setTypeTinyInt(1, false)->setDefault(0));
    }

    public function init()
    {
        $this->registerEntityField(PaymentsEntity::class, CodPayments::FIELD);
        // Налаштування ядро вже читає на кожному запиті: перевірка не коштує окремого запиту до бази.
        if (ServiceLocator::getInstance()->getService(Settings::class)->get(ShipmentEntity::LATE_FIELDS_SETTING)) {
            foreach (ShipmentEntity::LATE_FIELDS as $field) {
                $this->registerEntityField(ShipmentEntity::class, $field);
            }
        }

        $this->addFrontBlock('front_cart_delivery', 'cart_delivery.tpl');
        $this->addFrontBlock('front_cart_payment', 'cart_cod_fee.tpl');
        $this->addFrontBlock('front_scripts_after_validate', 'cart_validation.tpl');
        $this->registerQueueExtension(
            [DeliveriesHelper::class, 'getCartDeliveriesList'],
            [FrontExtender::class, 'getCartDeliveriesList']
        );
        $this->registerChainExtension(
            [DeliveriesHelper::class, 'getCartDeliveriesList'],
            [FrontExtender::class, 'applyQuotes']
        );
        $this->registerChainExtension(
            [DeliveriesHelper::class, 'prepareDeliveryPriceInfo'],
            [FrontExtender::class, 'orderPrice']
        );
        $this->registerChainExtension(
            [ValidateHelper::class, 'getCartValidateError'],
            [FrontExtender::class, 'getCartValidateError']
        );
        $this->registerQueueExtension(
            [OrdersHelper::class, 'finalCreateOrderProcedure'],
            [FrontExtender::class, 'saveOrderDelivery']
        );
        foreach ([[OrdersHelper::class, 'getOrderPaymentMethodsList'], [NotifyHelper::class, 'finalEmailOrderUser'], [NotifyHelper::class, 'finalEmailOrderAdmin']] as $hook) {
            $this->registerQueueExtension($hook, [FrontExtender::class, 'assignOrderDelivery']);
        }
        $this->addFrontBlock('front_email_order_user_contact_info', 'email_delivery.tpl');
        $this->addBackendBlock('email_order_admin_contact_info', 'email_delivery.tpl');

        $this->registerChainExtension([BackendPaymentsRequest::class, 'postPayment'], [BackendExtender::class, 'paymentPost']);
        $this->registerQueueExtension([BackendPaymentsHelper::class, 'getPaymentMethod'], [BackendExtender::class, 'assignPaymentCod']);
        $this->addBackendBlock('payment_switch_checkboxes', 'payment_cod.tpl');
        $this->registerQueueExtension([BackendOrdersHelper::class, 'findOrder'], [BackendExtender::class, 'assignOrderDelivery']);
        $this->registerQueueExtension([BackendOrdersHelper::class, 'delete'], [BackendExtender::class, 'deleteOrders']);
        // Ядро виводить блоки у зворотному порядку реєстрації: форма відправлення стає під пунктом видачі, як у NP.
        $this->addBackendBlock('order_contact', 'shipment_side.tpl');
        $this->addBackendBlock('order_contact', 'order_delivery.tpl');
        $this->addBackendBlock('order_custom_block', 'shipment_main.tpl');
        $this->registerChainExtension([BackendOrdersHelper::class, 'buildFilter'], [BackendExtender::class, 'listFilter']);
        $this->registerQueueExtension([BackendOrdersHelper::class, 'findOrders'], [BackendExtender::class, 'listOrders']);
        foreach (DeliveryFilterHub::hooks(DeliveryFilterHub::HELPER) as [$expandable, $extension]) {
            $this->registerChainExtension($expandable, $extension);
        }
        $this->registerEntityFilter(OrdersEntity::class, 'up_without_shipment', OrdersEntityFilters::class, 'filter__up_without_shipment');
        $this->registerEntityFilter(OrdersEntity::class, 'up_status_groups', OrdersEntityFilters::class, 'filter__up_status_groups');
        $this->addBackendBlock('orders_list_name', 'orders_status.tpl');
        $this->registerBackendController('OrderPointAdmin');
        $this->addBackendControllerPermission('OrderPointAdmin', 'orders');
        $this->registerBackendController('ShipmentAdmin');
        $this->addBackendControllerPermission('ShipmentAdmin', 'orders');
        $this->registerBackendController('UkrposhtaSettingsAdmin');
        $this->addBackendControllerPermission('UkrposhtaSettingsAdmin', 'delivery');
        $this->registerQueueExtension([BackendDeliveriesHelper::class, 'getDelivery'], [BackendExtender::class, 'deliveryWarning']);
        $this->addBackendBlock('delivery_price_block', 'delivery_free_warning.tpl');

        // Кожні 5 хвилин — лише дешева перевірка; runner сам вирішує, чи пора працювати,
        // тож кнопку в адмінці обслуговують за кілька хвилин, а тижневому оновленню не потрібен свій слот.
        $this->registerSchedule(
            new Schedule([DictionaryRunner::class, 'tick'])
                ->name('Ukrposhta: dictionaries')
                ->time('*/5 * * * *')
                ->overlap(false)
                ->timeout(DictionaryRunner::TASK_TIMEOUT)
        );
        $this->registerSchedule(
            new Schedule([ShipmentTracking::class, 'tick'])
                ->name('Ukrposhta: tracking')
                ->time('*/15 * * * *')
                ->overlap(false)
                ->timeout(600)
        );
        // Дешева перевірка кожні 15 хвилин; сама робота виконується раз на день (див. OrderRowsCleanup::tick).
        $this->registerSchedule(
            new Schedule([OrderRowsCleanup::class, 'tick'])
                ->name('Ukrposhta: rows of deleted orders')
                ->time('*/15 * * * *')
                ->overlap(false)
                ->timeout(300)
        );
    }

    /** @return array<class-string, list<EntityField>> */
    public static function tables(): array
    {
        return [
            StateEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
                new EntityField('name')->setTypeVarchar(64, false)->setIndexUnique(),
                new EntityField('value')->setTypeMediumText()->setNullable(),
                new EntityField('updated_at')->setTypeDatetime(false),
            ],
        ] + self::dictionaryTables() + self::orderTables();
    }

    /** @return array<class-string, list<EntityField>> що покупець обрав для замовлення, з копіями назв, і відправлення замовлень */
    public static function orderTables(): array
    {
        return [
            OrderDeliveryEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
                new EntityField('order_id')->setTypeInt(11, false)->setIndexUnique(),
                new EntityField('delivery_id')->setTypeInt(11, false),
                new EntityField('city_id')->setTypeInt(11, false),
                new EntityField('city_name')->setTypeVarchar(255, false),
                new EntityField('office_id')->setTypeInt(11, false),
                new EntityField('office_postcode')->setTypeVarchar(5, false),
                new EntityField('office_kind')->setTypeVarchar(16, false),
                new EntityField('office_title')->setTypeVarchar(255, false),
                new EntityField('office_address')->setTypeVarchar(255),
                new EntityField('price')->setTypeDecimal('10,2'),
                new EntityField('price_source')->setTypeVarchar(16),
                new EntityField('cod')->setTypeTinyInt(1, false)->setDefault(0),
                new EntityField('cod_fee')->setTypeDecimal('10,2'),
                new EntityField('created_at')->setTypeDatetime(false),
            ],
            ShipmentEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
                new EntityField('order_id')->setTypeInt(11, false)->setIndexUnique(),
                new EntityField('uuid')->setTypeVarchar(36),
                new EntityField('sender_uuid')->setTypeVarchar(36),
                new EntityField('barcode')->setTypeVarchar(20)->setIndexUnique(),
                new EntityField('state')->setTypeVarchar(16, false),
                new EntityField('status')->setTypeVarchar(32),
                new EntityField('status_group')->setTypeVarchar(16)->setIndex(),
                new EntityField('event_code')->setTypeInt(11),
                new EntityField('event_name')->setTypeVarchar(255),
                new EntityField('event_at')->setTypeDatetime(),
                new EntityField('event_reason')->setTypeInt(11),
                new EntityField('delivery_price')->setTypeDecimal('10,2'),
                new EntityField('cod_amount')->setTypeDecimal('10,2'),
                new EntityField('cod_fee')->setTypeDecimal('10,2'),
                new EntityField('paid_by_recipient')->setTypeTinyInt(1),
                new EntityField('created_at')->setTypeDatetime(false),
                new EntityField('checked_at')->setTypeDatetime(),
                new EntityField('lifecycle_checked_at')->setTypeDatetime(),
                new EntityField('reserved_at')->setTypeDatetime(),
                ...self::lateShipmentColumns(),
            ],
        ];
    }

    /** @return array<class-string, list<EntityField>> пункти, міста з пунктами та групи пунктів для кошика */
    public static function dictionaryTables(): array
    {
        $loaded = static fn(): array => [
            new EntityField('row_hash')->setTypeVarchar(40, false),
            new EntityField('seen_run')->setTypeInt(11, false)->setDefault(0),
            new EntityField('updated_at')->setTypeDatetime(false),
        ];

        return [
            CityEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false),
                new EntityField('region_id')->setTypeInt(11, false)->setIndex(),
                new EntityField('name_ua')->setTypeVarchar(255, false),
                new EntityField('type_ua')->setTypeVarchar(64),
                new EntityField('district_ua')->setTypeVarchar(255),
                new EntityField('region_ua')->setTypeVarchar(255),
                new EntityField('search_text')->setTypeText(),
                new EntityField('points')->setTypeInt(11, false)->setDefault(0),
                ...$loaded(),
            ],
            OfficeEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false),
                new EntityField('region_id')->setTypeInt(11, false)->setIndex(),
                new EntityField('city_id')->setTypeInt(11, false)->setIndex(),
                new EntityField('postcode')->setTypeVarchar(5, false)->setIndex(),
                new EntityField('postindex')->setTypeVarchar(10),
                new EntityField('kind')->setTypeVarchar(16, false),
                new EntityField('name_ua')->setTypeVarchar(255, false),
                new EntityField('address_ua')->setTypeVarchar(255),
                new EntityField('lat')->setTypeDecimal('10,7'),
                new EntityField('lng')->setTypeDecimal('10,7'),
                new EntityField('subtype_id')->setTypeInt(11),
                new EntityField('is_cash')->setTypeTinyInt(1, false)->setDefault(0),
                new EntityField('is_automated')->setTypeTinyInt(1, false)->setDefault(0),
                new EntityField('schedule_json')->setTypeText()->setNullable(),
                ...$loaded(),
            ],
            GroupEntity::class => [
                new EntityField('id')->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
                new EntityField('position')->setTypeInt(11, false)->setDefault(0),
                new EntityField('enabled')->setTypeTinyInt(1, false)->setDefault(1),
                new EntityField('kinds')->setTypeText(),
                new EntityField('name')->setTypeVarchar(255)->setIsLang(),
            ],
        ];
    }

    /**
     * Видалення модуля лишає його таблиці, а migrateEntityTable() — це голий CREATE TABLE:
     * повторне встановлення засмітило б лог повідомленнями "table already exists".
     *
     * @param array<class-string, list<EntityField>> $tables
     */
    private function createMissingTables(array $tables): void
    {
        $serviceLocator = ServiceLocator::getInstance();
        /** @var Database $db */
        $db = $serviceLocator->getService(Database::class);
        $db->query($serviceLocator->getService(QueryFactory::class)->newSqlQuery()->setStatement('SHOW TABLES'));
        $existing = [];
        foreach ($db->results() as $row) {
            $existing[] = (string)current((array)$row);
        }

        $prefix = (string)$serviceLocator->getService(Config::class)->get('db_prefix');
        foreach ($tables as $entityClass => $fields) {
            // Ядро підставляє префікс таблиць замість початкового "__".
            if (!in_array($prefix . substr($entityClass::getTable(), 2), $existing, true)) {
                $this->migrateEntityTable($entityClass, $fields);
            }
        }
    }

    private function addDefaultGroups(): void
    {
        $serviceLocator = ServiceLocator::getInstance();
        /** @var EntityFactory $entityFactory */
        $entityFactory = $serviceLocator->getService(EntityFactory::class);
        /** @var Languages $languages */
        $languages = $serviceLocator->getService(Languages::class);
        $groups = $entityFactory->get(GroupEntity::class);
        // Видалення модуля лишає його таблиці: повторне встановлення не повинне додати типові групи вдруге.
        if ($groups->count() > 0) {
            return;
        }
        $siteLanguages = $entityFactory->get(LanguagesEntity::class)->find();
        $initialLanguage = $languages->getLangId();

        foreach (DefaultGroups::rows() as $row) {
            $id = $groups->add([
                'position' => $row['position'],
                'enabled' => $row['enabled'],
                'kinds' => json_encode($row['kinds']),
                'name' => DefaultGroups::name($row, ''),
            ]);
            foreach ($siteLanguages as $language) {
                $languages->setLangId((int)$language->id);
                $groups->update($id, ['name' => DefaultGroups::name($row, (string)$language->label)]);
            }
        }
        $languages->setLangId($initialLanguage);
    }
}
