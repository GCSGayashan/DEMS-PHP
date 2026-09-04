(()=>{
    const optionLabel=row=>[row.dad_number,row.name_en].filter(Boolean).join(' - ');

    const typeSelect=document.querySelector('select[name="location_type_id"]');
    const currentType=document.querySelector('[data-current-location-type]')?.dataset.currentLocationType||'';
    const toggleGnIdentifiers=()=>{
        const selectedType=typeSelect?.selectedOptions[0]?.dataset.systemKey||currentType;
        document.querySelectorAll('[data-gn-identifier]').forEach(field=>{
            const hidden=selectedType!=='GN_DIVISION';field.hidden=hidden;
            field.querySelectorAll('input').forEach(input=>input.disabled=hidden);
        });
    };
    typeSelect?.addEventListener('change',toggleGnIdentifiers);
    toggleGnIdentifiers();

    document.querySelectorAll('[data-location-cascade]').forEach(container=>{
        const endpoint=container.dataset.endpoint;
        const selects=new Map([...container.querySelectorAll('select[data-location-type]')].map(select=>[select.name,select]));
        const requests=new WeakMap();
        const childrenOf=parentName=>[...selects.values()].filter(select=>select.dataset.parentField===parentName);
        const emptyLabel=select=>select.required?'Select':'Not linked';
        const reset=select=>select.replaceChildren(new Option(emptyLabel(select),''));

        const clearDescendants=parentName=>{
            for(const child of childrenOf(parentName)){
                requests.get(child)?.abort();
                reset(child);
                clearDescendants(child.name);
            }
        };

        const load=async(select,preserveInitial=false)=>{
            const parent=selects.get(select.dataset.parentField||'');
            const selected=preserveInitial?select.dataset.initialValue:'';
            requests.get(select)?.abort();
            reset(select);
            if(!parent?.value)return;
            const request=new AbortController();requests.set(select,request);
            select.disabled=true;
            try{
                const query=new URLSearchParams({parent_id:parent.value,child_type:select.dataset.locationType,limit:'1000'});
                const response=await fetch(endpoint+'?'+query,{headers:{Accept:'application/json'},credentials:'same-origin',signal:request.signal});
                if(!response.ok)throw new Error('Location hierarchy lookup denied');
                const payload=await response.json();
                reset(select);
                for(const row of payload.results||[])select.add(new Option(optionLabel(row),row.id));
                if(selected&&[...select.options].some(option=>option.value===selected))select.value=selected;
            }catch(error){
                if(error.name!=='AbortError')select.replaceChildren(new Option('Unable to load valid locations',''));
            }finally{
                if(requests.get(select)===request){requests.delete(select);select.disabled=false;}
            }
        };

        const reloadBranch=async parentName=>{
            const children=childrenOf(parentName);
            await Promise.all(children.map(child=>load(child,false)));
            await Promise.all(children.map(child=>reloadBranch(child.name)));
        };

        for(const select of selects.values())select.addEventListener('change',()=>{
            clearDescendants(select.name);
            reloadBranch(select.name);
        });

        (async()=>{
            for(const select of selects.values()){
                if(select.dataset.parentField)await load(select,true);
                select.dataset.initialValue='';
            }
        })();
    });
})();
