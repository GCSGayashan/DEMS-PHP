document.getElementById('menuToggle')?.addEventListener('click',()=>document.getElementById('sidebar')?.classList.toggle('open'));

document.querySelectorAll('select[data-searchable-select]').forEach((select) => {
    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'form-control form-control-sm mb-1';
    search.placeholder = select.dataset.searchableSelect || 'Search options';
    search.setAttribute('aria-label', search.placeholder);
    const options = Array.from(select.options).map((option) => ({
        option,
        text: option.text.toLocaleLowerCase(),
    }));
    search.addEventListener('input', () => {
        const term = search.value.trim().toLocaleLowerCase();
        options.forEach(({option, text}, index) => {
            option.hidden = index !== 0 && term !== '' && !text.includes(term);
        });
    });
    select.parentNode.insertBefore(search, select);
});

document.querySelectorAll('[data-context-search]').forEach((search) => {
    const groups = Array.from(document.querySelectorAll('[data-context-group]'));
    const noResults = document.querySelector('[data-context-no-results]');
    const filterContexts = () => {
        const term = search.value.trim().toLocaleLowerCase();
        let visibleRows = 0;
        groups.forEach((group) => {
            let visibleInGroup = 0;
            group.querySelectorAll('[data-context-row]').forEach((row) => {
                const visible = term === '' || (row.dataset.contextSearchText || '').includes(term);
                row.hidden = !visible;
                if (visible) visibleInGroup++;
            });
            group.hidden = visibleInGroup === 0;
            visibleRows += visibleInGroup;
        });
        if (noResults) noResults.hidden = visibleRows !== 0;
    };
    search.addEventListener('input', filterContexts);
});
