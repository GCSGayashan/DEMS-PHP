(() => {
    'use strict';

    const debounce = (callback, wait = 350) => {
        let timer;
        return (...args) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => callback(...args), wait);
        };
    };

    const showError = (table, show = true) => {
        table.closest('.dems-datatable-card')?.querySelector('.dems-datatable-error')?.classList.toggle('d-none', !show);
    };

    const filtersFor = (table) => {
        const values = {};
        table.closest('.dems-datatable-card')?.querySelectorAll('.js-dt-filter').forEach((input) => {
            if (input.value !== '') values[input.dataset.filterName] = input.value;
        });
        return values;
    };

    const init = (table) => {
        if (typeof window.DataTable !== 'function') {
            showError(table);
            return;
        }
        const config = JSON.parse(table.dataset.demsConfig || '{}');
        table.addEventListener('dt-error', () => showError(table));

        const instance = new window.DataTable(table, {
            serverSide: true,
            processing: true,
            deferRender: true,
            autoWidth: false,
            scrollX: true,
            pageLength: 25,
            lengthMenu: [10, 25, 50, 100],
            searchDelay: 350,
            order: config.defaultOrder || [[0, 'asc']],
            columns: config.columns,
            ajax: {
                url: config.endpoint,
                data: (request) => {
                    request.filters = filtersFor(table);
                },
                dataSrc: (response) => {
                    showError(table, false);
                    return response.data || [];
                }
            },
            layout: {
                topStart: 'pageLength',
                topEnd: 'search',
                bottomStart: 'info',
                bottomEnd: 'paging'
            },
            language: {
                emptyTable: config.emptyMessage || 'No records found.',
                zeroRecords: config.emptyMessage || 'No records found.',
                processing: '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span> Loading records…',
                search: 'Search:',
                searchPlaceholder: 'Search records',
                lengthMenu: 'Show _MENU_ entries',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                infoEmpty: 'Showing 0 to 0 of 0 entries'
            }
        });

        table.closest('.dems-datatable-card')?.querySelectorAll('.js-dt-filter').forEach((input) => {
            const reload = input.tagName === 'SELECT' || input.type === 'date'
                ? () => instance.ajax.reload()
                : debounce(() => instance.ajax.reload());
            input.addEventListener(input.tagName === 'SELECT' ? 'change' : 'input', reload);
        });

        const exportButton = table.closest('.dems-datatable-card')?.querySelector('.js-dt-export');
        exportButton?.addEventListener('click', (event) => {
            event.preventDefault();
            const exportUrl = new URL(exportButton.dataset.exportUrl, window.location.href);
            exportUrl.searchParams.set('search[value]', instance.search());
            const order = instance.order()?.[0] || [0, 'asc'];
            exportUrl.searchParams.set('order[0][column]', String(order[0]));
            exportUrl.searchParams.set('order[0][dir]', String(order[1]));
            Object.entries(filtersFor(table)).forEach(([name, value]) => exportUrl.searchParams.set(`filters[${name}]`, value));
            window.location.assign(exportUrl.toString());
        });
    };

    document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('.js-dems-datatable').forEach(init));
})();
