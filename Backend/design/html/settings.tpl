{$meta_title = $btr->sviat__ukrposhta__title scope=global}

<div class="main_header">
    <div class="main_header__item">
        <div class="main_header__inner">
            <div class="box_heading heading_page">{$btr->sviat__ukrposhta__title|escape}</div>
        </div>
    </div>
</div>

{if $up_free_for_all}
    <div class="alert alert--icon alert--error">
        <div class="alert__content">
            <div class="alert__title">{$btr->sviat__ukrposhta__free_for_all_title|escape}</div>
            <p>{$btr->sviat__ukrposhta__free_for_all_settings|escape}</p>
            <ul class="mb-0">
                {foreach $up_free_for_all as $up_delivery}
                    <li><a href="{url controller=DeliveryAdmin id=$up_delivery->id return=$smarty.server.REQUEST_URI}">{$up_delivery->name|escape}</a></li>
                {/foreach}
            </ul>
        </div>
    </div>
{/if}

{if $up_auth}
    <div id="up_auth_notice" class="alert alert--icon alert--error" role="alert">
        <div class="alert__content">
            <div class="alert__title">{$btr->sviat__ukrposhta__auth_title|escape}</div>
            {foreach $up_auth as $up_failure}
                <p>{$btr->getTranslation('sviat__ukrposhta__keys_status_'|cat:$up_failure.reason)|escape}. {$btr->getTranslation('sviat__ukrposhta__auth_'|cat:$up_failure.api)|escape}</p>
            {/foreach}
            <p class="mb-0">{$btr->sviat__ukrposhta__auth_settings_advice|escape}</p>
        </div>
    </div>
{/if}

{* Секрети в сторінку не потрапляють: поля ключів завжди порожні, збережений ключ показано лише як ••••. *}
<div id="up_settings" class="up-set" data-base="{$smarty.server.SCRIPT_NAME|escape}?controller=Sviat.Ukrposhta.UkrposhtaSettingsAdmin@">
<div class="tabs">
    <div class="tab_navigation tab_navigation--round">
        <a href="#up_tab_main" class="tab_navigation_link">{$btr->sviat__ukrposhta__tab_main|escape}</a>
        <a href="#up_tab_shipping" class="tab_navigation_link">{$btr->sviat__ukrposhta__tab_shipping|escape}</a>
        <a href="#up_tab_points" class="tab_navigation_link">{$btr->sviat__ukrposhta__tab_points|escape}</a>
    </div>
    <div class="tab_container">

<div id="up_tab_main" class="tab">
<div class="row">
<div class="col-xl-6 col-lg-12">

