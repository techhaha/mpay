/** 后台认证表单只提交用户输入；会话、密码策略和权限由服务器决定，不在浏览器保存任何凭据。 */
MpayForm.ready(function () {
    'use strict';
    const form = document.querySelector('[data-admin-form]');
    const feedback = document.getElementById('admin-feedback');
    const logout = document.getElementById('admin-logout');
    const mode = form?.dataset.adminForm;
    let busy = false;

    function message(text) {
        if (feedback) { feedback.textContent = text; feedback.dataset.tone = text.includes('正在') ? 'loading' : 'error'; }
    }
    /** 表单与退出共享提交锁；只抑制当前页面并行点击，不以此替代服务器事务或重放保护。 */
    function submitting(value) {
        busy = value;
        form?.setAttribute('aria-busy', String(value));
        form?.querySelectorAll('button').forEach(button => { button.disabled = value; });
        if (logout) logout.disabled = value;
    }
    // 登录本身的 401 表示密码不正确，应留在表单；已登录页面的 401 才跳到固定登录入口。
    window.addEventListener('mpay:session-expired', () => {
        if (mode !== 'login') location.replace('/admin/login');
    });

    form?.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !MpayForm.valid(form)) return;
        const values = new FormData(form);
        const input = mode === 'login'
            ? {username: values.get('username'), password: values.get('password')}
            : {current_password: values.get('current_password'), new_password: values.get('new_password'),
                password_confirmation: values.get('password_confirmation')};
        if (mode === 'password' && input.new_password !== input.password_confirmation) {
            message('两次输入的新密码必须一致');
            return;
        }
        submitting(true); message('正在提交…');
        try {
            await MpayHttp.request(mode === 'login' ? '/adminapi/login' : '/adminapi/password', {method: 'POST', data: input});
            // 改密后必须重新认证；响应丢失时也不自动重试原密码操作。
            form.reset();
            location.replace(mode === 'login' ? '/admin/dashboard' : '/admin/login');
        } catch (error) {
            const fields = error.data?.errors;
            const detail = fields ? Object.values(fields).flat().filter(value => typeof value === 'string').join('；') : '';
            message(detail || error.message || '请求未完成，请稍后重试');
            if (mode === 'login') form.elements.password.value = '';
        } finally { submitting(false); }
    });

    logout?.addEventListener('click', async () => {
        if (busy) return;
        submitting(true);
        try {
            const exit=()=>MpayHttp.request('/adminapi/logout', {method: 'POST', data: {}});
            if(window.MpayAdmin){
                if(!await MpayAdmin.confirm({title:'退出所有登录',content:'确定退出所有后台登录？',description:'当前及其他浏览器的登录会话都将退出。',confirmText:'退出登录',onConfirm:exit}))return;
            }else await exit();
            location.replace('/admin/login');
        } catch (error) {
            // 不把未知网络结果当作退出成功；用户可以直接重新打开登录入口确认状态。
            message(error.message || '退出状态未确认，请重新打开登录页面');
        } finally { submitting(false); }
    });
    document.getElementById('account-logout')?.addEventListener('click', () => logout?.click());
});
