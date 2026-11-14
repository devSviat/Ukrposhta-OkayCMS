{* Сторінка способу оплати, «Додаткові налаштування»: післяплата Укрпошти. Показується лише для оплат "Manual". *}
<div class="activity_of_switch_item up-codpay{if !$up_codpay.manual} hidden{/if}">
    <input type="hidden" name="sviat_up_cod_form" value="1">
    <div class="okay_switch clearfix">
        <label class="switch_label">
            {$btr->sviat__ukrposhta__codpay_title|escape}
            <i class="fn_tooltips" title="{$btr->sviat__ukrposhta__codpay_tip|escape}">{include file='svg_icon.tpl' svgId='icon_tooltips'}</i>
        </label>
        <label class="switch switch-default">
            <input class="switch-input" name="sviat_up_cod" value="1" type="checkbox" {if $up_codpay.on}checked=""{/if}/>
            <span class="switch-label"></span>
            <span class="switch-handle"></span>
        </label>
    </div>
</div>
