/** 按设置组保留版本及脏状态；冲突或提交结果不明时先重新读取，不自动覆盖。 */
MpayAdmin.ready(function () {
    'use strict';
    const UI=MpayAdmin,forms=Array.from(document.querySelectorAll('[data-settings-group]'));if(!forms.length)return;
    const feedback=document.getElementById('admin-feedback'),refresh=document.getElementById('settings-refresh');
    const fields={payment_defaults:['order_valid_seconds'],maintenance:['technical_log_days']};
    const current=new Map(),stale=new Set(),dirty=new Set(),saving=new Set();let loading=false;
    function controls(){forms.forEach(form=>form.querySelector('fieldset').disabled=loading||saving.has(form.dataset.settingsGroup)||stale.has(form.dataset.settingsGroup)||!current.has(form.dataset.settingsGroup));refresh.disabled=loading||saving.size>0;}
    function display(group){const form=forms.find(item=>item.dataset.settingsGroup===group.group);if(!form)return;current.set(group.group,group);stale.delete(group.group);dirty.delete(group.group);fields[group.group].forEach(name=>form.elements[name].value=group.values[name]);form.querySelector('[data-version]').textContent=group.source==='deployment'?'使用默认值':'已保存 · 版本 '+group.version;}
    async function load(){
        if(loading||saving.size)return;loading=true;controls();UI.feedback(feedback,'正在读取设置…','loading');
        try{const data=(await MpayHttp.request('/adminapi/settings')).data;data.groups.forEach(display);document.getElementById('settings-origin').textContent=location.origin;UI.feedback(feedback,'');}
        catch(exception){UI.error(feedback,exception);}finally{loading=false;controls();}
    }
    forms.forEach(form=>{
        const group=form.dataset.settingsGroup;
        form.addEventListener('input',()=>dirty.add(group));
        form.addEventListener('submit',async event=>{
            event.preventDefault();if(loading||saving.has(group)||stale.has(group)||!UI.valid(form))return;
            const values={};
            try{
                fields[group].forEach(name=>{const text=form.elements[name].value;if(!/^(0|[1-9][0-9]*)$/.test(text)||!Number.isSafeInteger(Number(text)))throw new Error('请输入范围内的整数');values[name]=Number(text);});
                saving.add(group);controls();UI.feedback(feedback,'正在保存…','loading');
                const result=await MpayHttp.request('/adminapi/settings/save',{method:'POST',data:{group,expected_version:current.get(group).version,values,}});
                display(result.data);UI.feedback(feedback,'');UI.message('设置已保存');
            }catch(exception){if(exception.code===40910||exception.outcomeUnknown===true)stale.add(group);UI.error(feedback,exception);if(stale.has(group))feedback.textContent+='。当前输入已保留，请重新读取核对结果后继续。';}
            finally{saving.delete(group);controls();}
        });
    });
    refresh.addEventListener('click',async()=>{if(dirty.size&&!await UI.confirm({title:'重新读取设置',content:'确定重新读取设置？',description:'尚未保存的输入将被替换。',confirmText:'重新读取'}))return;load();});load();
});
