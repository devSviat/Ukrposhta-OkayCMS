{if $up_order_delivery}
    <tr valign="top">
        <td class="es-p5t es-p5b" width="180px"><span>{$lang->sviat__ukrposhta__order_city|escape}:</span></td>
        <td class="es-p5t es-p5b"><span>{$up_order_delivery.city|escape}</span></td>
    </tr>
    <tr valign="top">
        <td class="es-p5t es-p5b" width="180px"><span>{$lang->sviat__ukrposhta__order_point|escape}:</span></td>
        <td class="es-p5t es-p5b"><span>{$up_order_delivery.point|escape}</span></td>
    </tr>
    {if $up_order_delivery.codFee && $up_order_delivery.codFeePublic}
    <tr valign="top">
        <td class="es-p5t es-p5b" width="180px"><span>{$lang->sviat__ukrposhta__order_cod_fee|escape}:</span></td>
        <td class="es-p5t es-p5b"><span>{$up_order_delivery.codFee|convert:$currency->id} {$currency->sign|escape}</span></td>
    </tr>
    {/if}
    {if $up_order_delivery.priceUnconfirmed}
    <tr valign="top">
        <td class="es-p5t es-p5b" width="180px"><span>{$lang->sviat__ukrposhta__order_delivery_cost|escape}:</span></td>
        <td class="es-p5t es-p5b"><span>{$lang->sviat__ukrposhta__cart_price_by_manager|escape}</span></td>
    </tr>
    {/if}
{/if}
