{if $up_module_id && $delivery->module_id == $up_module_id && isset($up_prefill[$delivery->id])}
{$up_choice = $up_prefill[$delivery->id]}
{if $up_unavailable}
<p class="up-cart-unavailable" role="status">{$lang->sviat__ukrposhta__cart_dictionary_empty|escape}</p>
{else}
<div class="up-cart up-cart--{$up_theme.family|escape}"
     data-delivery-id="{$delivery->id|escape}"
     data-city="{$up_choice.city_json|escape}"
     data-office="{$up_choice.office_json|escape}"
     data-texts="{$up_texts_json|escape}"
     data-chip="{$up_theme.chip|escape}"
     data-chip-selected="{$up_theme.chipSelected|escape}"
     data-cod-payments="{$up_cod_payments[$delivery->id]|default:''|escape}"
     data-price-hidden="{if $delivery->hide_front_delivery_price}1{else}0{/if}">

    {if $up_popular}
        <div class="up-cart__popular {$up_theme.chips|escape}" role="group" aria-label="{$lang->sviat__ukrposhta__cart_popular|escape}">
            {foreach $up_popular as $city}
                <button type="button" class="{$up_theme.chip|escape}" aria-pressed="false" data-up-city="{$city.id|escape}" data-up-city-name="{$city.label|escape}" data-up-city-type="{$city.type|escape}">{$city.label|escape}</button>
            {/foreach}
        </div>
    {/if}

    <div class="up-cart__city {$up_theme.field|escape}">
        {if $up_theme.family == 'vibe'}
            <label class="{$up_theme.label|escape}" for="up_city_{$delivery->id|escape}">{$lang->sviat__ukrposhta__cart_city|escape}*</label>
        {/if}
        {* Ім'я задано лише для jQuery Validate теми (він пропускає поля без імені); сервер його ігнорує. *}
        <input id="up_city_{$delivery->id|escape}" class="up-cart__city-input {$up_theme.input|escape}" type="text" autocomplete="off" name="ukrposhta_city_q[{$delivery->id|escape}]"
               role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="up_city_list_{$delivery->id|escape}"
               value="{if $up_choice.city}{$up_choice.city.name|escape}{/if}"
               {if $up_theme.family == 'vibe'}placeholder="{$lang->sviat__ukrposhta__cart_city_placeholder|escape}"{/if}>
        {if $up_theme.family != 'vibe'}
            <span class="{$up_theme.label|escape}">{$lang->sviat__ukrposhta__cart_city|escape}*</span>
        {/if}
        <ul id="up_city_list_{$delivery->id|escape}" class="up-cart__suggest" role="listbox" hidden></ul>
    </div>

    {* Показується, щойно місто вибрано, замість популярних міст і поля міста. *}
    <div class="up-cart__city-line" hidden>
        <span class="up-cart__city-line-label">{$lang->sviat__ukrposhta__cart_city|escape}:</span>
        <span class="up-cart__city-line-type"></span> <b class="up-cart__city-line-name"></b>
        <span class="up-cart__city-line-area"></span>
        <button type="button" class="up-cart__link up-cart__change-city">{$lang->sviat__ukrposhta__cart_change_city|escape}</button>
    </div>

    {* Вибраний пункт замінює список: ніщо в блоці його не повторює. *}
    <div class="up-cart__chosen" hidden>
        <div class="up-cart__chosen-text">
            <span class="up-cart__chosen-city"></span>
            <b class="up-cart__chosen-title"></b>
            <span class="up-cart__chosen-place"></span>
            <span class="up-cart__chosen-schedule"></span>
        </div>
        <button type="button" class="up-cart__link up-cart__change">{$lang->sviat__ukrposhta__cart_change|escape}</button>
    </div>

    <div class="up-cart__points" hidden>
        <div class="up-cart__groups {$up_theme.chips|escape}" role="group" aria-label="{$lang->sviat__ukrposhta__cart_groups|escape}"></div>
        <div class="up-cart__search {$up_theme.field|escape}" hidden>
            {if $up_theme.family == 'vibe'}
                <label class="{$up_theme.label|escape}" for="up_search_{$delivery->id|escape}">{$lang->sviat__ukrposhta__cart_search|escape}</label>
            {/if}
            <input id="up_search_{$delivery->id|escape}" class="up-cart__search-input {$up_theme.input|escape}" type="search" autocomplete="off" name="ukrposhta_search_q[{$delivery->id|escape}]"
                   {if $up_theme.family == 'vibe'}placeholder="{$lang->sviat__ukrposhta__cart_search_placeholder|escape}"{/if}>
            {if $up_theme.family != 'vibe'}
                <span class="{$up_theme.label|escape}">{$lang->sviat__ukrposhta__cart_search|escape}</span>
            {/if}
        </div>
        {* Несе правило "виберіть пункт" з jQuery Validate, тож його повідомлення стоїть над списком. *}
        <input class="up-cart__point-check" type="text" name="ukrposhta_point_check[{$delivery->id|escape}]" value="" tabindex="-1" aria-hidden="true" autocomplete="off">
        <div class="up-cart__list" role="listbox" aria-label="{$lang->sviat__ukrposhta__cart_points|escape}"></div>
        <p class="up-cart__more" hidden></p>
    </div>

    <p class="up-cart__message" role="status" aria-live="polite" hidden></p>
    <div class="up-cart__quote" role="status" aria-live="polite" hidden></div>

    <input type="hidden" class="up-cart__city-id" name="ukrposhta_city_id[{$delivery->id|escape}]" value="{if $up_choice.city}{$up_choice.city.id|escape}{/if}">
    <input type="hidden" class="up-cart__office-id" name="ukrposhta_office_id[{$delivery->id|escape}]" value="{if $up_choice.office}{$up_choice.office.id|escape}{/if}">
</div>
{/if}
{/if}
