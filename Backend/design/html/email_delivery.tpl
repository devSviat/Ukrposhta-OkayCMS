{if $up_order_delivery}
    <tr valign="top">
        <td class="es-p5t es-p5b" width="180px"><span>{$btr->sviat__ukrposhta__order_city|escape}:</span></td>
        <td class="es-p5t es-p5b"><span>{$up_order_delivery.city|escape}</span></td>
    </tr>
    <tr valign="top">
        <td class="es-p5t es-p5b" width="180px"><span>{$btr->sviat__ukrposhta__order_point|escape}:</span></td>
        <td class="es-p5t es-p5b"><span>{$up_order_delivery.point|escape}</span></td>
    </tr>
    {if $up_order_delivery.codFee}
    <tr valign="top">
        <td class="es-p5t es-p5b" width="180px"><span>{$btr->sviat__ukrposhta__order_cod_fee|escape}:</span></td>
        <td class="es-p5t es-p5b"><span>{$up_order_delivery.codFee|convert:$currency->id} {$currency->sign|escape}</span></td>
    </tr>
    {/if}
    {if $up_order_delivery.priceUnconfirmed}
    <tr valign="top">
        <td class="es-p5t es-p5b" colspan="2"><span style="color:#a35a00;">{$btr->sviat__ukrposhta__order_price_fallback|escape}</span></td>
    </tr>
    {/if}
{/if}
