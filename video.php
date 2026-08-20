<?php
// Prepend this code to handle backend requests before HTML output
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    // cURL Helper function to fetch remote contents safely (bypasses allow_url_fopen limits)
    function curl_get_contents($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');
        
        if (!empty($_GET['proxy'])) {
            $px = $_GET['proxy'];
            if (strpos($px, 'socks5://') === 0) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                curl_setopt($ch, CURLOPT_PROXY, str_replace('socks5://', '', $px));
            } else {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
                curl_setopt($ch, CURLOPT_PROXY, $px);
            }
        }
        
        $content = curl_exec($ch);
        curl_close($ch);
        return $content;
    }
    
    // 1. EXTRACT / SCRAPE ACTION
    if ($action === 'extract' && isset($_GET['url'])) {
        header('Content-Type: application/json; charset=utf-8');
        $target_url = $_GET['url'];
        
        if (!filter_var($target_url, FILTER_VALIDATE_URL)) {
            echo json_encode(array('error' => 'Geçersiz URL adresi.'));
            exit;
        }

        $html = curl_get_contents($target_url);
        
        if (!$html) {
            echo json_encode(array('error' => 'Sayfa içeriği indirilemedi.'));
            exit;
        }

        $formats = array();
        
        // Find page title
        $title = 'Web Videosu';
        if (preg_match('/<title>(.*?)<\/title>/is', $html, $matches)) {
            $title = trim(strip_tags($matches[1]));
        }

        // 1. Find .mp4 / .webm / .m3u8 URLs in the HTML source using regex
        preg_match_all('/(?:href|src|file|source|url)["\']?\s*[=:]\s*["\']?([^"\'\s>#]+\.(?:mp4|webm|m3u8)(?:\?[^"\'\s>#]*)?)/i', $html, $matches_urls);
        if (!empty($matches_urls[1])) {
            foreach ($matches_urls[1] as $v_url) {
                $v_url = html_entity_decode($v_url, ENT_QUOTES, 'UTF-8');
                
                // Resolve relative URLs to absolute
                if (strpos($v_url, '//') === 0) {
                    $parsed = parse_url($target_url);
                    $v_url = ($parsed['scheme'] ?? 'http') . ':' . $v_url;
                } elseif (strpos($v_url, 'http') !== 0) {
                    $parsed = parse_url($target_url);
                    $base = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '');
                    if (strpos($v_url, '/') === 0) {
                        $v_url = $base . $v_url;
                    } else {
                        $path = dirname($parsed['path'] ?? '/');
                        $v_url = $base . ($path === '/' ? '' : $path) . '/' . $v_url;
                    }
                }
                
                $ext = strtolower(pathinfo(parse_url($v_url, PHP_URL_PATH), PATHINFO_EXTENSION));
                $label = 'Çözümlenen ' . strtoupper($ext) . ' Dosyası';
                
                $formats[] = array(
                    'label' => $label,
                    'url' => $v_url,
                    'quality' => strtoupper($ext)
                );
            }
        }

        // 2. Scan meta tags
        preg_match_all('/<meta\s+(?:property|name)=["\'](og:video(?::secure_url)?|og:video:url|twitter:player)["\']\s+content=["\']([^"\']+)["\']/i', $html, $meta_matches);
        if (!empty($meta_matches[2])) {
            foreach ($meta_matches[2] as $meta_url) {
                $meta_url = html_entity_decode($meta_url, ENT_QUOTES, 'UTF-8');
                if (filter_var($meta_url, FILTER_VALIDATE_URL)) {
                    $ext = strtolower(pathinfo(parse_url($meta_url, PHP_URL_PATH), PATHINFO_EXTENSION));
                    if (in_array($ext, array('mp4', 'webm', 'm3u8'))) {
                        $formats[] = array(
                            'label' => 'Meta Etiket Videosu (' . strtoupper($ext) . ')',
                            'url' => $meta_url,
                            'quality' => strtoupper($ext)
                        );
                    } else {
                        $formats[] = array(
                            'label' => 'Meta Oynatıcı Bağlantısı',
                            'url' => $meta_url,
                            'quality' => 'WEB'
                        );
                    }
                }
            }
        }

        // Remove duplicate URLs
        $unique_formats = array();
        $seen_urls = array();
        foreach ($formats as $fmt) {
            $normalized = strtok($fmt['url'], '?');
            if (!in_array($normalized, $seen_urls)) {
                $seen_urls[] = $normalized;
                $unique_formats[] = $fmt;
            }
        }

        echo json_encode(array(
            'title' => $title,
            'formats' => $unique_formats
        ));
        exit;
    }

    // 2. PROXY ACTION
    if ($action === 'proxy' && isset($_GET['url'])) {
        $target_url = $_GET['url'];
        
        if (!filter_var($target_url, FILTER_VALIDATE_URL)) {
            header("HTTP/1.1 400 Bad Request");
            echo "Geçersiz URL.";
            exit;
        }

        $ch = curl_init($target_url);
        if (!empty($_GET['proxy'])) {
            curl_setopt($ch, CURLOPT_PROXY, $_GET['proxy']);
        }
        
        $headers = array();
        if (isset($_SERVER['HTTP_RANGE'])) {
            $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
        }
        $headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36';
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header_line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header_line, $matches)) {
                http_response_code(intval($matches[1]));
            } elseif (preg_match('/^(Content-Type|Content-Range|Content-Length|Accept-Ranges):/i', $header_line)) {
                header($header_line);
            }
            return strlen($header_line);
        });

        // Disable output buffering to support real-time chunk streaming
        if (ob_get_level()) {
            ob_end_clean();
        }
        header('X-Accel-Buffering: no'); // Tell Nginx not to buffer the proxy response
        
        // Write each chunk immediately as it arrives and flush it
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) {
            echo $chunk;
            flush();
            return strlen($chunk);
        });

        curl_exec($ch);
        curl_close($ch);
        exit;
    }

    // 3. DOWNLOAD ACTION
    if ($action === 'download' && isset($_GET['url'])) {
        $target_url = $_GET['url'];
        $filename = isset($_GET['name']) ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $_GET['name']) : 'Video_Indir';
        
        if (!filter_var($target_url, FILTER_VALIDATE_URL)) {
            header("HTTP/1.1 400 Bad Request");
            echo "Geçersiz URL.";
            exit;
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '.mp4"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Accept-Ranges: none');

        $ch = curl_init($target_url);
        if (!empty($_GET['proxy'])) {
            curl_setopt($ch, CURLOPT_PROXY, $_GET['proxy']);
        }
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');
        
        curl_exec($ch);
        curl_close($ch);
        exit;
    }

    // 4. DOWNLOAD HLS ACTION
    if ($action === 'download_hls' && isset($_GET['url'])) {
        $target_url = $_GET['url'];
        $filename = isset($_GET['name']) ? preg_replace('/[^a-zA-Z0-9_-]/', '_', urldecode($_GET['name'])) : 'HLS_Video';
        
        if (!filter_var($target_url, FILTER_VALIDATE_URL)) {
            header("HTTP/1.1 400 Bad Request");
            echo "Geçersiz URL.";
            exit;
        }

        $m3u8_content = curl_get_contents($target_url);
        
        if (!$m3u8_content) {
            header("HTTP/1.1 404 Not Found");
            echo "M3U8 dosyası indirilemedi.";
            exit;
        }

        // Helper url resolver
        $resolve_url_fn = function($base, $rel) {
            if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;
            if ($rel[0] == '//') {
                $parsed = parse_url($base);
                return ($parsed['scheme'] ?? 'http') . ':' . $rel;
            }
            if ($rel[0] == '/') {
                $parsed = parse_url($base);
                return ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '') . (isset($parsed['port']) ? ':' . $parsed['port'] : '') . $rel;
            }
            $parsed = parse_url($base);
            $path = $parsed['path'] ?? '/';
            $dir = dirname($path);
            if ($dir === '\\' || $dir === '/') $dir = '';
            return ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '') . (isset($parsed['port']) ? ':' . $parsed['port'] : '') . $dir . '/' . $rel;
        };

        // Check if it's a Master Playlist
        $media_playlist_url = $target_url;
        if (strpos($m3u8_content, '#EXT-X-STREAM-INF') !== false) {
            $lines = explode("\n", $m3u8_content);
            $variants = array();
            $current_info = '';
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                if (strpos($line, '#EXT-X-STREAM-INF') === 0) {
                    $current_info = $line;
                } elseif ($line[0] !== '#') {
                    $bandwidth = 0;
                    if (preg_match('/BANDWIDTH=(\d+)/i', $current_info, $b_match)) {
                        $bandwidth = (int)$b_match[1];
                    }
                    $resolution = '0x0';
                    if (preg_match('/RESOLUTION=(\d+x\d+)/i', $current_info, $r_match)) {
                        $resolution = $r_match[1];
                    }
                    $variants[] = array(
                        'url' => $resolve_url_fn($target_url, $line),
                        'bandwidth' => $bandwidth,
                        'resolution' => $resolution
                    );
                }
            }
            
            if (!empty($variants)) {
                usort($variants, function($a, $b) {
                    return $b['bandwidth'] <=> $a['bandwidth'];
                });
                $media_playlist_url = $variants[0]['url'];
            }
        }

        // Fetch the Media Playlist content
        $media_playlist_content = curl_get_contents($media_playlist_url);
        if (!$media_playlist_content) {
            header("HTTP/1.1 404 Not Found");
            echo "Medya oynatma listesi indirilemedi.";
            exit;
        }

        // Check for EXT-X-MAP (Initialization Segment for fragmented MP4)
        $media_lines = explode("\n", $media_playlist_content);
        $init_segment_url = '';
        foreach ($media_lines as $line) {
            $line = trim($line);
            if (strpos($line, '#EXT-X-MAP:') === 0) {
                if (preg_match('/URI=["\']?([^"\']+)["\']?/i', $line, $map_matches)) {
                    $init_segment_url = $resolve_url_fn($media_playlist_url, $map_matches[1]);
                }
                break;
            }
        }

        // Extract all segment URLs
        $segment_urls = array();
        foreach ($media_lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') continue;
            $segment_urls[] = $resolve_url_fn($media_playlist_url, $line);
        }

        if (empty($segment_urls)) {
            header("HTTP/1.1 404 Not Found");
            echo "Oynatma listesinde video parçası bulunamadı.";
            exit;
        }

        // Stream the compiled segments to the browser on-the-fly
        header('Content-Description: File Transfer');
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="' . $filename . '.mp4"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        // Stream the initialization segment first (for fragmented MP4)
        if (!empty($init_segment_url)) {
            $ch = curl_init($init_segment_url);
            if (!empty($_GET['proxy'])) {
                curl_setopt($ch, CURLOPT_PROXY, $_GET['proxy']);
            }
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');
            curl_exec($ch);
            curl_close($ch);
            flush();
        }

        foreach ($segment_urls as $seg_url) {
            if (connection_aborted()) {
                break;
            }

            $ch = curl_init($seg_url);
            if (!empty($_GET['proxy'])) {
                curl_setopt($ch, CURLOPT_PROXY, $_GET['proxy']);
            }
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');
            
            curl_exec($ch);
            curl_close($ch);
            
            flush();
        }
        exit;
    }

    // 5. FETCH ACTIVE PROXIES ACTION
    if ($action === 'fetch_proxies') {
        header('Content-Type: application/json; charset=utf-8');
        
        $cache_file = sys_get_temp_dir() . '/streamgrab_proxies_v2.json';
        $proxies = array();
        
        if (!isset($_GET['refresh']) && file_exists($cache_file) && (time() - filemtime($cache_file) < 300)) {
            $proxies = json_decode(file_get_contents($cache_file), true);
        }
        
        if (empty($proxies)) {
            $raw_list = curl_get_contents('https://raw.githubusercontent.com/monosans/proxy-list/main/proxies.json');
            if ($raw_list) {
                $data = json_decode($raw_list, true);
                if (is_array($data)) {
                    $candidates = array();
                    $allowed_countries = array('RU', 'DE', 'NL', 'US', 'FR', 'UA');
                    
                    foreach ($data as $item) {
                        if (!isset($item['host']) || !isset($item['port']) || !isset($item['protocol'])) continue;
                        $country = isset($item['geolocation']['country']['iso_code']) ? $item['geolocation']['country']['iso_code'] : '';
                        if (!in_array($country, $allowed_countries)) continue;
                        
                        $proxy_str = $item['host'] . ':' . $item['port'];
                        $candidates[] = array(
                            'proxy' => ($item['protocol'] === 'socks5') ? 'socks5://' . $proxy_str : $proxy_str,
                            'country' => $country,
                            'protocol' => $item['protocol']
                        );
                    }
                    
                    if (!empty($candidates)) {
                        shuffle($candidates);
                        $tested = 0;
                        $working = array();
                        
                        foreach ($candidates as $c) {
                            $tested++;
                            if ($tested > 12 || count($working) >= 5) {
                                break;
                            }
                            
                            $ch = curl_init('https://www.google.com');
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_NOBODY, true);
                            curl_setopt($ch, CURLOPT_HEADER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            
                            if ($c['protocol'] === 'socks5') {
                                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                                curl_setopt($ch, CURLOPT_PROXY, str_replace('socks5://', '', $c['proxy']));
                            } else {
                                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
                                curl_setopt($ch, CURLOPT_PROXY, $c['proxy']);
                            }
                            
                            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 1200);
                            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 2000);
                            
                            curl_exec($ch);
                            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            
                            if ($code >= 200 && $code < 400) {
                                $working[] = $c;
                            }
                        }
                        
                        $proxies = $working;
                        
                        if (!empty($proxies)) {
                            @file_put_contents($cache_file, json_encode($proxies));
                        }
                    }
                }
            }
        }
        
        if (empty($proxies)) {
            $proxies = array(
                array('proxy' => '80.94.95.82:8080', 'country' => 'RU', 'protocol' => 'http'),
                array('proxy' => '185.162.229.178:80', 'country' => 'RU', 'protocol' => 'http'),
                array('proxy' => '45.139.123.10:8080', 'country' => 'RU', 'protocol' => 'http'),
                array('proxy' => 'socks5://87.247.158.4:1080', 'country' => 'RU', 'protocol' => 'socks5'),
                array('proxy' => '185.196.220.16:8080', 'country' => 'DE', 'protocol' => 'http'),
                array('proxy' => '195.3.142.158:8080', 'country' => 'NL', 'protocol' => 'http'),
                array('proxy' => '194.33.191.139:3128', 'country' => 'UA', 'protocol' => 'http'),
                array('proxy' => '188.166.126.177:8080', 'country' => 'US', 'protocol' => 'http')
            );
        }
        
        echo json_encode(array('success' => true, 'proxies' => array_slice($proxies, 0, 8)));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="tr" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StreamGrab Pro - Gelişmiş Evrensel Video İndirici</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Font Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- HLS.js Library for M3U8 Streaming Support -->
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            400: '#818cf8',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            900: '#312e81'
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #0f172a;
            color: #f8fafc;
        }
        .glass-panel {
            background: rgba(30, 41, 59, 0.75);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .glass-card {
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .gradient-text {
            background: linear-gradient(135deg, #818cf8 0%, #c084fc 50%, #f472b6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #0f172a;
        }
        ::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #475569;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col justify-between selection:bg-brand-500 selection:text-white">

    <!-- Header -->
    <header class="sticky top-0 z-40 w-full glass-panel border-b border-slate-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Logo -->
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-brand-600 to-pink-500 flex items-center justify-center shadow-lg shadow-brand-500/20">
                        <i class="fa-solid font-bold fa-download text-white text-lg"></i>
                    </div>
                    <div>
                        <span class="font-extrabold text-xl tracking-tight gradient-text">StreamGrab Pro</span>
                        <span class="hidden sm:inline-block text-xs bg-brand-500/20 text-brand-300 border border-brand-500/30 px-2 py-0.5 rounded-full ml-2">v2.8 Ultra</span>
                    </div>
                </div>

                <!-- Navigation Controls -->
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <button id="historyModalBtn" class="p-2.5 rounded-xl text-slate-300 hover:text-white hover:bg-slate-800 transition relative" title="İndirme Geçmişi">
                        <i class="fa-solid fa-clock-rotate-left text-lg"></i>
                        <span id="historyBadge" class="hidden absolute -top-1 -right-1 bg-brand-500 text-white text-[10px] font-bold w-4 h-4 rounded-full flex items-center justify-center">0</span>
                    </button>
                    
                    <button id="settingsBtn" class="p-2.5 rounded-xl text-slate-300 hover:text-white hover:bg-slate-800 transition" title="Ayarlar & API">
                        <i class="fa-solid fa-sliders text-lg"></i>
                    </button>

                    <a href="#faqSection" class="hidden md:flex items-center space-x-2 px-3 py-2 rounded-xl text-sm font-medium text-slate-300 hover:text-white hover:bg-slate-800 transition">
                        <i class="fa-regular fa-circle-question"></i>
                        <span>Yardım</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content Container -->
    <main class="flex-grow max-w-5xl w-full mx-auto px-4 sm:px-6 py-8 space-y-10">

        <!-- Hero Section -->
        <section class="text-center space-y-4 pt-4">
            <h1 class="text-3xl sm:text-5xl font-extrabold tracking-tight leading-tight">
                Herhangi Bir <span class="gradient-text">Video veya M3U8 Linkini</span> Anında İndirin
            </h1>
            <p class="text-slate-400 text-sm sm:text-base max-w-2xl mx-auto">
                YouTube, TikTok, Instagram, Twitter/X, M3U8 (HLS), MP4 veya WEBM bağlantılarını yapıştırın; önizleyin, oynatın ve doğrudan indirin.
            </p>
        </section>

        <!-- Main URL Input Box -->
        <section class="glass-panel p-4 sm:p-6 rounded-3xl shadow-2xl space-y-4 border border-slate-700/50">
            <div class="relative flex flex-col sm:flex-row items-center gap-3">
                <div class="relative w-full">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                        <i class="fa-solid fa-link text-lg"></i>
                    </div>
                    <input type="text" id="videoUrlInput" 
                        placeholder="Video URL, YouTube, TikTok veya .m3u8 bağlantısını yapıştırın..." 
                        class="w-full pl-11 pr-28 py-4 bg-slate-900/80 border border-slate-700 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 rounded-2xl text-slate-100 placeholder-slate-500 text-sm sm:text-base outline-none transition duration-200">
                    
                    <!-- Quick Action Buttons Inside Input -->
                    <div class="absolute inset-y-0 right-0 pr-2 flex items-center space-x-1">
                        <button id="clearInputBtn" class="hidden text-slate-400 hover:text-slate-200 p-2 rounded-lg hover:bg-slate-800 transition" title="Temizle">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                        <button id="pasteUrlBtn" class="bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs px-3 py-1.5 rounded-xl border border-slate-700 transition flex items-center space-x-1">
                            <i class="fa-regular fa-clipboard"></i>
                            <span class="hidden sm:inline">Yapıştır</span>
                        </button>
                    </div>
                </div>

                <button id="analyzeBtn" class="w-full sm:w-auto px-8 py-4 bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-500 hover:to-indigo-500 text-white font-semibold rounded-2xl shadow-lg shadow-brand-500/25 transition duration-200 flex items-center justify-center space-x-2 shrink-0">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    <span>Çözümle</span>
                </button>
            </div>

            <!-- Supported Platforms Badges -->
            <div class="flex flex-wrap items-center justify-center gap-2 pt-2 text-xs text-slate-400">
                <span class="text-slate-500">Desteklenenler:</span>
                <span class="px-2.5 py-1 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-300 flex items-center gap-1.5"><i class="fa-solid fa-stream"></i> M3U8 / HLS Stream</span>
                <span class="px-2.5 py-1 rounded-lg bg-slate-800/80 border border-slate-700/50 flex items-center gap-1.5"><i class="fa-brands fa-youtube text-red-500"></i> YouTube</span>
                <span class="px-2.5 py-1 rounded-lg bg-slate-800/80 border border-slate-700/50 flex items-center gap-1.5"><i class="fa-brands fa-tiktok text-cyan-400"></i> TikTok</span>
                <span class="px-2.5 py-1 rounded-lg bg-slate-800/80 border border-slate-700/50 flex items-center gap-1.5"><i class="fa-brands fa-instagram text-pink-500"></i> Instagram</span>
                <span class="px-2.5 py-1 rounded-lg bg-slate-800/80 border border-slate-700/50 flex items-center gap-1.5"><i class="fa-brands fa-x-twitter text-slate-300"></i> Twitter / X</span>
                <span class="px-2.5 py-1 rounded-lg bg-slate-800/80 border border-slate-700/50 flex items-center gap-1.5"><i class="fa-solid fa-file-video text-emerald-400"></i> MP4 / WEBM</span>
            </div>
        </section>

        <!-- Loading State Indicator -->
        <section id="loadingState" class="hidden glass-panel p-8 rounded-3xl text-center space-y-4">
            <div class="inline-block relative">
                <div class="w-16 h-16 border-4 border-brand-500/20 border-t-brand-500 rounded-full animate-spin"></div>
                <div class="absolute inset-0 flex items-center justify-center">
                    <i class="fa-solid fa-compact-disc text-brand-400 animate-pulse text-lg"></i>
                </div>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-slate-200" id="loadingTitle">Video Bilgileri Getiriliyor...</h3>
                <p class="text-sm text-slate-400 mt-1" id="loadingStatusText">Bağlantı taranıyor ve medya akışı analiz ediliyor...</p>
            </div>
        </section>

        <!-- Video Result Panel -->
        <section id="resultSection" class="hidden glass-panel p-6 rounded-3xl border border-slate-700/80 space-y-6">
            <div class="flex flex-col lg:flex-row gap-6">
                
                <!-- Left: Video Player / Thumbnail Preview -->
                <div class="w-full lg:w-1/2 space-y-3">
                    <div class="relative rounded-2xl overflow-hidden bg-black aspect-video flex items-center justify-center border border-slate-800 group shadow-lg">
                        <!-- Native HTML5 Video for Direct & HLS Streams -->
                        <video id="previewVideo" class="w-full h-full object-contain hidden" controls playsinline crossorigin="anonymous"></video>
                        
                        <!-- Embed iFrame Fallback for External Platforms -->
                        <iframe id="previewIframe" class="w-full h-full object-contain hidden" frameborder="0" allowfullscreen></iframe>

                        <!-- Image Thumbnail Fallback -->
                        <img id="previewImage" src="" alt="Video Önizleme" class="w-full h-full object-cover hidden">
                        
                        <!-- Video Placeholder Icon -->
                        <div id="previewPlaceholder" class="text-center text-slate-600 p-6">
                            <i class="fa-solid fa-film text-5xl mb-2"></i>
                            <p class="text-xs">Önizleme Yüklenemedi</p>
                        </div>

                        <!-- Platform Badge Overlay -->
                        <div class="absolute top-3 left-3 bg-slate-900/90 backdrop-blur-md border border-slate-700/60 px-3 py-1 rounded-full text-xs font-medium text-slate-200 flex items-center gap-1.5 shadow-md" id="platformBadge">
                            <i id="platformIcon" class="fa-solid fa-globe text-brand-400"></i>
                            <span id="platformName">Web Video</span>
                        </div>
                    </div>

                    <!-- Video Tools bar -->
                    <div class="flex items-center justify-between text-xs text-slate-400 px-1 pt-1">
                        <button id="captureFrameBtn" class="flex items-center space-x-1.5 text-slate-300 hover:text-brand-400 transition" title="Şu anki kareyi resim olarak kaydet">
                            <i class="fa-solid fa-camera"></i>
                            <span>Ekran Görüntüsü Al</span>
                        </button>
                        <span id="videoDuration" class="font-mono text-slate-400">00:00</span>
                    </div>
                </div>

                <!-- Right: Video Info & Download Options -->
                <div class="w-full lg:w-1/2 flex flex-col justify-between space-y-4">
                    <div class="space-y-2">
                        <h2 id="videoTitle" class="text-xl font-bold text-slate-100 line-clamp-2 leading-snug">
                            Video Başlığı Yükleniyor...
                        </h2>
                        <p id="videoSourceUrl" class="text-xs text-slate-400 truncate max-w-md font-mono bg-slate-900/60 p-1.5 rounded-lg border border-slate-800">
                            https://...
                        </p>
                    </div>

                    <!-- Format Selection Options -->
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-semibold uppercase tracking-wider text-slate-400 block">
                                İndirme Formatı / Kalite Seçenekleri
                            </label>
                            <span id="streamTypeTag" class="text-[10px] px-2 py-0.5 rounded bg-brand-500/20 text-brand-300 border border-brand-500/30">Auto</span>
                        </div>
                        
                        <div id="formatOptionsContainer" class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                            <!-- Options injected dynamically by JS -->
                        </div>
                    </div>

                    <!-- Progress Bar for Direct Downloads -->
                    <div id="downloadProgressContainer" class="hidden space-y-1.5 bg-slate-900/80 p-3 rounded-2xl border border-brand-500/30">
                        <div class="flex justify-between text-xs font-medium">
                            <span class="text-slate-300" id="progressStatusText">İndiriliyor...</span>
                            <span class="text-brand-400 font-mono" id="progressPercent">0%</span>
                        </div>
                        <div class="w-full bg-slate-800 rounded-full h-2 overflow-hidden">
                            <div id="progressBar" class="bg-gradient-to-r from-brand-500 to-emerald-400 h-2 rounded-full transition-all duration-150" style="width: 0%"></div>
                        </div>
                    </div>

                    <!-- M3U8 Special FFmpeg Box (Displayed when M3U8 detected) -->
                    <div id="ffmpegBox" class="hidden bg-slate-900/90 border border-amber-500/30 rounded-2xl p-3.5 space-y-2">
                        <div class="flex items-center justify-between text-xs text-amber-400 font-semibold">
                            <span class="flex items-center gap-1.5"><i class="fa-solid fa-terminal"></i> Önerilen FFmpeg İndirme Komutu</span>
                            <button id="copyFfmpegBtn" class="text-[11px] bg-amber-500/20 hover:bg-amber-500/30 border border-amber-500/40 px-2.5 py-1 rounded-lg text-amber-300 transition flex items-center gap-1">
                                <i class="fa-regular fa-copy"></i> Komutu Kopyala
                            </button>
                        </div>
                        <code id="ffmpegCommandText" class="block text-[11px] font-mono text-slate-300 bg-black/60 p-2.5 rounded-xl break-all select-all">
                            ffmpeg -i "..." -c copy video.mp4
                        </code>
                        <p class="text-[10px] text-slate-400 leading-tight">
                            * .m3u8 akışlarını kayıpsız ve yüksek hızda MP4'e dönüştürmek için terminalinizde bu komutu çalıştırabilirsiniz.
                        </p>
                    </div>

                    <!-- Primary Action Buttons -->
                    <div class="pt-2 flex flex-col sm:flex-row gap-3">
                        <button id="downloadMainBtn" class="flex-1 py-3.5 px-6 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-2xl shadow-lg shadow-emerald-600/20 transition flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-download"></i>
                            <span id="downloadBtnText">Videoyu İndir</span>
                        </button>

                        <button id="copyDirectLinkBtn" class="px-4 py-3.5 bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 font-semibold rounded-2xl transition flex items-center justify-center space-x-2" title="Bağlantıyı Kopyala">
                            <i class="fa-regular fa-copy"></i>
                            <span class="sm:hidden">Bağlantıyı Kopyala</span>
                        </button>
                    </div>

                </div>
            </div>
        </section>

        <!-- Secondary Tools Tabs Section -->
        <section class="glass-panel rounded-3xl p-6 border border-slate-800 space-y-6">
            <!-- Tab Buttons -->
            <div class="flex border-b border-slate-800 pb-3 gap-6 overflow-x-auto">
                <button data-tab="tabM3u8Guide" class="tab-btn active text-brand-400 border-b-2 border-brand-500 pb-2 font-semibold text-sm flex items-center space-x-2 whitespace-nowrap">
                    <i class="fa-solid fa-file-code"></i>
                    <span>M3U8 İndirme Rehberi</span>
                </button>
                <button data-tab="tabBatch" class="tab-btn text-slate-400 hover:text-slate-200 pb-2 font-semibold text-sm flex items-center space-x-2 whitespace-nowrap">
                    <i class="fa-solid fa-layer-group"></i>
                    <span>Toplu Bağlantı İndirici</span>
                </button>
                <button data-tab="tabHistory" class="tab-btn text-slate-400 hover:text-slate-200 pb-2 font-semibold text-sm flex items-center space-x-2 whitespace-nowrap">
                    <i class="fa-solid fa-history"></i>
                    <span>Son İndirilenler</span>
                </button>
            </div>

            <!-- Tab Content 1: M3U8 Guide -->
            <div id="tabM3u8Guide" class="tab-content space-y-4">
                <p class="text-xs text-slate-300">`.m3u8` uzantılı HLS akış bağlantılarını indirmek için etkili yöntemler:</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                    <div class="p-4 bg-slate-900/80 rounded-2xl border border-slate-800 space-y-2">
                        <div class="font-bold text-amber-400 flex items-center gap-2">
                            <i class="fa-solid fa-terminal"></i> 1. FFmpeg Komutu
                        </div>
                        <p class="text-slate-400 text-[11px] leading-relaxed">
                            Terminal/Komut Satırına çözümleme ekranından aldığınız komutu yapıştırın:
                        </p>
                        <code class="block bg-black/60 p-2 rounded-lg font-mono text-[10px] text-emerald-400 select-all break-all">
                            ffmpeg -i "https://.../index.m3u8" -c copy video.mp4
                        </code>
                    </div>

                    <div class="p-4 bg-slate-900/80 rounded-2xl border border-slate-800 space-y-2">
                        <div class="font-bold text-cyan-400 flex items-center gap-2">
                            <i class="fa-solid fa-puzzle-piece"></i> 2. Tarayıcı Eklentileri
                        </div>
                        <p class="text-slate-400 text-[11px] leading-relaxed">
                            Chrome/Firefox için <strong>FetchV</strong>, <strong>Live Stream Downloader</strong> veya <strong>HLS Downloader</strong> eklentilerini kullanabilirsiniz.
                        </p>
                    </div>

                    <div class="p-4 bg-slate-900/80 rounded-2xl border border-slate-800 space-y-2">
                        <div class="font-bold text-orange-400 flex items-center gap-2">
                            <i class="fa-solid fa-play-circle"></i> 3. VLC Media Player
                        </div>
                        <p class="text-slate-400 text-[11px] leading-relaxed">
                            VLC'de <em>Ortam -&gt; Ağ Akışı Aç (Ctrl+N)</em> adımlarını izleyin. M3U8 linkini yapıştırıp "Dönüştür/Kaydet" seçeneğini kullanın.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Tab Content 2: Batch Downloader -->
            <div id="tabBatch" class="tab-content hidden space-y-4">
                <p class="text-xs text-slate-400">Birden fazla video veya M3U8 URL'sini her satıra bir tane gelecek şekilde yapıştırın.</p>
                <textarea id="batchUrlsInput" rows="4" placeholder="https://site.com/video1.m3u8&#10;https://site.com/video2.mp4" class="w-full p-3.5 bg-slate-900 border border-slate-800 rounded-xl text-slate-200 text-xs font-mono focus:border-brand-500 outline-none"></textarea>
                <div class="flex justify-end">
                    <button id="processBatchBtn" class="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-white font-medium text-xs rounded-xl transition flex items-center space-x-2">
                        <i class="fa-solid fa-play"></i>
                        <span>Toplu İşlemi Başlat</span>
                    </button>
                </div>
                <div id="batchResults" class="hidden space-y-2 pt-2"></div>
            </div>

            <!-- Tab Content 3: History -->
            <div id="tabHistory" class="tab-content hidden space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-slate-400">Cihazınızda saklanan son indirme geçmişiniz.</span>
                    <button id="clearHistoryBtn" class="text-xs text-red-400 hover:text-red-300 transition flex items-center gap-1">
                        <i class="fa-regular fa-trash-can"></i> Temizle
                    </button>
                </div>
                <div id="historyListContainer" class="space-y-2 max-h-60 overflow-y-auto pr-1">
                    <p class="text-xs text-slate-500 italic text-center py-4">Henüz kayıtlı indirme geçmişi yok.</p>
                </div>
            </div>
        </section>

        <!-- How To & FAQ Accordion -->
        <section id="faqSection" class="space-y-4">
            <div class="text-center space-y-1">
                <h3 class="text-xl font-bold text-slate-200">Sıkça Sorulan Sorular & Rehber</h3>
                <p class="text-xs text-slate-400">Video indirme ve akış kaynakları hakkında merak edilenler</p>
            </div>

            <div class="space-y-3 max-w-3xl mx-auto">
                <details class="glass-card rounded-2xl p-4 cursor-pointer group">
                    <summary class="font-semibold text-sm text-slate-200 flex justify-between items-center list-none">
                        <span><i class="fa-regular fa-circle-question text-brand-400 mr-2"></i> .m3u8 dosyası nedir, neden doğrudan inmiyor?</span>
                        <i class="fa-solid fa-chevron-down text-slate-400 group-open:rotate-180 transition"></i>
                    </summary>
                    <p class="text-xs text-slate-400 mt-3 leading-relaxed border-t border-slate-800/80 pt-3">
                        .m3u8 tek bir video dosyası değil, video parçalarının (TS veya AAC segmentlerinin) adreslerini tutan oynatma listesi metnidir. Doğrudan tıklandığında sadece birkaç kilobaytlık bir metin inebilir. Sistemimiz HLS desteği ile canlı önizleme sunar ve bilgisayarınızda tek parça MP4'e dönüştürmeniz için FFmpeg komutu üretir.
                    </p>
                </details>

                <details class="glass-card rounded-2xl p-4 cursor-pointer group">
                    <summary class="font-semibold text-sm text-slate-200 flex justify-between items-center list-none">
                        <span><i class="fa-solid fa-shield-halved text-emerald-400 mr-2"></i> İndirme işlemi güvenli mi?</span>
                        <i class="fa-solid fa-chevron-down text-slate-400 group-open:rotate-180 transition"></i>
                    </summary>
                    <p class="text-xs text-slate-400 mt-3 leading-relaxed border-t border-slate-800/80 pt-3">
                        Evet, çözümler doğrudan istemci tarafında veya güvenli proxy katmanlarında gerçekleşir. Hiçbir kişisel veri veya video dosyası sunucularımızda saklanmaz.
                    </p>
                </details>

                <details class="glass-card rounded-2xl p-4 cursor-pointer group">
                    <summary class="font-semibold text-sm text-slate-200 flex justify-between items-center list-none">
                        <span><i class="fa-solid fa-circle-exclamation text-amber-400 mr-2"></i> İndirme başarısız olursa ne yapmalıyım?</span>
                        <i class="fa-solid fa-chevron-down text-slate-400 group-open:rotate-180 transition"></i>
                    </summary>
                    <p class="text-xs text-slate-400 mt-3 leading-relaxed border-t border-slate-800/80 pt-3">
                        Sağ üst köşedeki Ayarlar simgesine tıklayarak alternatif Cobalt API sunucusunu seçebilir veya CORS Proxy ayarını aktif edebilirsiniz. Ayrıca M3U8 bağlantılarında yer verilen FFmpeg komutunu kopyalayarak komut satırından sorunsuz indirebilirsiniz.
                    </p>
                </details>
            </div>
        </section>

    </main>

    <!-- Settings Modal -->
    <div id="settingsModal" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-md flex items-center justify-center p-4 hidden">
        <div class="glass-panel max-w-md w-full rounded-3xl p-6 border border-slate-700 space-y-5 relative shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="font-bold text-lg text-slate-100 flex items-center gap-2">
                    <i class="fa-solid fa-sliders text-brand-400"></i>
                    API ve Uygulama Ayarları
                </h3>
                <button id="closeSettingsBtn" class="text-slate-400 hover:text-slate-200 p-1">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <div class="space-y-4 text-xs">
                <div>
                    <label class="block font-medium text-slate-300 mb-1">Çözümleyici API Sunucusu (Cobalt / Custom API)</label>
                    <input type="text" id="apiEndpointInput" value="https://api.cobalt.tools" class="w-full p-3 bg-slate-900 border border-slate-800 rounded-xl text-slate-200 font-mono focus:border-brand-500 outline-none">
                    <p class="text-[10px] text-slate-500 mt-1">Örn: https://api.cobalt.tools veya diğer aktif Cobalt instance'ları</p>
                </div>

                <div>
                    <label class="block font-medium text-slate-300 mb-1">CORS Proxy Sunucusu</label>
                    <input type="text" id="corsProxyInput" value="https://corsproxy.io/?" class="w-full p-3 bg-slate-900 border border-slate-800 rounded-xl text-slate-200 font-mono focus:border-brand-500 outline-none">
                </div>

                <div>
                    <label class="block font-medium text-slate-300 mb-1">Sunucu Proxy / VPN Tüneli (IP:Port)</label>
                    <div class="flex gap-2">
                        <input type="text" id="serverProxyInput" value="" placeholder="Örn: 185.123.45.67:8080 veya socks5://185.123.45.67:1080" class="w-full p-3 bg-slate-900 border border-slate-800 rounded-xl text-slate-200 font-mono focus:border-brand-500 outline-none">
                        <button id="refreshProxiesBtn" type="button" title="Aktif ücretsiz proxy sunucuları bul" class="px-3 bg-slate-800 hover:bg-slate-700 text-brand-400 rounded-xl border border-slate-700 transition">
                            <i class="fa-solid fa-arrows-rotate"></i>
                        </button>
                    </div>
                    <p class="text-[10px] text-slate-500 mt-1">Sunucunun video sitelerini engelleri aşarak (VPN gibi) taraması için kullanılır.</p>
                    
                    <!-- Quick Proxy Select List -->
                    <div id="proxySelectorContainer" class="hidden mt-2 p-2.5 bg-slate-950 border border-slate-800 rounded-xl space-y-1">
                        <label class="block font-semibold text-[10px] text-slate-400 uppercase tracking-wider mb-1">Aktif Sunucular (Test Edilmiş)</label>
                        <select id="quickProxySelect" class="w-full p-2 bg-slate-900 border border-slate-800 rounded-lg text-slate-300 text-[11px] outline-none cursor-pointer">
                            <option value="">-- Bir Sunucu Seçin --</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center justify-between py-2 border-t border-slate-800">
                    <div>
                        <span class="font-medium text-slate-200 block">Geçmişe Otomatik Kaydet</span>
                        <span class="text-slate-500">İndirilen videoları yerel hafızada sakla</span>
                    </div>
                    <input type="checkbox" id="autoSaveHistoryCheck" checked class="w-4 h-4 accent-brand-500 rounded cursor-pointer">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button id="saveSettingsBtn" class="w-full py-2.5 bg-brand-600 hover:bg-brand-500 text-white font-semibold rounded-xl text-xs transition shadow-lg shadow-brand-500/20">
                    Ayarları Kaydet
                </button>
            </div>
        </div>
    </div>

    <!-- Toast Notification Container -->
    <div id="toastContainer" class="fixed bottom-5 right-5 z-50 flex flex-col space-y-2 pointer-events-none"></div>

    <!-- Footer -->
    <footer class="border-t border-slate-800/80 glass-panel py-6 mt-12">
        <div class="max-w-7xl mx-auto px-4 text-center text-xs text-slate-500 space-y-2">
            <p>© 2026 StreamGrab Pro - Evrensel Video & Akış İndirici</p>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            
            // DOM Elements
            const videoUrlInput = document.getElementById('videoUrlInput');
            const clearInputBtn = document.getElementById('clearInputBtn');
            const pasteUrlBtn = document.getElementById('pasteUrlBtn');
            const analyzeBtn = document.getElementById('analyzeBtn');
            
            const loadingState = document.getElementById('loadingState');
            const loadingTitle = document.getElementById('loadingTitle');
            const loadingStatusText = document.getElementById('loadingStatusText');
            const resultSection = document.getElementById('resultSection');
            
            const previewVideo = document.getElementById('previewVideo');
            const previewIframe = document.getElementById('previewIframe');
            const previewImage = document.getElementById('previewImage');
            const previewPlaceholder = document.getElementById('previewPlaceholder');
            const platformBadge = document.getElementById('platformBadge');
            const platformIcon = document.getElementById('platformIcon');
            const platformName = document.getElementById('platformName');
            const captureFrameBtn = document.getElementById('captureFrameBtn');
            const videoDuration = document.getElementById('videoDuration');
            
            const videoTitle = document.getElementById('videoTitle');
            const videoSourceUrl = document.getElementById('videoSourceUrl');
            const formatOptionsContainer = document.getElementById('formatOptionsContainer');
            const downloadMainBtn = document.getElementById('downloadMainBtn');
            const downloadBtnText = document.getElementById('downloadBtnText');
            const copyDirectLinkBtn = document.getElementById('copyDirectLinkBtn');
            const streamTypeTag = document.getElementById('streamTypeTag');

            const downloadProgressContainer = document.getElementById('downloadProgressContainer');
            const progressStatusText = document.getElementById('progressStatusText');
            const progressPercent = document.getElementById('progressPercent');
            const progressBar = document.getElementById('progressBar');

            // M3U8 FFmpeg UI Elements
            const ffmpegBox = document.getElementById('ffmpegBox');
            const ffmpegCommandText = document.getElementById('ffmpegCommandText');
            const copyFfmpegBtn = document.getElementById('copyFfmpegBtn');
            
            // Settings Elements
            const settingsBtn = document.getElementById('settingsBtn');
            const settingsModal = document.getElementById('settingsModal');
            const closeSettingsBtn = document.getElementById('closeSettingsBtn');
            const saveSettingsBtn = document.getElementById('saveSettingsBtn');
            const apiEndpointInput = document.getElementById('apiEndpointInput');
            const corsProxyInput = document.getElementById('corsProxyInput');
            const serverProxyInput = document.getElementById('serverProxyInput');
            const autoSaveHistoryCheck = document.getElementById('autoSaveHistoryCheck');

            // Tabs & History Elements
            const historyModalBtn = document.getElementById('historyModalBtn');
            const historyBadge = document.getElementById('historyBadge');
            const historyListContainer = document.getElementById('historyListContainer');
            const clearHistoryBtn = document.getElementById('clearHistoryBtn');
            const batchUrlsInput = document.getElementById('batchUrlsInput');
            const processBatchBtn = document.getElementById('processBatchBtn');
            const batchResults = document.getElementById('batchResults');

            // App State
            let currentVideoData = null;
            let selectedFormat = null;
            let hlsPlayerInstance = null;
            let downloadHistory = JSON.parse(localStorage.getItem('sg_download_history') || '[]');
            let appSettings = JSON.parse(localStorage.getItem('sg_app_settings') || JSON.stringify({
                apiEndpoint: 'https://api.cobalt.tools',
                corsProxy: 'https://corsproxy.io/?',
                serverProxy: '',
                autoSaveHistory: true
            }));

            // Sync settings to inputs
            apiEndpointInput.value = appSettings.apiEndpoint || 'https://api.cobalt.tools';
            corsProxyInput.value = appSettings.corsProxy || 'https://corsproxy.io/?';
            serverProxyInput.value = appSettings.serverProxy || '';
            autoSaveHistoryCheck.checked = appSettings.autoSaveHistory;

            updateHistoryBadge();
            renderHistoryList();

            // Toast Notification Helper
            function showToast(message, type = 'info') {
                const toast = document.createElement('div');
                const bgClass = type === 'success' ? 'bg-emerald-600' : type === 'error' ? 'bg-red-600' : 'bg-slate-800';
                const iconClass = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-circle-exclamation' : 'fa-info-circle';

                toast.className = `${bgClass} text-white text-xs px-4 py-3 rounded-2xl shadow-xl flex items-center space-x-2 border border-white/10 transform transition-all duration-300 pointer-events-auto opacity-0 translate-y-2`;
                toast.innerHTML = `<i class="fa-solid ${iconClass}"></i> <span>${message}</span>`;

                const container = document.getElementById('toastContainer');
                container.appendChild(toast);

                setTimeout(() => toast.classList.remove('opacity-0', 'translate-y-2'), 10);
                setTimeout(() => {
                    toast.classList.add('opacity-0', 'translate-y-2');
                    setTimeout(() => toast.remove(), 300);
                }, 3500);
            }

            // Input Clear / Paste Handlers
            videoUrlInput.addEventListener('input', () => {
                clearInputBtn.classList.toggle('hidden', videoUrlInput.value.trim().length === 0);
            });

            clearInputBtn.addEventListener('click', () => {
                videoUrlInput.value = '';
                clearInputBtn.classList.add('hidden');
                videoUrlInput.focus();
            });

            pasteUrlBtn.addEventListener('click', async () => {
                try {
                    const text = await navigator.clipboard.readText();
                    if (text) {
                        videoUrlInput.value = text.trim();
                        clearInputBtn.classList.remove('hidden');
                        showToast('Panodan bağlantı yapıştırıldı', 'success');
                    }
                } catch (err) {
                    showToast('Panodan okuma izni alınamadı. Lütfen manuel yapıştırın.', 'error');
                }
            });

            // Settings Modal Handlers
            const refreshProxiesBtn = document.getElementById('refreshProxiesBtn');
            const proxySelectorContainer = document.getElementById('proxySelectorContainer');
            const quickProxySelect = document.getElementById('quickProxySelect');

            refreshProxiesBtn.addEventListener('click', async () => {
                refreshProxiesBtn.disabled = true;
                const icon = refreshProxiesBtn.querySelector('i');
                icon.className = 'fa-solid fa-spinner fa-spin';
                
                try {
                    const response = await fetch('video.php?action=fetch_proxies&refresh=true');
                    const data = await response.json();
                    
                    if (data && data.success && data.proxies && data.proxies.length > 0) {
                        quickProxySelect.innerHTML = '<option value="">-- Bir Sunucu Seçin --</option>';
                        
                        data.proxies.forEach((item, idx) => {
                            const opt = document.createElement('option');
                            opt.value = item.proxy;
                            const flag = item.country === 'RU' ? '🇷🇺' : item.country === 'DE' ? '🇩🇪' : item.country === 'NL' ? '🇳🇱' : item.country === 'US' ? '🇺🇸' : item.country === 'FR' ? '🇫🇷' : item.country === 'UA' ? '🇺🇦' : '🌐';
                            opt.textContent = `${flag} [${item.country}] ${item.protocol.toUpperCase()} - ${item.proxy}`;
                            quickProxySelect.appendChild(opt);
                        });
                        
                        proxySelectorContainer.classList.remove('hidden');
                        showToast('Çalışan güncel ücretsiz sunucular listelendi!', 'success');
                    } else {
                        showToast('Sunucu listesi alınamadı.', 'error');
                    }
                } catch (err) {
                    console.error('Proxy listesi çekme hatası:', err);
                    showToast('Sunucu listesi güncellenirken hata oluştu.', 'error');
                } finally {
                    refreshProxiesBtn.disabled = false;
                    icon.className = 'fa-solid fa-arrows-rotate';
                }
            });

            quickProxySelect.addEventListener('change', () => {
                if (quickProxySelect.value) {
                    serverProxyInput.value = quickProxySelect.value;
                }
            });

            settingsBtn.addEventListener('click', () => settingsModal.classList.remove('hidden'));
            closeSettingsBtn.addEventListener('click', () => settingsModal.classList.add('hidden'));
            saveSettingsBtn.addEventListener('click', () => {
                appSettings.apiEndpoint = apiEndpointInput.value.trim().replace(/\/$/, "");
                appSettings.corsProxy = corsProxyInput.value.trim();
                appSettings.serverProxy = serverProxyInput.value.trim();
                appSettings.autoSaveHistory = autoSaveHistoryCheck.checked;
                localStorage.setItem('sg_app_settings', JSON.stringify(appSettings));
                settingsModal.classList.add('hidden');
                showToast('Ayarlar kaydedildi', 'success');
            });

            // Tabs Switcher
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.tab-btn').forEach(b => {
                        b.classList.remove('active', 'text-brand-400', 'border-b-2', 'border-brand-500');
                        b.classList.add('text-slate-400');
                    });
                    document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));

                    btn.classList.add('active', 'text-brand-400', 'border-b-2', 'border-brand-500');
                    btn.classList.remove('text-slate-400');
                    const targetTab = document.getElementById(btn.dataset.tab);
                    if (targetTab) targetTab.classList.remove('hidden');
                });
            });

            // Detect Platform from URL
            function detectPlatform(url) {
                if (/\.m3u8(\?.*)?$/i.test(url) || url.includes('/media=hls') || url.includes('.m3u8')) {
                    return { name: 'HLS Akışı (.m3u8)', icon: 'fa-solid fa-stream text-amber-400', type: 'hls' };
                }
                if (/\.(mp4|webm|mkv|mov|avi)(\?.*)?$/i.test(url)) {
                    return { name: 'Doğrudan Video (MP4/WEBM)', icon: 'fa-solid fa-file-video text-emerald-400', type: 'direct_video' };
                }
                if (url.includes('youtube.com') || url.includes('youtu.be')) {
                    return { name: 'YouTube', icon: 'fa-brands fa-youtube text-red-500', type: 'youtube' };
                }
                if (url.includes('tiktok.com')) {
                    return { name: 'TikTok', icon: 'fa-brands fa-tiktok text-cyan-400', type: 'tiktok' };
                }
                if (url.includes('instagram.com')) {
                    return { name: 'Instagram', icon: 'fa-brands fa-instagram text-pink-500', type: 'instagram' };
                }
                if (url.includes('twitter.com') || url.includes('x.com')) {
                    return { name: 'Twitter / X', icon: 'fa-brands fa-x-twitter text-slate-300', type: 'twitter' };
                }
                if (url.includes('vimeo.com')) {
                    return { name: 'Vimeo', icon: 'fa-brands fa-vimeo text-blue-400', type: 'vimeo' };
                }
                if (url.includes('vk.com') || url.includes('vkvideo.ru')) {
                    return { name: 'VK Video', icon: 'fa-brands fa-vk text-blue-500', type: 'vk' };
                }
                return { name: 'Web Medya Akışı', icon: 'fa-solid fa-globe text-brand-400', type: 'generic' };
            }

            // Main Analyze Action
            analyzeBtn.addEventListener('click', () => {
                const url = videoUrlInput.value.trim();
                if (!url) {
                    showToast('Lütfen geçerli bir video veya .m3u8 URL adresi girin', 'error');
                    return;
                }
                processVideoUrl(url);
            });

            videoUrlInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    analyzeBtn.click();
                }
            });

            // Helper function to build backend URL with server proxy configuration
            function getBackendUrl(action, extraParams = {}) {
                let url = `video.php?action=${action}`;
                if (appSettings.serverProxy) {
                    url += `&proxy=${encodeURIComponent(appSettings.serverProxy)}`;
                }
                for (const [key, value] of Object.entries(extraParams)) {
                    url += `&${key}=${encodeURIComponent(value)}`;
                }
                return url;
            }

            // Master Process Video Function
            async function processVideoUrl(url) {
                // Reset UI
                resetPlayerState();
                loadingState.classList.remove('hidden');
                resultSection.classList.add('hidden');
                ffmpegBox.classList.add('hidden');
                downloadProgressContainer.classList.add('hidden');

                const platform = detectPlatform(url);
                platformName.textContent = platform.name;
                platformIcon.className = platform.icon;
                videoSourceUrl.textContent = url;

                loadingTitle.textContent = `${platform.name} Analiz Ediliyor...`;
                loadingStatusText.textContent = 'Medya bağlantısı ve akış seçenekleri getiriliyor...';

                try {
                    if (platform.type === 'hls') {
                        await handleHlsStream(url);
                    } else if (platform.type === 'direct_video') {
                        await handleDirectVideo(url);
                    } else {
                        await handleSocialPlatform(url, platform);
                    }
                } catch (err) {
                    console.error('Anlama hatası:', err);
                    // Fallback to direct player if possible
                    tryDirectFallback(url, platform);
                } finally {
                    loadingState.classList.add('hidden');
                }
            }

            // Handler for HLS (.m3u8) Streams
            async function handleHlsStream(url) {
                streamTypeTag.textContent = 'HLS / M3U8';
                videoTitle.textContent = 'M3U8 Canlı Akış Videosu';
                
                // Show Video Element
                previewVideo.classList.remove('hidden');
                previewPlaceholder.classList.add('hidden');

                // Prepare FFmpeg Command Box
                ffmpegBox.classList.remove('hidden');
                ffmpegCommandText.textContent = `ffmpeg -i "${url}" -c copy -bsf:a aac_adtstoasc output_video.mp4`;

                let availableQualities = [];

                if (Hls.isSupported()) {
                    if (hlsPlayerInstance) {
                        hlsPlayerInstance.destroy();
                    }
                    hlsPlayerInstance = new Hls({
                        enableWorker: true,
                        lowLatencyMode: true
                    });

                    hlsPlayerInstance.loadSource(url);
                    hlsPlayerInstance.attachMedia(previewVideo);
                    hlsPlayerInstance.on(Hls.Events.MANIFEST_PARSED, (event, data) => {
                        if (data.levels && data.levels.length > 0) {
                            availableQualities = data.levels.map((level, index) => ({
                                id: index,
                                label: `${level.height || 'Bilinmeyen'}p (${Math.round((level.bitrate || 0)/1000)} kbps)`,
                                height: level.height,
                                url: url
                            }));
                        }
                        renderFormatOptions(availableQualities.length > 0 ? availableQualities : [{ label: 'Orijinal HLS Akışı', url: url }]);
                        forceRenderFirstFrame();
                    });

                    hlsPlayerInstance.on(Hls.Events.ERROR, (event, data) => {
                        if (data.fatal) {
                            console.warn('HLS Fatal Error, proxied retry...');
                            loadHlsWithProxy(url);
                        }
                    });
                } else if (previewVideo.canPlayType('application/vnd.apple.mpegurl')) {
                    // Safari native HLS
                    previewVideo.src = url;
                    renderFormatOptions([{ label: 'Safari Native HLS Stream', url: url }]);
                    forceRenderFirstFrame();
                } else {
                    renderFormatOptions([{ label: 'M3U8 Akış Adresi', url: url }]);
                }

                setupVideoMetadataListeners();
                resultSection.classList.remove('hidden');
                showToast('M3U8 Akışı yüklendi ve oynatıcıya bağlandı', 'success');
            }

            function loadHlsWithProxy(originalUrl) {
                const proxyUrl = getBackendUrl('proxy', { url: originalUrl });
                if (hlsPlayerInstance) {
                    hlsPlayerInstance.loadSource(proxyUrl);
                    hlsPlayerInstance.attachMedia(previewVideo);
                } else {
                    previewVideo.src = proxyUrl;
                }
            }

            // Handler for Direct Videos (.mp4, .webm)
            async function handleDirectVideo(url) {
                streamTypeTag.textContent = 'Doğrudan MP4/WEBM';
                
                // Extract filename from URL
                const fileName = url.split('/').pop().split('?')[0] || 'Video_Dosyasi.mp4';
                videoTitle.textContent = fileName;

                previewVideo.src = url;
                previewVideo.classList.remove('hidden');
                previewPlaceholder.classList.add('hidden');

                const formats = [
                    { label: 'Orijinal Kalite (Doğrudan - Takılmasız)', url: url, quality: 'HD' },
                    { label: 'Güvenli Sunucu Proxy Akışı', url: getBackendUrl('proxy', { url: url }), quality: 'Proxy' }
                ];

                renderFormatOptions(formats);
                setupVideoMetadataListeners();
                forceRenderFirstFrame();
                resultSection.classList.remove('hidden');
                showToast('Doğrudan video dosyası algılandı', 'success');
            }

            // Handler for Social Platforms using Cobalt API (with automatic fallback mirror rotation)
            async function handleSocialPlatform(url, platform) {
                streamTypeTag.textContent = 'API Çözümlendi';

                // Normalize URL for API compatibility (vkvideo.ru -> vk.com, x.com -> twitter.com)
                let normalizedUrl = url;
                if (normalizedUrl.includes('vkvideo.ru')) {
                    normalizedUrl = normalizedUrl.replace('vkvideo.ru', 'vk.com');
                }
                if (normalizedUrl.includes('x.com')) {
                    normalizedUrl = normalizedUrl.replace('x.com', 'twitter.com');
                }

                // Gather list of API endpoints to try
                let endpointsToTry = [];
                
                // 1. First try the user's configured API endpoint
                if (appSettings.apiEndpoint) {
                    endpointsToTry.push(appSettings.apiEndpoint);
                }

                // 2. Fetch community working endpoints from cobalt.directory as fallback list
                try {
                    loadingStatusText.textContent = "Aktif çözümleyici sunucuları aranıyor...";
                    // Fetch list through our local proxy to avoid CORS
                    const directoryRes = await fetch(getBackendUrl('proxy', { url: 'https://cobalt.directory/api/working' }));
                    const directoryData = await directoryRes.json();
                    if (directoryData && directoryData.data) {
                        // Find category (e.g. vk, youtube, twitter, facebook, generic)
                        const category = platform.type || 'generic';
                        const communityList = directoryData.data[category] || directoryData.data['generic'] || [];
                        communityList.forEach(srv => {
                            if (srv && !endpointsToTry.includes(srv)) {
                                endpointsToTry.push(srv);
                            }
                        });
                    }
                } catch (dirErr) {
                    console.warn('Mirror listesi alınamadı, yerel alternatifler kullanılacak:', dirErr);
                    // Add some hardcoded reliable mirrors as a backup fallback
                    const hardcodedMirrors = [
                        'https://sunny.imput.net',
                        'https://kitty.tame.gg',
                        'https://kityune.imput.net',
                        'https://cobaltapi.squair.xyz',
                        'https://api.qwkuns.me',
                        'https://nuko-c.meowing.de'
                    ];
                    hardcodedMirrors.forEach(srv => {
                        if (!endpointsToTry.includes(srv)) {
                            endpointsToTry.push(srv);
                        }
                    });
                }

                // Try each endpoint sequentially until one succeeds
                let success = false;
                for (let i = 0; i < endpointsToTry.length; i++) {
                    const apiEndpoint = endpointsToTry[i];
                    loadingStatusText.textContent = `Çözümleyici sunucu deneniyor (${i + 1}/${endpointsToTry.length}): ${apiEndpoint.replace('https://', '')}...`;
                    
                    try {
                        const response = await fetch(`${apiEndpoint}/`, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                url: normalizedUrl,
                                videoQuality: '1080',
                                filenamePattern: 'basic'
                            })
                        });

                        if (!response.ok) {
                            throw new Error(`HTTP error ${response.status}`);
                        }

                        const data = await response.json();

                        if (data && (data.url || data.picker)) {
                            // Succeeded! Render options and return
                            videoTitle.textContent = data.filename || `${platform.name} Videosu`;
                            
                            let formats = [];

                            if (data.url) {
                                formats.push({ label: 'Yüksek Kalite (Doğrudan - Takılmasız)', url: data.url, quality: '1080p' });
                                formats.push({ label: 'Yüksek Kalite (Güvenli Sunucu Proxy)', url: getBackendUrl('proxy', { url: data.url }), quality: 'Proxy' });
                                previewVideo.src = data.url;
                                previewVideo.classList.remove('hidden');
                                previewPlaceholder.classList.add('hidden');
                            }

                            if (data.picker && Array.isArray(data.picker)) {
                                data.picker.forEach(item => {
                                    formats.push({
                                        label: `${item.type.toUpperCase()} - ${item.quality || 'Varsayılan'} (Doğrudan - Takılmasız)`,
                                        url: item.url,
                                        quality: item.quality || 'SD'
                                    });
                                    formats.push({
                                        label: `${item.type.toUpperCase()} - ${item.quality || 'Varsayılan'} (Güvenli Sunucu Proxy)`,
                                        url: getBackendUrl('proxy', { url: item.url }),
                                        quality: 'Proxy'
                                    });
                                });
                                if (!data.url && formats.length > 0) {
                                    previewVideo.src = formats[0].url;
                                    previewVideo.classList.remove('hidden');
                                    previewPlaceholder.classList.add('hidden');
                                }
                            }

                            renderFormatOptions(formats);
                            setupVideoMetadataListeners();
                            forceRenderFirstFrame();
                            resultSection.classList.remove('hidden');
                            showToast(`${platform.name} videosu başarıyla çözümlendi!`, 'success');
                            success = true;
                            break; // Stop trying other mirrors
                        }
                    } catch (attemptErr) {
                        console.warn(`Endpoint failed: ${apiEndpoint}`, attemptErr);
                    }
                }

                if (!success) {
                    showToast('Aktif çözümleyici sunuculardan yanıt alınamadı. Sunucu tabanlı tarama deneniyor...', 'warning');
                    await handleServerSideExtraction(url, platform);
                }
            }

            // Server-Side Extractor fallback using our PHP scraper
            async function handleServerSideExtraction(url, platform) {
                loadingTitle.textContent = "Sunucu Çözümlüyor...";
                loadingStatusText.textContent = "Web sayfası taranıyor ve video kaynakları ayıklanıyor...";
                
                try {
                    const response = await fetch(getBackendUrl('extract', { url: url }));
                    const data = await response.json();
                    
                    if (data && data.formats && data.formats.length > 0) {
                        videoTitle.textContent = data.title || 'Çözümlenen Video';
                        
                        const mappedFormats = [];
                        data.formats.forEach(fmt => {
                            if (fmt.url.includes('.m3u8')) {
                                mappedFormats.push({
                                    label: fmt.label,
                                    url: fmt.url,
                                    quality: fmt.quality
                                });
                            } else {
                                mappedFormats.push({
                                    label: `${fmt.label} (Doğrudan - Takılmasız)`,
                                    url: fmt.url,
                                    quality: fmt.quality
                                });
                                mappedFormats.push({
                                    label: `${fmt.label} (Güvenli Sunucu Proxy)`,
                                    url: getBackendUrl('proxy', { url: fmt.url }),
                                    quality: 'Proxy'
                                });
                            }
                        });
                        
                        const firstFormat = mappedFormats[0];
                        if (firstFormat.url.includes('.m3u8')) {
                            await handleHlsStream(firstFormat.url);
                        } else {
                            previewVideo.src = firstFormat.url;
                            previewVideo.classList.remove('hidden');
                            previewPlaceholder.classList.add('hidden');
                            
                            renderFormatOptions(mappedFormats);
                            setupVideoMetadataListeners();
                            forceRenderFirstFrame();
                            resultSection.classList.remove('hidden');
                        }
                        
                        showToast('Video kaynakları başarıyla çözümlendi!', 'success');
                    } else {
                        throw new Error(data.error || 'Sayfa içinde indirilebilir video dosyası bulunamadı.');
                    }
                } catch (err) {
                    console.error('Sunucu tarafında çözümleme hatası:', err);
                    tryDirectFallback(url, platform);
                }
            }

            // Fallback strategy if Cobalt or external fetch fails
            function tryDirectFallback(url, platform) {
                videoTitle.textContent = `${platform.name} Bağlantısı (Doğrudan Analiz)`;
                
                // Show iframe or thumbnail fallback if YouTube
                if (platform.type === 'youtube') {
                    const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/;
                    const match = url.match(regExp);
                    if (match && match[2].length === 11) {
                        const videoId = match[2];
                        previewIframe.src = `https://www.youtube.com/embed/${videoId}?autoplay=0`;
                        previewIframe.classList.remove('hidden');
                        previewPlaceholder.classList.add('hidden');
                    }
                } else {
                    previewPlaceholder.classList.remove('hidden');
                }

                const fallbackFormats = [
                    { label: 'Yedek İndirme Bağlantısı (Proxy)', url: getBackendUrl('proxy', { url: url }) },
                    { label: 'Harici Cobalt Aynası', url: `https://cobalt.tools/#${encodeURIComponent(url)}` }
                ];

                renderFormatOptions(fallbackFormats);
                resultSection.classList.remove('hidden');
                showToast('Doğrudan medya taranarak alternatif indirme seçenekleri sunuldu.', 'info');
            }

            // Forces video player to render the first frame without playing
            function forceRenderFirstFrame() {
                previewVideo.muted = true;
                previewVideo.play().then(() => {
                    setTimeout(() => {
                        previewVideo.pause();
                        previewVideo.muted = false;
                    }, 150);
                }).catch(e => {
                    console.log("Cover render autoplay blocked:", e);
                });
            }

            function setupVideoMetadataListeners() {
                previewVideo.onloadedmetadata = () => {
                    const duration = previewVideo.duration;
                    if (!isNaN(duration)) {
                        const mins = Math.floor(duration / 60);
                        const secs = Math.floor(duration % 60);
                        videoDuration.textContent = `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
                    }
                };

                previewVideo.onerror = () => {
                    const currentSrc = previewVideo.src;
                    if (currentSrc && !currentSrc.includes('action=proxy') && !currentSrc.includes('.m3u8')) {
                        const buttons = Array.from(formatOptionsContainer.children);
                        for (const btn of buttons) {
                            const span = btn.querySelector('span');
                            if (span && (span.textContent.includes('Güvenli Sunucu Proxy') || span.textContent.includes('Proxy Akışı'))) {
                                showToast('Doğrudan bağlantı engellendi. Otomatik olarak Güvenli Sunucu Proxy Akışına geçiliyor...', 'warning');
                                btn.click();
                                break;
                            }
                        }
                    }
                };
            }

            // Render Quality / Format Selection Buttons
            function renderFormatOptions(formats) {
                formatOptionsContainer.innerHTML = '';
                if (!formats || formats.length === 0) {
                    formatOptionsContainer.innerHTML = '<p class="text-xs text-slate-500 italic">Uygun format bulunamadı</p>';
                    return;
                }

                selectedFormat = formats[0];

                formats.forEach((fmt, idx) => {
                    const btn = document.createElement('button');
                    const isSelected = idx === 0;
                    btn.className = `p-3 rounded-2xl border text-left transition flex items-center justify-between ${
                        isSelected 
                            ? 'bg-brand-600/20 border-brand-500 text-slate-100 font-semibold' 
                            : 'bg-slate-900/60 border-slate-800 text-slate-400 hover:border-slate-700 hover:text-slate-200'
                    }`;

                    btn.innerHTML = `
                        <div class="flex items-center space-x-2 truncate">
                            <i class="fa-solid ${isSelected ? 'fa-circle-dot text-brand-400' : 'fa-circle text-slate-600'} text-xs"></i>
                            <span class="text-xs truncate">${fmt.label}</span>
                        </div>
                        <span class="text-[10px] bg-slate-800 px-2 py-0.5 rounded text-slate-400 font-mono">${fmt.quality || 'MP4'}</span>
                    `;

                    btn.addEventListener('click', () => {
                        selectedFormat = fmt;
                        // Highlight selected
                        Array.from(formatOptionsContainer.children).forEach(child => {
                            child.className = child.className.replace('bg-brand-600/20 border-brand-500 text-slate-100 font-semibold', 'bg-slate-900/60 border-slate-800 text-slate-400');
                            const icon = child.querySelector('.fa-circle-dot, .fa-circle');
                            if (icon) icon.className = 'fa-solid fa-circle text-slate-600 text-xs';
                        });

                        btn.className = btn.className.replace('bg-slate-900/60 border-slate-800 text-slate-400', 'bg-brand-600/20 border-brand-500 text-slate-100 font-semibold');
                        const icon = btn.querySelector('.fa-circle');
                        if (icon) icon.className = 'fa-solid fa-circle-dot text-brand-400 text-xs';

                        // Change HLS level or player source if applicable
                        if (fmt.id !== undefined && hlsPlayerInstance) {
                            hlsPlayerInstance.currentLevel = fmt.id;
                        } else if (fmt.url && !fmt.url.includes('.m3u8')) {
                            previewVideo.src = fmt.url;
                        }
                    });

                    formatOptionsContainer.appendChild(btn);
                });
            }

            // Main Download Handler with Progress
            downloadMainBtn.addEventListener('click', () => {
                if (!selectedFormat || !selectedFormat.url) {
                    showToast('Lütfen önce indireceğiniz formatı seçin', 'error');
                    return;
                }

                const targetUrl = selectedFormat.url;
                const cleanName = decodeURIComponent(encodeURIComponent(videoTitle.textContent || 'Video'));

                // Check if it's an M3U8 file
                if (targetUrl.includes('.m3u8')) {
                    const downloadUrl = getBackendUrl('download_hls', { url: targetUrl, name: cleanName });
                    showToast('M3U8 yayın parçaları birleştirilip indiriliyor. Lütfen bekleyin...', 'success');
                    saveToHistory(videoTitle.textContent, targetUrl, 'HLS MP4');
                    window.location.href = downloadUrl;
                    return;
                }

                const downloadUrl = getBackendUrl('download', { url: targetUrl, name: cleanName });
                
                showToast('İndirme başlatıldı...', 'success');
                saveToHistory(videoTitle.textContent, targetUrl, selectedFormat.quality || 'HD');
                
                window.location.href = downloadUrl;
            });

            // Copy Link Action
            copyDirectLinkBtn.addEventListener('click', () => {
                if (selectedFormat && selectedFormat.url) {
                    copyToClipboard(selectedFormat.url);
                    showToast('Video doğrudan bağlantısı panoya kopyalandı!', 'success');
                }
            });

            // Copy FFmpeg Command
            copyFfmpegBtn.addEventListener('click', () => {
                const cmd = ffmpegCommandText.textContent.trim();
                copyToClipboard(cmd);
                showToast('FFmpeg komutu kopyalandı!', 'success');
            });

            // Capture Frame / Screenshot Button
            captureFrameBtn.addEventListener('click', () => {
                if (previewVideo.classList.contains('hidden') || !previewVideo.videoWidth) {
                    showToast('Şu anda aktif bir video karesi bulunamadı', 'error');
                    return;
                }

                try {
                    const canvas = document.createElement('canvas');
                    canvas.width = previewVideo.videoWidth;
                    canvas.height = previewVideo.videoHeight;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(previewVideo, 0, 0, canvas.width, canvas.height);

                    const dataUrl = canvas.toDataURL('image/png');
                    const a = document.createElement('a');
                    a.href = dataUrl;
                    a.download = `Frame_Snapshot_${Date.now()}.png`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);

                    showToast('Video karesi resim olarak indirildi!', 'success');
                } catch (e) {
                    showToast('Ekran görüntüsü alınırken güvenlik kısıtlaması (CORS) oluştu', 'error');
                }
            });

            // Helper Copy Clipboard
            function copyToClipboard(text) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text);
                } else {
                    const textarea = document.createElement('textarea');
                    textarea.value = text;
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                }
            }

            // Save Download Record to History
            function saveToHistory(title, url, format) {
                if (!appSettings.autoSaveHistory) return;

                const newRecord = {
                    id: Date.now(),
                    title: title || 'Video',
                    url: url,
                    format: format || 'MP4',
                    date: new Date().toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' })
                };

                downloadHistory.unshift(newRecord);
                if (downloadHistory.length > 20) downloadHistory.pop(); // Keep last 20
                localStorage.setItem('sg_download_history', JSON.stringify(downloadHistory));

                updateHistoryBadge();
                renderHistoryList();
            }

            function updateHistoryBadge() {
                if (downloadHistory.length > 0) {
                    historyBadge.textContent = downloadHistory.length;
                    historyBadge.classList.remove('hidden');
                } else {
                    historyBadge.classList.add('hidden');
                }
            }

            function renderHistoryList() {
                if (downloadHistory.length === 0) {
                    historyListContainer.innerHTML = '<p class="text-xs text-slate-500 italic text-center py-4">Henüz kayıtlı indirme geçmişi yok.</p>';
                    return;
                }

                historyListContainer.innerHTML = '';
                downloadHistory.forEach(item => {
                    const row = document.createElement('div');
                    row.className = 'p-3 bg-slate-900/80 rounded-xl border border-slate-800 flex items-center justify-between text-xs space-x-2';
                    row.innerHTML = `
                        <div class="truncate flex-1 space-y-0.5">
                            <div class="font-medium text-slate-200 truncate">${item.title}</div>
                            <div class="text-[10px] text-slate-500 font-mono truncate">${item.url}</div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span class="text-[10px] bg-brand-500/20 text-brand-300 border border-brand-500/30 px-1.5 py-0.5 rounded">${item.format}</span>
                            <button class="copy-hist-btn text-slate-400 hover:text-white p-1" title="Linki Kopyala"><i class="fa-regular fa-copy"></i></button>
                        </div>
                    `;

                    row.querySelector('.copy-hist-btn').addEventListener('click', () => {
                        copyToClipboard(item.url);
                        showToast('Geçmiş bağlantısı kopyalandı', 'success');
                    });

                    historyListContainer.appendChild(row);
                });
            }

            clearHistoryBtn.addEventListener('click', () => {
                downloadHistory = [];
                localStorage.removeItem('sg_download_history');
                updateHistoryBadge();
                renderHistoryList();
                showToast('İndirme geçmişi temizlendi', 'info');
            });

            // Process Batch Download URLs
            processBatchBtn.addEventListener('click', async () => {
                const text = batchUrlsInput.value.trim();
                if (!text) {
                    showToast('Lüten işlenecek URL satırlarını girin', 'error');
                    return;
                }

                const urls = text.split('\n').map(u => u.trim()).filter(u => u.length > 0);
                batchResults.classList.remove('hidden');
                batchResults.innerHTML = `<div class="text-xs text-brand-400 font-semibold mb-2">${urls.length} bağlantı taranıyor...</div>`;

                for (let i = 0; i < urls.length; i++) {
                    const u = urls[i];
                    const itemDiv = document.createElement('div');
                    itemDiv.className = 'p-2.5 bg-slate-900 border border-slate-800 rounded-xl text-xs flex items-center justify-between';
                    
                    const p = detectPlatform(u);
                    itemDiv.innerHTML = `
                        <div class="truncate flex-1 font-mono text-[11px] text-slate-300">
                            <i class="${p.icon} mr-1.5"></i> ${u}
                        </div>
                        <button class="px-2.5 py-1 bg-brand-600 hover:bg-brand-500 text-white text-[11px] rounded-lg transition ml-2">İncele</button>
                    `;

                    itemDiv.querySelector('button').addEventListener('click', () => {
                        videoUrlInput.value = u;
                        clearInputBtn.classList.remove('hidden');
                        analyzeBtn.click();
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    });

                    batchResults.appendChild(itemDiv);
                }

                showToast('Toplu bağlantı listesi oluşturuldu', 'success');
            });

            // Reset video player states cleanly
            function resetPlayerState() {
                if (hlsPlayerInstance) {
                    hlsPlayerInstance.destroy();
                    hlsPlayerInstance = null;
                }
                previewVideo.pause();
                previewVideo.removeAttribute('src');
                previewVideo.load();
                previewVideo.classList.add('hidden');
                
                previewIframe.src = '';
                previewIframe.classList.add('hidden');

                previewImage.src = '';
                previewImage.classList.add('hidden');

                previewPlaceholder.classList.remove('hidden');
            }

        });
    </script>
</body>
</html>
