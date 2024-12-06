// 插件配置
const plugins = [
  {
    name: '收钱吧',
    host: 'web-platforms-msp.shouqianba.com',
    method: 'POST',
    orderQuery: '/api/transaction/findTransactions',
    channelKey: 'terminal_device_fingerprint',
    moneyKey: 'original_amount',
    listPath: 'data.records'
  }
];

// 登陆配置
const logins = [
  {
    name: '新商城',
    host: 'localhost',
    method: 'GET',
    orderQuery: '/api/Order/getOrders',
    accPath: 'order_id',
    pswPath: 'money',
  }
];

// 提取订单信息
function extractOrderInfo(response, plugins) {
  plugins.forEach((plugin) => {
    const urlObj = new URL(response.url);
    if (plugin.host === urlObj.hostname
      && plugin.orderQuery === urlObj.pathname
      && plugin.method === response.method) {
      const jsonDatas = JSON.parse(response.response);
      const orderDatas = eval(`jsonDatas.${plugin.listPath}`);
      let lists = [];
      orderDatas.forEach((orderData) => {
        const data = {
          '终端编号': orderData[plugin.channelKey],
          '订单金额': orderData[plugin.moneyKey]
        };
        lists.push(data);
      })
      console.log(plugin.name);
      console.table(lists);
    }
  })
}

// 提取登陆信息
function extractLoginInfo(request, logins) {
  logins.forEach((login) => {
    const urlObj = new URL(request.url);
    if (login.host === urlObj.hostname
      && login.orderQuery === urlObj.pathname
      && login.method === request.method) {
      const jsonData = JSON.parse(request.request);
      const acc = eval(`jsonData.${login.accPath}`);
      const psw = eval(`jsonData.${login.pswPath}`);
      const data = {
        '账号': acc,
        '密码': psw
      };
      console.log(login.name);
      console.table(data);
    }
  })
}

// XHR 重写
var oldOpen = XMLHttpRequest.prototype.open;
var oldSend = XMLHttpRequest.prototype.send;
XMLHttpRequest.prototype.open = function (method, url) {
  this._url = url;
  this._method = method;
  const res = {
    url: this.responseURL,
    method: this._method,
    response: this._body
  }
  extractLoginInfo(res, logins);
  return oldOpen.apply(this, arguments);
};
XMLHttpRequest.prototype.send = function (body) {
  this._body = body;
  this.addEventListener('load', function () {
    const res = {
      url: this.responseURL,
      method: this._method,
      response: this.responseText
    }
    console.log(res);
    
    extractOrderInfo(res, plugins);
  });
  return oldSend.apply(this, arguments);
};

// fetch 重写
window.au_fetch = window.fetch;
window.fetch = function (url, options) {
  // console.log('Fetch URL:', url);
  // console.log('Fetch Options:', options);
  return window.au_fetch.apply(window, [url, options]).then((response) => {
    const res = {
      url: url,
      method: options.method,
      response: response.text()
    }
    extractOrderInfo(res, plugins);
    return response;
  });
};