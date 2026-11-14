{* Блок відправлення Укрпошти. Він усередині форми замовлення, тож поля мають data-field замість name:
   збереження замовлення не повинне їх надсилати. Дії йдуть у ShipmentAdmin окремими POST. *}
{if $up_shipment}
{$s = $up_shipment}
{function name=up_ship_error field=''}<span class="up-ship__error" data-for="{$field|escape}">{if isset($s.issues[$field])}{$btr->getTranslation($s.issues[$field])|escape}{/if}</span>{/function}
{function name=up_ship_attach}
<div class="up-ship__attach" hidden>
    <div class="up-ship__row">
        <input class="form-control" data-attach="barcode" maxlength="20" autocomplete="off" spellcheck="false" placeholder="{$btr->sviat__ukrposhta__ship_attach_placeholder|escape}" aria-label="{$btr->sviat__ukrposhta__ship_attach_placeholder|escape}">
        <button type="button" class="btn btn_small btn_border_blue fn_up_ship_attach">{$btr->sviat__ukrposhta__ship_attach|escape}</button>
    </div>
    <div class="up-ship__issues up-ship__issues--attach" role="alert"></div>
</div>
{/function}
<div class="up-ship fn_up_shipment{if $s.mode == 'card'} up-ship--card{/if}" data-url="{$smarty.server.SCRIPT_NAME|escape}?controller=Sviat.Ukrposhta.ShipmentAdmin" data-order="{$s.orderId|escape}" data-token="{$smarty.session.id|escape}"
     data-confirm-remove="{$btr->sviat__ukrposhta__ship_confirm_remove|escape}" data-confirm-unlink="{$btr->sviat__ukrposhta__ship_confirm_unlink|escape}" data-confirm-unlink-check="{$btr->sviat__ukrposhta__ship_confirm_unlink_check|escape}"
     data-busy="{$btr->sviat__ukrposhta__ship_creating|escape}" data-failed="{$btr->sviat__ukrposhta__ship_failed|escape}" data-details="{$btr->sviat__ukrposhta__ship_details|escape}" data-network="{$btr->sviat__ukrposhta__op_network|escape}" data-expired="{$btr->sviat__ukrposhta__ship_err_session|escape}" data-unexpected="{$btr->sviat__ukrposhta__ship_err_unexpected|escape}"
     data-toast-ok="{$btr->toastr_success|escape}" data-toast-error="{$btr->toastr_error|escape}" data-order-text="{$btr->sviat__ukrposhta__ship_open_order|escape}"
     data-done-create="{$btr->sviat__ukrposhta__ship_done_create|escape}" data-done-attach="{$btr->sviat__ukrposhta__ship_done_attach|escape}" data-done-lookup="{$btr->sviat__ukrposhta__ship_lookup_found|escape}" data-done-remove="{$btr->sviat__ukrposhta__ship_done_remove|escape}" data-done-unlink="{$btr->sviat__ukrposhta__ship_done_unlink|escape}" data-done-refresh="{$btr->sviat__ukrposhta__ship_done_refresh|escape}">
    <div class="up-ship__head">
        <span class="{if $s.mode == 'card'}heading_box{else}heading_label{/if}">{if $s.mode == 'form'}{$btr->sviat__ukrposhta__ship_form_title|escape}{else}{$btr->sviat__ukrposhta__ship_title|escape}{/if}</span>
    </div>

    {if $s.auth}
        <div class="alert alert--error up-ship__auth" role="alert"><div class="alert__content">
            <p class="text_600">{$btr->sviat__ukrposhta__auth_title|escape}</p>
            {foreach $s.auth as $up_failure}
                <p>{$btr->getTranslation('sviat__ukrposhta__keys_status_'|cat:$up_failure.reason)|escape}. {$btr->getTranslation('sviat__ukrposhta__auth_'|cat:$up_failure.api)|escape}</p>
            {/foreach}
            <p class="text_grey">{$btr->sviat__ukrposhta__ship_advice_key|escape}</p>
        </div></div>
    {/if}
    <div class="up-ship__notices" role="status"></div>

    {if $s.mode == 'check'}
        <div class="alert alert--warning"><div class="alert__content"><p>{$btr->sviat__ukrposhta__ship_check_text|escape}</p></div></div>
        <div class="up-ship__actions">
            <button type="button" class="btn btn_small btn_blue fn_up_ship_lookup">{$btr->sviat__ukrposhta__ship_lookup|escape}</button>
            <button type="button" class="btn btn_small btn_border_blue fn_up_ship_attach_toggle" aria-expanded="false">{$btr->sviat__ukrposhta__ship_attach_open|escape}</button>
        </div>
        {up_ship_attach}
        <div class="up-ship__issues" role="alert"></div>
        <div class="up-ship__unlink">
            <p class="text_grey">{$btr->sviat__ukrposhta__ship_unlink_check_warning|escape}</p>
            <button type="button" class="btn btn_small btn_border_blue fn_up_ship_unlink" data-uncertain="1">{$btr->sviat__ukrposhta__ship_unlink|escape}</button>
        </div>
    {elseif $s.mode == 'pending' || $s.mode == 'refusal'}
        <div class="alert alert--{if $s.mode == 'pending'}info{else}warning{/if}"><div class="alert__content"><p>{$btr->getTranslation($s.refusalKey)|escape}</p></div></div>
        {if isset($s.can.attach)}
            <div class="up-ship__actions"><button type="button" class="btn btn_small btn_border_blue fn_up_ship_attach_toggle" aria-expanded="false">{$btr->sviat__ukrposhta__ship_attach_open|escape}</button></div>
            {up_ship_attach}
        {/if}
        <div class="up-ship__issues" role="alert"></div>
    {elseif $s.mode == 'form'}
        {$f = $s.form}
        <div class="up-ship__form">
            <div class="up-ship__caps">{$btr->sviat__ukrposhta__ship_recipient|escape}</div>
            <div class="up-ship__grid">
                <label class="up-ship__field"><span class="heading_label">{$btr->sviat__ukrposhta__ship_last_name|escape}</span><input class="form-control" data-field="last_name" maxlength="250" value="{$f.lastName|escape}"></label>
                <label class="up-ship__field"><span class="heading_label">{$btr->sviat__ukrposhta__ship_first_name|escape}</span><input class="form-control" data-field="first_name" maxlength="250" value="{$f.firstName|escape}"></label>
                <label class="up-ship__field"><span class="heading_label">{$btr->sviat__ukrposhta__ship_middle_name|escape}</span><input class="form-control" data-field="middle_name" maxlength="250" value="{$f.middleName|escape}"></label>
                <label class="up-ship__field"><span class="heading_label">{$btr->sviat__ukrposhta__ship_phone|escape}</span><input class="form-control" data-field="phone" maxlength="20" inputmode="tel" value="{$f.phone|escape}"></label>
            </div>
            {up_ship_error field='name'}{up_ship_error field='phone'}
            <div class="up-ship__line"><span class="up-ship__label">{$btr->sviat__ukrposhta__ship_postcode|escape}</span>{$f.officePostcode|escape}</div>

            <div class="up-ship__caps">{$btr->sviat__ukrposhta__ship_parcel|escape}</div>
            <div class="up-ship__grid">
                <label class="up-ship__field"><span class="heading_label">{$btr->sviat__ukrposhta__shipping_type|escape}</span><select class="form-control" data-field="type">
                    <option value="STANDARD" {if $f.type == 'STANDARD'}selected{/if}>{$btr->sviat__ukrposhta__shipping_type_standard|escape}</option>
                    <option value="EXPRESS" {if $f.type == 'EXPRESS'}selected{/if}>{$btr->sviat__ukrposhta__shipping_type_express|escape}</option>
                </select></label>
                <label class="up-ship__field"><span class="heading_label">{$btr->sviat__ukrposhta__ship_weight|escape}</span><input class="form-control" data-field="weight" inputmode="numeric" value="{$f.weight|escape}"></label>
            </div>
            {up_ship_error field='type'}{up_ship_error field='weight'}
            <span class="heading_label">{$btr->sviat__ukrposhta__shipping_box_size|escape}</span>
            <div class="up-ship__grid up-ship__grid--three">
                <input class="form-control" data-field="length" inputmode="numeric" value="{$f.length|escape}" aria-label="{$btr->sviat__ukrposhta__shipping_length|escape}" title="{$btr->sviat__ukrposhta__shipping_length|escape}">
                <input class="form-control" data-field="width" inputmode="numeric" value="{$f.width|escape}" aria-label="{$btr->sviat__ukrposhta__shipping_width|escape}" title="{$btr->sviat__ukrposhta__shipping_width|escape}">
                <input class="form-control" data-field="height" inputmode="numeric" value="{$f.height|escape}" aria-label="{$btr->sviat__ukrposhta__shipping_height|escape}" title="{$btr->sviat__ukrposhta__shipping_height|escape}">
            </div>
            {up_ship_error field='sides'}
            <label class="up-ship__field"><span class="heading_label">{$btr->sviat__ukrposhta__ship_declared|escape}</span><input class="form-control" data-field="declared_price" inputmode="decimal" value="{$f.declared|escape}"></label>
            {up_ship_error field='declared_price'}

            {if $f.paymentIsCod}
                <div class="up-ship__cod">
                    <label class="up-ship__switch">
                        <span class="switch switch-default"><input class="switch-input" type="checkbox" data-field="cod" value="1" {if $f.cod}checked{/if}><span class="switch-label"></span><span class="switch-handle"></span></span>
                        <span>{$btr->sviat__ukrposhta__ship_cod|escape}</span>
                    </label>
                    {up_ship_error field='cod'}
                    {* Тут Checkbox попереджає, що частину замовлення передплачено: тому сума менша за замовлення. *}
                    {get_design_block block="ukrposhta_shipment_payment"}
                    <div class="up-ship__grid fn_up_ship_cod"{if !$f.cod} hidden{/if}>
                        <label class="up-ship__field"><span class="heading_label">{$btr->sviat__ukrposhta__ship_cod_amount|escape}</span><input class="form-control" data-field="cod_amount" inputmode="decimal" value="{$f.codAmount|escape}"></label>
                        <label class="up-ship__field"><span class="heading_label">{$btr->sviat__ukrposhta__shipping_cod_payer|escape}</span><select class="form-control" data-field="cod_payer">
                            <option value="recipient" {if $f.codPayer == 'recipient'}selected{/if}>{$btr->sviat__ukrposhta__party_recipient|escape}</option>
                            <option value="sender" {if $f.codPayer == 'sender'}selected{/if}>{$btr->sviat__ukrposhta__party_sender|escape}</option>
                        </select></label>
                    </div>
                    {up_ship_error field='cod_amount'}
                </div>
            {/if}

            <div class="up-ship__grid">
                <label class="up-ship__field"><span class="heading_label">{$btr->sviat__ukrposhta__shipping_payer|escape}</span><select class="form-control" data-field="payer"{if $f.toLocker} disabled{/if}>
                    <option value="recipient" {if $f.payer == 'recipient'}selected{/if}>{$btr->sviat__ukrposhta__party_recipient|escape}</option>
                    <option value="sender" {if $f.payer == 'sender'}selected{/if}>{$btr->sviat__ukrposhta__party_sender|escape}</option>
                </select></label>
                <label class="up-ship__field"><span class="heading_label">{$btr->sviat__ukrposhta__shipping_on_fail|escape}</span><select class="form-control" data-field="on_fail">
                    <option value="RETURN" {if $f.onFail == 'RETURN'}selected{/if}>{$btr->sviat__ukrposhta__shipping_on_fail_return|escape}</option>
                    <option value="PROCESS_AS_REFUSAL" {if $f.onFail == 'PROCESS_AS_REFUSAL'}selected{/if}>{$btr->sviat__ukrposhta__shipping_on_fail_refusal|escape}</option>
                </select></label>
            </div>
            {if $f.toLocker}<p class="text_grey up-ship__note">{$btr->sviat__ukrposhta__ship_locker_payer|escape}</p>{/if}
            {up_ship_error field='on_fail'}
            <label class="up-ship__field"><span class="heading_label">{$btr->sviat__ukrposhta__shipping_description|escape}</span><input class="form-control" data-field="description" maxlength="255" required value="{$f.description|escape}"></label>
            {up_ship_error field='description'}
            <label class="up-ship__switch">
                <span class="switch switch-default"><input class="switch-input" type="checkbox" data-field="sms" value="1" {if $f.sms}checked{/if}><span class="switch-label"></span><span class="switch-handle"></span></span>
                <span>{$btr->sviat__ukrposhta__shipping_sms|escape}</span>
            </label>
        </div>
        <div class="up-ship__issues" role="alert"></div>
        <div class="up-ship__actions">
            <button type="button" class="btn btn_small btn_blue fn_up_ship_create">{$btr->sviat__ukrposhta__ship_create|escape}</button>
            <button type="button" class="btn btn_small btn_border_blue fn_up_ship_attach_toggle" aria-expanded="false">{$btr->sviat__ukrposhta__ship_attach_open|escape}</button>
        </div>
        {up_ship_attach}
    {else}
        {$c = $s.card}
        <div class="up-ship__card">
            <div class="up-ship__top">
                <span class="up-ship__barcode">
                    <button type="button" class="up-ship__copy fn_up_ship_copy" data-copy="{$c.barcode|escape}" data-copied="{$btr->sviat__ukrposhta__ship_copied|escape}" title="{$btr->sviat__ukrposhta__ship_copy|escape}">{$c.barcode|escape}</button>
                </span>
                {if $c.statusKey}<span class="up-ship__badge up-ship__badge--{$c.statusGroup|escape}">{$btr->getTranslation($c.statusKey)|escape}</span>{/if}
            </div>
            <div class="up-ship__body">
                <div>
                    {if $c.eventName}<div class="up-ship__line"><span class="up-ship__label">{$btr->sviat__ukrposhta__ship_event|escape}</span>{$c.eventName|escape}{if $c.eventAt}, {$c.eventAt|escape}{/if}</div>{/if}
                    {if $c.created}<div class="up-ship__line"><span class="up-ship__label">{$btr->sviat__ukrposhta__ship_created_at|escape}</span>{$c.created|escape}</div>{/if}
                    <div class="up-ship__line text_grey">{if $c.checked}{$btr->sviat__ukrposhta__ship_checked_at|escape} {$c.checked|escape}{else}{$btr->sviat__ukrposhta__ship_not_checked|escape}{/if}</div>
                </div>
                <div>
                    {if $c.deliveryPrice}<div class="up-ship__line"><span class="up-ship__label">{$btr->sviat__ukrposhta__ship_delivery_price|escape}</span><b>{$c.deliveryPrice|escape} ₴</b>{if $c.payerKey} · {$btr->sviat__ukrposhta__ship_pays|escape} {$btr->getTranslation($c.payerKey)|escape}{/if}</div>{/if}
                    {if $c.codAmount}<div class="up-ship__line"><span class="up-ship__label">{$btr->sviat__ukrposhta__ship_cod_amount|escape}</span><b>{$c.codAmount|escape} ₴</b></div>{/if}
                    {if $c.codFee}<div class="up-ship__line"><span class="up-ship__label">{$btr->sviat__ukrposhta__ship_cod_fee|escape}</span>{$c.codFee|escape} ₴</div>{/if}
                </div>
            </div>
            <div class="up-ship__issues" role="alert"></div>
            <div class="up-ship__actions">
                <select class="form-control up-ship__size" data-label="size" aria-label="{$btr->sviat__ukrposhta__shipping_label_size|escape}">
                    <option value="SIZE_10X10" {if $s.labelSize == 'SIZE_10X10'}selected{/if}>{$btr->sviat__ukrposhta__label_size_10x10|escape}</option>
                    <option value="SIZE_A4" {if $s.labelSize == 'SIZE_A4'}selected{/if}>{$btr->sviat__ukrposhta__label_size_a4|escape}</option>
                </select>
                <button type="button" class="btn btn_small btn_blue fn_up_ship_label">{$btr->sviat__ukrposhta__ship_label|escape}</button>
                <button type="button" class="btn btn_small btn_border_blue fn_up_ship_refresh">{$btr->sviat__ukrposhta__ship_refresh|escape}</button>
                {if isset($s.can.remove)}<button type="button" class="btn btn_small btn-danger fn_up_ship_remove">{$btr->sviat__ukrposhta__ship_remove|escape}</button>{/if}
                <button type="button" class="btn btn_small btn_border_blue fn_up_ship_unlink">{$btr->sviat__ukrposhta__ship_unlink|escape}</button>
            </div>
        </div>
    {/if}
</div>
{/if}
