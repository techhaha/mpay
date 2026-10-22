/** Build the public, offline-readable API reference from the canonical Wiki page.
 * Development only: Node 20+ and marked 17.0.5. No API calls or application boot.
 */
import {readFile, writeFile, mkdir} from 'node:fs/promises';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {resolve, dirname} from 'node:path';
import {createHash} from 'node:crypto';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const args = process.argv.slice(2);
const moduleIndex = args.indexOf('--marked-module');
const {Marked, Renderer} = await import(moduleIndex < 0 ? 'marked' : pathToFileURL(resolve(args[moduleIndex + 1])).href);
const check = args.includes('--check');
const source = 'docs/wiki/V1-16-易支付V1接口参考.md';
const template = 'docs/wiki/tools/local-api.template.html';
const examples = ['epay-sign.php', 'epay-create.php'];
const read = path => readFile(resolve(root, path), 'utf8').then(text => text.replace(/\r\n/g, '\n'));
const escape = text => String(text).replace(/[&<>"']/g, c => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[c]));
const toc = [];
const headings = new Map();
let block = 0;
const renderer = new Renderer();
const baseLink = renderer.link;
const baseTable = renderer.table;
renderer.heading = function (token) {
    if (token.depth === 1) return ''; // The page shell supplies its accessible title.
    const label = token.text.replace(/[`*_]/g, '');
    const slug = label.toLowerCase().replace(/[^\p{L}\p{N}_\s-]/gu, '').replace(/\s/g, '-');
    const count = headings.get(slug) || 0;
    headings.set(slug, count + 1);
    const id = count ? `${slug}-${count}` : slug;
    if (token.depth === 2) toc.push({id, label});
    return `<h${token.depth} id="${escape(id)}" tabindex="-1">${this.parser.parseInline(token.tokens)}</h${token.depth}>\n`;
};
renderer.code = function ({text, lang}) {
    const id = `example-${++block}`;
    return `<div class="api-example"><div class="api-example-bar"><span>${escape(lang || 'text')}</span><button type="button" class="layui-btn layui-btn-primary layui-btn-xs" data-copy="${id}" aria-label="复制代码示例 ${block}">复制</button></div><pre tabindex="0"><code id="${id}">${escape(text)}</code></pre></div>\n`;
};
renderer.table = function (token) {
    const classes = token.header.length >= 5 ? 'layui-table api-wide-table' : 'layui-table';
    return `<div class="api-table-scroll" tabindex="0" role="region" aria-label="参数与字段表，可横向滚动">${baseTable.call(this, token).replace('<table>', `<table class="${classes}">`)}</div>`;
};
renderer.link = function (token) {
    const [path, fragment] = token.href.split('#');
    // Example gateway/merchant URLs are values to copy, not live actions.
    if (/^https:\/\/(?:pay|shop)\.example\.com(?:\/|$)/.test(path)) {
        return `<code>${escape(token.text)}</code>`;
    }
    if (path.startsWith('examples/') && examples.includes(path.slice(9))) {
        const href = `downloads/${escape(path.slice(9))}.txt`;
        return `<a href="${href}" download>${this.parser.parseInline(token.tokens)} <span aria-hidden="true">↓</span></a> <a class="api-source-link" href="${href}" target="_blank" rel="noopener noreferrer" aria-label="查看 ${escape(path.slice(9))} 文本">查看文本 ↗</a>`;
    }
    if (path && !/^(?:https?:|mailto:|\/)/.test(path)) {
        if (!path.endsWith('.md')) throw new Error(`Unmapped local reference: ${token.href}`);
        token = {...token, href: 'https://gitee.com/technical-laohu/mpay/wikis/' + encodeURIComponent(path.slice(0, -3)) + (fragment ? '#' + fragment : '')};
    }
    return baseLink.call(this, token);
};
const markdown = await read(source);
const shell = await read(template);
const body = new Marked({gfm:true, renderer}).parse(markdown);
const nav = toc.map(({id, label}, i) => `<a href="#${escape(id)}"><span>${String(i + 1).padStart(2, '0')}</span>${escape(label)}</a>`).join('\n');
const hash = createHash('sha256').update(markdown).digest('hex');
const outputs = new Map([['public/docs/index.html', shell.replace('{{BODY}}', body).replace('{{TOC}}', nav).replace('{{SOURCE_HASH}}', hash)]]);
for (const name of examples) outputs.set(`public/docs/downloads/${name}.txt`, await read(`docs/wiki/examples/${name}`));
for (const [path, content] of outputs) {
    if (check) {
        if (await read(path) !== content) throw new Error(`Generated file is stale: ${path}`);
    } else {
        await mkdir(dirname(resolve(root, path)), {recursive:true});
        await writeFile(resolve(root, path), content, 'utf8');
    }
}
console.log(`${check ? 'Checked' : 'Generated'} ${outputs.size} files; ${toc.length} chapters; ${block} copyable examples.`);
