/** 订单列表和抽屉展示服务端事实；动作按服务返回的能力开放，真实到账证据必须由管理员填写。 */
MpayAdmin.ready(function () {
    'use strict';
    const UI=MpayAdmin,E=UI.escape,filters=document.getElementById('order-filters');if(!filters)return;
    const feedback=document.getElementById('admin-feedback'),locationValues=new URLSearchParams(location.search);
    let page=1,detailSequence=0,currentDialog=null,orderTable=null,apps=[],channels=[],groups=[];
    const operationLabels={manual_receipt_confirmed:'人工确认到账',manual_confirmation_replayed:'重复确认已处理',local_close:'关闭订单',local_close_replayed:'重复关闭已处理',manual_notify_scheduled:'已安排商户通知',manual_notify_replayed:'重复补发请求已处理'};
    const col=(field,title,width=150)=>({field,title,minWidth:width,templet:row=>field==='result_code'&&row.result_label?E(row.result_label)+' '+UI.help(row.result_code||'无结果代码'):field==='action'&&operationLabels[row.action]?E(operationLabels[row.action])+' '+UI.help(row.action):E(row[field]??'—')});
    for(const name of ['app_id','pid','poll_group_id','channel_id','pay_type','status','notification_status','start_date','end_date'])if(locationValues.has(name))filters.elements[name].value=locationValues.get(name);
    for(const name of ['biz_no','pay_no','merchant_order_no'])if(locationValues.has(name)){filters.elements.number_kind.value=name;filters.elements.number.value=locationValues.get(name);break;}
    if(['notification_status','pid','poll_group_id','channel_id'].some(name=>locationValues.has(name)))filters.querySelector('details').open=true;
    UI.dateRange(filters);UI.render(filters);
    function parameters(){const params=new URLSearchParams();new FormData(filters).forEach((value,key)=>{if(value!==''&&!['number','number_kind'].includes(key))params.set(key,value);});if(filters.elements.number.value.trim())params.set(filters.elements.number_kind.value,filters.elements.number.value.trim());return params;}
    const appName=row=>apps.find(app=>app.id===row.app_id)?.name||('PID '+row.pid);
    const channelName=row=>row.channel_id?(channels.find(channel=>channel.id===row.channel_id)?.name||('通道 #'+row.channel_id)):'未分配';
    const paymentName=row=>row.pay_type?(UI.paymentLabels[row.pay_type]||row.pay_type):row.pay_type_id?'未知方式':'未选择';
    function notification(row){
        const full=row.notification_status_label||'尚未生成通知';
        const label=({0:'待发送 / 重试',1:'发送中',2:'通知成功',3:'停止重试',4:'暂不通知'})[row.notification_status]
            ||(full==='未设置通知地址'?'无通知地址':full==='尚未生成通知'?'待生成':full);
        const tone=row.notification_status===2?'green':[0,1,3].includes(row.notification_status)?'orange':full.includes('异常')?'red':'gray';
        return '<div class="admin-two-line"><span title="'+E(full)+'">'+UI.badge(label,tone)+'</span>'
            +(row.notification_first_ack_at&&row.notification_status!==2?'<span class="admin-row-sub">商户曾确认成功</span>':'')+'</div>';
    }
    // 重查时沿用官方实例列配置，保留用户调整的列宽。
    function load(current=1){
        const columns=orderTable?layui.table.getOptions('order-table').cols:[[
            // 文本列仅限制最小宽度，由 table 自动分配余宽；短语义列继续保持固定宽度。
            {field:'biz_no',title:'订单编号',minWidth:228,templet:row=>'<div class="admin-two-line"><button type="button" class="admin-reference admin-order-number admin-order-link" lay-event="detail" title="查看订单详情">'+E(row.biz_no)+'</button><span class="admin-row-sub admin-order-number" title="商户订单号：'+E(row.merchant_order_no)+'">'+E(row.merchant_order_no)+'</span></div>'},
            {field:'app_id',title:'应用 / 商品',minWidth:160,templet:row=>'<div class="admin-two-line"><span class="admin-row-title" title="'+E(appName(row))+'">'+E(appName(row))+'</span><span class="admin-row-sub" title="'+E(row.subject)+'">'+E(row.subject)+'</span></div>'},
            {field:'pay_type',title:'支付方式',width:130,unresize:true,align:'center',templet:row=>row.pay_type?UI.payment(row.pay_type):'<span class="admin-muted">'+E(paymentName(row))+'</span>'},
            {field:'channel_id',title:'收款通道',minWidth:155,templet:row=>'<span title="'+E(channelName(row))+'">'+E(channelName(row))+'</span>'},
            {field:'order_amount',title:'金额（元）',width:130,align:'right',templet:row=>'<div class="admin-two-line"><span class="admin-money" title="订单金额">'+E(row.order_amount)+'</span><span class="admin-row-sub">实收 '+E(row.received_amount??'—')+'</span></div>'},
            {field:'status',title:'支付状态',width:110,align:'center',templet:row=>UI.state(row.status_label)},
            {field:'notification_status',title:'商户通知',width:148,align:'center',templet:notification},
            {field:'created_at',title:'交易时间',width:212,templet:row=>'<div class="admin-two-line admin-order-time"><span title="创建时间">'+E(row.created_at)+'</span><span class="admin-row-sub" title="付款时间">'+E(row.paid_at??'—')+'</span></div>'},
            {title:'操作',width:80,align:'center',fixed:'right',templet:()=>'<button type="button" class="admin-link" lay-event="detail">详情</button>'}
        ]];
        orderTable=UI.table({elem:'#order-table',id:'order-table',url:'/adminapi/orders/list',where:Object.fromEntries(parameters()),
            className:'admin-table-two-line admin-orders-table',toolbar:false,defaultToolbar:[],
            page:{curr:current},feedback,done(result,curr){page=curr;},cols:columns});
    }
    function translatedOrder(order){return {'业务单号':order.biz_no,'商户订单号':order.merchant_order_no,'支付单号':order.pay_no,'应用':appName(order),'支付方式':paymentName(order),'轮询组':groups.find(group=>group.id===order.poll_group_id)?.name||order.poll_group_id,'收款通道':channelName(order),'收款依据':order.confirmation_source_label,'创建时间':order.created_at,'付款时间':order.paid_at,'有效截止时间':order.expires_at,'来源流水号':order.channel_trade_no};}
    async function show(number,focusNotification=false){
        const sequence=++detailSequence;if(currentDialog&&!currentDialog.closed)currentDialog.close();
        const handle=UI.open({title:'订单详情',drawer:true,width:900,content:'<div class="order-detail-content"></div>'});currentDialog=handle;handle.feedback('正在读取订单详情…','loading');
        try{const data=(await MpayHttp.request('/adminapi/orders/detail?'+new URLSearchParams({biz_no:number}))).data;if(sequence!==detailSequence||handle.closed)return;
            handle.feedback('');const order=data.order;
            const related=data.receipt?'<div class="admin-filter-buttons"><a class="layui-btn layui-btn-primary" href="/admin/receipts?id='+encodeURIComponent(data.receipt.id)+'">查看流水详情</a></div>':'';
            const receipt=data.receipt?UI.facts({'流水编号':data.receipt.id,'实际收款金额（元）':data.receipt.amount,'来源付款时间':data.receipt.paid_at,'接收时间':data.receipt.received_at,'来源流水号':data.receipt.platform_trade_no,'付款备注':data.receipt.remark,'核对说明':data.receipt.reason_label})+related:UI.notice('尚无关联到账流水');
            const notification=data.notification?UI.facts({'通知号':data.notification.notify_no,'当前安排':data.notification.status_label,'通知目标':data.notification.target_host,'首次商户确认':data.notification.first_ack_at||'尚未确认','已发送次数':data.notification.retry_count,'最近发送':data.notification.last_notify_at,'最近发送结果':data.notification.reason_label,'下次发送':data.notification.next_retry_at})+(data.actions.retry_notification?'<div class="admin-filter-buttons"><button type="button" class="layui-btn order-notify-send">'+E(data.actions.notification_action)+'</button></div>':'')+'<div class="admin-section-title">发送记录</div><table class="order-notify-table"></table><div class="order-notify-feedback admin-feedback" role="status"></div>':UI.notice('尚无商户通知');
            handle.node.querySelector('.order-detail-content').innerHTML='<div class="admin-app-summary"><div><h2>'+E(order.subject)+'</h2><span class="admin-muted">'+E(order.biz_no)+'</span> <button type="button" class="admin-link" data-copy="'+E(order.biz_no)+'">复制</button></div>'+UI.state(order.status_label)+'</div>'
                +'<div class="admin-form-grid">'+[['订单原金额',order.order_amount],['提示付款金额',order.receipt_amount],['已确认实收',order.received_amount]].map(([title,value])=>'<div class="admin-fact"><label>'+title+'（元）</label><p class="admin-money">'+E(value??'—')+'</p></div>').join('')+'</div>'
                +UI.tabs([{title:'订单概况',content:UI.facts(translatedOrder(order))+(data.actions.confirm_unavailable_reason?UI.notice(data.actions.confirm_unavailable_reason):'')},...(data.refunds.length?[{title:'退款记录',content:data.refunds.map(refund=>UI.facts({'退款号':refund.refund_no,'金额（元）':refund.amount,'状态':refund.status_label,'说明':refund.result_label,'平台退款号':refund.channel_refund_no,'创建时间':refund.created_at})).join('')}]:[]),{title:'到账流水',content:receipt},{title:'商户通知',content:notification},{title:'处理记录',content:'<div class="layui-form"><select class="order-history-kind"><option value="operations">人工操作</option><option value="callbacks">支付执行与回调</option></select></div><table class="order-history-table"></table><div class="order-history-feedback admin-feedback" role="status"></div>'+(order.channel_id?'<details class="admin-details"><summary>技术信息</summary>'+UI.facts({'支付产品（pay_product）':order.pay_product,'支付动作（pay_action）':order.pay_action})+'<a class="admin-link" href="/admin/ingress?channel_id='+encodeURIComponent(order.channel_id)+'">查询该通道回调</a></details>':'')}],focusNotification?(data.refunds.length?3:2):0);
            const footer=handle.node.querySelector('.admin-dialog-footer');
            [['close','关闭订单',data.actions.close],['confirm','人工确认到账',data.actions.confirm]].forEach(([kind,label,allowed])=>{if(!allowed)return;const button=document.createElement('button');button.type='button';button.className='layui-btn '+(kind==='confirm'?'':'layui-btn-primary');button.textContent=label;button.addEventListener('click',()=>{handle.close();action(kind,data);});footer.append(button);});
            handle.node.querySelector('.order-notify-send')?.addEventListener('click',()=>{handle.close();action('retry-notification',data);});
            UI.render(handle.node);
            function history(current,notificationTab=false){
                const kind=notificationTab?'notifications':handle.node.querySelector('.order-history-kind').value;
                const prefix=notificationTab?'order-notify':'order-history';
                const columns=kind==='operations'?[col('created_at','时间',175),col('action','操作'),col('reason','原因',220)]
                    :kind==='callbacks'?[col('created_at','时间',175),col('result_code','结果',160),col('request_id','请求编号',280)]
                    :[{...col('attempt_no','次数',80),align:'center'},col('started_at','开始时间',175),{...col('http_status','HTTP 状态',100),align:'center'},{...col('duration_ms','耗时（毫秒）',110),align:'right'},col('result_code','结果'),UI.responseColumn()];
                UI.table({elem:handle.node.querySelector('.'+prefix+'-table'),url:'/adminapi/orders/history',
                    where:{biz_no:number,kind},page:{curr:current},feedback:handle.node.querySelector('.'+prefix+'-feedback'),cols:[columns]});
            }
            handle.node.querySelector('.order-history-kind').addEventListener('change',()=>history(1));history(1);if(data.notification)history(1,true);
        }catch(exception){if(!handle.closed)handle.error(exception);}
    }
    function action(kind,data){
        const titles={confirm:'人工确认到账',close:'关闭订单','retry-notification':data.actions.notification_action};
        const impacts={confirm:'请先核对真实到账依据。确认后订单标记为支付成功，可选择是否发送商户通知。',close:'关闭后订单停止等待付款，但原收款二维码仍可能收到转账。','retry-notification':data.notification?.first_ack_at?'商户此前已确认接收。本次将向原地址追加一次通知，请核对是否需要重复处理。':data.notification?.status===4?'开始向原业务网站发送付款结果；未确认时按正常规则自动重试。':'使用原通知任务发送付款结果，支付状态保持不变。'};
        let content=UI.notice(impacts[kind],true)+UI.facts({'订单':data.order.biz_no,'原订单金额（元）':data.order.order_amount})+(kind==='retry-notification'?UI.facts({'通知目标':data.notification.target_host,'首次商户确认':data.notification.first_ack_at||'尚未确认','最近发送结果':data.notification.reason_label}):'')+'<div class="admin-form-grid">';
        if(kind==='confirm')content+=UI.field('实际收款金额（元）',UI.input('received_amount','','required inputmode="decimal"'),{required:true})
            +UI.field('实际付款时间（上海时区）',UI.input('paid_at','','required type="text" data-admin-date="datetime" placeholder="yyyy-MM-dd HH:mm:ss" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}"'),{required:true})
            +UI.field('平台交易号',UI.input('platform_trade_no','','maxlength="128" autocomplete="off"'))
            +UI.field('核对依据',UI.textarea('reference','','required maxlength="1024"'),{full:true,required:true})
            +UI.field('管理员密码',UI.input('current_password','','type="password" required maxlength="72" autocomplete="current-password"'),{full:true,required:true})
            +UI.field('商户通知','<input type="checkbox" name="notify_merchant" lay-skin="primary" title="确认后通知商户" checked>',{full:true,help:'取消后记为暂不通知，之后可以手动发送；商户主动查询和页面回跳仍会获知支付成功。'});
        content+=UI.field('操作原因',UI.textarea('reason','','required maxlength="512"'),{full:true,required:true})+'</div>';
        UI.form({title:titles[kind],width:730,content,submit:kind==='confirm'?'已核实，确认到账':kind==='close'?'确认关闭':data.actions.notification_action,
            async onSubmit(form,handle){
                const input={biz_no:data.order.biz_no,reason:form.elements.reason.value};
                if(kind==='confirm'){
                    for(const key of ['received_amount','reference','platform_trade_no','current_password'])input[key]=form.elements[key].value;
                    input.paid_at=form.elements.paid_at.value;
                    input.notify_merchant=form.elements.notify_merchant.checked;
                }else if(kind==='retry-notification')input.expected_attempts=data.notification.retry_count;
                try{const result=await MpayHttp.request('/adminapi/orders/'+kind,{method:'POST',data:input});handle.clean();handle.close();UI.message(result.message);load(page);show(data.order.biz_no,kind==='retry-notification');}
                catch(exception){handle.error(exception);if(exception.code===0||exception.code>=50000||exception instanceof TypeError){handle.feedback('处理结果尚未确认，请先重新读取原订单，勿重复提交。');const submit=handle.node.querySelector('[type=submit]');submit.dataset.keepDisabled='1';submit.disabled=true;const check=document.createElement('button');check.className='layui-btn layui-btn-primary';check.type='button';check.textContent='读取原订单';check.addEventListener('click',()=>{handle.clean();handle.close();show(data.order.biz_no,kind==='retry-notification');});handle.node.querySelector('.admin-dialog-footer').append(check);}}
                finally{if(form.elements.current_password)form.elements.current_password.value='';}
            }
        });
    }
    layui.table.on('tool(order-table)',event=>{if(event.event==='detail')show(event.data.biz_no);});
    filters.addEventListener('submit',event=>{event.preventDefault();if(UI.valid(filters))load(1);});
    UI.onReset(filters,()=>{UI.render(filters);load(1);});
    document.getElementById('order-export').addEventListener('click',async event=>{const button=event.currentTarget;button.disabled=true;try{const bytes=await MpayHttp.download('/adminapi/orders/export?'+parameters(),'mpay-orders.csv');UI.message('导出文件已准备下载（'+(bytes/1024).toFixed(1)+' KB）');}catch(exception){UI.error(feedback,exception);}finally{button.disabled=false;}});
    document.getElementById('order-notifications').addEventListener('click',event=>{
        event.preventDefault();const params=new URLSearchParams({all_dates:'1'}),status=filters.elements.notification_status.value;
        if(filters.elements.app_id.value)params.set('app_id',filters.elements.app_id.value);
        if(status!==''&&status!=='none')params.set('status',status);
        location.assign('/admin/notifications?'+params);
    });
    Promise.allSettled([MpayHttp.request('/adminapi/applications'),MpayHttp.request('/adminapi/poll-groups'),UI.all('/adminapi/configuration/channels')]).then(results=>{
        if(results[0].status==='fulfilled'){apps=results[0].value.data.items;apps.forEach(app=>filters.elements.app_id.add(new Option(app.name+' / '+app.pid,app.id)));}
        if(results[1].status==='fulfilled'){groups=results[1].value.data.items;groups.forEach(group=>filters.elements.poll_group_id.add(new Option(group.name,group.id)));}
        if(results[2].status==='fulfilled'){channels=results[2].value;channels.forEach(channel=>filters.elements.channel_id.add(new Option(channel.name,channel.id)));}
        for(const name of ['app_id','poll_group_id','channel_id'])if(locationValues.has(name))filters.elements[name].value=locationValues.get(name);
        // 配置摘要和事件就绪后才开放查询，避免页面加载期间触发原生 GET 丢失筛选状态。
        UI.render(filters);filters.querySelector('[data-order-query]').disabled=false;load(1);
    });
    if(locationValues.has('biz_no'))show(locationValues.get('biz_no'));
});
