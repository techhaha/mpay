-- MySQL dump 10.13  Distrib 5.7.26, for Win64 (x86_64)
--
-- Host: localhost    Database: test
-- ------------------------------------------------------
-- Server version	5.7.26

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `mpay_order`
--

DROP TABLE IF EXISTS `mpay_order`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mpay_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '商户ID',
  `order_id` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '订单号',
  `type` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '支付类型',
  `out_trade_no` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '商户订单号',
  `notify_url` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '异步通知地址',
  `return_url` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '跳转通知地址',
  `name` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '商品名称',
  `really_price` float NOT NULL DEFAULT '0' COMMENT '实际支付金额',
  `money` float NOT NULL DEFAULT '0' COMMENT '订单价格',
  `clientip` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '用户IP地址',
  `device` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '设备类型',
  `param` varchar(720) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '扩展参数',
  `state` tinyint(4) NOT NULL DEFAULT '0' COMMENT '订单状态',
  `patt` tinyint(4) NOT NULL DEFAULT '0' COMMENT '开启回调监听',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '订单创建时间',
  `close_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '订单关闭时间',
  `pay_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '支付时间',
  `platform_order` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '收款平台订单号',
  `aid` int(11) NOT NULL DEFAULT '0' COMMENT '收款账号ID',
  `cid` int(11) NOT NULL DEFAULT '0' COMMENT '收款码ID',
  `delete_time` timestamp NULL DEFAULT NULL COMMENT '软删除',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=MyISAM DEFAULT CHARSET=utf8 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mpay_order`
--

LOCK TABLES `mpay_order` WRITE;
/*!40000 ALTER TABLE `mpay_order` DISABLE KEYS */;
/*!40000 ALTER TABLE `mpay_order` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mpay_pay_account`
--

DROP TABLE IF EXISTS `mpay_pay_account`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mpay_pay_account` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '收款平台ID',
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '用户ID',
  `platform` varchar(255) NOT NULL DEFAULT '' COMMENT '收款平台',
  `account` varchar(255) NOT NULL DEFAULT '' COMMENT '账号',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT '密码',
  `state` tinyint(4) NOT NULL DEFAULT '1' COMMENT '启用',
  `pattern` tinyint(4) NOT NULL DEFAULT '0' COMMENT '账号监听模式',
  `delete_time` timestamp NULL DEFAULT NULL COMMENT '软删除',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mpay_pay_account`
--

