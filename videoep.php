<?php
// Standalone Eporner Video Downloader & Resolver
// Without modifying the main video.php

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    // Helper to fetch remote page using cURL with age verification cookies
    function fetch_eporner_html($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');
        
        $parsed_ref = parse_url($url);
        $referer = ($parsed_ref['scheme'] ?? 'https') . '://' . ($parsed_ref['host'] ?? 'www.eporner.com');
        curl_setopt($ch, CURLOPT_REFERER, $referer);
        curl_setopt($ch, CURLOPT_COOKIE, 'age_verified=1');
        
        $html = curl_exec($ch);
        curl_close($ch);
        return $html;
    }

    if ($action === 'extract' && isset($_GET['url'])) {
        header('Content-Type: application/json; charset=utf-8');
        $target_url = $_GET['url'];

        if (!filter_var($target_url, FILTER_VALIDATE_URL) || strpos($target_url, 'eporner.com') === false) {
            echo json_encode(array('error' => 'Lütfen geçerli bir eporner.com linki girin.'));
            exit;
        }

        $html = fetch_eporner_html($target_url);

        if (!$html) {
            echo json_encode(array('error' => 'Eporner sayfası yüklenemedi. Lütfen tekrar deneyin.'));
            exit;
        }

        if (strpos($html, 'Eporner Age Verification') !== false) {
            echo json_encode(array('error' => 'Yaş doğrulaması aşılamadı.'));
            exit;
        }

        // 1. Extract Video Title
        $title = 'Eporner Video';
        if (preg_match('/<title>(.*?)<\/title>/is', $html, $title_matches)) {
            $title = str_replace(' - EPORNER', '', trim($title_matches[1]));
        }

        $formats = array();
        $parsed = parse_url($target_url);
        $base_url = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.eporner.com');

        // 2. Extract ld+json contentUrl (Direct High Quality CDN URL)
        if (preg_match('/"contentUrl"\s*:\s*["\']([^"\']+)["\']/i', $html, $json_matches)) {
            $formats[] = array(
                'label' => 'Orijinal Video (En Yüksek Çözünürlük - CDN)',
                'url' => $json_matches[1],
                'quality' => 'Orijinal (4K/HD)'
            );
        }

        // 3. Extract all download links from the downloaddiv container
        preg_match_all('/<a\s+[^>]*href=["\']([^"\']+\/dload\/[^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $rel_url = $match[1];
            $anchor_text = trim(strip_tags($match[2]));

            // Build Absolute URL
            $abs_url = $rel_url;
            if (strpos($rel_url, 'http') !== 0) {
                if (strpos($rel_url, '/') === 0) {
                    $abs_url = $base_url . $rel_url;
                } else {
                    $abs_url = $base_url . '/' . $rel_url;
                }
            }

            // Parse and clean label
            $clean_label = $anchor_text;
            if (preg_match('/\(([^)]+)\)/', $anchor_text, $label_info)) {
                $clean_label = str_replace(', ', ' - ', $label_info[1]);
            } else {
                $clean_label = preg_replace('/^Download\s+MP4\s+/i', '', $clean_label);
                $clean_label = preg_replace('/^MP4\s+/i', '', $clean_label);
            }

            // Determine Quality Badge
            $quality_badge = 'HD';
            if (stripos($clean_label, '240p') !== false) $quality_badge = '240p';
            elseif (stripos($clean_label, '360p') !== false) $quality_badge = '360p';
            elseif (stripos($clean_label, '480p') !== false) $quality_badge = '480p';
            elseif (stripos($clean_label, '720p') !== false) $quality_badge = '720p';
            elseif (stripos($clean_label, '1080p') !== false) $quality_badge = '1080p';
            elseif (stripos($clean_label, '1440p') !== false) $quality_badge = '2K';
            elseif (stripos($clean_label, '2160p') !== false) $quality_badge = '4K';

            $formats[] = array(
                'label' => $clean_label,
                'url' => $abs_url,
                'quality' => $quality_badge
            );
        }

        // De-duplicate formats by URL
        $unique_formats = array();
        $seen_urls = array();
        foreach ($formats as $fmt) {
            if (!in_array($fmt['url'], $seen_urls)) {
                $seen_urls[] = $fmt['url'];
                $unique_formats[] = $fmt;
            }
        }

        if (empty($unique_formats)) {
            echo json_encode(array('error' => 'Bu sayfada indirilebilir video formatı bulunamadı.'));
            exit;
        }

        echo json_encode(array(
            'title' => $title,
            'formats' => $unique_formats
        ));
        exit;
    }

    // Direct High-Speed Download Streaming with Referer & Cookie bypass
    if ($action === 'download' && isset($_GET['url'])) {
        $target_url = $_GET['url'];
        $filename = isset($_GET['name']) ? preg_replace('/[^a-zA-Z0-9_-]/', '_', urldecode($_GET['name'])) : 'Eporner_Video';

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
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');
        
        // Set Referer and Cookie to bypass Hotlink & Age-Gate blocks
        $parsed_ref = parse_url($target_url);
        $referer = ($parsed_ref['scheme'] ?? 'https') . '://' . ($parsed_ref['host'] ?? 'www.eporner.com');
        curl_setopt($ch, CURLOPT_REFERER, $referer);
        curl_setopt($ch, CURLOPT_COOKIE, 'age_verified=1; cookies_accepted=1');

        if (ob_get_level()) {
            ob_end_clean();
        }
        
        curl_exec($ch);
        curl_close($ch);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StreamGrab Pro - Eporner Özel İndirici</title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: rgba(20, 26, 46, 0.6);
            --border-color: rgba(255, 255, 255, 0.08);
            --brand-color: #ff3838;
            --brand-glow: rgba(255, 56, 56, 0.4);
            --text-color: #f3f4f6;
            --text-muted: #9ca3af;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(at 0% 0%, rgba(255, 56, 56, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(31, 58, 138, 0.15) 0px, transparent 50%);
            background-attachment: fixed;
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            max-width: 700px;
            width: 100%;
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            text-align: center;
        }

        .header {
            margin-bottom: 35px;
        }

        .header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #ffffff 30%, #ff6b6b 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header p {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        .input-group {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
            background: rgba(0, 0, 0, 0.25);
            padding: 6px;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .input-group:focus-within {
            border-color: var(--brand-color);
            box-shadow: 0 0 12px var(--brand-glow);
        }

        .input-group input {
            flex: 1;
            background: transparent;
            border: none;
            outline: none;
            color: #fff;
            padding: 12px 16px;
            font-size: 1rem;
            font-family: inherit;
        }

        .input-group input::placeholder {
            color: #4b5563;
        }

        .btn {
            background: var(--brand-color);
            color: white;
            border: none;
            outline: none;
            padding: 12px 28px;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px var(--brand-glow);
        }

        .btn:hover {
            transform: translateY(-2px);
            filter: brightness(1.1);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn:disabled {
            background: #4b5563;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        /* Result Section styling */
        .result-section {
            margin-top: 30px;
            border-top: 1px solid var(--border-color);
            padding-top: 30px;
            text-align: left;
        }

        .video-title {
            font-size: 1.15rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 12px;
            border-left: 4px solid var(--brand-color);
        }

        .formats-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            max-height: 350px;
            overflow-y: auto;
            padding-right: 5px;
        }

        .formats-list::-webkit-scrollbar {
            width: 6px;
        }

        .formats-list::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
        }

        .format-item {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.2s ease;
        }

        .format-item:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 56, 56, 0.3);
        }

        .format-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .format-label {
            font-weight: 500;
            color: #e5e7eb;
            font-size: 0.95rem;
        }

        .badge {
            background: rgba(255, 56, 56, 0.15);
            color: #ff6b6b;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            align-self: flex-start;
            text-transform: uppercase;
        }

        .btn-download {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff;
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-download:hover {
            background: var(--brand-color);
            border-color: var(--brand-color);
            box-shadow: 0 4px 10px var(--brand-glow);
        }

        .loader {
            display: none;
            margin: 20px 0;
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        .loader i {
            color: var(--brand-color);
            margin-right: 8px;
        }

        .error-message {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #f87171;
            padding: 14px 20px;
            border-radius: 14px;
            margin-top: 20px;
            display: none;
            text-align: left;
            font-size: 0.9rem;
            align-items: center;
            gap: 10px;
        }

        .footer {
            margin-top: 30px;
            color: var(--text-muted);
            font-size: 0.8rem;
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="header">
            <h1><i class="fa-solid fa-download" style="color: var(--brand-color);"></i> Eporner Özel İndirici</h1>
            <p>Eporner videolarını çözümler ve Render sunucunuz üzerinden kesintisiz indirir.</p>
        </div>

        <div class="input-group">
            <input type="text" id="video-url" placeholder="https://www.eporner.com/video-xxxxxx/..." value="https://www.eporner.com/video-OSzspjk9hSm/hqcollect-6879/">
            <button id="resolve-btn" class="btn">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Çözümle
            </button>
        </div>

        <div id="loader" class="loader">
            <i class="fa-solid fa-spinner fa-spin"></i> Video bağlantıları çözümleniyor, lütfen bekleyin...
        </div>

        <div id="error-box" class="error-message">
            <i class="fa-solid fa-circle-exclamation"></i> <span id="error-text">Hata açıklaması</span>
        </div>

        <div id="result-box" class="result-section" style="display: none;">
            <div id="title-display" class="video-title">
                <i class="fa-solid fa-film"></i> Video Başlığı
            </div>
            <div id="formats-container" class="formats-list">
                <!-- Quality options will be loaded here dynamically -->
            </div>
        </div>

        <div class="footer">
            StreamGrab Pro &copy; 2026 - Bulut İndirme Altyapısı
        </div>
    </div>

    <script>
        const resolveBtn = document.getElementById('resolve-btn');
        const videoUrlInput = document.getElementById('video-url');
        const loader = document.getElementById('loader');
        const errorBox = document.getElementById('error-box');
        const errorText = document.getElementById('error-text');
        const resultBox = document.getElementById('result-box');
        const titleDisplay = document.getElementById('title-display');
        const formatsContainer = document.getElementById('formats-container');

        resolveBtn.addEventListener('click', async () => {
            const url = videoUrlInput.value.trim();
            if (!url) {
                showError('Lütfen bir Eporner video linki girin.');
                return;
            }

            // Reset UI
            errorBox.style.display = 'none';
            resultBox.style.display = 'none';
            loader.style.display = 'block';
            resolveBtn.disabled = true;

            try {
                const response = await fetch(`videoep.php?action=extract&url=${encodeURIComponent(url)}`);
                const data = await response.json();

                loader.style.display = 'none';
                resolveBtn.disabled = false;

                if (data.error) {
                    showError(data.error);
                } else if (data.formats && data.formats.length > 0) {
                    showResults(data);
                } else {
                    showError('Video formatı çözümlenemedi.');
                }
            } catch (err) {
                loader.style.display = 'none';
                resolveBtn.disabled = false;
                showError('Çözümleme isteği sırasında bir hata oluştu.');
                console.error(err);
            }
        });

        function showError(msg) {
            errorText.textContent = msg;
            errorBox.style.display = 'flex';
        }

        function showResults(data) {
            titleDisplay.innerHTML = `<i class="fa-solid fa-film"></i> ${data.title}`;
            formatsContainer.innerHTML = '';

            data.formats.forEach(fmt => {
                const item = document.createElement('div');
                item.className = 'format-item';

                // Route through the Render cloud videoep.php download service
                // This bypasses the shared hosting firewall blocks and 500MB limits entirely!
                const renderDownloadUrl = `https://streamgrab-gzrx.onrender.com/videoep.php?action=download&url=${encodeURIComponent(fmt.url)}&name=${encodeURIComponent(data.title)}`;

                item.innerHTML = `
                    <div class="format-info">
                        <span class="format-label">${fmt.label}</span>
                        <span class="badge">${fmt.quality}</span>
                    </div>
                    <a href="${renderDownloadUrl}" class="btn-download" target="_blank">
                        <i class="fa-solid fa-circle-arrow-down"></i> İndir
                    </div>
                `;
                formatsContainer.appendChild(item);
            });

            resultBox.style.display = 'block';
        }
    </script>
</body>
</html>
