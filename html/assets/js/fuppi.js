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
        if ($('i', container).hasClass('check')) {
            $('i', container).removeClass('check');
            $('i', itemSelector).removeClass('check');
        } else {
            $('i', container).addClass('check');
            $('i', itemSelector).addClass('check');
        }
    });
    $('.multi-select-item').click((event) => {
        let container = event.currentTarget;
        if ($('i', container).hasClass('check')) {
            $('i', container).removeClass('check');
        } else {
            $('i', container).addClass('check');
        }
    });
    $('.multi-select-action').click(async (event) => {
        let action = event.currentTarget;
        let itemSelector = $(action).data('multi-select-item-selector') ?? '.multi-select-item';
        let selectedIds = $(itemSelector).get().filter((item) => $('i', item).hasClass('check')).map((item) => $(item).data('multi-select-item-id'));

        switch ($(action).data('multi-select-action')) {
            case 'download':
                $('.ui.page.dimmer').dimmer('show');
                window.location = 'file.php?id=' + JSON.stringify(selectedIds);
                setTimeout(() => {
                    $(action).removeClass('active');
                    $(action).removeClass('selected');
                });
                setTimeout(() => {
                    $('.ui.page.dimmer').dimmer('hide');
                }, 3000);
                break;
            case 'delete':
                if (confirm('Are you sure you want to delete the ' + selectedIds.length + ' selected? This action cannot be undone!')) {
                    $('.ui.page.dimmer').dimmer('show');
                    
                    let formData = new FormData();
                    formData.append('_method', 'delete');
                    formData.append('_ajax', 1);
                    formData.append('fileIds', JSON.stringify(selectedIds));

                    setTimeout(() => {
                        $(action).removeClass('active');
                        $(action).removeClass('selected');
                    });
                    
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
                            alert(error);
                        });
                }
                break;
        }
    });
});