LOCK TABLES `mpay_pay_account` WRITE;
/*!40000 ALTER TABLE `mpay_pay_account` DISABLE KEYS */;
/*!40000 ALTER TABLE `mpay_pay_account` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mpay_pay_channel`
--

DROP TABLE IF EXISTS `mpay_pay_channel`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mpay_pay_channel` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '渠道ID',
  `account_id` int(11) NOT NULL DEFAULT '0' COMMENT '收款平台ID',
  `channel` varchar(255) NOT NULL DEFAULT '' COMMENT '收款通道',
  `qrcode` varchar(255) NOT NULL DEFAULT '' COMMENT '二维码',
  `last_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '最近使用',
  `state` tinyint(4) NOT NULL DEFAULT '1' COMMENT '启用',
  `delete_time` timestamp NULL DEFAULT NULL COMMENT '软删除',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mpay_pay_channel`
--

LOCK TABLES `mpay_pay_channel` WRITE;
/*!40000 ALTER TABLE `mpay_pay_channel` DISABLE KEYS */;
/*!40000 ALTER TABLE `mpay_pay_channel` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mpay_platform`
--

DROP TABLE IF EXISTS `mpay_platform`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mpay_platform` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '标记',
  `name` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '名称',
  `class_name` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '类名',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '价格',
  `describe` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '说明',
  `website` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '官网',
  `state` tinyint(4) NOT NULL DEFAULT '1' COMMENT '启用状态',
  `query` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT 'API查询',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `delete_time` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=MyISAM AUTO_INCREMENT=7 DEFAULT CHARSET=utf8 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mpay_platform`
--

LOCK TABLES `mpay_platform` WRITE;
/*!40000 ALTER TABLE `mpay_platform` DISABLE KEYS */;
INSERT INTO `mpay_platform` VALUES (1,'sqbpay','收钱吧','ShouQianBa',99.00,'主流移动支付全能收 信用卡,花呗都能用,生意帮手收钱吧,移动收款就用它!','https://www.shouqianba.com/',1,'a:8:{s:8:\"date_end\";N;s:10:\"date_start\";N;s:4:\"page\";i:1;s:9:\"page_size\";i:10;s:13:\"upayQueryType\";i:0;s:6:\"status\";s:4:\"2000\";s:8:\"store_sn\";s:0:\"\";s:4:\"type\";s:2:\"30\";}','2024-08-16 02:25:05',NULL),(2,'storepay','数字门店','ZhiHuiJingYing',99.00,'数字门店','https://store.zhihuijingyingba.com/',1,'a:7:{s:6:\"pageNo\";i:1;s:8:\"pageSize\";i:10;s:9:\"payClient\";i:4;s:6:\"status\";i:2;s:2:\"_t\";N;s:16:\"createTime_begin\";N;s:14:\"createTime_end\";N;}','2024-08-17 06:31:16',NULL),(3,'ysepay','小Y经营','Ysepay',99.00,'为商户和消费者提供安全、便捷、高效的支付产品与服务助力商户提升运营效率，实现数字化运营','https://xym.ysepay.com/',1,'a:10:{s:7:\"storeNo\";s:0:\"\";s:7:\"bizType\";i:3;s:7:\"payType\";s:0:\"\";s:11:\"orderStatus\";i:3;s:5:\"trmNo\";s:0:\"\";s:12:\"operatorUser\";s:0:\"\";s:13:\"codeBoardCode\";s:0:\"\";s:8:\"pageSize\";i:10;s:6:\"pageNo\";i:1;s:7:\"orderNo\";s:0:\"\";}','2024-08-17 06:31:19',NULL),(4,'mqpay','码钱','MaQian',99.00,'码钱商管平台','https://m.hkrt.cn/',1,'a:12:{s:12:\"terminalType\";s:0:\"\";s:7:\"payType\";s:0:\"\";s:7:\"payMode\";s:0:\"\";s:11:\"tradeStatus\";s:1:\"1\";s:7:\"tradeNo\";s:0:\"\";s:7:\"storeId\";s:0:\"\";s:4:\"page\";i:1;s:4:\"rows\";i:10;s:7:\"endDate\";N;s:7:\"endTime\";N;s:9:\"startDate\";N;s:9:\"startTime\";N;}','2024-08-17 06:32:29',NULL),(5,'lklpay','拉卡拉','LaKaLa',99.00,'数字支付，更安全，更高效','https://customer.lakala.com/',1,'','2024-10-17 08:17:47',NULL),(6,'sftpay','盛付通','ShengPay',99.00,'轻松生活 放心支付','https://b.shengpay.com/',1,'','2024-10-17 08:18:35',NULL);
/*!40000 ALTER TABLE `mpay_platform` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mpay_user`
--

DROP TABLE IF EXISTS `mpay_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mpay_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pid` int(11) NOT NULL DEFAULT '0' COMMENT '商户ID',
  `secret_key` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '商户秘钥',
  `nickname` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '用户昵称',
  `username` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '账号',
  `password` varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT '' COMMENT '密码',
  `state` tinyint(4) NOT NULL DEFAULT '1' COMMENT '启用状态 0:禁用 1:启用',
  `role` tinyint(4) NOT NULL DEFAULT '0' COMMENT '用户角色 0:普通用户 1:管理员',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `delete_time` timestamp NULL DEFAULT NULL COMMENT '软删除',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mpay_user`
--

LOCK TABLES `mpay_user` WRITE;
/*!40000 ALTER TABLE `mpay_user` DISABLE KEYS */;
INSERT INTO `mpay_user` VALUES (1,1001,'953c4d682d9ab148277b76a06e215ce7','技术老胡','admin','Aa12345678',1,1,'2024-08-02 07:42:41',NULL);
/*!40000 ALTER TABLE `mpay_user` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2024-10-19  9:31:50