<form method="post" id="up_keys_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="ukr_action" value="keys">
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__ukrposhta__keys_box|escape}</div>
        <p class="text_grey">{$btr->sviat__ukrposhta__keys_intro|escape}</p>
        {if $up_keys_errors}
            <div class="alert alert--error" role="alert"><div class="alert__content">
                {foreach $up_keys_errors as $up_error}
                    <p>{$btr->getTranslation($up_error)|escape}</p>
                {/foreach}
            </div></div>
        {/if}
        {if $up_keys_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__ukrposhta__keys_saved_message|escape}</p></div></div>
        {/if}
        {if $up_keys_checked}
            <div id="up_keys_result" class="alert {if $up_keys.has_problem}alert--warning{else}alert--success{/if}" role="status"><div class="alert__content">
                <div class="alert__title">{$btr->sviat__ukrposhta__keys_result_title|escape}</div>
                <ul class="mb-0">
                    {foreach $up_keys.fields as $up_field}
                        <li>{$btr->getTranslation('sviat__ukrposhta__keys_'|cat:$up_field.name)|escape}: <b>{$btr->getTranslation('sviat__ukrposhta__keys_status_'|cat:$up_field.status)|escape}</b></li>
                    {/foreach}
                </ul>
            </div></div>
        {/if}
        {foreach $up_keys.fields as $up_field}
            <div class="form-group up-set__key">
                <label class="heading_label" for="up_{$up_field.name|escape}">{$btr->getTranslation('sviat__ukrposhta__keys_'|cat:$up_field.name)|escape}</label>
                <input id="up_{$up_field.name|escape}" type="password" name="{$up_field.name|escape}" class="form-control" value=""
                       autocomplete="new-password" spellcheck="false"
                       placeholder="{if $up_field.set}{$btr->sviat__ukrposhta__keys_stored|escape} ••••. {$btr->sviat__ukrposhta__keys_keep|escape}{else}{$btr->sviat__ukrposhta__keys_empty|escape}{/if}">
                <div class="up-set__key-meta">
                    {if $up_keys.checked_at && $up_field.set}
                        <span class="up-set__badge up-set__badge--{$up_field.status|escape}">{$btr->getTranslation('sviat__ukrposhta__keys_status_'|cat:$up_field.status)|escape}</span>
                    {/if}
                    {if $up_field.set}
                        <label class="up-set__clear"><input type="checkbox" name="clear_{$up_field.name|escape}" value="1"> {$btr->sviat__ukrposhta__keys_clear|escape}</label>
                    {/if}
                </div>
            </div>
        {/foreach}
        <input type="hidden" name="sandbox" value="0">
        <label class="up-set__switch">
            <span class="switch switch-default">
                <input class="switch-input" type="checkbox" name="sandbox" value="1" {if $up_keys.sandbox}checked{/if}>
                <span class="switch-label"></span>
                <span class="switch-handle"></span>
            </span>
            <span><b>{$btr->sviat__ukrposhta__keys_sandbox|escape}</b><br><span class="text_grey">{$btr->sviat__ukrposhta__keys_sandbox_note|escape}</span></span>
        </label>
        {if $up_keys.checked_at}
            <p class="text_grey mt-1 mb-0">{$btr->sviat__ukrposhta__keys_checked_at|escape} {$up_keys.checked_at|escape}.{if $up_keys.has_problem} {$btr->sviat__ukrposhta__keys_problem|escape}{/if}</p>
        {/if}
        <div class="up-set__buttons">
            <button type="submit" class="btn btn_small btn_blue"><span>{$btr->sviat__ukrposhta__save|escape}</span></button>
            <button type="submit" name="check" value="1" class="btn btn_small btn-outline-info" data-up-busy="{$btr->sviat__ukrposhta__keys_checking|escape}" {if !$up_keys.any_set}disabled aria-describedby="up_keys_check_reason"{/if}><span>{$btr->sviat__ukrposhta__keys_check|escape}</span></button>
        </div>
        {if !$up_keys.any_set}
            <p id="up_keys_check_reason" class="up-set__reason mt-1">{$btr->sviat__ukrposhta__keys_check_needs_key|escape}</p>
        {/if}
    </div>
</form>

