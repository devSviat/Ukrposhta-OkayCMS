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
        <div class="up-card">
            <div class="up-card__top">
                {if $c.created}<span class="up-card__muted">{$btr->sviat__ukrposhta__ship_created_at|escape} {$c.created|escape}</span>{/if}
                <div class="up-card__head">
                    <svg class="up-card__logo" width="20.07" height="19" viewBox="79 87 356 337" aria-hidden="true"><path fill="#ffbe0c" d="M435 87C435 87 367.333 275.131 343.606 326.5C319.878 377.868 279.409 424 210.669 424C137.95 424 79 365.803 79 294.014C79 234.339 119.733 184.059 175.248 168.788L209.83 245.945C183.321 246.387 161.97 267.738 161.97 294.014C161.97 320.567 183.773 342.091 210.669 342.091C237.565 342.091 258.744 320.567 258.744 294.014C258.744 281.549 254.704 270.527 250.351 261.072C245.997 251.619 172.613 87 172.613 87H435Z"/></svg>
                    <a href="" class="up-card__number fn_clipboard fn_up_copy hint-bottom-middle-t-info-s-small-mobile hint-anim" data-hint="{$btr->sviat__ukrposhta__ship_copy|escape}" data-hint-copied="{$btr->sviat__ukrposhta__ship_copied|escape}" data-copy-string="{$c.barcode|escape}">{$c.barcode|escape}</a>
                    {if $c.statusKey}<span class="up-ship__badge up-ship__badge--{$c.statusGroup|escape}">{$btr->getTranslation($c.statusKey)|escape}</span>{/if}
                </div>
                <div class="up-card__event">
                    {if $c.event}
                        <b>{$c.event.name|escape}</b>{if $c.event.place}<span class="up-card__dot">{$c.event.place|escape}</span>{/if}{if $c.event.at}<span class="up-card__dot">{$c.event.at|escape}</span>{/if}
                    {elseif $c.checked}
                        <span class="text_grey">{$btr->sviat__ukrposhta__ship_no_events|escape}</span>
                    {else}
                        <span class="text_grey">{$btr->sviat__ukrposhta__ship_not_checked|escape}</span>
                    {/if}
                </div>
            </div>
            <div class="up-card__body">
                <dl class="up-card__facts">
                    {if $c.recipient || $c.phone}<div><dt>{$btr->sviat__ukrposhta__ship_recipient|escape}</dt><dd>{if $c.recipient}{$c.recipient|escape}{/if}{if $c.recipient && $c.phone}<br>{/if}{if $c.phone}{$c.phone|escape}{/if}</dd></div>{/if}
                    {if $c.point}<div><dt>{$btr->sviat__ukrposhta__ship_point|escape}</dt><dd>{$c.point|escape}</dd></div>{/if}
                    {if $c.deliveryPrice}<div><dt>{$btr->sviat__ukrposhta__ship_delivery_price|escape}</dt><dd><b>{$c.deliveryPrice|escape} ₴</b>{if $c.payerKey}<span class="up-card__dot">{$btr->sviat__ukrposhta__ship_pays|escape} {$btr->getTranslation($c.payerKey)|escape}</span>{/if}</dd></div>{/if}
                    {if $c.plannedDate}<div><dt>{$btr->sviat__ukrposhta__ship_planned|escape}</dt><dd>{$c.plannedDate|escape}</dd></div>{/if}
                    {if $c.declared}<div><dt>{$btr->sviat__ukrposhta__ship_declared_value|escape}</dt><dd>{$c.declared|escape} ₴</dd></div>{/if}
                    {if $c.codAmount}<div><dt>{$btr->sviat__ukrposhta__ship_cod|escape}</dt><dd><b>{$c.codAmount|escape} ₴</b>{if $c.codFee}<span class="up-card__dot">{$btr->sviat__ukrposhta__ship_fee|escape} {$c.codFee|escape} ₴</span>{/if}</dd></div>{/if}
                </dl>
            </div>
            {if $c.more || $c.history}
                <div class="up-card__more" hidden><div class="up-card__more-inner">
                    {if $c.more}
                        {$m = $c.more}
                        <dl class="up-card__facts">
                            {if isset($m.typeKey)}<div><dt>{$btr->sviat__ukrposhta__shipping_type|escape}</dt><dd>{$btr->getTranslation($m.typeKey)|escape}</dd></div>{/if}
                            {if isset($m.weight)}<div><dt>{$btr->sviat__ukrposhta__ship_weight|escape}</dt><dd>{$m.weight|escape}</dd></div>{/if}
                            {if isset($m.sides)}<div><dt>{$btr->sviat__ukrposhta__ship_sides|escape}</dt><dd>{$m.sides|escape}</dd></div>{/if}
                            {if isset($m.description)}<div><dt>{$btr->sviat__ukrposhta__shipping_description|escape}</dt><dd>{$m.description|escape}</dd></div>{/if}
                            {if isset($m.onFailKey)}<div><dt>{$btr->sviat__ukrposhta__shipping_on_fail|escape}</dt><dd>{$btr->getTranslation($m.onFailKey)|escape}</dd></div>{/if}
                            {if isset($m.sms)}<div><dt>{$btr->sviat__ukrposhta__shipping_sms|escape}</dt><dd>{if $m.sms}{$btr->sviat__ukrposhta__ship_yes|escape}{else}{$btr->sviat__ukrposhta__ship_no|escape}{/if}</dd></div>{/if}
                        </dl>
                    {/if}
                    {if $c.history}
                        <div class="up-card__caps">{$btr->sviat__ukrposhta__ship_history|escape}</div>
                        <ol class="up-card__history">
                            {foreach $c.history as $up_event}
                                <li><span class="up-card__when">{$up_event.at|escape}</span><span>{$up_event.name|escape}{if $up_event.place}<span class="up-card__dot text_grey">{$up_event.place|escape}</span>{/if}</span></li>
                            {/foreach}
                        </ol>
                    {/if}
                </div></div>
            {/if}
            <div class="up-ship__issues up-card__issues" role="alert"></div>
            <div class="up-card__footer">
                {if $c.more || $c.history}<button type="button" class="up-card__more-toggle fn_up_ship_more" aria-expanded="false" data-more="{$btr->sviat__ukrposhta__ship_more|escape}" data-less="{$btr->sviat__ukrposhta__ship_less|escape}"><span>{$btr->sviat__ukrposhta__ship_more|escape}</span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6l6 -6"/></svg></button>{/if}
                <span class="up-card__tools">
                    {if $c.checked}<span class="up-card__meta">{$btr->sviat__ukrposhta__ship_checked_at|escape} {$c.checked|escape}</span>{/if}
                    <button type="button" class="up-card__icon fn_up_ship_refresh" title="{$btr->sviat__ukrposhta__ship_refresh|escape}" aria-label="{$btr->sviat__ukrposhta__ship_refresh|escape}"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"/><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"/></svg></button>
                    <span class="up-card__sep" aria-hidden="true"></span>
                    <span class="up-card__print">
                        <button type="button" class="btn btn_small btn_blue fn_up_ship_label"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2"/><path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4"/><path d="M7 15a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z"/></svg>{$btr->sviat__ukrposhta__ship_label_short|escape}</button>
                        <select class="form-control up-ship__size" data-label="size" aria-label="{$btr->sviat__ukrposhta__shipping_label_size|escape}">
                            <option value="SIZE_10X10" {if $s.labelSize == 'SIZE_10X10'}selected{/if}>{$btr->sviat__ukrposhta__label_size_10x10|escape}</option>
                            <option value="SIZE_A4" {if $s.labelSize == 'SIZE_A4'}selected{/if}>{$btr->sviat__ukrposhta__label_size_a4|escape}</option>
                        </select>
                    </span>
                    {if isset($s.can.remove)}<button type="button" class="up-card__icon up-card__icon--danger fn_up_ship_remove" title="{$btr->sviat__ukrposhta__ship_remove_title|escape}" aria-label="{$btr->sviat__ukrposhta__ship_remove_title|escape}"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/></svg></button>{/if}
                    <button type="button" class="up-card__icon up-card__icon--danger fn_up_ship_unlink" title="{$btr->sviat__ukrposhta__ship_unlink_title|escape}" aria-label="{$btr->sviat__ukrposhta__ship_unlink_title|escape}"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 15l6 -6"/><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464"/><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463"/><path d="M16 19h5"/></svg></button>
                </span>
            </div>
        </div>
    {/if}
</div>
{/if}
