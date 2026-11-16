<?php

namespace Okay\Modules\Sviat\Ukrposhta;

use Okay\Core\Modules\Modules;
use Okay\Modules\Sviat\Ukrposhta\Extenders\DeliveryFilterExtender;
use Okay\Core\BackendTranslations;
use Okay\Core\Cart;
use Okay\Core\Database;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Modules\Module;
use Okay\Core\Money;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;
use Okay\Core\QueryFactory;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Okay\Modules\Sviat\Ukrposhta\Api\ClassifierClient;
use Okay\Modules\Sviat\Ukrposhta\Api\CredentialsProvider;
use Okay\Modules\Sviat\Ukrposhta\Api\EcomClient;
use Okay\Modules\Sviat\Ukrposhta\Api\TrackingClient;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\CurlTransport;
use Okay\Modules\Sviat\Ukrposhta\Api\Http\HttpTransport;
use Okay\Modules\Sviat\Ukrposhta\Backend\KeySettings;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderOffices;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderSettings;
use Okay\Modules\Sviat\Ukrposhta\Backend\ShippingSettings;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\DictionaryCounts;
use Okay\Modules\Sviat\Ukrposhta\Cart\CartSelectionValidator;
use Okay\Modules\Sviat\Ukrposhta\Cart\CitySearch;
use Okay\Modules\Sviat\Ukrposhta\Cart\DictionaryReady;
use Okay\Modules\Sviat\Ukrposhta\Extenders\BackendExtender;
use Okay\Modules\Sviat\Ukrposhta\Extenders\FrontExtender;
use Okay\Modules\Sviat\Ukrposhta\Orders\DbExistingOrders;
use Okay\Modules\Sviat\Ukrposhta\Orders\ExistingOrders;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderParcel;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderPointChange;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrderRowsCleanup;
use Okay\Modules\Sviat\Ukrposhta\Orders\OrdersMassAction;
use Okay\Modules\Sviat\Ukrposhta\Cart\OfficeList;
use Okay\Modules\Sviat\Ukrposhta\Cart\PopularCities;
use Okay\Modules\Sviat\Ukrposhta\Cart\ScheduleCache;
use Okay\Modules\Sviat\Ukrposhta\Cart\UahTotal;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\RegionSweep;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Store\DbDictionaryStore;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\Store\DictionaryStore;
use Okay\Modules\Sviat\Ukrposhta\Groups\GroupsStore;
use Okay\Modules\Sviat\Ukrposhta\Integration\UpOrders;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;
use Okay\Modules\Sviat\Ukrposhta\Quote\CartQuotes;
use Okay\Modules\Sviat\Ukrposhta\Quote\ClientAddress;
use Okay\Modules\Sviat\Ukrposhta\Quote\QuoteService;
use Okay\Modules\Sviat\Ukrposhta\Shipments\AmountDue;
use Okay\Modules\Sviat\Ukrposhta\Shipments\Labels;
use Okay\Modules\Sviat\Ukrposhta\Shipments\MassOrders;
use Okay\Modules\Sviat\Ukrposhta\Shipments\MassOrderSource;
use Okay\Modules\Sviat\Ukrposhta\Shipments\MassShipments;
use Okay\Modules\Sviat\Ukrposhta\Shipments\RecipientParty;
use Okay\Modules\Sviat\Ukrposhta\Shipments\SenderClient;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentDrafts;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentRows;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentService;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentStore;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentTracking;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentView;
use Okay\Modules\Sviat\Ukrposhta\Storage\DbMutex;
use Okay\Modules\Sviat\Ukrposhta\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\Ukrposhta\Storage\ModuleState;
use Okay\Modules\Sviat\Ukrposhta\Storage\Mutex;
use Okay\Modules\Sviat\Ukrposhta\Storage\StateStorage;
use Psr\Log\LoggerInterface;

