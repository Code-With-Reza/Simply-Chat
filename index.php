<?php
// Pastikan tidak ada spasi sebelum tag <?php
ob_start(); // Mulai output buffering untuk mencegah kerusakan header gambar
session_start();
date_default_timezone_set('Asia/Jakarta');

// ==========================================
// 1. KONFIGURASI (Dari Variabel ENV)
// ==========================================
$db_host = getenv('DB_HOST') ?: 'localhost'; 
$db_name = getenv('DB_NAME') ?: 'chatdb';
$db_user = getenv('DB_USERNAME') ?: 'chatuser';
$db_pass = getenv('DB_PASSWORD') ?: 'chatpass';
$db_port = getenv('DB_PORT') ? (int)getenv('DB_PORT') : 3306;

$cousin_pass = getenv('COUSIN_PASSWORD') ?: 'Raja';
$cousin_hint = getenv('COUSIN_HINT') ?: 'nama lengkap raja';

$raja_pass = getenv('RAJA_PASSWORD') ?: 'raja123';
$raja_hint = getenv('RAJA_HINT') ?: 'Admin passcode';

// ==========================================
// 2. SETUP DATABASE
// ==========================================
$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);

if ($conn->connect_error) {
    $conn = @new mysqli($db_host, $db_user, $db_pass, "", $db_port);
    if ($conn->connect_error) die("Koneksi Database Gagal.");
    $conn->query("CREATE DATABASE IF NOT EXISTS `$db_name`");
    $conn->select_db($db_name);
}

$conn->query("CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender VARCHAR(50),
    type VARCHAR(20),
    content TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS user_status (
    username VARCHAR(50) PRIMARY KEY,
    last_seen INT
)");

// Tabel file dengan tipe MEDIUMBLOB (Cukup untuk file hingga 16MB)
$conn->query("CREATE TABLE IF NOT EXISTS files_storage (
    id VARCHAR(100) PRIMARY KEY,
    file_name VARCHAR(255),
    mime_type VARCHAR(100),
    file_data MEDIUMBLOB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ==========================================
// 3. FUNGSI BANTUAN
// ==========================================
function formatTerakhirDilihat($timestamp) {
    $hari_ini = strtotime("today");
    $kemarin = strtotime("yesterday");
    if ($timestamp >= $hari_ini) return "hari ini pukul " . date("H:i", $timestamp);
    elseif ($timestamp >= $kemarin) return "kemarin pukul " . date("H:i", $timestamp);
    else return date("d/m/Y", $timestamp) . " pukul " . date("H:i", $timestamp);
}

// ==========================================
// 4. API & AJAX ENDPOINTS
// ==========================================
if (isset($_GET['action'])) {
    
    // --- POLLING CHAT (OPTIMIZED) ---
    if ($_GET['action'] == 'poll' && isset($_SESSION['user'])) {
        header('Content-Type: application/json');
        $last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
        $saya = $conn->real_escape_string($_SESSION['user']);
        $sekarang = time();

        $conn->query("INSERT INTO user_status (username, last_seen) VALUES ('$saya', $sekarang) ON DUPLICATE KEY UPDATE last_seen=$sekarang");

        // OPTIMASI: Jika ini load pertama ($last_id = 0), hanya ambil 50 pesan terakhir agar tidak lag!
        if ($last_id == 0) {
            $res = $conn->query("SELECT * FROM (SELECT * FROM messages ORDER BY id DESC LIMIT 50) sub ORDER BY id ASC");
        } else {
            $res = $conn->query("SELECT * FROM messages WHERE id > $last_id ORDER BY id ASC");
        }
        
        $msgs =[];
        while ($row = $res->fetch_assoc()) {
            $msgs[] = $row;
        }

        $partner = ($saya === 'Raja') ? 'Widya' : 'Raja';
        $res_stat = $conn->query("SELECT last_seen FROM user_status WHERE username = '$partner'");
        $stat = $res_stat->fetch_assoc();

        $is_online = false;
        $status_text = "Belum pernah online";

        if ($stat) {
            $selisih_waktu = $sekarang - $stat['last_seen'];
            if ($selisih_waktu < 10) { 
                $is_online = true;
                $status_text = "Online";
            } else {
                $status_text = "Terakhir dilihat " . formatTerakhirDilihat($stat['last_seen']);
            }
        }

        echo json_encode(['messages' => $msgs, 'is_online' => $is_online, 'partner_status' => $status_text]);
        exit;
    }

    // --- SEND MESSAGE ---
    if ($_GET['action'] == 'send_message' && isset($_SESSION['user'])) {
        header('Content-Type: application/json');
        $type = $conn->real_escape_string($_POST['type'] ?? 'text');
        $content = $conn->real_escape_string($_POST['content'] ?? '');
        $sender = $conn->real_escape_string($_SESSION['user']);
        
        $conn->query("INSERT INTO messages (sender, type, content) VALUES ('$sender', '$type', '$content')");
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // --- UPLOAD FILE ---
    if ($_GET['action'] == 'upload' && isset($_FILES['file'])) {
        header('Content-Type: application/json');
        $file = $_FILES['file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(500);
            echo json_encode(['error' => 'Gagal mengunggah file.']);
            exit;
        }

        $file_id = uniqid('f_') . bin2hex(random_bytes(2));
        $file_name = $file['name'];
        $mime_type = $file['type'] ?: 'application/octet-stream';
        $file_data = file_get_contents($file['tmp_name']);
        
        $stmt = $conn->prepare("INSERT INTO files_storage (id, file_name, mime_type, file_data) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssss", $file_id, $file_name, $mime_type, $file_data);
            if ($stmt->execute()) {
                echo json_encode(['url' => '?action=view_file&id=' . urlencode($file_id)]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Database penuh/kuota habis.']);
            }
            $stmt->close();
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Error kueri DB.']);
        }
        exit;
    }

    // --- VIEW / RENDER FILE (SANGAT DIOPTIMASI) ---
    if ($_GET['action'] == 'view_file' && isset($_GET['id'])) {
        $file_id = $_GET['id'];
        
        $stmt = $conn->prepare("SELECT file_name, mime_type, file_data FROM files_storage WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $file_id);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $stmt->bind_result($db_file_name, $db_mime_type, $db_file_data);
                $stmt->fetch();
                
                // BERSIHKAN BUFFER UNTUK MENCEGAH GAMBAR RUSAK
                ob_end_clean(); 
                
                // CACHING 1 TAHUN (Agar database tidak terus-terusan ditarik)
                header("Content-Type: " . $db_mime_type);
                header('Cache-Control: max-age=31536000, public');
                header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 31536000));
                header('Content-Disposition: inline; filename="' . $db_file_name . '"');
                
                echo $db_file_data; 
                exit;
            }
            $stmt->close();
        }
        http_response_code(404);
        exit;
    }
    exit;
}

