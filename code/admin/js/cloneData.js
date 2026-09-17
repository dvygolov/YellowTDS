(function ($) {

    $.fn.cloneData = function (options) {

        var settings = jQuery.extend({
            mainContainerId: "clone-container",
            cloneContainer: "clone-item",
            copyClass: "clone-div",
            removeButtonClass: "remove-item",
            template: null,
            maxLimit: 0, // 0 = unlimited
            minLimit: 1, // 0 = unlimited
            counterIndex: 0,
            regexName: /(^.+?)([\[\d{1,}\]]{1,})(\[.+\])*$/i,
        }, options);

        var _addItem = function () {

            settings.counterIndex = $('#' + settings.mainContainerId + ' .' + settings.cloneContainer).length;

            if (settings.maxLimit != 0 && settings.counterIndex >= settings.maxLimit) {
                alert("Max limit exceeded!");
                return false;
            }

            $('#' + settings.mainContainerId).append(settings.template.first()[0].outerHTML);

            _updateAttributes();

            return false;
        }

        var _updateAttributes = function () {

            $('#' + settings.mainContainerId + ' .' + settings.cloneContainer).each(function (index) {
                $(this).find('*').each(function () {
                    _updateAttrID($(this), index);
                    _updateAttrName($(this), index);
                });
            });

            $('#' + settings.mainContainerId).addClass('clone-data');
            $('#' + settings.mainContainerId + ' .' + settings.cloneContainer).each(function (parent_index, item) {
                $(this).attr('data-index', parent_index).addClass(settings.copyClass);
            });

            $('.' + settings.cloneContainer + '.' + settings.copyClass).each(function (parent_index, item) {
                $(item).find('[for]').each(function () {
                    $(this).attr('for', $(this).attr('for').replace(/.$/, parent_index));
                });

            });
        }

        var _updateAttrID = function ($elem, index) {
            var id = $elem.attr('id');
            var newID = id;

            if (id !== undefined) {
                newID = _incrementLastNumber(id, index);
                $elem.attr('id', newID);
            }

            if (id !== newID) {
                $elem.closest('.' + settings.cloneContainer).find('.field-' + id).each(function () {
                    $(this).removeClass('field-' + id).addClass('field-' + newID);
                });
                $elem.closest('.' + settings.cloneContainer).find("label[for='" + id + "']").attr('for', newID);
            }

            return newID;
        }

        var _incrementLastNumber = function (string, index) {
            return string.replace(/[0-9]+(?!.*[0-9])/, function (match) {
                return index;
            });
        }

        var _updateAttrName = function ($elem, index) {
            var name = $elem.attr('name');

            if (name !== undefined) {
                var matches = name.match(settings.regexName);

                if (matches && matches.length >= 3 && matches.length <= 4) {
                    matches[2] = matches[2].replace(/\]\[/g, "-").replace(/\]|\[/g, '');
                    var identifiers = matches[2].split('-');
                    identifiers[0] = index;

                    if (identifiers.length > 1) {
                        var widgetsOptions = [];
                        $elem.parents('.' + settings.mainContainerId).each(function (i) {
                            widgetsOptions[i] = eval($(this).find('#' + settings.mainContainerId));
                        });

                        widgetsOptions = widgetsOptions.reverse();
                        for (var i = identifiers.length - 1; i >= 1; i--) {
                            identifiers[i] = $elem.closest('.' + settings.cloneContainer).closest('#' + settings.mainContainerId).index();
                        }
                    }

                    lastParam = matches[3] || '';
                    name = matches[1] + '[' + identifiers.join('][') + ']' + lastParam;
                    $elem.attr('name', name);
                }
            }

            return name;
        };

        var _parseTemplate = function () {
            var template_clone = $('#' + settings.mainContainerId + ' .' + settings.cloneContainer + ":first");

            var $template = $(template_clone).clone(false, false);

            $template.find('input, textarea, select').each(function () {
                if ($(this).is(':checkbox') || $(this).is(':radio')) {
                    var type = ($(this).is(':checkbox')) ? 'checkbox' : 'radio';
                    var inputName = $(this).attr('name');
                    var $inputHidden = $template.find('input[type="hidden"][name="' + inputName + '"]').first();
                    var count = $template.find('input[type="' + type + '"][name="' + inputName + '"]').length;

                    if ($inputHidden && count === 1) {
                        $(this).val(1);
                        $inputHidden.val(0);
                    }

                    $(this).removeAttr("checked");
                } else if ($(this).is('select')) {
                    $(this).find('option:selected').removeAttr("selected");
                } else if ($(this).is('textarea')) {
                    $(this).html("");
                } else {
                    $(this).removeAttr("value");
                }

            });

            settings.template = $template;
        };

        var _deleteItem = function ($elem) {

            var count = _count();
            if (count > settings.minLimit) {
                $elem.parents('.' + settings.cloneContainer).slideUp(function () {
                    $(this).remove();
                    _updateAttributes();
                });
            } else {
                alert('you must have at least one item.');
            }
        };

        var _count = function () {
            return $('.' + settings.cloneContainer).closest('#' + settings.mainContainerId).find('.' + settings.cloneContainer).length;
        };

        $(document).on('click', '.' + settings.removeButtonClass, function () {
            _deleteItem($(this));
        });

        this.each(function () {
            $(this).click(function () {
                _addItem();
            });
            _parseTemplate();
            _updateAttributes();
        });

        return this; // return to jQuery
    };

})(jQuery);
