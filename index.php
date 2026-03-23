<?php
session_start();
date_default_timezone_set('Asia/Jakarta'); // Mengatur zona waktu ke Waktu Indonesia Barat (WIB)

// ==========================================
// 1. KONFIGURASI (Dari Variabel ENV)
// ==========================================
$db_host = getenv('DB_HOST') ?: 'localhost'; 
$db_user = getenv('DB_USER') ?: 'chatuser';
$db_pass = getenv('DB_PASS') ?: 'chatpass';
$db_name = getenv('DB_NAME') ?: 'chatdb';

$cousin_pass = getenv('COUSIN_PASSWORD') ?: 'Anugrah Raja Aria Purnomo';
$cousin_hint = getenv('COUSIN_HINT') ?: 'nama lengkap raja';

$raja_pass = getenv('RAJA_PASSWORD') ?: 'raja123';
$raja_hint = getenv('RAJA_HINT') ?: 'Admin passcode';

// ==========================================
// 2. SETUP DATABASE
// ==========================================
$conn = @new mysqli($db_host, $db_user, $db_pass);
if ($conn->connect_error) {
    die("Koneksi Database Gagal: " . $conn->connect_error);
}

// Buat database dan tabel secara otomatis jika belum ada
$conn->query("CREATE DATABASE IF NOT EXISTS `$db_name`");
$conn->select_db($db_name);

// Tabel Pesan
$conn->query("CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender VARCHAR(50),
    type VARCHAR(20),
    content TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Tabel Status Pengguna (Untuk Fitur Last Seen / Online)
$conn->query("CREATE TABLE IF NOT EXISTS user_status (
    username VARCHAR(50) PRIMARY KEY,
    last_seen INT
)");

// ==========================================
// 3. FUNGSI BANTUAN
// ==========================================
// Format waktu "Terakhir dilihat" bergaya WhatsApp
function formatTerakhirDilihat($timestamp) {
    $now = time();
    $hari_ini = strtotime("today");
    $kemarin = strtotime("yesterday");
    
    if ($timestamp >= $hari_ini) {
        return "hari ini pukul " . date("H:i", $timestamp);
    } elseif ($timestamp >= $kemarin) {
        return "kemarin pukul " . date("H:i", $timestamp);
    } else {
        return date("d/m/Y", $timestamp) . " pukul " . date("H:i", $timestamp);
    }
}

// ==========================================
// 4. API & AJAX ENDPOINTS
// ==========================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // Polling: Ambil pesan baru & perbarui/cek status online
    if ($_GET['action'] == 'poll' && isset($_SESSION['user'])) {
        $last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
        $saya = $conn->real_escape_string($_SESSION['user']);
        $sekarang = time();

        // 1. Perbarui status online SAYA
        $conn->query("INSERT INTO user_status (username, last_seen) VALUES ('$saya', $sekarang) ON DUPLICATE KEY UPDATE last_seen=$sekarang");

        // 2. Ambil Pesan Baru
        $res = $conn->query("SELECT * FROM messages WHERE id > $last_id ORDER BY id ASC");
        $msgs =[];
        while ($row = $res->fetch_assoc()) {
            $msgs[] = $row;
        }

        // 3. Cek status partner obrolan
        $partner = ($saya === 'Raja') ? 'Sepupu' : 'Raja';
        $res_stat = $conn->query("SELECT last_seen FROM user_status WHERE username = '$partner'");
        $stat = $res_stat->fetch_assoc();

        $is_online = false;
        $status_text = "Belum pernah online";

        if ($stat) {
            $selisih_waktu = $sekarang - $stat['last_seen'];
            if ($selisih_waktu < 10) { 
                // Jika kurang dari 10 detik, berarti dia sedang buka aplikasi (Online)
                $is_online = true;
                $status_text = "Online";
            } else {
                $status_text = "Terakhir dilihat " . formatTerakhirDilihat($stat['last_seen']);
            }
        }

        echo json_encode([
            'messages' => $msgs,
            'is_online' => $is_online,
            'partner_status' => $status_text
        ]);
        exit;
    }

    // Kirim Pesan (Teks/URL Media)
    if ($_GET['action'] == 'send_message' && isset($_SESSION['user'])) {
        $type = $conn->real_escape_string($_POST['type'] ?? 'text');
        $content = $conn->real_escape_string($_POST['content'] ?? '');
        $sender = $conn->real_escape_string($_SESSION['user']);
        
        $conn->query("INSERT INTO messages (sender, type, content) VALUES ('$sender', '$type', '$content')");
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // Unggah File (Gambar, Suara, Dokumen)
    if ($_GET['action'] == 'upload' && isset($_FILES['file'])) {
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $file = $_FILES['file'];
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $ext = $ext ? strtolower($ext) : 'file';
        $new_name = uniqid() . '.' . $ext;
        
        if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_name)) {
            echo json_encode(['url' => $upload_dir . $new_name]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Gagal mengunggah']);
        }
        exit;
    }
    exit;
}