<form method="post" id="up_dict_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="ukr_action" value="dictionaries">
    <div class="boxed mb-2">
        <div class="up-set__box-head">
            <div class="heading_box">{$btr->sviat__ukrposhta__dict_box|escape}</div>
            <button type="submit" class="btn btn_small btn-outline-info" {if !$up_dict.can_run}disabled{/if} {if !$up_dict.has_bearer}aria-describedby="up_dict_needs_bearer"{/if}>
                <span>{if $up_dict.busy}{$btr->sviat__ukrposhta__dict_button_busy|escape}{else}{$btr->sviat__ukrposhta__dict_button|escape}{/if}</span>
            </button>
        </div>
        {if !$up_dict.has_bearer}
            <p id="up_dict_needs_bearer" class="up-set__reason">{$btr->sviat__ukrposhta__dict_needs_bearer|escape}</p>
        {/if}
        {if $up_dict_refused}
            <div class="alert alert--error" role="alert"><div class="alert__content"><p>{$btr->sviat__ukrposhta__dict_refused|escape}</p></div></div>
        {/if}
        <p class="text_grey">{$btr->sviat__ukrposhta__dict_intro|escape}</p>
        {if $up_dict.has_data}
            <div class="up-set__figures">
                <div><b>{$up_dict.shown.office|escape}</b><span>{$btr->sviat__ukrposhta__dict_fig_office|escape}</span></div>
                <div><b>{$up_dict.shown.locker|escape}</b><span>{$btr->sviat__ukrposhta__dict_fig_locker|escape}</span></div>
                <div><b>{$up_dict.shown.pudo|escape}</b><span>{$btr->sviat__ukrposhta__dict_fig_pudo|escape}</span></div>
                <div><b>{$up_dict.shown.cities|escape}</b><span>{$btr->sviat__ukrposhta__dict_fig_cities|escape}</span></div>
            </div>
            {if $up_dict.updated_at}
                <div class="text_grey">
                    {$btr->sviat__ukrposhta__dict_updated|escape} {$up_dict.updated_at|escape}.
                    {$btr->sviat__ukrposhta__dict_changes|replace:'%added%':$up_dict.changes.added|replace:'%changed%':$up_dict.changes.changed|replace:'%deleted%':$up_dict.changes.deleted|escape}
                </div>
            {/if}
        {/if}

        {if $up_dict.state != 'ok'}
            <div class="alert alert--icon mt-1 mb-0 alert--{$up_dict.tone|escape}" role="status" aria-live="polite">
                <div class="alert__content">
                    <div class="alert__title">{$btr->getTranslation('sviat__ukrposhta__dict_'|cat:$up_dict.state|cat:'_title')|escape}</div>
                    <p>
                        {$btr->getTranslation('sviat__ukrposhta__dict_'|cat:$up_dict.state|cat:'_text')|escape}
                        {if $up_dict.error} <span class="text_grey">({$up_dict.error|escape})</span>{/if}
                    </p>
                    {if $up_dict.skipped}
                        <ul class="up-set__regions">
                            {foreach $up_dict.skipped as $up_region}
                                <li><b>{$up_region.region|escape}</b> <span class="text_grey">{$up_region.reason|escape}</span></li>
                            {/foreach}
                        </ul>
                    {/if}
                    {if $up_dict.unresolved}
                        <p class="mb-0">{$btr->sviat__ukrposhta__dict_unresolved|escape} {foreach $up_dict.unresolved as $up_name}{$up_name|escape}{if !$up_name@last}, {/if}{/foreach}</p>
                    {/if}
                    {if $up_dict.state == 'scheduler_idle' || $up_dict.state == 'stale'}
                        <code class="up-set__cron">* * * * * php /path/to/site/ok scheduler:run</code>
                    {/if}
                </div>
            </div>
        {/if}
    </div>
</form>

</div>
<div class="col-xl-6 col-lg-12">

