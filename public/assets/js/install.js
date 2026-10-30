/** 安装步骤由业务推进，输入验证使用 Layui；密码只留在当前表单，写入超时后只检查原目标。 */
MpayForm.ready(function () {
    'use strict';
    const root = document.getElementById('installer');
    if (!root) return;
    const database = document.getElementById('install-database'),
        administrator = document.getElementById('install-administrator');
    const feedback = document.getElementById('install-feedback'),
        resultButton = document.getElementById('install-result');
    const next = document.getElementById('install-next');
    const overwriteChoice = document.getElementById('install-overwrite-choice'),
        overwriteCheckbox = document.getElementById('install-overwrite'),
        overwriteSummary = document.getElementById('install-overwrite-summary'),
        installButton = document.getElementById('install-submit');
    let busy = false,
        ready = false,
        checked = false,
        requiresOverwrite = false,
        reinstalling = false;
    function message(text, failed = false, tone = '') {
        feedback.textContent = text;
        feedback.classList.toggle('is-error', failed);
        feedback.dataset.tone = failed ? 'error' : tone;
    }
    function controls(value) {
        busy = value;
        root.inert = value;
        root.setAttribute('aria-busy', String(value));
        root.querySelectorAll('fieldset,button').forEach((element) => {
            element.disabled = value;
        });
        next.disabled = value || !ready;
    }
    function step(number) {
        root.querySelectorAll('[data-step]').forEach((panel) => {
            panel.hidden = Number(panel.dataset.step) !== number;
        });
        root.querySelectorAll('[data-step-label]').forEach((label) => {
            const index = Number(label.dataset.stepLabel);
            label.classList.toggle('is-complete', index < number);
            if (index === number) label.setAttribute('aria-current', 'step');
            else label.removeAttribute('aria-current');
        });
        const panel = root.querySelector('[data-step="' + number + '"]');
        if (panel.matches('form')) layui.form.render(null, panel.getAttribute('lay-filter'));
        panel.querySelector('h2')?.focus();
    }
    function values() {
        return {
            host: database.elements.host.value,
            port: Number(database.elements.port.value),
            name: database.elements.name.value,
            user: database.elements.user.value,
            pass: database.elements.pass.value,
        };
    }
    function completed(text) {
        database.elements.pass.value = '';
        administrator.elements.password.value = '';
        administrator.elements.password_confirmation.value = '';
        resultButton.hidden = true;
        step(3);
        message(text || '安装完成，请登录管理后台。');
    }
    async function environment() {
        if (busy) return;
        controls(true);
        message('正在检查环境…', false, 'loading');
        try {
            const data = (await MpayHttp.request('/installapi/environment')).data;
            const rows = document.getElementById('install-checks');
            rows.replaceChildren();
            data.checks.forEach((check) => {
                const tr = document.createElement('tr'),
                    name = document.createElement('td'),
                    state = document.createElement('td');
                name.textContent = check.name;
                state.textContent = (check.ok ? '✓ ' : '× ') + check.value;
                state.className = check.ok ? 'install-ok' : 'install-failed';
                tr.append(name, state);
                rows.append(tr);
            });
            ready = data.ready === true;
            // 只预填服务器公开的目标信息，用户输入的密码不会被加载或回显。
            for (const key of ['host', 'port', 'name', 'user'])
                if (!database.elements[key].dataset.edited)
                    database.elements[key].value = data.database[key];
            message(
                data.pending
                    ? '检测到未完成安装，请使用原数据库继续。'
                    : ready
                      ? '环境检查通过，可以继续。'
                      : '请处理未通过的项目后重新检查。',
                !ready,
            );
        } catch (error) {
            if (error.code === 40900) completed('系统已经安装，请直接登录。');
            else message(error.message || '环境检查未完成，请刷新重试。', true);
        } finally {
            controls(false);
        }
    }
    async function checkConnection(advance) {
        if (busy || !MpayForm.valid(database)) return;
        controls(true);
        message('正在检查数据库连接和现有数据…', false, 'loading');
        try {
            const data = (
                await MpayHttp.request('/installapi/check', {
                    method: 'POST',
                    data: values(),
                    timeout: 60000,
                })
            ).data;
            if (!data.ready) {
                completed(data.message);
                return;
            }
            requiresOverwrite = data.requires_overwrite === true;
            reinstalling = data.reinstalling === true;
            overwriteChoice.hidden = !requiresOverwrite;
            if (!requiresOverwrite) overwriteCheckbox.checked = false;
            layui.form.render(null, 'install-database');
            if (requiresOverwrite && !overwriteCheckbox.checked) {
                checked = false;
                message('此数据库已有 MPAY 数据。需要重新安装时，请明确勾选“覆盖并重新安装”。');
                return;
            }
            installButton.textContent = reinstalling
                ? '继续安装'
                : requiresOverwrite
                  ? '覆盖并重新安装'
                  : '立即安装';
            installButton.classList.toggle('layui-btn-danger', requiresOverwrite);
            overwriteSummary.hidden = !requiresOverwrite && !reinstalling;
            overwriteSummary.textContent = requiresOverwrite
                ? '将删除原有 MPAY 数据，并重新创建管理员、默认应用和收款路由。'
                : '继续本次覆盖安装；清理阶段完成后，仅继续建表和初始化，不重复清除数据。';
            checked = true;
            resultButton.hidden = true;
            message(data.message);
            if (advance) step(2);
        } catch (error) {
            // result 检查中 409 可能是已有业务数据或目标错误，不按错误文案猜测成功。
            checked = false;
            message(
                (error.message || '数据库检查未完成') +
                    (error.requestId ? '（请求编号：' + error.requestId + '）' : ''),
                true,
            );
        } finally {
            controls(false);
        }
    }
    database.addEventListener('input', (event) => {
        if (event.target === overwriteCheckbox) return;
        checked = false;
        requiresOverwrite = false;
        reinstalling = false;
        overwriteCheckbox.checked = false;
        overwriteChoice.hidden = true;
        event.target.dataset.edited = 'true';
    });
    database.addEventListener('submit', (event) => {
        event.preventDefault();
        checkConnection(true);
    });
    administrator.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy || !MpayForm.valid(administrator)) return;
        if (!checked) {
            step(1);
            message('请先检查数据库连接。', true);
            return;
        }
        if (
            administrator.elements.password.value !==
            administrator.elements.password_confirmation.value
        ) {
            message('两次管理员密码不一致。', true);
            return;
        }
        const admin = Object.fromEntries(new FormData(administrator));
        const target = values(),
            overwrite = requiresOverwrite || reinstalling;
        controls(true);
        try {
            if (overwrite && !(await confirmOverwrite(target))) return;
            resultButton.hidden = true;
            message('正在安装，请保持页面打开；结果未确认时请先检查安装结果…', false, 'loading');
            const data = (
                await MpayHttp.request('/installapi/install', {
                    method: 'POST',
                    data: {
                        database: target,
                        administrator: admin,
                        overwrite,
                        confirmation: overwrite ? target.name : '',
                    },
                    timeout: 150000,
                })
            ).data;
            completed(data.message);
        } catch (error) {
            resultButton.hidden = false;
            message(
                (error.message || '暂时无法确认安装结果') +
                    '。请点击“检查安装结果”后继续。' +
                    (error.requestId ? ' 请求编号：' + error.requestId : ''),
                true,
            );
        } finally {
            controls(false);
        }
    });
    // 使用官方 prompt 的关闭生命周期等待确认；父表单在弹窗和请求期间保持冻结。
    function confirmOverwrite(target) {
        return new Promise((resolve) => {
            let confirmed = false;
            layui.layer.prompt(
                {
                    title: '输入数据库名，确认覆盖并重新安装',
                    formType: 0,
                    maxlength: 64,
                    shadeClose: false,
                    success(layer) {
                        const note = document.createElement('p');
                        note.className = 'mpay-help';
                        note.textContent =
                            '数据库：' +
                            target.host +
                            ':' +
                            target.port +
                            ' / ' +
                            target.name +
                            '。原管理员、应用、通道、订单及通知数据将被清除。请确认已备份并停止业务和后台任务。' +
                            (reinstalling ? '本次继续原安装进度，不会重复已完成的清理阶段。' : '');
                        layer[0].querySelector('.layui-layer-content').prepend(note);
                    },
                    end() {
                        resolve(confirmed);
                    },
                },
                (value, index) => {
                    if (value !== target.name) {
                        layui.layer.msg('请输入完全一致的数据库名');
                        return;
                    }
                    confirmed = true;
                    layui.layer.close(index);
                },
            );
        });
    }
    root.querySelectorAll('[data-back]').forEach((button) =>
        button.addEventListener('click', () => {
            if (!busy) {
                step(Number(button.dataset.back));
                message('');
            }
        }),
    );
    next.addEventListener('click', () => {
        if (!busy && ready) {
            step(1);
            message('');
        }
    });
    document.getElementById('install-recheck').addEventListener('click', environment);
    resultButton.addEventListener('click', async () => {
        if (busy) return;
        // 已关闭的环境入口返回固定 409；否则检查同一数据库，恢复已提交但未写安装锁的结果。
        try {
            await MpayHttp.request('/installapi/environment');
            await checkConnection(false);
        } catch (error) {
            if (error.code === 40900) completed();
            else message(error.message, true);
        }
    });
    environment();
});
