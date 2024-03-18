$(() => {
    $('.clickable').click((event) => {
        let button = event.currentTarget;
        if ($(button).data('url')) {
            window.location = $(button).data('url');
        }
    });
    $('.clickable-confirm').click((event) => {
        let button = event.currentTarget;
        if ($(button).data('confirm')) {
            if (confirm($(button).data('confirm'))) {
                let action = eval($(button).data('action'))
                if (typeof action == 'function') {
                    action(event);
                }
            }
        }
    });
    $('.ui.dropdown').dropdown();
    $('.copy-to-clipboard').click((event) => {
        let button = event.currentTarget;
        if ($(button).data('content')) {
            navigator.clipboard.writeText($(button).data('content'));
            $.toast({ message: 'Code "' + $(button).data('content') + '" copied to clipboard!' })
        }
    });
    $('.tabular.menu .item').tab();
});
