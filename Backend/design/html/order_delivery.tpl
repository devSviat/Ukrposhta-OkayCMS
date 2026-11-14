{if $up_order_point || $up_order_delivery}
{$p = $up_order_point}
{$d = $up_order_delivery}
<div class="up-op mb-1{if $p} fn_up_order_point{/if}"{if $p} data-url="{$smarty.server.SCRIPT_NAME|escape}?controller=Sviat.Ukrposhta.OrderPointAdmin" data-order="{$p.orderId|escape}" data-token="{$smarty.session.id|escape}" data-currency="{$p.currency|escape}" data-city-id="{$p.cityId|escape}" data-city-name="{$p.cityName|escape}" data-office="{$p.officeId|escape}" data-texts="{$p.textsJson|escape}" data-open="{if $p.needsChoice && !$p.locked}1{/if}"{/if}>
    <div class="up-op__head">
        <span class="heading_label">{$btr->sviat__ukrposhta__order_title|escape}</span>
        {if $p && !$p.locked}<button type="button" class="up-op__link fn_up_op_change"{if $p.needsChoice} hidden{/if}>{$btr->sviat__ukrposhta__op_change|escape}</button>{/if}
    </div>
    {if $p && $p.locked}<div class="up-op__hint mb-h">{$btr->sviat__ukrposhta__op_locked|escape}</div>{/if}
    {if $p && $p.needsChoice}
        <div class="alert alert--warning mb-h fn_up_op_needs"><div class="alert__content"><p>{$btr->sviat__ukrposhta__op_needs_point|escape}</p></div></div>
    {/if}
    {* Редактор після збереження перемальовує цей блок сам, з тих самих рядків OrderDeliveryView. *}
    <div class="up-op__summary">
    {if $d}
        {if $d.priceUnconfirmed}
            <div class="alert alert--icon alert--warning mb-h"><div class="alert__content"><p>{$btr->sviat__ukrposhta__order_price_fallback|escape}</p></div></div>
        {/if}
        <div class="up-op__row"><span class="up-op__label">{$btr->sviat__ukrposhta__order_city|escape}</span>{$d.city|escape}</div>
        <div class="up-op__row"><span class="up-op__label">{$btr->sviat__ukrposhta__order_point|escape}</span>{$d.point|escape}</div>
        {if $d.codFee}
            <div class="up-op__row"><span class="up-op__label">{$btr->sviat__ukrposhta__order_cod_fee|escape}</span>{$d.codFee|string_format:'%.2f'|escape} {$currency->sign|escape}{if !$d.codFeePublic}<span class="up-op__hint">{$btr->sviat__ukrposhta__order_cod_fee_hidden|escape}</span>{/if}</div>
        {/if}
    {/if}
    </div>
    {if $p}
        <div class="up-op__message" role="status"></div>
        <div class="up-op__editor" hidden></div>
    {/if}
</div>
{/if}
