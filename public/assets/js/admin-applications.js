/** 应用卡片、详情与凭证操作；列表不读取 KEY，所有写操作仍使用版本和原有认证接口。 */
MpayAdmin.ready(function () {
    'use strict';
    const UI = MpayAdmin, E = UI.escape, grid = document.getElementById('apps-list');
    if (!grid) return;
    const filters = document.getElementById('apps-filters'), feedback = document.getElementById('admin-feedback');
    let apps = [], origin = '', routing = null, plugins = [], loading = false;
    const modes = {1:'顺序轮询',2:'权重随机',3:'默认优先'};
    const reason = () => UI.field('操作原因',UI.input('reason','','required maxlength="500" placeholder="简要说明本次操作"'),{full:true,required:true});
    const post = (action,input) => MpayHttp.request('/adminapi/applications/' + action,{method:'POST',data:input});
    const bindingFor = (app,type) => routing?.bindings.find(item => item.app_id === app.id && routing.types[item.pay_type_id] === type);

    function renderCards() {
        const search = filters.elements.search.value.trim().toLowerCase(), status = filters.elements.status.value;
        const visible = apps.filter(app => (app.name.toLowerCase().includes(search) || app.pid.includes(search)) && (status === '' || app.enabled === (status === '1')));
        grid.replaceChildren();
        if (!visible.length) {
            UI.empty(grid,apps.length ? '没有符合条件的应用' : '暂无接入应用',apps.length ? {label:'清空筛选',run:() => {filters.reset(); UI.render(filters); renderCards();}} : {label:'新增应用',run:() => edit(null)});
            return;
        }
        visible.forEach(app => {
            const article = document.createElement('article'); article.className = 'admin-app-card';
            const types = UI.catalog.types.filter(type=>type.enabled&&Number(bindingFor(app,type.code)?.status)===1&&plugins.some(plugin=>plugin.enabled&&plugin.pay_types.includes(type.code))).map(type=>type.code);
            article.innerHTML = '<div class="admin-app-main" role="button" tabindex="0" aria-label="查看应用 ' + E(app.name) + '"><div class="admin-app-heading"><span class="admin-app-icon">' + E(Array.from(app.name)[0] || '应') + '</span><div class="admin-app-name">' + E(app.name) + '</div></div>'
                + '<div class="admin-app-pid">PID <span>' + E(app.pid) + '</span><button type="button" class="admin-link app-copy" aria-label="复制 PID">' + UI.icon('file-b') + '</button></div>'
                + '<div class="admin-app-payments">' + UI.badge(app.enabled ? '已启用' : '已停用',app.enabled ? 'green':'gray')
                + UI.badge(app.use_default_route?'默认收款':'自定义路由',app.use_default_route?'blue':'gray')
                + (routing === null ? '<span class="admin-muted">路由信息未读取</span>' : types.length ? types.map(UI.payment).join('') : UI.badge('待配置','orange')) + '</div></div>'
                + '<div class="admin-app-foot"><button class="admin-link app-detail" type="button">查看详情</button><button class="admin-link app-edit" type="button">编辑</button><button class="admin-link app-more" type="button">更多 ' + UI.icon('down') + '</button></div>';
            const main = article.querySelector('.admin-app-main');
            main.addEventListener('click',event => { if (!event.target.closest('button')) detail(app); });
            main.addEventListener('keydown',event => { if (event.target === main && ['Enter',' '].includes(event.key)) {event.preventDefault(); detail(app);} });
            article.querySelector('.app-copy').addEventListener('click',() => UI.copy(app.pid));
            article.querySelector('.app-detail').addEventListener('click',() => detail(app));
            article.querySelector('.app-edit').addEventListener('click',() => edit(app));
            grid.append(article);
            layui.dropdown.render({elem:article.querySelector('.app-more'),data:[{id:'key',title:'查看 KEY'},{id:'rotate',title:'重置 KEY'},{id:'orders',title:'查看订单'}],click:item => {
                if (item.id === 'orders') location.assign('/admin/orders?' + new URLSearchParams({app_id:app.id}));
                else keyEditor(app,item.id === 'rotate' ? 'rotate':'reveal');
            }});
        });
    }
    async function load() {
        if (loading) return; loading = true; UI.feedback(feedback,'正在读取应用…','loading');
        try {
            // 配置摘要读取失败不隐藏已经成功读取的应用，也不把未读取绑定显示成未配置。
            const results = await Promise.allSettled([MpayHttp.request('/adminapi/applications'),MpayHttp.request('/adminapi/poll-groups'),MpayHttp.request('/adminapi/configuration/plugins')]);
            if (results[0].status === 'rejected') throw results[0].reason;
            apps = results[0].value.data.items; origin = results[0].value.data.origin;
            routing = results[1].status === 'fulfilled' ? results[1].value.data : null;
            plugins = results[2].status === 'fulfilled' ? results[2].value.data.items : [];
            renderCards();
            UI.feedback(feedback,routing === null ? '应用已读取，收款路由读取失败，请刷新后重试。' : '');
        } catch (exception) { UI.error(feedback,exception); }
        finally { loading = false; }
    }
    function edit(app) {
        UI.form({title:app ? '编辑应用 · ' + app.name : '新增应用',width:710,
            content:'<div class="admin-form-grid">'
                + UI.field('应用名称',UI.input('name',app?.name || '','required maxlength="128"'),{required:true})
                + UI.field('PID',UI.input('pid',app?.pid || '','maxlength="32" pattern="[1-9][0-9]{0,31}"' + (app ? ' required' : ' placeholder="留空自动生成"')),{help:'应用在业务网站中的接入标识。修改后，新请求应使用新的 PID。'})
                + (app ? UI.field('接入状态','<input name="enabled" type="checkbox" lay-skin="switch" lay-text="启用|停用"' + (app.enabled ? ' checked':'') + '>') : '')
                + UI.field('允许的通知及回跳域名',UI.textarea('allowed_hosts',(app?.allowed_hosts || []).join('\n'),'placeholder="每行一个域名，如 shop.example.com"'),{full:true,help:'填写域名，不带协议、端口或路径。已有订单保留创建时的回调目标和凭证。'})
                + '</div>',
            async onSubmit(form,handle) {
                const f = form.elements, data = {name:f.name.value,pid:f.pid.value,allowed_hosts:f.allowed_hosts.value.split(/\r?\n/).map(x=>x.trim()).filter(Boolean)};
                if (app) Object.assign(data,{id:app.id,revision:app.revision,enabled:f.enabled.checked});
                await post(app ? 'save':'create',data);
                handle.clean(); handle.close(); UI.message('应用已保存'); await load();
            }
        });
    }
    function routeForm(app,type) {
        const binding = bindingFor(app,type), supported = plugins.some(plugin=>plugin.pay_types.includes(type));
        const group = routing?.items.find(item=>item.id===binding?.poll_group_id);
        const status = !supported ? UI.badge('暂无插件','gray') : !binding ? UI.badge('未绑定','orange')
            : Number(binding.status)!==1 ? UI.badge('绑定已停用','gray') : UI.badge('已绑定','blue');
        const header = '<div class="layui-card-header admin-route-card-heading">'+UI.payment(type)+'<span class="app-route-status">'+status+'</span></div>';
        if (!supported) return '<section class="layui-card admin-route-card admin-route-unavailable">'+header
            +'<div class="layui-card-body"><span class="admin-muted">暂无支持此支付方式的插件</span><a href="/admin/plugins" class="admin-link">插件管理 '+UI.icon('right')+'</a></div></section>';
        const options = (routing?.items || []).filter(group=>routing.types[group.pay_type_id] === type).map(group=>({value:group.id,label:group.name + (group.enabled ? '' : '（已停用）')}));
        const configuration = app.use_default_route
            ? '<div class="admin-route-summary"><span class="admin-label">轮询组</span><strong>'+E(group?.name || '尚未绑定')+'</strong><p class="admin-muted">'+E(group ? (modes[group.route_mode]||'')+' · '+group.members.length+' 个成员' : '暂无路由配置')+'</p></div>'
            : UI.field('轮询组',UI.select('group_id',[{value:'',label:'请选择轮询组'},...options],binding?.poll_group_id || '','required lay-search'),{required:true})
                + '<div class="admin-route-binding">'+UI.field('绑定状态','<input type="checkbox" name="enabled" lay-skin="switch" lay-text="启用|停用"' + (!binding || Number(binding.status) === 1 ? ' checked' : '') + '>')+'<button type="submit" class="layui-btn layui-btn-sm">保存绑定</button></div>';
        // 一种支付方式对应一个官方 card；控件仍由 form 渲染，模式与选路结果不在展示层推断。
        return '<form class="layui-form layui-card admin-route-card app-binding" data-pay-type="' + E(type) + '" novalidate>'+header
            +'<div class="layui-card-body admin-route-config">'+configuration+'<a href="/admin/poll-groups" class="admin-link">管理轮询组 '+UI.icon('right')+'</a></div>'
            +'<div class="admin-route-preview-area"><div class="admin-route-preview-controls">'+UI.field('预览金额',UI.unit('amount','1.00','元','inputmode="decimal" data-admin-transient'),{help:'仅预览已保存的绑定，不创建订单，不推进轮询顺序。权重随机结果不保证与实单一致。'})
            +'<button type="button" class="layui-btn layui-btn-primary app-route-preview">预览路由</button></div><div class="admin-feedback" role="status"></div></div></form>';
    }
    function detail(app,section=null) {
        UI.open({title:'应用详情',drawer:true,form:true,width:850,content:'<div class="admin-app-summary"><span class="admin-app-icon">' + E(Array.from(app.name)[0]) + '</span><div><h2>' + E(app.name) + '</h2><span class="admin-muted">PID ' + E(app.pid) + '</span> ' + UI.badge(app.enabled?'已启用':'已停用',app.enabled?'green':'gray') + '</div></div>'
            + UI.tabs([
                {title:'基本信息',content:UI.facts({'应用名称':app.name,'PID':app.pid,'接入状态':app.enabled?'启用':'停用','允许的回调域名':(app.allowed_hosts || []).join('\n') || '尚未配置，不能创建新订单','KEY 版本':app.key_version ?? '未设置'}) + '<button type="button" class="layui-btn layui-btn-primary app-edit-current">编辑应用</button>'},
                {title:'收款路由',content:routing ? '<div class="admin-route-mode"><div><strong>'+(app.use_default_route?'默认收款':'自定义路由')+'</strong>'+UI.help(app.use_default_route?'新增通道自动加入对应默认组。仅参与默认收款且符合当前限额、时段的通道可被选中。':'可以为各支付方式单独选择轮询组或停用绑定。恢复默认会替换此应用的全部绑定。')+'</div><button type="button" class="layui-btn layui-btn-primary layui-btn-sm app-route-mode">'+(app.use_default_route?'自定义路由':'恢复默认收款')+'</button></div><div class="admin-route-grid">'+Object.keys(UI.paymentLabels).map(type=>routeForm(app,type)).join('')+'</div>' : UI.notice('路由信息未能读取，请关闭详情并刷新。')},
                {title:'接入信息',content:['页面下单|/submit.php','API 下单|/mapi.php','订单查询|/api.php'].map(value=>{const [name,path]=value.split('|');return '<div class="admin-section-title">'+name+'</div><div class="admin-code">'+E(origin+path)+' <button type="button" class="admin-link" data-copy="'+E(origin+path)+'">复制</button></div>';}).join('') + '<div class="admin-section-title">易支付 V1</div><p class="admin-help">PID：' + E(app.pid) + '。KEY 在凭证管理中查看。</p>'},
                {title:'凭证管理',content:UI.facts({'当前 KEY 版本':app.key_version ?? '未设置'}) + '<div class="admin-actions"><button type="button" class="layui-btn layui-btn-primary app-reveal">查看 KEY</button><button type="button" class="layui-btn layui-btn-danger app-rotate">重置 KEY</button></div>'}
            ],section==='routing'?1:section==='integration'?2:0),
            onOpen(handle) {
                handle.node.querySelector('.app-route-mode')?.addEventListener('click',()=>handle.run(async()=>{
                    const save=()=>post('routing',{id:app.id,revision:app.revision,use_default_route:!app.use_default_route});
                    if(!app.use_default_route){
                        if(!await UI.confirm({title:'恢复默认收款',content:'确定将“'+app.name+'”恢复为默认收款？',description:'各支付方式将改为绑定对应的默认轮询组。',confirmText:'恢复默认',onConfirm:save}))return;
                    }else await save();
                    handle.clean();handle.close();await load();const current=apps.find(row=>row.id===app.id);if(current)detail(current,'routing');
                }));
                handle.node.querySelector('.app-edit-current').addEventListener('click',async()=>{if(await handle.requestClose())edit(app);});
                handle.node.querySelector('.app-reveal').addEventListener('click',async()=>{if(await handle.requestClose())keyEditor(app,'reveal');});
                handle.node.querySelector('.app-rotate').addEventListener('click',async()=>{if(await handle.requestClose())keyEditor(app,'rotate');});
                handle.node.querySelectorAll('.app-binding').forEach(form=>{
                    const type = form.dataset.payType; let binding = bindingFor(app,type), saved = true, mustReload = false;
                    // 金额输入的回车意图是试算；不触发同表单的“保存绑定”。
                    form.elements.amount.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();form.querySelector('.app-route-preview').click();}});
                    form.addEventListener('change',event=>{if (event.target.name !== 'amount') saved = false;});
                    form.addEventListener('submit',event=>{
                        event.preventDefault();
                        // 默认模式回车只预览，不把隐式 form submit 当作修改绑定并切换为自定义。
                        if(app.use_default_route){form.querySelector('.app-route-preview').click();return;}
                        if (mustReload || !UI.valid(form)) return;
                        handle.run(async()=>{
                            await MpayHttp.request('/adminapi/poll-groups/bind',{method:'POST',data:{app_id:app.id,pay_type:type,group_id:form.elements.group_id.value,revision:Number(binding?.revision || 0),enabled:form.elements.enabled.checked}});
                            UI.feedback(form.querySelector('.admin-feedback'),'已保存，正在读取最新绑定…','success');
                            try { routing=(await MpayHttp.request('/adminapi/poll-groups')).data; binding=bindingFor(app,type); saved=true; form.querySelector('.app-route-status').innerHTML=UI.badge(Number(binding.status)===1?'已绑定':'绑定已停用',Number(binding.status)===1?'blue':'gray'); renderCards(); handle.clean(); UI.feedback(form.querySelector('.admin-feedback'),'绑定已保存','success'); }
                            catch (exception) { saved=false; mustReload=true; const submit=form.querySelector('[type=submit]'); submit.disabled=true; submit.dataset.keepDisabled='1'; UI.feedback(form.querySelector('.admin-feedback'),'绑定已保存，但读取结果失败。请重新打开详情核对后再操作。'); }
                        });
                    });
                    form.querySelector('.app-route-preview').addEventListener('click',()=>handle.run(async()=>{
                        const feedback=form.querySelector('.admin-feedback');
                        try{
                            if (!saved) throw new Error('请先保存绑定，再预览已保存的路由');
                            const result = (await MpayHttp.request('/adminapi/poll-groups/preview',{method:'POST',data:{app_id:app.id,pay_type:type,amount:UI.cents(form.elements.amount.value)}})).data;
                            UI.feedback(feedback,'预览通道：'+result.channel_name+'（'+(modes[result.route_mode]||result.route_mode)+'）','success');
                        }catch(exception){UI.error(feedback,exception);}
                    }));
                });
            }
        });
    }
    function keyEditor(app,action) {
        let timer = null, activeHandle;
        const clear = () => {const secret=activeHandle?.node.querySelector('.admin-secret');if(secret)secret.value='';clearTimeout(timer);};
        const hidden = () => {if(document.hidden)clear();};
        activeHandle = UI.form({title:(action==='rotate'?'重置 KEY':'查看 KEY')+' · '+app.name,width:650,submit:action==='rotate'?'确认重置':'验证并查看',
            content:(action==='rotate'?UI.notice('重置后旧 KEY 将停止新请求认证。已有订单仍使用原凭证，请确保接入网站保留核验旧单所需凭证。',true):'')
                + '<div class="admin-form-grid">'+UI.field('管理员密码',UI.input('password','','type="password" required maxlength="72" autocomplete="current-password"'),{full:true,required:true})
                + (action==='rotate'?UI.field('自定义新 KEY',UI.input('key','','type="password" maxlength="128" autocomplete="off" placeholder="留空随机生成"'),{full:true,help:'自定义为 16～128 位无空格字符。'}):'')+(action==='rotate'?reason():'')+'</div>',
            async onSubmit(form,handle) {
                const data={id:app.id,expected_id:app.active_key_id,password:form.elements.password.value};
                if(action==='rotate'){data.key=form.elements.key.value;data.reason=form.elements.reason.value;}
                let result;try {result=await post(action,data);}finally{form.reset();}
                handle.clean();
                // 仅当前确认操作的结果可显示；两分钟、失焦或关闭后清除，禁止保存到缓存。
                handle.node.querySelector('form').innerHTML=UI.field('KEY',UI.input('secret','','readonly autocomplete="off"'),{full:true,hint:'两分钟后或切换页面时自动隐藏。'});UI.render(handle.node);
                const secret=handle.node.querySelector('[name=secret]'); secret.classList.add('admin-secret'); if(!document.hidden)secret.value=result.data.key;
                handle.node.querySelector('.admin-dialog-footer').innerHTML='<button type="button" class="layui-btn layui-btn-primary" data-dialog-close>关闭并隐藏</button>';
                timer=setTimeout(clear,120000);await load();
            },onClose(){clear();document.removeEventListener('visibilitychange',hidden);}
        });
        document.addEventListener('visibilitychange',hidden);
    }
    function signature() {
        UI.form({title:'签名工具',width:730,submit:'计算签名',content:'<div class="admin-form-grid">'+UI.field('参数 JSON 对象',UI.textarea('parameters','','required placeholder="填写待计算签名的参数对象"'),{full:true,required:true})+UI.field('签名 KEY',UI.input('key','','required type="password" maxlength="128" autocomplete="off"'),{full:true,required:true})+'</div><pre class="admin-code" hidden></pre>',
            async onSubmit(form,handle){try{const result=await post('signature',{parameters:JSON.parse(form.elements.parameters.value),key:form.elements.key.value});const pre=handle.node.querySelector('pre');pre.hidden=false;pre.textContent=JSON.stringify(result.data,null,2);handle.clean();}finally{form.elements.key.value='';}}
        });
    }
    filters.addEventListener('submit',event=>{event.preventDefault();renderCards();});
    UI.onReset(filters,()=>{UI.render(filters);renderCards();});
    document.getElementById('apps-new').addEventListener('click',()=>edit(null));
    document.getElementById('apps-sign').addEventListener('click',signature);
    document.getElementById('apps-refresh').addEventListener('click',load);
    load().then(()=>{if(new URLSearchParams(location.search).has('setup')){const app=[...apps].reverse().find(row=>row.use_default_route)||apps[0];if(app)detail(app,'integration');}});
});
