# MPAY 界面素材

素材来源、变体处理与当前 SHA-256 见 sources.json。素材随 V1 交付，不在运行时读取 V2 或其他网站。

## MPAY 标识

- mpay-logo.svg：V2 标准矢量字标，用于登录、安装、收银台与错误页。
- mpay-logo-light.svg：深色背景版本，仅将 PAY 字色改浅，用于后台侧栏。
- mpay-mark.svg：V2 独立图形，用于收起的侧栏。
- mpay-logo.png、mpay-login.png、background.svg：原 V1 的 MPAY 品牌及背景素材；与支付方式图片分开维护。

## 支付方式图片

支付方式的小图标、完整标识及通用兜底统一使用透明 PNG。已移除不再引用的支付方式 SVG，以及旧 JPG/ICO 图片，避免维护重复格式。

- alipay-icon.png / alipay-logo.png：来自 V2 的支付宝图形和完整标识，保留品牌色与路径形状。
- wechat-pay-icon.png / wechat-pay-logo.png：来自 V2 的微信支付图形和完整标识，保留品牌色与路径形状。
- cloudpay-icon.png / cloudpay-logo.png：本项目绘制的红色云形闪电与“云闪付”文字，非平台官方商标图稿。
- bank-icon.png / bank-logo.png：本项目绘制的蓝色圆角银行卡及“银行卡”文字，非特定银行商标。
- payment-default.png：灰蓝通用收款符号，小图标和完整标识未配置时均使用这一张图片，不按编码猜测品牌。

小图标宽 192px；完整标识高 96px，按原比例生成，不把横向字标缩进方形图标格。后台、收银台和首页的支付方式图片均使用 PNG。

安装声明集中在 database/seeds/payment_types.php，首次初始化将品牌图片登记为普通本地图片资产并保存到支付方式配置。运行时只读取配置；管理员可上传替换，或选择“使用通用图标”清除对应引用。已有站点不自动运行种子回填。

品牌图形只用于标明产品或实际付款方式，不代表平台认证。二维码区域不叠加标志，通用菜单和操作继续使用本地 Layui 图标。原项目与第三方版权、许可证不因制作图片变体而改变。public/favicon.ico 继续作为站点图标。
