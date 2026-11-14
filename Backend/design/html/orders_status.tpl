{if !empty($order->up_shipment)}
    {$us = $order->up_shipment}
    <div class="up-list-status">
        {if $us.barcode}<a href="" class="up-list-status__barcode fn_up_list_copy hint-bottom-middle-t-info-s-small-mobile hint-anim" data-hint="{$btr->sviat__ukrposhta__list_copy|escape}" data-copy-string="{$us.barcode|escape}">{$us.barcode|escape}</a>{/if}
        {if $us.statusKey}<span class="up-ship__badge up-ship__badge--{$us.group|escape}{if $us.eventName} hint-bottom-middle-t-info-s-small-mobile hint-anim{/if}"{if $us.eventName} data-hint="{$us.eventName|escape}"{/if}>{$btr->getTranslation($us.statusKey)|escape}</span>{/if}
    </div>
{/if}