<form method="post" id="up_sender_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="ukr_action" value="sender">
    {$up_s = $up_sender.values}
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__ukrposhta__sender_box|escape}</div>
        <p class="text_grey">{$btr->sviat__ukrposhta__sender_intro|escape}</p>
        {if $up_sender_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__ukrposhta__sender_saved|escape}</p></div></div>
        {/if}
        {if $up_sender_errors}
            <div class="alert alert--error" role="alert"><div class="alert__content">
                {foreach $up_sender_errors as $up_error}
                    <p>{$btr->getTranslation($up_error)|escape}</p>
                {/foreach}
            </div></div>
        {elseif !$up_s.postcode}
            <div class="alert alert--warning" role="status"><div class="alert__content"><p>{$btr->sviat__ukrposhta__sender_no_office|escape}</p></div></div>
        {/if}

        <div class="up-set__radios" role="radiogroup" aria-label="{$btr->sviat__ukrposhta__sender_type|escape}">
            <div class="okay_type_radio_wrap">
                <input id="up_sender_type_company" class="hidden_check" type="radio" name="sender_type" value="COMPANY" {if $up_s.type == 'COMPANY'}checked{/if}>
                <label for="up_sender_type_company" class="okay_type_radio"><span>{$btr->sviat__ukrposhta__sender_company|escape}</span></label>
            </div>
            <div class="okay_type_radio_wrap">
                <input id="up_sender_type_fop" class="hidden_check" type="radio" name="sender_type" value="PRIVATE_ENTREPRENEUR" {if $up_s.type == 'PRIVATE_ENTREPRENEUR'}checked{/if}>
                <label for="up_sender_type_fop" class="okay_type_radio"><span>{$btr->sviat__ukrposhta__sender_fop|escape}</span></label>
            </div>
        </div>

        {* Неактивний тип вимкнено, а не лише сховано: інакше браузер перевіряв би приховані поля й не давав зберегти. *}
        <fieldset class="up-set__grid up-set__fieldset" data-up-sender="COMPANY" {if $up_s.type != 'COMPANY'}hidden disabled{/if}>
            <div class="up-set__wide">
                <label class="heading_label" for="up_sender_name">{$btr->sviat__ukrposhta__sender_name|escape}</label>
                <input id="up_sender_name" class="form-control" type="text" name="sender_name" minlength="2" maxlength="60" value="{$up_s.name|escape}">
            </div>
            <div>
                <label class="heading_label" for="up_sender_edrpou">{$btr->sviat__ukrposhta__sender_edrpou|escape}</label>
                <input id="up_sender_edrpou" class="form-control" type="text" inputmode="numeric" name="sender_edrpou" minlength="8" maxlength="8" {literal}pattern="[0-9]{8}"{/literal} autocomplete="off" value="{$up_s.edrpou|escape}">
            </div>
        </fieldset>
        <fieldset class="up-set__grid up-set__fieldset" data-up-sender="PRIVATE_ENTREPRENEUR" {if $up_s.type != 'PRIVATE_ENTREPRENEUR'}hidden disabled{/if}>
            <div>
                <label class="heading_label" for="up_sender_last_name">{$btr->sviat__ukrposhta__sender_last_name|escape}</label>
                <input id="up_sender_last_name" class="form-control" type="text" name="sender_last_name" minlength="2" maxlength="250" value="{$up_s.last_name|escape}">
            </div>
            <div>
                <label class="heading_label" for="up_sender_first_name">{$btr->sviat__ukrposhta__sender_first_name|escape}</label>
                <input id="up_sender_first_name" class="form-control" type="text" name="sender_first_name" minlength="2" maxlength="250" value="{$up_s.first_name|escape}">
            </div>
            <div>
                <label class="heading_label" for="up_sender_tin">{$btr->sviat__ukrposhta__sender_tin|escape}</label>
                <input id="up_sender_tin" class="form-control" type="text" inputmode="numeric" name="sender_tin" minlength="10" maxlength="10" {literal}pattern="[0-9]{10}"{/literal} autocomplete="off" value="{$up_s.tin|escape}">
            </div>
        </fieldset>

        <div class="up-set__grid">
            <div>
                <label class="heading_label" for="up_sender_phone">{$btr->sviat__ukrposhta__sender_phone|escape}</label>
                <input id="up_sender_phone" class="form-control" type="tel" inputmode="tel" name="sender_phone" maxlength="20" {literal}pattern="[0-9 \(\)+\-]{10,20}"{/literal} value="{$up_s.phone|escape}" placeholder="+380XXXXXXXXX">
            </div>
            <div>
                <label class="heading_label" for="up_sender_iban">{$btr->sviat__ukrposhta__sender_iban|escape}</label>
                <input id="up_sender_iban" class="form-control" type="text" name="sender_iban" maxlength="40" {literal}pattern="[Uu][Aa][0-9 ]{27,38}"{/literal} spellcheck="false" autocomplete="off" value="{$up_s.iban|escape}" placeholder="UA…">
            </div>
        </div>

        <div class="up-set__grid">
            <div>
                <div class="heading_label">{$btr->sviat__ukrposhta__sender_city|escape}</div>
                <div class="up-set__city" id="up_sender_city">
                    <div class="up-set__city-chosen" {if !$up_sender.city}hidden{/if}>
                        <span class="up-set__city-name">{if $up_sender.city}{if $up_sender.city.type}<span class="text_grey">{$up_sender.city.type|escape}</span> {/if}<b>{$up_sender.city.name|escape}</b>{/if}</span>
                        <button type="button" class="up-set__link up-set__city-change">{$btr->sviat__ukrposhta__change|escape}</button>
                    </div>
                    <div class="up-set__search" {if $up_sender.city}hidden{/if}>
                        <input class="form-control up-set__city-input" type="text" autocomplete="off" aria-label="{$btr->sviat__ukrposhta__sender_city|escape}" placeholder="{$btr->sviat__ukrposhta__city_placeholder|escape}">
                        <ul class="up-set__suggest" role="listbox" hidden></ul>
                    </div>
                </div>
            </div>
            <div>
                <label class="heading_label" for="up_sender_postcode">{$btr->sviat__ukrposhta__sender_office|escape}</label>
                <select id="up_sender_postcode" name="sender_postcode" class="selectpicker form-control" data-live-search="true">
                    <option value="">{$btr->sviat__ukrposhta__sender_office_none|escape}</option>
                    {if $up_s.postcode && !$up_sender.offices}
                        <option value="{$up_s.postcode|escape}" selected>{$up_s.postcode|escape}</option>
                    {/if}
                    {foreach $up_sender.offices as $up_office}
                        <option value="{$up_office.postcode|escape}" {if $up_office.postcode == $up_s.postcode}selected{/if}>{$up_office.title|escape}</option>
                    {/foreach}
                </select>
            </div>
        </div>
        <div><button type="submit" class="btn btn_small btn_blue mt-1"><span>{$btr->sviat__ukrposhta__save|escape}</span></button></div>
    </div>
