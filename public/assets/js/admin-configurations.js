/**
 * 通道只维护名称、限额与插件配置引用；插件表单完全由已同步 schema 生成。
 * 新增在一个服务事务中保存；编辑通道和配置插件使用独立接口，不互相提交字段。
 */
MpayAdmin.ready(function () {
    'use strict';
    const UI=MpayAdmin,E=UI.escape,filters=document.getElementById('configuration-filters');if(!filters)return;
    const api='/adminapi/configuration/',feedback=document.getElementById('admin-feedback'),query=new URLSearchParams(location.search);
    let plugins=[],channels=[],loading=false;
    const matchesCondition=(condition,value)=>Array.isArray(condition.in)?condition.in.includes(value):value===condition.value;
    const get=(path,values={})=>MpayHttp.request(api+path+'?'+new URLSearchParams(values)).then(result=>result.data);
    const post=(path,data)=>MpayHttp.request(api+path,{method:'POST',data});
    const versions=detail=>({id:detail.id,expected_revision:detail.revision_id,expected_configuration_version:detail.configuration_version});
    const channelValues=form=>({name:form.elements.name.value,minimum_amount:UI.cents(form.elements.minimum_amount.value),maximum_amount:UI.cents(form.elements.maximum_amount.value),daily_limit_amount:UI.cents(form.elements.daily_limit_amount.value),daily_limit_count:Number(form.elements.daily_limit_count.value),available_from:form.elements.available_from.value||null,available_to:form.elements.available_to.value||null});
    const pluginName=code=>UI.catalog.plugins.find(item=>item.code===code)?.name||code;

    function renderList(){
        const f=filters.elements,search=f.search.value.trim().toLowerCase();
        const rows=channels.filter(row=>row.name.toLowerCase().includes(search)&&(!f.pay_type.value||row.pay_types.includes(f.pay_type.value))
            &&(f.enabled.value===''||row.enabled===(f.enabled.value==='1'))&&(!query.has('configuration_id')||row.configuration_id===query.get('configuration_id')));
        UI.table({elem:'#configuration-channels',id:'configuration-channels',data:rows,cols:[[
            {field:'name',title:'通道名称',minWidth:180,templet:row=>E(row.name)},
            {title:'支付方式',width:135,unresize:true,align:'center',templet:row=>row.pay_types.map(UI.payment).join(' ')},
            {title:'插件',minWidth:190,templet:row=>E(pluginName(row.plugin_code))},
            {title:'状态',width:110,align:'center',templet:row=>UI.badge(row.enabled?'已启用':'已停用',row.enabled?'green':'gray')},
            {title:'操作',width:240,align:'center',fixed:'right',templet:()=>'<div class="admin-inline-actions"><button type="button" class="admin-link" lay-event="detail">查看</button><button type="button" class="admin-link" lay-event="edit">编辑</button><button type="button" class="admin-link" lay-event="configure">配置</button><button type="button" class="admin-link admin-channel-more" lay-event="more">更多 '+UI.icon('down')+'</button></div>'}
        ]],emptyText:channels.length?'没有符合条件的通道':'暂无收款通道'});
        document.querySelector('#configuration-channels + .layui-table-view').hidden=!channels.length;
        const empty=document.getElementById('configuration-empty');empty.replaceChildren();if(!channels.length)UI.empty(empty,'暂无收款通道',{label:'新增通道',run:create});
    }
    async function load(){
        if(loading)return;loading=true;document.getElementById('configuration-new').disabled=true;UI.feedback(feedback,'正在读取通道…','loading');
        try{
            await UI.loadCatalog(true);const data=await Promise.all([get('plugins'),UI.all(api+'channels')]);
            plugins=data[0].items;channels=data[1];UI.render(filters);renderList();UI.feedback(feedback,'');
        }catch(error){UI.error(feedback,error);}finally{loading=false;document.getElementById('configuration-new').disabled=false;}
        if(query.get('action')==='create'){query.delete('action');create();}
    }
    function channelFields(channel,participates=true){
        return '<div class="admin-channel-basic"><div class="admin-section-title">通道信息</div><div class="admin-form-grid">'+UI.field('通道名称',UI.input('name',channel?.name||'','required maxlength="128"'),{required:true,full:true})
            +UI.field('单笔最小金额',UI.unit('minimum_amount',channel?UI.money(channel.minimum_amount):'0.01','元','required inputmode="decimal"'),{required:true})
            +UI.field('单笔最大金额',UI.unit('maximum_amount',channel?UI.money(channel.maximum_amount):'9999999.99','元','required inputmode="decimal"'),{required:true,help:'按业务订单原金额限制是否选用本通道。'})+'</div></div>'
            +'<div class="layui-collapse admin-channel-strategies" data-admin-collapse><div class="layui-colla-item"><h2 class="layui-colla-title">高级选路策略</h2><div class="layui-colla-content"><div class="admin-form-grid">'
            +UI.field('默认收款','<input type="checkbox" name="participates_default_route" lay-skin="switch" lay-text="启用|关闭"'+(participates?' checked':'')+'>',{full:true,help:'关闭后，此通道不参与默认收款，仍可用于自定义轮询组。'})
            +UI.field('每日限额',UI.unit('daily_limit_amount',UI.money(channel?.daily_limit_amount||0),'元','required inputmode="decimal"'),{help:'0 表示不限。按上海时区当天发起的支付单统计，计入已成功及有效待付款订单的原金额；失败、关闭、超时不占用额度。'})
            +UI.field('每日限笔',UI.unit('daily_limit_count',channel?.daily_limit_count||0,'笔','required type="number" min="0" max="1000000" step="1"'),{help:'0 表示不限。统计范围与每日限额一致。达到限制后跳过本通道。'})
            +UI.field('可用开始时间',UI.input('available_from',channel?.available_from||'','data-admin-date="time" placeholder="留空为全天"'),{help:'上海时区；开始与结束须同时填写。可跨午夜，例如 22:00 至次日 06:00。'})
            +UI.field('可用结束时间',UI.input('available_to',channel?.available_to||'','data-admin-date="time" placeholder="留空为全天"'))+'</div></div></div></div>';
    }

    /**
     * 只解释声明中的控件类型与有限展示属性，不按插件 code 拼字段，不执行 schema 中的脚本。
     * 图片先在表单预览，保存时经统一上传接口取得资产 ID；所有字段按原值回填并直接保存；密钥只控制显示，不改变保存语义。
     */
    function pluginFields(target,definition,configuration,handle,form){
        const fields=[],pending=new Map();target.replaceChildren();
        if(definition.description?.trim()){const description=document.createElement('div');description.className='admin-field full admin-plugin-description';description.textContent=definition.description;target.append(description);}
        for(const field of definition.schema){
            const stored=configuration?.settings||{},present=Object.hasOwn(stored,field.field),value=present?stored[field.field]:(field.value??'');
            const required=Object.values(field.validate).includes('require'),full=['textarea','list','upload'].includes(field.type);
            const name='setting_'+field.field,wrapper=document.createElement('div');
            let control;
            if(field.type==='upload'){
                control='<input type="hidden" name="'+E(name)+'" value="'+E(value)+'"><div class="admin-upload"><button type="button" class="layui-btn layui-btn-primary" data-pick-image>选择图片</button><button type="button" class="admin-link" data-remove-image>移除图片</button><img class="admin-qr-preview" alt="'+E(field.title)+'预览" hidden></div>';
            }else if(field.type==='select')control=UI.select(name,field.options||[],value,required?'required':'');
            else if(field.type==='switch')control='<input name="'+E(name)+'" type="checkbox" lay-skin="switch"'+(value?' checked':'')+'>';
            else{
                const attributes=[required?'required':'',field.secret?'autocomplete="new-password"':'autocomplete="off"'];
                if(field.type==='number')attributes.push('type="number" step="1"','min="'+E(field.validate.between?.[0]??'')+'"','max="'+E(field.validate.between?.[1]??'')+'"');
                else if(field.secret)attributes.push('type="password" lay-affix="eye"');
                if(field.validate.length)attributes.push('maxlength="'+E(field.validate.length[1])+'"','minlength="'+E(field.validate.length[0])+'"');
                if(field.placeholder)attributes.push('placeholder="'+E(field.placeholder)+'"');
                if(field.rows)attributes.push('rows="'+E(field.rows)+'"');
                const text=Array.isArray(value)?value.join('\n'):value;
                control=['textarea','list'].includes(field.type)?UI.textarea(name,text,attributes.join(' ')):UI.input(name,text,attributes.join(' '));
            }
            wrapper.innerHTML=UI.field(field.title,control,{required,full,help:field.help});
            const node=wrapper.firstElementChild;node.dataset.pluginField=field.field;target.append(node);
            const input=node.querySelector('[name="'+name+'"]');
            if(field.type==='upload'){
                const image=node.querySelector('img'),remove=node.querySelector('[data-remove-image]');remove.hidden=!value;
                const preview=url=>{image.hidden=!url;remove.hidden=!url;if(url)image.src=url;else image.removeAttribute('src');};
                if(value)get('image',{id:value}).then(data=>{if(!handle.closed&&input.value===value&&!pending.has(field.field))preview(data.url);}).catch(error=>handle.error(error));
                UI.imagePicker(node.querySelector('[data-pick-image]'),(file,object)=>{
                    if(handle.busy||handle.closed)return;pending.set(field.field,file);form.dispatchEvent(new Event('input',{bubbles:true}));
                    object.preview((index,selected,result)=>{if(!handle.closed&&pending.get(field.field)===file)preview(result);});
                });
                remove.addEventListener('click',()=>{pending.delete(field.field);input.value='';preview('');form.dispatchEvent(new Event('input',{bubbles:true}));});
            }
            fields.push({field,input,present,required,node});
        }
        const visibility=()=>fields.forEach(item=>{
            const condition=item.field.visible_when;if(!condition)return;
            const source=fields.find(other=>other.field.field===condition.field);
            item.node.hidden=!source||!matchesCondition(condition,source.field.type==='switch'?source.input.checked:source.field.type==='number'?Number(source.input.value):source.input.value);
            // 显示条件同时限定必填范围；隐藏字段仍保留原值及类型/格式约束。
            item.input.required=item.required&&!item.node.hidden;
        });
        target.addEventListener('change',visibility);visibility();UI.render(form);
        return {
            async values(){
                for(const [field,file] of pending){const data=new FormData();data.set('image',file);const result=await MpayHttp.request(api+'image-upload',{method:'POST',data,timeout:30000});fields.find(item=>item.field.field===field).input.value=result.data.id;pending.delete(field);}
                const settings={};
                fields.forEach(({field,input,present,required,node})=>{
                    // 未填写的可选数字保持缺失，不能被 Number('') 静默改成 0；列表只分行，不修剪插件的文本。
                    if(input.value===''&&(!required||node.hidden)&&(field.type==='number'||(!Object.hasOwn(field,'value')&&!present)))return;
                    settings[field.field]=field.type==='number'?(input.value===''?'':Number(input.value)):field.type==='switch'?input.checked:field.type==='list'?input.value.split(/\r?\n/).filter(line=>line!==''):input.value;
                });
                return {settings};
            },
            dispose(){pending.clear();target.removeEventListener('change',visibility);}
        };
    }

    /** 业务向导只编排步骤；面板、切换、验证及弹层仍使用 Layui 官方组件。 */
    function channelForm(detail=null){
        if(loading)return;
        const available=UI.catalog.types.filter(type=>type.enabled||type.code===detail?.channel.pay_type);
        if(!available.length){UI.message('请先启用一种支付方式');return;}
        let definition=null,fields=null,step=0,highest=0,go=null;
        const originalCode=detail?.plugin_code;
        // 官方 radio 的 lay-radio 标题模板承载卡片视觉，选择状态和键盘行为仍交给 form。
        const choice=(name,value,title,content,checked=false)=>'<div class="admin-channel-option"><input type="radio" name="'+E(name)+'" value="'+E(value)+'" title="'+E(title)+'" lay-skin="none" required'+(checked?' checked':'')+'><div lay-radio class="admin-channel-choice">'+content+'<i class="layui-icon layui-icon-ok-circle admin-choice-check" aria-hidden="true"></i></div></div>';
        const access=detail?'<div class="admin-form-grid">'+UI.field('支付方式',UI.select('pay_type',available.map(type=>({value:type.code,label:type.name})),detail.channel.pay_type,'required'),{required:true,full:true})+'</div>'
            :'<div class="admin-section-title">选择支付方式</div><div class="admin-payment-choices">'+available.map((type,index)=>choice('pay_type',type.code,type.name,
                (type.logo?'<img class="admin-payment-logo" src="'+E(type.logo)+'" alt="'+E(type.name)+'">':'<strong>'+E(type.name)+'</strong>'),index===0)).join('')+'</div>';
        const plugin=detail?'<div class="admin-form-grid">'+UI.field('支付插件',UI.select('plugin_code',[],'','required lay-search'),{required:true,full:true})+'</div>'
            :'<div class="admin-section-title">选择支付插件</div><div class="channel-plugin-choices"></div>';
        const parameters='<div class="channel-settings admin-form-grid"></div>';
        UI.form({title:detail?'编辑通道 · '+detail.channel.name:'新增通道',width:860,className:detail?'admin-channel-editor':'admin-channel-wizard',
            submit:detail?'保存通道':'下一步',secondarySubmit:detail?null:'仅保存',
            validationRoot:form=>detail?form:form.querySelectorAll('.layui-tabs-item')[step],
            content:detail?'<div class="admin-section-title">支付接入</div>'+access+plugin+channelFields(detail.channel,detail.participates_default_route)+parameters
                :UI.tabs([{title:'1 · 支付方式',content:access},{title:'2 · 支付插件',content:plugin},{title:'3 · 通道配置',content:channelFields(null)},{title:'4 · 插件参数',content:parameters}]),
            onOpen(handle,form){
                const settings=form.querySelector('.channel-settings');
                function choosePlugin(){
                    const next=plugins.find(item=>item.code===form.elements.plugin_code?.value)||null;
                    if(next?.code===definition?.code)return;
                    fields?.dispose();fields=null;settings.replaceChildren();definition=next;
                    if(definition&&(!detail||definition.code!==originalCode))fields=pluginFields(settings,definition,null,handle,form);
                    UI.render(form);
                }
                function chooseType(){
                    const previous=form.elements.plugin_code?.value;
                    const supported=plugins.filter(item=>(item.enabled||item.code===originalCode)&&item.pay_types.includes(form.elements.pay_type.value));
                    if(detail){
                        form.elements.plugin_code.replaceChildren(new Option(supported.length?'请选择插件':'暂无已启用的支持插件',''),...supported.map(item=>new Option(item.name,item.code)));
                        if(supported.some(item=>item.code===previous))form.elements.plugin_code.value=previous;
                    }else{
                        const host=form.querySelector('.channel-plugin-choices');host.replaceChildren();
                        if(supported.length)host.innerHTML=supported.map(item=>choice('plugin_code',item.code,item.name,'<span class="admin-plugin-choice-icon">'+UI.icon('component')+'</span><span class="admin-plugin-choice-label"><strong>'+E(item.name)+'</strong>'+UI.badge(item.version,'gray')+'</span>',item.code===previous)).join('');
                        else UI.empty(host,'暂无支持该支付方式的已启用插件');
                    }
                    choosePlugin();highest=0;UI.render(form);
                }
                form.addEventListener('change',event=>{if(event.target.name==='pay_type')chooseType();else if(event.target.name==='plugin_code')choosePlugin();});
                chooseType();
                if(detail){form.elements.plugin_code.value=originalCode;choosePlugin();UI.render(form);return;}
                const tabs=form.querySelector('[data-admin-tabs]'),id=form.id+'-steps';tabs.id=id;
                const stepTitles=['支付方式','支付插件','通道配置','插件参数'];
                tabs.querySelectorAll('.layui-tabs-header>li').forEach((item,index)=>{item.innerHTML='<span class="admin-step-number">'+(index+1)+'</span><span>'+stepTitles[index]+'</span>';});
                // 公共 render 已按模板初始化，显式实例 ID 后刷新，以官方 API 控制业务前后步骤。
                layui.tabs.render({elem:tabs,id,index:0});
                const footer=handle.node.querySelector('.admin-dialog-footer'),primary=footer.querySelector('button[type=submit]:not([data-submit-action])'),secondary=footer.querySelector('[data-submit-action]');
                const back=document.createElement('button');back.type='button';back.className='layui-btn layui-btn-primary';back.textContent='上一步';primary.before(back);
                const update=()=>{back.hidden=step===0;primary.textContent=step===3?'保存并启用':'下一步';secondary.hidden=step!==3;
                    tabs.querySelectorAll('.layui-tabs-header>li').forEach((item,index)=>{item.classList.toggle('admin-step-complete',index<step);item.setAttribute('aria-current',index===step?'step':'false');});};
                go=index=>{layui.tabs.change(id,index);};
                layui.tabs.on('beforeChange('+id+')',data=>{
                    if(handle.busy)return false;
                    if(data.to.index>step){
                        if(data.to.index>Math.max(highest,step+1)||!UI.valid(form.querySelectorAll('.layui-tabs-item')[step]))return false;
                        if(step===1&&!definition){UI.message('请选择支付插件');return false;}
                    }
                });
                layui.tabs.on('afterChange('+id+')',data=>{step=data.index;highest=Math.max(highest,step);update();UI.render(form);handle.feedback('');});
                // 上一步保留所有已填写字段；切换插件才重新生成插件参数。
                back.addEventListener('click',()=>go(step-1));update();
                // 下一步不发请求；到最后一步才进入公共表单的防重复提交处理。
                primary.type='button';primary.addEventListener('click',()=>{
                    if(step<3)go(step+1);
                    else form.dispatchEvent(new SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:primary}));
                });
                form.addEventListener('keydown',event=>{if(event.key==='Enter'&&step<3&&event.target.tagName!=='TEXTAREA'){event.preventDefault();go(step+1);}});
            },
            async onSubmit(form,handle,action){
                if(!detail&&step<3)return;
                if(!definition)throw new Error('请选择已同步并启用的插件');
                const data={...(detail?versions(detail):{}),plugin_code:definition.code,pay_type:form.elements.pay_type.value,channel:channelValues(form),participates_default_route:form.elements.participates_default_route.checked,settings:{}};
                if(!detail)data.enabled=action!=='secondary';
                if(fields)data.settings=(await fields.values()).settings;
                const result=await post(detail?'editor-save':'editor-create',data);handle.clean();handle.close();await load();
                UI.message(result.data.enabled?(data.participates_default_route?'通道已启用，已加入默认收款':'通道已启用，可配置自定义路由'):'通道已保存，尚未启用');
            },onClose(){fields?.dispose();}
        });
    }
    function create(){channelForm();}
    async function edit(id){
        try{channelForm(await get('channel',{id}));}catch(error){UI.error(feedback,error);}
    }
    async function configure(id){
        try{const detail=await get('channel',{id}),configuration=await get('detail',{id:detail.configuration_id});
            if(configuration.config_version!==detail.configuration_version)throw new Error('配置已变化，请重新打开');let fields;
            // 通道共享关系不属于插件声明内容，不插入插件配置表单正文。
            UI.form({title:'配置插件 · '+detail.channel.name,width:820,
                content:'<div class="admin-section-title">'+E(configuration.plugin.name)+'</div>'
                    +'<div class="channel-settings admin-form-grid"></div>',
                onOpen(handle,form){fields=pluginFields(form.querySelector('.channel-settings'),configuration.plugin,configuration,handle,form);},
                async onSubmit(form,handle){const values=await fields.values();await post('plugin-save',{...versions(detail),...values});handle.clean();handle.close();await load();UI.message('插件配置已保存，请核对后启用此通道');},
                onClose(){fields?.dispose();}});
        }catch(error){UI.error(feedback,error);}
    }
    async function details(id){
        const handle=UI.open({title:'通道详情',drawer:true,width:820,content:'<div class="channel-detail"></div>'});handle.feedback('正在读取…','loading');
        try{const detail=await get('channel',{id}),configuration=await get('detail',{id:detail.configuration_id});if(handle.closed)return;
            // 详情沿用 schema 顺序与严格类型的显示条件；说明/帮助仅用于配置表单。
            // 每个字段保留独立行，避免同名标题相互覆盖；图片原位留入口，预览集中放在下方。
            const rows=[],images=[];configuration.plugin.schema.forEach(field=>{
                if(field.visible_when&&!matchesCondition(field.visible_when,configuration.settings[field.visible_when.field]))return;
                const value=configuration.settings[field.field];
                const empty=value==null||value===''||(Array.isArray(value)&&value.length===0);
                let content;
                if(empty)content='未设置';
                else if(field.secret)content='已配置';
                else if(field.type==='upload'){
                    const targetId=handle.node.id+'-plugin-image-'+images.length;
                    images.push({id:value,title:field.title,targetId});
                    content='<button type="button" class="admin-link" data-plugin-image="'+E(targetId)+'" aria-controls="'+E(targetId)+'">已上传，查看图片</button>';
                }else if(field.type==='list')content='<ul class="admin-plugin-values">'+value.map(item=>'<li>'+E(item)+'</li>').join('')+'</ul>';
                else content=E(field.type==='select'?(field.options.find(option=>option.value===value)?.label??value):typeof value==='boolean'?(value?'是':'否'):value);
                rows.push('<dt>'+E(field.title)+'</dt><dd>'+content+'</dd>');
            });
            const content=UI.facts({'通道名称':detail.channel.name,'支付方式':UI.paymentLabels[detail.channel.pay_type],'插件':configuration.plugin.name,'单笔最小金额（元）':UI.money(detail.channel.minimum_amount),'单笔最大金额（元）':UI.money(detail.channel.maximum_amount),'每日限额（元）':detail.channel.daily_limit_amount?UI.money(detail.channel.daily_limit_amount):'不限','每日限笔':detail.channel.daily_limit_count||'不限','可用时段':detail.channel.available_from?detail.channel.available_from+'～'+detail.channel.available_to+'（上海时区）':'全天'})
                +UI.tabs([{title:'插件参数',content:'<dl class="admin-facts">'+rows.join('')+'</dl>'+images.map(image=>'<section id="'+E(image.targetId)+'" tabindex="-1" aria-label="'+E(image.title)+'"><div class="admin-section-title">'+E(image.title)+'</div><img class="admin-qr-preview" alt="'+E(image.title)+'"></section>').join('')},
                    {title:'接入信息',content:detail.notification.path?'<div class="admin-code">'+E(location.origin+detail.notification.path)+'</div><button class="layui-btn layui-btn-primary" data-copy="'+E(location.origin+detail.notification.path)+'">复制通知地址</button>':UI.notice(detail.notification.mode==='polling'?'由后台采集任务查询到账流水。':'当前插件没有通知地址。')}]);
            handle.node.querySelector('.channel-detail').innerHTML=UI.state(detail.enabled?'已启用':'已停用')+content;UI.render(handle.node);handle.feedback('');
            handle.node.querySelectorAll('[data-plugin-image]').forEach(button=>button.addEventListener('click',()=>{
                const target=handle.node.querySelector('#'+CSS.escape(button.dataset.pluginImage));
                target.focus({preventScroll:true});target.scrollIntoView({block:'nearest'});
            }));
            for(const image of images){const data=await get('image',{id:image.id});if(handle.closed)return;handle.node.querySelector('#'+CSS.escape(image.targetId)+' img').src=data.url;}
        }catch(error){if(!handle.closed)handle.error(error);}
    }
    async function changeState(id,enabled,remove=false){
        let detail;try{detail=await get('channel',{id});}catch(error){UI.error(feedback,error);return;}
        const title=remove?'删除通道':enabled?'启用通道':'停用通道';
        const confirmed=await UI.confirm({title,content:'确定'+title.slice(0,2)+'“'+detail.channel.name+'”？',confirmText:title.slice(0,2),danger:remove,
            description:remove?'删除后不再用于新收款，已有订单和流水保留。请先移除轮询组引用。':enabled?'启用后允许本通道接收新订单。':'停用后不再接收新订单，已有订单继续处理。',
            onConfirm(){const data=versions(detail);if(!remove)data.enabled=enabled;return post(remove?'channel-delete':'editor-enabled',data);},
            onError(error,target){if(error.data?.errors?.groups){const a=document.createElement('a');a.className='admin-link admin-confirm-link';a.href='/admin/poll-groups?channel_id='+encodeURIComponent(id);a.textContent='查看引用的轮询组';target.append(a);}}
        });
        if(confirmed){UI.message(title+'完成');await load();}
    }
    /** 每次打开表单创建一个幂等键；超时后保留原金额与键，重试只取回同一收银台。 */
    async function testChannel(channel){
        let applications;
        try {
            applications=(await MpayHttp.request('/adminapi/applications')).data.items.filter(app=>app.enabled)
                .sort((a,b)=>a.created_at.localeCompare(b.created_at)||(BigInt(a.id)<BigInt(b.id)?-1:BigInt(a.id)>BigInt(b.id)?1:0));
        } catch(error){UI.error(feedback,error);return;}
        if(!applications.length){UI.message('请先启用一个应用');return;}
        const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);
        const requestKey=Array.from(bytes,value=>value.toString(16).padStart(2,'0')).join('');
        UI.form({title:'测试通道 · '+channel.name,width:460,submit:'发起支付',retrySafe:true,
            // 复用公共表单网格的行间距；窄弹窗内每个字段独占一行，说明归属金额字段。
            content:'<div class="admin-form-grid">'
                +UI.field('应用',UI.select('app_id',applications.map(app=>({value:app.id,label:app.name+' · PID '+app.pid})),applications[0].id,'required lay-search'),{required:true,full:true})
                +UI.field('测试金额',UI.unit('money','1.00','元','required inputmode="decimal" autocomplete="off"'),{required:true,full:true,hint:'本次为真实付款，支付后不会自动退款。'})
                +'</div>',
            async onSubmit(form,handle){
                const money=form.elements.money.value,appId=form.elements.app_id.value;
                if(UI.cents(money)<1)throw new Error('测试金额必须大于 0');
                form.elements.money.readOnly=true;form.elements.app_id.disabled=true;UI.render(form);
                // 在用户点击中打开页签，避免异步建单返回后被浏览器拦截；失败时关闭空白页。
                const paymentWindow=window.open('about:blank','_blank');if(paymentWindow)paymentWindow.opener=null;
                try{
                    const result=(await post('channel-test',{app_id:appId,channel_id:channel.id,money,request_key:requestKey})).data;
                    handle.clean();handle.close();
                    // 本站接口生成收银台相对地址；访问凭证由收银台入口校验。
                    if(paymentWindow&&!paymentWindow.closed)paymentWindow.location.replace(result.cashier_url);else location.assign(result.cashier_url);
                }catch(error){
                    paymentWindow?.close();
                    // 字段校验拒绝尚未建单，可修正金额；未知结果必须保留原参数重投同一请求。
                    if(error.code===42200){form.elements.money.readOnly=false;form.elements.app_id.disabled=false;UI.render(form);}
                    throw error;
                }
            }});
    }
    layui.table.on('tool(configuration-channels)',event=>{
        if(event.event==='detail')details(event.data.id);else if(event.event==='edit')edit(event.data.id);else if(event.event==='configure')configure(event.data.id);
        else if(event.event==='more')layui.dropdown.render({elem:event.tr.find('[lay-event=more]')[0],show:true,data:[
            {id:'test',title:'测试',templet:'<span class="layui-font-blue">{{= d.title }}</span>'},
            {id:'enabled',title:event.data.enabled?'停用':'启用',templet:'<span class="'+(event.data.enabled?'layui-font-orange':'layui-font-green')+'">{{= d.title }}</span>'},
            {type:'-'},{id:'delete',title:'删除',templet:'<span class="layui-font-red">{{= d.title }}</span>'}
        ],click:item=>{if(item.id==='test'){testChannel(event.data);return;}changeState(event.data.id,!event.data.enabled,item.id==='delete');}});
    });
    filters.addEventListener('submit',event=>{event.preventDefault();renderList();});UI.onReset(filters,()=>{query.delete('configuration_id');UI.render(filters);renderList();});
    document.getElementById('configuration-new').addEventListener('click',create);document.getElementById('configuration-refresh').addEventListener('click',load);
    window.addEventListener('mpay:catalog-updated',load);load().then(()=>{if(query.has('channel_id'))details(query.get('channel_id'));});
});
