# Wiki 检查与同步

公开正文的维护入口为 `docs/wiki/`，不要分别手改两个远程 Wiki。工具只依赖 Python 3.10+ 标准库和 Git，默认只检查，不提交、不推送、不删除目标目录中的未知文件。

## 检查

在 MPAY 源码根目录执行：

```sh
python docs/wiki/tools/wiki.py check
```

检查 UTF-8、本地 Markdown/图片/示例链接、标题锚点、发布目录边界、PNG/JPEG 文件头与扩展名是否一致及明显的本机路径。它不检查外站链接是否在线，也不代替人工凭证检查和截图复核。

## 准备 Wiki 仓库

在主程序之外准备两份 Wiki 克隆；如放在项目内，应放入已忽略的 runtime。GitHub 首次启用 Wiki 时，需先通过网页创建 Home，随后才能克隆。

```sh
git clone https://github.com/techhaha/mpay.wiki.git runtime/wiki-publish/github
git clone https://gitee.com/technical-laohu/mpay.wiki.git runtime/wiki-publish/gitee
```

已存在的克隆不要重复创建；先检查工作区并快进更新，远程若有人工编辑，先把有效内容合回 docs/wiki，避免导出覆盖。历史版本通过 Git 保留；原 Gitee Home 另保存在[旧版归档](../旧版-使用百问百答.md)。

## 导出、审阅与推送

```sh
python docs/wiki/tools/wiki.py export --platform github --destination runtime/wiki-publish/github
python docs/wiki/tools/wiki.py export --platform gitee --destination runtime/wiki-publish/gitee
git -C runtime/wiki-publish/github diff --stat
git -C runtime/wiki-publish/gitee diff --stat
```

工具仅向匹配 origin 的 MPAY Wiki 克隆写入白名单文件，并生成 `wiki-manifest.json`。两端页面链接分别指向自身 Wiki；截图、Logo、示例与工具使用 GitHub Wiki 的公开 raw 地址，同时文件也完整保存在两端 Git 仓库。Gitee 阅读图片因此依赖读者可访问 GitHub raw，离线查阅可直接克隆 Wiki。

Wiki 页面不保留 `.md` 扩展名，工具指南导出为独立的 `Wiki-发布工具.md`，避免 README 页面重名。源 Markdown 保持相对链接，可在编辑器及源码仓库阅读。

审阅新增文件和差异，确认无真实密钥、账号密码、私有备份、未脱敏请求/响应或开发机信息后，才分别在 Wiki 克隆中提交和普通推送。不得在主程序目录执行 `git add .` 代替 Wiki 提交，也不使用强制推送。

推送后核对远端提交号，打开首页、教程、侧栏和图片链接。两端 manifest 的 source 摘要应相同；export 摘要允许因页面域名不同而不同。源码中的 README、docs/README 和 docs/wiki 应作为文档变更纳入项目的正常版本提交。

## 生成本站接口文档

客户站点的 `/docs/` 是静态接口文档，无需登录、网络 Markdown 服务或客户侧构建。正文唯一来源为 [易支付 V1 接口参考](../V1-16-易支付V1接口参考.md)，页面模板为 `local-api.template.html`，生成工具为 `build-local-api.mjs`。不要手改生成的 `public/docs/index.html` 或下载文件。

维护者使用 Node.js 20+ 和 Marked 17.0.5。在源码根目录执行一次依赖安装，再生成；这些开发依赖不进入 PHP 客户安装包：

```sh
npm install --no-save --package-lock=false marked@17.0.5
node docs/wiki/tools/build-local-api.mjs
node docs/wiki/tools/build-local-api.mjs --check
python docs/wiki/tools/wiki.py check
```

若使用已有开发运行时的 Marked，可通过 `--marked-module` 指向其 `lib/marked.esm.js`，不要把机器路径写入源码。`--check` 只比较生成结果，不改文件或运行支付请求。

生成器用同一正文产生章节锚点、静态参数表和可复制代码块，补充资料链接转向 Wiki；两个 PHP 示例以 `.php.txt` 提供下载，不在客户站点执行。页面复用本地 Layui、主题及复制辅助代码，网关显示为浏览器当前站点根地址，不读取后台凭证。更新接口合同时，正文和生成结果须一起提交。

交付清单应包含 `public/docs/index.html`、`public/docs/downloads/epay-sign.php.txt`、`public/docs/downloads/epay-create.php.txt` 以及 `public/assets` 下的页面样式和脚本；根目录 docs、tools、tests 继续不进客户包。发布前运行上述检查，再在实际站点打开 `/docs/`，核对首页三个入口、手机目录、表格横向滚动、复制、示例下载和章节链接。

## 配图与示例维护

公开目录只保留使用文档、配图及必要的开发/部署示例。演示助手、临时交互页面等实施工具放入 Git 忽略的私有工作目录，不随源码、安装包或 Wiki 附件交付；正式教程不能依赖这些工具。

页面截图只使用明确的演示数据；不打开凭证弹窗截图。流程图源码为 `tools/render-flows.php`，生成 PNG 供不同 Wiki 渲染器显示。使用 PHP GD/FreeType，传入本机中文字体路径后执行；修改后同步图源与 PNG，并目视检查文字和箭头。

每张操作截图必须完成以下核对后才放入公开 assets：

1. 等待页面数据和弹层动画完成，再截取真实界面。正常截图与长页面截图分别检查，不能假定两者排版相同。
2. 保存后打开实际图片文件，核对标题、字段、关键按钮和结果是否齐全。局部截取必须确认位置正确，不能只检查图片宽高或文件是否存在。
3. 表单超出可视区域时分上下两张，并在正文说明滚动位置；不把缺失的内容补画成截图。
4. JPEG 使用 `.jpg`，PNG 使用 `.png`；导出保持图片原字节，不按文本读写。
5. 在实际文档中检查图片可读性与步骤一致性。截图中的演示域名、编号和测试状态须在正文说明，密码和密钥不得展示。
6. 不合格图片留在公开文档目录之外，完成重截前不引用、不导出。已完成的安装或交易场景需要重新演示时，使用独立环境，不修改历史记录制造画面。

`examples/epay-sign.php` 是签名辅助示例；`examples/ExamplePayment.php` 是完整接口骨架，不是可用支付插件。更新示例后按当前 PHP 与接口检查语法、类加载及预期拒绝行为，不把示例复制进正式插件目录自动启用。
