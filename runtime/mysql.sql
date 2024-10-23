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
  `params` varchar(255) NOT NULL DEFAULT '' COMMENT '自定义查询',
  `delete_time` timestamp NULL DEFAULT NULL COMMENT '软删除',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mpay_pay_account`
--

LOCK TABLES `mpay_pay_account` WRITE;
/*!40000 ALTER TABLE `mpay_pay_account` DISABLE KEYS */;
INSERT INTO `mpay_pay_account` VALUES (1,1001,'sqbpay','18872410423','7698177hcnSQB',1,0,'{}',NULL);
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mpay_pay_channel`
--

LOCK TABLES `mpay_pay_channel` WRITE;
/*!40000 ALTER TABLE `mpay_pay_channel` DISABLE KEYS */;
INSERT INTO `mpay_pay_channel` VALUES (1,1,'24101820013292761382','https://qr.shouqianba.com/24101820013292761382','2024-10-19 02:23:37',1,NULL);
/*!40000 ALTER TABLE `mpay_pay_channel` ENABLE KEYS */;
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

-- Dump completed on 2024-10-23 17:17:20
