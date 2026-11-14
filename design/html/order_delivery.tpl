{if $up_order_delivery}
    {if $up_order_family == 'vibe'}
        <dt><span data-language="sviat__ukrposhta__order_city">{$lang->sviat__ukrposhta__order_city|escape}</span></dt>
        <dd>{$up_order_delivery.city|escape}</dd>
        <dt><span data-language="sviat__ukrposhta__order_point">{$lang->sviat__ukrposhta__order_point|escape}</span></dt>
        <dd>{$up_order_delivery.point|escape}</dd>
        {if $up_order_delivery.priceUnconfirmed}
            <dt><span data-language="sviat__ukrposhta__order_delivery_cost">{$lang->sviat__ukrposhta__order_delivery_cost|escape}</span></dt>
            <dd>{$lang->sviat__ukrposhta__cart_price_by_manager|escape}</dd>
        {/if}
        {if $up_order_delivery.codFee && $up_order_delivery.codFeePublic}
            <dt><span data-language="sviat__ukrposhta__order_cod_fee">{$lang->sviat__ukrposhta__order_cod_fee|escape}</span></dt>
            <dd>{$up_order_delivery.codFee|convert} {$currency->sign|escape}</dd>
        {/if}
    {else}
        <tr>
            <td><span data-language="sviat__ukrposhta__order_city">{$lang->sviat__ukrposhta__order_city|escape}</span></td>
            <td>{$up_order_delivery.city|escape}</td>
        </tr>
        <tr>
            <td><span data-language="sviat__ukrposhta__order_point">{$lang->sviat__ukrposhta__order_point|escape}</span></td>
            <td>{$up_order_delivery.point|escape}</td>
        </tr>
        {if $up_order_delivery.priceUnconfirmed}
            <tr>
                <td><span data-language="sviat__ukrposhta__order_delivery_cost">{$lang->sviat__ukrposhta__order_delivery_cost|escape}</span></td>
                <td>{$lang->sviat__ukrposhta__cart_price_by_manager|escape}</td>
            </tr>
        {/if}
        {if $up_order_delivery.codFee && $up_order_delivery.codFeePublic}
            <tr>
                <td><span data-language="sviat__ukrposhta__order_cod_fee">{$lang->sviat__ukrposhta__order_cod_fee|escape}</span></td>
                <td>{$up_order_delivery.codFee|convert} {$currency->sign|escape}</td>
            </tr>
        {/if}
    {/if}
{/if}