// ==========================================
// 5. ROUTING & AUTENTIKASI
// ==========================================
$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

if (basename($uri) === 'raja') {
    $target_user = 'Raja';
    $partner_user = 'Widya';
    $target_pass = strtolower($raja_pass);
    $hint = $raja_hint;
} else {
    $target_user = 'Widya';
    $partner_user = 'Raja';
    $target_pass = strtolower($cousin_pass);
    $hint = $cousin_hint;
}

$error_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $input_pass = strtolower(trim($_POST['password']));
    if ($input_pass === $target_pass) {
        $_SESSION['user'] = $target_user;
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    } else {
        $error_msg = 'Kata sandi salah!';
    }
}

if (!isset($_SESSION['user']) || $_SESSION['user'] !== $target_user) {
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Diperlukan Akses</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-gray-100 h-screen flex items-center justify-center p-4"><form method="POST" class="bg-white p-8 rounded-lg shadow-xl max-w-sm w-full"><h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">Selamat Datang, ' . htmlspecialchars($target_user) . '</h2>';
    if ($error_msg !== '') echo '<div class="bg-red-100 text-red-600 p-3 rounded mb-4 text-sm text-center font-semibold">' . htmlspecialchars($error_msg) . '</div>';
    echo '<div class="mb-4"><label class="block text-gray-700 text-sm font-bold mb-2">Kata Sandi</label><input type="password" name="password" class="w-full px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500" required autofocus><p class="text-xs text-gray-500 mt-2">Petunjuk: ' . htmlspecialchars($hint) . '</p></div><button type="submit" class="w-full bg-[#00a884] text-white font-bold py-2 px-4 rounded hover:bg-[#008f6f] transition">Masuk</button></form></body></html>';
    exit;
}

$currentUser = $_SESSION['user'];
ob_end_flush(); // Selesai buffer
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Obrolan - <?= htmlspecialchars($currentUser) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        #chat-box::-webkit-scrollbar { width: 6px; }
        #chat-box::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }
    </style>
