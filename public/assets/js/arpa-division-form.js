(()=>{
    const form=document.getElementById('division-assignment-form');
    const date=document.getElementById('effective_from');
    const asc=document.getElementById('asc_location_id');
    const officer=document.getElementById('officer_id');
    const appointmentType=document.getElementById('appointment_type');
    const typeHelp=document.getElementById('appointment-type-help');
    const division=document.getElementById('arpa_division_location_id');
    const submit=document.getElementById('submit-division-assignment');
    const message=document.getElementById('eligibility-refresh-message');
    const officerEmpty=document.getElementById('officer-empty-state');
    const divisionEmpty=document.getElementById('division-empty-state');
    const continuity=document.getElementById('division-continuity');
    const historicalCoverage=document.getElementById('historical-coverage');
    const lastCovered=document.getElementById('last-covered-through');
    const missingPeriod=document.getElementById('missing-period');
    const requiredStart=document.getElementById('required-next-start');
    const nextExisting=document.getElementById('next-existing-assignment');
    const maximumEnd=document.getElementById('maximum-end-date');
    const boundedGapFields=document.getElementById('bounded-gap-fields');
    const effectiveTo=document.getElementById('effective_to');
    const endReason=document.getElementById('end_reason_id');
    const continuityMessage=document.getElementById('continuity-validation-message');
    const issueLink=document.getElementById('continuity-data-issue-link');
    const issuePanel=document.getElementById('appointment-data-issue-panel');
    const issueSummary=document.getElementById('appointment-data-issue-summary');
    const issueList=document.getElementById('appointment-data-issue-list');
    const issuesReview=document.getElementById('appointment-data-issues-review');
    if(!form||!date||!officer||!appointmentType||!division||!submit||!message)return;

    let pending=null;
    let refreshing=false;
    let blockingIssue={row_key:form.dataset.initialIssueKey||'',reconciliation_item_id:form.dataset.initialReviewId||''};
    let unresolvedIssues=[];try{unresolvedIssues=JSON.parse(form.dataset.initialIssues||'[]');}catch(_error){unresolvedIssues=[];}
    const displayDate=value=>{
        if(!/^\d{4}-\d{2}-\d{2}$/.test(value||''))return value||'—';
        return new Date(`${value}T00:00:00`).toLocaleDateString(undefined,{day:'2-digit',month:'short',year:'numeric'});
    };
    const applyAppointmentTypes=(preferred='')=>{
        const selected=officer.options[officer.selectedIndex];
        const allowed=new Set((selected?.dataset.allowedTypes||'').split(',').filter(Boolean));
        Array.from(appointmentType.options).forEach((option,index)=>{
            if(index===0){option.textContent=selected?.value?'Select appointment type':'Select an officer first';return;}
            const available=allowed.has(option.value);option.hidden=!available;option.disabled=!available;
        });
        appointmentType.value=allowed.has(preferred)?preferred:'';
        if(typeHelp)typeHelp.textContent=selected?.value
            ?(allowed.size?'Only appointment types allowed for this officer and date are shown.':'No appointment type is currently available for this officer.')
            :'Appointment types will be shown after you select an officer.';
    };
    const replaceOptions=(select,placeholder,rows,selectedId,kind)=>{
        select.replaceChildren(new Option(placeholder,''));
        rows.forEach(row=>{
            const option=new Option(row.label,row.id,false,row.id===selectedId);
            if(kind==='officer')option.dataset.allowedTypes=(row.allowed_appointment_types||[]).join(',');
            if(kind==='division'){
                option.dataset.requiredNextStart=row.required_next_start||'';
                option.dataset.lastCoveredThrough=row.last_covered_through||'';
                option.dataset.continuityRelation=row.continuity_relation||'';
                option.dataset.gapEnd=row.gap_end||'';
                option.dataset.nextExistingStart=row.next_existing_start||'';
                option.dataset.nextExistingEnd=row.next_existing_end||'';
                option.dataset.timelineStatus=row.timeline_status||'';
            }
            select.add(option);
        });
        select.value=selectedId||'';
    };
    const showMessage=(text,isError=false)=>{
        message.textContent=text;
        message.className='alert mt-3 '+(isError?'alert-danger':'alert-secondary');
        message.hidden=text==='';
    };
    const updateContinuity=()=>{
        const selected=division.options[division.selectedIndex];
        if(!selected?.value){continuity.hidden=true;if(boundedGapFields)boundedGapFields.hidden=true;if(effectiveTo)effectiveTo.value='';if(endReason)endReason.required=false;if(!refreshing)submit.disabled=false;return;}
        continuity.hidden=false;
        const required=selected.dataset.requiredNextStart||'';
        const last=selected.dataset.lastCoveredThrough||'';
        const relation=selected.dataset.continuityRelation||'';
        const gapEnd=selected.dataset.gapEnd||'';
        const nextStart=selected.dataset.nextExistingStart||'';
        const nextEnd=selected.dataset.nextExistingEnd||'';
        if(historicalCoverage)historicalCoverage.textContent=last?`01 Jan 2025 - ${displayDate(last)}`:'No continuous coverage from 01 Jan 2025';
        lastCovered.textContent=last?displayDate(last):'No covered period from 01 Jan 2025';
        if(missingPeriod)missingPeriod.textContent=required?`${displayDate(required)} - ${gapEnd?displayDate(gapEnd):'Open'}`:'No uncovered period';
        requiredStart.textContent=displayDate(required);
        if(nextExisting)nextExisting.textContent=nextStart?`${displayDate(nextStart)} - ${nextEnd?displayDate(nextEnd):'Open'}`:'None';
        if(maximumEnd)maximumEnd.textContent=gapEnd?displayDate(gapEnd):'Open';
        if(boundedGapFields)boundedGapFields.hidden=gapEnd==='';
        if(effectiveTo)effectiveTo.value=gapEnd;
        if(endReason){endReason.required=gapEnd!=='';if(gapEnd==='')endReason.value='';}
        issueLink.hidden=true;
        if(blockingIssue?.row_key){
            issueLink.href=issueLink.dataset.issueUrl+encodeURIComponent(blockingIssue.row_key);issueLink.hidden=false;
        }else if(blockingIssue?.reconciliation_item_id){
            issueLink.href=issueLink.dataset.reviewUrl+encodeURIComponent(blockingIssue.reconciliation_item_id);issueLink.hidden=false;
        }
        let text='';let style='alert-success';
        if(blockingIssue?.row_key||blockingIssue?.reconciliation_item_id){
            text='This ARPA Division has unresolved Appointment Data Issues. Review and complete them before creating a new appointment request.';style='alert-danger';
        }else if(relation==='GAP'){
            text=last
                ?`This ARPA Division has an uncovered assignment period. The next assignment must start on ${displayDate(required)}.`
                :'This ARPA Division has no assignment history from 01 Jan 2025. Complete the missing period starting 01 Jan 2025 first.';
            style='alert-warning';
        }else if(relation==='OVERLAP'){
            text=required
                ?`The selected date overlaps authoritative assignment history. The required missing period starts on ${displayDate(required)}.`
                :'This ARPA Division has complete Open coverage from the system baseline. There is no uncovered period for a new assignment.';
            style='alert-danger';
        }else if(relation==='EXACT')text='The selected start date preserves ARPA Division assignment continuity.';
        continuityMessage.textContent=text;continuityMessage.className=`alert ${style} mt-3 mb-0`;continuityMessage.hidden=text==='';
        submit.disabled=refreshing||relation!=='EXACT'||Boolean(blockingIssue?.row_key||blockingIssue?.reconciliation_item_id)||(gapEnd!==''&&Boolean(endReason)&&endReason.value==='');
        renderIssues();
    };
    const renderIssues=()=>{
        if(!issuePanel||!issueSummary||!issueList||!issuesReview)return;
        issuePanel.hidden=unresolvedIssues.length===0;
        if(unresolvedIssues.length===0){issueList.replaceChildren();return;}
        issueSummary.textContent=unresolvedIssues.length===1
            ?'This ARPA Division has unresolved historical appointment information. Review and complete the Appointment Data Issue before creating a new appointment request.'
            :`${unresolvedIssues.length} unresolved Appointment Data Issues were found. Every issue must be completed before creating a new appointment request.`;
        const table=document.createElement('div');table.className='table-responsive';
        const rows=unresolvedIssues.map(issue=>{
            const officer=issue.officer||`${issue.officer_number||''} - ${issue.officer_name||'Unknown Officer'}`;
            const type=issue.appointment_type||issue.appointment_types||issue.issue_type||'Appointment Data Issue';
            const period=issue.period||issue.effective_periods||`${issue.issue_from||'Unknown'} to ${(issue.issue_to||'')==='9999-12-31'?'Open':(issue.issue_to||'Open')}`;
            return `<tr><td>${escapeHtml(officer)}</td><td>${escapeHtml(type)}</td><td>${escapeHtml(period)}</td><td><span class="badge bg-warning text-dark">UNRESOLVED</span></td></tr>`;
        }).join('');
        table.innerHTML=`<table class="table table-sm mb-0"><thead><tr><th>Officer</th><th>Appointment Type / Issue</th><th>Period</th><th>Status</th></tr></thead><tbody>${rows}</tbody></table>`;issueList.replaceChildren(table);
        const first=unresolvedIssues[0]||{};
        if(unresolvedIssues.length===1&&first.row_key){issuesReview.href=issueLink.dataset.issueUrl+encodeURIComponent(first.row_key);issuesReview.textContent='Review Data Issue';}
        else if(unresolvedIssues.length===1&&first.reconciliation_item_id){issuesReview.href=issueLink.dataset.reviewUrl+encodeURIComponent(first.reconciliation_item_id);issuesReview.textContent='Review Data Issue';}
        else{issuesReview.href=issueLink.dataset.issueUrl.replace(/issues\/$/,'issues');issuesReview.textContent='Review Data Issues';}
    };
    const escapeHtml=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
    const refresh=async()=>{
        if(pending)pending.abort();
        const request=new AbortController();pending=request;refreshing=true;
        const previous={officer:officer.value,division:division.value,type:appointmentType.value};
        submit.disabled=true;showMessage('Refreshing eligible officers and ARPA Division timelines for the selected date.');
        const target=new URL(form.dataset.optionsUrl,window.location.origin);
        target.searchParams.set('effective_from',date.value);
        target.searchParams.set('officer_id',previous.officer);
        target.searchParams.set('arpa_division_location_id',previous.division);
        target.searchParams.set('appointment_type',previous.type);
        if(asc)target.searchParams.set('asc_location_id',asc.value);
        try{
            const response=await fetch(target.toString(),{headers:{Accept:'application/json'},credentials:'same-origin',signal:request.signal});
            const payload=await response.json();if(!response.ok)throw new Error(payload.error||'Unable to refresh appointment options.');
            replaceOptions(officer,'Select officer',payload.officers,payload.selection.officer_id,'officer');
            replaceOptions(division,'Select ARPA Division',payload.arpa_divisions,payload.selection.arpa_division_location_id,'division');
            blockingIssue=payload.selection.blocking_data_issue||{};
            unresolvedIssues=payload.selection.unresolved_data_issues||[];
            applyAppointmentTypes(payload.selection.appointment_type);
            if(officerEmpty){officerEmpty.textContent=`No eligible ARPA Officers are available for ${payload.display_date}.`;officerEmpty.hidden=payload.officers.length!==0;}
            if(divisionEmpty){divisionEmpty.textContent='No ARPA Divisions are available within this Agrarian Service Center on the selected business date.';divisionEmpty.hidden=payload.arpa_divisions.length!==0;}
            showMessage((payload.messages||[]).join(' '));
        }catch(error){
            if(error.name!=='AbortError')showMessage(error.message||'Unable to refresh appointment options.',true);
        }finally{
            if(pending===request){pending=null;refreshing=false;updateContinuity();}
        }
    };
    officer.addEventListener('change',()=>applyAppointmentTypes(''));
    if(endReason)endReason.addEventListener('change',updateContinuity);
    // Re-run the secured server-side option calculation when the Division
    // changes so its continuity period and any open Data Issue are evaluated
    // together. The retained-selection parameters keep valid form choices.
    division.addEventListener('change',refresh);
    date.addEventListener('change',refresh);
    if(asc)asc.addEventListener('change',refresh);
    applyAppointmentTypes(appointmentType.value);updateContinuity();
})();
