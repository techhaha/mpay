/** 目录管理只负责表单与操作反馈；支持范围、版本和引用约束由服务复核。 */
MpayAdmin.ready(function () {
    'use strict';
    const UI=MpayAdmin, E=UI.escape, api='/adminapi/catalog';
    const post=(action,data)=>MpayHttp.request(api+'/'+action,{method:'POST',data});
    const supported=code=>UI.catalog.plugins.filter(plugin=>plugin.installed&&plugin.pay_types.includes(code));
    const enabledLabel=enabled=>UI.badge(enabled?'已启用':'已停用',enabled?'green':'gray');
    let directoryDialog=null;

    function drawTypes() {
        if (!directoryDialog || directoryDialog.closed) return;
        const node=directoryDialog.node.querySelector('.payment-type-list');
        directoryDialog.node.querySelector('[data-type-count]').textContent=UI.catalog.types.length+' 种';
        node.innerHTML='<table class="payment-types-table"></table>';
        UI.table({elem:node.querySelector('table'),data:UI.catalog.types,className:'admin-table-two-line',cols:[[
            {title:'支付方式',width:140,unresize:true,align:'center',templet:type=>'<div class="admin-two-line">'+UI.payment(type.code)+'<small class="admin-row-sub">'+E(type.code)+'</small></div>'},
            {field:'sort_order',title:'排序',width:80,align:'center'},
            {title:'状态',width:100,align:'center',templet:type=>enabledLabel(type.enabled)},
            // 单一支付方式保持固定宽度，由此列接收余宽，避免表体右侧留白；窄屏沿用官方横向滚动。
            {title:'支持插件',minWidth:120,align:'center',templet:type=>UI.badge(supported(type.code).length?supported(type.code).length+' 个':'暂无插件',supported(type.code).length?'blue':'orange')},
            {title:'操作',width:130,align:'center',fixed:'right',templet:type=>'<div class="admin-inline-actions"><button class="admin-link" data-edit-type="'+E(type.id)+'">编辑</button><button class="admin-link danger" data-delete-type="'+E(type.id)+'" '+(type.referenced?'disabled title="已有引用，可通过编辑停用"':'')+'>删除</button></div>'}
        ]]});
    }

    async function reload() {
        await UI.loadCatalog(true);
        UI.render(document);
        drawTypes();drawPlugins();
        window.dispatchEvent(new CustomEvent('mpay:catalog-updated'));
    }

    function openTypes() {
        directoryDialog=UI.open({title:'支付方式',width:720,
            content:'<div class="admin-section-title admin-catalog-toolbar"><span>支付方式 <span class="admin-muted" data-type-count>'+UI.catalog.types.length+' 种</span></span><button type="button" class="layui-btn" data-new-type>'+UI.icon('add-1')+'新增支付方式</button></div><div class="payment-type-list"></div>'});
        directoryDialog.node.addEventListener('click',event=>{
            if(event.target.closest('[data-new-type]'))editType(null);
            const edit=event.target.closest('[data-edit-type]'),remove=event.target.closest('[data-delete-type]');
            if(edit)editType(UI.catalog.types.find(type=>type.id===edit.dataset.editType));
            if(remove)deleteType(UI.catalog.types.find(type=>type.id===remove.dataset.deleteType));
        });
        drawTypes();
    }

    function editType(type) {
        const assets={icon:type?.icon_asset_id||null,logo:type?.logo_asset_id||null};
        const imageField=(kind,title,url)=>'<div class="admin-field"><label>'+title+'</label><div class="admin-catalog-image" data-image-box="'+kind+'">'+(url?'<img src="'+E(url)+'" alt="'+title+'">':UI.icon('picture'))+'</div><div class="admin-inline-actions"><button type="button" class="layui-btn layui-btn-primary layui-btn-sm" data-image="'+kind+'">选择图片</button><button type="button" class="admin-link" data-default-image="'+kind+'">使用默认</button></div></div>';
        UI.form({title:type?'编辑支付方式 · '+type.name:'新增支付方式',width:680,
            content:'<div class="admin-form-grid">'+UI.field('名称',UI.input('name',type?.name||'','required maxlength="64"'),{required:true})+
                UI.field('编码',UI.input('code',type?.code||'','required maxlength="32" pattern="[a-z][a-z0-9_]{0,31}"'+(type?.referenced?' readonly':'')),{required:true,help:'小写字母开头，可包含数字和下划线；接入网站使用此编码。已有引用时不可修改。'})+
                UI.field('排序',UI.input('sort_order',type?.sort_order||0,'required type="number" min="0" max="65535" step="1"'))+
                UI.field('状态',UI.select('enabled',[{value:'1',label:'启用'},{value:'0',label:'停用'}],type?.enabled?'1':'0'))+
                imageField('icon','小图标',type?.icon)+imageField('logo','完整标识',type?.logo)+'</div>',
            onOpen(handle,form){
                form.querySelectorAll('[data-image]').forEach(button=>UI.imagePicker(button,file=>handle.run(async()=>{
                        const data=new FormData();data.append('image',file);
                        const result=await MpayHttp.request('/adminapi/configuration/image-upload',{method:'POST',data});
                        assets[button.dataset.image]=result.data.id;
                        const box=form.querySelector('[data-image-box="'+button.dataset.image+'"]');
                        const image=document.createElement('img');image.alt=button.dataset.image==='icon'?'小图标':'完整标识';image.src=result.data.url;box.replaceChildren(image);
                        button.dispatchEvent(new Event('change',{bubbles:true}));
                        handle.feedback('图片已上传，保存支付方式后生效','success');
                })));
                form.addEventListener('click',event=>{
                    const button=event.target.closest('[data-default-image]');if(!button)return;
                    const kind=button.dataset.defaultImage;assets[kind]=null;
                    const box=form.querySelector('[data-image-box="'+kind+'"]');box.textContent='保存后使用默认标识';
                    button.dispatchEvent(new Event('change',{bubbles:true}));
                });
            },
            async onSubmit(form,handle){
                const f=form.elements;
                await post('save',{id:type?.id||null,revision:type?.revision||0,name:f.name.value.trim(),code:f.code.value.trim(),sort_order:Number(f.sort_order.value),enabled:f.enabled.value==='1',icon_asset_id:assets.icon,logo_asset_id:assets.logo,});
                handle.clean();handle.close();await reload();UI.message('支付方式已保存');
            }
        });
    }

    async function deleteType(type) {
        if(!type)return;
        if(await UI.confirm({title:'删除支付方式',content:'确定删除“'+type.name+'”？',confirmText:'删除',danger:true,
            description:'删除后将从支付方式目录移除。已有引用的支付方式只能停用。',
            onConfirm:()=>post('delete',{id:type.id,revision:type.revision})})){UI.message('支付方式已删除');await refreshAfterAction();}
    }

    // 写入已成功后，读取失败只提示刷新，不重新执行已完成的操作。
    async function refreshAfterAction() {
        try {await reload();} catch(error) {UI.error(directoryDialog&&!directoryDialog.closed?directoryDialog.node.querySelector('.admin-feedback'):'#admin-feedback',error);}
    }

    function drawPlugins() {
        if(!document.getElementById('plugin-table'))return;
        const all=UI.catalog.plugins,query=document.getElementById('plugin-search').value.trim().toLowerCase();
        const status=document.getElementById('plugin-status').value;
        const items=all.filter(item=>(item.name.toLowerCase().includes(query)||item.code.includes(query))&&(status===''||String(Number(item.enabled))===status));
        document.getElementById('plugin-count').textContent=all.filter(item=>item.installed).length;
        document.getElementById('plugin-enabled-count').textContent=all.filter(item=>item.installed&&item.enabled).length;
        UI.table({elem:'#plugin-table',id:'plugin-table',data:items,className:'admin-table-two-line',cols:[[
            {title:'插件',minWidth:210,templet:row=>'<div class="admin-two-line"><strong class="admin-row-title">'+E(row.name)+'</strong><span class="admin-row-sub">'+E(row.code)+'</span></div>'},
            {title:'版本',width:100,align:'center',templet:row=>UI.badge(row.version,'blue')},
            {title:'支付方式',minWidth:200,maxWidth:300,expandedMode:'tips',templet:row=>row.pay_types.map(UI.payment).join(' ')},
            {title:'配置项',width:90,align:'center',templet:row=>UI.badge(row.config_schema?.length||0,'blue')},
            {title:'状态',width:140,align:'center',templet:row=>!row.installed?UI.badge('未安装','red'):!row.synchronized?UI.badge('待刷新','orange'):enabledLabel(row.enabled)},
            {field:'channel_count',title:'关联通道',width:100,align:'center'},
            {title:'操作',width:140,align:'center',fixed:'right',templet:row=>'<div class="admin-inline-actions"><button class="admin-link" lay-event="detail">详情</button><button class="admin-link" lay-event="enabled" '+(!row.synchronized||(!row.installed&&!row.enabled)?'disabled':'')+'>'+(row.enabled?'停用':'启用')+'</button></div>'}
        ]],emptyText:'没有符合条件的插件'});
    }

    document.getElementById('payment-types-manage')?.addEventListener('click',openTypes);
    if(document.getElementById('plugin-table')) {
        drawPlugins();
        document.getElementById('plugin-filter').addEventListener('submit',event=>{event.preventDefault();drawPlugins();});
        UI.onReset(document.getElementById('plugin-filter'),()=>{UI.render();drawPlugins();});
        document.getElementById('plugin-refresh').addEventListener('click',async event=>{
            const button=event.currentTarget;button.disabled=true;
            try {const result=await post('refresh',{});UI.applyCatalog(result.data);drawPlugins();UI.message('已同步 '+result.data.plugins.filter(plugin=>plugin.installed).length+' 个插件到数据库');}
            catch(error){UI.error('#admin-feedback',error);}finally{button.disabled=false;}
        });
        layui.table.on('tool(plugin-table)',async event=>{
            const row=event.data;
            if(event.event==='detail') {
                const handle=UI.open({title:'插件详情',width:840,content:UI.facts({'插件名称':row.name,'编码':row.code,'版本':row.version,'支付方式':row.pay_types.map(code=>UI.paymentLabels[code]||code).join('、'),'关联通道':row.channel_count,'状态':!row.installed?'未安装':!row.synchronized?'待刷新':row.enabled?'已启用':'已停用','最近更新':row.updated_at||'尚未同步'})
                    +'<div class="admin-section-title">插件声明的配置项</div><table class="plugin-schema-table"></table>'});
                const types={input:'文本',textarea:'多行文本',number:'数字',select:'选择',list:'文本列表',switch:'开关',upload:'图片'};
                // 管理员看到选项名称；存储编码只用于提交和插件调用。
                const defaultLabel=field=>{
                    if(field.secret)return '需自行填写';
                    if(field.value===undefined||field.value===null||field.value==='')return '未设置';
                    if(field.type==='select')return (field.options||[]).find(option=>String(option.value)===String(field.value))?.label??field.value;
                    if(field.type==='switch')return field.value?'开启':'关闭';
                    return Array.isArray(field.value)?field.value.join('、'):field.value;
                };
                UI.table({elem:handle.node.querySelector('table'),data:row.config_schema||[],page:false,cols:[[
                    {field:'title',title:'配置项',minWidth:190,templet:field=>E(field.title)},
                    {title:'类型',width:100,align:'center',templet:field=>E(field.secret?'凭证':types[field.type]||field.type)},
                    {title:'默认值',minWidth:180,templet:field=>E(defaultLabel(field))}
                ]]});return;
            }
            const action=row.enabled?'停用':'启用';
            if(await UI.confirm({title:action+'插件',content:'确定'+action+'“'+row.name+'”？',confirmText:action,
                description:row.enabled?'停用后不能创建新订单或启用新通道，已有订单继续核对到账并通知商户。':'启用后可用于配置通道，已有通道保留各自的启停状态。',
                onConfirm:()=>post('enabled',{code:row.code,revision:row.revision,enabled:!row.enabled})})){UI.message('插件已'+action);await refreshAfterAction();}
        });
    }
});