</form>

</div>
</div>
</div>

<div id="up_tab_shipping" class="tab">
<div class="row">
<div class="col-xl-7 col-lg-12">

<form method="post" id="up_shipping_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="ukr_action" value="shipping">
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__ukrposhta__shipping_box|escape}</div>
        <p class="text_grey">{$btr->sviat__ukrposhta__shipping_intro|escape}</p>
        {if $up_shipping_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__ukrposhta__shipping_saved|escape}</p></div></div>
        {/if}
        {if $up_shipping_errors}
            <div class="alert alert--error" role="alert"><div class="alert__content">
                {foreach $up_shipping_errors as $up_error}
                    <p>{$btr->getTranslation($up_error)|escape}</p>
                {/foreach}
            </div></div>
        {/if}
        <div class="up-set__grid">
            <div>
                <label class="heading_label" for="up_shipment_type">{$btr->sviat__ukrposhta__shipping_type|escape}</label>
                <select id="up_shipment_type" name="shipment_type" class="selectpicker form-control">
                    <option value="STANDARD" {if $up_shipping.type == 'STANDARD'}selected{/if}>{$btr->sviat__ukrposhta__shipping_type_standard|escape}</option>
                    <option value="EXPRESS" {if $up_shipping.type == 'EXPRESS'}selected{/if}>{$btr->sviat__ukrposhta__shipping_type_express|escape}</option>
                </select>
            </div>
            <div>
                <label class="heading_label" for="up_default_weight">{$btr->sviat__ukrposhta__shipping_weight|escape}</label>
                <input id="up_default_weight" class="form-control" type="number" inputmode="decimal" name="default_weight" min="0.001" max="30" step="0.001" required value="{$up_shipping.weight|escape}">
            </div>
        </div>
        <div class="heading_label mt-1">{$btr->sviat__ukrposhta__shipping_box_size|escape}</div>
        <div class="up-set__grid up-set__grid--three">
            <input class="form-control" type="number" inputmode="numeric" name="box_length" min="1" max="120" step="1" required value="{$up_shipping.length|escape}" aria-label="{$btr->sviat__ukrposhta__shipping_length|escape}" title="{$btr->sviat__ukrposhta__shipping_length|escape}">
            <input class="form-control" type="number" inputmode="numeric" name="box_width" min="1" max="120" step="1" required value="{$up_shipping.width|escape}" aria-label="{$btr->sviat__ukrposhta__shipping_width|escape}" title="{$btr->sviat__ukrposhta__shipping_width|escape}">
            <input class="form-control" type="number" inputmode="numeric" name="box_height" min="1" max="120" step="1" required value="{$up_shipping.height|escape}" aria-label="{$btr->sviat__ukrposhta__shipping_height|escape}" title="{$btr->sviat__ukrposhta__shipping_height|escape}">
        </div>
        <p class="text_grey mt-1">{$btr->sviat__ukrposhta__shipping_defaults_note|escape}</p>
        <div class="up-set__grid">
            <div>
                <label class="heading_label" for="up_payer">{$btr->sviat__ukrposhta__shipping_payer|escape}</label>
                <select id="up_payer" name="payer" class="selectpicker form-control">
                    <option value="recipient" {if $up_shipping.payer == 'recipient'}selected{/if}>{$btr->sviat__ukrposhta__party_recipient|escape}</option>
                    <option value="sender" {if $up_shipping.payer == 'sender'}selected{/if}>{$btr->sviat__ukrposhta__party_sender|escape}</option>
                </select>
            </div>
            <div>
                <label class="heading_label" for="up_cod_payer">{$btr->sviat__ukrposhta__shipping_cod_payer|escape}</label>
                <select id="up_cod_payer" name="cod_payer" class="selectpicker form-control">
                    <option value="recipient" {if $up_shipping.cod_payer == 'recipient'}selected{/if}>{$btr->sviat__ukrposhta__party_recipient|escape}</option>
                    <option value="sender" {if $up_shipping.cod_payer == 'sender'}selected{/if}>{$btr->sviat__ukrposhta__party_sender|escape}</option>
                </select>
            </div>
            <div>
                <label class="heading_label" for="up_on_fail">{$btr->sviat__ukrposhta__shipping_on_fail|escape}</label>
                <select id="up_on_fail" name="on_fail" class="selectpicker form-control">
                    <option value="RETURN" {if $up_shipping.on_fail == 'RETURN'}selected{/if}>{$btr->sviat__ukrposhta__shipping_on_fail_return|escape}</option>
                    <option value="PROCESS_AS_REFUSAL" {if $up_shipping.on_fail == 'PROCESS_AS_REFUSAL'}selected{/if}>{$btr->sviat__ukrposhta__shipping_on_fail_refusal|escape}</option>
                </select>
            </div>
            <div>
                <label class="heading_label" for="up_discount">{$btr->sviat__ukrposhta__shipping_discount|escape}</label>
                <input id="up_discount" class="form-control" type="number" inputmode="decimal" name="discount" min="0" max="100" step="0.01" value="{$up_shipping.discount|escape}">
            </div>
            <div>
                <label class="heading_label" for="up_label_size">{$btr->sviat__ukrposhta__shipping_label_size|escape}</label>
                <select id="up_label_size" name="label_size" class="selectpicker form-control">
                    <option value="SIZE_10X10" {if $up_shipping.label_size == 'SIZE_10X10'}selected{/if}>{$btr->sviat__ukrposhta__label_size_10x10|escape}</option>
                    <option value="SIZE_A4" {if $up_shipping.label_size == 'SIZE_A4'}selected{/if}>{$btr->sviat__ukrposhta__label_size_a4|escape}</option>
                </select>
            </div>
            <div class="up-set__wide">
                <label class="heading_label" for="up_description_mode">{$btr->sviat__ukrposhta__shipping_description|escape}</label>
                <select id="up_description_mode" name="description_mode" class="selectpicker form-control">
                    <option value="products" {if $up_shipping.description_mode == 'products'}selected{/if}>{$btr->sviat__ukrposhta__shipping_description_products|escape}</option>
                    <option value="order" {if $up_shipping.description_mode == 'order'}selected{/if}>{$btr->sviat__ukrposhta__shipping_description_order|escape}</option>
                    <option value="text" {if $up_shipping.description_mode == 'text'}selected{/if}>{$btr->sviat__ukrposhta__shipping_description_text|escape}</option>
                </select>
                <input id="up_description" class="form-control mt-q" type="text" name="description" maxlength="255" value="{$up_shipping.description|escape}" aria-label="{$btr->sviat__ukrposhta__shipping_description_text|escape}" {if $up_shipping.description_mode != 'text'}hidden{else}required{/if}>
                <p class="text_grey mt-q mb-0">{$btr->sviat__ukrposhta__shipping_description_note|escape}</p>
            </div>
        </div>
        <label class="up-set__switch mt-1">
            <span class="switch switch-default">
                <input class="switch-input" type="checkbox" name="declare_total" value="1" {if $up_shipping.declare_total}checked{/if}>
                <span class="switch-label"></span>
                <span class="switch-handle"></span>
            </span>
            <span><b>{$btr->sviat__ukrposhta__shipping_declare_total|escape}</b><br><span class="text_grey">{$btr->sviat__ukrposhta__shipping_declare_total_note|escape}</span></span>
        </label>
        <label class="up-set__switch mt-1">
            <span class="switch switch-default">
                <input class="switch-input" type="checkbox" name="sms" value="1" {if $up_shipping.sms}checked{/if}>
                <span class="switch-label"></span>
                <span class="switch-handle"></span>
            </span>
            <span><b>{$btr->sviat__ukrposhta__shipping_sms|escape}</b><br><span class="text_grey">{$btr->sviat__ukrposhta__shipping_sms_note|escape}</span></span>
        </label>
        <div><button type="submit" class="btn btn_small btn_blue mt-1"><span>{$btr->sviat__ukrposhta__save|escape}</span></button></div>
    </div>
