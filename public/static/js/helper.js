// 登陆配置
const logins = [
  {
    name: '拉卡拉',
    host: 'm2.lakala.com',
    method: 'POST',
    orderQuery: '/m/lamsmerdash/account/pwdLogin',
    accPath: 'account',
    pswPath: 'pwd',
  },
  {
    name: '收钱吧',
    host: 'web-platforms-msp.shouqianba.com',
    method: 'POST',
    orderQuery: '/api/login/ucUser/login',
    accPath: 'username',
    pswPath: 'password',
  }
];

// 提取登陆信息
function extractLoginInfo(request, logins) {
  logins.forEach((login) => {
    const urlObj = isHttp(request.url, login.host);
    if (login.host.toLowerCase() === urlObj.hostname.toLowerCase() &&
      login.orderQuery.toLowerCase() === urlObj.pathname.toLowerCase() &&
      login.method.toLowerCase() === request.method.toLowerCase()) {
      const jsonData = JSON.parse(request.request);
      const acc = eval(`jsonData.${login.accPath}`);
      const psw = eval(`jsonData.${login.pswPath}`);
      const data = {
        '账号': acc,
        '密码': psw
      };
      console.log('----- ' + login.name + ' -----');
      console.table(data);
      alert('账号：' + acc + '\n密码：' + psw);
    }
  })
}

function isHttp(url, host) {
  if (url.startsWith('http') || url.startsWith('https')) {
    return new URL(url);
  } else {
    url = 'https://' + host + url;
    return new URL(url);
  }
}
var oldOpen = XMLHttpRequest.prototype.open;
var oldSend = XMLHttpRequest.prototype.send;
XMLHttpRequest.prototype.open = function (method, url) {
  this._url = url;
  this._method = method;
  return oldOpen.apply(this, arguments);
};
XMLHttpRequest.prototype.send = function (body) {
  this._body = body;
  const res = {
    url: this._url,
    method: this._method,
    request: this._body
  };
  extractLoginInfo(res, logins);
  return oldSend.apply(this, arguments);
};
window.au_fetch = window.fetch;
window.fetch = function (url, options) {
  const res = {
    url: url,
    method: options.method,
    request: options.body
  };
  extractLoginInfo(res, logins);
  return window.au_fetch.apply(window, [url, options]).then((response) => {
    return response;
  })
};