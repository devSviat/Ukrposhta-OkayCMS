// Сторінка способу оплати: ядро показує налаштування платіжного модуля при зміні select[name=module];
// післяплата стосується лише оплат "Manual" (сервер однаково зберігає 0 для будь-якого модуля).
$(document).on('change', 'select[name=module]', function () {
    var manual = $(this).val() === 'null' || $(this).val() === '';
    $('.up-codpay').toggleClass('hidden', !manual);
});
