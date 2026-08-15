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
