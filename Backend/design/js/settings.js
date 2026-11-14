/* Сторінка налаштувань Укрпошти. Файл підключається на всіх сторінках адмінки, тож без #up_settings нічого не робить.
   Усе, що приходить із сервера, потрапляє на сторінку лише через textContent. */
(function () {
    'use strict';

    var DEBOUNCE_MS = 250;
    var MIN_CITY_QUERY = 2;

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    /** Списки на сторінці — bootstrap-select: без refresh він показує старі пункти. */
    function refreshPicker(select) {
        if (window.jQuery && window.jQuery.fn.selectpicker) {
            window.jQuery(select).selectpicker('refresh');
        }
    }

    function getJson(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        });
    }

    function cityItem(city) {
        var item = el('li');
        item.setAttribute('role', 'option');
        if (city.type) {
            item.appendChild(el('span', 'text_grey', city.type + ' '));
        }
        item.appendChild(el('b', null, city.name));
        var where = [city.district, city.region].filter(Boolean).join(', ');
        if (where) {
            item.appendChild(el('span', 'up-set__where', where));
        }
        return item;
    }

    /** Поле пошуку міста з підказками; onPick отримує обране місто. */
    function citySearch(base, input, list, onPick) {
        var timer = null;
        var request = null;

        function close() {
            list.hidden = true;
            list.textContent = '';
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            var query = input.value.trim();
            if (query.length < MIN_CITY_QUERY) {
                close();
                return;
            }
            timer = setTimeout(function () {
                if (request) {
                    request.abort();
                }
                request = new AbortController();
                fetch(base + 'searchCities&q=' + encodeURIComponent(query), { credentials: 'same-origin', signal: request.signal })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        list.textContent = '';
                        (data.cities || []).forEach(function (city) {
                            var item = cityItem(city);
                            item.addEventListener('mousedown', function (event) {
                                event.preventDefault();
                                onPick(city);
                                close();
                            });
                            list.appendChild(item);
                        });
                        list.hidden = list.children.length === 0;
                    })
                    .catch(function () {});
            }, DEBOUNCE_MS);
        });
        input.addEventListener('blur', function () { setTimeout(close, 150); });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                var first = list.querySelector('li');
                if (first) {
                    first.dispatchEvent(new Event('mousedown'));
                }
            } else if (event.key === 'Escape') {
                close();
            }
        });
    }

    function keysForm() {
        var form = document.getElementById('up_keys_form');
        var check = form.querySelector('button[name="check"]');
        var reason = document.getElementById('up_keys_check_reason');
        // Перевірка спершу зберігає форму, тож вставлений, але ще не збережений ключ теж можна перевірити.
        if (reason) {
            form.querySelectorAll('input[type="password"]').forEach(function (input) {
                input.addEventListener('input', function () {
                    var typed = Array.prototype.some.call(form.querySelectorAll('input[type="password"]'), function (field) {
                        return field.value.trim() !== '';
                    });
                    check.disabled = !typed;
                    reason.hidden = typed;
                });
            });
        }
        form.addEventListener('submit', function (event) {
            var pressed = event.submitter;
            // Вимкнена кнопка не надсилається: її name/value переносимо в приховане поле.
            if (pressed && pressed.name) {
                var flag = el('input');
                flag.type = 'hidden';
                flag.name = pressed.name;
                flag.value = pressed.value;
                form.appendChild(flag);
            }
            if (pressed && pressed.getAttribute('data-up-busy')) {
                pressed.querySelector('span').textContent = pressed.getAttribute('data-up-busy');
            }
            form.querySelectorAll('button[type=submit]').forEach(function (button) {
                button.disabled = true;
            });
        });
    }

    function senderForm(base) {
        var form = document.getElementById('up_sender_form');
        var blocks = form.querySelectorAll('[data-up-sender]');
        form.querySelectorAll('input[name="sender_type"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                blocks.forEach(function (block) {
                    var inactive = block.getAttribute('data-up-sender') !== radio.value;
                    block.hidden = inactive;
                    block.disabled = inactive;
                });
            });
        });

        var box = document.getElementById('up_sender_city');
        var chosen = box.querySelector('.up-set__city-chosen');
        var search = box.querySelector('.up-set__search');
        var input = box.querySelector('.up-set__city-input');
        var select = document.getElementById('up_sender_postcode');

        box.querySelector('.up-set__city-change').addEventListener('click', function () {
            chosen.hidden = true;
            search.hidden = false;
            input.value = '';
            input.focus();
        });

        citySearch(base, input, search.querySelector('.up-set__suggest'), function (city) {
            var name = chosen.querySelector('.up-set__city-name');
            name.textContent = '';
            if (city.type) {
                name.appendChild(el('span', 'text_grey', city.type + ' '));
            }
            name.appendChild(el('b', null, city.name));
            chosen.hidden = false;
            search.hidden = true;
            getJson(base + 'senderOffices&city_id=' + encodeURIComponent(city.id))
                .then(function (data) {
                    while (select.options.length > 1) {
                        select.remove(1);
                    }
                    (data.offices || []).forEach(function (office) {
                        var option = el('option', null, office.title);
                        option.value = office.postcode;
                        select.appendChild(option);
                    });
                    select.value = '';
                    refreshPicker(select);
                })
                .catch(function () {});
        });
    }

    function popularForm(base) {
        var rows = document.getElementById('up_popular_rows');
        var empty = document.querySelector('.up-set__popular-empty');
        var box = document.getElementById('up_popular_search');
        var input = box.querySelector('.up-set__city-input');
        var template = document.getElementById('up_popular_template');

        function refreshEmpty() {
            empty.hidden = rows.querySelector('.up-set__popular') !== null;
        }

        rows.addEventListener('click', function (event) {
            var button = event.target.closest('.up-set__remove');
            if (button) {
                button.closest('.up-set__popular').remove();
                refreshEmpty();
            }
        });

        citySearch(base, input, box.querySelector('.up-set__suggest'), function (city) {
            input.value = '';
            var exists = Array.prototype.some.call(rows.querySelectorAll('input[name="popular[]"]'), function (hidden) {
                return hidden.value === String(city.id);
            });
            if (exists) {
                return;
            }
            var row = template.content.firstElementChild.cloneNode(true);
            row.querySelector('input').value = city.id;
            row.querySelector('b').textContent = city.name;
            row.querySelector('.text_grey').textContent = city.region || '';
            rows.appendChild(row);
            refreshEmpty();
        });
    }

    function groupsForm() {
        var form = document.getElementById('up_groups_form');
        var rows = document.getElementById('up_group_rows');
        var newRow = rows.querySelector('.up-set__group--new');
        var newName = newRow.querySelector('[data-field="name"]');
        var addButton = document.getElementById('up_group_add');
        var selects = form.querySelectorAll('select.up-set__kind-group');

        function newOptions(callback) {
            selects.forEach(function (select) {
                callback(select, select.querySelector('option[value="new"]'));
            });
        }

        addButton.addEventListener('click', function () {
            newRow.hidden = false;
            addButton.hidden = true;
            newOptions(function (select, option) {
                if (!option) {
                    option = el('option', null, newName.value.trim());
                    option.value = 'new';
                    select.appendChild(option);
                }
                refreshPicker(select);
            });
            newName.focus();
        });

        newName.addEventListener('input', function () {
            newOptions(function (select, option) {
                if (option) {
                    option.textContent = newName.value.trim();
                    refreshPicker(select);
                }
            });
        });

        // Видалення спрацьовує після «Зберегти»; до того сторінку можна перезавантажити, і група повернеться.
        rows.addEventListener('click', function (event) {
            var button = event.target.closest('.up-set__group-remove');
            if (!button) {
                return;
            }
            var row = button.closest('.up-set__group');
            if (row === newRow) {
                newRow.hidden = true;
                newName.value = '';
                newOptions(function (select, option) {
                    if (option) {
                        option.remove();
                        refreshPicker(select);
                    }
                });
                addButton.hidden = false;
                return;
            }
            row.classList.add('up-set__group--deleted');
            row.querySelector('[data-field="delete"]').value = '1';
        });

        // Імена полів — у порядку на сторінці, тож перетягнута група зберігає нову позицію.
        form.addEventListener('submit', function () {
            var index = 0;
            rows.querySelectorAll('.up-set__group').forEach(function (row) {
                if (row === newRow && row.hidden) {
                    return;
                }
                row.querySelectorAll('[data-field]').forEach(function (field) {
                    field.name = 'groups[' + index + '][' + field.getAttribute('data-field') + ']';
                });
                index++;
            });
        });
    }

    function shippingForm() {
        var mode = document.getElementById('up_description_mode');
        var text = document.getElementById('up_description');
        window.jQuery(mode).on('changed.bs.select change', function () {
            text.hidden = mode.value !== 'text';
            text.required = !text.hidden;
            if (!text.hidden) {
                text.focus();
            }
        });
    }

    ready(function () {
        var root = document.getElementById('up_settings');
        if (!root) {
            return;
        }
        var base = root.getAttribute('data-base');
        keysForm();
        senderForm(base);
        popularForm(base);
        groupsForm();
        shippingForm();
    });
})();
