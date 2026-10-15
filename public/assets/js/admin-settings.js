/** 修订号仅用于内部并发检查；页面只呈现未保存/处理中状态，冲突时保留输入供核对。 */
MpayAdmin.ready(function () {
    'use strict';
    const UI=MpayAdmin,forms=Array.from(document.querySelectorAll('[data-settings-group]'));if(!forms.length)return;
    const feedback=document.getElementById('admin-feedback'),refresh=document.getElementById('settings-refresh');
    const fields={payment_defaults:['order_valid_seconds'],maintenance:['technical_log_days'],page_display:['home_enabled','about_enabled']};
    const current=new Map(),stale=new Set(),dirty=new Set(),saving=new Set();let loading=false;
    function controls(){
        forms.forEach(form=>{
            const disabled=loading||saving.has(form.dataset.settingsGroup)||stale.has(form.dataset.settingsGroup)||!current.has(form.dataset.settingsGroup);
            form.querySelector('fieldset').disabled=disabled;
            // 官方 switch 读取原始 input.disabled；同步 fieldset 状态后重新渲染。
            form.querySelectorAll('input[type=checkbox]').forEach(input=>input.disabled=disabled);
            form.querySelector('button[type=submit]').disabled=disabled||!dirty.has(form.dataset.settingsGroup);
            form.querySelector('[data-form-status]').textContent=stale.has(form.dataset.settingsGroup)?'请重新读取后继续':saving.has(form.dataset.settingsGroup)?'正在保存…':dirty.has(form.dataset.settingsGroup)?'有未保存的修改':'';
        });
        layui.form.render('checkbox','settings-page-display');refresh.disabled=loading||saving.size>0;
    }
    function display(group){
        const form=forms.find(item=>item.dataset.settingsGroup===group.group);if(!form)return;
        current.set(group.group,group);stale.delete(group.group);dirty.delete(group.group);
        fields[group.group].forEach(name=>{const input=form.elements[name];if(input.type==='checkbox')input.checked=group.values[name]===1;else input.value=group.values[name];});
        if(group.group==='page_display'){
            const about=document.getElementById('admin-about-nav');
            if(about){about.hidden=group.values.about_enabled!==1;layui.element.render('nav','admin-navigation');}
        }
    }
    function changed(form){
        const group=form.dataset.settingsGroup,previous=current.get(group);if(!previous)return;
        const modified=fields[group].some(name=>{const input=form.elements[name];return (input.type==='checkbox'?(input.checked?1:0):Number(input.value))!==previous.values[name];});
        if(modified)dirty.add(group);else dirty.delete(group);
        form.querySelector('button[type=submit]').disabled=!modified;
        form.querySelector('[data-form-status]').textContent=modified?'有未保存的修改':'';
    }
    async function load(){
        if(loading||saving.size)return;loading=true;controls();UI.feedback(feedback,'正在读取设置…','loading');
        try{const data=(await MpayHttp.request('/adminapi/settings')).data;data.groups.forEach(display);document.getElementById('settings-origin').textContent=location.origin;UI.feedback(feedback,'');}
        catch(exception){UI.error(feedback,exception);}finally{loading=false;controls();}
    }
    forms.forEach(form=>{
        const group=form.dataset.settingsGroup;
        form.addEventListener('input',()=>changed(form));
        form.addEventListener('submit',async event=>{
            event.preventDefault();if(loading||saving.has(group)||stale.has(group)||!dirty.has(group)||!UI.valid(form))return;
            const values={};
            try{
                fields[group].forEach(name=>{
                    const input=form.elements[name];
                    if(input.type==='checkbox'){values[name]=input.checked?1:0;return;}
                    const text=input.value;if(!/^(0|[1-9][0-9]*)$/.test(text)||!Number.isSafeInteger(Number(text)))throw new Error('请输入范围内的整数');values[name]=Number(text);
                });
                saving.add(group);controls();UI.feedback(feedback,'');
                const result=await MpayHttp.request('/adminapi/settings/save',{method:'POST',data:{group,expected_version:current.get(group).version,values,}});
                display(result.data);UI.feedback(feedback,'');UI.message('设置已保存');
            }catch(exception){if(exception.code===40910||exception.outcomeUnknown===true)stale.add(group);UI.error(feedback,exception);if(stale.has(group))feedback.textContent+='。当前输入已保留，请重新读取核对结果后继续。';}
            finally{saving.delete(group);controls();}
        });
    });
    layui.form.on('switch(page-display)',event=>changed(event.elem.form));
    refresh.addEventListener('click',async()=>{if(dirty.size&&!await UI.confirm({title:'重新读取设置',content:'确定重新读取设置？',description:'尚未保存的输入将被替换。',confirmText:'重新读取'}))return;load();});load();
});
