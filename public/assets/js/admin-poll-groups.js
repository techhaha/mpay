/** 轮询组编辑复用服务端策略和版本检查；成员表格只收集配置，不在浏览器选择正式订单通道。 */
MpayAdmin.ready(function () {
    'use strict';
    const UI=MpayAdmin,E=UI.escape,filters=document.getElementById('groups-filters');if(!filters)return;
    const feedback=document.getElementById('admin-feedback'),modes={1:'顺序轮询',2:'权重随机',3:'默认优先'},locationValues=new URLSearchParams(location.search);
    let groups=[],channels=[],types={},loading=false,catalogLoaded=false;
    function renderList(){
        const f=filters.elements,search=f.search.value.trim().toLowerCase();
        const data=groups.filter(group=>group.name.toLowerCase().includes(search)&&(!f.pay_type.value||types[group.pay_type_id]===f.pay_type.value)&&(!f.route_mode.value||group.route_mode===Number(f.route_mode.value))&&(!locationValues.has('channel_id')||group.members.some(member=>String(member.channel_id)===locationValues.get('channel_id'))));
        UI.table({elem:'#groups-list',id:'groups-list',data,cols:[[
            {field:'name',title:'轮询组',minWidth:180,templet:row=>E(row.name)+(row.is_default?' '+UI.badge('默认','blue'):'')},
            {title:'支付方式',width:135,unresize:true,align:'center',templet:row=>UI.payment(types[row.pay_type_id])},
            {title:'策略',width:140,align:'center',templet:row=>UI.badge(modes[row.route_mode]||row.route_mode,'blue')},
            {title:'成员',width:90,align:'center',templet:row=>row.members.length+' 个'},
            {title:'状态',width:120,align:'center',templet:row=>UI.badge(row.enabled?'已启用':'已停用',row.enabled?'green':'gray')},
            {title:'操作',width:120,align:'center',fixed:'right',templet:()=>'<button type="button" class="admin-link" lay-event="edit">编辑成员</button>'}
        ]],emptyText:groups.length?'没有符合条件的轮询组':'暂无轮询组'});
        document.querySelector('#groups-list + .layui-table-view').hidden=!groups.length;
        const empty=document.getElementById('groups-empty');empty.replaceChildren();if(!groups.length)UI.empty(empty,'暂无轮询组',{label:'新增轮询组',run:()=>edit(null)});
    }
    async function load(){if(loading)return;loading=true;document.getElementById('groups-new').disabled=true;UI.feedback(feedback,'正在读取轮询组…','loading');try{const [result,list]=await Promise.all([MpayHttp.request('/adminapi/poll-groups'),UI.all('/adminapi/configuration/channels')]);groups=result.data.items;types=result.data.types;channels=list;catalogLoaded=true;renderList();UI.feedback(feedback,'');}catch(exception){UI.error(feedback,exception);}finally{loading=false;document.getElementById('groups-new').disabled=!catalogLoaded;}}
    function edit(group){
        if(loading)return;
        const available=Object.entries(UI.paymentLabels).map(([value,label])=>({value,label}));
        const content='<div class="admin-form-grid">'+UI.field('轮询组名称',UI.input('name',group?.name||'','required maxlength="128"'),{required:true})
            +UI.field('支付方式',UI.select('pay_type',available,group?types[group.pay_type_id]:'alipay',group?'disabled':''),{required:true})
            +UI.field('选路策略',UI.select('route_mode',Object.entries(modes).map(([value,label])=>({value,label})),group?.route_mode||'1'))
            +(group?UI.field('组状态','<input name="enabled" type="checkbox" lay-skin="switch" lay-text="启用|停用"'+(group.enabled?' checked':'')+(group.is_default?' disabled':'')+'>'):'')+'</div>'
            +'<div class="admin-section-title group-strategy-help">成员通道 '+UI.help('')+'</div><div class="admin-table-scroll"><table class="layui-table admin-member-table"><thead><tr><th>通道</th><th data-sort>排序</th><th data-weight>权重</th><th data-default>默认</th><th>启用</th><th></th></tr></thead><tbody class="group-members"></tbody></table></div>'
            +'<div class="admin-filter-buttons"><button type="button" class="layui-btn layui-btn-primary group-add">添加通道</button></div><div class="admin-feedback group-hint" role="status"></div><div class="admin-form-grid">'
            +UI.field('备注',UI.input('remark',group?.remark||'','maxlength="500"'),{full:true})
            +'</div>';
        UI.form({title:group?'编辑轮询组 · '+group.name:'新增轮询组',width:900,content,
            onOpen(handle,form){
                const body=form.querySelector('.group-members');let index=0;
                form.querySelector('.group-add').hidden=!!group?.is_default;
                if(group?.is_default)UI.feedback(form.querySelector('.group-hint'),'通道自动加入本组；关闭成员开关即可退出默认收款。','info');
                function strategy(){
                    const mode=form.elements.route_mode.value;
                    const tips={1:'排序值越小，轮询顺序越靠前；按顺序依次选择可用通道。',2:'权重越大，选中概率越高。概率为本通道权重占本次可用通道总权重的比例；例如权重 3 和 7，概率分别为 30% 和 70%。短期次数不保证严格按比例分配。',3:'优先选择可用默认通道；默认通道不可用时，按排序值从小到大选择备用通道。'};
                    form.querySelector('.group-strategy-help [data-help]').dataset.help='同一通道只能添加一次。'+tips[mode];
                    form.querySelectorAll('[data-sort]').forEach(node=>node.hidden=mode==='2');form.querySelectorAll('[data-weight]').forEach(node=>node.hidden=mode!=='2');form.querySelectorAll('[data-default]').forEach(node=>node.hidden=mode!=='3');
                }
                function add(member){
                    const options=channels.filter(channel=>channel.pay_types.includes(form.elements.pay_type.value)).map(channel=>({value:channel.id,label:channel.name+(channel.enabled?'':'（已停用）')}));
                    if(member&&!options.some(option=>option.value===member.channel_id))options.push({value:member.channel_id,label:'当前通道 #'+member.channel_id+'（不可用）'});
                    const row=document.createElement('tr');row.dataset.member=String(++index);
                    row.innerHTML='<td>'+UI.select('member_channel_'+index,[{value:'',label:'请选择通道'},...options],member?.channel_id||'','data-member-field="channel_id" required lay-search'+(group?.is_default?' disabled':''))+'</td>'
                        +'<td data-sort>'+UI.input('member_sort_'+index,member?.sort_no??0,'data-member-field="sort_no" type="number" min="0" max="1000000" step="1" required')+'</td>'
                        +'<td data-weight>'+UI.input('member_weight_'+index,member?.weight??1,'data-member-field="weight" type="number" min="1" max="1000000" step="1" required')+'</td>'
                        +'<td data-default><input type="radio" name="default_member" value="'+index+'" data-member-field="is_default"'+(member?.is_default?' checked':'')+' title="默认"></td>'
                        +'<td><input type="checkbox" data-member-field="enabled" lay-skin="switch" lay-text="启用|停用"'+(member?.enabled===false?'':' checked')+'></td><td><button type="button" class="admin-link danger member-remove">移除</button></td>';
                    row.querySelector('.member-remove').hidden=!!group?.is_default;
                    row.querySelector('.member-remove').addEventListener('click',()=>{row.remove();form.dispatchEvent(new Event('input',{bubbles:true}));});
                    body.append(row);UI.render(form);strategy();
                }
                (group?.members||[]).forEach(add);strategy();
                form.querySelector('.group-add').addEventListener('click',()=>{
                    if(!channels.some(channel=>channel.pay_types.includes(form.elements.pay_type.value))){UI.feedback(form.querySelector('.group-hint'),'没有可选择的通道，请先在收款配置中新增对应通道。');return;}
                    UI.feedback(form.querySelector('.group-hint'),'');add(null);form.dispatchEvent(new Event('input',{bubbles:true}));
                });
                form.elements.route_mode.addEventListener('change',strategy);
                let previousType=form.elements.pay_type.value;
                form.elements.pay_type.addEventListener('change',async()=>{
                    if(body.children.length&&!await UI.confirm({title:'切换支付方式',content:'确定切换支付方式？',description:'当前未保存的通道成员将被移除。',confirmText:'切换'})){form.elements.pay_type.value=previousType;UI.render(form);return;}
                    previousType=form.elements.pay_type.value;body.replaceChildren();UI.feedback(form.querySelector('.group-hint'),!channels.some(channel=>channel.pay_types.includes(previousType))?'当前没有支持此支付方式的通道。':'');
                });
            },
            async onSubmit(form,handle){
                const members=Array.from(form.querySelectorAll('[data-member]')).map(row=>Object.fromEntries(Array.from(row.querySelectorAll('[data-member-field]')).map(control=>[control.dataset.memberField,['checkbox','radio'].includes(control.type)?control.checked:control.type==='number'?Number(control.value):control.value])));
                if(new Set(members.map(item=>item.channel_id)).size!==members.length)throw new Error('组内不能重复添加同一通道');
                if(!group?.is_default&&!channels.some(channel=>channel.pay_types.includes(form.elements.pay_type.value)))throw new Error('当前没有支持此支付方式的通道');
                const data={name:form.elements.name.value,route_mode:Number(form.elements.route_mode.value),remark:form.elements.remark.value,members};
                if(group)Object.assign(data,{id:group.id,revision:group.revision,enabled:form.elements.enabled.checked});else data.pay_type=form.elements.pay_type.value;
                await MpayHttp.request('/adminapi/poll-groups/'+(group?'save':'create'),{method:'POST',data});handle.clean();handle.close();UI.message('轮询组已保存');await load();
            }
        });
    }
    layui.table.on('tool(groups-list)',event=>{if(event.event==='edit')edit(event.data);});
    filters.addEventListener('submit',event=>{event.preventDefault();renderList();});UI.onReset(filters,()=>{locationValues.delete('channel_id');UI.render(filters);renderList();});
    document.getElementById('groups-refresh').addEventListener('click',load);document.getElementById('groups-new').addEventListener('click',()=>edit(null));load();
});
