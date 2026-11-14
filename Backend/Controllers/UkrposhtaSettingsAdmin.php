<?php

namespace Okay\Modules\Sviat\Ukrposhta\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\Modules\Module;
use Okay\Entities\DeliveriesEntity;
use Okay\Modules\Sviat\Ukrposhta\Api\AuthFlags;
use Okay\Modules\Sviat\Ukrposhta\Api\CredentialsProvider;
use Okay\Modules\Sviat\Ukrposhta\Backend\DeliveryWarnings;
use Okay\Modules\Sviat\Ukrposhta\Backend\DictionaryStatusView;
use Okay\Modules\Sviat\Ukrposhta\Backend\KeySettings;
use Okay\Modules\Sviat\Ukrposhta\Backend\KeySettingsView;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderOffices;
use Okay\Modules\Sviat\Ukrposhta\Backend\SenderSettings;
use Okay\Modules\Sviat\Ukrposhta\Backend\ShippingSettings;
use Okay\Modules\Sviat\Ukrposhta\Cart\CitySearch;
use Okay\Modules\Sviat\Ukrposhta\Cart\PopularCities;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\DictionaryCounts;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\DictionaryStatus;
use Okay\Modules\Sviat\Ukrposhta\Groups\GroupsInput;
use Okay\Modules\Sviat\Ukrposhta\Groups\GroupsStore;
use Okay\Modules\Sviat\Ukrposhta\Groups\GroupsView;
use Okay\Modules\Sviat\Ukrposhta\Payments\CodPayments;
use Okay\Modules\Sviat\Ukrposhta\Storage\StateStorage;

/**
 * Сторінка налаштувань модуля (право `delivery`). Кожен блок — окрема форма, `ukr_action` каже, яка.
 * Перегляд сторінки не звертається до Укрпошти: ключі перевіряє лише кнопка «Перевірити ключі».
 */
class UkrposhtaSettingsAdmin extends IndexAdmin
{
    private const int CITY_LIMIT = 10;

    /** @var array<string, string>|null надісланий відправник, якщо його не збережено через помилки */
    private ?array $senderInput = null;
    /** @var array<string, string|bool>|null надіслані параметри відправлення, якщо їх не збережено через помилки */
    private ?array $shippingInput = null;

    public function fetch(
        KeySettings $keySettings,
        SenderSettings $senderSettings,
        ShippingSettings $shippingSettings,
        DictionaryRunner $dictionaryRunner,
        GroupsStore $groupsStore,
        PopularCities $popularCities,
        CredentialsProvider $credentials,
        SenderOffices $senderOffices,
        DictionaryCounts $dictionaryCounts,
        StateStorage $state,
        CodPayments $codPayments,
        Module $module,
        AuthFlags $authFlags,
    ) {
        if ($this->request->method('post')) {
            // Сток 4.5.3 перевіряє session_id лише після виконання контролера, тож підроблений POST уже встиг би спрацювати.
            if (!$this->request->checkSession()) {
                $this->response->setStatusCode(403);
                $this->response->setContent('Session expired', RESPONSE_TEXT);

                return;
            }
            $this->handle($keySettings, $senderSettings, $shippingSettings, $dictionaryRunner, $groupsStore, $popularCities);
        }

        $byKind = $dictionaryCounts->byKind();
        $this->design->assign('up_keys', KeySettingsView::vars($credentials->get(), $keySettings->lastResult()));
        $sender = $this->senderInput ?? $senderSettings->read();
        $senderCity = $senderOffices->cityOf($sender['postcode']);
        $this->design->assign('up_sender', [
            'values' => $sender,
            'city' => $senderCity,
            'offices' => $senderCity ? $senderOffices->forCity($senderCity['id']) : [],
        ]);
        $this->design->assign('up_dict', DictionaryStatusView::vars(
            DictionaryStatus::load($state),
            time(),
            $byKind + ['cities' => $dictionaryCounts->cities()],
            $dictionaryRunner->hasBearer(),
        ));
        $this->design->assign('up_groups', GroupsView::vars($groupsStore->all(), $byKind));
        $this->design->assign('up_popular', $popularCities->list());
        $this->design->assign('up_shipping', $this->shippingInput ?? ShippingSettings::form($shippingSettings->read()));
        $this->design->assign('up_cod_payments', $codPayments->flagged());
        $this->design->assign('up_auth', $authFlags->items());
        $this->design->assign('up_free_for_all', DeliveryWarnings::freeForAll(
            $this->entityFactory->get(DeliveriesEntity::class)->noLimit()->find() ?: [],
            (int)$module->getModuleIdByNamespace(__CLASS__),
        ));
        $this->response->setContent($this->design->fetch('settings.tpl'));
    }

    private function handle(
        KeySettings $keySettings,
        SenderSettings $senderSettings,
        ShippingSettings $shippingSettings,
        DictionaryRunner $dictionaryRunner,
        GroupsStore $groupsStore,
        PopularCities $popularCities,
    ): void {
        switch ($this->request->post('ukr_action')) {
            case 'keys':
                $errors = $keySettings->save($_POST);
                if ($errors !== []) {
                    $this->design->assign('up_keys_errors', $errors);
                } elseif ($this->request->post('check')) {
                    if ($keySettings->check() === null) {
                        $this->design->assign('up_keys_errors', ['sviat__ukrposhta__keys_nothing_to_check']);
                    } else {
                        $this->design->assign('up_keys_checked', true);
                    }
                } else {
                    $this->design->assign('up_keys_saved', true);
                }
                break;
            case 'sender':
                $errors = $senderSettings->save($_POST);
                $this->assignResult('sender', $errors);
                $this->senderInput = $errors === [] ? null : SenderSettings::fromPost($_POST);
                break;
            case 'dictionaries':
                if (!$dictionaryRunner->requestRun()) {
                    $this->design->assign('up_dict_refused', true);
                }
                break;
            case 'shipping':
                $errors = $shippingSettings->save($_POST);
                $this->assignResult('shipping', $errors);
                $this->shippingInput = $errors === [] ? null : ShippingSettings::formFromPost($_POST);
                break;
            case 'groups':
                $input = GroupsInput::parse($_POST);
                if ($input['errors'] === []) {
                    $groupsStore->save($input['groups']);
                    $this->design->assign('up_groups_saved', true);
                } else {
                    $this->design->assign('up_groups_errors', $input['errors']);
                }
                break;
            case 'popular':
                $ids = $this->request->post('popular');
                $popularCities->save(is_array($ids) ? array_values($ids) : []);
                $this->design->assign('up_popular_saved', true);
                break;
        }
    }

    /** @param array<array-key, string> $errors */
    private function assignResult(string $block, array $errors): void
    {
        if ($errors === []) {
            $this->design->assign("up_{$block}_saved", true);
        } else {
            $this->design->assign("up_{$block}_errors", $errors);
        }
    }

    /** Підказки міст для популярних міст і міста відправника (`controller=…@searchCities&q=`). */
    public function searchCities(CitySearch $search)
    {
        $query = $this->request->get('q');
        $found = is_string($query) ? $search->find($query, self::CITY_LIMIT) : [];
        $this->response->setContent(json_encode(['cities' => $found], JSON_UNESCAPED_UNICODE), RESPONSE_JSON);
    }

    /** Відділення міста для вибору відділення відправника (`controller=…@senderOffices&city_id=`). */
    public function senderOffices(SenderOffices $offices)
    {
        $cityId = (int)$this->request->get('city_id', 'integer');
        $this->response->setContent(json_encode(['offices' => $offices->forCity($cityId)], JSON_UNESCAPED_UNICODE), RESPONSE_JSON);
    }
}