</head>
<body class="bg-gray-200 h-screen flex flex-col items-center justify-center p-0 md:p-4">

    <div class="w-full h-full md:max-w-3xl md:h-[850px] bg-white md:rounded-lg shadow-2xl flex flex-col relative overflow-hidden">
        
        <!-- Header -->
        <div class="bg-[#005c4b] text-white p-3 md:rounded-t-lg flex justify-between items-center shadow-md z-10 shrink-0">
            <div class="flex items-center space-x-3">
                <div class="bg-gray-300 text-gray-600 w-10 h-10 rounded-full flex items-center justify-center text-xl">
                    <i class="fas fa-user"></i>
                </div>
                <div class="flex flex-col">
                    <h2 class="text-base font-bold leading-tight"><?= htmlspecialchars($partner_user) ?></h2>
                    <span id="partner-status" class="text-[11px] text-gray-200">Memuat status...</span>
                </div>
            </div>
            <span class="text-xs bg-[#00a884] px-2 py-1 rounded shadow-sm flex items-center">
                Saya: <?= htmlspecialchars($currentUser) ?>
            </span>
        </div>

        <!-- Chat Area -->
        <div id="chat-box" class="flex-1 p-4 overflow-y-auto bg-[#efeae2] flex flex-col space-y-3 relative w-full h-full" style="background-image: url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png'); background-blend-mode: multiply; background-color: #efeae2;">
            <!-- Pesan akan muncul di sini -->
        </div>

        <!-- Input Area -->
        <div class="p-2 md:p-3 bg-[#f0f2f5] border-t flex items-center space-x-1 md:space-x-2 md:rounded-b-lg shrink-0">
            <label class="cursor-pointer text-gray-500 hover:text-gray-700 p-2 transition">
                <i class="fas fa-paperclip text-xl md:text-2xl"></i>
                <input type="file" id="file-input" class="hidden" onchange="handleFileUpload()">
            </label>
            <input type="text" id="message-input" class="flex-1 border-0 rounded-lg px-4 py-3 shadow-sm focus:outline-none focus:ring-1 focus:ring-gray-300" placeholder="Ketik pesan...">
            <button id="record-btn" class="text-gray-500 hover:text-red-500 p-2 transition duration-200" onmousedown="startRecording()" onmouseup="stopRecording()" ontouchstart="startRecording()" ontouchend="stopRecording()">
                <i class="fas fa-microphone text-xl md:text-2xl"></i>
            </button>
            <button onclick="sendText()" class="bg-[#00a884] text-white rounded-full p-3 w-10 h-10 md:w-12 md:h-12 flex items-center justify-center hover:bg-[#008f6f] transition shadow-md">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
    </div>

    <script>
        const currentUser = "<?= htmlspecialchars($currentUser) ?>";
        const chatBox = document.getElementById("chat-box");
        let lastId = 0;

        // 1. Polling diubah menjadi 2.5 detik (Mencegah Lag Server)
        async function fetchMessages() {
            try {
                const res = await fetch(`?action=poll&last_id=${lastId}`);
                if (!res.ok) return;
                const data = await res.json();
                
                const statusEl = document.getElementById('partner-status');
                statusEl.textContent = data.partner_status;
                if (data.is_online) {
                    statusEl.classList.replace('text-gray-200', 'text-green-300');
                    statusEl.classList.add('font-bold');
                } else {
                    statusEl.classList.replace('text-green-300', 'text-gray-200');
                    statusEl.classList.remove('font-bold');
                }

                if (data.messages.length > 0) {
                    let shouldScroll = chatBox.scrollHeight - chatBox.scrollTop <= chatBox.clientHeight + 150;
                    data.messages.forEach(msg => {
                        appendMessage(msg);
                        lastId = msg.id;
                    });
                    if (shouldScroll) chatBox.scrollTop = chatBox.scrollHeight;
                }
            } catch (e) {}
        }
        setInterval(fetchMessages, 2500); 
        fetchMessages();

        function appendMessage(data) {
            const isMe = data.sender === currentUser;
            const msgDiv = document.createElement("div");
            
            msgDiv.className = `max-w-[80%] md:max-w-[70%] p-2 rounded-lg shadow-sm ${isMe ? 'bg-[#dcf8c6] text-gray-800 self-end rounded-tr-none' : 'bg-white text-gray-800 self-start rounded-tl-none'}`;

            let contentHtml = '';
            
            if (data.type === 'text') {
                contentHtml = `<p class="break-words text-[14px] md:text-[15px] leading-relaxed">${escapeHTML(data.content)}</p>`;
            } else if (data.type === 'image') {
                // Menambahkan loading lazy agar memori tidak penuh
                contentHtml = `<img src="${data.content}" loading="lazy" class="rounded-md max-h-64 object-cover cursor-pointer border mt-1" onclick="window.open('${data.content}')">`;
            } else if (data.type === 'voice') {
                contentHtml = `<audio controls class="w-full max-w-[200px] md:max-w-[250px] mt-1"><source src="${data.content}"></audio>`;
            } else if (data.type === 'document') {
                contentHtml = `<a href="${data.content}" target="_blank" class="text-blue-600 flex items-center bg-black/5 p-2 rounded mt-1"><i class="fas fa-file-alt mr-2 text-xl"></i> <span class="truncate max-w-[150px] text-sm underline">Dokumen</span></a>`;
            }

            const time = new Date(data.created_at).toLocaleTimeString('id-ID', {hour: '2-digit', minute:'2-digit'});
            
            msgDiv.innerHTML = `
                <div class="flex flex-col">
                    ${!isMe ? `<span class="text-xs font-bold text-[#ea0070] mb-1">${data.sender}</span>` : ''}
                    ${contentHtml}
                    <span class="text-[10px] text-gray-500 self-end mt-1">${time}</span>
                </div>
            `;
            chatBox.appendChild(msgDiv);
        }

        async function postMessage(type, content) {
            const fd = new FormData();
            fd.append('type', type);
            fd.append('content', content);
            await fetch('?action=send_message', { method: 'POST', body: fd });
            fetchMessages();
        }

        function sendText() {
            const input = document.getElementById("message-input");
            if (input.value.trim() !== "") {
                postMessage('text', input.value.trim());
                input.value = "";
            }
        }

        document.getElementById("message-input").addEventListener("keypress", e => {
            if (e.key === "Enter") sendText();
        });

        // 2. OPTIMASI: Kompresi Gambar Otomatis Sebelum Diunggah (Agar ringan di DB)
        async function compressImage(file) {
            return new Promise((resolve) => {
                const reader = new FileReader();
                reader.readAsDataURL(file);
                reader.onload = (event) => {
                    const img = new Image();
                    img.src = event.target.result;
                    img.onload = () => {
                        const canvas = document.createElement('canvas');
                        const MAX_WIDTH = 1080;
                        const MAX_HEIGHT = 1080;
                        let width = img.width;
                        let height = img.height;

                        if (width > height) {
                            if (width > MAX_WIDTH) { height *= MAX_WIDTH / width; width = MAX_WIDTH; }
                        } else {
                            if (height > MAX_HEIGHT) { width *= MAX_HEIGHT / height; height = MAX_HEIGHT; }
                        }
                        
                        canvas.width = width; canvas.height = height;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0, width, height);
                        
                        // Kompresi kualitas JPEG 70% (File akan jadi sekitar 100KB - 200KB saja!)
                        canvas.toBlob((blob) => {
                            resolve(new File([blob], file.name, { type: 'image/jpeg' }));
                        }, 'image/jpeg', 0.7);
                    };
                };
            });
        }

        async function handleFileUpload() {
            const fileInput = document.getElementById("file-input");
            let file = fileInput.files[0];
            if (!file) return;

            const inputField = document.getElementById("message-input");
            inputField.placeholder = "Memproses & Mengirim...";
            inputField.disabled = true;

            try {
                // Jika itu adalah gambar, kompres dulu sebelum dikirim!
                let msgType = 'document';
                if (file.type.startsWith('image/')) {
                    msgType = 'image';
                    file = await compressImage(file);
                } else if (file.type.startsWith('audio/')) {
                    msgType = 'voice';
                }

                const fd = new FormData();
                fd.append("file", file);

                const res = await fetch('?action=upload', { method: 'POST', body: fd });
                const data = await res.json();
                
                if(data.error) throw new Error(data.error);

                postMessage(msgType, data.url);
            } catch (err) { 
                alert(err.message || "Gagal mengunggah ke database."); 
            } finally {
                inputField.placeholder = "Ketik pesan...";
                inputField.disabled = false;
                fileInput.value = ""; 
            }
        }

        // --- Voice Recording ---
        let mediaRecorder;
        let audioChunks =[];
        const recordBtn = document.getElementById("record-btn");

        async function startRecording() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                mediaRecorder = new MediaRecorder(stream);
                recordBtn.classList.add("text-red-500", "scale-125");
                document.getElementById("message-input").placeholder = "Merekam... Lepaskan untuk mengirim.";

                mediaRecorder.ondataavailable = event => {
                    if (event.data.size > 0) audioChunks.push(event.data);
                };

                mediaRecorder.onstop = async () => {
                    document.getElementById("message-input").placeholder = "Mengirim...";
                    const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                    audioChunks =[]; 
                    
                    const fd = new FormData();
                    fd.append("file", audioBlob, "voice_note.webm");

                    try {
                        const res = await fetch('?action=upload', { method: 'POST', body: fd });
                        const data = await res.json();
                        postMessage("voice", data.url);
                    } catch(e) {}
                    document.getElementById("message-input").placeholder = "Ketik pesan...";
                };
                mediaRecorder.start();
            } catch (err) {
                alert("Izinkan mikrofon di pengaturan browser Anda.");
            }
        }

        function stopRecording() {
            if (mediaRecorder && mediaRecorder.state !== "inactive") {
                mediaRecorder.stop();
                recordBtn.classList.remove("text-red-500", "scale-125");
                mediaRecorder.stream.getTracks().forEach(track => track.stop());
            }
        }

        function escapeHTML(str) {
            return str.replace(/[&<>'"]/g, tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag]));
        }
    </script>
</body>
</html>