</form>

</div>
<div class="col-xl-5 col-lg-12">

<div class="boxed mb-2">
    <div class="heading_box mb-1">{$btr->sviat__ukrposhta__cod_box|escape}</div>
    <p class="text_grey">{$btr->sviat__ukrposhta__cod_intro|escape}</p>
    {if $up_cod_payments}
        <ul class="up-set__cod">
            {foreach $up_cod_payments as $up_payment}
                <li><a href="{url controller=PaymentMethodAdmin id=$up_payment->id return=$smarty.server.REQUEST_URI}">{$up_payment->name|escape}</a>{if !$up_payment->enabled} <span class="text_grey">{$btr->sviat__ukrposhta__cod_disabled|escape}</span>{/if}</li>
            {/foreach}
        </ul>
    {else}
        <div class="alert alert--info mb-0" role="status"><div class="alert__content"><p>{$btr->sviat__ukrposhta__cod_none|escape}</p></div></div>
    {/if}
</div>

</div>
</div>
</div>

<div id="up_tab_points" class="tab">
<div class="row">
<div class="col-xl-7 col-lg-12">

<form method="post" id="up_groups_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="ukr_action" value="groups">
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__ukrposhta__groups_box|escape}</div>
        <p class="text_grey">{$btr->sviat__ukrposhta__groups_intro|escape}</p>
        {if $up_groups_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__ukrposhta__groups_saved|escape}</p></div></div>
        {/if}
        {if $up_groups_errors}
            <div class="alert alert--error" role="alert"><div class="alert__content">
                {foreach $up_groups_errors as $up_error}
                    <p>{$btr->getTranslation($up_error)|escape}</p>
                {/foreach}
            </div></div>
        {/if}

        <div class="heading_label">{$btr->sviat__ukrposhta__groups_list|escape}</div>
        <div id="up_group_rows" class="sortable">
            {foreach $up_groups.groups as $up_group}
                <div class="up-set__group">
                    <input type="hidden" data-field="id" value="{$up_group.id|escape}">
                    <div class="up-set__drag move_zone" title="{$btr->sviat__ukrposhta__drag|escape}">{include file='svg_icon.tpl' svgId='drag_vertical'}</div>
                    <input type="text" class="form-control up-set__group-name" data-field="name" value="{$up_group.name|escape}" maxlength="255" aria-label="{$btr->sviat__ukrposhta__groups_name|escape}">
                    <label class="up-set__inline">
                        <span>{$btr->sviat__ukrposhta__groups_enabled|escape}</span>
                        <span class="switch switch-default">
                            <input class="switch-input" type="checkbox" data-field="enabled" value="1" {if $up_group.enabled}checked{/if}>
                            <span class="switch-label"></span>
                            <span class="switch-handle"></span>
                        </span>
                    </label>
                    <input type="hidden" data-field="delete" value="">
                    <button type="button" class="btn_close up-set__remove up-set__group-remove" aria-label="{$btr->sviat__ukrposhta__groups_delete|escape}" title="{$btr->sviat__ukrposhta__groups_delete|escape}">{include file='svg_icon.tpl' svgId='trash'}</button>
                </div>
            {/foreach}
            <div class="up-set__group up-set__group--new" hidden>
                <input type="hidden" data-field="id" value="new">
                <div class="up-set__drag move_zone" title="{$btr->sviat__ukrposhta__drag|escape}">{include file='svg_icon.tpl' svgId='drag_vertical'}</div>
                <input type="text" class="form-control up-set__group-name" data-field="name" value="" maxlength="255" aria-label="{$btr->sviat__ukrposhta__groups_name|escape}">
                <label class="up-set__inline">
                    <span>{$btr->sviat__ukrposhta__groups_enabled|escape}</span>
                    <span class="switch switch-default">
                        <input class="switch-input" type="checkbox" data-field="enabled" value="1" checked>
                        <span class="switch-label"></span>
                        <span class="switch-handle"></span>
                    </span>
                </label>
                <button type="button" class="btn_close up-set__remove up-set__group-remove" aria-label="{$btr->sviat__ukrposhta__groups_delete|escape}" title="{$btr->sviat__ukrposhta__groups_delete|escape}">{include file='svg_icon.tpl' svgId='trash'}</button>
            </div>
        </div>
        <button type="button" class="btn btn_mini btn-secondary btn_openSans mt-1 mb-2" id="up_group_add">
            {include file='svg_icon.tpl' svgId='plus'}
            <span>{$btr->sviat__ukrposhta__groups_add|escape}</span>
        </button>

        <div class="heading_label">{$btr->sviat__ukrposhta__groups_kinds|escape}</div>
        {foreach $up_groups.kinds as $up_kind}
            <div class="up-set__kind">
                <div>
                    <b>{$btr->getTranslation($up_kind.label_key)|escape}</b>
                    <span class="text_grey">{$up_kind.count|escape} {$btr->sviat__ukrposhta__groups_points|escape}</span>
                </div>
                <select name="kinds[{$up_kind.key|escape}]" class="selectpicker form-control up-set__kind-group" aria-label="{$btr->sviat__ukrposhta__groups_group|escape}">
                    <option value="0" {if !$up_kind.group}selected{/if}>{$btr->sviat__ukrposhta__groups_none|escape}</option>
                    {foreach $up_groups.groups as $up_group}
                        <option value="{$up_group.id|escape}" {if $up_kind.group == $up_group.id}selected{/if}>{$up_group.name|escape}</option>
                    {/foreach}
                </select>
                {if $up_kind.warning}
                    <div class="up-set__warning">{$btr->getTranslation('sviat__ukrposhta__groups_warn_'|cat:$up_kind.warning)|escape}</div>
                {/if}
            </div>
        {/foreach}

        <button type="submit" class="btn btn_small btn_blue mt-1"><span>{$btr->sviat__ukrposhta__save|escape}</span></button>
    </div>
