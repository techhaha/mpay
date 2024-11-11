<?php
function encrypt($string, $key) {
    $method = "AES-256-CBC";
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
    $encrypted = openssl_encrypt($string, $method, $key, 0, $iv);
    // 将iv和加密字符串拼接起来
    return base64_encode($iv . $encrypted);
}
 
$key = "your-encryption-key"; // 这里应该是一个安全的密钥
$string = "Hello, World!";
$encryptedString = encrypt($string, $key);
 
echo $encryptedString; // 输出加密字符串