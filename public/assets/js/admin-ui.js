/**
 * 后台共享交互：复用 Layui 弹层、表单、表格和提示，不承担支付状态或业务权限判断。
 * 动态业务文本先转义；只有调用方编写的固定模板可以作为 HTML 传入弹层。
 */
(function (global) {
    'use strict';
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    let sequence = 0;
    const dateControls = new WeakMap();
    const tableLimits = new WeakMap(), tableRequests = new WeakMap();
    const pageSizes = [10, 20, 50, 100];
    let catalogPromise = null, catalog = {types:[],plugins:[]};
    const paymentLabels = Object.create(null);
    function applyCatalog(data) {
        catalog = data;
        Object.keys(paymentLabels).forEach(key => delete paymentLabels[key]);
        data.types.forEach(type => paymentLabels[type.code] = type.name);
        MpayBrand.setPayments(data.types);
        document.querySelectorAll('[data-payment-filter]').forEach(select => {
            const value=select.value; select.replaceChildren(new Option('全部方式',''));
            data.types.forEach(type=>select.add(new Option(type.name,type.code)));
            select.value=value;
        });
        return data;
    }
    function loadCatalog(force = false) {
        if (force || !catalogPromise) catalogPromise=MpayHttp.request('/adminapi/catalog').then(result=>applyCatalog(result.data)).catch(error=>{catalogPromise=null;throw error;});
        return catalogPromise;
    }
    const ready = callback => layui.use(['layer', 'form', 'table', 'laydate', 'tabs', 'dropdown', 'upload', 'util', 'element'], async () => {
        try { if(document.body.classList.contains('admin-page')) await loadCatalog(); await callback(); }
        catch(exception){const target=document.getElementById('admin-feedback');if(target) error(target,exception);else layui.layer.msg('基础目录读取失败，请刷新页面');}
    });
    const icon = name => '<i class="layui-icon layui-icon-' + escape(name) + '" aria-hidden="true"></i>';
    const help = text => '<button type="button" class="admin-help-button" data-help="' + escape(text) + '" aria-label="查看说明">' + icon('about') + '</button>';
    function payment(code) {
        const asset = MpayBrand.paymentAsset(code);
        return '<span class="admin-pay-label"><img src="' + escape(asset?.icon || MpayBrand.fallbackImage) + '" alt="">' + escape(paymentLabels[code] || code) + '</span>';
    }
    function badge(label, tone = 'gray') { return '<span class="layui-badge admin-tag ' + escape(tone) + '">' + escape(label) + '</span>'; }
    function state(label) {
        const text = String(label || '未知');
        // 颜色表达处置优先级：尚未发生/已退出属于中性，待核对或重试需关注，不能都画成正在处理。
        const tone = /失败|未通过|异常/.test(text) ? 'red' : /核对|重试|关注|限流|待配置|补发未确认/.test(text) ? 'orange'
            : /停用|关闭|超时|重复|未建立|暂无|未知|已退出|尚未生成|未安排|暂不通知/.test(text) ? 'gray'
            : /成功|已确认|已关联|启用|通过|近期有活动|已创建|已更新|已保存/.test(text) ? 'green' : 'blue';
        return badge(text, tone);
    }
    function field(label, control, options = {}) {
        return '<div class="admin-field' + (options.full ? ' full' : '') + (options.required ? ' is-required' : '') + '"><label>' + escape(label) + (options.help ? help(options.help) : '') + '</label>' + control + (options.hint ? '<p class="admin-hint">' + escape(options.hint) + '</p>' : '') + '</div>';
    }
    function input(name, value = '', attributes = '') { return '<input class="layui-input" name="' + escape(name) + '" value="' + escape(value) + '" ' + attributes + '>'; }
    function select(name, options, value = '', attributes = '') {
        // 默认使用普通选择；需要检索的业务列表由调用方显式传入官方 lay-search 属性。
        return '<select name="' + escape(name) + '" ' + attributes + '>' + options.map(option => '<option value="' + escape(option.value) + '"' + (String(option.value) === String(value) ? ' selected' : '') + (option.disabled ? ' disabled' : '') + '>' + escape(option.label) + '</option>').join('') + '</select>';
    }
    function textarea(name, value = '', attributes = '') { return '<textarea class="layui-textarea" name="' + escape(name) + '" ' + attributes + '>' + escape(value) + '</textarea>'; }
    function unit(name, value, suffix, attributes = '') { return '<div class="admin-unit">' + input(name, value, attributes) + '<span>' + escape(suffix) + '</span></div>'; }
    function facts(values) { return '<dl class="admin-facts">' + Object.entries(values).map(([label,value]) => '<dt>' + escape(label) + '</dt><dd>' + escape(value == null ? '—' : typeof value === 'boolean' ? (value ? '是' : '否') : value) + '</dd>').join('') + '</dl>'; }
    function notice(text, warning = false) { return '<div class="admin-notice' + (warning ? ' warning' : '') + '">' + escape(text) + '</div>'; }
    function feedback(target, message = '', tone = 'error') {
        const node = typeof target === 'string' ? document.querySelector(target) : target;
        if (!node) return;
        node.textContent = message; node.dataset.tone = tone;
    }
    function error(target, exception) {
        const fields = exception.data?.errors;
        const scope = target?.closest('.admin-dialog') || document;
        const detail = fields ? Object.entries(fields).map(([name,messages]) => {
            const field = /^(settings|configuration)\./.test(name) ? 'setting_' + name.replace(/^[^.]+\./,'') : name.replace(/^channel\./,'');
            const input = scope.querySelector('[name="' + CSS.escape(field) + '"]');
            const label = input?.closest('.admin-field')?.querySelector('label')?.textContent.replace('ⓘ','').trim();
            const text = (Array.isArray(messages) ? messages : [messages]).filter(value => typeof value === 'string').join('，');
            return text ? (label && !text.startsWith(label) ? label + '：' : '') + text : '';
        }).filter(Boolean).join('；') : '';
        const message = exception instanceof TypeError ? '页面处理异常，请重新读取' : exception.message || '操作未完成，请稍后重试';
        feedback(target, message + (detail ? '：' + detail : ''));
    }
    function message(text) { layui.layer.msg(escape(text), {time:2300}); }
    /**
     * 无输入操作使用官方 confirm/异步按钮；content、description 和按钮文案均按文本转义。
     * onConfirm 只提交操作，刷新放在返回 true 之后，避免刷新失败导致重复写入。
     * 版本冲突或写入结果未知须重新读取；普通业务拒绝保留操作入口。
     * @param {{title: string, content: string, description?: string, confirmText: string, danger?: boolean,
     *   onConfirm?: () => Promise<unknown>, onError?: (error: Error, feedback: HTMLElement) => void}} options
     * @returns {Promise<boolean>} 弹层关闭后返回是否已确认且提交成功。
     */
    function confirm(options) {
        const opener = document.activeElement;
        let busy = false, stale = false, accepted = false, layerNode, index;
        return new Promise(resolve => {
            function keyboard(event) {
                if (event.defaultPrevented || !layerNode?.isConnected) return;
                if (event.key === 'Escape') { event.preventDefault(); layui.layer.close(index); }
                if (event.key === 'Tab') {
                    const controls = Array.from(layerNode.querySelectorAll('a[href],[tabindex="0"]')).filter(node => node.getClientRects().length);
                    const first = controls[0], last = controls.at(-1);
                    if (first && (event.shiftKey ? document.activeElement === first : document.activeElement === last)) {
                        event.preventDefault(); (event.shiftKey ? last : first).focus();
                    }
                }
            }
            index = layui.layer.confirm('<div class="admin-confirm-question">' + escape(options.content) + '</div>'
                + (options.description ? '<p class="admin-confirm-description">' + escape(options.description) + '</p>' : '')
                + '<div class="admin-feedback" role="status" aria-live="polite"></div>', {
                title:escape(options.title), area:Math.min(420, innerWidth - 24) + 'px',
                skin:'mpay-admin-confirm' + (options.danger ? ' mpay-admin-confirm-danger' : ''),
                btn:[escape(options.confirmText), '取消'], btnAsync:true,
                shade:.22, shadeClose:true, scrollbar:false, resize:false, move:false, anim:0, isOutAnim:true,
                beforeEnd:() => !busy,
                success(layer) {
                    layerNode = layer[0];
                    layer.attr({role:'alertdialog', 'aria-modal':'true', 'aria-label':options.title});
                    layer.find('.layui-layer-close').attr('aria-label','关闭');
                    // 官方按钮为 a 元素，补齐键盘可操作性；关闭和异步状态仍由 layer 管理。
                    layer.find('.layui-layer-btn a,.layui-layer-close').attr({role:'button', tabindex:'0'}).on('keydown', function(event) {
                        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); this.click(); }
                    });
                    layer.find('.layui-layer-btn1')[0]?.focus();
                    document.addEventListener('keydown',keyboard);
                },
                end() {
                    document.removeEventListener('keydown',keyboard);
                    if (opener?.isConnected) opener.focus();
                    resolve(accepted);
                }
            }, async (index, layer, instance) => {
                if (busy || stale || accepted) return false;
                // loading 绑定本次按钮；等待期间点击取消也不会改变此处的加载目标。
                const loading = instance.loading;
                busy = true; layer.attr('aria-busy','true'); loading(true);
                const target = layerNode.querySelector('.admin-feedback'); feedback(target,'');
                try { await options.onConfirm?.(); accepted = true; return true; }
                catch (exception) {
                    stale = exception.code === 40910 || exception.outcomeUnknown === true;
                    error(target,exception);
                    if (stale) {
                        target.append('。请关闭弹窗，重新读取并核对结果后再操作。');
                        layer.find('.layui-layer-btn0').attr('aria-disabled','true').addClass('layui-btn-disabled');
                    }
                    options.onError?.(exception,target);
                    return false;
                } finally { loading(false); busy = false; layer.removeAttr('aria-busy'); }
            });
        });
    }
    const valid = root => MpayForm.valid(root);
    /** 等原生 reset 完成再读筛选值；接管本表单重绘，避免 Layui 的延后重绘丢失可访问标签。 */
    function onReset(form, callback) {
        form.addEventListener('reset', event => {
            event.stopPropagation();
            setTimeout(callback, 0);
        });
    }
    function render(root = document) {
        MpayForm.prepare(root);
        // 使用 Layui 独立面板，避免弹窗滚动区和成员表格裁切选项；不自行实现另一套下拉控件。
        if (root.closest?.('.admin-dialog')) root.querySelectorAll('select').forEach(select => {
            select.setAttribute('lay-append-to','body');
            select.setAttribute('lay-append-position','fixed');
        });
        // 动态表单先关联可见标签，再交给 Layui 渲染；弹层中的同名字段使用独立 ID。
        root.querySelectorAll('.admin-field').forEach(field => {
            const label = field.querySelector(':scope > label'), control = field.querySelector('input:not([type=hidden]),select,textarea');
            if (!label || !control) return;
            if (!control.id) control.id = 'admin-control-' + (++sequence);
            label.htmlFor = control.id;
        });
        if (root === document) layui.form.render();
        else {
            const forms = root.matches('.layui-form') ? [root] : Array.from(root.querySelectorAll('.layui-form'));
            forms.forEach(form => layui.form.render(layui.$(form)));
        }
        root.querySelectorAll('select').forEach(select => {
            const replacement = select.nextElementSibling?.querySelector('input');
            const label = Array.from(select.labels?.[0]?.childNodes || []).filter(node => node.nodeType === Node.TEXT_NODE).map(node => node.textContent).join('').trim();
            if (replacement && label) replacement.setAttribute('aria-label', label);
        });
        root.querySelectorAll('[data-admin-tabs]').forEach(node => { if (!node.dataset.ready) { node.dataset.ready = '1'; layui.tabs.render({elem:node}); } });
        root.querySelectorAll('[data-admin-collapse]').forEach(node => {
            if (node.dataset.ready) return;
            node.dataset.ready='1';const filter='admin-collapse-'+(++sequence);node.setAttribute('lay-filter',filter);
            layui.element.render('collapse',filter);
        });
        root.querySelectorAll('[data-admin-date]').forEach(node => dateControl(node));
    }
    function closeSelectPanels(root) {
        const selected = layui.$(root).find('.layui-form-selected');
        if (!selected.length) return;
        // 官方 select 文档的 layer 滚动处理：detach 保留组件事件，不能 remove 后破坏再次打开。
        selected.removeClass('layui-form-selected');
        layui.$('.layui-select-panel-wrap').detach();
    }
    /** 弹层持有自己的提交状态；关闭时清除密码，恢复触发元素焦点，不保存业务数据到浏览器存储。 */
    function open(options) {
        const node = document.createElement('section'), opener = document.activeElement;
        const id = 'admin-dialog-' + (++sequence);
        node.className = 'admin-dialog' + (options.className ? ' ' + options.className : ''); node.id = id;
        node.innerHTML = '<div class="admin-dialog-body"><div class="admin-feedback" role="status" aria-live="polite"></div>' + options.content + '</div><div class="admin-dialog-footer">' + (options.footer ?? (options.drawer ? '' : '<button type="button" class="layui-btn layui-btn-primary" data-dialog-close>关闭</button>')) + '</div>';
        document.body.append(node);
        let busy = false, dirty = false, closed = false, closing = false, closeApproved = false, index;
        function keyboard(event) {
            const layers = Array.from(document.querySelectorAll('.layui-layer[role="dialog"],.layui-layer[role="alertdialog"]'))
                .sort((a,b) => Number(getComputedStyle(a).zIndex)-Number(getComputedStyle(b).zIndex));
            const layer = node.closest('.layui-layer');
            if (event.defaultPrevented || layers.at(-1)!==layer) return;
            if (event.key==='Escape') {
                event.preventDefault();
                if (closeDatePanels(node)) return;
                if (node.querySelector('.layui-form-selected')) closeSelectPanels(node);
                else requestClose();
            } else if (event.key==='Tab' && !event.target.closest('.layui-select-panel-wrap,.layui-laydate')) {
                const focusable=Array.from(layer.querySelectorAll('button,a[href],input,select,textarea,[tabindex="0"]'))
                    .filter(element=>!element.disabled && element.getClientRects().length);
                const first=focusable[0],last=focusable.at(-1);
                if (first && (event.shiftKey ? document.activeElement===first : document.activeElement===last)) {
                    event.preventDefault();(event.shiftKey?last:first).focus();
                }
            }
        }
        const handle = {node, get busy() {return busy;}, get closed() {return closed;},requestClose,
            // 业务保存完成或已确认的切换使用；用户关闭入口统一经过 beforeEnd。
            close() { if (!closed) { closeApproved = true; layui.layer.close(index); } },
            clean() { dirty = false; },
            feedback(text,tone) { feedback(node.querySelector('.admin-feedback'),text,tone); },
            error(exception) { error(node.querySelector('.admin-feedback'),exception); },
            async run(callback) {
                if (busy) return; busy = true; node.setAttribute('aria-busy','true');
                const buttons = Array.from(node.querySelectorAll('button')).filter(button => !button.disabled);
                buttons.forEach(button => button.disabled = true);
                try { await callback(); } catch (exception) { handle.error(exception); }
                finally { busy = false; node.removeAttribute('aria-busy'); buttons.forEach(button => { if (!button.hasAttribute('data-keep-disabled')) button.disabled = false; }); }
            }
        };
        async function canClose() {
            if (closed) return false;
            if (closeApproved) return true;
            if (busy || closing) return false;
            closing = true;
            try {
                closeDatePanels(node);
                if (options.form && dirty && !await confirm({title:'放弃修改',content:'有尚未保存的修改，确定放弃？',confirmText:'放弃修改'})) return false;
                closeApproved = true;return true;
            } finally { closing = false; }
        }
        async function requestClose() {
            if (!await canClose()) return false;
            // 等待组件完成退出，再切换到下一编辑器，避免旧遮罩与新弹层重叠。
            return new Promise(resolve => layui.layer.close(index, () => resolve(true)));
        }
        index = layui.layer.open({type:1,title:escape(options.title),content:layui.$(node),
            area:options.drawer ? [Math.min(options.width || 800,innerWidth) + 'px','100%'] : Math.min(options.width || 720,innerWidth - 24) + 'px',
            offset:options.drawer ? 'r' : 'auto',anim:options.drawer ? 'slideLeft' : -1,isOutAnim:!!options.drawer,resize:false,move:false,scrollbar:false,shade:.22,shadeClose:true,
            skin:'mpay-admin-layer' + (options.drawer ? ' mpay-admin-drawer' : ''),
            success(layer) {
                layer.attr({role:'dialog','aria-modal':'true','aria-label':options.title});
                layer.find('.layui-layer-close').attr('aria-label','关闭');
                render(node); options.onOpen?.(handle);
                // 优先落在可编辑字段，避免自动聚焦说明按钮而弹出无关提示。
                const focus = Array.from(node.querySelectorAll('input:not([type=hidden]):not(:disabled):not([readonly]),textarea:not(:disabled):not([readonly])'))
                    .find(element=>element.getClientRects().length) || node.querySelector('button[type=submit]:not(:disabled)'); focus?.focus();
            },
            // Layui 原生异步关闭钩子覆盖右上角、遮罩和 close 调用，无需手动绑定遮罩。
            beforeEnd:canClose,
            end() { closed = true; closeSelectPanels(node); closeDatePanels(node,true); document.removeEventListener('keydown',keyboard);node.querySelectorAll('input[type=password]').forEach(input => input.value = ''); options.onClose?.(); node.remove(); if (opener?.isConnected) opener.focus(); }
        });
        // 搜索、只用于预览的输入及未通过校验的选图不是待保存配置；有效选图由调用方派发 change。
        const changed=event=>{if(event.target.type!=='file' && !event.target.matches('[data-admin-transient]') && !event.target.closest('.layui-form-select'))dirty=true;};
        node.addEventListener('input',changed); node.addEventListener('change',changed);
        node.querySelector('.admin-dialog-body').addEventListener('scroll',()=>{closeSelectPanels(node);closeDatePanels(node);});
        document.addEventListener('keydown',keyboard);
        node.addEventListener('click', event => { if (event.target.closest('[data-dialog-close]')) requestClose(); });
        return handle;
    }
    function form(options) {
        const id = 'admin-form-' + (++sequence);
        // 密码复核仍归属当前管理员；明确用户名，避免密码管理器将前一个业务字段识别为登录账号。
        // 此输入无 name，不参与业务表单提交或后台字段验证。
        const identity=options.content.includes('autocomplete="current-password"')
            ? '<input type="text" autocomplete="username" value="'+escape(document.getElementById('admin-username')?.textContent||'')+'" hidden>' : '';
        return open({...options,form:true,content:'<form id="' + id + '" class="layui-form" novalidate>' + identity + options.content + '</form>',
            footer:'<button type="button" class="layui-btn layui-btn-primary" data-dialog-close>取消</button><button type="submit" data-admin-submit form="' + id + '" class="layui-btn">' + escape(options.submit || '保存') + '</button>'
                + (options.secondarySubmit ? '<button type="submit" data-admin-submit form="'+id+'" class="layui-btn layui-btn-primary" data-submit-action="secondary">'+escape(options.secondarySubmit)+'</button>' : ''),
            onOpen(handle) {
                const element = handle.node.querySelector('form');
                let stale = false;
                element.addEventListener('submit',event => {
                    event.preventDefault();if(stale || !valid(options.validationRoot?.(element) || element)) return;
                    handle.run(async () => {
                        try { await options.onSubmit(element,handle,event.submitter?.dataset.submitAction || 'primary'); }
                        catch(exception) {
                            // 只有版本过期或写入结果未知才阻止重投；普通业务拒绝允许原地修正。
                            // retrySafe 只用于服务端幂等且冻结提交参数的用例，不能绕过版本冲突。
                            if(exception.code===40910 || (!options.retrySafe && exception.outcomeUnknown===true)){
                                stale=true;handle.node.querySelectorAll('[data-admin-submit]').forEach(button=>{button.disabled=true;button.dataset.keepDisabled='1';});
                                handle.error(exception);handle.node.querySelector('.admin-feedback').append('。请关闭表单，重新读取并核对结果后再操作。');
                            }else throw exception;
                        }
                    });
                });
                options.onOpen?.(handle,element);
            }
        });
    }
    function tabs(items, activeIndex = 0) {
        return '<div class="layui-tabs" data-admin-tabs lay-options="{index:'+Number(activeIndex)+'}"><ul class="layui-tabs-header">' + items.map((item,index) => '<li lay-id="' + index + '">' + escape(item.title) + '</li>').join('') + '</ul><div class="layui-tabs-body">' + items.map(item => '<div class="layui-tabs-item">' + item.content + '</div>').join('') + '</div></div>';
    }
    function empty(target, text, action = null) {
        target.innerHTML = '<div class="admin-empty">' + icon('template-1') + '<p>' + escape(text) + '</p>' + (action ? '<button class="layui-btn" type="button">' + escape(action.label) + '</button>' : '') + '</div>';
        if (action) target.querySelector('button').addEventListener('click',action.run);
    }
    /**
     * 分页、条数和重载由官方 table 管理；ajax 钩子接入公共同源请求，parseData 只转换成功分页响应。
     * 每张表独立保留当前条数。旧筛选/已关闭弹窗的异步结果不覆盖新表，不把请求失败转为空表成功。
     */
    function table(options) {
        const node = typeof options.elem === 'string' ? document.querySelector(options.elem) : options.elem;
        const requestState={sequence:0};tableRequests.set(node,requestState);
        const done=options.done,errorTarget=options.feedback || document.getElementById('admin-feedback');
        const remote=options.url?{
            parseData:MpayHttp.tableData,
            ajax(original){
                const sequence=++requestState.sequence,url=new URL(original.url,location.origin);
                // 条数切换后立即记住选择，紧接着提交筛选也不能退回上一请求的条数。
                tableLimits.set(node,Number(original.data.limit));
                Object.entries(original.data || {}).forEach(([key,value])=>url.searchParams.set(key,value));
                MpayHttp.request(url.href).then(result=>{
                    if(!node.isConnected || tableRequests.get(node)!==requestState || sequence!==requestState.sequence)return;
                    original.success(result);
                }).catch(exception=>{
                    if(!node.isConnected || tableRequests.get(node)!==requestState || sequence!==requestState.sequence)return;
                    original.error({status:0,responseText:''},'error','列表读取失败');
                    error(errorTarget,exception);
                }).finally(()=>original.complete?.());
            }
        }:{};
        // 单行是默认场景；lineStyle 会开启悬停滚动，不能为统一行高而全局开启。
        return layui.table.render({skin:'line',even:false,size:'lg',lineStyle:null,cellExpandedMode:'tips',text:{none:options.emptyText || '暂无记录'},
            ...options,...remote,limit:tableLimits.get(node)||20,limits:pageSizes,
            // 使用官方实例样式统一分页位置，保留分页组件自身的尺寸与交互。
            css:(options.css || '') + '\n.layui-table-page{text-align:right;}',
            page:options.page===false?false:{layout:['count','prev','page','next','limit'],groups:3,...options.page},
            done(result,current,count){if(!options.url)tableLimits.set(node,this.limit);else feedback(errorTarget,'');done?.call(this,result,current,count);}
        });
    }
    async function copy(value) {
        const copied = await MpayClipboard.writeText(value);
        message(copied ? '已复制' : '复制未完成，请手动选择内容复制');
    }
    /**
     * 官方 upload 校验图片后交给业务处理。auto:false 的 choose 早于大小检查，故使用 before。
     * 返回 false 阻止组件再次发送；写请求仍由 MpayHttp 统一处理 CSRF、登录失效和超时。
     */
    function imagePicker(elem, onChoose) {
        return layui.upload.render({elem,accept:'images',acceptMime:'image/png,image/jpeg,image/webp',
            exts:'png|jpg|jpeg|webp',size:2048,multiple:false,auto:true,
            async before(object) {
                const file = Object.values(object.getChooseFiles())[0];
                if (file) await onChoose(file, object);
                return false;
            }
        });
    }
    /** 商户返回内容只作为文本查看；列表隐藏长正文，避免错误页撑开表格或执行远端 HTML。 */
    function responseColumn() {
        return {title:'商户响应',width:115,align:'center',templet:row=>row.response_body===null || row.response_body===undefined
            ? '<span class="admin-muted">暂无响应正文</span>'
            : '<button type="button" class="admin-link" data-merchant-response="'+escape(row.response_body)+'" data-response-truncated="'+(row.response_truncated?'1':'0')+'">查看'+(row.response_truncated?'（截断）':'')+'</button>'};
    }
    /** 日期和日期时间统一使用 Layui；原样提交上海墙上时间，不经浏览器时区换算。 */
    function dateControl(node, options = {}) {
        if (dateControls.has(node)) return;
        const state = {id:'admin-date-' + (++sequence),open:false};
        dateControls.set(node,state);
        const type=node.dataset.adminDate || 'date';
        if(node.tagName==='INPUT'){
            const wrapper=document.createElement('span');wrapper.className='admin-date-field';
            node.before(wrapper);wrapper.append(node);
            const marker=document.createElement('i');marker.className='layui-icon layui-icon-date';marker.setAttribute('aria-hidden','true');wrapper.append(marker);
            node.autocomplete='off';
        }
        layui.laydate.render({elem:node,id:state.id,type,format:type==='time'?'HH:mm':type==='datetime'?'yyyy-MM-dd HH:mm:ss':'yyyy-MM-dd',
            position:'fixed',...options,
            ready(){state.open=true;},close(){state.open=false;},
            done(value){
                options.done?.(value);
                // 组件赋值不会原生派发 change；让脏表单保护与手工输入使用相同合同。
                node.dispatchEvent(new Event('change',{bubbles:true}));
            }
        });
    }
    /** 只关闭/解绑所属表单的日期面板，避免嵌套弹窗遗留面板或事件。 */
    function closeDatePanels(root, unbind = false) {
        let closed = false;
        root.querySelectorAll('[data-admin-date],[data-date-range]').forEach(node => {
            const state=dateControls.get(node);if(!state)return;
            if(state.open){layui.laydate.close(state.id);closed=true;}
            if(unbind){layui.laydate.unbind(state.id);dateControls.delete(node);}
        });
        return closed;
    }
    /** 日期范围保持服务端提供的上海自然日，不将浏览器 UTC 偏移写入查询条件。 */
    function dateRange(form) {
        const node = form.querySelector('[data-date-range]'); if (!node) return;
        const start=form.elements.start_date,end=form.elements.end_date;
        if(!start.id)start.id='admin-date-start-'+(++sequence);
        if(!end.id)end.id='admin-date-end-'+(++sequence);
        // Layui 官方双输入范围绑定负责回填，采用默认独立面板，不另拆字符串或复制日期运算。
        dateControl(node,{range:['#'+start.id,'#'+end.id],rangeLinked:false});
    }
    /** 表单金额使用十进制字符串转分，不能以浮点乘法处理交易输入。 */
    function cents(value) { if (!/^(0|[1-9][0-9]{0,6})(\.[0-9]{1,2})?$/.test(value)) throw new Error('金额须为最多两位小数的元金额'); const [integer,fraction=''] = value.split('.'); return Number(integer)*100+Number(fraction.padEnd(2,'0')); }
    function money(value) { if (!Number.isSafeInteger(value)) return ''; return Math.floor(value/100)+'.'+String(value%100).padStart(2,'0'); }
    /** 配置选择器完整读取分页目录；提前空页属于读取失败，不能静默丢失已有配置。 */
    async function all(path) {
        const items = []; let page = 1, total = 0;
        do {
            const data = (await MpayHttp.request(path + '?' + new URLSearchParams({page,limit:100}))).data;
            total = data.total;
            if (!data.items.length && items.length < total) throw new Error('配置列表读取不完整，请刷新重试');
            items.push(...data.items); page++;
        } while (items.length < total);
        return items;
    }
    global.MpayAdmin = {loadCatalog,applyCatalog,get catalog(){return catalog;},ready,escape,icon,help,payment,paymentLabels,badge,state,field,input,select,textarea,unit,facts,notice,feedback,error,message,confirm,valid,onReset,render,open,form,tabs,empty,table,copy,imagePicker,responseColumn,dateRange,cents,money,all};
    ready(() => {
        render();
        // Layui 的替代控件用统一 DOM change 事件衔接页面原有逻辑，避免重复维护两套表单状态。
        ['select','checkbox','switch','radio'].forEach(type => layui.form.on(type, data => data.elem.dispatchEvent(new Event('change',{bubbles:true}))));
        // 标签页切换（包括 API 切换）后再重算表格，不监听组件内部标题 DOM。
        layui.tabs.on('afterChange', () => layui.table.resize());
        const menu = document.getElementById('admin-account-menu');
        if (menu) layui.dropdown.render({elem:menu,data:[{title:'账户安全',id:'account'},{title:'退出所有登录',id:'logout'}],click:item => { if (item.id === 'account') location.assign('/admin/account'); else document.getElementById('admin-logout').click(); }});
        let tip = null, anchor = null, pinned = false;
        function hide() { if (tip !== null) layui.layer.close(tip); tip = null; anchor = null; pinned = false; }
        function show(element, persist = false) {
            hide();anchor=element;pinned=persist;
            const narrow=innerWidth<620,width=Math.min(280,innerWidth-32);
            tip=layui.layer.tips(escape(element.dataset.help),element,{tips:[narrow?3:2,'var(--mpay-heading)'],time:0,anim:-1,isOutAnim:false,area:[width+'px','auto'],
                success(layer){
                    // 窄屏左右均容不下长提示时改为下方展示，并将框体约束在视口内。
                    const panel=layer[0],rect=panel.getBoundingClientRect();
                    const left=Math.max(16,Math.min(rect.left,innerWidth-rect.width-16));
                    layer.css('left',left+(getComputedStyle(panel).position==='fixed'?0:scrollX)+'px');
                    if(narrow){const source=element.getBoundingClientRect(),arrow=panel.querySelector('i');
                        if(source.bottom+rect.height+8<=innerHeight-16)layer.css('top',source.bottom+8+(getComputedStyle(panel).position==='fixed'?0:scrollY)+'px');
                        if(arrow)arrow.style.left=Math.max(10,Math.min(width-20,source.left+source.width/2-left-5))+'px';}
                }});
        }
        document.addEventListener('mouseover',event => { const element = event.target.closest('[data-help]'); if (element && element !== anchor && !pinned) show(element); });
        document.addEventListener('mouseout',event => { if (!pinned && event.target.closest('[data-help]') && !event.relatedTarget?.closest('[data-help]')) hide(); });
        document.addEventListener('focusin',event => { const element = event.target.closest('[data-help]'); if (element) show(element); });
        document.addEventListener('focusout',event => { if (!pinned && event.target.closest('[data-help]')) hide(); });
        document.addEventListener('click',event => {
            const element = event.target.closest('[data-help]');
            if (element) { event.preventDefault(); if (element === anchor && pinned) hide(); else show(element,true); }
            else if (!event.target.closest('.layui-layer-tips')) hide();
            const copyButton = event.target.closest('[data-copy]'); if (copyButton) copy(copyButton.dataset.copy);
            const responseButton=event.target.closest('[data-merchant-response]');
            if(responseButton)open({title:'商户响应',width:760,content:(responseButton.dataset.responseTruncated==='1'?notice('正文较长或传输中断，仅显示已保存的响应片段。'): '')
                +'<pre class="admin-code admin-response-text">'+escape(responseButton.dataset.merchantResponse || '（空响应）')+'</pre>'});
        });
        document.addEventListener('keydown',event => { if (event.key === 'Escape' && tip!==null) {hide();event.preventDefault();} });
        window.addEventListener('resize',()=>{hide();closeSelectPanels(document);closeDatePanels(document);});
    });
})(window);
