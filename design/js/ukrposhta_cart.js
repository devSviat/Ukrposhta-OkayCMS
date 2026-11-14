/*
 * Вибір пункту Укрпошти в кошику. Усе, що приходить із довідника, потрапляє на сторінку
 * лише через textContent: назви — сторонні дані.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 200;
    var MIN_CITY_QUERY = 2;
    /** Види пунктів, де Укрпошта не приймає післяплату (OfficeFit на сервері). */
    var NO_COD_KINDS = ['locker', 'pudo'];

    function route(name) {
        // Теми оголошують `okay` через let/const: він глобальний, але не є властивістю window.
        return typeof okay !== 'undefined' && okay.router ? okay.router[name] : null;
    }

    function parse(json) {
        try {
            return json ? JSON.parse(json) : null;
        } catch (e) {
            return null;
        }
    }

    function format(template, values) {
        return String(template || '').replace(/%(\w+)%/g, function (m, key) {
            return values[key] !== undefined ? values[key] : m;
        });
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

    /** Теми на основі okay_shop піднімають label поля класом `filled`, який ставлять лише на blur. */
    function syncFilled(input) {
        if (input.parentNode) {
            input.parentNode.classList.toggle('filled', input.value.trim() !== '');
        }
    }

    function cityArea(city) {
        return [city.district, city.region].filter(Boolean).join(', ');
    }

    function Block(root) {
        this.root = root;
        root.upBlock = this;
        this.texts = parse(root.getAttribute('data-texts')) || {};
        this.chip = root.getAttribute('data-chip');
        this.chipSelected = root.getAttribute('data-chip-selected');
        this.deliveryId = root.getAttribute('data-delivery-id');
        this.codPayments = (root.getAttribute('data-cod-payments') || '').split(',').filter(Boolean);
        this.priceHidden = root.getAttribute('data-price-hidden') === '1';
        this.city = root.querySelector('.up-cart__city-input');
        this.popular = root.querySelector('.up-cart__popular');
        this.cityBox = root.querySelector('.up-cart__city');
        this.cityLine = root.querySelector('.up-cart__city-line');
        this.chosen = root.querySelector('.up-cart__chosen');
        this.suggest = root.querySelector('.up-cart__suggest');
        this.points = root.querySelector('.up-cart__points');
        this.groups = root.querySelector('.up-cart__groups');
        this.searchBox = root.querySelector('.up-cart__search');
        this.search = root.querySelector('.up-cart__search-input');
        this.pointCheck = root.querySelector('.up-cart__point-check');
        this.list = root.querySelector('.up-cart__list');
        this.more = root.querySelector('.up-cart__more');
        this.message = root.querySelector('.up-cart__message');
        this.quoteBox = root.querySelector('.up-cart__quote');
        this.cityInput = root.querySelector('.up-cart__city-id');
        this.officeInput = root.querySelector('.up-cart__office-id');
        this.selectedCity = parse(root.getAttribute('data-city'));
        this.office = parse(root.getAttribute('data-office'));
        this.step = 'city';
        this.codFeeText = null;
        this.themePrice = null;
        this.groupId = null;
        this.suggestions = [];
        this.active = -1;
        this.cityRequest = null;
        this.pointsRequest = null;
        this.quoteRequest = null;
        this.cityTimer = null;
        this.searchTimer = null;
        this.bind();
        this.showUnpricedSummary();
        this.showCodLines();
        if (this.selectedCity) {
            this.markPopular();
            // Вибір, відновлений із форми, сесії чи останнього замовлення, показується як вибраний, а не списком.
            if (this.office && !this.officeRefusesCod()) {
                this.setStep('chosen');
                if (this.isSelected()) {
                    this.requestQuote();
                }
            } else {
                this.office = null;
                this.officeInput.value = '';
                this.setStep('points');
                this.load();
            }
        } else {
            this.setStep('city');
        }
    }

    /** city: популярні міста й поле міста; points: місто одним рядком, групи, пошук, список; chosen: лише вибраний пункт. */
    Block.prototype.setStep = function (step) {
        this.step = step;
        this.root.setAttribute('data-step', step);
        if (this.popular) {
            this.popular.hidden = step !== 'city';
        }
        this.cityBox.hidden = step !== 'city';
        this.cityLine.hidden = step !== 'points';
        if (step === 'points' && this.selectedCity) {
            this.cityLine.querySelector('.up-cart__city-line-type').textContent = this.selectedCity.type || '';
            this.cityLine.querySelector('.up-cart__city-line-name').textContent = this.selectedCity.name || '';
            this.cityLine.querySelector('.up-cart__city-line-area').textContent = cityArea(this.selectedCity);
        }
        this.chosen.hidden = step !== 'chosen';
        if (step === 'chosen') {
            this.showChosen();
        }
        if (step !== 'points') {
            this.points.hidden = true;
        }
    };

    Block.prototype.paymentId = function () {
        var checked = document.querySelector('input[name="payment_method_id"]:checked');
        return checked ? checked.value : '';
    };

    Block.prototype.codChosen = function () {
        return this.codPayments.indexOf(this.paymentId()) !== -1;
    };

    Block.prototype.officeRefusesCod = function () {
        return !!(this.office && this.codChosen() && NO_COD_KINDS.indexOf(this.office.kind) !== -1);
    };

    /**
     * Рядок комісії кожної оплати "післяплата": Укрпошта бере її у відділенні, окремо від суми.
     * Модуль ставить рядок у блок опису оплати (front_cart_payment); тема без цього блоку отримує
     * його всередині label, бо теми показують опис лише одразу після label.
     */
    Block.prototype.showCodLines = function () {
        var self = this;
        var input = this.themeInput();
        var mine = input && input.length && input.is(':checked');
        // Два способи доставки можуть ділити одну оплату з післяплатою: спосіб ховає лише рядок, який заповнив сам.
        document.querySelectorAll('.up-cod-line[data-up-delivery="' + this.deliveryId + '"]').forEach(function (line) {
            if (!mine) {
                if (line.hasAttribute('data-up-cod-payment')) {
                    line.hidden = true;
                    line.removeAttribute('data-up-delivery');
                } else {
                    line.remove();
                }
            }
        });
        if (!mine) {
            return;
        }
        var text = this.codFeeText && !this.priceHidden
            ? format(this.texts.cart_cod_fee, { fee: this.codFeeText })
            : this.texts.cart_cod_fee_unknown || '';
        this.codPayments.forEach(function (id) {
            id = id.replace(/[^0-9]/g, '');
            var line = document.querySelector('.up-cod-line[data-up-cod-payment="' + id + '"]');
            if (!line) {
                var payment = document.getElementById('payment_' + id);
                var label = payment ? (payment.closest('label') || document.querySelector('label[for="' + payment.id + '"]')) : null;
                if (!label) {
                    return;
                }
                var host = label.querySelector('.vs-option-card__body') || label.querySelector('.delivery__name') || label;
                line = host.querySelector('.up-cod-line[data-up-delivery="' + self.deliveryId + '"]');
                if (!line) {
                    line = el('span', 'up-cod-line');
                    host.appendChild(line);
                }
            }
            line.setAttribute('data-up-delivery', self.deliveryId);
            line.textContent = text;
            line.hidden = false;
        });
    };

    /** Прибирає повідомлення, яке показав валідатор теми, щойно поле стає правильним. */
    Block.prototype.revalidate = function (element) {
        var $ = window.jQuery;
        if (element && $ && $(element).hasClass('error') && $(element.form).data('validator')) {
            $(element).valid();
        }
    };

    Block.prototype.officeTitle = function (office) {
        return [office.postcode, office.title].filter(Boolean).join(' ');
    };

    Block.prototype.showChosen = function () {
        var cityBox = this.chosen.querySelector('.up-cart__chosen-city');
        cityBox.textContent = '';
        if (this.selectedCity) {
            if (this.selectedCity.type) {
                cityBox.appendChild(el('span', 'up-cart__city-line-type', this.selectedCity.type + ' '));
            }
            cityBox.appendChild(el('b', null, this.selectedCity.name));
            var area = cityArea(this.selectedCity);
            if (area) {
                cityBox.appendChild(document.createTextNode(', ' + area));
            }
        }
        this.chosen.querySelector('.up-cart__chosen-title').textContent = this.office ? this.officeTitle(this.office) : '';
        var place = this.chosen.querySelector('.up-cart__chosen-place');
        place.textContent = this.office && this.office.address ? this.office.address : '';
        place.hidden = place.textContent === '';
        this.showSchedule(this.office ? this.office.schedule : null);
    };

    Block.prototype.showSchedule = function (schedule) {
        var box = this.chosen.querySelector('.up-cart__chosen-schedule');
        box.textContent = '';
        var hours = this.scheduleNode(schedule);
        if (hours) {
            var around = String(this.texts.cart_schedule || '%schedule%').split('%schedule%');
            box.appendChild(document.createTextNode(around[0]));
            box.appendChild(hours);
            box.appendChild(document.createTextNode(around[1] || ''));
        }
        box.hidden = !hours;
    };

    /**
     * Тиждень як "Пн–Пт 08:00–19:00 (перерва 13:00–14:00), Сб 09:00–15:00": групи WorkSchedule::groups
     * {from, to, hours, break} (0 = понеділок, hours null — вихідний) або [{allDay: true}].
     */
    Block.prototype.scheduleNode = function (schedule) {
        if (!Array.isArray(schedule) || schedule.length === 0) {
            return null;
        }
        if (schedule[0] && schedule[0].allDay) {
            return el('span', 'up-cart__schedule up-cart__day', this.texts.cart_all_day || '');
        }
        var days = String(this.texts.cart_weekdays || '').split(',');
        var self = this;
        var line = el('span', 'up-cart__schedule');
        schedule.forEach(function (group, index) {
            var span = (days[group.from] || '') + (group.to !== group.from ? '–' + (days[group.to] || '') : '');
            var text = span + ' ' + (group.hours || self.texts.cart_day_off || '');
            if (group.hours && group.break) {
                text += ' (' + format(self.texts.cart_break, { hours: group.break }) + ')';
            }
            if (index > 0) {
                line.appendChild(document.createTextNode(', '));
            }
            line.appendChild(el('span', 'up-cart__day', text));
        });
        return line;
    };

    Block.prototype.bind = function () {
        var self = this;
        this.root.querySelectorAll('[data-up-city]').forEach(function (button) {
            button.addEventListener('click', function () {
                self.chooseCity({ id: Number(button.getAttribute('data-up-city')), name: button.getAttribute('data-up-city-name'), type: button.getAttribute('data-up-city-type') || '' });
            });
        });
        this.city.addEventListener('input', function () {
            if (self.selectedCity && self.city.value.trim() !== self.selectedCity.name) {
                self.dropCity();
            }
            clearTimeout(self.cityTimer);
            self.cityTimer = setTimeout(function () { self.findCities(); }, DEBOUNCE_MS);
        });
        this.city.addEventListener('keydown', function (event) { self.cityKeys(event); });
        this.city.addEventListener('blur', function () { setTimeout(function () { self.closeSuggest(); }, 150); });
        this.root.querySelector('.up-cart__change-city').addEventListener('click', function () {
            self.city.value = '';
            syncFilled(self.city);
            self.dropCity();
            self.city.focus();
        });
        this.root.querySelector('.up-cart__change').addEventListener('click', function () {
            self.setStep('points');
            self.load();
            if (!self.searchBox.hidden) {
                self.search.focus();
            }
        });
        this.search.addEventListener('input', function () {
            clearTimeout(self.searchTimer);
            self.searchTimer = setTimeout(function () { self.load(); }, DEBOUNCE_MS);
        });
        this.search.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
    };

    Block.prototype.findCities = function () {
        var self = this;
        var query = this.city.value.trim();
        var url = route('Sviat_Ukrposhta_cart_cities');
        if (query.length < MIN_CITY_QUERY || !url) {
            this.closeSuggest();
            return;
        }
        if (this.cityRequest) {
            this.cityRequest.abort();
        }
        this.cityRequest = new AbortController();
        fetch(url + '?q=' + encodeURIComponent(query), { credentials: 'same-origin', signal: this.cityRequest.signal })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.problem === 'busy') {
                    self.closeSuggest();
                    self.say(self.texts.cart_busy);
                    return;
                }
                self.say('');
                self.showSuggest(data.cities || []);
            })
            .catch(function (error) {
                if (error.name !== 'AbortError') {
                    self.say(self.texts.cart_search_failed);
                }
            });
    };

    Block.prototype.showSuggest = function (items) {
        var self = this;
        this.suggestions = items;
        this.active = -1;
        this.suggest.textContent = '';
        if (items.length === 0) {
            var none = el('li', 'up-cart__suggest-empty', this.texts.cart_no_city);
            none.setAttribute('aria-disabled', 'true');
            this.suggest.appendChild(none);
        }
        items.forEach(function (item, index) {
            var option = el('li', 'up-cart__suggest-item');
            option.id = self.suggest.id + '_' + index;
            option.setAttribute('role', 'option');
            if (item.type) {
                option.appendChild(el('span', 'up-cart__suggest-type', item.type + ' '));
            }
            option.appendChild(el('b', null, item.name));
            // Тезок розрізняють район і область.
            var area = cityArea(item);
            if (area) {
                option.appendChild(el('span', 'up-cart__suggest-region', area));
            }
            option.addEventListener('mousedown', function (event) {
                event.preventDefault();
                self.chooseCity(item);
            });
            self.suggest.appendChild(option);
        });
        this.suggest.hidden = false;
        this.city.setAttribute('aria-expanded', 'true');
    };

    Block.prototype.closeSuggest = function () {
        this.suggest.hidden = true;
        this.city.setAttribute('aria-expanded', 'false');
        this.city.removeAttribute('aria-activedescendant');
    };

    Block.prototype.cityKeys = function (event) {
        var options = this.suggest.querySelectorAll('.up-cart__suggest-item');
        if (this.suggest.hidden || options.length === 0) {
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            this.active = (this.active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
            options.forEach(function (option, index) {
                option.setAttribute('aria-selected', index === this.active ? 'true' : 'false');
            }, this);
            this.city.setAttribute('aria-activedescendant', options[this.active].id);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            this.chooseCity(this.suggestions[this.active >= 0 ? this.active : 0]);
        } else if (event.key === 'Escape') {
            this.closeSuggest();
        }
    };

    Block.prototype.chooseCity = function (city) {
        if (!city) {
            return;
        }
        var changed = !this.selectedCity || this.selectedCity.id !== city.id;
        this.selectedCity = city;
        this.city.value = city.name;
        syncFilled(this.city);
        this.cityInput.value = city.id;
        this.closeSuggest();
        this.markPopular();
        if (changed) {
            this.office = null;
            this.officeInput.value = '';
            this.clearQuote();
            this.groupId = null;
            this.search.value = '';
            syncFilled(this.search);
        }
        // До того, як поле сховано: jQuery Validate не ховає повідомлення прихованого поля.
        this.revalidate(this.city);
        this.setStep('points');
        this.load();
    };

    /** Змінений текст міста вже не називає вибране місто: замовлення не повинне тихо піти туди. */
    Block.prototype.dropCity = function () {
        if (this.pointsRequest) {
            this.pointsRequest.abort();
        }
        this.selectedCity = null;
        this.office = null;
        this.cityInput.value = '';
        this.officeInput.value = '';
        this.groupId = null;
        this.points.hidden = true;
        this.say('');
        this.markPopular();
        this.clearQuote();
        this.setStep('city');
    };

    Block.prototype.markPopular = function () {
        var self = this;
        this.root.querySelectorAll('[data-up-city]').forEach(function (button) {
            var on = self.selectedCity && Number(button.getAttribute('data-up-city')) === self.selectedCity.id;
            button.classList.toggle(self.chipSelected, !!on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    };

    Block.prototype.load = function () {
        var self = this;
        var url = route('Sviat_Ukrposhta_cart_offices');
        if (!this.selectedCity || !url) {
            return;
        }
        if (this.pointsRequest) {
            this.pointsRequest.abort();
        }
        this.pointsRequest = new AbortController();
        url += '?city_id=' + encodeURIComponent(this.selectedCity.id)
            + (this.groupId ? '&group_id=' + encodeURIComponent(this.groupId) : '')
            + '&payment_id=' + encodeURIComponent(this.paymentId())
            + '&q=' + encodeURIComponent(this.search.value.trim());
        this.root.classList.add('up-cart--loading');
        fetch(url, { credentials: 'same-origin', signal: this.pointsRequest.signal })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                self.root.classList.remove('up-cart--loading');
                self.render(data || {});
            })
            .catch(function (error) {
                if (error.name !== 'AbortError') {
                    self.root.classList.remove('up-cart--loading');
                    self.say(self.texts.cart_search_failed);
                }
            });
    };

    Block.prototype.render = function (data) {
        var self = this;
        this.say('');
        if (data.problem === 'busy') {
            this.say(this.texts.cart_busy);
            return;
        }
        var groups = data.groups || [];
        var points = data.points || [];
        if (groups.length === 0) {
            this.points.hidden = true;
            this.say(this.texts.cart_no_points);
            return;
        }
        // Список, що приходить, поки показано вибраний пункт, лишається прихованим до "Змінити".
        this.points.hidden = this.step !== 'points';
        this.groupId = data.group;

        this.groups.textContent = '';
        groups.forEach(function (group) {
            var on = group.id === data.group;
            var button = el('button', self.chip + (on ? ' ' + self.chipSelected : ''));
            button.type = 'button';
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            button.appendChild(el('span', null, group.name));
            button.appendChild(el('span', 'up-cart__count', String(group.count)));
            button.addEventListener('click', function () {
                if (self.groupId !== group.id) {
                    self.groupId = group.id;
                    self.search.value = '';
                    syncFilled(self.search);
                    self.load();
                }
            });
            self.groups.appendChild(button);
        });

        var query = this.search.value.trim();
        var groupTotal = (groups.filter(function (g) { return g.id === data.group; })[0] || {}).count || 0;
        this.searchBox.hidden = groupTotal <= 8 && query === '';

        this.list.textContent = '';
        if (points.length === 0) {
            this.list.appendChild(el('p', 'up-cart__empty', this.texts.cart_no_results));
        } else if (points.every(function (point) { return point.disabled; })) {
            this.list.appendChild(el('p', 'up-cart__empty', this.texts.cart_none_fit));
        }
        points.forEach(function (point) { self.list.appendChild(self.option(point)); });

        this.more.hidden = query !== '' || groupTotal <= points.length;
        this.more.textContent = format(this.texts.cart_shown_of, { shown: points.length, total: groupTotal });
    };

    Block.prototype.option = function (point) {
        var self = this;
        var unfit = !!point.disabled;
        var selected = !!(this.office && this.office.id === point.id);
        var option = el('button', 'up-cart__option' + (selected ? ' up-cart__option--selected' : '') + (unfit ? ' up-cart__option--unfit' : ''));
        option.type = 'button';
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', selected ? 'true' : 'false');
        if (unfit) {
            option.setAttribute('aria-disabled', 'true');
        }

        var head = el('span', 'up-cart__option-head');
        head.appendChild(el('b', 'up-cart__option-title', this.officeTitle(point)));
        head.appendChild(el('span', 'up-cart__option-kind', this.texts['cart_kind_' + point.kind] || ''));
        option.appendChild(head);
        if (point.address) {
            option.appendChild(el('span', 'up-cart__option-place', point.address));
        }
        if (unfit && point.reason) {
            option.appendChild(el('span', 'up-cart__option-note', this.texts['cart_' + point.reason] || ''));
        }

        option.addEventListener('click', function () {
            if (unfit) {
                return;
            }
            self.office = { id: point.id, postcode: point.postcode, kind: point.kind, title: point.title, address: point.address || '', schedule: null };
            self.officeInput.value = point.id;
            self.list.querySelectorAll('.up-cart__option').forEach(function (other) {
                other.classList.remove('up-cart__option--selected');
                other.setAttribute('aria-selected', 'false');
            });
            option.classList.add('up-cart__option--selected');
            option.setAttribute('aria-selected', 'true');
            self.revalidate(self.pointCheck);
            self.setStep('chosen');
            self.requestQuote();
        });
        return option;
    };

    /** Власна радіокнопка теми для цього способу доставки (jQuery: теми зберігають ціни в jQuery data). */
    Block.prototype.themeInput = function () {
        var $ = window.jQuery;
        return $ ? $('input[name="delivery_id"][value="' + String(this.deliveryId).replace(/[^0-9]/g, '') + '"]') : null;
    };

    /** Запам'ятовує, що тема показувала до втручання модуля, щоб пізніше це повернути. */
    Block.prototype.captureTheme = function () {
        var input = this.themeInput();
        if (this.themePrice === null && input && input.length) {
            this.themePrice = {
                price: input.data('delivery_price'),
                total: input.data('total_price'),
                free: input.data('is_free_delivery'),
                text: input.closest('.fn_delivery_item').find('.fn_delivery_price').first().text()
            };
        }
    };

    Block.prototype.restoreTheme = function () {
        if (this.themePrice) {
            var original = this.themePrice;
            this.setThemePrice(original.price, original.total, original.text, original.free);
        }
    };

    /**
     * Ще не нараховано (Укрпошта не дала ціни або не вибрано пункт): підсумок замовлення
     * повторює текст способу доставки замість "0" теми.
     */
    Block.prototype.showUnpricedSummary = function () {
        var input = this.themeInput();
        var texts = [this.texts.cart_price_by_manager, this.texts.cart_price_after_point].filter(Boolean);
        if (!input || !input.length || !input.is(':checked') || input.data('is_free_delivery') || Number(input.data('delivery_price')) !== 0) {
            return;
        }
        var shown = input.closest('.fn_delivery_item').find('.fn_delivery_price').first().text().trim().replace(/^\(|\)$/g, '');
        if (texts.indexOf(shown) !== -1) {
            window.jQuery('#fn_total_delivery_price').text(shown);
        }
    };

    /** Пише ціну туди, де її читає тема, потім дає темі перерахувати підсумки. */
    Block.prototype.setThemePrice = function (price, total, text, free) {
        var input = this.themeInput();
        if (!input || !input.length) {
            return;
        }
        this.captureTheme();
        input.data('delivery_price', price).data('total_price', total).data('is_free_delivery', free ? 1 : 0);
        input.closest('.fn_delivery_item').find('.fn_delivery_price').text(text);
        if (input.is(':checked') && typeof okay !== 'undefined' && typeof okay.update_cart_total_price === 'function') {
            okay.update_cart_total_price();
            // Тема заповнює суми "до оплати" лише при зміні способу доставки.
            var $ = window.jQuery;
            $(typeof okay.convert === 'function' ? '.fn_payment_method__item' : []).each(function () {
                var currency = $(this).find('input[name="payment_method_id"]').data('currency_id');
                $(this).find('.fn_payment_price').text(okay.convert(total, currency));
            });
        }
    };

    Block.prototype.clearQuote = function () {
        if (this.quoteRequest) {
            this.quoteRequest.abort();
            this.quoteRequest = null;
        }
        this.quoteBox.hidden = true;
        this.quoteBox.textContent = '';
        this.codFeeText = null;
        this.showCodLines();
        this.restoreTheme();
    };

    Block.prototype.requestQuote = function () {
        var self = this;
        var url = route('Sviat_Ukrposhta_cart_quote');
        if (!this.office || !url) {
            return;
        }
        if (this.quoteRequest) {
            this.quoteRequest.abort();
        }
        this.quoteRequest = new AbortController();
        var query = '?delivery_id=' + encodeURIComponent(this.deliveryId) + '&office_id=' + encodeURIComponent(this.office.id)
            + '&payment_id=' + encodeURIComponent(this.paymentId());
        var input = this.themeInput();
        if (input && input.length && !input.data('hide_front_delivery_price') && !input.data('is_free_delivery')) {
            this.captureTheme();
            input.closest('.fn_delivery_item').find('.fn_delivery_price').text(this.texts.cart_calculating || '');
        }
        fetch(url + query, { credentials: 'same-origin', signal: this.quoteRequest.signal })
            .then(function (response) { return response.json(); })
            .then(function (data) { self.showQuote(data || {}); })
            .catch(function (error) {
                if (error.name !== 'AbortError') {
                    self.showQuote({ problem: 'unreachable' });
                }
            });
    };

    Block.prototype.showQuote = function (data) {
        var lines = [];
        var problem = ['unreachable', 'rejected', 'busy', 'no_uah'].indexOf(data.problem) !== -1;
        var priced = typeof data.price === 'number' && !data.unpriced;
        if (typeof data.price === 'number') {
            this.setThemePrice(data.price, data.totalWithDelivery, data.priceText || '', !!data.free);
            this.showUnpricedSummary();
        } else {
            // Ціни немає (безкоштовно, приховано, немає відправника або збій): повертаються власні числа теми.
            this.restoreTheme();
            var input = this.themeInput();
            if (problem && input && input.length && !input.data('hide_front_delivery_price') && !input.data('is_free_delivery')) {
                input.closest('.fn_delivery_item').find('.fn_delivery_price').text(this.texts.cart_price_by_manager || '');
            }
        }
        if (data.problem === 'busy' && !priced) {
            lines.push(this.texts.cart_busy);
        } else if (data.problem === 'rejected' && !priced) {
            lines.push(this.texts.cart_price_rejected);
        } else if (problem && !priced) {
            lines.push(this.texts.cart_price_unreachable);
        }
        if (this.office && Array.isArray(data.schedule)) {
            this.office.schedule = data.schedule;
            if (this.step === 'chosen') {
                this.showSchedule(data.schedule);
            }
        }
        this.codFeeText = data.codFeeText || null;
        this.showCodLines();
        this.quoteBox.textContent = '';
        var self = this;
        lines.forEach(function (line) { self.quoteBox.appendChild(el('p', null, line)); });
        this.quoteBox.classList.toggle('up-cart__quote--problem', problem && !priced);
        this.quoteBox.hidden = lines.length === 0;
    };

    Block.prototype.isSelected = function () {
        var input = this.themeInput();
        return !!(input && input.length && input.is(':checked'));
    };

    /**
     * Оплата чи доставка змінилася: післяплата змінює і ціну, і те, які пункти її приймають. Лише вибраний
     * спосіб доставки питає ціну: кожен запит іде з ліміту сесії й бюджету магазину.
     */
    Block.prototype.refresh = function () {
        this.showCodLines();
        if (!this.selectedCity || !this.isSelected()) {
            return;
        }
        if (this.officeRefusesCod()) {
            this.office = null;
            this.officeInput.value = '';
            this.clearQuote();
            this.setStep('points');
            this.load();
            return;
        }
        if (this.step === 'points') {
            this.load();
        }
        this.requestQuote();
    };

    Block.prototype.say = function (text) {
        this.message.textContent = text || '';
        this.message.hidden = !text;
    };

    function visibleColor(value) {
        return value && value !== 'transparent' && !/rgba\([^)]*,\s*0\)$/.test(value) ? value : null;
    }

    /** Колір, що означає "вибрано": не чорний, білий чи сірий — ними теми фарбують звичайний текст і лінії. */
    function accentColor(value) {
        var color = visibleColor(value);
        var rgb = color ? color.match(/\d+(\.\d+)?/g) : null;
        if (!rgb || rgb.length < 3) {
            return null;
        }
        var r = Number(rgb[0]), g = Number(rgb[1]), b = Number(rgb[2]);
        return Math.max(r, g, b) - Math.min(r, g, b) > 24 ? color : null;
    }

    function isDark(color) {
        var rgb = color.match(/\d+(\.\d+)?/g).map(Number);
        return 0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2] < 160;
    }

    /**
     * Колір, яким тема позначає вибрану опцію: позначка вибраного способу доставки (svg-галочка
     * або стилізована радіокнопка), інакше кнопка оформлення замовлення. Не перший .form__button: це
     * кнопка купона, бліда в деяких темах.
     */
    function themeAccent() {
        var chosen = document.querySelector('input[name="delivery_id"]:checked');
        var label = chosen ? (chosen.closest('label') || document.querySelector('label[for="' + chosen.id + '"]')) : null;
        var found = null;
        if (label) {
            label.querySelectorAll('svg, svg *').forEach(function (node) {
                var style = getComputedStyle(node);
                found = found || accentColor(style.stroke) || accentColor(style.fill);
            });
            [chosen, label].forEach(function (node) {
                ['::before', '::after'].forEach(function (pseudo) {
                    var style = getComputedStyle(node, pseudo);
                    found = found || accentColor(style.backgroundColor) || accentColor(style.borderTopColor);
                });
            });
            found = found || accentColor(getComputedStyle(chosen).accentColor);
        }
        var button = found ? null : document.querySelector('[name="checkout"].form__button, button[name="checkout"]');
        if (button) {
            var style = getComputedStyle(button);
            // Градієнтна кнопка має фірмовий колір у градієнті або на рамці.
            var gradient = (style.backgroundImage.match(/rgba?\([^)]*\)/g) || []);
            found = accentColor(style.backgroundColor) || accentColor(gradient[gradient.length - 1]) || accentColor(gradient[0]) || accentColor(style.borderTopColor);
        }
        return found;
    }

    /**
     * Не всі теми на основі okay_shop виставляють свої кольори як CSS-змінні, тож колір і заокруглення
     * зчитуються зі сторінки. vibe_shop нічого не потребує: блок використовує його компонент чіпа.
     */
    function adoptThemeColours(root) {
        if (!root.classList.contains('up-cart--okay')) {
            return;
        }
        var accent = themeAccent();
        if (accent) {
            root.style.setProperty('--up-accent', accent);
            root.style.setProperty('--up-accent-text', isDark(accent) ? '#fff' : '#222');
        }
        var input = root.querySelector('.up-cart__city-input');
        if (input) {
            var style = getComputedStyle(input);
            var line = visibleColor(style.borderTopColor);
            if (line) {
                root.style.setProperty('--up-border', line);
            }
            root.style.setProperty('--up-radius', style.borderTopLeftRadius);
        }
    }

    var blocks = [];
    var rulesAdded = false;

    /**
     * Перевірки полів Укрпошти у власному jQuery Validate теми, щоб повідомлення стояло під своїм полем
     * ще до надсилання форми. Поля інших способів доставки тема вимикає, а приховані кроки невидимі,
     * тож валідатор пропускає і ті, й інші. Сервер перевіряє все ще раз.
     */
    function addRules() {
        var $ = window.jQuery;
        var form = $ ? $('.fn_validate_cart') : null;
        if (rulesAdded || !form || !form.length || !$.validator || !form.data('validator') || blocks.length === 0) {
            return;
        }
        rulesAdded = true;
        var blockOf = function (element) {
            var root = element.closest('.up-cart');
            return root ? root.upBlock : null;
        };
        $.validator.addMethod('upCity', function (value, element) {
            var block = blockOf(element);
            return !block || !!block.selectedCity;
        });
        $.validator.addMethod('upOffice', function (value, element) {
            var block = blockOf(element);
            return !block || !!block.office;
        });
        $.validator.addMethod('upCod', function (value, element) {
            var block = blockOf(element);
            return !block || !block.officeRefusesCod();
        });
        blocks.forEach(function (block) {
            var texts = block.texts;
            $(block.city).rules('add', { upCity: true, messages: { upCity: texts.err_city } });
            $(block.pointCheck).rules('add', { upOffice: true, upCod: true, messages: { upOffice: texts.err_office, upCod: texts.err_cod_locker } });
        });
    }

    window.SviatUkrposhtaCart = { addRules: addRules };

    function init() {
        document.querySelectorAll('.up-cart').forEach(function (root) {
            adoptThemeColours(root);
            blocks.push(new Block(root));
        });
        addRules();
        if (!window.jQuery) {
            return;
        }
        // Після змін у кошику тема повторно запускає `change` на вибраній доставці (jQuery
        // trigger, якого звичайні слухачі не бачать): вага й сума могли змінитися.
        window.jQuery(document).on('change', 'input[name="delivery_id"]', function () {
            blocks.forEach(function (block) {
                // Тема позначає лише вибраний спосіб доставки: тепер його колір можна зчитати.
                adoptThemeColours(block.root);
                block.showUnpricedSummary();
                block.refresh();
            });
        });
        window.jQuery(document).on('change', 'input[name="payment_method_id"]', function () {
            blocks.forEach(function (block) { block.refresh(); });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