</form>

</div>
<div class="col-xl-5 col-lg-12">

<form method="post" id="up_popular_form">
    <input type="hidden" name="session_id" value="{$smarty.session.id|escape}">
    <input type="hidden" name="ukr_action" value="popular">
    <div class="boxed mb-2">
        <div class="heading_box mb-1">{$btr->sviat__ukrposhta__popular_box|escape}</div>
        <p class="text_grey">{$btr->sviat__ukrposhta__popular_intro|escape}</p>
        {if $up_popular_saved}
            <div class="alert alert--success" role="status"><div class="alert__content"><p>{$btr->sviat__ukrposhta__popular_saved|escape}</p></div></div>
        {/if}
        <div id="up_popular_rows" class="sortable">
            {foreach $up_popular as $up_city}
                <div class="up-set__popular">
                    <input type="hidden" name="popular[]" value="{$up_city.id|escape}">
                    <div class="up-set__drag move_zone" title="{$btr->sviat__ukrposhta__drag|escape}">{include file='svg_icon.tpl' svgId='drag_vertical'}</div>
                    <div class="up-set__popular-name"><b>{$up_city.name|escape}</b> <span class="text_grey">{$up_city.region|escape}</span></div>
                    <button type="button" class="btn_close up-set__remove" aria-label="{$btr->sviat__ukrposhta__popular_remove|escape}" title="{$btr->sviat__ukrposhta__popular_remove|escape}">{include file='svg_icon.tpl' svgId='trash'}</button>
                </div>
            {/foreach}
        </div>
        <template id="up_popular_template">
            <div class="up-set__popular">
                <input type="hidden" name="popular[]" value="">
                <div class="up-set__drag move_zone" title="{$btr->sviat__ukrposhta__drag|escape}">{include file='svg_icon.tpl' svgId='drag_vertical'}</div>
                <div class="up-set__popular-name"><b></b> <span class="text_grey"></span></div>
                <button type="button" class="btn_close up-set__remove" aria-label="{$btr->sviat__ukrposhta__popular_remove|escape}" title="{$btr->sviat__ukrposhta__popular_remove|escape}">{include file='svg_icon.tpl' svgId='trash'}</button>
            </div>
        </template>
        <p class="text_grey up-set__popular-empty" {if $up_popular}hidden{/if}>{$btr->sviat__ukrposhta__popular_empty|escape}</p>
        <div class="up-set__search" id="up_popular_search">
            <label class="heading_label" for="up_popular_input">{$btr->sviat__ukrposhta__popular_add|escape}</label>
            <input id="up_popular_input" class="form-control up-set__city-input" type="text" autocomplete="off" placeholder="{$btr->sviat__ukrposhta__city_placeholder|escape}">
            <ul class="up-set__suggest" role="listbox" hidden></ul>
        </div>
        <button type="submit" class="btn btn_small btn_blue mt-1"><span>{$btr->sviat__ukrposhta__save|escape}</span></button>
    </div>
</form>

</div>
</div>
</div>

    </div>
</div>
</div>