return [
    ModuleState::class => [
        'class' => ModuleState::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    StateStorage::class => [
        'class' => ModuleState::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    DbMutex::class => [
        'class' => DbMutex::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    Mutex::class => [
        'class' => DbMutex::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    HttpTransport::class => [
        'class' => CurlTransport::class,
        'arguments' => [],
    ],
    CredentialsProvider::class => [
        'class' => CredentialsProvider::class,
        'arguments' => [new SR(Settings::class)],
    ],
    ClassifierClient::class => [
        'class' => ClassifierClient::class,
        'arguments' => [new SR(HttpTransport::class), new SR(CredentialsProvider::class)],
    ],
    EcomClient::class => [
        'class' => EcomClient::class,
        'arguments' => [new SR(HttpTransport::class), new SR(CredentialsProvider::class)],
    ],
    TrackingClient::class => [
        'class' => TrackingClient::class,
        'arguments' => [new SR(HttpTransport::class), new SR(CredentialsProvider::class)],
    ],
    KeyChecker::class => [
        'class' => KeyChecker::class,
        'arguments' => [
            new SR(ClassifierClient::class),
            new SR(EcomClient::class),
            new SR(TrackingClient::class),
            new SR(CredentialsProvider::class),
            new SR(StateStorage::class),
        ],
    ],
    DbDictionaryStore::class => [
        'class' => DbDictionaryStore::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    DictionaryStore::class => [
        'class' => DbDictionaryStore::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    RegionSweep::class => [
        'class' => RegionSweep::class,
        'arguments' => [new SR(ClassifierClient::class), new SR(StateStorage::class)],
    ],
    DictionaryRunner::class => [
        'class' => DictionaryRunner::class,
        'arguments' => [
            new SR(RegionSweep::class),
            new SR(DictionaryStore::class),
            new SR(StateStorage::class),
            new SR(Mutex::class),
            new SR(CredentialsProvider::class),
        ],
    ],
    GroupsStore::class => [
        'class' => GroupsStore::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    CitySearch::class => [
        'class' => CitySearch::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    OfficeList::class => [
        'class' => OfficeList::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class), new SR(GroupsStore::class)],
    ],
    PopularCities::class => [
        'class' => PopularCities::class,
        'arguments' => [new SR(StateStorage::class), new SR(EntityFactory::class)],
    ],
    ScheduleCache::class => [
        'class' => ScheduleCache::class,
        'arguments' => [new SR(ClassifierClient::class), new SR(EntityFactory::class)],
    ],
    UahTotal::class => [
        'class' => UahTotal::class,
        'arguments' => [new SR(EntityFactory::class), new SR(Money::class)],
    ],
    CodPayments::class => [
        'class' => CodPayments::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    QuoteService::class => [
        'class' => QuoteService::class,
        'arguments' => [new SR(EcomClient::class), new SR(AuthFlags::class)],
    ],
    CartQuotes::class => [
        'class' => CartQuotes::class,
        'arguments' => [
            new SR(QuoteService::class),
            new SR(Settings::class),
            new SR(EntityFactory::class),
            new SR(CodPayments::class),
            new SR(Module::class),
            new SR(Money::class),
            new SR(StateStorage::class),
            new SR(UahTotal::class),
            new SR(Mutex::class),
            new SR(ClientAddress::class),
        ],
    ],
    ClientAddress::class => [
        'class' => ClientAddress::class,
        'arguments' => [],
    ],
    DictionaryReady::class => [
        'class' => DictionaryReady::class,
        'arguments' => [new SR(StateStorage::class), new SR(EntityFactory::class)],
    ],
    CartSelectionValidator::class => [
        'class' => CartSelectionValidator::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Module::class),
            new SR(GroupsStore::class),
            new SR(CodPayments::class),
            new SR(DictionaryReady::class),
        ],
    ],
    OrderDeliveryStore::class => [
        'class' => OrderDeliveryStore::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    FrontExtender::class => [
        'class' => FrontExtender::class,
        'arguments' => [
            new SR(Design::class),
            new SR(Settings::class),
            new SR(Module::class),
            new SR(EntityFactory::class),
            new SR(FrontTranslations::class),
            new SR(CartSelectionValidator::class),
            new SR(OrderDeliveryStore::class),
            new SR(Cart::class),
            new SR(CartQuotes::class),
            new SR(CodPayments::class),
            new SR(Money::class),
            new SR(UahTotal::class),
            new SR(PopularCities::class),
            new SR(DictionaryReady::class),
            new SR(OrderDeliveryLines::class),
        ],
    ],
    OrderParcel::class => [
        'class' => OrderParcel::class,
        'arguments' => [new SR(EntityFactory::class), new SR(UahTotal::class)],
    ],
    OrderPointChange::class => [
        'class' => OrderPointChange::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Module::class),
            new SR(GroupsStore::class),
            new SR(OrderDeliveryStore::class),
            new SR(OrderParcel::class),
            new SR(CartQuotes::class),
            new SR(CodPayments::class),
            new SR(OrderDeliveryLines::class),
            new SR(ShipmentRows::class),
        ],
    ],
    OrderDeliveryLines::class => [
        'class' => OrderDeliveryLines::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Module::class),
            new SR(OrderDeliveryStore::class),
            new SR(CodPayments::class),
        ],
    ],
    ExistingOrders::class => [
        'class' => DbExistingOrders::class,
        'arguments' => [new SR(Database::class), new SR(QueryFactory::class)],
    ],
    OrderRowsCleanup::class => [
        'class' => OrderRowsCleanup::class,
        'arguments' => [new SR(EntityFactory::class), new SR(StateStorage::class), new SR(ExistingOrders::class)],
    ],
    DeliveryFilterExtender::class => [
        'class' => DeliveryFilterExtender::class,
        'arguments' => [new SR(ShipmentRows::class), new SR(BackendTranslations::class), new SR(Module::class), new SR(Modules::class)],
    ],
    BackendExtender::class => [
        'class' => BackendExtender::class,
        'arguments' => [
            new SR(Design::class),
            new SR(Request::class),
            new SR(EntityFactory::class),
            new SR(Module::class),
            new SR(BackendTranslations::class),
            new SR(OrderDeliveryStore::class),
            new SR(OrderRowsCleanup::class),
            new SR(OrderDeliveryLines::class),
            new SR(ShipmentRows::class),
            new SR(ShipmentView::class),
            new SR(OrdersMassAction::class),
            new SR(Response::class),
        ],
    ],
    KeySettings::class => [
        'class' => KeySettings::class,
        'arguments' => [new SR(Settings::class), new SR(KeyChecker::class), new SR(AuthFlags::class)],
    ],
    SenderOffices::class => [
        'class' => SenderOffices::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    SenderSettings::class => [
        'class' => SenderSettings::class,
        'arguments' => [new SR(Settings::class), new SR(SenderOffices::class)],
    ],
    SenderClient::class => [
        'class' => SenderClient::class,
        'arguments' => [new SR(EcomClient::class), new SR(StateStorage::class), new SR(SenderSettings::class)],
    ],
    ShipmentDrafts::class => [
        'class' => ShipmentDrafts::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(OrderDeliveryLines::class),
            new SR(OrderDeliveryStore::class),
            new SR(OrderParcel::class),
            new SR(UahTotal::class),
            new SR(CodPayments::class),
            new SR(Settings::class),
            new SR(AmountDue::class),
        ],
    ],
    AmountDue::class => [
        'class' => AmountDue::class,
    ],
    RecipientParty::class => [
        'class' => RecipientParty::class,
        'arguments' => [new SR(EcomClient::class)],
    ],
    UpOrders::class => [
        'class' => UpOrders::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    ShipmentRows::class => [
        'class' => ShipmentStore::class,
        'arguments' => [new SR(EntityFactory::class)],
    ],
    ShipmentService::class => [
        'class' => ShipmentService::class,
        'arguments' => [
            new SR(EcomClient::class),
            new SR(ShipmentRows::class),
            new SR(SenderClient::class),
            new SR(RecipientParty::class),
            new SR(AuthFlags::class),
        ],
    ],
    ShipmentTracking::class => [
        'class' => ShipmentTracking::class,
        'arguments' => [
            new SR(TrackingClient::class),
            new SR(EcomClient::class),
            new SR(ShipmentRows::class),
            new SR(Mutex::class),
            new SR(AuthFlags::class),
            new SR(LoggerInterface::class),
        ],
    ],
    Labels::class => [
        'class' => Labels::class,
        'arguments' => [new SR(EcomClient::class), new SR(ShipmentRows::class), new SR(Settings::class), new SR(AuthFlags::class)],
    ],
    MassOrders::class => [
        'class' => MassOrderSource::class,
        'arguments' => [new SR(EntityFactory::class), new SR(OrderDeliveryLines::class), new SR(ShipmentDrafts::class)],
    ],
    MassShipments::class => [
        'class' => MassShipments::class,
        'arguments' => [new SR(ShipmentService::class), new SR(ShipmentRows::class), new SR(MassOrders::class), new SR(LoggerInterface::class)],
    ],
    OrdersMassAction::class => [
        'class' => OrdersMassAction::class,
        'arguments' => [new SR(MassShipments::class), new SR(Labels::class), new SR(LoggerInterface::class)],
    ],
    AuthFlags::class => [
        'class' => AuthFlags::class,
        'arguments' => [new SR(StateStorage::class)],
    ],
    ShipmentView::class => [
        'class' => ShipmentView::class,
        'arguments' => [
            new SR(OrderDeliveryLines::class),
            new SR(ShipmentRows::class),
            new SR(ShipmentDrafts::class),
            new SR(AuthFlags::class),
            new SR(Labels::class),
        ],
    ],
    ShippingSettings::class => [
        'class' => ShippingSettings::class,
        'arguments' => [new SR(Settings::class)],
    ],
    DictionaryCounts::class => [
        'class' => DictionaryCounts::class,
        'arguments' => [new SR(EntityFactory::class), new SR(Database::class), new SR(QueryFactory::class)],
    ],
];
