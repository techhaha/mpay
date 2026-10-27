/** 后台与收银台共用的文本复制；只返回是否复制成功，提示交给页面的 Layui 组件。 */
(function (global) {
    'use strict';
    /** 必须从用户操作调用；普通 HTTP 站点可能无 Clipboard API，权限拒绝也可能发生在 HTTPS。 */
    async function writeText(value) {
        const text = String(value);
        try {
            if (typeof navigator.clipboard?.writeText === 'function') {
                await navigator.clipboard.writeText(text);
                return true;
            }
        } catch (_) {
            /* 包含同步异常与权限拒绝，继续尝试浏览器兼容复制。 */
        }

        // execCommand 仅作兼容后备；检查真实返回值，浏览器全部拒绝时不能提示“已复制”。
        const active = document.activeElement,
            selection = global.getSelection();
        const ranges = selection
            ? Array.from({ length: selection.rangeCount }, (_, index) =>
                  selection.getRangeAt(index).cloneRange(),
              )
            : [];
        const inputSelection =
            active && typeof active.selectionStart === 'number'
                ? [active.selectionStart, active.selectionEnd, active.selectionDirection]
                : null;
        const field = document.createElement('textarea');
        field.value = text;
        field.readOnly = true;
        field.tabIndex = -1;
        field.style.cssText =
            'position:fixed;top:0;left:-9999px;width:1px;height:1px;font-size:16px;';
        try {
            document.body.append(field);
            field.focus({ preventScroll: true });
            field.select();
            field.setSelectionRange(0, field.value.length);
            return (
                typeof document.execCommand === 'function' && document.execCommand('copy') === true
            );
        } catch (_) {
            return false;
        } finally {
            field.remove();
            // 兼容复制借用了选区，结束后恢复原表单焦点及选区，不清空或提交用户输入。
            try {
                if (active?.isConnected) active.focus({ preventScroll: true });
                if (selection) {
                    selection.removeAllRanges();
                    ranges.forEach((range) => selection.addRange(range));
                }
                if (inputSelection && active?.isConnected)
                    active.setSelectionRange(...inputSelection);
            } catch (_) {
                /* 复制期间原弹窗可能已销毁；恢复失败不改变已经完成的复制结果。 */
            }
        }
    }
    global.MpayClipboard = { writeText };
})(window);
