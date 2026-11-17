{if !empty($order->up_shipment)}
    {$us = $order->up_shipment}
    <div class="up-list-status">
        <svg class="up-list-status__logo" width="16" height="16" viewBox="54.75 53.25 404.5 404.5" aria-hidden="true"><path fill="#ffbe0c" d="M435 87C435 87 367.333 275.131 343.606 326.5C319.878 377.868 279.409 424 210.669 424C137.95 424 79 365.803 79 294.014C79 234.339 119.733 184.059 175.248 168.788L209.83 245.945C183.321 246.387 161.97 267.738 161.97 294.014C161.97 320.567 183.773 342.091 210.669 342.091C237.565 342.091 258.744 320.567 258.744 294.014C258.744 281.549 254.704 270.527 250.351 261.072C245.997 251.619 172.613 87 172.613 87H435Z"/></svg>
        {if $us.barcode}<a href="" class="up-list-status__barcode fn_up_list_copy hint-bottom-middle-t-info-s-small-mobile hint-anim" data-hint="{$btr->sviat__ukrposhta__list_copy|escape}" data-copy-string="{$us.barcode|escape}">{$us.barcode|escape}</a>{/if}
        {if $us.statusKey}<span class="up-ship__badge up-ship__badge--{$us.group|escape}{if $us.eventName} hint-bottom-middle-t-info-s-small-mobile hint-anim{/if}"{if $us.eventName} data-hint="{$us.eventName|escape}"{/if}>{$btr->getTranslation($us.statusKey)|escape}</span>{/if}
    </div>
{/if}
