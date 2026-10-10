/** 运行页面只读取任务事实；诊断按需触发，不从心跳推断设备在线，也不提供进程控制入口。 */
MpayAdmin.ready(function () {
    'use strict';
    const UI=MpayAdmin,E=UI.escape,form=document.getElementById('runtime-filters');if(!form)return;
    const feedback=document.getElementById('admin-feedback');let page=1,overviewSequence=0;
    const when=value=>value==null?'暂无记录':new Date(value*1000).toLocaleString('zh-CN',{timeZone:'Asia/Shanghai',hour12:false});
    async function overview(){const sequence=++overviewSequence;try{
        const data=(await MpayHttp.request('/adminapi/runtime')).data;if(sequence!==overviewSequence)return;
        const workers=document.getElementById('runtime-workers');workers.replaceChildren();
        data.workers.workers.forEach(worker=>{const card=document.createElement('section');card.className='admin-worker-card';card.innerHTML='<h2>'+E(worker.label)+UI.state(worker.status_label)+'</h2><p>最近活动：'+E(when(worker.observed_at))+'</p>';workers.append(card);});
        if(!data.workers.scan_complete)UI.feedback(feedback,'任务活动记录读取不完整，请核对运行目录权限。');
        document.getElementById('runtime-overview').innerHTML=Object.entries({'观测时间':data.observed_at,'PHP':data.php+' / '+data.sapi,'待发送通知':data.notifications.pending,'发送中通知':data.notifications.sending,'停止自动重试':data.notifications.exhausted,'未知采集状态':data.unknown_states,...data.packages}).map(([name,value])=>'<dt>'+E(name)+'</dt><dd>'+E(value)+'</dd>').join('');
        const selected=form.elements.status.value;form.elements.status.replaceChildren(new Option('全部状态',''));Object.entries(data.status_options).forEach(([value,label])=>form.elements.status.add(new Option(label,value)));form.elements.status.value=selected;UI.render(form);
    }catch(exception){if(sequence===overviewSequence)UI.error(feedback,exception);}}
    function collections(current=1){
        const where={};new FormData(form).forEach((value,key)=>{if(value!=='')where[key]=value;});
        UI.table({elem:'#runtime-collections',id:'runtime-collections',url:'/adminapi/runtime/collections',where,
            page:{curr:current},feedback,done(result,curr){page=curr;},emptyText:'暂无采集记录',cols:[[
                {field:'name',title:'收款通道',minWidth:180,templet:row=>E(row.name)},
                {title:'状态',width:170,align:'center',templet:row=>UI.state(row.status_label)},
                {field:'last_success_at',title:'最近成功',minWidth:175,templet:row=>E(row.last_success_at||'暂无记录')},
                {field:'next_run_at',title:'下次执行',minWidth:175,templet:row=>E(row.next_run_at||'未安排')},
                {field:'retry_count',title:'重试次数',width:110,align:'center',templet:row=>E(row.retry_count??'—')},
                {title:'操作',width:100,align:'center',fixed:'right',templet:()=>'<button type="button" class="admin-link" lay-event="detail">详情</button>'}
            ]]});
    }
    layui.table.on('tool(runtime-collections)',event=>{if(event.event!=='detail')return;const item=event.data;
        UI.open({title:'采集详情 · '+item.name,drawer:true,content:UI.state(item.status_label)+UI.facts({'收款账号':item.name,'启用采集':item.enabled,'最近成功':item.last_success_at,'下次执行':item.next_run_at,'最近心跳':item.last_heartbeat_at,'已采集至':item.cursor_completed_at,'重试次数':item.retry_count,'最近耗时（毫秒）':item.last_duration_ms,'处理说明':item.reason_label})
            +'<details class="admin-details"><summary>技术信息</summary>'+UI.facts({'插件':item.plugin_code,'配置版本':item.configuration_version,'执行状态':item.lease_status})+'</details><a class="layui-btn layui-btn-primary" href="/admin/configurations?configuration_id='+encodeURIComponent(item.configuration_id)+'">查看收款通道</a>'});
    });
    document.getElementById('runtime-doctor').addEventListener('click',()=>{
        const handle=UI.open({title:'只读环境诊断',width:850,content:'<table class="runtime-diagnosis"></table>'});
        handle.run(async()=>{handle.feedback('正在检查…','loading');const data=(await MpayHttp.request('/adminapi/runtime/doctor',{timeout:30000})).data;
            const names={php:'PHP 与扩展',site:'站点配置',runtime:'运行目录',database:'数据库',migrations:'安装进度',notification:'通知任务',collection:'采集状态',workers:'任务活动'};
            const states={ok:['通过','green'],warning:['需关注','orange'],error:['未通过','red']};
            UI.table({elem:handle.node.querySelector('.runtime-diagnosis'),data:data.checks,page:false,cellExpandedMode:'tips',cols:[[{title:'项目',width:160,templet:row=>E(names[row.id]||row.id)},{title:'结果',width:100,align:'center',templet:row=>UI.badge(...(states[row.status]||['未知','gray']))},{field:'message',title:'说明',minWidth:300,templet:row=>E(row.message)}]]});handle.feedback('');
        });
    });
    UI.all('/adminapi/configuration/list').then(items=>{items.forEach(item=>form.elements.configuration_id.add(new Option(item.name,item.id)));UI.render(form);}).catch(exception=>UI.error(feedback,exception));
    form.addEventListener('submit',event=>{event.preventDefault();UI.feedback(feedback,'');collections(1);});UI.onReset(form,()=>{UI.render(form);collections(1);});
    document.getElementById('runtime-refresh').addEventListener('click',()=>{UI.feedback(feedback,'');overview();collections(page);});overview();collections();
});
