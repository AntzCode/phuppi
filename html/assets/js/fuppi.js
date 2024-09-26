$(() => {
    function humanFileSize(size) {
        var i = size == 0 ? 0 : Math.floor(Math.log(size) / Math.log(1024));
        return +((size / Math.pow(1024, i)).toFixed(2)) * 1 + ' ' + ['B', 'kB', 'MB', 'GB', 'TB'][i];
    }
    window.humanFileSize = humanFileSize;
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
        if ($('i', container).hasClass('check')) {
            $('i', container).removeClass('check');
            $('i', itemSelector).removeClass('check');
        } else {
            $('i', container).addClass('check');
            $('i', itemSelector).addClass('check');
        }
        $(container).trigger('multi-select-sum');
    });
    $('.multi-select-all').on('multi-select-sum', (event) => {
        let container = event.currentTarget;
        let itemSelector = $(container).data('multi-select-item-selector') ?? '.multi-select-item';
        let countContainer = $(container).data('multi-select-count-selector') ?? '.multi-select-count';
        let sizeContainer = $(container).data('multi-select-size-selector') ?? '.multi-select-size';
        let count = 0;
        let size = 0;
        $(itemSelector + ':has(.check)').get().map((item) => {
            count++;
            size = size + $(item).data('multi-select-item-size') ?? 0;
        });
        $(countContainer).text(count);
        $(sizeContainer).text(size > 0 ? humanFileSize(size) : '0B');
    });
    $('.multi-select-item').click((event) => {
        let item = event.currentTarget;
        let container = $($(item).data('multi-select-all-selector') ?? '.multi-select-all');
        if ($('i', item).hasClass('check')) {
            $('i', item).removeClass('check');
        } else {
            $('i', item).addClass('check');
        }
        container.trigger('multi-select-sum');
    });
    $('.multi-select-action').click(async (event) => {
        let action = event.currentTarget;
        let itemSelector = $(action).data('multi-select-item-selector') ?? '.multi-select-item';
        let selectedIds = $(itemSelector).get().filter((item) => $('i', item).hasClass('check')).map((item) => $(item).data('multi-select-item-id'));
        setTimeout(() => {
            $(action).removeClass('active');
            $(action).removeClass('selected');
        });
        if (selectedIds.length > 0) { 
            switch ($(action).data('multi-select-action')) {
                case 'download':
                    $('.ui.page.dimmer').dimmer('show');
                    window.location = 'file.php?id=' + JSON.stringify(selectedIds);
                    setTimeout(() => {
                        $('.ui.page.dimmer').dimmer('hide');
                    }, 3000);
                    break;
                case 'tag':
                    $($(action).data('modal-selector')).data('selected-ids', JSON.stringify(selectedIds));
                    $($(action).data('modal-selector')).modal('show');
                    break;
                case 'delete':
                    if (confirm('Are you sure you want to delete the ' + selectedIds.length + ' selected? This action cannot be undone!')) {
                        $('.ui.page.dimmer').dimmer('show');

                        let formData = new FormData();
                        formData.append('_method', 'delete');
                        formData.append('ajax', 1);
                        formData.append('fileIds', JSON.stringify(selectedIds));

                        await axios.post($(action).data('multi-select-action-url'), formData)
                            .then(async (response) => {
                                $('.ui.page.dimmer').dimmer('hide');
                                switch ($(action).data('multi-select-action-callback')) {
                                    case 'refresh':
                                        window.location = window.location;
                                        break;
                                }
                            }).catch((error) => {
                                $('.ui.page.dimmer').dimmer('hide');
                                alert(error.response?.data?.message || error.message);
                            });
                    }
                    break;
            }
        }
    });
});