// ==========================================
// 5. ROUTING & AUTENTIKASI
// ==========================================
// Deteksi jika path URL berakhiran 'raja'
$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

if (basename($uri) === 'raja') {
    $target_user = 'Raja';
    $partner_user = 'Sepupu';
    $target_pass = strtolower($raja_pass);
    $hint = $raja_hint;
} else {
    $target_user = 'Sepupu';
    $partner_user = 'Raja';
    $target_pass = strtolower($cousin_pass);
    $hint = $cousin_hint;
}

// Proses Form Login
$error_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $input_pass = strtolower(trim($_POST['password'])); // Pengecekan tidak sensitif huruf besar/kecil
    if ($input_pass === $target_pass) {
        $_SESSION['user'] = $target_user;
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    } else {
        $error_msg = 'Kata sandi salah!';
    }
}

// Tampilkan Halaman Login Jika Belum Masuk
if (!isset($_SESSION['user']) || $_SESSION['user'] !== $target_user) {
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Diperlukan Akses</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gray-100 h-screen flex items-center justify-center p-4">
        <form method="POST" class="bg-white p-8 rounded-lg shadow-xl max-w-sm w-full">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">
                Selamat Datang, <?= htmlspecialchars($target_user) ?>
            </h2>
            <?php if($error_msg): ?>
                <div class="bg-red-100 text-red-600 p-3 rounded mb-4 text-sm text-center font-semibold"><?= htmlspecialchars($error_msg) ?></div>
            <?php endif; ?>
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Kata Sandi</label>
                <input type="password" name="password" class="w-full px-3 py-2 border rounded shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500" required autofocus>
                <p class="text-xs text-gray-500 mt-2">Petunjuk: <?= htmlspecialchars($hint) ?></p>
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2 px-4 rounded hover:bg-blue-700 transition">Masuk</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

$currentUser = $_SESSION['user'];
?>

<!-- ==========================================
// 6. ANTARMUKA CHAT (Setelah Login)
// ========================================== -->
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Obrolan - <?= htmlspecialchars($currentUser) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Sembunyikan scrollbar agar tampilan lebih bersih */
        #chat-box::-webkit-scrollbar { width: 6px; }
        #chat-box::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }
    </style>
</head>
<body class="bg-gray-200 h-screen flex flex-col items-center justify-center p-2 md:p-4">

    <div class="w-full max-w-3xl bg-white rounded-lg shadow-2xl flex flex-col h-full max-h-[850px]">
        
        <!-- Header Profil Partner -->
        <div class="bg-[#005c4b] text-white p-3 rounded-t-lg flex justify-between items-center shadow-md z-10">
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

        <!-- Area Pesan -->
        <div id="chat-box" class="flex-1 p-4 overflow-y-auto bg-[#efeae2] flex flex-col space-y-3 relative" style="background-image: url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png'); background-blend-mode: multiply; background-color: #efeae2;">
            <!-- Pesan akan dimuat di sini melalui AJAX -->
        </div>

        <!-- Area Input -->
        <div class="p-3 bg-[#f0f2f5] border-t flex items-center space-x-2 rounded-b-lg">
            
            <label class="cursor-pointer text-gray-500 hover:text-gray-700 p-2 transition">
                <i class="fas fa-paperclip text-2xl"></i>
                <input type="file" id="file-input" class="hidden" onchange="uploadFile()">
            </label>

            <input type="text" id="message-input" class="flex-1 border-0 rounded-lg px-4 py-3 shadow-sm focus:outline-none focus:ring-1 focus:ring-gray-300" placeholder="Ketik pesan...">

            <button id="record-btn" class="text-gray-500 hover:text-red-500 p-2 transition duration-200" onmousedown="startRecording()" onmouseup="stopRecording()" ontouchstart="startRecording()" ontouchend="stopRecording()" title="Tahan untuk merekam suara">
                <i class="fas fa-microphone text-2xl"></i>
            </button>

            <button onclick="sendText()" class="bg-[#00a884] text-white rounded-full p-3 w-12 h-12 flex items-center justify-center hover:bg-[#008f6f] transition shadow-md">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
    </div>

    <script>
        const currentUser = "<?= htmlspecialchars($currentUser) ?>";
        const chatBox = document.getElementById("chat-box");
        let lastId = 0;

        // --- Polling Pesan & Status Online ---
        async function fetchMessages() {
            try {
                const res = await fetch(`?action=poll&last_id=${lastId}`);
                const data = await res.json();
                
                // Update UI Status Partner
                const statusEl = document.getElementById('partner-status');
                statusEl.textContent = data.partner_status;
                
                if (data.is_online) {
                    statusEl.classList.remove('text-gray-200');
                    statusEl.classList.add('text-green-300', 'font-bold');
                } else {
                    statusEl.classList.remove('text-green-300', 'font-bold');
                    statusEl.classList.add('text-gray-200');
                }

                // Append Pesan Baru
                if (data.messages.length > 0) {
                    let shouldScroll = chatBox.scrollHeight - chatBox.scrollTop <= chatBox.clientHeight + 100;
                    data.messages.forEach(msg => {
                        appendMessage(msg);
                        lastId = msg.id;
                    });
                    if (shouldScroll) chatBox.scrollTop = chatBox.scrollHeight;
                }
            } catch (e) { 
                console.error("Kesalahan koneksi", e); 
            }
        }
        setInterval(fetchMessages, 1500); // Cek setiap 1.5 detik
        fetchMessages(); // Muat pertama kali

        // --- Render UI Pesan ---
        function appendMessage(data) {
            const isMe = data.sender === currentUser;
            const msgDiv = document.createElement("div");
            
            msgDiv.className = `max-w-[80%] p-2 rounded-lg shadow-sm ${isMe ? 'bg-[#dcf8c6] text-gray-800 self-end rounded-tr-none' : 'bg-white text-gray-800 self-start rounded-tl-none'}`;

            let contentHtml = '';
            
            if (data.type === 'text') {
                contentHtml = `<p class="break-words text-[15px]">${escapeHTML(data.content)}</p>`;
            } else if (data.type === 'image') {
                contentHtml = `<img src="${data.content}" class="rounded-md max-h-64 object-cover cursor-pointer border" onclick="window.open('${data.content}')">`;
            } else if (data.type === 'voice') {
                contentHtml = `<audio controls class="w-full max-w-[250px] mt-1"><source src="${data.content}"></audio>`;
            } else if (data.type === 'document') {
                const fileName = data.content.split('/').pop();
                contentHtml = `<a href="${data.content}" target="_blank" class="text-blue-600 flex items-center bg-black/5 p-2 rounded"><i class="fas fa-file-alt mr-2 text-2xl"></i> <span class="truncate max-w-[200px] text-sm underline">${fileName}</span></a>`;
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

        // --- Logika Pengiriman ---
        async function postMessage(type, content) {
            const fd = new FormData();
            fd.append('type', type);
            fd.append('content', content);
            await fetch('?action=send_message', { method: 'POST', body: fd });
            fetchMessages(); // Ambil langsung setelah mengirim
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

        // --- Upload File ---
        async function uploadFile() {
            const fileInput = document.getElementById("file-input");
            const file = fileInput.files[0];
            if (!file) return;

            const fd = new FormData();
            fd.append("file", file);

            const inputField = document.getElementById("message-input");
            inputField.placeholder = "Mengunggah file...";
            inputField.disabled = true;

            try {
                const res = await fetch('?action=upload', { method: 'POST', body: fd });
                const data = await res.json();
                
                if(data.error) throw new Error(data.error);

                let msgType = 'document';
                if (file.type.startsWith('image/')) msgType = 'image';
                if (file.type.startsWith('audio/')) msgType = 'voice';

                postMessage(msgType, data.url);
            } catch (err) { 
                alert("Gagal mengunggah file!"); 
            } finally {
                inputField.placeholder = "Ketik pesan...";
                inputField.disabled = false;
                fileInput.value = ""; 
            }
        }

        // --- Rekam Suara (Voice Note) ---
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
                    document.getElementById("message-input").placeholder = "Mengirim pesan suara...";
                    
                    const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                    audioChunks =[]; // reset
                    
                    const fd = new FormData();
                    fd.append("file", audioBlob, "voice_note.webm");

                    try {
                        const res = await fetch('?action=upload', { method: 'POST', body: fd });
                        const data = await res.json();
                        postMessage("voice", data.url);
                    } catch(e) {
                        alert("Gagal mengirim pesan suara.");
                    }
                    
                    document.getElementById("message-input").placeholder = "Ketik pesan...";
                };

                mediaRecorder.start();
            } catch (err) {
                alert("Akses mikrofon ditolak. Mohon izinkan akses mikrofon di browser Anda.");
            }
        }

        function stopRecording() {
            if (mediaRecorder && mediaRecorder.state !== "inactive") {
                mediaRecorder.stop();
                recordBtn.classList.remove("text-red-500", "scale-125");
                mediaRecorder.stream.getTracks().forEach(track => track.stop());
            }
        }

        // Mencegah XSS di teks pesan
        function escapeHTML(str) {
            return str.replace(/[&<>'"]/g, 
                tag => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    "'": '&#39;',
                    '"': '&quot;'
                }[tag])
            );
        }
    </script>
</body>
</html>
