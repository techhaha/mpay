# 码支付

#### 介绍

聚合收款码收款回调工具，主要支持第四方收款服务商的聚合收款码。
可以添加多个收款平台，每个平台添加多个账户，每个账户绑定多个收款码，收款码按照使用时间轮询。


之前做过一些个人的付费项目，因为收款接触过源支付相关产品，产品是非常不错的，但是实际使用体验，是真的一言难尽。


基本网上搜索到的提供能提供服务的平台，大多存续都很不稳定，关站跑路，且收款回调都需要挂机宝之类的工具，经常因为网络问题和登陆问题，导致收款失败，所以就没有再考虑使用此类产品了。

当然，还有彩虹易支付之类的产品，也了解过，连收款安全都无法保证，就更不用考虑了。


后来利用 SmsForwarder短信转发器 做过一个自己使用的工具，但在电池优化，程序稳定通知方面一直不是很满意，就放弃了，转向了其他方向了。


于是就有了这个项目，利用随处可申请的第四收款服务商来达到收款加回调的目的，线上或线下的第四方收款服务提供方，大多都是提供收银终端设备，面向小微商户会提供聚合收款码服务，支持微信和支付宝等
同时提供web版的商户管理后台，也就可以支持回调。当有新订单时，程序会自动登陆商户管理后台，查询收款订单明细，并核验是否收款成功。

这样，只要账户、密码没有问题，就不存在监听掉线的情况，也就非常稳定了。


 **博客文章介绍**  [记录我的第一个thinkphp项目，实现聚合码支付收款回调，无需挂机](https://blog.csdn.net/weixin_44177222/article/details/141722951?fromshare=blogdetail&sharetype=blogdetail&sharerId=141722951&sharerefer=PC&sharesource=weixin_44177222&sharefrom=from_link)


目前已支持的收款平台： **收钱吧** 、 **码钱** 、 **小Y经营** 、 **数字门店** 等。

正在开发的收款平台： **拉卡拉** 、 **云闪付盛意旺** 

 **平台介绍** 

| 平台   | 官网                                  |
|------|-------------------------------------|
| 收钱吧  | https://www.shouqianba.com/         |
| 数字门店 | https://store.zhihuijingyingba.com/ |
| 小Y经营 | https://xym.ysepay.com/             |
| 码钱   | https://m.hkrt.cn/                  |
| 拉卡拉  | https://customer.lakala.com/        |
| 盛付通  | https://b.shengpay.com/             |


#### 软件架构

项目采用 THINKPHP8 + layui 2.9 + PearAdmin后台UI 开发


#### 安装教程

![输入图片说明](public/admin/data/1.png)
![输入图片说明](public/admin/data/2.png)
![输入图片说明](public/admin/data/3.png)
![输入图片说明](public/admin/data/4.png)
![输入图片说明](public/admin/data/5.png)
![输入图片说明](public/admin/data/6.png)
![输入图片说明](public/admin/data/7.png)

#### 使用说明

因为个人使用，并未做什么发行版，主要是学习交流，分享和记录一下自己的首个THINKPHP项目，里面的设计思路和规范，如果有什么好建议欢迎各位同学留言交流

学习交流+ K103516

#### 参与贡献

1.  Fork 本仓库
2.  新建 Feat_xxx 分支
3.  提交代码
4.  新建 Pull Request


#### 特技

1.  使用 Readme\_XXX.md 来支持不同的语言，例如 Readme\_en.md, Readme\_zh.md
2.  Gitee 官方博客 [blog.gitee.com](https://blog.gitee.com)
3.  你可以 [https://gitee.com/explore](https://gitee.com/explore) 这个地址来了解 Gitee 上的优秀开源项目
4.  [GVP](https://gitee.com/gvp) 全称是 Gitee 最有价值开源项目，是综合评定出的优秀开源项目
5.  Gitee 官方提供的使用手册 [https://gitee.com/help](https://gitee.com/help)
6.  Gitee 封面人物是一档用来展示 Gitee 会员风采的栏目 [https://gitee.com/gitee-stars/](https://gitee.com/gitee-stars/)
