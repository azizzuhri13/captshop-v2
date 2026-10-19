<!DOCTYPE html>
<html lang="en">

<head>
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css" rel="stylesheet">
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="SBH">
    <title>Netflix Panel</title>
    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <!-- Custom styles for this page -->
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
        <script src="https://cdnjs.cloudflare.com/ajax/libs/js-md5/2.3.0/md5.min.js"></script>
        <!-- Favicon utama -->
  <link rel="icon" type="image/png" href="capt-putih.png">

  <!-- Untuk Apple (iPhone, iPad) -->
  <link rel="apple-touch-icon" href="capt-putih.png">
<meta charset="UTF-8">

    <style>
    body.dark-mode #darkModeToggle {
    border-color: #444;
    color: #fff;
}

body.dark-mode #darkModeToggle:hover {
    background: #e50914;
    border-color: #e50914;
}
    /* ================= MOBILE TABLE FIX ================= */
@media (max-width: 768px) {

    .table-responsive {
        overflow-x: auto;
    }

    table.dataTable {
        width: 100% !important;
    }

    table.dataTable td,
    table.dataTable th {
        white-space: nowrap;
        font-size: 12px;
    }

    /* Batasi panjang text */
    td {
        max-width: 150px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Center control DataTables */
    .dataTables_wrapper .dataTables_paginate {
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
    }

    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_length {
        text-align: center;
        width: 100%;
    }

    .card-body {
        padding: 10px;
    }

    .card {
        margin-bottom: 15px;
    }
}

    /* ================= NETFLIX DARK MODE ================= */
body.dark-mode {
    background-color: #0b0b0b;
    color: #ffffff;
    font-family: 'Nunito', sans-serif;
}

/* Navbar */
body.dark-mode .navbar {
    background: linear-gradient(to bottom, #0b0b0b, #141414) !important;
    border-bottom: 1px solid #222;
}

/* Sidebar */
body.dark-mode .sidebar {
    background: #0b0b0b !important;
}

/* Card */
body.dark-mode .card {
    background: #141414;
    border: none;
    border-radius: 10px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.6);
}

/* Card header */
body.dark-mode .card-header {
    background: #1c1c1c;
    border-bottom: 1px solid #222;
    color: #fff;
}

/* Input */
body.dark-mode .form-control {
    background: #1c1c1c;
    border: 1px solid #333;
    color: #fff;
}

body.dark-mode .form-control::placeholder {
    color: #888;
}

/* BUTTON NETFLIX */
body.dark-mode .btn-primary {
    background: #e50914;
    border: none;
    border-radius: 6px;
    font-weight: 600;
}

body.dark-mode .btn-primary:hover {
    background: #f6121d;
    box-shadow: 0 0 10px rgba(229,9,20,0.7);
}

/* TABLE */
body.dark-mode table.dataTable {
    background: #141414;
}

body.dark-mode table.dataTable thead {
    background: #1c1c1c;
    color: #fff;
}

body.dark-mode table.dataTable tbody tr {
    border-bottom: 1px solid #222;
    transition: all 0.2s ease;
}

body.dark-mode table.dataTable tbody tr:hover {
    background: #1f1f1f;
    transform: scale(1.01);
}

/* ANIMASI HALUS */
body.dark-mode .card,
body.dark-mode .btn,
body.dark-mode .form-control {
    transition: all 0.3s ease;
}

/* ================= NETFLIX FULL UI ================= */

/* Background cinematic */
body.dark-mode {
    background: radial-gradient(circle at top, #141414, #0b0b0b);
    color: #fff;
}

/* Navbar blur effect */
body.dark-mode .navbar {
    background: rgba(20,20,20,0.85) !important;
    backdrop-filter: blur(8px);
    border-bottom: 1px solid #222;
}

/* Card cinematic */
body.dark-mode .card {
    background: linear-gradient(145deg, #141414, #1c1c1c);
    border: none;
    border-radius: 12px;
    box-shadow: 0 6px 25px rgba(0,0,0,0.6);
    transition: all 0.3s ease;
}

/* Hover zoom (Netflix feel) */
body.dark-mode .card:hover {
    transform: scale(1.02);
    box-shadow: 0 10px 35px rgba(0,0,0,0.8);
}

/* Header */
body.dark-mode .card-header {
    background: transparent;
    border-bottom: 1px solid #222;
}

/* Input modern */
body.dark-mode .form-control {
    background: #1a1a1a;
    border: 1px solid #333;
    border-radius: 8px;
    color: #fff;
}

body.dark-mode .form-control:focus {
    border-color: #e50914;
    box-shadow: 0 0 5px rgba(229,9,20,0.5);
}

/* Button Netflix */
body.dark-mode .btn-primary {
    background: linear-gradient(135deg, #e50914, #b20710);
    border: none;
    border-radius: 8px;
    font-weight: bold;
    letter-spacing: 0.5px;
}

body.dark-mode .btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 15px rgba(229,9,20,0.6);
}

/* Table cinematic */
body.dark-mode table.dataTable {
    background: transparent;
}

body.dark-mode table.dataTable thead {
    background: #1a1a1a;
}

body.dark-mode table.dataTable tbody tr {
    background: #141414;
    border-radius: 8px;
    margin-bottom: 8px;
    transition: all 0.2s ease;
}

body.dark-mode table.dataTable tbody tr:hover {
    background: #1f1f1f;
    transform: scale(1.01);
}

/* Pagination modern */
body.dark-mode .dataTables_paginate .paginate_button {
    border-radius: 6px !important;
    margin: 2px;
}

body.dark-mode .dataTables_paginate .paginate_button.current {
    background: #e50914 !important;
    border: none !important;
}

/* Scrollbar custom */
body.dark-mode::-webkit-scrollbar {
    width: 6px;
}

body.dark-mode::-webkit-scrollbar-thumb {
    background: #e50914;
    border-radius: 10px;
}

body.dark-mode h6 {
    color: #e50914;
}

body.dark-mode .card {
    animation: fadeIn 0.5s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}


/* Subject biar gak kepanjangan */
td:first-child {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Tombol kecil & rapi */
.btn-sm {
    padding: 4px 10px;
    font-size: 12px;
}

/* Mobile fix */
@media (max-width: 768px) {
    td:first-child {
        max-width: 120px;
    }
}

/* Paksa semua isi tabel tetap 1 baris */
#dataTable td, 
#dataTable th {
    white-space: nowrap !important;
    vertical-align: middle;
}

/* Khusus tombol jangan sampai turun */
.view-btn {
    display: inline-block;
    white-space: nowrap;
}

/* Fix mobile supaya tidak pecah */
@media (max-width: 768px) {
    #dataTable td {
        font-size: 12px;
    }

    /* Lebar kolom biar proporsional */
    #dataTable td:nth-child(1) { max-width: 120px; } /* Subject */
    #dataTable td:nth-child(2) { width: 90px; }     /* Time */
    #dataTable td:nth-child(3) { width: 70px; }     /* Button */
}
    </style>
</head>

<body id="page-top" class="sidebar-toggled">
<div id="wrapper">

    <!-- Sidebar -->
    <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion toggled" id="accordionSidebar">
        <a class="sidebar-brand d-flex align-items-center justify-content-center" href="#">
            <div class="sidebar-brand-icon">
                <i class="fas fa-user-secret"></i>
            </div>
            <div class="sidebar-brand-text mx-3" style="color:#e50914; font-weight:800;">
    NETFLIX HOUSEHOLD PANEL
</div>
        </a>
    </ul>

    <!-- Content Wrapper -->
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">

            <!-- Topbar -->
            <nav class="navbar navbar-expand-lg navbar-light bg-white shadow mb-4">
                <button class="btn btn-link d-lg-none" id="sidebarToggleTop">
                    <i class="fa fa-bars"></i>
                </button>
                <ul class="navbar-nav ml-auto">
                    <li class="nav-item">
                        <li class="nav-item">
    <button id="darkModeToggle" class="btn btn-sm btn-outline-secondary ml-3">
        🌙 Dark Mode
    </button>
</li>
                </ul>
            </nav>

            <div class="container-fluid">

                <!-- Form -->
                <div class="card shadow mb-4">
                    <div class="card-body">
                        <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                            <div class="row">
                                <div class="col-12 col-md-6 mb-2">
                                    <input type="email" name="email" class="form-control" placeholder="Enter Email" required>
                                </div>
                                <div class="col-12 col-md-3">
                                    <button class="btn btn-primary btn-block" type="submit">Submit</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Table -->
                <div class="card shadow mb-4">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-primary">Email List</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="dataTable" width="100%">
<thead>
    <tr>
        <th>Subject</th>
        <th>Waktu</th>
        <th>Aksi</th>
    </tr>
</thead>
                                <tbody>

<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $userEmail = $_POST["email"];

    $configData = file_get_contents('config.json');
    $config = json_decode($configData, true);

    $server = $config['imap_server'];
    $username = $config['email_username'];
    $password = $config['email_password'];

    date_default_timezone_set('Asia/Jakarta');

    $mailbox = imap_open($server, $username, $password);
    
    if (!$mailbox) {
echo '<tr><td colspan="2">Unable to connect: ...</td></tr>';
    } else {
        $allowedSubjects = $config['allowed_subjects'];
        $mailIds = imap_search($mailbox, 'TO "' . $userEmail . '"');

        if ($mailIds) {
            $emails = [];

            foreach ($mailIds as $mailId) {
                $header = imap_headerinfo($mailbox, $mailId);
                $decodedSubject = isset($header->subject) ? mb_decode_mimeheader($header->subject) : '';

                if (in_array($decodedSubject, $allowedSubjects)) {
                    $emails[] = [
                        'id' => $mailId,
                        'subject' => $decodedSubject,
                        'from' => $header->fromaddress,
                        'date' => strtotime($header->date)
                    ];
                }
            }

            usort($emails, function ($a, $b) {
                return $b['date'] - $a['date'];
            });

            foreach ($emails as $email) {
$minutesAgo = floor((time() - $email['date']) / 60);

if ($minutesAgo < 1) {
    $timeAgoText = '🔥 Baru saja';
} elseif ($minutesAgo < 60) {
    $timeAgoText = '⏱ ' . $minutesAgo . ' menit lalu';
} else {
    $hours = floor($minutesAgo / 60);
    $timeAgoText = '🕒 ' . $hours . ' jam lalu';
}

echo '<tr>';
echo '<td style="font-weight:600; max-width:200px; overflow:hidden; text-overflow:ellipsis;">' 
    . htmlspecialchars($email['subject']) . '</td>';

echo '<td style="text-align:center; white-space:nowrap;">' 
    . $timeAgoText . '</td>';

echo '<td style="text-align:center;">
    <button type="button" 
        class="btn btn-primary btn-sm view-btn"
        data-id="' . base64_encode($email['id']) . '"
        data-email="' . base64_encode($userEmail) . '"
        data-subject="' . base64_encode($email['subject']) . '">
        View
    </button>
</td>';

echo '</tr>';
            }
        } else {
echo '<tr><td colspan="2" style="text-align:center;">No emails found.</td></tr>';
        }
    }
    imap_close($mailbox);
}
?>
<script>
console.log("JS Loaded OK");
</script>

                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <footer class="bg-white text-center py-3">
            <span>Copyright © 2026</span>
        </footer>

    </div>
</div>

<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="vendor/datatables/jquery.dataTables.min.js"></script>
<script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function () {

$('#dataTable').DataTable({
    responsive: false, // ❗ MATIKAN RESPONSIVE
    autoWidth: true,
    scrollX: true, // 👉 biar bisa scroll kalau sempit
    columnDefs: [
        { targets: 0, width: "50%" },
        { targets: 1, width: "30%" },
        { targets: 2, width: "20%", orderable: false }
    ]
});

    $(document).on('click', '.view-btn', function () {
        const id = $(this).data('id');
        const email = $(this).data('email');
        const subject = $(this).data('subject');

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'read.php';

        const fields = {
            email_id: id,
            email: email,
            subject: subject
        };

        for (let key in fields) {
            let input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = fields[key];
            form.appendChild(input);
        }

        document.body.appendChild(form);
        form.submit();
    });

});
</script>
<script>
const toggleBtn = document.getElementById("darkModeToggle");

// Load mode dari localStorage
if (localStorage.getItem("darkMode") === "enabled") {
    document.body.classList.add("dark-mode");
    if (toggleBtn) toggleBtn.innerText = "☀️ Light Mode";
}

// Auto detect system (opsional, tidak override pilihan user)
if (!localStorage.getItem("darkMode")) {
    if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.body.classList.add("dark-mode");
    }
}

if (toggleBtn) {
    toggleBtn.addEventListener("click", () => {
        document.body.classList.toggle("dark-mode");

        if (document.body.classList.contains("dark-mode")) {
            localStorage.setItem("darkMode", "enabled");
            toggleBtn.innerText = "☀️ Light Mode";
        } else {
            localStorage.setItem("darkMode", "disabled");
            toggleBtn.innerText = "🌙 Dark Mode";
        }
    });
}
</script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap4.min.js"></script>
</body>
</html>
