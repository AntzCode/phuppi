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
            $.toast({ message: '"' + $(button).data('content') + '" copied to clipboard!' })
        }
    });
    $('.tabular.menu .item').tab();
    $('.multi-select-all').click((event) => {
        let container = event.currentTarget;
        let itemSelector = $(container).data('multi-select-item-selector') ?? '.multi-select-item';
        if($('i', container).hasClass('check')){
            $('i', container).removeClass('check');
            $('i', itemSelector).removeClass('check');
        }else{
            $('i', container).addClass('check');
            $('i', itemSelector).addClass('check');
        }
    });
    $('.multi-select-item').click((event) => {
        let container = event.currentTarget;
        if($('i', container).hasClass('check')){
            $('i', container).removeClass('check');
        }else{
            $('i', container).addClass('check');
        }
    });
    $('.multi-select-action').click((event) => {
        let action = event.currentTarget;
        let itemSelector = $(action).data('multi-select-item-selector') ?? '.multi-select-item';
        switch($(action).data('multi-select-action')){
            case 'download':
                let downloadIds = $(itemSelector).get().filter((item) => $('i', item).hasClass('check')).map((item) => $(item).data('multi-select-item-id'));
                window.location = 'file.php?id='+JSON.stringify(downloadIds);
                setTimeout(() => {
                    $(action).removeClass('active');
                    $(action).removeClass('selected');
                });
            break;
        }
    });
});
